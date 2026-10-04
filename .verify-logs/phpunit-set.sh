#!/bin/bash
# usage: phpunit-set.sh <label>  runs the FULL suite (phpunit.xml: Unit + Integration), writes
#   .verify-logs/phpunit-<label>.log and .verify-logs/phpunit-<label>.fails (sorted "category|test" set:
#   failures, errors, risky). Prints the summary line and the count. Exit 0 always (the set is the result).
set -u
D=/home/rubenlinde/memcap-work/lq-lanes/lq-defects; V=$D/.verify-logs
cd $D || exit 9
l=$1
# round 3: the suite's own temp files go under the clone (MbzExtractorTest leaks ~100 MB per run in its
# learniq_test_mbz_oversize_build_* dir; on a 98%-full disk that is not harmless), and this lane's leak is removed after.
T=$D/.tmp/phpunit-tmp; mkdir -p $T
TMPDIR=$T ./vendor/bin/phpunit --colors=never --no-coverage > $V/phpunit-$l.log 2>&1; echo "phpunit-exit=$?"
for w in $T/learniq_test_mbz_oversize_build_*; do [ -d "$w" ] || continue; rm -f "$w/oversized.tar" "$w/big.bin" "$w/oversized.tar.gz"; rmdir "$w" 2>/dev/null; done
python3 - "$V/phpunit-$l.log" > $V/phpunit-$l.fails <<'PY'
import sys, re
cat = None; out = set()
for line in open(sys.argv[1], errors='replace'):
    m = re.match(r'^There (?:was|were) \d+ (error|failure|risky test)s?:', line)
    if m: cat = m.group(1); continue
    if re.match(r'^There (?:was|were) \d+ ', line) or line.startswith(('FAILURES!', 'OK', 'ERRORS!', 'Tests: ')): cat = None; continue
    m = re.match(r'^\d+\) (\S.*?)\s*$', line)
    if cat and m: out.add(f'{cat}|{m.group(1)}')
for x in sorted(out): print(x)
PY
grep -E '^(Tests: |OK \()' $V/phpunit-$l.log | tail -1
echo "defects=$(wc -l < $V/phpunit-$l.fails)"
