#!/bin/bash
set -euo pipefail

echo "Starting hotglue2 preview server on port 443..."
php -S 0.0.0.0:443 router.php &

echo "Preview server started."
