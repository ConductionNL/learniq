#!/bin/bash
cd /home/rubenlinde/memcap-work/lq-lanes/lq-defects || exit 9
b=$(git branch --show-current)
[ "$(git rev-parse --show-toplevel)" = "$PWD" ] || exit 9
if git grep -nE '^(<<<<<<<|>>>>>>>) ' -- . ':!.verify-logs'; then echo MARKERS; exit 4; fi
[ -z "$(git diff --name-only --diff-filter=U)" ] || { echo UNMERGED; exit 4; }
[ -z "$(git diff --name-only)" ] || { echo "UNSTAGED CHANGES:"; git diff --name-only; exit 4; }
if [ -f .git/MERGE_HEAD ] || ! git diff --cached --quiet; then git commit -q --no-verify -m "merge development into $b" || exit 3; fi
git log -1 --oneline
