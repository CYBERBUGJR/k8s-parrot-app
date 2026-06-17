<?php
require __DIR__ . '/k8s.php';

header('Content-Type: application/json');

$creds     = k8s_creds();
$nodeName  = getenv('NODE_NAME') ?: '';
$podName   = getenv('POD_NAME') ?: '';
$namespace = $creds['namespace'] ?? (getenv('POD_NAMESPACE') ?: 'default');

// Build all calls upfront so they fire in parallel.
$calls     = [];
$probeList = k8s_probe_list();

$calls['rules'] = [
    'method' => 'POST',
    'path'   => '/apis/authorization.k8s.io/v1/selfsubjectrulesreviews',
    'body'   => ['apiVersion' => 'authorization.k8s.io/v1', 'kind' => 'SelfSubjectRulesReview', 'spec' => ['namespace' => $namespace]],
];
foreach ($probeList as $i => $probe) {
    $attrs = ['resource' => $probe['resource'], 'group' => $probe['group'], 'verb' => $probe['verb']];
    if ($probe['namespaced']) $attrs['namespace'] = $namespace;
    $calls["probe_$i"] = [
        'method' => 'POST',
        'path'   => '/apis/authorization.k8s.io/v1/selfsubjectaccessreviews',
        'body'   => ['apiVersion' => 'authorization.k8s.io/v1', 'kind' => 'SelfSubjectAccessReview', 'spec' => ['resourceAttributes' => $attrs]],
    ];
}

$calls['version']    = ['method' => 'GET', 'path' => '/version'];
$calls['nodes']      = ['method' => 'GET', 'path' => '/api/v1/nodes?limit=500'];
$calls['namespaces'] = ['method' => 'GET', 'path' => '/api/v1/namespaces?limit=500'];
$calls['metrics']    = ['method' => 'GET', 'path' => '/apis/metrics.k8s.io/v1beta1/namespaces/' . rawurlencode($namespace) . '/pods/' . rawurlencode($podName)];

if ($nodeName !== '') {
    $calls['node']  = ['method' => 'GET', 'path' => '/api/v1/nodes/' . rawurlencode($nodeName)];
    $calls['stats'] = ['method' => 'GET', 'path' => '/api/v1/nodes/' . rawurlencode($nodeName) . '/proxy/stats/summary'];
}

$res = k8s_multi($creds, $calls);

function section_status(array $r): string {
    if ($r['error'] === 'no-token') return 'no-token';
    if ($r['error'] === 'unreachable') return 'unreachable';
    if ($r['status'] === 403) return 'forbidden';
    if ($r['status'] === 404) return 'unavailable';
    return $r['ok'] ? 'ok' : 'error';
}

$out = [];

// --- ServiceAccount permissions -----------------------------------------
$rules  = $res['rules'];
$probes = [];
foreach ($probeList as $i => $probe) {
    $r        = $res["probe_$i"];
    $probes[] = [
        'label'   => $probe['label'],
        'allowed' => (bool) ($r['data']['status']['allowed'] ?? false),
    ];
}
$out['permissions'] = [
    'status' => section_status($rules),
    'rules'  => $rules['ok'] && is_array($rules['data']) ? k8s_format_rules($rules['data']) : [],
    'probes' => $probes,
];

// --- Cluster --------------------------------------------------------------
$version    = $res['version'];
$nodes      = $res['nodes'];
$namespaces = $res['namespaces'];
$out['cluster'] = [
    'status'         => section_status($version),
    'version'        => $version['data']['gitVersion'] ?? null,
    'platform'       => $version['data']['platform'] ?? null,
    'nodeCount'      => ['status' => section_status($nodes),      'value' => $nodes['ok']      ? count($nodes['data']['items'] ?? [])      : null],
    'namespaceCount' => ['status' => section_status($namespaces), 'value' => $namespaces['ok'] ? count($namespaces['data']['items'] ?? []) : null],
];

// --- Node -----------------------------------------------------------------
$node     = $res['node'] ?? ['ok' => false, 'status' => 404, 'error' => null, 'data' => null];
$nodeInfo = $node['data']['status']['nodeInfo'] ?? [];
$out['node'] = [
    'status'           => section_status($node),
    'kubeletVersion'   => $nodeInfo['kubeletVersion'] ?? null,
    'osImage'          => $nodeInfo['osImage'] ?? null,
    'containerRuntime' => $nodeInfo['containerRuntimeVersion'] ?? null,
    'capacityCpu'      => $node['data']['status']['capacity']['cpu'] ?? null,
    'capacityMemory'   => $node['data']['status']['capacity']['memory'] ?? null,
];

// --- Pod metrics ----------------------------------------------------------
$metrics       = $res['metrics'];
$cpuMilli      = null;
$memMib        = null;
$metricsSource = 'metrics-api';
if ($metrics['ok']) {
    foreach ($metrics['data']['containers'] ?? [] as $c) {
        $cpuMilli = ($cpuMilli ?? 0) + k8s_cpu_to_millicores($c['usage']['cpu'] ?? '0');
        $memMib   = ($memMib   ?? 0) + k8s_mem_to_mib($c['usage']['memory'] ?? '0');
    }
} else {
    $metricsSource = 'local';
    $ru       = getrusage();
    $cpuMilli = round(($ru['ru_utime.tv_sec'] + $ru['ru_stime.tv_sec']) * 1000
                    + ($ru['ru_utime.tv_usec'] + $ru['ru_stime.tv_usec']) / 1000, 1);
    $memMib   = round(memory_get_usage(true) / (1024 * 1024), 1);
}
$out['metrics'] = [
    'status'        => $metrics['ok'] ? 'ok' : section_status($metrics),
    'source'        => $metricsSource,
    'cpuMillicores' => $cpuMilli !== null ? round($cpuMilli, 1) : null,
    'memoryMib'     => $memMib   !== null ? round($memMib, 1)   : null,
];

// --- Ephemeral storage via kubelet stats summary --------------------------
$stats        = $res['stats'] ?? ['ok' => false, 'status' => 404, 'error' => null, 'data' => null];
$ephemeralMib = null;
if ($stats['ok']) {
    foreach ($stats['data']['pods'] ?? [] as $p) {
        if (($p['podRef']['name'] ?? '') === $podName && ($p['podRef']['namespace'] ?? '') === $namespace) {
            $ephemeralMib = round(($p['ephemeral-storage']['usedBytes'] ?? 0) / (1024 * 1024), 1);
        }
    }
}
$out['ephemeralStorage'] = [
    'status'  => $stats['ok'] && $ephemeralMib === null ? 'unavailable' : section_status($stats),
    'usedMib' => $ephemeralMib,
];

echo json_encode($out);
