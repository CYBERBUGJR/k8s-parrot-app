# Parrot App Redesign - Design Spec

Date: 2026-06-11
Status: validated with mockups (see `.superpowers/brainstorm/` sessions)

## Goal

Turn the FER-TEST demo app into an English-language Kubernetes pod inspector with a clean dashboard design. The pod's purpose is to **test the permissions of the ServiceAccount assigned to it**: depending on the scenario, an operator assigns a SA to the pod and the app displays what that SA can and cannot do, plus pod, node, cluster and resource information.

## Visual design (validated)

Light card-based dashboard, Kubernetes-blue header (`#326ce5`), background `#f4f6fa`.

- **Header**: blue rounded banner with the party parrot GIF at ~110px next to the title "Parrot App" and the tagline "El Famoso Kubernetes Parrot Inspectore !". Version and namespace shown as translucent badges on the right.
- **Row 1**: three equal flex cards: POD (name, IP, ServiceAccount), NODE (hostname, kubelet version, OS), CLUSTER (version, platform, node count).
- **Row 2**: RESOURCES card with three gauges: CPU, Memory, Ephemeral storage. Each gauge shows consumed vs request (yellow marker) vs limit (bar length). Bar colors: blue (CPU), green (memory), purple (ephemeral storage).
- **Row 3**: SERVICEACCOUNT PERMISSIONS card (2/3 width) listing rules with green `ALLOWED` / red `403 FORBIDDEN` pills, and POD LABELS card (1/3 width) with labels as gray pills.
- All layout uses flexbox with consistent 14px gaps and equal-height cards per row.
- Typography: headings (app title, tagline, card section titles) in **Space Grotesk**; body text in the system sans-serif stack. Base body size 16px, card section titles 13px uppercase, header title 30px.
- All user-facing text in English.

### Airgapped constraints

- `assets/parrot.gif` is bundled in the image (no tenor.com).
- `assets/space-grotesk.woff2` is bundled in the image (no Google Fonts).

## Architecture (approach B: PHP shell + JSON endpoint)

```
src/
├── index.php      # page shell: renders layout + Downward API info (instant)
├── api.php        # JSON endpoint: server-side calls to the Kubernetes API
├── k8s.php        # shared helper: curl to the apiserver (SA token, CA, 2s timeout)
└── assets/
    ├── parrot.gif
    ├── space-grotesk.woff2
    └── style.css
```

- Container unchanged: nginx + php-fpm (php:8.4-fpm-alpine), no Composer dependencies.
- ~50 lines of vanilla inline JavaScript: fetch `api.php` on load to fill the async cards, then refresh the metrics gauges every 10 seconds.
- `k8s.php` reads the mounted SA credentials: token from `/var/run/secrets/kubernetes.io/serviceaccount/token`, CA from `.../ca.crt`, namespace from `.../namespace`, apiserver from `KUBERNETES_SERVICE_HOST`/`_PORT`.

## Data sources

| Section | Source | When denied |
|---|---|---|
| Pod (name, IP, namespace, SA) | Downward API env vars (add `POD_NAMESPACE`, `POD_IP`, `SERVICE_ACCOUNT` via `spec.serviceAccountName`) | always available |
| Requests/limits | Downward API env vars (existing) | always available |
| SA permissions | `POST SelfSubjectRulesReview` (no RBAC required) | n/a |
| Cluster version/platform | `GET /version` (any authenticated SA) | n/a |
| Cluster node/namespace counts | `GET /api/v1/nodes`, `GET /api/v1/namespaces` | red `403` badge |
| Node info (kubelet, OS, capacity) | `GET /api/v1/nodes/<NODE_NAME>` | red `403` badge |
| CPU/memory consumed | `metrics.k8s.io` pod metrics | fallback to local PHP measurement, labelled "local" |
| Ephemeral storage consumed | kubelet stats summary via `GET /api/v1/nodes/<node>/proxy/stats/summary` | red `403` badge |

## Key behavior: SA scenario

- SA is `default` or token not mounted: the SERVICEACCOUNT PERMISSIONS section is hidden, replaced by the note "running with default ServiceAccount - assign one to test its permissions".
- Custom SA: permissions table (resource / verbs / scope) built from two sources:
  - `SelfSubjectRulesReview` for the ALLOWED rows (it only returns granted rules),
  - a small fixed probe list checked with `SelfSubjectAccessReview` for the FORBIDDEN rows (e.g. list nodes cluster-wide, delete deployments, read secrets), so denials are visible too.
- Every denied API call in the other sections shows its own `403` badge (pedagogical display, validated).

## Error handling

- Every API call: 2 second timeout, structured result `{ok, httpStatus, data}`.
- `403` renders a red badge with the denied action; unreachable apiserver or missing metrics API renders a gray `unreachable` / `unavailable` badge instead of breaking the page.
- `api.php` always returns HTTP 200 with per-section status so the front end renders partial results.

## Helm chart changes (`chart/parrot-app`)

- `values.yaml`: new `serviceAccountName` (empty by default, meaning the `default` SA) and `automountServiceAccountToken: true`.
- Deployment template: set `serviceAccountName` when provided, add env vars `POD_NAMESPACE`, `POD_IP`, `SERVICE_ACCOUNT`.
- **No RBAC shipped in the chart**: the test scenario provides the SA and its bindings externally.

## Dockerfile changes

- Copy `src/` including `assets/` (GIF, font, CSS).
- No other changes to the nginx + php-fpm setup.

## Testing

- `helm lint` and `helm template` on the chart.
- Image build.
- Test deployment with three scenarios to verify the three renderings:
  1. no SA assigned (default): permissions section hidden, 403 badges visible
  2. SA with no extra rights: permissions table nearly empty, 403 badges
  3. SA with a read Role on pods (and optionally nodes): ALLOWED pills and populated node/cluster cards
