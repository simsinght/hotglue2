#!/bin/bash
set -euo pipefail

echo "Starting hotglue2 preview server on port 3000..."
php -S 0.0.0.0:3000 router.php &

echo "Preview server started."
