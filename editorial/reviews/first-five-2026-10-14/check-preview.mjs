// Браузерная проверка локальных материалов. Не публикует и не обращается к БД.
import fs from 'node:fs';
import path from 'node:path';
import {pathToFileURL,fileURLToPath} from 'node:url';
import {chromium} from '../../../tests/ui/node_modules/@playwright/test/index.mjs';
const out=path.dirname(fileURLToPath(import.meta.url));
const root=path.resolve(out,'../../..');
const slugs=['den-otca-v-detskom-sadu','prazdniki-v-detskom-sadu','kak-narisovat-medvedya','podelki-dlya-papy','roditelskoe-sobranie-v-nachalnoj-shkole'];
const browser=await chromium.launch({headless:true});
const results=[];
try{
 for(const [name,file,dir] of [['catalog',path.join(out,'index.html'),out],...slugs.map(s=>[s,path.join(root,'editorial/articles',s,'preview.html'),path.join(root,'editorial/articles',s)])]){
  fs.mkdirSync(path.join(dir,'previews'),{recursive:true});
  for(const width of [1440,390]){
   const page=await browser.newPage({viewport:{width,height:1000},deviceScaleFactor:1});
   await page.goto(pathToFileURL(file).href);
   await page.evaluate(async()=>{await document.fonts.ready;await Promise.all([...document.images].map(async i=>{i.loading='eager';await i.decode();}));});
   const metrics=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,h1:document.querySelectorAll('h1').length,images:[...document.images].map(i=>({loaded:i.complete&&i.naturalWidth>0,src:i.getAttribute('src'),natural:[i.naturalWidth,i.naturalHeight],box:[i.getBoundingClientRect().width,i.getBoundingClientRect().height],fit:getComputedStyle(i).objectFit})),tables:[...document.querySelectorAll('.pub-body table')].map(t=>({width:t.getBoundingClientRect().width,overflow:getComputedStyle(t).overflowX}))}));
   if(metrics.scrollWidth>width||metrics.images.some(i=>!i.loaded)||metrics.h1!==1)throw new Error(name+' '+width+' '+JSON.stringify(metrics));
   await page.screenshot({path:path.join(dir,'previews',width+'.png'),fullPage:true});
   fs.writeFileSync(path.join(dir,'previews',width+'.json'),JSON.stringify(metrics,null,2));
   results.push({name,...metrics});await page.close();
  }
 }
}finally{await browser.close();}
fs.writeFileSync(path.join(out,'browser-check.json'),JSON.stringify(results,null,2));
console.log(JSON.stringify(results.map(x=>({name:x.name,width:x.width,scrollWidth:x.scrollWidth,images:x.images.length,h1:x.h1}))));
