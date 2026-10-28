# Прод-nginx: что настроено вне репозитория

TLS терминируется фронтовым nginx на 141.105.69.45, PHP-приложение живёт в Docker-контейнере
`pedagogy_web` (Apache) за проксёй. Конфиг vhost — `/etc/nginx/sites-enabled/kamgorod`,
**в git его нет**, поэтому нетривиальные правки фиксируются здесь.

⚠️ Бэкапы vhost класть **не** в `sites-enabled/` — nginx подключает `sites-enabled/*` целиком
и подхватит копию как второй конфиг («conflicting server name … ignored»). Класть в `/root/`.

## HTTPS-сертификат (27.10.2026)

nginx использует `/etc/letsencrypt/live/fgos.pro/fullchain.pem` и
`/etc/letsencrypt/live/fgos.pro/privkey.pem` для `fgos.pro` и `www.fgos.pro`.
Старый wildcard GlobalSign в `/etc/ssl/fgos.pro/` истёк 26.10.2026; подключение
этой статической копии при наличии действующего Let's Encrypt вызывало
`NET::ERR_CERT_DATE_INVALID`. Старые файлы сохранены, nginx на них больше не ссылается.

Продление выполняет включённый `certbot.timer`, проверка доменов — webroot
`/var/www/certbot`. Hook `/etc/letsencrypt/renewal-hooks/deploy/reload-nginx`
выполняет `nginx -t` и `systemctl reload nginx` после успешного продления.
Резервная копия vhost до исправления:
`/root/kamgorod.nginx-before-tls-20261027T041305Z`.

Проверка продления конкретного сертификата:

```bash
certbot renew --cert-name fgos.pro --dry-run --run-deploy-hooks --no-random-sleep-on-renew
```

27.10.2026 эта проверка и deploy hook прошли успешно. Внешние HTTPS-запросы
с проверкой доверия получили 200 для `fgos.pro` и 301 для `www.fgos.pro`;
подключённый сертификат действует до 10.12.2026.

На сервере также есть отдельный истёкший сертификат `mail.fgos.pro`, продление
которого завершается ошибкой. Он не используется vhost сайта; его состояние
следует проверять отдельно, не удаляя сертификат без проверки потребителей.

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
