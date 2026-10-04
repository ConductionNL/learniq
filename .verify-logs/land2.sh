#!/bin/bash
# land2.sh, round 2 landing (extends land.sh). Phases, each exits 0 on success:
#   prep   <pr> <branch> [<base-pr> <base-branch>]  checkout, (stacked) merge the landed base, merge development, auto-resolve
#   verify <pr>     l10n:build, register bumps (rule 4), dup scan (R2.2), npm checks (R2.5), php -l, FULL PHPUnit vs current set (R2.1), phpmd
#   ship   <pr>     commit + push (no merge)
#   merge  <pr>     gh pr merge --squash --admin, confirm MERGED, fetch, refresh the current failure set, log
#   all    <pr> <branch> [<base-pr> <base-branch>]  prep, verify, ship (merge stays a separate call: R2.7)
# Stacked PR (base landed as squash S): merge origin/<base-branch> (its final tip) normally, then record S with
# `merge -s ours` ONLY when tree(S) == tree(origin/<base-branch>), so the development merge sees only post-S changes
# and the stacked diff shrinks to its own commits. If the trees differ, S is left to the ordinary development merge.
set -u -o pipefail
D=/home/rubenlinde/memcap-work/lq-lanes/lq-defects; V=$D/.verify-logs; R=ConductionNL/learniq; LOG=$V/MERGE-LOG-R2.md
cd $D || exit 9
[ "$(git rev-parse --show-toplevel)" = "$D" ] || { echo "WRONG TOPLEVEL"; exit 9; }
REGS="lib/Settings/learniq_register.json lib/Settings/learniq_mock_register.json"
ph=$1; n=$2; ST=$V/state-$n
note(){ echo "$*"; echo "$*" >> $ST; }
resolve(){  # $1 = label of incoming side; resolves every conflicted file it can, returns 4 if manual work is left
  local conf other f
  conf=$(git diff --name-only --diff-filter=U)
  [ -z "$conf" ] && { note "conflicts($1): none"; return 0; }
  note "conflicts($1): $(echo $conf)"
  for f in $(echo "$conf" | grep -E '^lib/Settings/.*register.*\.json$|^src/manifest\.d/.*\.json$'); do
    python3 $V/union-json3.py "$f" | tee -a $ST || { note "STOP: scalar conflict in $f (R2.2)"; return 4; }
    node -e 'JSON.parse(require("fs").readFileSync(process.argv[1]))' "$f" || return 4; git add "$f"
  done
  js=$(echo "$conf" | grep -E '^l10n/.*\.json$')
  if [ -n "$js" ]; then python3 $V/union-json3.py --lenient $js | tee -a $ST; for f in $js; do node -e 'JSON.parse(require("fs").readFileSync(process.argv[1]))' $f || return 4; git add $f; done; fi
  if echo "$conf" | grep -qE '^l10n/'; then npm run -s l10n:build >/dev/null || return 4; git add l10n/*.js; fi
  other=$(git diff --name-only --diff-filter=U)
  [ -n "$other" ] && { note "MANUAL: non-mechanical conflicts left: $(echo $other)"; return 4; }
  return 0
}
case $ph in
prep)
  b=$3; bp=${4:-}; bb=${5:-}
  [ -f .git/MERGE_HEAD ] && { echo "MERGE IN PROGRESS, refusing"; exit 9; }
  : > $ST; note "== PR $n $b $(date +%H:%M) base-pr=${bp:-none}"
  git fetch -q origin development "$b" ${bb:+"$bb"} || exit 8
  if git show-ref -q --verify refs/heads/$b; then
    git checkout -q "$b" || exit 7
    up=$(git log $b --not --remotes --format=%s)
    if [ -n "$up" ] && [ -n "$(echo "$up" | grep -v '^merge ')" ]; then note "UNPUSHED COMMITS on $b"; exit 6; fi
    if [ -n "$up" ]; then note "resuming: local merge commits not yet pushed: $(echo "$up" | tr '\n' ';')"; git merge-base --is-ancestor origin/$b HEAD || exit 5
    else git merge -q --ff-only origin/$b || exit 5; fi
  else git checkout -q -b "$b" origin/$b || exit 7; fi
  note "branch tip $(git rev-parse --short HEAD); dev $(git rev-parse --short origin/development); PR files vs dev before: $(git diff --name-only origin/development...HEAD | wc -l)"
  if [ -n "$bp" ]; then
    S=$(gh pr view $bp -R $R --json state,mergeCommit --jq 'select(.state=="MERGED")|.mergeCommit.oid')
    [ -z "$S" ] && { note "STOP: base #$bp not merged"; exit 4; }
    git cat-file -e $S 2>/dev/null || git fetch -q origin development
    if git merge-base --is-ancestor $S HEAD; then note "stack: $S already in history"
    else
      if ! git merge-base --is-ancestor origin/$bb HEAD; then
        git merge --no-edit --no-ff --no-commit origin/$bb >/dev/null 2>&1
        resolve "base $bb" || exit 4
        git commit -q --no-verify -m "merge $bb into $b" && note "stack: merged final $bb ($(git rev-parse --short origin/$bb))"
        for f in $(sed -n '/^conflicts(base/,/^stack: merged final/p' $ST | grep -oE '^resolved [^ ]+' | cut -d' ' -f2 | sort -u); do
          MERGED_REF=HEAD python3 $V/union-audit.py $f HEAD^1 HEAD^2 > $V/audit-$n-base-$(basename $f).log 2>&1; grep BOTH-CHANGED $V/audit-$n-base-$(basename $f).log | cut -c1-200 | tee -a $ST
          tail -1 $V/audit-$n-base-$(basename $f).log | tee -a $ST; grep -q ': 0 mismatches' $V/audit-$n-base-$(basename $f).log || { note "STOP: base union audit mismatch in $f"; exit 4; }
        done
      else note "stack: $bb final already merged"; fi
      if git diff --quiet $S origin/$bb; then
        git merge -q -s ours --no-edit -m "merge development's squash of #$bp into $b (its tree equals $bb)" $S && note "stack: recorded squash ${S:0:8} (-s ours, tree(S)==tree($bb) verified)"
      elif [ "$(git merge-tree --write-tree origin/$bb $S^1 2>/dev/null | head -1)" = "$(git rev-parse $S^{tree})" ]; then
        # development moved between the base's last merge and its squash: take S^1 normally, then S is exactly base-final + S^1
        git merge --no-edit --no-ff --no-commit $S^1 >/dev/null 2>&1; resolve "development up to ${S:0:8}^1" || exit 4
        git commit -q --no-verify -m "merge development up to $(git rev-parse --short $S^1) into $b" && note "stack: merged S^1 $(git rev-parse --short $S^1) normally"
        git merge -q -s ours --no-edit -m "merge development's squash of #$bp into $b (its tree equals the merge of $bb and $(git rev-parse --short $S^1), both merged here)" $S && note "stack: recorded squash ${S:0:8} (-s ours, tree(S)==merge-tree($bb, S^1) verified)"
      else note "stack: tree(squash ${S:0:8}) != tree($bb): $(git diff --stat $S origin/$bb | tail -1); left to the development merge"; fi
    fi
  fi
  git merge --no-edit --no-ff --no-commit origin/development >/dev/null 2>&1; note "merge-dev exit=$?"
  resolve development || exit 4
  note "prep OK"
  ;;
verify)
  [ -z "$(git diff --name-only --diff-filter=U)" ] || { echo "UNMERGED LEFT"; exit 4; }
  git grep -nE '^(<<<<<<<|>>>>>>>)( |$)' -- . ':!.verify-logs' && { echo MARKERS; exit 4; }
  npm run -s l10n:build >/dev/null || exit 4; git add l10n/*.js
  for f in $REGS; do if ! git diff --quiet origin/development -- $f; then python3 $V/bump-register2.py $f $([ $f = lib/Settings/learniq_mock_register.json ] && echo --mirror=lib/Settings/learniq_register.json) | tee -a $ST || exit 4; git add $f; fi; done
  for f in $REGS; do python3 $V/scan-register.py $f | grep -v '^inherited' | tee -a $ST; [ ${PIPESTATUS[0]} = 0 ] || { note "STOP: NEW duplicate in $f (R2.2)"; exit 4; }; done
  for f in $(sed -n '/^conflicts(development)/,$p' $ST | grep -oE '^resolved [^ ]+' | cut -d' ' -f2 | sort -u); do
    python3 $V/union-audit.py $f HEAD > $V/audit-$n-$(basename $f).log 2>&1; grep -E 'BOTH-CHANGED' $V/audit-$n-$(basename $f).log | cut -c1-200 | tee -a $ST
    tail -1 $V/audit-$n-$(basename $f).log | tee -a $ST; grep -q ': 0 mismatches' $V/audit-$n-$(basename $f).log || { note "STOP: union audit mismatch in $f"; exit 4; }
  done
  bad=0; res=""
  for c in check:specs check:schema-l10n check:l10n-js; do npm run -s $c > $V/npm-$n-$c.log 2>&1; e=$?; res="$res $c=$e"; [ $e = 0 ] || bad=1; done
  note "checks:$res"; [ $bad = 0 ] || { note "STOP: npm check red"; exit 4; }
  pl=0
  cf=$(grep -oE '^conflicts\([^)]*\): .*' $ST | sed 's/^[^:]*: //' | tr ' ' '\n' | grep '\.php$' | sort -u)
  for f in $cf; do [ -f $f ] || { note "php -l skip $f (deleted by the resolution)"; continue; }; php -l $f >/dev/null 2>&1 || { note "php -l FAIL $f"; pl=1; }; done; note "php -l on conflicted PHP ($(echo $cf | wc -w) files) exit=$pl"; [ $pl = 0 ] || exit 4
  bash $V/phpunit-set.sh pr$n | tee -a $ST
  new=$(comm -13 $V/phpunit-current.fails $V/phpunit-pr$n.fails); fixed=$(comm -23 $V/phpunit-current.fails $V/phpunit-pr$n.fails)
  note "phpunit: $(wc -l < $V/phpunit-pr$n.fails) defects; new vs current dev set: $(echo -n "$new" | grep -c .); fixed: $(echo -n "$fixed" | grep -c .)"
  [ -n "$fixed" ] && note "fixed: $(echo $fixed)"
  if [ -n "$new" ]; then note "NEW FAILURES:"; echo "$new" | tee -a $ST; exit 4; fi
  if ! git diff --quiet origin/development -- lib/; then
    HOME=$D/.tmp/home composer phpmd > $V/phpmd-pr$n.log 2>&1
    grep -oE 'lib/[^ ]+\.php:[0-9]+ +[A-Za-z]+ .*' $V/phpmd-pr$n.log | sed -E 's/\.php:[0-9]+ +/.php /; s/ +/ /g' | sort -u > $V/phpmd-pr$n.set
    grep -oE 'lib/[^ ]+\.php:[0-9]+ +[A-Za-z]+ .*' $V/phpmd-baseline-b1165973.log | sed -E 's/\.php:[0-9]+ +/.php /; s/ +/ /g' | sort -u > $V/phpmd-base.set
    pn=$(comm -13 <(cut -d' ' -f1,2 $V/phpmd-base.set | sort -u) <(cut -d' ' -f1,2 $V/phpmd-pr$n.set | sort -u))
    note "phpmd: $(wc -l < $V/phpmd-pr$n.set) findings; new (file+rule) vs baseline: $(echo -n "$pn" | grep -c .) $(echo $pn)"
  else note "phpmd: lib/ unchanged vs dev, not run"; fi
  note "verify OK"
  ;;
ship)
  bash $V/commit.sh | tee -a $ST; [ ${PIPESTATUS[0]} = 0 ] || exit 3
  bash $V/push.sh | tee -a $ST; [ ${PIPESTATUS[0]} = 0 ] || exit 2
  note "PR files vs dev after: $(git diff --name-only origin/development...HEAD | wc -l) (+$(git diff --shortstat origin/development...HEAD | grep -oE '[0-9]+ insertion' | cut -d' ' -f1)/-$(git diff --shortstat origin/development...HEAD | grep -oE '[0-9]+ deletion' | cut -d' ' -f1))"
  ;;
merge)
  out=$(gh pr merge "$n" -R $R --squash --admin 2>&1); e=$?; note "gh pr merge exit=$e $out"
  if [ $e != 0 ] && echo "$out" | grep -qi 'could not resolve'; then gh api -X PUT repos/$R/pulls/$n/merge -f merge_method=squash 2>&1 | tail -2 | tee -a $ST; fi
  st=$(gh pr view "$n" -R $R --json state,mergeCommit --jq '"\(.state) \(.mergeCommit.oid)"'); note "RESULT PR $n: $st"
  git fetch -q origin development
  if [ "${st%% *}" = "MERGED" ]; then
    if git diff --quiet origin/development HEAD; then cp $V/phpunit-pr$n.fails $V/phpunit-current.fails; note "dev tree == tested tree; current failure set = pr$n ($(wc -l < $V/phpunit-current.fails))"
    else note "WARN dev tree != tested tree ($(git diff --stat HEAD origin/development | tail -1)); refresh the current set"; touch $V/REFRESH-CURRENT; fi
    echo "| #$n | $(git branch --show-current) | ${st#* } | $(grep -m1 '^conflicts(development)' $ST | cut -c1-200) | $(grep -m1 '^checks:' $ST) | $(grep -m1 '^phpunit:' $ST) | $(grep -m1 '^phpmd:' $ST | cut -c1-120) |" >> $LOG
    exit 0
  fi
  exit 1
  ;;
all)
  bash $0 prep $n "$3" "${4:-}" "${5:-}" && bash $0 verify $n && bash $0 ship $n
  ;;
esac
