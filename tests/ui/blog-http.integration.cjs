// Реальная HTTP-проверка локального сервера, включая вызов настроенного ИИ-модератора.
const { chromium } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
if (process.env.BLOG_HTTP_TEST !== '1') throw new Error('Нужен явный BLOG_HTTP_TEST=1');
const fixture = (...args) => execFileSync('docker', ['exec','--user','www-data','-e','BLOG_UI_TEST=1','pedagogy_web','php','tests/blog-comments-ui-fixture.php',...args], {encoding:'utf8'}).trim();
(async () => {
  const browser = await chromium.launch(); const sessions=[];
  const base='http://localhost:8080';
  fixture('--clean-http');
  try {
    const context=await browser.newContext();
    await context.addCookies([{name:'fgos_vote_token',value:'bd'+'0'.repeat(30),url:base}]);
    const page=await context.newPage();await page.goto(base+'/blog/etapy-uroka-po-fgos/');
    const article=await page.locator('[name=publication_id]').inputValue();
    const csrf=await page.locator('[name=csrf_token]').inputValue();
    const form={publication_id:article,csrf_token:csrf,author_name:'Анна',author_role:'Учитель',body:'Спасибо за объяснение этапов урока. Таблица помогает при подготовке занятия.',rating:'5',request_key:crypto.randomBytes(16).toString('hex')};
    const r=await context.request.post(base+'/ajax/submit-blog-comment.php',{form,timeout:40000}); const saved=await r.json();
    assert(saved.success,JSON.stringify(saved)); console.log('Guest HTTP submit:', saved.status);
    const retry=await (await context.request.post(base+'/ajax/submit-blog-comment.php',{form})).json();assert.equal(retry.id,saved.id);assert(retry.duplicate);
    const second=await (await context.request.post(base+'/ajax/submit-blog-comment.php',{form:{...form,request_key:crypto.randomBytes(16).toString('hex')}})).json();assert.equal(second.success,false);
    const adminSid=fixture('--session','admin');sessions.push(adminSid);
    const admin=await browser.newContext();await admin.addCookies([{name:'PHPSESSID',value:adminSid,url:base}]);
    const adminPage=await admin.newPage();await adminPage.goto(base+'/admin/reviews/?section=blog&status='+saved.status);
    assert(await adminPage.locator('h1').textContent() === 'Обсуждения блога');
    const adminCsrf=await adminPage.locator('[name=csrf_token]').first().inputValue();
    const moderate = status => admin.request.post(base+'/admin/reviews/?section=blog',{form:{csrf_token:adminCsrf,id:String(saved.id),action:status}});
    await moderate('approved');
    const userSid=fixture('--session','user');sessions.push(userSid);
    await context.addCookies([{name:'PHPSESSID',value:userSid,url:base}]);await page.reload();
    const profileName=await page.locator('#bd-name').inputValue();assert(profileName);
    const response=await context.request.post(base+'/ajax/submit-blog-comment.php',{form:{publication_id:article,csrf_token:await page.locator('[name=csrf_token]').inputValue(),parent_id:String(saved.id),author_name:'Имя из формы должно замениться профилем',body:'Какие этапы можно объединить в коротком занятии?',request_key:crypto.randomBytes(16).toString('hex')},timeout:40000});
    const reply=await response.json();assert(reply.success,JSON.stringify(reply));console.log('Authenticated HTTP reply:',reply.status);
    const adminReply=await admin.request.post(base+'/admin/reviews/?section=blog',{form:{csrf_token:adminCsrf,id:String(reply.id),action:'approved'}});assert(adminReply.ok());
    const visible=await (await context.request.get(base+'/ajax/get-blog-comments.php?publication_id='+article)).json();assert(visible.html.includes(profileName));assert(!visible.html.includes('Имя из формы должно'));
    await moderate('rejected');
    const hidden=await (await context.request.get(base+'/ajax/get-blog-comments.php?publication_id='+article)).json();assert(!hidden.html.includes('comment-'+reply.id+'"'));assert(!hidden.html.includes('comment-'+saved.id+'"'));
    console.log('PASS: HTTP duplicate, rating guard, profile name, admin approval and subtree rejection');
  } finally {
    await browser.close();for(const sid of sessions)fixture('--clear-session',sid);fixture('--clean-http');
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
