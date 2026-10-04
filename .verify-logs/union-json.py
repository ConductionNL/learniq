#!/usr/bin/env python3
# usage: union-json.py <file>...   resolves a conflicted JSON file as union(dev=:3, branch=:2)
import sys, json, subprocess
def stage(n, f):
    r = subprocess.run(['git', 'show', f':{n}:{f}'], capture_output=True, text=True)
    return json.loads(r.stdout) if r.returncode == 0 else None
conflicts = []
def merge(base, ours, theirs, path):
    # theirs = development, ours = branch
    if isinstance(ours, dict) and isinstance(theirs, dict):
        b = base if isinstance(base, dict) else {}
        out = {}
        for k in theirs:
            if k in ours:
                out[k] = merge(b.get(k), ours[k], theirs[k], path + '/' + k)
            elif k in b:  # branch deleted it
                if theirs[k] != b[k]: out[k] = theirs[k]
            else:
                out[k] = theirs[k]
        for k in ours:
            if k not in theirs:
                if k in b and ours[k] == b[k]:
                    continue  # dev deleted, branch unchanged
                out[k] = ours[k]
        return out
    if isinstance(ours, list) and isinstance(theirs, list):
        out = list(theirs)
        for x in ours:
            if x not in out: out.append(x)
        return out
    if ours == theirs: return ours
    if path == '/info/description' and isinstance(ours, str) and isinstance(theirs, str) and isinstance(base, str) and ours != base and theirs != base:
        import os, re
        pre = os.path.commonprefix([ours, theirs]); m = re.search(r'[^A-Za-z0-9]+$', pre); sep = m.group(0) if m else ' '
        return theirs.rstrip() + sep + ours[len(pre):]
    if ours == base: return theirs
    if theirs == base: return ours
    conflicts.append((path, ours, theirs))
    return ours
for f in sys.argv[1:]:
    base, ours, theirs = stage(1, f), stage(2, f), stage(3, f)
    res = merge(base, ours, theirs, '')
    raw = subprocess.run(['git', 'show', f':3:{f}'], capture_output=True, text=True).stdout
    indent = '\t' if '\n\t' in raw[:200] else (len(raw.split('\n')[1]) - len(raw.split('\n')[1].lstrip()) or 4)
    with open(f, 'w') as fh:
        fh.write(json.dumps(res, indent=indent, ensure_ascii=False) + ('\n' if raw.endswith('\n') else ''))
    print(f'resolved {f}')
for c in conflicts:
    print('SCALAR CONFLICT', c[0], 'branch=', json.dumps(c[1])[:120], 'dev=', json.dumps(c[2])[:120])
