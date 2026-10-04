#!/bin/bash
cd /home/rubenlinde/memcap-work/lq-lanes/lq-defects || exit 9
[ "$(git rev-parse --show-toplevel)" = "$PWD" ] || exit 9
js=$(git diff --name-only --diff-filter=U -- 'l10n/*.json')
[ -n "$js" ] && python3 .verify-logs/union-json.py $js
for f in $js; do node -e 'JSON.parse(require("fs").readFileSync(process.argv[1]))' $f && echo "$f parse=0" || { echo "$f parse FAIL"; exit 3; }; done
npm run -s l10n:build >/dev/null || exit 3
for f in $js; do git add "$f" "${f%.json}.js"; done
npm run -s check:l10n-js >/dev/null; echo l10njs=$?
npm run -s check:schema-l10n 2>&1 | tail -2; echo sl10n=${PIPESTATUS[0]}
npm run -s check:register | tail -1; echo reg=${PIPESTATUS[0]}
echo "REMAINING UNMERGED:"; git diff --name-only --diff-filter=U
