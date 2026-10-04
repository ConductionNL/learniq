#!/bin/bash
# usage: prep.sh <branch>
set -u
cd /home/rubenlinde/memcap-work/lq-lanes/lq-defects || exit 9
b=$1
git fetch -q origin development "$b" || exit 8
if git show-ref -q --verify refs/heads/$b; then
  git checkout -q "$b" || exit 7
  if [ -n "$(git log origin/$b..$b --oneline)" ]; then echo "UNPUSHED COMMITS on $b"; exit 6; fi
  git merge -q --ff-only origin/$b || exit 5
else
  git checkout -q -b "$b" origin/$b || exit 7
fi
git merge --no-edit --no-ff --no-commit origin/development >/dev/null 2>&1
echo "merge-exit=$?"
echo "CONFLICTS:"; git diff --name-only --diff-filter=U
for f in lib/Settings/learniq_register.json lib/Settings/learniq_mock_register.json; do
  echo "$f dev=$(git show origin/development:$f | jq -r .info.version 2>/dev/null) branch-theirs=$(git show origin/$b:$f | jq -r .info.version 2>/dev/null) work=$(jq -r .info.version $f 2>/dev/null)"
done
for f in lib/Settings/learniq_register.json lib/Settings/learniq_mock_register.json; do
  if git diff --quiet origin/development -- $f; then echo "$f unchanged vs dev"; else echo "$f CHANGED vs dev"; fi
done
