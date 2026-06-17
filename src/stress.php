<?php
header('Content-Type: application/json');

const MC_KEY    = 'parrot:stress:state';
const MC_TTL    = 300;
const STATE_FILE = '/tmp/stress_state.json';
const MEM_FILE   = '/dev/shm/parrot_mem_stress';
const DISK_FILE  = '/tmp/parrot_disk_stress';
const PHP_BIN    = '/usr/local/bin/php';

function mc(): ?Memcached {
    static $mc = false;
    if ($mc === false) {
        $host = getenv('MEMCACHE_HOST');
        if (!$host || !class_exists('Memcached')) { $mc = null; return null; }
        [$h, $p] = explode(':', $host) + [1 => '11211'];
        $mc = new Memcached();
        $mc->addServer($h, (int)$p);
    }
    return $mc;
}

function read_state(): array {
    $mc = mc();
    if ($mc) {
        $s = $mc->get(MC_KEY);
        if ($mc->getResultCode() === Memcached::RES_SUCCESS && is_array($s)) {
            return auto_clear_dead_worker($s, fn($s) => $mc->set(MC_KEY, $s, MC_TTL));
        }
        return ['type' => 'none'];
    }
    if (!file_exists(STATE_FILE)) return ['type' => 'none'];
    $s = json_decode(file_get_contents(STATE_FILE), true) ?: ['type' => 'none'];
    return auto_clear_dead_worker($s, fn($s) => file_put_contents(STATE_FILE, json_encode($s)));
}

// Clears stale state when the background worker process died on THIS pod.
// Cross-pod workers are left alone — Memcached TTL handles eventual expiry.
function auto_clear_dead_worker(array $s, callable $save): array {
    $type = $s['type'] ?? 'none';
    if ($type === 'none' || !isset($s['pid']) || (int)$s['pid'] <= 0) return $s;

    $my_pod   = getenv('POD_NAME') ?: '';
    $test_pod = $s['pod_name'] ?? '';
    if ($test_pod && $my_pod !== $test_pod) return $s; // different pod, don't touch

    if (!file_exists('/proc/' . $s['pid'])) {
        $s = ['type' => 'none'];
        $save($s);
    }
    return $s;
}

function write_state(array $s): void {
    $mc = mc();
    if ($mc) { $mc->set(MC_KEY, $s, MC_TTL); return; }
    file_put_contents(STATE_FILE, json_encode($s));
}

function stop_all(): void {
    // Write type='none' first - workers exit on next loop iteration (within 1s).
    // Avoid posix_kill: the PID in state comes from shell_exec and may not match
    // the actual worker PID, risking SIGTERM to the wrong process (e.g. php-fpm master).
    write_state(['type' => 'none']);
    if (file_exists(MEM_FILE))  unlink(MEM_FILE);
    if (file_exists(DISK_FILE)) unlink(DISK_FILE);
}

function launch_worker(string $script, string $args = ''): int {
    $cmd = PHP_BIN . ' ' . escapeshellarg($script)
         . ($args !== '' ? ' ' . $args : '')
         . ' > /dev/null 2>&1 & echo $!';
    return (int)trim((string)shell_exec($cmd));
}

$action   = $_GET['action'] ?? 'status';
$pod_name = getenv('POD_NAME') ?: '';
$pod_ip   = getenv('POD_IP') ?: '';

if ($action === 'status') {
    echo json_encode(read_state());
    exit;
}

if ($action === 'stop') {
    stop_all();
    echo json_encode(['type' => 'none']);
    exit;
}

if ($action === 'start_cpu') {
    stop_all();
    $duration = max(5, min(120, (int)($_GET['duration'] ?? 30)));
    $state = [
        'type'       => 'cpu',
        'started_at' => time(),
        'duration'   => $duration,
        'pid'        => 0,
        'pod_name'   => $pod_name,
        'pod_ip'     => $pod_ip,
    ];
    write_state($state);
    $pid = launch_worker(__DIR__ . '/stress_worker.php', (string)$duration);
    if ($pid > 0) {
        $state['pid'] = $pid;
        write_state($state);
    }
    echo json_encode($state);
    exit;
}

if ($action === 'start_mem') {
    stop_all();
    $state = [
        'type'         => 'mem',
        'started_at'   => time(),
        'allocated_mb' => 0,
        'pid'          => 0,
        'pod_name'     => $pod_name,
        'pod_ip'       => $pod_ip,
    ];
    write_state($state);
    $pid = launch_worker(__DIR__ . '/mem_worker.php');
    if ($pid > 0) {
        $state['pid'] = $pid;
        write_state($state);
    }
    echo json_encode($state);
    exit;
}

if ($action === 'start_disk') {
    stop_all();
    $state = [
        'type'         => 'disk',
        'started_at'   => time(),
        'allocated_mb' => 0,
        'pid'          => 0,
        'pod_name'     => $pod_name,
        'pod_ip'       => $pod_ip,
    ];
    write_state($state);
    $pid = launch_worker(__DIR__ . '/disk_worker.php');
    if ($pid > 0) {
        $state['pid'] = $pid;
        write_state($state);
    }
    echo json_encode($state);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
