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
    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const badge = (status) => {
        if (status === 'forbidden') return '<span class="pill deny">403</span>';
        if (status === 'unreachable') return '<span class="pill gray">unreachable</span>';
        return '<span class="pill gray">unavailable</span>';
    };
    const val = (status, v) => status === 'ok' && v !== null && v !== undefined ? esc(v) : badge(status);

    function setGauge(fillId, markerId, used, request, limit) {
        if (used === null || !limit) return;
        $id(fillId).style.width = Math.min(100, used / limit * 100) + '%';
        if (markerId && request) $id(markerId).style.left = Math.min(100, request / limit * 100) + '%';
    }

    function render(d) {
        // Node + cluster cards
        $id('node-kubelet').innerHTML = val(d.node.status, d.node.kubeletVersion);
        $id('node-os').innerHTML = val(d.node.status, d.node.osImage);
        $id('cluster-version').innerHTML = val(d.cluster.status, d.cluster.version);
        $id('cluster-platform').innerHTML = val(d.cluster.status, d.cluster.platform);
        $id('cluster-nodes').innerHTML = d.cluster.nodeCount.status === 'ok'
            ? esc(d.cluster.nodeCount.value) : badge(d.cluster.nodeCount.status);

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
            ? esc(d.ephemeralStorage.usedMib) + 'Mi' : badge(d.ephemeralStorage.status);
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
                    '<div class="perm-row"><span><code>' + esc(r.resource) + '</code> — ' + esc(r.verbs) +
                    ' <span class="card-source">(' + esc(r.group) + ')</span></span>' +
                    '<span class="pill ok">ALLOWED</span></div>').join('');
                const probes = d.permissions.probes.filter(p => !p.allowed).map(p =>
                    '<div class="perm-row"><span>' + esc(p.label) + '</span>' +
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
