#!/usr/bin/env python3
"""Merge generated demo objects for the named schemas into learniq_mock_register.json.

Usage: r5ttb-mock-merge.py SchemaKey [SchemaKey ...]
Generates a full mock into a temp file with the vendored generator, then copies
ONLY the named schemas' objects (and their mock schema entry) into the real
mock register, leaving every other schema, object and the version untouched.
"""
import json
import subprocess
import sys
import tempfile

ROOT = '/home/rubenlinde/memcap-work/lq-lanes/lq-privacy'
GEN = ROOT + '/vendor/conduction/hydra-gates/hydra-gates/scripts/lib/generate_mock_register.py'
MOCK = ROOT + '/lib/Settings/learniq_mock_register.json'

keys = sys.argv[1:]
reg = json.load(open(ROOT + '/lib/Settings/learniq_register.json', encoding='utf-8'))
keys = keys + [reg['components']['schemas'][k]['slug'] for k in keys]
with tempfile.NamedTemporaryFile(suffix='.json', delete=False) as tmp:
    out = tmp.name
subprocess.run(['python3', GEN, ROOT, '--out', out], check=True, capture_output=True)
gen = json.load(open(out, encoding='utf-8'))
raw = open(MOCK, encoding='utf-8').read()
mock = json.loads(raw)

gen_objects = [o for o in gen['components']['objects'] if o.get('@self', {}).get('schema') in keys]
mock['components']['objects'] = [o for o in mock['components']['objects'] if o.get('@self', {}).get('schema') not in keys]
mock['components']['objects'].extend(gen_objects)
for key in keys:
    if key in gen['components'].get('schemas', {}):
        mock['components']['schemas'][key] = gen['components']['schemas'][key]

indent = 2
with open(MOCK, 'w', encoding='utf-8') as fh:
    fh.write(json.dumps(mock, indent=indent, ensure_ascii=False) + '\n')
print('merged', len(gen_objects), 'objects for', keys)
