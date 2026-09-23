#!/usr/bin/env python3
"""HTTP/HTML-аудит без изменений: сохраняет метаданные, не выполняет JavaScript страниц."""
import argparse, csv, json, re, time
from concurrent.futures import ThreadPoolExecutor, as_completed
from html.parser import HTMLParser
from pathlib import Path
from urllib.request import Request, build_opener, HTTPRedirectHandler
from urllib.error import HTTPError
from urllib.parse import urlparse
import xml.etree.ElementTree as ET

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Page(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.canonical=[]; self.robots=[]; self.hrefs=[]; self.h1=0; self.title=''; self.in_title=False
        self.jsonld=[]; self.script=None
    def handle_starttag(self, tag, attrs):
        a=dict(attrs)
        if tag=='h1': self.h1+=1
        if tag=='title': self.in_title=True
        if tag=='meta' and a.get('name','').lower()=='robots': self.robots.append(a.get('content',''))
        if tag=='link' and a.get('rel')=='canonical': self.canonical.append(a.get('href',''))
        if tag=='a' and 'href' in a: self.hrefs.append(a['href'])
        if tag=='script' and a.get('type')=='application/ld+json': self.script=''
    def handle_endtag(self, tag):
        if tag=='title': self.in_title=False
        if tag=='script' and self.script is not None:
            try: self.jsonld.append(json.loads(self.script))
            except ValueError: pass
            self.script=None
    def handle_data(self, data):
        if self.in_title:self.title+=data
        if self.script is not None:self.script+=data

def fetch(url):
    for attempt in range(3):
        try:
            req=Request(url,headers={'User-Agent':'FGOS-Technical-QA/1.0','Accept-Encoding':'identity'})
            try: response=build_opener(NoRedirect).open(req,timeout=20)
            except HTTPError as e: response=e
            with response:
                data=response.read(); status=response.code; headers=dict(response.headers)
            if status==429 and attempt<2:
                time.sleep(min(10, int(headers.get('Retry-After','3')) if headers.get('Retry-After','3').isdigit() else 3));continue
            return status,headers,data
        except Exception as e:
            if attempt==2:return 0,{},str(e).encode()
            time.sleep(1)

def inspect(item,base):
    path,tasks=item; status,headers,data=fetch(base.rstrip('/')+path)
    p=Page()
    if status==200 and b'<html' in data.lower():p.feed(data.decode('utf-8','replace'))
    bad=[h for h in p.hrefs if (h.startswith('/') or urlparse(h).netloc in ['fgos.pro','www.fgos.pro','localhost:8080']) and re.search(r'encodeURIComponent|_compEsc|%22|%27|\x22|\x27\s*\+',urlparse(h).path)]
    non_slash=[]
    for h in p.hrefs:
        u=urlparse(h)
        if (h.startswith('/') and not h.startswith('//')) or u.netloc in ['fgos.pro','www.fgos.pro','localhost:8080']:
            if u.path and not u.path.endswith('/') and not re.search(r'\.[^/]+$',u.path) and not re.match(r'^/(api|ajax|ai-chat|ai-consultant)/',u.path):non_slash.append(h)
    crumbs=[node for node in p.jsonld if isinstance(node,dict) and node.get('@type')=='BreadcrumbList']
    return {'tasks':','.join(sorted(tasks)),'path':path,'http':status,'location':headers.get('Location',''),'canonical':'|'.join(p.canonical),'robots':'|'.join(p.robots),
        'h1_count':p.h1,'html_bytes':len(data),'title':p.title,'breadcrumb_count':len(crumbs),'bad_hrefs':json.dumps(bad,ensure_ascii=False),'non_slash_hrefs':json.dumps(non_slash,ensure_ascii=False),'error':data.decode('utf-8','replace')[:160] if status==0 else ''}

def main():
    a=argparse.ArgumentParser();a.add_argument('--base',required=True);a.add_argument('--output',required=True);a.add_argument('--registry');a.add_argument('--all-sitemap',action='store_true');a.add_argument('--workers',type=int,default=3);args=a.parse_args()
    paths={}
    if args.registry:
        import openpyxl
        w=openpyxl.load_workbook(args.registry,read_only=True,data_only=True)
        for row in w['Реестр_URL'].iter_rows(values_only=True):
            if row[0] in ['FGOS-001','FGOS-003','FGOS-004','FGOS-014','FGOS-015','FGOS-018','FGOS-022','FGOS-023'] and row[3]:
                paths.setdefault(urlparse(row[3]).path,set()).add(row[0])
    status,_,data=fetch(args.base.rstrip('/')+'/sitemap.xml')
    if status!=200:raise RuntimeError('Sitemap HTTP '+str(status))
    locs=[urlparse(x.text).path for x in ET.fromstring(data).iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc')]
    for prefix in ['/kursy/','/material/']:
        for path in [x for x in locs if x.startswith(prefix)][:100]:paths.setdefault(path,set()).add('sample100')
    if args.all_sitemap:
        for path in locs:paths.setdefault(path,set()).add('sitemap')
    out=Path(args.output);out.parent.mkdir(parents=True,exist_ok=True)
    results=[]
    with ThreadPoolExecutor(max_workers=args.workers) as pool, out.open('w',encoding='utf-8',newline='') as f:
        writer=None
        jobs=[pool.submit(inspect,item,args.base) for item in sorted(paths.items())]
        for future in as_completed(jobs):
            result=future.result();results.append(result)
            if writer is None:writer=csv.DictWriter(f,fieldnames=result);writer.writeheader()
            writer.writerow(result);f.flush()
            if len(results)%50==0:print(f'{len(results)}/{len(jobs)}',flush=True)
    print(json.dumps({'urls':len(results),'http200':sum(r['http']==200 for r in results),'errors':sum(r['http']>=500 or r['http']==0 for r in results),'sitemap_urls':len(locs)},ensure_ascii=False))
if __name__=='__main__':main()
