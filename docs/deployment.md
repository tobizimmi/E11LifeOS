# Deployment auf den Strato VPS (217.72.202.93)

Beide Umgebungen laufen **ohne eigene Subdomain** als Pfad unter der
bestehenden `zimmimail.de`-Domain (wie z.B. auch `zimmimail.de/RoboDoc/`):

- Live: `https://zimmimail.de/E11LifeOS`
- Staging: `https://zimmimail.de/E11LifeOS-staging`

Das ist mehr Aufwand als eine Subdomain, weil Laravel/Livewire/Reverb sonst
davon ausgehen, direkt auf dem Domain-Root zu laufen — die App-seitigen
Anpassungen dafür (erzwungene Root-URL, relative Pfade in `manifest.json`,
`wsPath` für den Reverb-Client) sind bereits im Code (siehe
`app/Providers/AppServiceProvider.php` und `resources/js/echo.js`). Auf dem
Server bleibt zu tun: Verzeichnisse, DBs, `.env`, PM2-Prozesse, Apache-Proxy.

## 1. Verzeichnisse

```bash
mkdir -p /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live
mkdir -p /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging
```

(Passe die Pfade an, falls du andere Namen willst — dann auch
`APP_PATH_LIVE`/`APP_PATH_STAGING` in den GitHub Secrets entsprechend
setzen.)

## 2. MySQL-Datenbanken

```sql
CREATE DATABASE e11lifeos_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE e11lifeos_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'e11lifeos'@'localhost' IDENTIFIED BY '<starkes-passwort>';
GRANT ALL PRIVILEGES ON e11lifeos_live.* TO 'e11lifeos'@'localhost';
GRANT ALL PRIVILEGES ON e11lifeos_staging.* TO 'e11lifeos'@'localhost';
FLUSH PRIVILEGES;
```

## 3. `.env` je Umgebung

Der Deploy-Workflow fasst `.env` nie an — einmalig von Hand anlegen.
**Wichtig für den Pfad-Betrieb:** `APP_URL` UND `ASSET_URL` müssen beide den
vollen Pfad inkl. `/E11LifeOS`(`-staging`) enthalten, und `VITE_REVERB_PATH`
muss auf denselben Pfad gesetzt sein (das ist der Präfix, den Apache vor dem
Weiterleiten an Reverb wieder abschneidet).

**Live** — `<app-path>/.env`:

```
APP_NAME=E11LifeOS
APP_ENV=production
APP_KEY=            # php artisan key:generate --show, dann eintragen
APP_URL=https://zimmimail.de/E11LifeOS
ASSET_URL=https://zimmimail.de/E11LifeOS
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=e11lifeos_live
DB_USERNAME=e11lifeos
DB_PASSWORD=<starkes-passwort>

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<eigene-id-live>
REVERB_APP_KEY=<eigener-key-live>
REVERB_APP_SECRET=<eigenes-secret-live>
REVERB_HOST=zimmimail.de
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_REVERB_PATH=/E11LifeOS
```

**Staging** — dieselben Werte, aber `APP_ENV=staging`, `_staging`-DB,
`APP_URL`/`ASSET_URL`/`VITE_REVERB_PATH` mit `/E11LifeOS-staging`, und
eigene `REVERB_APP_ID`/`KEY`/`SECRET` (nicht mit Live teilen).

## 4. PM2-Prozesse (einmalig starten)

Zwei Prozesse pro Umgebung — App (`php -S`) und Reverb-WebSocket-Server —
auf unterschiedlichen internen Ports, analog zu
`RezepteApp2/backend/ecosystem.config.js`. **Wichtig:** explizit den PHP-8.4-
Pfad (`/opt/plesk/php/8.4/bin/php`) verwenden, nicht das bloße `php` — das
zeigt auf die alte System-PHP 8.2, mit der die App nicht läuft.

```bash
# Live: App-Port 3010, Reverb-Port 3011
pm2 start /opt/plesk/php/8.4/bin/php --name e11lifeos-app --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live -- -S 127.0.0.1:3010 -t public/
pm2 start "/opt/plesk/php/8.4/bin/php artisan reverb:start --port=3011" --name e11lifeos-reverb --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live

# Staging: App-Port 3020, Reverb-Port 3021
pm2 start /opt/plesk/php/8.4/bin/php --name e11lifeos-app-staging --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging -- -S 127.0.0.1:3020 -t public/
pm2 start "/opt/plesk/php/8.4/bin/php artisan reverb:start --port=3021" --name e11lifeos-reverb-staging --cwd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging

pm2 save
```

## 5. Apache Reverse Proxy (Pfad statt Subdomain)

In der bestehenden `zimmimail.de`-vhost-Konfiguration (Plesk → "Apache & nginx-Einstellungen" → "Zusätzliche Apache-Direktiven" für die Domain,
**nicht** für eine Subdomain) ergänzen. `mod_proxy_wstunnel` muss aktiv sein.

**Reihenfolge beachten:** `/E11LifeOS-staging` muss VOR `/E11LifeOS` stehen,
sonst "verschluckt" die kürzere Live-Regel auch alle Staging-Requests, weil
`/E11LifeOS` ein Präfix von `/E11LifeOS-staging` ist.

```apache
# Staging zuerst (längerer, spezifischerer Pfad)
ProxyPass /E11LifeOS-staging/app ws://127.0.0.1:3021/app
ProxyPassReverse /E11LifeOS-staging/app ws://127.0.0.1:3021/app
ProxyPass /E11LifeOS-staging http://127.0.0.1:3020
ProxyPassReverse /E11LifeOS-staging http://127.0.0.1:3020

# Live danach
ProxyPass /E11LifeOS/app ws://127.0.0.1:3011/app
ProxyPassReverse /E11LifeOS/app ws://127.0.0.1:3011/app
ProxyPass /E11LifeOS http://127.0.0.1:3010
ProxyPassReverse /E11LifeOS http://127.0.0.1:3010
```

## 6. GitHub Actions Deploy-Key

Bereits erledigt (siehe Repo-Verlauf): dedizierter Key `e11lifeos_deploy`,
öffentlicher Teil in `authorized_keys` auf dem VPS, privater Teil + Host/User/Pfade
als Secrets in GitHub (`SSH_PRIVATE_KEY`, `SSH_USER`, `SSH_HOST_LIVE`,
`SSH_HOST_STAGING`, `APP_PATH_LIVE`, `APP_PATH_STAGING`).

## 7. Erster Deploy

Sobald 1–5 stehen: `git push origin develop` deployt Staging, `git push
origin main` deployt Live (oder manuell über die "Run workflow"-Schaltfläche
unter github.com/tobizimmi/E11LifeOS/actions, dank `workflow_dispatch`). Der
Workflow bricht kontrolliert ab, wenn `.env` fehlt oder ein PM2-Prozess noch
nicht einmalig gestartet wurde (siehe Fehlermeldung im Workflow-Log).
