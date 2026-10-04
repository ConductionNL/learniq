#!/bin/bash
cd /home/rubenlinde/memcap-work/lq-lanes/lq-defects || exit 9
npm run -s check:register >/dev/null 2>&1; echo reg=$?
npm run -s check:schema-l10n 2>&1 | head -1; npm run -s check:schema-l10n >/dev/null 2>&1; echo sl10n=$?
npm run -s check:l10n-js >/dev/null 2>&1; echo l10njs=$?
