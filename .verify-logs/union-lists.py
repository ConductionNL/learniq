#!/usr/bin/env python3
# usage: union-lists.py <file>...  three-way JSON union for manifest fragments: dicts recurse, lists of id-keyed dicts union by id
import sys, json, subprocess
def stage(n, f):
    r = subprocess.run(['git', 'show', f':{n}:{f}'], capture_output=True, text=True)
    return json.loads(r.stdout) if r.returncode == 0 else None
def key(x):
    if isinstance(x, dict):
        for k in ('id', 'route', 'name', 'key', 'title'):
            if k in x: return (k, x[k] if isinstance(x[k], (str, int, float, bool)) else json.dumps(x[k], sort_keys=True))
    return ('v', json.dumps(x, sort_keys=True))
def merge(base, ours, theirs):
    if isinstance(ours, dict) and isinstance(theirs, dict):
        b = base if isinstance(base, dict) else {}
        out = {}
        for k in theirs:
            if k in ours: out[k] = merge(b.get(k), ours[k], theirs[k])
            elif k in b:
                if theirs[k] != b[k]: out[k] = theirs[k]
            else: out[k] = theirs[k]
        for k in ours:
            if k not in theirs and (k not in b or ours[k] != b[k]): out[k] = ours[k]
        return out
    if isinstance(ours, list) and isinstance(theirs, list):
        b = base if isinstance(base, list) else []
        bk = {key(x): x for x in b}
        tk = {key(x): x for x in theirs}
        ok = {key(x): x for x in ours}
        out = []
        for x in theirs:  # development order, recursing into items both sides have
            k = key(x)
            if k in ok: out.append(merge(bk.get(k), ok[k], x))
            elif k in bk and ok is not None and k not in ok: continue  # ours deleted it
            else: out.append(x)
        # ours' new items: insert after their predecessor in ours when present, else append
        outk = [key(x) for x in out]
        prev = None
        for x in ours:
            k = key(x)
            if k not in tk and k not in bk:
                pos = outk.index(prev) + 1 if prev in outk else len(out)
                out.insert(pos, x); outk.insert(pos, k)
            prev = k
        return out
    if ours == theirs or ours == base: return theirs
    if theirs == base: return ours
    print('SCALAR CONFLICT, keeping branch value:', json.dumps(ours)[:100], 'vs dev', json.dumps(theirs)[:100])
    return ours
for f in sys.argv[1:]:
    base, ours, theirs = stage(1, f), stage(2, f), stage(3, f)
    raw = open(f).read()
    line = next((l for l in raw.split('\n')[1:] if l.strip()), '  ')
    indent = '\t' if line.startswith('\t') else (len(line) - len(line.lstrip()))
    res = merge(base, ours, theirs)
    with open(f, 'w') as fh: fh.write(json.dumps(res, indent=indent, ensure_ascii=False) + ('\n' if raw.endswith('\n') else ''))
    print('resolved', f)
