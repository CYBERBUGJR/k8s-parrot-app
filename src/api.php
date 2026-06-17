<?php
// Prevent PHP warnings/notices from corrupting JSON output.
ini_set('display_errors', '0');
error_reporting(0);

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

$calls['version']       = ['method' => 'GET', 'path' => '/version'];
$calls['nodes']         = ['method' => 'GET', 'path' => '/api/v1/nodes?limit=500'];
$calls['namespaces']    = ['method' => 'GET', 'path' => '/api/v1/namespaces?limit=500'];
$calls['node_metrics']  = ['method' => 'GET', 'path' => '/apis/metrics.k8s.io/v1beta1/nodes'];
$calls['metrics']       = ['method' => 'GET', 'path' => '/apis/metrics.k8s.io/v1beta1/namespaces/' . rawurlencode($namespace) . '/pods/' . rawurlencode($podName)];

if ($nodeName !== '') {
    $calls['node']  = ['method' => 'GET', 'path' => '/api/v1/nodes/' . rawurlencode($nodeName)];
    $calls['stats'] = ['method' => 'GET', 'path' => '/api/v1/nodes/' . rawurlencode($nodeName) . '/proxy/stats/summary'];
}
if ($podName !== '' && $namespace !== '') {
    $calls['pod_spec'] = ['method' => 'GET', 'path' => '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods/' . rawurlencode($podName)];
    $calls['events']   = ['method' => 'GET', 'path' => '/api/v1/namespaces/' . rawurlencode($namespace) . '/events?fieldSelector=involvedObject.name%3D' . rawurlencode($podName) . '%2CinvolvedObject.namespace%3D' . rawurlencode($namespace) . '&limit=50'];
}
$deploymentName = getenv('DEPLOYMENT_NAME') ?: '';
if ($deploymentName !== '' && $namespace !== '') {
    $calls['deployment'] = ['method' => 'GET', 'path' => '/apis/apps/v1/namespaces/' . rawurlencode($namespace) . '/deployments/' . rawurlencode($deploymentName)];
    $calls['pods']       = ['method' => 'GET', 'path' => '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods?labelSelector=app%3D' . rawurlencode($deploymentName) . '&limit=50'];
}

$res = k8s_multi($creds, $calls);

function section_status(array $r): string {
    if (($r['error'] ?? null) === 'no-token') return 'no-token';
    if (($r['error'] ?? null) === 'unreachable') return 'unreachable';
    if (($r['status'] ?? 0) === 403) return 'forbidden';
    if (($r['status'] ?? 0) === 404) return 'unavailable';
    return ($r['ok'] ?? false) ? 'ok' : 'error';
}

$out = [];

// --- Pod identity (must come from THIS pod, not from index.php rendering) --
$out['pod'] = [
    'name'     => $podName,
    'ip'       => getenv('POD_IP') ?: '-',
    'nodeName' => $nodeName,
    'sa'       => getenv('SERVICE_ACCOUNT') ?: 'default',
];

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
$version      = $res['version'];
$nodes        = $res['nodes'];
$namespaces   = $res['namespaces'];
$nodeMetrics  = $res['node_metrics'];

$clusterCpuMilli = null;
$clusterMemMib   = null;
$clusterCpuPct   = null;
$clusterMemPct   = null;
if ($nodeMetrics['ok']) {
    $clusterCpuMilli = 0;
    $clusterMemMib   = 0;
    foreach ($nodeMetrics['data']['items'] ?? [] as $nm) {
        $clusterCpuMilli += k8s_cpu_to_millicores($nm['usage']['cpu']    ?? '0');
        $clusterMemMib   += k8s_mem_to_mib($nm['usage']['memory'] ?? '0');
    }
    $clusterCpuMilli = round($clusterCpuMilli, 0);
    $clusterMemMib   = round($clusterMemMib,   0);

    if ($nodes['ok']) {
        $capCpuMilli = 0;
        $capMemMib   = 0;
        foreach ($nodes['data']['items'] ?? [] as $n) {
            $capCpuMilli += k8s_cpu_to_millicores($n['status']['capacity']['cpu']    ?? '0');
            $capMemMib   += k8s_mem_to_mib($n['status']['capacity']['memory'] ?? '0');
        }
        if ($capCpuMilli > 0) $clusterCpuPct = round($clusterCpuMilli / $capCpuMilli * 100, 1);
        if ($capMemMib   > 0) $clusterMemPct = round($clusterMemMib   / $capMemMib   * 100, 1);
    }
}

$out['cluster'] = [
    'status'         => section_status($version),
    'version'        => $version['data']['gitVersion'] ?? null,
    'platform'       => $version['data']['platform'] ?? null,
    'nodeCount'      => ['status' => section_status($nodes),      'value' => $nodes['ok']      ? count($nodes['data']['items'] ?? [])      : null],
    'namespaceCount' => ['status' => section_status($namespaces), 'value' => $namespaces['ok'] ? count($namespaces['data']['items'] ?? []) : null],
    'cpuMillicores'  => $clusterCpuMilli,
    'memoryMib'      => $clusterMemMib,
    'cpuPercent'     => $clusterCpuPct,
    'memPercent'     => $clusterMemPct,
    'metricsStatus'  => section_status($nodeMetrics),
];

// --- Node -----------------------------------------------------------------
$node     = $res['node']       ?? ['ok' => false, 'status' => 404, 'error' => null, 'data' => null];
$nodeInfo = $node['data']['status']['nodeInfo'] ?? [];
$podSpec  = $res['pod_spec']   ?? ['ok' => false, 'status' => 0, 'error' => null, 'data' => null];
$deployR  = $res['deployment'] ?? ['ok' => false, 'status' => 0, 'error' => null, 'data' => null];

$out['node'] = [
    'status'           => section_status($node),
    'kubeletVersion'   => $nodeInfo['kubeletVersion'] ?? null,
    'osImage'          => $nodeInfo['osImage'] ?? null,
    'containerRuntime' => $nodeInfo['containerRuntimeVersion'] ?? null,
    // Only populated when explicitly set on the pod — absence means "cluster default handler"
    'runtimeClassName' => $podSpec['ok'] ? ($podSpec['data']['spec']['runtimeClassName'] ?? null) : null,
    'capacityCpu'      => $node['data']['status']['capacity']['cpu'] ?? null,
    'capacityMemory'   => $node['data']['status']['capacity']['memory'] ?? null,
];

$out['deployment'] = [
    'status'    => section_status($deployR),
    'desired'   => $deployR['ok'] ? ($deployR['data']['spec']['replicas'] ?? null) : null,
    'ready'     => $deployR['ok'] ? ($deployR['data']['status']['readyReplicas'] ?? 0) : 0,
    'available' => $deployR['ok'] ? ($deployR['data']['status']['availableReplicas'] ?? 0) : 0,
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
// metrics-server timestamp (ISO-8601, e.g. "2026-06-17T10:00:00Z") tells us when the data was collected
$metricsTimestamp = $metrics['ok'] ? ($metrics['data']['timestamp'] ?? null) : null;
$out['metrics'] = [
    'status'        => $metrics['ok'] ? 'ok' : section_status($metrics),
    'source'        => $metricsSource,
    'cpuMillicores' => $cpuMilli !== null ? round($cpuMilli, 1) : null,
    'memoryMib'     => $memMib   !== null ? round($memMib, 1)   : null,
    'timestamp'     => $metricsTimestamp,
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

// --- Pod events ----------------------------------------------------------
$eventsR = $res['events'] ?? ['ok' => false, 'status' => 0, 'error' => null, 'data' => null];
$events  = [];
if ($eventsR['ok']) {
    $items = $eventsR['data']['items'] ?? [];
    usort($items, fn($a, $b) =>
        strcmp($b['lastTimestamp'] ?? $b['eventTime'] ?? '', $a['lastTimestamp'] ?? $a['eventTime'] ?? ''));
    foreach (array_slice($items, 0, 30) as $ev) {
        $events[] = [
            'type'    => $ev['type']   ?? 'Normal',
            'reason'  => $ev['reason'] ?? '',
            'message' => $ev['message'] ?? '',
            'count'   => $ev['count']  ?? 1,
            'age'     => $ev['lastTimestamp'] ?? $ev['eventTime'] ?? null,
        ];
    }
}
$out['events'] = [
    'status' => section_status($eventsR),
    'items'  => $events,
];

// --- Sibling pods (same deployment) --------------------------------------
$podsR   = $res['pods'] ?? ['ok' => false, 'status' => 0, 'error' => null, 'data' => null];
$siblings = [];
if ($podsR['ok']) {
    foreach ($podsR['data']['items'] ?? [] as $p) {
        $siblings[] = [
            'name'   => $p['metadata']['name'] ?? '',
            'phase'  => $p['status']['phase']  ?? 'Unknown',
            'nodeName' => $p['spec']['nodeName'] ?? '',
        ];
    }
}
$out['siblings'] = [
    'status' => section_status($podsR),
    'items'  => $siblings,
];

echo json_encode($out);
