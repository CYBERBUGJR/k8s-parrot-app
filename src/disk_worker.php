<?php
// Background: gradually writes to /tmp in 10 Mi steps until kubelet evicts the pod.
set_time_limit(0);

const MC_KEY    = 'parrot:stress:state';
const MC_TTL    = 300;
const DISK_FILE = '/tmp/parrot_disk_stress';
const STEP_MB   = 10;

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
        return ($mc->getResultCode() === Memcached::RES_SUCCESS && is_array($s)) ? $s : ['type' => 'none'];
    }
    if (!file_exists('/tmp/stress_state.json')) return ['type' => 'none'];
    return json_decode(file_get_contents('/tmp/stress_state.json'), true) ?: ['type' => 'none'];
}

function write_state(array $s): void {
    $mc = mc();
    if ($mc) { $mc->set(MC_KEY, $s, MC_TTL); return; }
    file_put_contents('/tmp/stress_state.json', json_encode($s));
}

$my_pid   = getmypid();
$pod_name = getenv('POD_NAME') ?: '';
$pod_ip   = getenv('POD_IP') ?: '';

$initial = read_state();
if (($initial['type'] ?? '') !== 'disk') {
    exit;
}

$fh = fopen(DISK_FILE, 'w');
if (!$fh) exit;

// Non-null bytes force real block allocation on overlay2 (avoids sparse-file optimisation).
$chunk     = str_repeat("\xff", STEP_MB * 1024 * 1024);
$allocated = 0;

while (true) {
    // Check stop signal BEFORE writing so write_state never overwrites a stop.
    $current = read_state();
    if (($current['type'] ?? '') !== 'disk') {
        fclose($fh);
        if (file_exists(DISK_FILE)) unlink(DISK_FILE);
        exit;
    }

    if (!fwrite($fh, $chunk)) break;
    fflush($fh);
    $allocated += STEP_MB;

    write_state([
        'type'         => 'disk',
        'started_at'   => $initial['started_at'],
        'allocated_mb' => $allocated,
        'pod_name'     => $pod_name,
        'pod_ip'       => $pod_ip,
        'pid'          => $my_pid,
    ]);

    sleep(1);
}
fclose($fh);
