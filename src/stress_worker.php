<?php
// CLI only — launched by stress.php in background.
// MEMCACHE_HOST is inherited from the PHP-FPM environment.

const MC_KEY     = 'parrot:stress:state';
const MC_TTL     = 300;
const STATE_FILE = '/tmp/stress_state.json';

$duration = (int)($argv[1] ?? 30);

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
    return file_exists(STATE_FILE) ? (json_decode(file_get_contents(STATE_FILE), true) ?: []) : [];
}

function write_state(array $s): void {
    $mc = mc();
    if ($mc) { $mc->set(MC_KEY, $s, MC_TTL); return; }
    file_put_contents(STATE_FILE, json_encode($s));
}

$state = read_state();

$end = time() + $duration;
while (time() < $end) {
    for ($i = 0; $i < 500000; $i++) {
        $x = sqrt($i * M_PI);
    }
    $state['elapsed'] = time() - ($state['started_at'] ?? time());
    write_state($state);
}

write_state(['type' => 'none']);
