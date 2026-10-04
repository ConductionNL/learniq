#!/usr/bin/env python3
"""Insert or replace schemas in learniq_register.json and list their slugs.

Usage: r5ttb-add-schema.py <schemas.json> [info-version]
schemas.json is {"SchemaKey": {...schema...}}. Keeps the file's own format
(indent 4, ensure_ascii False, trailing newline).
"""
import json
import sys

PATH = '/home/rubenlinde/memcap-work/lq-lanes/lq-privacy/lib/Settings/learniq_register.json'

with open(PATH, encoding='utf-8') as fh:
    reg = json.load(fh)

new = json.load(open(sys.argv[1], encoding='utf-8'))
schemas = reg['components']['schemas']
listed = reg['components']['registers']['learniq']['schemas']
for key, schema in new.items():
    schemas[key] = schema
    if schema['slug'] not in listed:
        listed.append(schema['slug'])
if len(sys.argv) > 2:
    reg['info']['version'] = sys.argv[2]

with open(PATH, 'w', encoding='utf-8') as fh:
    fh.write(json.dumps(reg, indent=4, ensure_ascii=False) + '\n')
print('ok', list(new))
