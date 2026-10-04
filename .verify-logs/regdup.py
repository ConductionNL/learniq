#!/usr/bin/env python3
# usage: regdup.py <registrar.php>...  exit 1 if a file registers the same (event, listener) pair twice or repeats a use line.
import re, sys
bad = 0
for p in sys.argv[1:]:
    s = open(p).read()
    pairs = re.findall(r'registerEventListener\(\s*(?:event:\s*)?([\w\\]+)::class,\s*(?:listener:\s*)?([\w\\]+)::class', s)
    uses = re.findall(r'^use [^;]+;', s, re.M)
    dp = sorted({x for x in pairs if pairs.count(x) > 1}); du = sorted({u for u in uses if uses.count(u) > 1})
    for x in dp: print(f'{p}: DUPLICATE registration {x[0]} -> {x[1]}'); bad = 1
    for u in du: print(f'{p}: DUPLICATE {u}'); bad = 1
    if not dp and not du: print(f'{p}: {len(pairs)} registrations, no duplicates')
sys.exit(bad)
