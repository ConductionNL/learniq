#!/usr/bin/env python3
# usage: keep-both-registrations.py <file>  resolves conflict blocks that are two independent listener-registration
# additions ending just before a shared closing `);` line: development's block first, then the branch's.
# Import-only blocks become the sorted union. Refuses anything else.
import re, sys
p = sys.argv[1]; s = open(p).read()
pat = re.compile(r'<<<<<<< HEAD\n(.*?)=======\n(.*?)>>>>>>> origin/development\n', re.S)
def rep(m):
    ours, theirs = m.group(1), m.group(2)
    lines = (ours + theirs).splitlines()
    if all(l.startswith('use ') for l in lines if l.strip()):
        return ''.join(sorted(set(ours.splitlines(True) + theirs.splitlines(True))))
    for blk in (ours, theirs):
        if 'registerEventListener' not in blk or not blk.rstrip().endswith('::class'):
            sys.exit(f'REFUSE: block is not a registration ending before a shared ");": {blk[-80:]!r}')
    return theirs + '\t\t);\n\n' + ours
out, n = pat.subn(rep, s)
if n == 0: sys.exit('no conflict blocks')
open(p, 'w').write(out); print(f'{p}: {n} block(s) resolved')
