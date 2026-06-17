<?php
// Kubernetes API helper. Credentials come from the mounted ServiceAccount
// secret; every call has a short timeout so a broken apiserver never hangs
// the page.

const K8S_TIMEOUT_SECONDS = 2;
const K8S_SA_DIR = '/var/run/secrets/kubernetes.io/serviceaccount';

function k8s_creds(): ?array {
    $host = getenv('KUBERNETES_SERVICE_HOST');
    $port = getenv('KUBERNETES_SERVICE_PORT') ?: '443';
    if (!$host || !is_readable(K8S_SA_DIR . '/token')) {
        return null;
    }
    return [
        'server' => 'https://' . $host . ':' . $port,
        'token' => trim(file_get_contents(K8S_SA_DIR . '/token')),
        'ca' => K8S_SA_DIR . '/ca.crt',
        'namespace' => is_readable(K8S_SA_DIR . '/namespace')
            ? trim(file_get_contents(K8S_SA_DIR . '/namespace'))
            : (getenv('POD_NAMESPACE') ?: 'default'),
    ];
}

// Returns ['ok' => bool, 'status' => int, 'error' => ?string, 'data' => ?array]
// error is one of: null, 'no-token', 'unreachable'
function k8s_request(?array $creds, string $method, string $path, ?array $body = null): array {
    if ($creds === null) {
        return ['ok' => false, 'status' => 0, 'error' => 'no-token', 'data' => null];
    }
    $ch = curl_init($creds['server'] . $path);
    $headers = ['Authorization: Bearer ' . $creds['token'], 'Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => K8S_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => K8S_TIMEOUT_SECONDS,
        CURLOPT_CAINFO => $creds['ca'],
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'error' => 'unreachable', 'data' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => null,
        'data' => json_decode($resp, true),
    ];
}

// Fires multiple calls in parallel. $calls: ['key' => ['method'=>, 'path'=>, 'body'=>?], ...]
// Returns same keyed array, each value same format as k8s_request().
function k8s_multi(?array $creds, array $calls): array {
    if ($creds === null) {
        return array_map(fn($_) => ['ok' => false, 'status' => 0, 'error' => 'no-token', 'data' => null], $calls);
    }

    $mh      = curl_multi_init();
    $handles = [];

    foreach ($calls as $key => $call) {
        $ch      = curl_init($creds['server'] . $call['path']);
        $headers = ['Authorization: Bearer ' . $creds['token'], 'Accept: application/json'];
        $opts    = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => K8S_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => K8S_TIMEOUT_SECONDS,
            CURLOPT_CAINFO         => $creds['ca'],
            CURLOPT_CUSTOMREQUEST  => $call['method'],
        ];
        if (isset($call['body'])) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($call['body']);
            $headers[]                = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $key => $ch) {
        $resp   = curl_multi_getcontent($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($errno !== 0 || $resp === false) {
            $results[$key] = ['ok' => false, 'status' => 0, 'error' => 'unreachable', 'data' => null];
        } else {
            $results[$key] = [
                'ok'     => $status >= 200 && $status < 300,
                'status' => $status,
                'error'  => null,
                'data'   => json_decode($resp, true),
            ];
        }
    }
    curl_multi_close($mh);

    return $results;
}

// "250000000n" -> 250.0 millicores. Accepts n, u, m and plain cores.
function k8s_cpu_to_millicores(string $q): float {
    if (preg_match('/^([0-9.]+)(n|u|m)?$/', $q, $m)) {
        $v = (float) $m[1];
        return match ($m[2] ?? '') {
            'n' => $v / 1e6,
            'u' => $v / 1e3,
            'm' => $v,
            default => $v * 1000,
        };
    }
    return 0.0;
}

// "38Mi" -> 38.0 MiB. Accepts Ki, Mi, Gi and plain bytes.
function k8s_mem_to_mib(string $q): float {
    if (preg_match('/^([0-9.]+)(Ki|Mi|Gi)?$/', $q, $m)) {
        $v = (float) $m[1];
        return match ($m[2] ?? '') {
            'Ki' => $v / 1024,
            'Mi' => $v,
            'Gi' => $v * 1024,
            default => $v / (1024 * 1024),
        };
    }
    return 0.0;
}

// SelfSubjectRulesReview response -> rows for the permissions table.
function k8s_format_rules(array $review): array {
    $rows = [];
    foreach ($review['status']['resourceRules'] ?? [] as $rule) {
        foreach ($rule['resources'] ?? [] as $resource) {
            $group = $rule['apiGroups'][0] ?? '';
            $rows[] = [
                'resource' => $resource,
                'verbs' => implode(', ', $rule['verbs'] ?? []),
                'group' => $group === '' ? 'core' : $group,
            ];
        }
    }
    return $rows;
}

// Fixed probes checked with SelfSubjectAccessReview so denials are visible
// too (SelfSubjectRulesReview only returns granted rules).
function k8s_probe_list(): array {
    return [
        ['label' => 'nodes — list (cluster-wide)', 'resource' => 'nodes', 'group' => '', 'verb' => 'list', 'namespaced' => false],
        ['label' => 'deployments — delete', 'resource' => 'deployments', 'group' => 'apps', 'verb' => 'delete', 'namespaced' => true],
        ['label' => 'secrets — get', 'resource' => 'secrets', 'group' => '', 'verb' => 'get', 'namespaced' => true],
    ];
}
