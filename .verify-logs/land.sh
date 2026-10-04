#!/bin/bash
# usage: land.sh <pr> <branch>; exit 0 = merged, else stopped with reason printed
set -u
D=/home/rubenlinde/memcap-work/lq-lanes/lq-defects; V=$D/.verify-logs
cd $D || exit 9
n=$1; b=$2
echo "===== PR $n $b"
bash $V/prep.sh "$b" || { echo "PREP FAILED"; git merge --abort 2>/dev/null; exit 5; }
conf=$(git diff --name-only --diff-filter=U)
other=$(echo "$conf" | grep -vE '^l10n/.*\.(json|js)$|^lib/Settings/learniq(_mock)?_register\.json$|^src/manifest\.d/.*\.json$' | grep -v '^$')
if [ -n "$other" ]; then echo "STOP: non-mechanical conflicts:"; echo "$other"; git merge --abort; exit 4; fi
regc=$(echo "$conf" | grep -E '^lib/Settings/' )
manc=$(echo "$conf" | grep -E '^src/manifest\.d/' )
if [ -n "$manc" ]; then python3 $V/union-lists.py $manc || { echo "STOP: manifest union failed"; git merge --abort; exit 4; }; for f in $manc; do node -e 'JSON.parse(require("fs").readFileSync(process.argv[1]))' $f || { git merge --abort; exit 4; }; git add $f; done; npm run -s check:manifest >/dev/null 2>&1 || { echo "STOP: check:manifest red after union"; npm run -s check:manifest 2>&1 | tail -5; git merge --abort; exit 4; }; fi
if [ -n "$regc" ]; then python3 $V/union-json.py $regc || { echo "STOP: register union failed"; git merge --abort; exit 4; }; for f in $regc; do node -e 'JSON.parse(require("fs").readFileSync(process.argv[1]))' $f || { git merge --abort; exit 4; }; git add $f; done; fi
if echo "$conf" | grep -qE '^l10n/'; then bash $V/resolve-l10n.sh || { echo "STOP: l10n resolve failed"; git merge --abort; exit 4; }; fi
[ -z "$(git diff --name-only --diff-filter=U)" ] || { echo "STOP: unmerged left"; git diff --name-only --diff-filter=U; git merge --abort; exit 4; }
for f in lib/Settings/learniq_register.json lib/Settings/learniq_mock_register.json; do
  if ! git diff --quiet origin/development -- $f; then dev=$(git show origin/development:$f | jq -r .info.version); python3 $V/bump-register.py $f "$dev" || exit 4; git add $f; fi
done
bash $V/checks.sh
npm run -s check:register >/dev/null 2>&1 || { echo "STOP: check:register red"; exit 4; }
bash $V/commit.sh || { echo "STOP: commit failed"; exit 3; }
bash $V/push.sh || { echo "STOP: push failed"; exit 2; }
out=$(gh pr merge "$n" -R ConductionNL/learniq --squash --admin 2>&1) || { echo "merge attempt 1: $out"; out=$(gh pr merge "$n" -R ConductionNL/learniq --squash --admin 2>&1) || { echo "merge attempt 2: $out"; gh api -X PUT repos/ConductionNL/learniq/pulls/$n/merge -f merge_method=squash 2>&1 | tail -2; }; }
st=$(gh pr view "$n" -R ConductionNL/learniq --json state,mergeCommit --jq '"\(.state) \(.mergeCommit.oid[0:8])"')
echo "RESULT PR $n: $st"
git fetch -q origin development
echo "$(date +%H:%M) | $n | $b | $st | conflicts: $(echo $conf | tr '\n' ' ')" >> $D/MERGE-LOG.md
[ "${st%% *}" = "MERGED" ]
