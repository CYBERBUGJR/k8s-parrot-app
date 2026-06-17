<?php
// Background: gradually fills PHP heap in 10 Mi steps until OOM kill.
// Heap allocation is counted directly by the cgroup memory controller.
set_time_limit(0);
ini_set('memory_limit', '-1');

const MC_KEY  = 'parrot:stress:state';
const MC_TTL  = 300;
const STEP_MB = 10;

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
if (($initial['type'] ?? '') !== 'mem') {
    exit;
}

// Keep chunks in array so PHP GC cannot reclaim them.
$chunks    = [];
$allocated = 0;

while (true) {
    // Check stop signal BEFORE allocating so the write below never overwrites a stop.
    $current = read_state();
    if (($current['type'] ?? '') !== 'mem') {
        exit;
    }

    // Allocate one chunk of real heap memory (\xff forces non-interned strings).
    $chunks[] = str_repeat("\xff", STEP_MB * 1024 * 1024);
    $allocated += STEP_MB;

    write_state([
        'type'         => 'mem',
        'started_at'   => $initial['started_at'],
        'allocated_mb' => $allocated,
        'pod_name'     => $pod_name,
        'pod_ip'       => $pod_ip,
        'pid'          => $my_pid,
    ]);

    sleep(1);
}
