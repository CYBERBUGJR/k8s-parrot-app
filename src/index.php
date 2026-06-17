<?php
// Static data comes from the Downward API env vars; everything that needs
// the Kubernetes API is fetched async from api.php by the inline JS below.

// Server-side sticky-cookie clear: JS cannot delete httpOnly cookies, so it
// redirects here with ?switch=1; PHP sends Max-Age=0 and bounces to root.
if (isset($_GET['switch'])) {
    setcookie('parrot_sticky', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: /');
    exit;
}

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
$serviceAccount = getenv('SERVICE_ACCOUNT') ?: 'default';
$hasCustomSa = $serviceAccount !== '' && $serviceAccount !== 'default';

$cpuRequest = getenv('MY_CPU_REQUEST') ?: '0';
$cpuLimit = getenv('MY_CPU_LIMIT') ?: '0';
$memRequest = getenv('MY_MEM_REQUEST') ?: '0';
$memLimit = getenv('MY_MEM_LIMIT') ?: '0';
$ephLimit = getenv('MY_EPH_LIMIT') ?: '';
$hpaBench = getenv('HPA_BENCH') === 'true';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

// Returns an iconoir SVG sized for card titles, using currentColor.
function icon(string $paths, int $size = 15): string {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" stroke-width="1.5" fill="none"'
         . ' xmlns="http://www.w3.org/2000/svg" style="vertical-align:-.15em;flex-shrink:0">'
         . $paths . '</svg>';
}

// Iconoir path sets (stroke="currentColor", no fill).
const ICON_SERVER = '<path d="M21 7.35304L21 16.647C21 16.8649 20.8819 17.0656 20.6914 17.1715L12.2914 21.8381C12.1102 21.9388 11.8898 21.9388 11.7086 21.8381L3.30861 17.1715C3.11814 17.0656 3 16.8649 3 16.647L2.99998 7.35304C2.99998 7.13514 3.11812 6.93437 3.3086 6.82855L11.7086 2.16188C11.8898 2.06121 12.1102 2.06121 12.2914 2.16188L20.6914 6.82855C20.8818 6.93437 21 7.13514 21 7.35304Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.52844 7.29357L11.7086 11.8381C11.8898 11.9388 12.1102 11.9388 12.2914 11.8381L20.5 7.27777" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 21L12 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
const ICON_CLUSTER = '<path d="M12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 16C9.79086 16 8 14.2091 8 12C8 9.79086 9.79086 8 12 8C14.2091 8 16 9.79086 16 12C16 14.2091 14.2091 16 12 16Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 2V8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 16V22" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 12H8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 12H22" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.92896 4.92871L9.1716 9.17135" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.8284 14.8286L19.071 19.0713" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M4.92896 19.0713L9.1716 14.8286" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.8284 9.17139L19.071 4.92875" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
const ICON_RESOURCES = '<path d="M2.49999 3.50011L7 7.99977M7 7.99977L6.99999 4.00011M7 7.99977L3.00009 7.99988" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 16L11.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.5 9C10.3579 9 7 12.2832 7 16.3333C7 17.4668 7.26298 18.5401 7.73253 19.4983C7.88808 19.8157 8.22018 20 8.57365 20H20.4264C20.7798 20 21.1119 19.8157 21.2675 19.4983C21.737 18.5401 22 17.4668 22 16.3333C22 12.2832 18.6421 9 14.5 9Z" stroke="currentColor" stroke-width="1.5"/>';
const ICON_TESTING = '<path d="M6.1414 19.995C8.59885 21.7157 10.4224 19.9831 11.4592 18.5025L18.7592 8.07692L20.7255 7.0122L14.1723 2.42358L5.7251 14.4875C4.68838 15.9681 3.68394 18.2743 6.1414 19.995Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M16.091 11.0194C13.2146 10.1673 11.6877 11.801 8.81128 10.9489" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
const ICON_LABEL = '<path d="M3 17.4V6.6C3 6.26863 3.26863 6 3.6 6H16.6789C16.8795 6 17.0668 6.10026 17.1781 6.26718L20.7781 11.6672C20.9125 11.8687 20.9125 12.1313 20.7781 12.3328L17.1781 17.7328C17.0668 17.8997 16.8795 18 16.6789 18H3.6C3.26863 18 3 17.7314 3 17.4Z" stroke="currentColor" stroke-width="1.5"/>';
const ICON_SA = '<path d="M7 18V17C7 14.2386 9.23858 12 12 12V12C14.7614 12 17 14.2386 17 17V18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M12 12C13.6569 12 15 10.6569 15 9C15 7.34315 13.6569 6 12 6C10.3431 6 9 7.34315 9 9C9 10.6569 10.3431 12 12 12Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5"/>';
const ICON_NODE = '<path d="M6 18.01L6.01 17.9989" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 6.01L6.01 5.99889" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 9.4V2.6C2 2.26863 2.26863 2 2.6 2H21.4C21.7314 2 22 2.26863 22 2.6V9.4C22 9.73137 21.7314 10 21.4 10H2.6C2.26863 10 2 9.73137 2 9.4Z" stroke="currentColor" stroke-width="1.5"/><path d="M2 21.4V14.6C2 14.2686 2.26863 14 2.6 14H21.4C21.7314 14 22 14.2686 22 14.6V21.4C22 21.7314 21.7314 22 21.4 22H2.6C2.26863 22 2 21.7314 2 21.4Z" stroke="currentColor" stroke-width="1.5"/>';
const ICON_EVENTS = '<path d="M2.90602 17.505L5.33709 3.71766C5.5289 2.62987 6.56621 1.90354 7.654 2.09534L19.4717 4.17912C20.5595 4.37093 21.2858 5.40824 21.094 6.49603L18.6629 20.2833C18.4711 21.3711 17.4338 22.0975 16.346 21.9057L4.52834 19.8219C3.44055 19.6301 2.71421 18.5928 2.90602 17.505Z" stroke="currentColor" stroke-width="1.5"/><path d="M8.92902 6.38184L16.8075 7.77102" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M8.23444 10.3213L16.1129 11.7105" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M7.53986 14.2607L12.4639 15.129" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>';
const ICON_CHEVRON_DOWN = '<path d="M14.5 10.75L12 13.25L9.5 10.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 5H18C20.2091 5 22 6.79086 22 9V15C22 17.2091 20.2091 19 18 19H6C3.79086 19 2 17.2091 2 15V9C2 6.79086 3.79086 5 6 5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
const ICON_CHEVRON_UP = '<path d="M14.5 13.25L12 10.75L9.5 13.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 5H18C20.2091 5 22 6.79086 22 9V15C22 17.2091 20.2091 19 18 19H6C3.79086 19 2 17.2091 2 15V9C2 6.79086 3.79086 5 6 5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parrot App <?= e($version) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
    <script>
    // On full-page reload (F5), bounce through /?switch=1 so PHP can clear
    // the httpOnly Traefik sticky cookie via Set-Cookie, then Traefik assigns
    // a new pod. sessionStorage flag prevents a redirect loop.
    (function () {
        var nav = performance.getEntriesByType('navigation')[0];
        if (nav && nav.type === 'reload' && !sessionStorage.getItem('parrot-switching')) {
            sessionStorage.setItem('parrot-switching', '1');
            window.location.replace('/?switch=1');
        } else {
            sessionStorage.removeItem('parrot-switching');
        }
    })();
    </script>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <img src="assets/parrot.gif" alt="Party Parrot">
            <div>
                <h1>Parrot App</h1>
                <div class="tagline">The famous crazy parrot — Kubernetes inspector!</div>
            </div>
        </div>
        <div class="header-badges">
            <span class="badge"><?= e($version) ?></span>
            <span class="badge">namespace: <?= e($namespace) ?></span>
        </div>
    </header>

    <div class="row">
        <div class="card">
            <div class="card-title"><?= icon(ICON_SERVER) ?> Pod</div>
            <div class="kv">
                <div><span>Name</span><b id="pod-name">…</b></div>
                <div><span>IP</span><b id="pod-ip">…</b></div>
                <div><span>ServiceAccount</span><b id="pod-sa">…</b></div>
                <div><span>Replicas</span><b id="pod-replicas">…</b></div>
            </div>
        </div>
        <div class="card">
            <div class="card-title"><?= icon(ICON_NODE) ?> Node</div>
            <div class="kv">
                <div><span>Hostname</span><b id="node-name">…</b></div>
                <div><span>Kubelet</span><b id="node-kubelet">…</b></div>
                <div><span>OS</span><b id="node-os">…</b></div>
                <div><span>CRI</span><b id="node-cri">…</b></div>
                <div id="node-runtimeclass-row" style="display:none"><span>RuntimeClass</span><b id="node-runtimeclass">…</b></div>
            </div>
        </div>
        <div class="card">
            <div class="card-title"><?= icon(ICON_CLUSTER) ?> Cluster</div>
            <div class="kv">
                <div><span>Version</span><b id="cluster-version">…</b></div>
                <div><span>Platform</span><b id="cluster-platform">…</b></div>
                <div><span>Nodes</span><b id="cluster-nodes">…</b></div>
                <div><span>CPU/Memory Usage</span><b><span id="cluster-cpu">…</span> / <span id="cluster-mem">…</span></b></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="card">
            <div class="card-head">
                <div class="card-title"><?= icon(ICON_RESOURCES) ?> Resources</div>
                <span class="card-source" id="metrics-source">loading…</span>
                <span class="card-source" id="metrics-age" style="margin-left:8px;color:#f9ab00" title="Age of the data from metrics-server (scrape interval ~15s)"></span>
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

    <!-- ===== STRESS TESTERS ===== -->
    <div class="row">
      <div class="card">
        <div class="card-title"><?= icon(ICON_TESTING) ?> Testing</div>
        <div class="tests">

          <?php if ($hpaBench): ?>
          <div class="test">
            <div class="test-subtitle">HPA Scaling</div>
            <div class="note">Generates CPU load to trigger Horizontal Pod Autoscaler scaling. The HPA is deployed alongside this chart and targets this Deployment.</div>
            <div class="stress-controls">
              <label>Duration <b id="cpu-dur-val">30</b>s
                <input type="range" id="cpu-dur" min="5" max="120" value="30"
                  oninput="$id('cpu-dur-val').textContent=this.value">
              </label>
              <div class="stress-status" id="cpu-stress-status"></div>
              <div class="stress-buttons">
                <button class="btn-start" id="btn-cpu-start" onclick="stressStart('cpu')">&#9654; Start</button>
                <button class="btn-stop"  id="btn-cpu-stop"  onclick="stressStop()" disabled>&#9632; Stop</button>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="test">
            <div class="test-subtitle">OOMKiller</div>
            <?php if ((int)$memLimit === 0): ?>
            <div class="stress-warning">&#9888; No memory limit — OOM may affect node workloads.</div>
            <?php endif; ?>
            <div class="note">Allocates RAM in PHP heap in 10 Mi increments every second until the cgroup limit is reached and the OOM killer terminates the container.</div>
            <div class="stress-controls">
              <div class="stress-status" id="mem-stress-status"></div>
              <div class="stress-buttons">
                <button class="btn-start" id="btn-mem-start" onclick="stressStart('mem')">&#9654; Start</button>
                <button class="btn-stop"  id="btn-mem-stop"  onclick="stressStop()" disabled>&#9632; Stop</button>
              </div>
            </div>
          </div>

          <div class="test">
            <div class="test-subtitle">Ephemeral Storage</div>
            <?php if ($ephLimit === ''): ?>
            <div class="stress-warning">&#9888; No ephemeral-storage limit — filling disk may affect the node.</div>
            <?php endif; ?>
            <div class="note">Writes to <code>/tmp</code> in 10 Mi increments every second until the kubelet evicts the pod for exceeding its ephemeral-storage limit.</div>
            <div class="stress-controls">
              <div class="stress-status" id="disk-stress-status"></div>
              <div class="stress-buttons">
                <button class="btn-start" id="btn-disk-start" onclick="stressStart('disk')">&#9654; Start</button>
                <button class="btn-stop"  id="btn-disk-stop"  onclick="stressStop()" disabled>&#9632; Stop</button>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
    <!-- ===== END STRESS TESTERS ===== -->

    <div class="row">
        <div class="card">
            <details id="permissions-details">
                <summary class="card-head permissions-summary">
                    <div class="card-title"><?= icon(ICON_SA) ?> ServiceAccount Permissions<?= $hasCustomSa ? ' — ' . e($serviceAccount) : '' ?></div>
                    <span class="card-source">via SelfSubjectRulesReview</span>
                    <span class="perm-chevron">
                        <span class="chevron-closed"><?= icon(ICON_CHEVRON_DOWN, 18) ?></span>
                        <span class="chevron-open"><?= icon(ICON_CHEVRON_UP, 18) ?></span>
                    </span>
                </summary>
                <?php if ($hasCustomSa): ?>
                    <div id="permissions" style="margin-top:10px"><div class="note">loading…</div></div>
                <?php else: ?>
                    <div class="note" style="margin-top:10px">running with default ServiceAccount - assign one to test its permissions</div>
                <?php endif; ?>
            </details>
        </div>
        <div class="card">
            <div class="card-title"><?= icon(ICON_LABEL) ?> Pod Labels</div>
            <div class="labels">
                <?php if ($labels === []): ?>
                    <span class="note">no labels found</span>
                <?php else: foreach ($labels as $key => $value): ?>
                    <span class="pill gray"><?= e($key) ?>=<?= e($value) ?></span>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="card" style="flex:1;min-width:0;overflow:hidden">
            <details id="events-details">
                <summary class="card-head permissions-summary">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div class="card-title"><?= icon(ICON_EVENTS) ?> Pod Events</div>
                        <span class="card-source" style="font-size:13px">last 30 · auto-refreshed</span>
                    </div>
                    <span class="perm-chevron">
                        <span class="chevron-closed"><?= icon(ICON_CHEVRON_DOWN, 18) ?></span>
                        <span class="chevron-open"><?= icon(ICON_CHEVRON_UP, 18) ?></span>
                    </span>
                </summary>
                <div id="events" style="margin-top:10px"><div class="note">loading…</div></div>
            </details>
        </div>
    </div>

    <script>
    const CPU_REQUEST = <?= (float) $cpuRequest ?>, CPU_LIMIT = <?= (float) $cpuLimit ?>;
    const MEM_REQUEST = <?= (float) $memRequest ?>, MEM_LIMIT = <?= (float) $memLimit ?>;
    const EPH_LIMIT = <?= $ephLimit !== '' ? (float) $ephLimit : 'null' ?>;
    const HAS_CUSTOM_SA = <?= $hasCustomSa ? 'true' : 'false' ?>;

    let currentPodName = '';
    let permissionsLoaded = false;
    const $id = (i) => document.getElementById(i);
    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const badge = (status) => {
        if (status === 'forbidden') return '<span class="pill deny">403</span>';
        if (status === 'unreachable') return '<span class="pill gray">unreachable</span>';
        return '<span class="pill gray">unavailable</span>';
    };
    const val = (status, v) => status === 'ok' && v !== null && v !== undefined ? esc(v) : badge(status);
    function relativeAge(iso) {
        const sec = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
        if (sec < 60)   return sec + 's ago';
        if (sec < 3600) return Math.round(sec / 60) + 'm ago';
        if (sec < 86400) return Math.round(sec / 3600) + 'h ago';
        return Math.round(sec / 86400) + 'd ago';
    }

    function setGauge(fillId, markerId, used, request, limit) {
        if (used === null || !limit) return;
        $id(fillId).style.width = Math.min(100, used / limit * 100) + '%';
        if (markerId && request) $id(markerId).style.left = Math.min(100, request / limit * 100) + '%';
    }

    function render(d) {
        currentPodName = d.pod.name;
        // Pod identity — always from the pod that served api.php
        $id('pod-name').textContent = d.pod.name;
        $id('pod-ip').textContent   = d.pod.ip;
        $id('pod-sa').textContent   = d.pod.sa;
        $id('node-name').textContent = d.pod.nodeName;

        // Deployment replica count
        if (d.deployment && d.deployment.status === 'ok' && d.deployment.desired !== null) {
            $id('pod-replicas').textContent = d.deployment.ready + ' ready / ' + d.deployment.desired + ' desired';
        } else if (d.deployment) {
            $id('pod-replicas').innerHTML = badge(d.deployment.status);
        }

        // Node + cluster cards
        $id('node-kubelet').innerHTML = val(d.node.status, d.node.kubeletVersion);
        $id('node-os').innerHTML = val(d.node.status, d.node.osImage);
        $id('node-cri').innerHTML = val(d.node.status, d.node.containerRuntime);
        if (d.node.runtimeClassName) {
            $id('node-runtimeclass').textContent = d.node.runtimeClassName;
            $id('node-runtimeclass-row').style.display = '';
        }
        $id('cluster-version').innerHTML = val(d.cluster.status, d.cluster.version);
        $id('cluster-platform').innerHTML = val(d.cluster.status, d.cluster.platform);
        $id('cluster-nodes').innerHTML = d.cluster.nodeCount.status === 'ok'
            ? esc(d.cluster.nodeCount.value) : badge(d.cluster.nodeCount.status);
        $id('cluster-cpu').innerHTML = d.cluster.cpuPercent !== null
            ? esc(d.cluster.cpuPercent) + '%' : badge(d.cluster.metricsStatus);
        $id('cluster-mem').innerHTML = d.cluster.memPercent !== null
            ? esc(d.cluster.memPercent) + '%' : badge(d.cluster.metricsStatus);

        // Gauges
        $id('metrics-source').textContent = d.metrics.source === 'metrics-api'
            ? 'via metrics.k8s.io · kubelet stats' : 'local measurement (metrics API ' + d.metrics.status + ')';
        const ageEl = $id('metrics-age');
        if (d.metrics.timestamp) {
            const ageSec = Math.round((Date.now() - new Date(d.metrics.timestamp).getTime()) / 1000);
            ageEl.textContent = 'data: ' + ageSec + 's old';
            ageEl.title = 'metrics-server collected this data at ' + d.metrics.timestamp + ' (scrape interval ~15s)';
        } else {
            ageEl.textContent = '';
        }
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

        // Pod events — refresh on every tick (events change over time).
        const evEl = $id('events');
        if (evEl) {
            if (d.events.status !== 'ok') {
                evEl.innerHTML = '<div class="note">could not load events ' + badge(d.events.status) + '</div>';
            } else if (!d.events.items.length) {
                evEl.innerHTML = '<div class="note">no events recorded for this pod</div>';
            } else {
                evEl.innerHTML = d.events.items.map(ev => {
                    const age = ev.age ? relativeAge(ev.age) : '?';
                    const typeClass = ev.type === 'Warning' ? 'deny' : 'ok';
                    return '<div class="perm-row">' +
                        '<span style="display:flex;gap:8px;align-items:baseline;flex:1;min-width:0;overflow:hidden">' +
                        '<span class="pill ' + typeClass + '" style="flex-shrink:0;font-size:12px">' + esc(ev.type) + '</span>' +
                        '<code style="flex-shrink:0">' + esc(ev.reason) + '</code>' +
                        '<span style="color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;flex:1">' + esc(ev.message) + '</span>' +
                        '</span>' +
                        '<span style="flex-shrink:0;color:var(--muted);font-size:13px;margin-left:12px;white-space:nowrap">' +
                        (ev.count > 1 ? 'x' + ev.count + ' · ' : '') + age +
                        '</span></div>';
                }).join('');
            }
        }

        // Permissions — loaded once on first successful response, never re-rendered on refresh.
        if (HAS_CUSTOM_SA && !permissionsLoaded) {
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
                permissionsLoaded = true;
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
    setInterval(refresh, 5000);

    // ---- Stress testers ----
    async function stressStart(type) {
        let url = 'stress.php?action=start_' + type;
        if (type === 'cpu') url += '&duration=' + document.getElementById('cpu-dur').value;
        try { await fetch(url); } catch (e) { /* let pollStress reflect current state */ }
        pollStress();
    }

    async function stressStop() {
        await fetch('stress.php?action=stop');
        updateStressUI({ type: 'none' });
    }

    function updateStressUI(s) {
        const running = s.type !== 'none';
        ['cpu', 'mem', 'disk'].forEach(t => {
            const start = document.getElementById('btn-' + t + '-start');
            const stop  = document.getElementById('btn-' + t + '-stop');
            if (!start) return;
            start.disabled = running;
            stop.disabled  = !(running && s.type === t);
        });
        function podLabel(s) {
            if (!s.pod_name) return '';
            const warn = currentPodName && s.pod_name && currentPodName !== s.pod_name
                ? ' ⚠ page served by ' + currentPodName : '';
            return ' · pod: ' + s.pod_name + warn;
        }
        if (document.getElementById('cpu-stress-status'))
            $id('cpu-stress-status').textContent = s.type === 'cpu'
                ? 'Running… ' + Math.round(s.elapsed ?? 0) + 's / ' + s.duration + 's' + podLabel(s) : '';
        if (document.getElementById('mem-stress-status'))
            $id('mem-stress-status').textContent = s.type === 'mem'
                ? s.allocated_mb + ' Mi allocated in PHP heap' + podLabel(s) : '';
        if (document.getElementById('disk-stress-status'))
            $id('disk-stress-status').textContent = s.type === 'disk'
                ? s.allocated_mb + ' Mi written to /tmp' + podLabel(s) : '';
    }

    async function pollStress() {
        let s = { type: 'none' };
        try {
            const r = await fetch('stress.php?action=status');
            s = await r.json();
            updateStressUI(s);
        } catch(e) {}
        setTimeout(pollStress, s.type !== 'none' ? 2000 : 5000);
    }

    pollStress();
    </script>
</body>
</html>
