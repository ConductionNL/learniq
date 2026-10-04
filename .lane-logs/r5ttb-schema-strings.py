import json,sys
d=json.load(open(sys.argv[1]))
en=json.load(open('/home/rubenlinde/memcap-work/lq-lanes/lq-privacy/l10n/en.json'))['translations']
out=[]
def walk(props):
    for k,p in props.items():
        for f in ('title','description'):
            if f in p: out.append(p[f])
        for v in (p.get('x-enum-labels') or {}).values(): out.append(v)
        if 'properties' in p: walk(p['properties'])
        if isinstance(p.get('items'),dict) and 'properties' in p['items']: walk(p['items']['properties'])
for s in d.values():
    out.append(s['title']); 
    if 'description' in s: out.append(s['description'])
    walk(s['properties'])
res={}
for x in out:
    if x not in en: res[x]=""
print(json.dumps(res,indent=1,ensure_ascii=False))
