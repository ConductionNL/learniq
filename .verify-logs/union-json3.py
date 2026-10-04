#!/usr/bin/env python3
# usage: union-json3.py [--lenient] <file>...
# Resolves a conflicted JSON file from the index stages as a THREE-WAY union: base=:1, ours=:2 (branch), theirs=:3 (incoming).
#  - dicts recurse; a key one side deleted and the other left unchanged is deleted.
#  - lists of dicts that all carry a unique scalar key (id, uuid, slug, name, key, route, title) merge BY KEY, recursing into
#    items both sides have (round 1's two-way list union kept the old and the new copy of an edited seed under one id).
#  - other lists merge as three-way sets: an item one side removed and the other kept is removed; items either side added are kept.
#  - /info/description conflict = concatenation (rule R2.2). */version conflicts keep the higher (bump-register2.py sets the final value).
#  - any other scalar conflict: printed as SCALAR CONFLICT; exit 3 unless --lenient (l10n catalogues keep the branch value).
import sys, json, subprocess, os, re
lenient = '--lenient' in sys.argv
files = [a for a in sys.argv[1:] if a != '--lenient']
def stage(n, f):
    r = subprocess.run(['git', 'show', f':{n}:{f}'], capture_output=True, text=True)
    return json.loads(r.stdout) if r.returncode == 0 else None
conflicts = []
KEYS = ('id', 'uuid', 'slug', 'name', 'key', 'route', 'title')
def pick_key(*lists):
    items = [x for l in lists for x in l]
    if not items or not all(isinstance(x, dict) for x in items): return None
    for k in KEYS:
        if all(k in x and isinstance(x[k], (str, int)) for x in items) and all(len({x[k] for x in l}) == len(l) for l in lists):
            return k
    return None
def vkey(x): return json.dumps(x, sort_keys=True)
def vtup(v):
    try: return tuple(int(p) for p in str(v).split('.'))
    except ValueError: return None
def merge(base, ours, theirs, path):
    if isinstance(ours, dict) and isinstance(theirs, dict):
        b = base if isinstance(base, dict) else {}
        out = {}
        for k in theirs:
            if k in ours: out[k] = merge(b.get(k), ours[k], theirs[k], path + '/' + k)
            elif k in b:
                if theirs[k] != b[k]: out[k] = theirs[k]   # branch deleted, incoming edited: keep edit
            else: out[k] = theirs[k]
        for k in ours:
            if k not in theirs and (k not in b or ours[k] != b[k]): out[k] = ours[k]
        return out
    if isinstance(ours, list) and isinstance(theirs, list):
        b = base if isinstance(base, list) else []
        k = pick_key(b, ours, theirs)
        kf = (lambda x: x[k]) if k else vkey
        bk = {kf(x): x for x in b}; ok = {kf(x): x for x in ours}; tk = {kf(x): x for x in theirs}
        out = []
        for x in theirs:
            kk = kf(x)
            if kk in ok: out.append(merge(bk.get(kk), ok[kk], x, f'{path}[{k}={kk}]' if k else path + '[]') if k else x)
            elif kk in bk and bk[kk] == x: continue      # branch removed it, incoming unchanged
            else: out.append(x)
        outk = [kf(x) for x in out]; prev = None
        for x in ours:
            kk = kf(x)
            if kk not in tk and not (kk in bk and bk[kk] == x):   # branch added it (or edited an item incoming removed)
                if kk not in outk:
                    pos = outk.index(prev) + 1 if prev in outk else len(out)
                    out.insert(pos, x); outk.insert(pos, kk)
            prev = kk
        return out
    if ours == theirs: return ours
    if ours == base: return theirs
    if theirs == base: return ours
    if path == '/info/description' and isinstance(ours, str) and isinstance(theirs, str) and isinstance(base, str):
        if ours.startswith(base) and theirs.startswith(base):   # both appended to the same text: dev's, then the branch's addition
            return theirs.rstrip() + ours[len(base.rstrip()):]
        pre = os.path.commonprefix([ours, theirs]); m = re.search(r'[^A-Za-z0-9]+$', pre); sep = m.group(0) if m else ' '
        return theirs.rstrip() + sep + ours[len(pre):]
    if path.endswith('/version') and vtup(ours) and vtup(theirs):
        return ours if vtup(ours) >= vtup(theirs) else theirs
    conflicts.append((path, ours, theirs))
    return ours
for f in files:
    base, ours, theirs = stage(1, f), stage(2, f), stage(3, f)
    if ours is None or theirs is None: print(f'SKIP {f}: a side is missing (delete/modify conflict)'); conflicts.append((f, 'missing', 'side')); continue
    raw = subprocess.run(['git', 'show', f':3:{f}'], capture_output=True, text=True).stdout
    line = next((l for l in raw.split('\n')[1:] if l.strip()), '    ')
    indent = '\t' if line.startswith('\t') else (len(line) - len(line.lstrip()) or 4)
    res = merge(base, ours, theirs, '')
    with open(f, 'w') as fh: fh.write(json.dumps(res, indent=indent, ensure_ascii=False) + ('\n' if raw.endswith('\n') else ''))
    print(f'resolved {f}')
for c in conflicts: print('SCALAR CONFLICT', c[0], 'branch=', json.dumps(c[1], ensure_ascii=False)[:160], 'incoming=', json.dumps(c[2], ensure_ascii=False)[:160])
sys.exit(3 if conflicts and not lenient else 0)
