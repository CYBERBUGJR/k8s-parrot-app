# Contributing

Issues and PRs are welcome. The app is intentionally simple — keep it that way.

- No frameworks, no npm, no build step
- All K8s calls go through `src/k8s.php` helpers
- New dashboard sections belong in `api.php` (data) + `index.php` (render)
