# Deployment auf den Strato VPS (217.72.202.93)

Einmalige, manuelle Einrichtung pro Umgebung (Staging, Live), bevor der
GitHub-Actions-Workflow (`.github/workflows/deploy.yml`) zum ersten Mal für
diese Umgebung läuft. Der Workflow selbst rsynct nur Code und startet
bestehende PM2-Prozesse neu — er legt keine Server-Infrastruktur an.

## 1. Verzeichnisse & vhost (Plesk)

Für jede Umgebung ein Plesk-Subdomain/vhost anlegen, z.B.:

- Live: `e11lifeos.zimmimail.de` → `/var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live`
- Staging: `staging.e11lifeos.zimmimail.de` → `/var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging`

Document Root auf `<app-path>/public` setzen.

## 2. MySQL-Datenbanken

Pro Umgebung eine eigene DB + eigener User (nicht mit anderen Projekten teilen):

```sql
CREATE DATABASE e11lifeos_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE e11lifeos_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'e11lifeos'@'localhost' IDENTIFIED BY '<starkes-passwort>';
GRANT ALL PRIVILEGES ON e11lifeos_live.* TO 'e11lifeos'@'localhost';
GRANT ALL PRIVILEGES ON e11lifeos_staging.* TO 'e11lifeos'@'localhost';
FLUSH PRIVILEGES;
```

## 3. `.env` je Umgebung (einmalig von Hand anlegen, danach server-verwaltet)

Der Deploy-Workflow fasst `.env` nie an. In `<app-path>/.env` u.a.:

```
APP_NAME=E11LifeOS
APP_ENV=production
APP_KEY=            # php artisan key:generate --show, dann eintragen
APP_URL=https://e11lifeos.zimmimail.de   # bzw. staging-Subdomain
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=e11lifeos_live               # bzw. e11lifeos_staging
DB_USERNAME=e11lifeos
DB_PASSWORD=<starkes-passwort>

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<eigene-id-pro-umgebung>
REVERB_APP_KEY=<eigener-key-pro-umgebung>
REVERB_APP_SECRET=<eigenes-secret-pro-umgebung>
REVERB_HOST=e11lifeos.zimmimail.de       # bzw. staging-Subdomain
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Live und Staging brauchen unterschiedliche `REVERB_PORT`-Werte für die
*internen* PM2-Prozesse (siehe Schritt 4) — nach außen laufen beide über 443,
Apache unterscheidet per Hostname.

## 4. PM2-Prozesse (einmalig starten, danach übernimmt CI nur noch `pm2 restart`)

Analog zum bestehenden Muster in `RezepteApp2/backend/ecosystem.config.js`.
Pro Umgebung zwei Prozesse — App (`php -S`) und Reverb-WebSocket-Server, auf
unterschiedlichen internen Ports:

```bash
# Live, Beispielports 3010 (App) / 3011 (Reverb)
pm2 start php --name e11lifeos-app --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live -- -S 127.0.0.1:3010 -t public/
pm2 start "php artisan reverb:start --port=3011" --name e11lifeos-reverb --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live

# Staging, Beispielports 3020 / 3021
pm2 start php --name e11lifeos-app-staging --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging -- -S 127.0.0.1:3020 -t public/
pm2 start "php artisan reverb:start --port=3021" --name e11lifeos-reverb-staging --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging

pm2 save
```

## 5. Apache Reverse Proxy

Pro vhost: normale Requests auf den App-Port, `/app` (Reverb-Pfad) inkl.
WebSocket-Upgrade auf den Reverb-Port proxien, z.B. für Live:

```apache
ProxyPreserveHost On
ProxyPass /app ws://127.0.0.1:3011/app
ProxyPassReverse /app ws://127.0.0.1:3011/app
ProxyPass / http://127.0.0.1:3010/
ProxyPassReverse / http://127.0.0.1:3010/
```

(`mod_proxy_wstunnel` muss aktiv sein.) Für Staging entsprechend mit den
Staging-Ports und dem Staging-vhost.

## 6. GitHub Actions Deploy-Key

Einen dedizierten Deploy-Key erzeugen (nicht den privaten `tobistrato`-Key
wiederverwenden) und dessen **öffentlichen** Teil in
`~/.ssh/authorized_keys` des Deploy-Users auf dem VPS eintragen:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/e11lifeos_deploy -N "" -C "github-actions-e11lifeos"
cat ~/.ssh/e11lifeos_deploy.pub   # -> auf dem VPS in authorized_keys eintragen
```

Danach in den GitHub-Repo-Settings (Settings → Secrets and variables →
Actions) folgende Secrets anlegen:

- `SSH_PRIVATE_KEY` — Inhalt von `e11lifeos_deploy` (privater Teil)
- `SSH_USER` — Deploy-Benutzername auf dem VPS
- `SSH_HOST_LIVE`, `SSH_HOST_STAGING` — i.d.R. beide `217.72.202.93`
- `APP_PATH_LIVE` — z.B. `/var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live`
- `APP_PATH_STAGING` — z.B. `/var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging`

## 7. Erster Deploy

Sobald 1–6 stehen: `git push origin develop` deployt Staging, `git push
origin main` (bzw. Merge von `develop` nach `main`) deployt Live. Der
Workflow bricht kontrolliert ab, wenn `.env` fehlt oder ein PM2-Prozess noch
nicht einmalig gestartet wurde (siehe Fehlermeldung im Workflow-Log).
