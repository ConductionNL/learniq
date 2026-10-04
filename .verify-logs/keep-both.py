import sys,re
for p in sys.argv[1:]:
    s=open(p).read()
    n=len(re.findall(r'^<<<<<<< ',s,flags=re.M))
    s=re.sub(r'^<<<<<<< [^\n]*\n(.*?)^=======\n(.*?)^>>>>>>> [^\n]*\n', lambda m:m.group(1)+m.group(2), s, flags=re.M|re.S)
    assert not re.search(r'^(<<<<<<<|=======|>>>>>>>)( |$)',s,flags=re.M), p
    open(p,'w').write(s); print('keep-both',p,n,'blocks')
