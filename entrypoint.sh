#!/bin/sh
set -e

echo "=== Parrot App Startup ==="
echo "Version:           ${VERSION:-unknown}"
echo "Pod:               ${POD_NAME:-unknown}"
echo "Namespace:         ${POD_NAMESPACE:-unknown}"
echo "Node:              ${NODE_NAME:-unknown}"
echo "Service Account:   ${SERVICE_ACCOUNT:-unknown}"
echo "Pod IP:            ${POD_IP:-unknown}"
echo ""
echo "--- Runtime ---"
php --version | head -1
echo "Nginx:             $(nginx -v 2>&1)"
echo "=========================="
echo ""

nginx
exec php-fpm
