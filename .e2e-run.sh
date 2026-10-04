#!/bin/bash
# usage: .e2e-run.sh <spec> <tag>
cd /home/rubenlinde/memcap-work/lq-lanes/lq-live2 || exit 9
export PLAYWRIGHT_BASE_URL=http://localhost:8080 LEARNIQ_E2E_ALLOW_SHARED_INSTANCE=http://localhost:8080 TMPDIR=$PWD/.tmp
npx playwright test "$1" ${3:+--grep "$3"} --project=chromium --reporter=list > .tmp/run-$2.log 2>&1
echo "exit=$?" >> .tmp/run-$2.log
