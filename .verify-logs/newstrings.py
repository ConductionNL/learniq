#!/usr/bin/env python3
import subprocess
S='/tmp/claude-1000/-home-rubenlinde-nextcloud-docker-dev-workspace-server-apps-extra/c16bbb9a-fbd5-4a4c-8e6d-f8e40d55819f/scratchpad/'
out=subprocess.run(['node','scripts/check-schema-l10n.js','--list'],cwd='/home/rubenlinde/memcap-work/lq-lanes/lq-defects',capture_output=True,text=True)
def pairs(txt):
    L=txt.split('\n'); o={}
    for i in range(len(L)-1):
        if L[i] and not L[i].startswith(' ') and L[i+1].startswith('  '): o[L[i+1].strip()]=L[i]
    return o
a=pairs(open(S+'dev-list.txt').read()); b=pairs(out.stdout+out.stderr)
for s in b:
    if s not in a and not s.startswith('node '): print('NEW:', b[s], '|', s[:160])
