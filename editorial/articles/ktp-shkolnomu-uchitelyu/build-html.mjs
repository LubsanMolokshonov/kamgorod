import fs from "node:fs/promises";
import { marked } from "marked";

const markdown = await fs.readFile("editorial/articles/ktp-shkolnomu-uchitelyu/article.md", "utf8");
const title = markdown.match(/^# (.+)$/m)?.[1] ?? "Предпросмотр статьи";
const withoutH1 = markdown.replace(/^# .+\n+/, "");
let html = await marked.parse(withoutH1, { gfm: true });
html = html.replace(/<a href="([^"]+)">/g, '<a href="$1" target="_blank" rel="noopener noreferrer">');
await fs.writeFile("editorial/articles/ktp-shkolnomu-uchitelyu/article.html", html, "utf8");

const preview = `<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${title}</title>
<link rel="stylesheet" href="../../../assets/css/bundle.min.css">
<link rel="stylesheet" href="../../../assets/css/journal-redesign.css">
<style>body{margin:0;background:#f5f5f2;color:#222}main{max-width:900px;margin:auto;padding:24px;box-sizing:border-box}.preview-note{font:14px sans-serif;padding:12px 0}h1{font-size:clamp(26px,4vw,40px);line-height:1.2}.pub-cover{width:100%;height:auto;border-radius:16px}.pub-body{font-family:Onest,Arial,sans-serif;font-size:17px}article{min-width:0}</style>
</head><body><main><p class="preview-note">Предпросмотр новой редакции. На сайте пока опубликована прежняя версия.</p>
<article class="pub-article--blog"><img class="pub-cover" src="../../../assets/images/blog/ktp-shkolnomu-uchitelyu.jpg" alt="Планировщик, учебные карточки и книги на столе учителя">
<h1>${title}</h1><div class="pub-body pub-body--blog">${html}</div></article></main></body></html>`;
await fs.writeFile("editorial/articles/ktp-shkolnomu-uchitelyu/preview.html", preview, "utf8");
