# Прод-nginx: что настроено вне репозитория

TLS терминируется фронтовым nginx на 141.105.69.45, PHP-приложение живёт в Docker-контейнере
`pedagogy_web` (Apache) за проксёй. Конфиг vhost — `/etc/nginx/sites-enabled/kamgorod`,
**в git его нет**, поэтому нетривиальные правки фиксируются здесь.

⚠️ Бэкапы vhost класть **не** в `sites-enabled/` — nginx подключает `sites-enabled/*` целиком
и подхватит копию как второй конфиг («conflicting server name … ignored»). Класть в `/root/`.

## Схема в заголовке Location (08.09.2026)

**Симптом:** 301-редиректы отдавали `Location: http://fgos.pro/...` — при том что сайт живёт
на HTTPS. Клиент получал лишний хоп http → https, а краулер вдобавок первый хоп в открытую.

**Причина:** правила в `.htaccess` с ОТНОСИТЕЛЬНОЙ целью (`RewriteRule … /olimpiady/pedagogi/
[R=301,L]`, таких ~29: порядок сегментов ac/as/at, опустевшие уровни, legacy-аудитории, трейлинг-слеш)
заставляют Apache самому достроить абсолютный URL. Внутри контейнера TLS нет и mod_ssl не загружен,
поэтому Apache берёт схему `http`.

**Почему не чинится в `.htaccess`:** `Header always edit Location "^http://" "https://"` не работает —
mod_headers на таких ответах отрабатывает (проверено пробным заголовком, он появляется), но Apache
переустанавливает `Location` уже ПОСЛЕ обработчика заголовков. Правки в vhost-конфиге Apache
(`ServerName https://fgos.pro` + `UseCanonicalName On`) потребовали бы пересборки образа.

**Решение** — переписывание схемы на проксе. В `server`-блоке :443, после `limit_conn`:

```nginx
proxy_redirect http://fgos.pro/     https://fgos.pro/;
proxy_redirect http://www.fgos.pro/ https://fgos.pro/;
```

Директивы стоят на уровне `server` и наследуются всеми `location` (ни один из них своего
`proxy_redirect` не задаёт). `proxy_redirect default;` на этом уровне поставить нельзя —
nginx требует его после `proxy_pass`, то есть только внутри `location`.

**Проверка:**

```bash
curl -sI https://fgos.pro/olimpiady/pedagogi/nachalnaya-shkola/ | grep -i '^location:'
# ожидаем https://fgos.pro/olimpiady/pedagogi/
```
