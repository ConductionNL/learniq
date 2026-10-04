#!/usr/bin/env python3
"""Add catalogue keys: en.json identity, nl.json value, ai-translated keys (sorted).

Usage: r5ttb-add-l10n.py <mapping.json>   where mapping is {"English": "Nederlands"}.
Existing keys are left as they are.
"""
import json
import sys

ROOT = '/home/rubenlinde/memcap-work/lq-lanes/lq-privacy/l10n/'


def load(name):
    with open(ROOT + name, encoding='utf-8') as fh:
        return json.load(fh)


def save(name, data):
    with open(ROOT + name, 'w', encoding='utf-8') as fh:
        fh.write(json.dumps(data, indent=4, ensure_ascii=False) + '\n')


mapping = json.load(open(sys.argv[1], encoding='utf-8'))
en = load('en.json')
nl = load('nl.json')
ai = load('ai-translated.json')
added = 0
for key, value in mapping.items():
    if key not in en['translations']:
        en['translations'][key] = key
    if key not in nl['translations']:
        nl['translations'][key] = value
        added += 1
        if key not in ai['keys']:
            ai['keys'].append(key)
ai['keys'] = sorted(set(ai['keys']))
save('en.json', en)
save('nl.json', nl)
save('ai-translated.json', ai)
print(f'added {added} nl keys')
