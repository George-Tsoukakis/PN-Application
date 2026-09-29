import re,os,datetime,sys
ver=sys.argv[1]
old=open('languages/qr-rebuilder-pro.pot',encoding='utf-8').read()
blocks=old.split('\n\n'); header=blocks[0]
plugin_hdr=[b for b in blocks[1:] if b.startswith('#. ') and any(w in b.split('\n')[0] for w in ('Plugin','Author','Description'))]
old_ids=set(re.findall(r'^msgid "(.*)"$',old,re.M))
S=r"'((?:[^'\\]|\\.)*)'"
pat=re.compile(r"(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*"+S+r"\s*,\s*'qr-rebuilder-pro'\s*\)",re.S)
patn=re.compile(r"_n\(\s*"+S+r"\s*,\s*"+S+r"\s*,[^,]+,\s*'qr-rebuilder-pro'\s*\)",re.S)
patx=re.compile(r"(?:_x|esc_html_x|esc_attr_x)\(\s*"+S+r"\s*,\s*"+S+r"\s*,\s*'qr-rebuilder-pro'\s*\)",re.S)
unesc=lambda s:s.replace("\\'","'").replace("\\\\","\\")
po=lambda s:s.replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\t','\\t')
entries={};order=[]
files=sorted(os.path.join(d,f)[2:] for d,_,fs in os.walk('.') if 'vendor' not in d for f in fs if f.endswith('.php'))
for f in files:
    src=open(f,encoding='utf-8').read()
    line=lambda p:src.count('\n',0,p)+1
    def com(p):
        m=re.search(r'/\*\s*(translators:.*?)\*/\s*$',src[max(0,p-400):p],re.S); return m.group(1).strip() if m else None
    for rx,kind in ((pat,0),(patn,1),(patx,2)):
        for m in rx.finditer(src):
            k=('',unesc(m.group(1)),None) if kind==0 else (('',unesc(m.group(1)),unesc(m.group(2))) if kind==1 else (unesc(m.group(2)),unesc(m.group(1)),None))
            e=entries.setdefault(k,{'refs':[],'c':None})
            if k not in order: order.append(k)
            e['refs'].append(f"{f}:{line(m.start())}"); e['c']=e['c'] or com(m.start())
now=datetime.datetime.now(datetime.UTC).strftime('%Y-%m-%d %H:%M:%S+00:00')
header=re.sub(r'Project-Id-Version: QR ReBuilder Pro [0-9.]+',"Project-Id-Version: QR ReBuilder Pro "+ver,header)
header=re.sub(r'POT-Creation-Date: [^\\]+',"POT-Creation-Date: "+now,header)
out=[header];seen=set()
for b in plugin_hdr:
    mid=re.search(r'^msgid "(.*)"$',b,re.M).group(1); seen.add(mid); out.append(b)
new=0
for k in order:
    ctx,mid,pl=k;e=entries[k]
    if po(mid) in seen: continue
    L=[]
    if e['c']: L.append('#. '+e['c'].replace('\n',' '))
    L.append('#: '+' '.join(e['refs']))
    if ctx: L.append(f'msgctxt "{po(ctx)}"')
    L.append(f'msgid "{po(mid)}"')
    L+= [f'msgid_plural "{po(pl)}"','msgstr[0] ""','msgstr[1] ""'] if pl else ['msgstr ""']
    if po(mid) not in old_ids: new+=1
    out.append('\n'.join(L))
open('languages/qr-rebuilder-pro.pot','w',encoding='utf-8').write('\n\n'.join(out)+'\n')
cur=set(re.findall(r'^msgid "(.*)"$','\n\n'.join(out),re.M))
print('entries',len(out)-1,'new',new,'dropped',len(old_ids-cur))
