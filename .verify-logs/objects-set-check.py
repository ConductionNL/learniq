#!/usr/bin/env python3
# usage: objects-set-check.py <merge-commit>  checks the mock register's components.objects list of a merge commit as a
# three-way set: merged == theirs - (removed by ours) + (added by ours), with ours = first parent, theirs = second parent.
import json, subprocess, sys
M = sys.argv[1]; f = 'lib/Settings/learniq_mock_register.json'
def objs(ref): return {json.dumps(o, sort_keys=True) for o in json.loads(subprocess.run(['git', 'show', f'{ref}:{f}'], capture_output=True, text=True).stdout)['components']['objects']}
p1, p2 = f'{M}^1', f'{M}^2'
mb = subprocess.run(['git', 'merge-base', p1, p2], capture_output=True, text=True).stdout.strip()
B, O, T, R = objs(mb), objs(p1), objs(p2), objs(M)
exp = (T - (B - O)) | (O - B)
print(f'objects: base {len(B)} ours {len(O)} theirs {len(T)} merged {len(R)}; ours +{len(O-B)} -{len(B-O)}; merged == expected: {R == exp} (missing {len(exp-R)}, extra {len(R-exp)})')
sys.exit(0 if R == exp else 1)
