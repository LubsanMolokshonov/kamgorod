// Авторские векторные схемы: последовательность рисунка и плоские композиции.
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from '../../../tests/ui/node_modules/@playwright/test/index.mjs';
const out=path.dirname(fileURLToPath(import.meta.url)), root=path.resolve(out,'../../..');
const ink='#234634';
function frog(step){
 const fill=step===6?'#83b966':'#fff';
 let s=`<g stroke="${ink}" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round">`;
 if(step===6)s+='<path d="M38 307q102 30 204 0M28 322q112 28 224 0" stroke="#66a9d0" fill="none"/>';
 if(step>=3)s+=`<ellipse cx="74" cy="241" rx="30" ry="53" transform="rotate(-35 74 241)" fill="${fill}"/><ellipse cx="206" cy="241" rx="30" ry="53" transform="rotate(35 206 241)" fill="${fill}"/>`;
 s+=`<ellipse cx="140" cy="224" rx="62" ry="76" fill="${fill}"/>`;
 if(step===6)s+='<ellipse cx="140" cy="231" rx="35" ry="51" fill="#cce79c" stroke="none"/>';
 s+=`<ellipse cx="140" cy="135" rx="86" ry="47" fill="${fill}"/>`;
 if(step>=2)s+='<circle cx="92" cy="88" r="29" fill="white"/><circle cx="188" cy="88" r="29" fill="white"/>';
 if(step>=4)s+=`<path d="M109 180l-4 77q-19 0-15 8q-20 4-8 10q-13 12 5 11l31-4 8-35M171 180l4 77q19 0 15 8q20 4 8 10q13 12-5 11l-31-4-8-35" fill="${fill}"/>`;
 if(step>=5)s+=`<circle cx="92" cy="88" r="10" fill="${ink}"/><circle cx="188" cy="88" r="10" fill="${ink}"/><circle cx="125" cy="132" r="2.8" fill="${ink}"/><circle cx="155" cy="132" r="2.8" fill="${ink}"/><path d="M89 147q51 35 102 0" fill="none"/>`;
 return s+'</g>';
}
const labels=['Туловище и голова','Круглые глаза','Задние лапы','Передние лапки','Детали лица','Цвет и пруд'];
let f='';
for(let i=0;i<6;i++)f+=`<g transform="translate(${20+i%2*350} ${20+Math.floor(i/2)*385})"><rect width="330" height="365" rx="14" fill="#f2f7f5"/><text x="16" y="30" font-size="20">${i+1}. ${labels[i]}</text><g transform="translate(25 28)">${frog(i+1)}</g></g>`;
const frogSvg=`<svg xmlns="http://www.w3.org/2000/svg" width="720" height="1175" viewBox="0 0 720 1175"><rect width="720" height="1175" fill="white"/><g font-family="Arial,sans-serif" fill="#173557">${f}</g></svg>`;
const leaf=(x,y,angle,fill,scale=1)=>`<g transform="translate(${x} ${y}) rotate(${angle}) scale(${scale})"><path d="M0-65Q60 0 0 65Q-60 0 0-65Z" fill="${fill}"/><path d="M0 54V-48M0-10L-21-28M0 16L22-4M0 35L-18 20" stroke="#996237" stroke-width="2" fill="none"/></g>`;
let crown='';
for(const [i,[x,y]] of [[0,[170,113]],[1,[221,104]],[2,[268,123]],[3,[145,158]],[4,[190,157]],[5,[243,161]],[6,[286,166]],[7,[172,203]],[8,[220,211]],[9,[267,208]]]) crown+=`<path d="M${x-24} ${y-20}l19-8 26 12 5 24-21 15-29-12Z" fill="${['#e4ae33','#e37636','#edcb58'][i%3]}"/>`;
let carpet='';for(let i=0;i<6;i++)carpet+=leaf(102+i%3*123,115+Math.floor(i/3)*132,i%2?20:-15,i%2?'#bd4f45':'#e8b63e',0.72);
const appliqueSvg=`<svg xmlns="http://www.w3.org/2000/svg" width="620" height="1240" viewBox="0 0 620 1240"><rect width="620" height="1240" fill="white"/><g font-family="Arial,sans-serif" fill="#173557"><text x="25" y="34" font-size="26">1. Один лист</text><g transform="translate(150 55)"><rect width="320" height="320" rx="6" fill="#faf0df"/>${leaf(160,160,-20,'#d98438',1.6)}</g><text x="25" y="414" font-size="26">2. Дерево из рваной бумаги</text><g transform="translate(85 436)"><rect width="450" height="330" rx="6" fill="#dfedf5"/><path d="M218 289V155M223 211L169 160M223 207L273 155" stroke="#916346" stroke-width="23" fill="none"/>${crown}<path d="M110 296l18-9 21 11-9 14-26-1ZM303 290l21-5 17 16-14 10-29-9Z" fill="#e4ae33"/></g><text x="25" y="822" font-size="26">3. Осенний ковёр</text><g transform="translate(85 851)"><rect width="450" height="344" rx="6" fill="#f6ead1"/>${carpet}</g></g></svg>`;
const browser=await chromium.launch({headless:true});
try{
 for(const [name,svg,width,height] of [['kak-narisovat-lyagushku-shagi',frogSvg,720,1175],['osennyaya-applikaciya-varianty',appliqueSvg,620,1240]]){
  fs.writeFileSync(path.join(out,name+'.svg'),svg);
  const p=await browser.newPage({viewport:{width,height},deviceScaleFactor:1.5});
  await p.setContent('<html><body style="margin:0">'+svg+'</body></html>');
  await p.screenshot({path:path.join(root,'assets/images/blog',name+'.png')});await p.close();
 }
}finally{await browser.close();}
console.log('Схемы созданы.');
