#!/usr/bin/env python3
# usage: ai-union.py l10n/ai-translated.json   three-way union of the conflicted sidecar from the index stages:
# keys = sorted((theirs - removed by ours) | added by ours), so a key either side added is kept and a key one side marked
# reviewed (removed) while the other left it stays removed. Other fields: the branch value when it changed, else development's.
import json, subprocess, sys
f = sys.argv[1]
def stage(n):
    r = subprocess.run(['git', 'show', f':{n}:{f}'], capture_output=True, text=True); return json.loads(r.stdout) if r.returncode == 0 else None
B, O, T = stage(1) or {}, stage(2) or {}, stage(3) or {}
kb, ko, kt = set(B.get('keys', [])), set(O.get('keys', [])), set(T.get('keys', []))
keys = sorted((kt - (kb - ko)) | (ko - kb))
out = dict(T)
for k, v in O.items():
    if k != 'keys' and (k not in B or B[k] != v): out[k] = v
out['keys'] = keys
open(f, 'w').write(json.dumps(out, indent=4, ensure_ascii=False) + '\n')
print(f'ai-union {f}: keys base {len(kb)} ours {len(ko)} theirs {len(kt)} merged {len(keys)}')
