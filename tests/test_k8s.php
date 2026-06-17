<?php
require __DIR__ . '/../src/k8s.php';

$failures = 0;
function check(string $name, $actual, $expected): void {
    global $failures;
    if ($actual === $expected) {
        echo "PASS $name\n";
    } else {
        $failures++;
        echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

// CPU quantity parsing (metrics.k8s.io returns nanocores like "12345678n")
check('cpu nanocores', k8s_cpu_to_millicores('250000000n'), 250.0);
check('cpu microcores', k8s_cpu_to_millicores('1500u'), 0.0015 * 1000);
check('cpu millicores', k8s_cpu_to_millicores('150m'), 150.0);
check('cpu cores', k8s_cpu_to_millicores('2'), 2000.0);

// Memory quantity parsing to MiB
check('mem Ki', k8s_mem_to_mib('2048Ki'), 2.0);
check('mem Mi', k8s_mem_to_mib('38Mi'), 38.0);
check('mem Gi', k8s_mem_to_mib('1Gi'), 1024.0);
check('mem bytes', k8s_mem_to_mib('1048576'), 1.0);

// SelfSubjectRulesReview -> table rows
$review = ['status' => ['resourceRules' => [
    ['verbs' => ['get', 'list'], 'apiGroups' => [''], 'resources' => ['pods']],
    ['verbs' => ['*'], 'apiGroups' => ['*'], 'resources' => ['*']],
]]];
check('rules rows', k8s_format_rules($review), [
    ['resource' => 'pods', 'verbs' => 'get, list', 'group' => 'core'],
    ['resource' => '*', 'verbs' => '*', 'group' => '*'],
]);

// Probe list shape
$p = k8s_probe_list();
check('probe count', count($p), 3);
check('probe has resource', isset($p[0]['resource']), true);

exit($failures === 0 ? 0 : 1);
