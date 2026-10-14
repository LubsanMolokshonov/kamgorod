// Авторские схемы из векторных примитивов. Это не обработка сгенерированных обложек.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '../../../tests/ui/node_modules/@playwright/test/index.mjs';
const out=path.dirname(fileURLToPath(import.meta.url));
const root=path.resolve(out,'../../..');
const brown='#a97952', light='#f0d7b8', ink='#4a3428';
function bear(step){
 const fill=step===6?brown:'#fff', inner=step===6?light:'#fff';
 let s='<g stroke="'+ink+'" stroke-width="4" stroke-linejoin="round">';
 s+=`<ellipse cx="140" cy="224" rx="64" ry="86" fill="${fill}"/>`;
 if(step>=2)s+=`<circle cx="97" cy="80" r="24" fill="${fill}"/><circle cx="183" cy="80" r="24" fill="${fill}"/><circle cx="97" cy="80" r="13" fill="${inner}"/><circle cx="183" cy="80" r="13" fill="${inner}"/>`;
 s+=`<circle cx="140" cy="125" r="60" fill="${fill}"/>`;
 if(step>=3)s+=`<ellipse cx="78" cy="220" rx="21" ry="46" transform="rotate(12 78 220)" fill="${fill}"/><ellipse cx="202" cy="220" rx="21" ry="46" transform="rotate(-12 202 220)" fill="${fill}"/><ellipse cx="94" cy="294" rx="33" ry="29" fill="${fill}"/><ellipse cx="186" cy="294" rx="33" ry="29" fill="${fill}"/>`;
 if(step>=4)s+=`<ellipse cx="140" cy="147" rx="32" ry="24" fill="${inner}"/><ellipse cx="140" cy="231" rx="34" ry="48" fill="${inner}"/>`;
 if(step>=5)s+=`<circle cx="119" cy="118" r="5" fill="${ink}"/><circle cx="161" cy="118" r="5" fill="${ink}"/><ellipse cx="140" cy="140" rx="10" ry="7" fill="${ink}"/><path d="M140 147v10q-9 10-18 0m18 0q9 10 18 0" fill="none"/>`;
 if(step===6)s+=`<ellipse cx="94" cy="298" rx="16" ry="12" fill="${light}"/><ellipse cx="186" cy="298" rx="16" ry="12" fill="${light}"/>`;
 return s+'</g>';
}
const labels=['Голова и туловище','Круглые уши','Четыре лапы','Мордочка и живот','Глаза, нос и рот','Цвет'];
let body='';
for(let i=0;i<6;i++){const x=20+(i%2)*340,y=20+Math.floor(i/2)*380;body+=`<g transform="translate(${x} ${y})"><rect width="320" height="360" rx="16" fill="#f7f9fc"/><text x="18" y="32" font-size="19" fill="#123153">${i+1}. ${labels[i]}</text><g transform="translate(20 22)">${bear(i+1)}</g></g>`;}
const bearSvg=`<svg xmlns="http://www.w3.org/2000/svg" width="700" height="1160" viewBox="0 0 700 1160"><rect width="700" height="1160" fill="white"/><g font-family="Arial,sans-serif">${body}</g></svg>`;
const craftSvg=`<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="700" viewBox="0 0 1000 700"><rect width="1000" height="700" fill="white"/><g font-family="Arial,sans-serif" fill="#123153"><text x="50" y="50" font-size="27">Развёртка: 19 × 10 см</text><rect x="50" y="90" width="855" height="230" fill="#edf5ff" stroke="#123153" stroke-width="3"/><rect x="860" y="90" width="45" height="230" fill="#c8d9ec"/><path d="M320 90v230M590 90v230M860 90v230" stroke="#376c9d" stroke-width="3" stroke-dasharray="10 8"/><g font-size="25" text-anchor="middle"><text x="185" y="127">6 см</text><text x="455" y="127">6 см</text><text x="725" y="127">6 см</text><text x="883" y="75">1 см</text><text x="185" y="230">Основание</text><text x="455" y="230">Рисунок ↻</text><text x="725" y="230">Задняя стенка</text></g><text x="945" y="215" font-size="24" transform="rotate(90 945 215)">10 см</text><text x="50" y="370" font-size="23">Поверните лист: верх и низ рисунка — вдоль сгибов.</text><text x="50" y="415" font-size="23">Клапан вклеивается внутрь у свободного края основания.</text><text x="570" y="485" font-size="25">Вид сбоку</text><path d="M180 640L300 432L420 640Z" fill="#edf5ff" stroke="#123153" stroke-width="4"/><path d="M380 636h35" stroke="#6c8cab" stroke-width="7"/><text x="270" y="677" font-size="22">6 см</text><text x="570" y="530" font-size="22">Три панели замыкаются</text><text x="570" y="565" font-size="22">в треугольное основание.</text><text x="570" y="615" font-size="22">Рисунок остаётся снаружи.</text></g></svg>`;
const browser=await chromium.launch({headless:true});
try{
 for(const [name,svg,width,height] of [['kak-narisovat-medvedya-shagi',bearSvg,700,1160],['podelki-dlya-papy-shema',craftSvg,1000,700]]){
  fs.writeFileSync(path.join(out,name+'.svg'),svg);
  const page=await browser.newPage({viewport:{width,height},deviceScaleFactor:1.5});
  await page.setContent('<html><body style="margin:0">'+svg+'</body></html>');
  await page.screenshot({path:path.join(root,'assets/images/blog',name+'.png')});
  await page.close();
 }
}finally{await browser.close();}
console.log('Авторские схемы созданы.');
