#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if command -v php >/dev/null 2>&1; then
    while IFS= read -r -d '' file; do
        php -l "$file" >/dev/null
    done < <(find . -type f -name '*.php' \
        -not -path './.git/*' \
        -not -path './vendor/api/ipsearch/vendor/*' \
        -not -path './vendor/public/phpmailer/*' \
        -print0)
    echo "PHP syntax: OK"
else
    echo "PHP syntax: skipped (php CLI not installed)"
fi

node --check assets/js/account-ui.js
node --check vendor/public/captcha/BehaviorAuth.js
echo "JavaScript syntax: OK"

git diff --check
echo "Whitespace check: OK"
