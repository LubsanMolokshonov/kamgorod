// Проверка локальных HTML через установленный Playwright; только чтение и снимки.
import fs from 'node:fs';
import path from 'node:path';
import {pathToFileURL,fileURLToPath} from 'node:url';
const playwright=await import(process.env.EDITORIAL_PLAYWRIGHT_MODULE);
const {chromium}=playwright.default ?? playwright;
const out=path.dirname(fileURLToPath(import.meta.url));
const root=path.resolve(out,'../../..');
const slugs=['gde-skachat-materialy-razgovory-o-vazhnom','razgovory-o-vazhnom-po-klassam','kak-provodit-razgovory-o-vazhnom'];
const browser=await chromium.launch({headless:true});
const results=[];
try {
 for(const [name,file,dir] of [['catalog',path.join(out,'index.html'),out],...slugs.map(s=>[s,path.join(root,'editorial/articles',s,'preview.html'),path.join(root,'editorial/articles',s)])]) {
  fs.mkdirSync(path.join(dir,'previews'),{recursive:true});
  for(const width of [1440,390]) {
   const page=await browser.newPage({viewport:{width,height:1000},deviceScaleFactor:1});
   await page.goto(pathToFileURL(file).href);
   await page.evaluate(()=>document.fonts.ready);
   const metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,images:[...document.images].map(i=>({loaded:i.complete&&i.naturalWidth>0,natural:[i.naturalWidth,i.naturalHeight],box:[i.getBoundingClientRect().width,i.getBoundingClientRect().height],fit:getComputedStyle(i).objectFit})),tables:[...document.querySelectorAll('.pub-body table')].map(t=>({width:t.getBoundingClientRect().width,overflow:getComputedStyle(t).overflowX})),h1:document.querySelectorAll('h1').length}));
   if(metrics.scrollWidth>width||metrics.images.some(i=>!i.loaded)||metrics.h1!==1) throw new Error(name+' '+width+' '+JSON.stringify(metrics));
   await page.screenshot({path:path.join(dir,'previews',width+'.png'),fullPage:true});
   fs.writeFileSync(path.join(dir,'previews',width+'.json'),JSON.stringify(metrics,null,2));
   results.push({name,...metrics});
   await page.close();
  }
 }
} finally {await browser.close();}
console.log(JSON.stringify(results,null,2));
