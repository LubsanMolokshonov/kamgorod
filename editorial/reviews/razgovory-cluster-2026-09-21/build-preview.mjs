// Локальная сборка предпросмотра. Не обращается к БД и не публикует статьи.
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const slugs = ['gde-skachat-materialy-razgovory-o-vazhnom', 'razgovory-o-vazhnom-po-klassam', 'kak-provodit-razgovory-o-vazhnom'];
const out = path.dirname(fileURLToPath(import.meta.url));
const esc = s => String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;');
const url = p => pathToFileURL(path.join(root,p)).href;
const css = ['fonts','main','redesign','redesign-info','competition-detail','journal-redesign','publication-extras'].map(n=>`<link rel="stylesheet" href="${url('assets/css/'+n+'.css')}">`).join('\n');
const header = title => `<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>${esc(title)}</title>${css}<style>body{margin:0}.preview-label{padding:16px 24px;background:#eaf2fb;color:#173557;font:500 15px/1.5 sans-serif}.preview-label a{color:inherit}main{padding-top:28px;padding-bottom:48px}</style></head><body><div class="preview-label">Редакционный предпросмотр · 21.09.2026 · Не опубликовано · <a href="${pathToFileURL(path.join(out,'index.html')).href}">Все три статьи</a></div>`;
const cards=[];
for (const slug of slugs) {
  const dir=path.join(root,'editorial/articles',slug);
  const m=JSON.parse(fs.readFileSync(path.join(dir,'metadata.json'),'utf8'));
  // Markdown-конвертер выводит H1; в чистовой HTML он не входит.
  const html=fs.readFileSync(path.join(dir,'article.html'),'utf8').replace(/^<h1>[^]*?<\/h1>\s*/,'');
  fs.writeFileSync(path.join(dir,'article.html'),html);
  let body=html;
  for (const target of slugs) body=body.replaceAll(`href="/blog/${target}/"`,`href="${url('editorial/articles/'+target+'/preview.html')}"`);
  body=body.replaceAll('href="/blog/','href="https://fgos.pro/blog/');
  const cover=url(m['cover-image'].slice(1));
  const preview=header(m.title)+`<main class="rd-wrap"><div class="pub-detail-layout"><article class="pub-article pub-article--blog"><img class="pub-cover" src="${cover}" alt="${esc(m.title)}"><span class="pub-type">Методика</span><h1>${esc(m.title)}</h1><div class="pub-body">${body}</div></article></div></main></body></html>`;
  fs.writeFileSync(path.join(dir,'preview.html'),preview);
  cards.push(`<a class="rd-card pub-card has-cover" href="${url('editorial/articles/'+slug+'/preview.html')}"><img class="pub-card-cover" src="${cover}" alt="${esc(m.title)}"><div class="rd-card-tags"><span class="rd-tag indigo">Методика</span></div><h4>${esc(m.title)}</h4><div class="rd-card-meta">${esc(m.annotation)}</div><div class="pub-meta-line">На согласование</div></a>`);
}
fs.writeFileSync(path.join(out,'index.html'),header('Три статьи о «Разговорах о важном»')+`<main class="rd-wrap"><h1>Три практические статьи</h1><p>Откройте карточку, чтобы прочитать текст с обложкой. Карточки используют стили каталога сайта.</p><div class="rd-grid">${cards.join('')}</div></main></body></html>`);
console.log('Предпросмотры собраны: '+path.join(out,'index.html'));
