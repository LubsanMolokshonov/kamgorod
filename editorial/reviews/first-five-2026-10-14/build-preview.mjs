// Предпросмотры на реальных стилях сайта, без изменения текста и без БД.
import fs from 'node:fs';
import path from 'node:path';
import {pathToFileURL,fileURLToPath} from 'node:url';
const out=path.dirname(fileURLToPath(import.meta.url));
const root=path.resolve(out,'../../..');
const plan=JSON.parse(fs.readFileSync(path.join(out,'plan-snapshot.json'),'utf8'));
const slugs=['den-otca-v-detskom-sadu','prazdniki-v-detskom-sadu','kak-narisovat-medvedya','podelki-dlya-papy','roditelskoe-sobranie-v-nachalnoj-shkole'];
const esc=s=>String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
const url=p=>pathToFileURL(path.join(root,p)).href;
const css=['fonts','main','redesign','redesign-info','competition-detail','journal-redesign','publication-extras'].map(n=>`<link rel="stylesheet" href="${url('assets/css/'+n+'.css')}">`).join('\n');
const header=title=>`<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>${esc(title)}</title>${css}<style>body{margin:0}.preview-label{padding:16px 24px;background:#eaf2fb;color:#173557;font:500 15px/1.5 sans-serif}.preview-label a{color:inherit}main{padding-top:28px;padding-bottom:48px}.review-intro{max-width:800px;margin-bottom:32px}.queue{line-height:1.7;padding-left:28px}.rd-grid+h2{margin-top:40px}.queue li{margin:8px 0}</style></head><body><div class="preview-label">Редакционный предпросмотр · 14.10.2026 · Не опубликовано · <a href="${pathToFileURL(path.join(out,'index.html')).href}">Все пять статей</a></div>`;
const cards=[];
for(const [i,slug] of slugs.entries()){
 const dir=path.join(root,'editorial/articles',slug);
 const m=JSON.parse(fs.readFileSync(path.join(dir,'metadata.json'),'utf8'));
 let body=fs.readFileSync(path.join(dir,'article.html'),'utf8');
 for(const target of slugs)body=body.replaceAll(`href="/blog/${target}/"`,`href="${url('editorial/articles/'+target+'/preview.html')}"`);
 body=body.replaceAll('href="/blog/','href="https://fgos.pro/blog/');
 body=body.replace(/src="(\/assets\/images\/blog\/[^" ]+)"/g,(_,p)=>`src="${url(p.slice(1))}"`);
 const cover=url(m['cover-image'].slice(1));
 const preview=header(m.title)+`<main class="rd-wrap"><div class="pub-detail-layout"><article class="pub-article pub-article--blog"><img class="pub-cover" src="${cover}" alt="${esc(m.title)}"><span class="pub-type">Практика для педагога</span><h1>${esc(m.title)}</h1><div class="pub-body pub-body--blog">${body}</div></article></div></main></body></html>`;
 fs.writeFileSync(path.join(dir,'preview.html'),preview);
 cards.push(`<a class="rd-card pub-card has-cover" href="${url('editorial/articles/'+slug+'/preview.html')}"><img class="pub-card-cover" src="${cover}" alt="${esc(m.title)}"><div class="rd-card-tags"><span class="rd-tag indigo">${i+1} · ${plan.values[i+1][0]}</span></div><h4>${esc(m.title)}</h4><div class="rd-card-meta">${esc(m.annotation)}</div><div class="pub-meta-line">Открыть статью</div></a>`);
}
const queue=plan.values.slice(1).map((r,i)=>`<li>${i<5?`<a href="${url('editorial/articles/'+slugs[i]+'/preview.html')}">${esc(r[1])}</a>`:esc(r[1])} <small>· ${esc(r[0])}</small></li>`).join('');
fs.writeFileSync(path.join(out,'index.html'),header('Первые пять статей из плана')+`<main class="rd-wrap"><h1>Первые пять статей для блога</h1><div class="review-intro"><p>Тексты, обложки и схемы подготовлены в порядке вкладки <a href="${plan.spreadsheet_url}">«Первые 30»</a>. Откройте карточку для чтения.</p><p>Материалы не опубликованы. Для статьи о поделках перед публикацией остаётся пробная физическая сборка: геометрия и последовательность проверены цифровым способом.</p></div><div class="rd-grid">${cards.join('')}</div><h2>Найденная очередь из 30 статей</h2><ol class="queue">${queue}</ol></main></body></html>`);
fs.writeFileSync(path.join(out,'plan.md'),'# Найденный список: первые 30\n\nИсточник: '+plan.spreadsheet_url+'\nПорядок проверен 14.10.2026. Первые пять подготовлены в текущем пакете.\n\n'+plan.values.slice(1).map((r,i)=>`${i+1}. **${r[0]}** — ${r[1]}`).join('\n')+'\n');
console.log('Предпросмотры собраны.');
