#!/usr/bin/env python3
# usage: scan-register.py <file> [ref=origin/development]   Rule R2.2 scan.
# Reports lists of objects with a repeated id/uuid, and recipients lists holding near duplicates (same kind and one
# entry's values a subset of another's). Prints findings NEW versus ref (inherited ones listed separately); exit 1 if any NEW.
import sys, json, subprocess
f = sys.argv[1]; ref = sys.argv[2] if len(sys.argv) > 2 else 'origin/development'
def scan(d):
    out = set()
    def walk(x, p):
        if isinstance(x, dict):
            for k, v in x.items():
                if k == 'recipients' and isinstance(v, list): rec(v, p + '/' + k)
                walk(v, p + '/' + k)
        elif isinstance(x, list):
            for key in ('id', 'uuid'):
                vals = [i[key] for i in x if isinstance(i, dict) and isinstance(i.get(key), (str, int))]
                for v in sorted({v for v in vals if vals.count(v) > 1}, key=str): out.add(f'DUP {key}={v} at {p}')
            for i, v in enumerate(x): walk(v, f'{p}[{i}]' if not isinstance(v, dict) else p + '[]')
    def flat(e):
        s = set()
        for k, v in e.items():
            if k == 'kind': continue
            for i in (v if isinstance(v, list) else [v]): s.add(f'{k}={json.dumps(i, sort_keys=True)}')
        return s
    def rec(lst, p):
        es = [e for e in lst if isinstance(e, dict)]
        for i in range(len(es)):
            for j in range(len(es)):
                if i < j and es[i].get('kind') == es[j].get('kind'):
                    a, b = flat(es[i]), flat(es[j])
                    if a <= b or b <= a: out.add(f'NEAR-DUP recipients at {p}: {json.dumps(es[i], sort_keys=True)} ~ {json.dumps(es[j], sort_keys=True)}')
    walk(d, ''); return out
cur = scan(json.load(open(f)))
r = subprocess.run(['git', 'show', f'{ref}:{f}'], capture_output=True, text=True)
old = scan(json.loads(r.stdout)) if r.returncode == 0 else set()
new = sorted(cur - old)
for x in sorted(cur & old): print('inherited:', x[:220])
for x in new: print('NEW:', x[:300])
print(f'{f}: {len(new)} new, {len(cur & old)} inherited, {len(old - cur)} fixed')
sys.exit(1 if new else 0)
