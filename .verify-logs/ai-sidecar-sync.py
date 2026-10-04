#!/usr/bin/env python3
# usage: ai-sidecar-sync.py   (in the clone, mid-merge: HEAD = branch, MERGE_HEAD or origin/development = dev)
# Keeps l10n/ai-translated.json true across a landing merge, by the sidecar's own rule (#1141: "New AI-written keys are
# added in the same pull request that adds them"; every PR in this landing list was written by an AI lane):
#   keys = sorted((sidecar U keys whose Dutch value either side added or changed since the merge-base) & keys of nl.json)
# A key a translator removed stays removed unless its Dutch value changed again; a key no longer in nl.json is dropped.
import json, subprocess, sys, os
F = 'l10n/ai-translated.json'
if not os.path.exists(F): print(f'{F}: absent, nothing to sync'); sys.exit(0)
def git(*a):
    r = subprocess.run(['git', *a], capture_output=True, text=True); return r.stdout if r.returncode == 0 else None
dev = 'MERGE_HEAD' if git('rev-parse', '-q', '--verify', 'MERGE_HEAD') else 'origin/development'
mb = git('merge-base', 'HEAD', dev).strip()
def nl(ref):
    s = git('show', f'{ref}:l10n/nl.json'); return json.loads(s)['translations'] if s else {}
base = nl(mb); cur = json.load(open('l10n/nl.json'))['translations']
raw = open(F).read(); doc = json.loads(raw); side = set(doc['keys'])
added = {k for k, v in cur.items() if k not in base or base[k] != v}
final = sorted((side | added) & set(cur))
plus = sorted(set(final) - side); minus = sorted(side - set(final))
if plus or minus or doc['keys'] != final:
    doc['keys'] = final
    line = next((l for l in raw.split('\n')[1:] if l.strip()), '    '); ind = '\t' if line.startswith('\t') else (len(line) - len(line.lstrip()) or 4)
    open(F, 'w').write(json.dumps(doc, indent=ind, ensure_ascii=False) + '\n')
print(f'{F}: {len(side)} -> {len(final)} keys; added {len(plus)}, dropped {len(minus)} (not in nl.json)')
for k in plus: print(f'  + {k[:110]}')
for k in minus: print(f'  - {k[:110]}')
