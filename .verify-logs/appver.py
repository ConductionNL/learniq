#!/usr/bin/env python3
# usage: appver.py [--resolve-conflict]   (run in the clone, mid-merge: HEAD = branch, MERGE_HEAD or origin/development = dev)
# Round 3 app <version> rule for appinfo/info.xml:
#  - the merged <version> is the HIGHEST of development's and the branch's (PHP version_compare), never lower;
#  - if the branch moved <version> (it carries a new repair step) and development moved too, the merged value must be
#    STRICTLY above development's, else an install already on development's version never runs the branch's repair step.
#    When the branch's own value is not above development's, a new value is minted: development's prefix plus the highest
#    timestamp seen plus one hour.
# --resolve-conflict: the working-tree file has conflict markers; resolve them only if every hunk is the <version> line.
# Prints what it did; exit 0 ok, 3 refused (non-version conflict), 4 error.
import re, subprocess, sys, datetime
F = 'appinfo/info.xml'
def git(*a):
    r = subprocess.run(['git', *a], capture_output=True, text=True); return r.stdout if r.returncode == 0 else None
def ver_of(txt):
    m = re.search(r'<version>([^<]+)</version>', txt or ''); return m.group(1).strip() if m else None
def vc(a, b):
    r = subprocess.run(['php', '-r', 'echo version_compare($argv[1], $argv[2]);', a, b], capture_output=True, text=True)
    return int(r.stdout.strip())
dev_ref = 'MERGE_HEAD' if git('rev-parse', '-q', '--verify', 'MERGE_HEAD') else 'origin/development'
mb = (git('merge-base', 'HEAD', dev_ref) or '').strip()
D = ver_of(git('show', f'{dev_ref}:{F}')); B = ver_of(git('show', f'HEAD:{F}')); M = ver_of(git('show', f'{mb}:{F}')) if mb else None
txt = open(F).read()
if '--resolve-conflict' in sys.argv:
    pat = re.compile(r'<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n', re.S)
    hunks = pat.findall(txt)
    if not hunks: print(f'{F}: no conflict hunks'); sys.exit(0)
    for o, t in hunks:
        lines = [l for l in (o + t).splitlines() if l.strip()]
        if not all(re.fullmatch(r'\s*<version>[^<]+</version>\s*', l) for l in lines) or len(o.splitlines()) != 1 or len(t.splitlines()) != 1:
            print(f'{F}: REFUSE, a conflict hunk is not only the <version> line'); sys.exit(3)
    txt = pat.sub(lambda m: m.group(1), txt)  # branch line for now; the rule below sets the final value
W = ver_of(txt)
if not (D and B and W): print(f'{F}: missing version (dev={D} branch={B} merged={W})'); sys.exit(4)
target = D if vc(D, B) >= 0 else B
branch_moved = M is not None and B != M; dev_moved = M is not None and D != M
note = ''
if branch_moved and dev_moved and vc(target, D) <= 0:
    tss = [int(x) for v in (D, B) for x in re.findall(r'(\d{14})', v)]
    base = datetime.datetime.strptime(str(max(tss)), '%Y%m%d%H%M%S') + datetime.timedelta(hours=1)
    pre = re.sub(r'\d{14}$', '', D) if re.search(r'\d{14}$', D) else D + '-unstable.'
    target = pre + base.strftime('%Y%m%d%H%M%S')
    note = f' (minted: the branch moved <version> for a repair step and development moved to {D}, so strictly above it)'
if vc(W, target) < 0 or note:
    if W != target:
        txt = re.sub(r'<version>[^<]+</version>', f'<version>{target}</version>', txt, count=1)
    print(f'{F}: <version> {W} -> {target} (dev {D}, branch {B}, merge-base {M}){note}')
else:
    print(f'{F}: <version> {W} ok (dev {D}, branch {B}, merge-base {M})')
open(F, 'w').write(txt)
chk = ver_of(open(F).read())
if vc(chk, D) < 0 or vc(chk, B) < 0: print(f'{F}: FINAL {chk} below dev or branch'); sys.exit(4)
if branch_moved and dev_moved and vc(chk, D) <= 0: print(f'{F}: FINAL {chk} not strictly above dev {D}'); sys.exit(4)
