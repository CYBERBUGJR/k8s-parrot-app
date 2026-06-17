# Parrot App Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the FER-TEST PHP demo into an English dashboard ("Parrot App") that inspects the pod, node, cluster and the permissions of the ServiceAccount assigned to the pod.

**Architecture:** PHP shell (`index.php`) renders instantly from Downward API env vars; a JSON endpoint (`api.php`) performs server-side Kubernetes API calls (SA token + CA from the mounted secret) and ~50 lines of vanilla JS fill the async cards and refresh metrics every 10s. No Composer, no JS framework. Assets (parrot GIF, Space Grotesk font, CSS) are bundled in the image for airgapped prod.

**Tech Stack:** php:8.4-fpm-alpine + nginx, curl extension (built into the image), Helm chart `chart/parrot-app`, Harbor registry `harbor.cms`.

**Spec:** `docs/superpowers/specs/2026-06-11-parrot-app-redesign-design.md`

**Testing reality:** there is no PHP test framework in this repo. Pure functions in `src/k8s.php` get a CLI test script (`tests/test_k8s.php`, plain `assert`-style, run with `php tests/test_k8s.php`). Everything touching the network or HTML is verified with `php -l`, `helm lint`/`helm template`, a local `docker build`, and the three SA deployment scenarios at the end.

---

## File map

- Create: `src/assets/parrot.gif` (bundled GIF)
- Create: `src/assets/space-grotesk.woff2` (bundled font)
- Create: `src/assets/style.css` (validated dashboard design)
- Create: `src/k8s.php` (API helper + pure functions)
- Create: `src/api.php` (JSON endpoint)
- Create: `tests/test_k8s.php` (CLI tests for pure functions)
- Rewrite: `src/index.php` (English dashboard shell + inline JS)
- Modify: `nginx.conf` (add mime.types include)
- Modify: `chart/parrot-app/templates/configmap.yaml` (same nginx fix)
- Modify: `chart/parrot-app/values.yaml` (serviceAccountName, automount, ephemeral limit)
- Modify: `chart/parrot-app/templates/deployment.yaml` (SA, new env vars)
- Create: `k8s/test-scenarios/scenario-3-reader-sa.yaml` (SA + Role + binding for testing)

---

### Task 1: Bundle assets (GIF + font)

**Files:**
- Create: `src/assets/parrot.gif`
- Create: `src/assets/space-grotesk.woff2`

- [ ] **Step 1: Download the party parrot GIF**

```bash
mkdir -p src/assets
curl -fsSL -o src/assets/parrot.gif "https://media.tenor.com/3_mXIoBPNhoAAAAi/party-parrot.gif"
file src/assets/parrot.gif
```

Expected: `GIF image data` in the `file` output. (Run from the laptop, not from .cms; use the SOCKS proxy if needed: `curl --proxy socks5://localhost:1080 ...`)

- [ ] **Step 2: Download Space Grotesk (woff2, weight 500-700)**

```bash
CSS=$(curl -fsSL -A "Mozilla/5.0" "https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&display=swap")
URL=$(echo "$CSS" | grep -o 'https://[^)]*\.woff2' | head -1)
curl -fsSL -o src/assets/space-grotesk.woff2 "$URL"
file src/assets/space-grotesk.woff2
```

Expected: `Web Open Font Format (Version 2)` in the `file` output. Space Grotesk is OFL-licensed, bundling is fine.

- [ ] **Step 3: Commit**

```bash
git add src/assets/parrot.gif src/assets/space-grotesk.woff2
git commit -m "Bundle party parrot GIF and Space Grotesk font for airgapped prod"
```

---

### Task 2: Fix nginx MIME types

Without `include mime.types`, nginx serves `.css`, `.gif` and `.woff2` as `application/octet-stream` and the browser ignores the stylesheet.

**Files:**
- Modify: `nginx.conf:5-6`
- Modify: `chart/parrot-app/templates/configmap.yaml:11-14`

- [ ] **Step 1: Add mime include to `nginx.conf`**

Replace the opening of the `http` block:

```nginx
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    server {
```

(The rest of the file is unchanged.)

- [ ] **Step 2: Apply the identical change inside `chart/parrot-app/templates/configmap.yaml`**

The ConfigMap embeds the same nginx.conf; add the same two lines at the top of its `http {` block so the chart-mounted config matches.

- [ ] **Step 3: Verify the configs stay in sync**

```bash
diff <(sed 's/^    //' nginx.conf) <(awk '/nginx.conf: \|/{flag=1;next} flag{sub(/^    /,"");print}' chart/parrot-app/templates/configmap.yaml)
```

Expected: no output (identical).

- [ ] **Step 4: Commit**

```bash
git add nginx.conf chart/parrot-app/templates/configmap.yaml
git commit -m "Include mime.types in nginx config (css/woff2/gif content types)"
```

---

### Task 3: `src/k8s.php` helper with tested pure functions

**Files:**
- Create: `src/k8s.php`
- Test: `tests/test_k8s.php`

- [ ] **Step 1: Write the failing CLI test**

Create `tests/test_k8s.php`:

```php
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
```

- [ ] **Step 2: Run the test, verify it fails**

```bash
php tests/test_k8s.php
```

Expected: fatal error, `k8s.php` not found / functions undefined.

- [ ] **Step 3: Write `src/k8s.php`**

```php
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
```

- [ ] **Step 4: Run the tests, verify they pass**

```bash
php tests/test_k8s.php && php -l src/k8s.php
```

Expected: all `PASS`, exit 0, `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add src/k8s.php tests/test_k8s.php
git commit -m "Add Kubernetes API helper with tested quantity/rules parsing"
```

---

### Task 4: `src/api.php` JSON endpoint

**Files:**
- Create: `src/api.php`

- [ ] **Step 1: Write `src/api.php`**

Every section reports its own `status` (`ok`, `forbidden`, `unreachable`, `no-token`, `unavailable`) so the front end renders partial results. The endpoint always answers HTTP 200.

```php
<?php
require __DIR__ . '/k8s.php';

header('Content-Type: application/json');

$creds = k8s_creds();
$nodeName = getenv('NODE_NAME') ?: '';
$podName = getenv('POD_NAME') ?: '';
$namespace = $creds['namespace'] ?? (getenv('POD_NAMESPACE') ?: 'default');

function section_status(array $r): string {
    if ($r['error'] === 'no-token') return 'no-token';
    if ($r['error'] === 'unreachable') return 'unreachable';
    if ($r['status'] === 403) return 'forbidden';
    if ($r['status'] === 404) return 'unavailable';
    return $r['ok'] ? 'ok' : 'error';
}

$out = [];

// --- ServiceAccount permissions -----------------------------------------
$rules = k8s_request($creds, 'POST', '/apis/authorization.k8s.io/v1/selfsubjectrulesreviews', [
    'apiVersion' => 'authorization.k8s.io/v1',
    'kind' => 'SelfSubjectRulesReview',
    'spec' => ['namespace' => $namespace],
]);
$probes = [];
foreach (k8s_probe_list() as $probe) {
    $attrs = ['resource' => $probe['resource'], 'group' => $probe['group'], 'verb' => $probe['verb']];
    if ($probe['namespaced']) {
        $attrs['namespace'] = $namespace;
    }
    $r = k8s_request($creds, 'POST', '/apis/authorization.k8s.io/v1/selfsubjectaccessreviews', [
        'apiVersion' => 'authorization.k8s.io/v1',
        'kind' => 'SelfSubjectAccessReview',
        'spec' => ['resourceAttributes' => $attrs],
    ]);
    $probes[] = [
        'label' => $probe['label'],
        'allowed' => (bool) ($r['data']['status']['allowed'] ?? false),
    ];
}
$out['permissions'] = [
    'status' => section_status($rules),
    'rules' => $rules['ok'] ? k8s_format_rules($rules['data']) : [],
    'probes' => $probes,
];

// --- Cluster --------------------------------------------------------------
$version = k8s_request($creds, 'GET', '/version');
$nodes = k8s_request($creds, 'GET', '/api/v1/nodes?limit=500');
$namespaces = k8s_request($creds, 'GET', '/api/v1/namespaces?limit=500');
$out['cluster'] = [
    'status' => section_status($version),
    'version' => $version['data']['gitVersion'] ?? null,
    'platform' => $version['data']['platform'] ?? null,
    'nodeCount' => ['status' => section_status($nodes), 'value' => $nodes['ok'] ? count($nodes['data']['items'] ?? []) : null],
    'namespaceCount' => ['status' => section_status($namespaces), 'value' => $namespaces['ok'] ? count($namespaces['data']['items'] ?? []) : null],
];

// --- Node -------------------------------------------------------------------
$node = k8s_request($creds, 'GET', '/api/v1/nodes/' . rawurlencode($nodeName));
$nodeInfo = $node['data']['status']['nodeInfo'] ?? [];
$out['node'] = [
    'status' => section_status($node),
    'kubeletVersion' => $nodeInfo['kubeletVersion'] ?? null,
    'osImage' => $nodeInfo['osImage'] ?? null,
    'containerRuntime' => $nodeInfo['containerRuntimeVersion'] ?? null,
    'capacityCpu' => $node['data']['status']['capacity']['cpu'] ?? null,
    'capacityMemory' => $node['data']['status']['capacity']['memory'] ?? null,
];

// --- Pod metrics (CPU / memory consumed) -----------------------------------
$metrics = k8s_request($creds, 'GET',
    '/apis/metrics.k8s.io/v1beta1/namespaces/' . rawurlencode($namespace) . '/pods/' . rawurlencode($podName));
$cpuMilli = null;
$memMib = null;
$metricsSource = 'metrics-api';
if ($metrics['ok']) {
    foreach ($metrics['data']['containers'] ?? [] as $c) {
        $cpuMilli = ($cpuMilli ?? 0) + k8s_cpu_to_millicores($c['usage']['cpu'] ?? '0');
        $memMib = ($memMib ?? 0) + k8s_mem_to_mib($c['usage']['memory'] ?? '0');
    }
} else {
    // Local fallback: PHP process view only, clearly labelled.
    $metricsSource = 'local';
    $ru = getrusage();
    $cpuMilli = round(($ru['ru_utime.tv_sec'] + $ru['ru_stime.tv_sec']) * 1000
        + ($ru['ru_utime.tv_usec'] + $ru['ru_stime.tv_usec']) / 1000, 1);
    $memMib = round(memory_get_usage(true) / (1024 * 1024), 1);
}
$out['metrics'] = [
    'status' => $metrics['ok'] ? 'ok' : section_status($metrics),
    'source' => $metricsSource,
    'cpuMillicores' => $cpuMilli !== null ? round($cpuMilli, 1) : null,
    'memoryMib' => $memMib !== null ? round($memMib, 1) : null,
];

// --- Ephemeral storage via kubelet stats summary ----------------------------
$stats = k8s_request($creds, 'GET', '/api/v1/nodes/' . rawurlencode($nodeName) . '/proxy/stats/summary');
$ephemeralMib = null;
if ($stats['ok']) {
    foreach ($stats['data']['pods'] ?? [] as $p) {
        if (($p['podRef']['name'] ?? '') === $podName && ($p['podRef']['namespace'] ?? '') === $namespace) {
            $ephemeralMib = round(($p['ephemeral-storage']['usedBytes'] ?? 0) / (1024 * 1024), 1);
        }
    }
}
$out['ephemeralStorage'] = [
    'status' => section_status($stats),
    'usedMib' => $ephemeralMib,
];

echo json_encode($out);
```

- [ ] **Step 2: Syntax check**

```bash
php -l src/api.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/api.php
git commit -m "Add api.php JSON endpoint for Kubernetes API sections"
```

---

### Task 5: `src/assets/style.css` (validated design)

**Files:**
- Create: `src/assets/style.css`

- [ ] **Step 1: Write the stylesheet**

```css
@font-face {
  font-family: 'Space Grotesk';
  src: url('space-grotesk.woff2') format('woff2');
  font-weight: 500 700;
  font-display: swap;
}

:root {
  --k8s-blue: #326ce5;
  --bg: #f4f6fa;
  --card-bg: #fff;
  --muted: #888;
  --text-muted: #666;
  --ok-bg: #e6f4ea;
  --ok-fg: #137333;
  --deny-bg: #fce8e6;
  --deny-fg: #c5221f;
  --pill-bg: #eef1f6;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  padding: 20px;
  background: var(--bg);
  font-family: -apple-system, 'Segoe UI', Roboto, sans-serif;
  font-size: 16px;
  color: #1c1e21;
  max-width: 1100px;
  margin-inline: auto;
}

h1, .tagline, .card-title { font-family: 'Space Grotesk', sans-serif; }

/* Header */
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--k8s-blue);
  color: #fff;
  border-radius: 10px;
  padding: 14px 22px;
}
.header-left { display: flex; align-items: center; gap: 18px; }
.header-left img { width: 110px; height: 110px; }
.header h1 { font-size: 30px; font-weight: 700; margin: 0; }
.tagline { opacity: .85; font-size: 16px; }
.header-badges { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.badge {
  background: rgba(255, 255, 255, .2);
  border-radius: 14px;
  padding: 4px 14px;
  font-size: 15px;
}

/* Card grid */
.row { display: flex; gap: 14px; margin-top: 14px; align-items: stretch; }
.card {
  flex: 1;
  background: var(--card-bg);
  border-radius: 10px;
  padding: 16px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
}
.card.wide { flex: 2; }
.card-title {
  font-size: 13px;
  letter-spacing: 1px;
  color: var(--muted);
  font-weight: 700;
  text-transform: uppercase;
}
.card-head { display: flex; justify-content: space-between; align-items: center; }
.card-source { font-size: 14px; color: var(--muted); }
.kv { font-size: 16px; margin-top: 10px; line-height: 1.8; }
.kv div { display: flex; justify-content: space-between; align-items: center; }
.kv span:first-child { color: var(--text-muted); }

/* Pills */
.pill { border-radius: 12px; padding: 2px 12px; font-size: 14px; font-weight: 600; }
.pill.ok { background: var(--ok-bg); color: var(--ok-fg); }
.pill.deny { background: var(--deny-bg); color: var(--deny-fg); }
.pill.gray { background: var(--pill-bg); color: var(--text-muted); font-weight: 400; }

/* Gauges */
.gauges { display: flex; gap: 28px; margin-top: 12px; }
.gauge { flex: 1; }
.gauge-top { display: flex; justify-content: space-between; font-size: 15px; }
.gauge-bar { height: 10px; background: var(--pill-bg); border-radius: 5px; margin-top: 8px; position: relative; }
.gauge-fill { height: 10px; border-radius: 5px; transition: width .5s; }
.gauge-fill.cpu { background: var(--k8s-blue); }
.gauge-fill.mem { background: #34a853; }
.gauge-fill.eph { background: #a142f4; }
.gauge-marker { position: absolute; top: -3px; width: 2px; height: 16px; background: #f9ab00; }
.gauge-sub { font-size: 14px; color: var(--muted); margin-top: 6px; }

/* Permissions table */
.perm-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  border-bottom: 1px solid #f0f2f6;
  font-size: 15px;
}
.perm-row:last-child { border-bottom: none; }
.perm-row code { background: var(--pill-bg); border-radius: 4px; padding: 1px 6px; }

/* Labels */
.labels { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; }

.note { color: var(--text-muted); font-style: italic; margin-top: 10px; }

@media (max-width: 800px) {
  .row { flex-direction: column; }
  .gauges { flex-direction: column; gap: 14px; }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/assets/style.css
git commit -m "Add dashboard stylesheet (validated design)"
```

---

### Task 6: Rewrite `src/index.php` (English shell + inline JS)

**Files:**
- Rewrite: `src/index.php`

- [ ] **Step 1: Write the new `index.php`**

```php
<?php
// Static data comes from the Downward API env vars; everything that needs
// the Kubernetes API is fetched async from api.php by the inline JS below.

$labels = [];
$labelsFile = getenv('LABELS_FILE');
if ($labelsFile && file_exists($labelsFile)) {
    foreach (explode("\n", file_get_contents($labelsFile)) as $line) {
        if ($line !== '' && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $labels[$key] = trim($value, '"');
        }
    }
}

$version = getenv('VERSION') ?: 'dev';
$namespace = getenv('POD_NAMESPACE') ?: 'default';
$podName = getenv('POD_NAME') ?: gethostname();
$podIp = getenv('POD_IP') ?: '-';
$nodeName = getenv('NODE_NAME') ?: '-';
$serviceAccount = getenv('SERVICE_ACCOUNT') ?: 'default';
$hasCustomSa = $serviceAccount !== '' && $serviceAccount !== 'default';

$cpuRequest = getenv('MY_CPU_REQUEST') ?: '0';
$cpuLimit = getenv('MY_CPU_LIMIT') ?: '0';
$memRequest = getenv('MY_MEM_REQUEST') ?: '0';
$memLimit = getenv('MY_MEM_LIMIT') ?: '0';
$ephLimit = getenv('MY_EPH_LIMIT') ?: '';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parrot App v<?= e($version) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="header">
        <div class="header-left">
            <img src="assets/parrot.gif" alt="Party Parrot">
            <div>
                <h1>Parrot App</h1>
                <div class="tagline">El Famoso Kubernetes Parrot Inspectore !</div>
            </div>
        </div>
        <div class="header-badges">
            <span class="badge">v<?= e($version) ?></span>
            <span class="badge">namespace: <?= e($namespace) ?></span>
        </div>
    </header>

    <div class="row">
        <div class="card">
            <div class="card-title">Pod</div>
            <div class="kv">
                <div><span>Name</span><b><?= e($podName) ?></b></div>
                <div><span>IP</span><b><?= e($podIp) ?></b></div>
                <div><span>ServiceAccount</span><b><?= e($serviceAccount) ?></b></div>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Node</div>
            <div class="kv">
                <div><span>Hostname</span><b><?= e($nodeName) ?></b></div>
                <div><span>Kubelet</span><b id="node-kubelet">…</b></div>
                <div><span>OS</span><b id="node-os">…</b></div>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Cluster</div>
            <div class="kv">
                <div><span>Version</span><b id="cluster-version">…</b></div>
                <div><span>Platform</span><b id="cluster-platform">…</b></div>
                <div><span>Nodes</span><b id="cluster-nodes">…</b></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="card">
            <div class="card-head">
                <div class="card-title">Resources</div>
                <span class="card-source" id="metrics-source">loading…</span>
            </div>
            <div class="gauges">
                <div class="gauge">
                    <div class="gauge-top"><span>CPU</span><span><b id="cpu-used">…</b> / <?= e($cpuLimit) ?>m</span></div>
                    <div class="gauge-bar">
                        <div class="gauge-fill cpu" id="cpu-fill" style="width:0%"></div>
                        <div class="gauge-marker" id="cpu-marker"></div>
                    </div>
                    <div class="gauge-sub">request: <?= e($cpuRequest) ?>m · limit: <?= e($cpuLimit) ?>m</div>
                </div>
                <div class="gauge">
                    <div class="gauge-top"><span>Memory</span><span><b id="mem-used">…</b> / <?= e($memLimit) ?>Mi</span></div>
                    <div class="gauge-bar">
                        <div class="gauge-fill mem" id="mem-fill" style="width:0%"></div>
                        <div class="gauge-marker" id="mem-marker"></div>
                    </div>
                    <div class="gauge-sub">request: <?= e($memRequest) ?>Mi · limit: <?= e($memLimit) ?>Mi</div>
                </div>
                <div class="gauge">
                    <div class="gauge-top"><span>Ephemeral storage</span><span><b id="eph-used">…</b><?= $ephLimit !== '' ? ' / ' . e($ephLimit) . 'Mi' : '' ?></span></div>
                    <div class="gauge-bar"><div class="gauge-fill eph" id="eph-fill" style="width:0%"></div></div>
                    <div class="gauge-sub">limit: <?= $ephLimit !== '' ? e($ephLimit) . 'Mi' : '—' ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="card wide">
            <div class="card-head">
                <div class="card-title">ServiceAccount Permissions<?= $hasCustomSa ? ' — ' . e($serviceAccount) : '' ?></div>
                <span class="card-source">via SelfSubjectRulesReview</span>
            </div>
            <?php if ($hasCustomSa): ?>
                <div id="permissions"><div class="note">loading…</div></div>
            <?php else: ?>
                <div class="note">running with default ServiceAccount - assign one to test its permissions</div>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-title">Pod Labels</div>
            <div class="labels">
                <?php if ($labels === []): ?>
                    <span class="note">no labels found</span>
                <?php else: foreach ($labels as $key => $value): ?>
                    <span class="pill gray"><?= e($key) ?>=<?= e($value) ?></span>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <script>
    const CPU_REQUEST = <?= (float) $cpuRequest ?>, CPU_LIMIT = <?= (float) $cpuLimit ?>;
    const MEM_REQUEST = <?= (float) $memRequest ?>, MEM_LIMIT = <?= (float) $memLimit ?>;
    const EPH_LIMIT = <?= $ephLimit !== '' ? (float) $ephLimit : 'null' ?>;
    const HAS_CUSTOM_SA = <?= $hasCustomSa ? 'true' : 'false' ?>;

    const $id = (i) => document.getElementById(i);
    const badge = (status) => {
        if (status === 'forbidden') return '<span class="pill deny">403</span>';
        if (status === 'unreachable') return '<span class="pill gray">unreachable</span>';
        return '<span class="pill gray">unavailable</span>';
    };

    function setGauge(fillId, markerId, used, request, limit) {
        if (used === null || !limit) return;
        $id(fillId).style.width = Math.min(100, used / limit * 100) + '%';
        if (markerId && request) $id(markerId).style.left = Math.min(100, request / limit * 100) + '%';
    }

    function render(d) {
        // Node + cluster cards
        $id('node-kubelet').innerHTML = d.node.status === 'ok' ? d.node.kubeletVersion : badge(d.node.status);
        $id('node-os').innerHTML = d.node.status === 'ok' ? d.node.osImage : badge(d.node.status);
        $id('cluster-version').innerHTML = d.cluster.version ?? badge(d.cluster.status);
        $id('cluster-platform').innerHTML = d.cluster.platform ?? badge(d.cluster.status);
        $id('cluster-nodes').innerHTML = d.cluster.nodeCount.status === 'ok'
            ? d.cluster.nodeCount.value : badge(d.cluster.nodeCount.status);

        // Gauges
        $id('metrics-source').textContent = d.metrics.source === 'metrics-api'
            ? 'via metrics.k8s.io · kubelet stats' : 'local measurement (metrics API ' + d.metrics.status + ')';
        if (d.metrics.cpuMillicores !== null) {
            $id('cpu-used').textContent = d.metrics.cpuMillicores + 'm';
            setGauge('cpu-fill', 'cpu-marker', d.metrics.cpuMillicores, CPU_REQUEST, CPU_LIMIT);
        }
        if (d.metrics.memoryMib !== null) {
            $id('mem-used').textContent = d.metrics.memoryMib + 'Mi';
            setGauge('mem-fill', 'mem-marker', d.metrics.memoryMib, MEM_REQUEST, MEM_LIMIT);
        }
        $id('eph-used').innerHTML = d.ephemeralStorage.status === 'ok'
            ? d.ephemeralStorage.usedMib + 'Mi' : badge(d.ephemeralStorage.status);
        if (d.ephemeralStorage.status === 'ok' && EPH_LIMIT) {
            setGauge('eph-fill', null, d.ephemeralStorage.usedMib, null, EPH_LIMIT);
        }

        // Permissions
        if (HAS_CUSTOM_SA) {
            const el = $id('permissions');
            if (d.permissions.status !== 'ok') {
                el.innerHTML = '<div class="note">could not read permissions ' + badge(d.permissions.status) + '</div>';
            } else {
                const rules = d.permissions.rules.map(r =>
                    '<div class="perm-row"><span><code>' + r.resource + '</code> — ' + r.verbs +
                    ' <span class="card-source">(' + r.group + ')</span></span>' +
                    '<span class="pill ok">ALLOWED</span></div>').join('');
                const probes = d.permissions.probes.filter(p => !p.allowed).map(p =>
                    '<div class="perm-row"><span>' + p.label + '</span>' +
                    '<span class="pill deny">403 FORBIDDEN</span></div>').join('');
                el.innerHTML = rules + probes || '<div class="note">no rules returned</div>';
            }
        }
    }

    async function refresh() {
        try {
            const r = await fetch('api.php');
            render(await r.json());
        } catch (e) { /* keep last values; next tick retries */ }
    }
    refresh();
    setInterval(refresh, 10000);
    </script>
</body>
</html>
```

- [ ] **Step 2: Syntax check**

```bash
php -l src/index.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Quick local render smoke test**

```bash
cd src && php -S 127.0.0.1:8099 >/dev/null 2>&1 &
sleep 1
curl -s http://127.0.0.1:8099/index.php | grep -c "Parrot App"
curl -s http://127.0.0.1:8099/api.php | head -c 200; echo
kill %1
```

Expected: grep count >= 1; `api.php` returns JSON where every section has `"status":"no-token"` (no SA mounted on the laptop). This validates the no-token degradation path.

- [ ] **Step 4: Commit**

```bash
git add src/index.php
git commit -m "Rewrite index.php as English dashboard with async API sections"
```

---

### Task 7: Helm chart updates

**Files:**
- Modify: `chart/parrot-app/values.yaml`
- Modify: `chart/parrot-app/templates/deployment.yaml`

- [ ] **Step 1: Add SA and ephemeral storage settings to `values.yaml`**

After the `appVersion: v1.2.0` line, add:

```yaml
# ServiceAccount assigned to the pod. Empty means the namespace default SA;
# the app then hides the permissions section. RBAC for this SA is NOT part
# of this chart: the test scenario provides it.
serviceAccountName: ""
automountServiceAccountToken: true
```

And inside `resources.limits`, add ephemeral storage:

```yaml
resources:
  requests:
    memory: 50Mi
    cpu: 100m
  limits:
    memory: 100Mi
    cpu: 200m
    ephemeral-storage: 1Gi
```

- [ ] **Step 2: Update `deployment.yaml` pod spec**

In the `spec.template.spec` block (line 17, before `containers:`), add:

```yaml
    spec:
      {{- if .Values.serviceAccountName }}
      serviceAccountName: {{ .Values.serviceAccountName }}
      {{- end }}
      automountServiceAccountToken: {{ .Values.automountServiceAccountToken }}
```

In the container `resources.limits`, add:

```yaml
          limits:
            memory: {{ .Values.resources.limits.memory }}
            cpu: {{ .Values.resources.limits.cpu }}
            ephemeral-storage: {{ index .Values.resources.limits "ephemeral-storage" }}
```

In the `env:` list, add these entries:

```yaml
        - name: POD_NAMESPACE
          valueFrom:
            fieldRef:
              fieldPath: metadata.namespace
        - name: POD_IP
          valueFrom:
            fieldRef:
              fieldPath: status.podIP
        - name: SERVICE_ACCOUNT
          valueFrom:
            fieldRef:
              fieldPath: spec.serviceAccountName
        - name: MY_EPH_LIMIT
          valueFrom:
            resourceFieldRef:
              containerName: parrot-app
              divisor: "1M"
              resource: limits.ephemeral-storage
```

- [ ] **Step 3: Lint and render**

```bash
helm lint chart/parrot-app
helm template test chart/parrot-app | grep -A2 "SERVICE_ACCOUNT\|serviceAccountName\|ephemeral-storage"
helm template test chart/parrot-app --set serviceAccountName=sa-tester | grep "serviceAccountName: sa-tester"
```

Expected: `1 chart(s) linted, 0 chart(s) failed`; env vars and ephemeral-storage present; the `--set` render shows `serviceAccountName: sa-tester`.

- [ ] **Step 4: Commit**

```bash
git add chart/parrot-app/values.yaml chart/parrot-app/templates/deployment.yaml
git commit -m "Chart: configurable ServiceAccount, new Downward API env, ephemeral limit"
```

---

### Task 8: Test scenario manifests

**Files:**
- Create: `k8s/test-scenarios/scenario-3-reader-sa.yaml`

- [ ] **Step 1: Write the scenario manifest**

Scenario 1 (default SA) and scenario 2 (bare SA) only need `--set serviceAccountName=...`; scenario 3 needs RBAC:

```yaml
# Scenario 3: SA with read access on pods (namespaced) and nodes (cluster).
# Deploy with: helm upgrade --install parrot chart/parrot-app --set serviceAccountName=parrot-reader
apiVersion: v1
kind: ServiceAccount
metadata:
  name: parrot-reader
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: parrot-bare   # scenario 2: SA with no rights at all
---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: parrot-reader
rules:
- apiGroups: [""]
  resources: ["pods", "configmaps"]
  verbs: ["get", "list", "watch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: parrot-reader
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: Role
  name: parrot-reader
subjects:
- kind: ServiceAccount
  name: parrot-reader
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRole
metadata:
  name: parrot-reader-nodes
rules:
- apiGroups: [""]
  resources: ["nodes", "nodes/proxy"]
  verbs: ["get", "list"]
- apiGroups: ["metrics.k8s.io"]
  resources: ["pods"]
  verbs: ["get"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: ClusterRoleBinding
metadata:
  name: parrot-reader-nodes
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: ClusterRole
  name: parrot-reader-nodes
subjects:
- kind: ServiceAccount
  name: parrot-reader
  namespace: REPLACE_WITH_NAMESPACE
```

Note: `REPLACE_WITH_NAMESPACE` is intentional in a ClusterRoleBinding subject; the operator running the scenario substitutes the deploy namespace (`kubectl apply -n <ns>` does not template it). Keep the comment in the file.

- [ ] **Step 2: Validate and commit**

```bash
kubectl apply --dry-run=client -f k8s/test-scenarios/scenario-3-reader-sa.yaml 2>&1 | head -5
git add k8s/test-scenarios/scenario-3-reader-sa.yaml
git commit -m "Add RBAC test scenario manifests for SA permission testing"
```

Expected: dry-run lists the 6 resources (namespace placeholder warning is fine if offline: client dry-run does not need a cluster).

---

### Task 9: Image build + final verification

**Files:**
- None new (Dockerfile already copies `src/` which now contains `assets/`)

- [ ] **Step 1: Confirm Dockerfile needs no change**

`COPY src/ /var/www/html/` already includes `src/assets/`, `api.php`, `k8s.php`. Verify:

```bash
grep "COPY src/" Dockerfile
```

Expected: `COPY src/ /var/www/html/`

- [ ] **Step 2: Build the image locally**

```bash
podman machine start 2>/dev/null; podman build -t parrot-app:redesign .
podman run --rm -d -p 8098:80 --name parrot-test parrot-app:redesign
sleep 2
curl -s http://127.0.0.1:8098/ | grep -c "El Famoso"
curl -s http://127.0.0.1:8098/api.php | grep -c "no-token"
curl -sI http://127.0.0.1:8098/assets/style.css | grep -i "content-type"
podman stop parrot-test
```

Expected: both grep counts >= 1; `Content-Type: text/css`.

- [ ] **Step 3: Run the full check suite**

```bash
php tests/test_k8s.php
php -l src/index.php && php -l src/api.php && php -l src/k8s.php
helm lint chart/parrot-app
```

Expected: all pass.

- [ ] **Step 4: Final commit and push**

```bash
git status
git add -A
git commit -m "Parrot app redesign: English dashboard, SA permission tester" || echo "nothing left to commit"
```

- [ ] **Step 5: Cluster scenarios (manual, when a test cluster is reachable)**

1. Push image to `harbor.cms/rancher_images/parrot-app:redesign` (via SOCKS proxy or tarball transfer, see session notes from April).
2. Scenario 1: `helm upgrade --install parrot chart/parrot-app --set image.tag=redesign` - permissions section shows the default-SA note; node/cluster cards show 403 badges.
3. Scenario 2: apply `k8s/test-scenarios/scenario-3-reader-sa.yaml` (it includes `parrot-bare`), then `--set serviceAccountName=parrot-bare` - permissions table nearly empty, probes show 403 FORBIDDEN.
4. Scenario 3: `--set serviceAccountName=parrot-reader` - ALLOWED pills for pods/configmaps, node and cluster cards populated, metrics gauges live.
