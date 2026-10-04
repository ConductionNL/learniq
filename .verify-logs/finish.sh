#!/bin/bash
# usage: finish.sh <pr> <branch>
set -u
cd /home/rubenlinde/memcap-work/lq-lanes/lq-defects || exit 9
n=$1; b=$2
[ "$(git branch --show-current)" = "$b" ] || { echo "WRONG BRANCH"; exit 9; }
if [ -n "$(git grep -lE '^(<<<<<<<|>>>>>>>) ' -- . ':!.verify-logs' 2>/dev/null)" ]; then echo "CONFLICT MARKERS LEFT"; git grep -nE '^(<<<<<<<|>>>>>>>) '; exit 4; fi
if [ -f .git/MERGE_HEAD ] || ! git diff --cached --quiet; then
  git commit -q --no-verify -m "merge development into $b" || exit 3
fi
git push -q origin "$b" || { echo PUSH FAILED; exit 2; }
r=$(git ls-remote origin refs/heads/$b | cut -f1); l=$(git rev-parse HEAD)
[ "$r" = "$l" ] || { echo "REMOTE MISMATCH $r $l"; exit 2; }
echo "pushed $l"
gh pr merge "$n" -R ConductionNL/learniq --squash --admin 2>&1 || { echo "gh pr merge failed, trying api"; gh api -X PUT repos/ConductionNL/learniq/pulls/$n/merge -f merge_method=squash 2>&1; }
gh pr view "$n" -R ConductionNL/learniq --json state,mergedAt,mergeCommit --jq '"\(.state) \(.mergedAt) \(.mergeCommit.oid)"'
git fetch -q origin development
