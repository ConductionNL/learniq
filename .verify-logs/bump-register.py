#!/usr/bin/env python3
# usage: bump-register.py <file> <dev-version>: sets info.version to dev-version patch+1 if not already strictly higher, textually (no reformat)
import sys, json, re
f, dev = sys.argv[1], sys.argv[2]
txt = open(f).read()
cur = json.loads(txt)['info']['version']
def tup(v): return tuple(int(x) for x in v.split('.'))
if tup(cur) > tup(dev):
    print(f"{f}: {cur} already > dev {dev}"); sys.exit(0)
parts = dev.split('.'); parts[-1] = str(int(parts[-1]) + 1); new = '.'.join(parts)
i = txt.index('"info"'); m = re.compile(r'"version"\s*:\s*"([^"]+)"').search(txt, i)
if not m or m.group(1) != cur: print("NO info.version MATCH"); sys.exit(2)
txt = txt[:m.start(1)] + new + txt[m.end(1):]
open(f, 'w').write(txt)
assert json.loads(txt)['info']['version'] == new
print(f"{f}: {cur} -> {new} (dev {dev})")
