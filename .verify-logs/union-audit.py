#!/usr/bin/env python3
# usage: union-audit.py <file> <branch-ref> [dev-ref=origin/development]
# Checks a merged JSON file: every path the branch changed (vs merge-base) holds the branch value, every path development
# changed holds dev's value, and nothing else differs from dev. Version keys ignored (rule 4 sets them). Prints disagreements.
import sys, json, subprocess
f, br = sys.argv[1], sys.argv[2]; dv = sys.argv[3] if len(sys.argv) > 3 else 'origin/development'
def show(ref):
    r = subprocess.run(['git', 'show', f'{ref}:{f}'], capture_output=True, text=True); return json.loads(r.stdout) if r.returncode == 0 else None
mb = subprocess.run(['git', 'merge-base', dv, br], capture_output=True, text=True).stdout.strip()
import os
B, O, T = show(mb), show(br), show(dv); M = show(os.environ["MERGED_REF"]) if os.environ.get("MERGED_REF") else json.load(open(f))
def flat(x, p='', out=None):
    out = {} if out is None else out
    if isinstance(x, dict):
        for k, v in x.items(): flat(v, p + '/' + k, out)
    elif isinstance(x, list) and not x:
        out[p + '#list'] = 'list'   # an empty list and a keyed list share this marker, so emptying one is not a false mismatch
    elif isinstance(x, list) and all(isinstance(i, dict) for i in x):
        out[p + '#list'] = 'list'
        for k in ('id', 'uuid', 'slug', 'name', 'key'):
            if all(k in i and isinstance(i[k], (str, int)) for i in x) and len({i[k] for i in x}) == len(x):
                for i in x: flat(i, f'{p}[{k}={i[k]}]', out)
                return out
        out[p] = json.dumps(sorted(json.dumps(i, sort_keys=True) for i in x))
    elif isinstance(x, list): out[p] = json.dumps(sorted(json.dumps(i, sort_keys=True) for i in x))
    else: out[p] = json.dumps(x)
    return out
b, o, t, m = flat(B), flat(O), flat(T), flat(M)
bad = 0
for p in sorted(set(b) | set(o) | set(t) | set(m)):
    if p.endswith('/version'): continue
    bo, oo, to, mo = b.get(p), o.get(p), t.get(p), m.get(p)
    exp = oo if oo != bo else to        # branch changed -> branch value, else dev value (None = absent)
    if oo != bo and to != bo and oo != to:   # both changed differently: list union / concatenation, reported
        print('BOTH-CHANGED', p[:140], '| merged', 'has' if mo else 'LACKS', 'it'); continue
    if mo != exp: bad += 1; print('MISMATCH', p[:160], '\n   merged=', (mo or 'None')[:200], '\n   expected=', (exp or 'None')[:200])
print(f'{f}: {bad} mismatches')
