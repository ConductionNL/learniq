#!/usr/bin/env python3
# usage: bump-register2.py <file> [ref=origin/development]
# Rule 4: info.version strictly above ref's; the version of every schema that differs from ref (ignoring its version key)
# strictly above ref's. A value already strictly higher is kept. File is canonical json.dumps, so load/dump is diff-neutral;
# verified: the rewrite changes exactly the version lines it reports.
import sys, json, subprocess
args = [a for a in sys.argv[1:] if not a.startswith('--mirror=')]
mirror = next((a.split('=', 1)[1] for a in sys.argv[1:] if a.startswith('--mirror=')), None)
f = args[0]; ref = args[1] if len(args) > 1 else 'origin/development'
ms = json.load(open(mirror)).get('components', {}).get('schemas', {}) if mirror else {}
raw = open(f).read(); cur = json.loads(raw)
r = subprocess.run(['git', 'show', f'{ref}:{f}'], capture_output=True, text=True)
if r.returncode != 0: print(f'{f}: not on {ref}, nothing to compare'); sys.exit(0)
dev = json.loads(r.stdout)
def tup(v): return tuple(int(x) for x in str(v).split('.'))
def nxt(v): p = str(v).split('.'); p[-1] = str(int(p[-1]) + 1); return '.'.join(p)
line = next((l for l in raw.split('\n')[1:] if l.strip()), '    ')
indent = '\t' if line.startswith('\t') else (len(line) - len(line.lstrip()) or 4)
canonical = json.dumps(cur, indent=indent, ensure_ascii=False) + ('\n' if raw.endswith('\n') else '') == raw
bumps = []
if cur != dev and tup(cur['info']['version']) <= tup(dev['info']['version']):
    new = nxt(dev['info']['version']); bumps.append(f"info.version {cur['info']['version']} -> {new} (dev {dev['info']['version']})"); cur['info']['version'] = new
elif cur != dev: print(f"{f}: info.version {cur['info']['version']} already > dev {dev['info']['version']}")
ds = dev.get('components', {}).get('schemas', {}); cs = cur.get('components', {}).get('schemas', {})
for name, s in cs.items():
    d = ds.get(name)
    if not isinstance(d, dict) or not isinstance(s, dict) or 'version' not in s or 'version' not in d: continue
    a = {k: v for k, v in s.items() if k != 'version'}; b = {k: v for k, v in d.items() if k != 'version'}
    if a != b and tup(s['version']) <= tup(d['version']):
        mv = ms.get(name, {}).get('version') if isinstance(ms.get(name), dict) else None
        new = mv if mv and tup(mv) > tup(d['version']) else nxt(d['version'])
        bumps.append(f"schema {name} {s['version']} -> {new} (dev {d['version']}{', mirrors ' + mirror if new == mv else ''})"); s['version'] = new
if not canonical:
    # textual fallback (the branch itself wrote non-canonical JSON): only info.version can be edited safely
    import re
    out = raw
    if bumps and any(b.startswith('info.version') for b in bumps):
        i = out.index('"info"'); m = re.compile(r'"version"\s*:\s*"([^"]+)"').search(out, i)
        out = out[:m.start(1)] + cur['info']['version'] + out[m.end(1):]
    ci = out.index('"components"'); si = out.index('"schemas"', ci)
    sind = len(out[:si]) - len(out[:si].rstrip(' ')) - 0  # not used
    for b in bumps:
        if not b.startswith('schema '): continue
        name = b.split()[1]; want = cs[name]['version']
        m = re.compile(r'\n( +)"' + re.escape(name) + r'": \{\n').search(out, si)
        ind = m.group(1); end = out.index('\n' + ind + '}', m.end())
        vm = re.compile(r'\n' + ind + r' +"version": "([^"]+)"').search(out, m.end(), end)
        vind = len(vm.group(0)) - len(vm.group(0).lstrip('\n').lstrip(' ')) - 1
        # the version key must sit exactly one level below the schema key
        lvl = vm.group(0)[1:].index('"'); assert lvl > len(ind) and lvl <= len(ind) + 8, (name, lvl, len(ind))
        out = out[:vm.start(1)] + want + out[vm.end(1):]
    chk = json.loads(out); assert chk['info']['version'] == cur['info']['version']
    for n2, s2 in cs.items(): assert chk['components']['schemas'][n2].get('version') == s2.get('version'), n2
    assert chk == cur, 'textual edit does not reproduce the intended document'
    changed = sum(1 for a, b in zip(raw.split('\n'), out.split('\n')) if a != b)
    assert changed == len(bumps), (changed, bumps)
    if bumps: open(f, 'w').write(out)
    for b in bumps: print(f'{f}: {b} (textual, file not canonical)')
    if not bumps: print(f'{f}: no bump needed (file not canonical)')
    sys.exit(0)
out = json.dumps(cur, indent=indent, ensure_ascii=False) + ('\n' if raw.endswith('\n') else '')
changed = sum(1 for a, b in zip(raw.split('\n'), out.split('\n')) if a != b)
assert len(raw.split('\n')) == len(out.split('\n')) and changed == len(bumps), (changed, bumps)
open(f, 'w').write(out)
for b in bumps: print(f'{f}: {b}')
if not bumps: print(f'{f}: no bump needed')
