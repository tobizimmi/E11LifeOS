# `.env` einrichten – Anleitung

Die `.env` enthält alle Zugangsdaten und Schlüssel der App. Sie liegt auf dem Server und nie
im Code. Der Deploy-Workflow fasst sie nicht an: Er bricht ab, wenn sie fehlt, und überschreibt
sie nie. Ohne SSH geht es über das GitHub-Secret `ENV_FILE_LIVE` und den Ablauf
„server-einrichten“, siehe [live-einrichten.md](live-einrichten.md).

Vorlage zum Ausfüllen: [`docs/env.production.example`](env.production.example)

> **Ohne Server-Zugriff:** KI, E-Mail, Web-Push, Portfolio-Schlüssel, Backups und die
> Import-Zugänge lassen sich auch in der App eintragen: Profil-Menü → **Servereinstellungen**
> (`/admin/settings`, nur für Administratoren; das erste Konto ist automatisch Admin,
> weitere mit `php artisan user:admin <email>`). Dort gespeicherte Werte sind verschlüsselt
> und haben Vorrang vor der `.env`. Die Seite zeigt auch, ob der Scheduler läuft, und startet
> die Altdaten-Importe. In der `.env` müssen dann nur App, Datenbank und Reverb stehen.

## 1. Wohin mit der Datei?

Pro Umgebung eine eigene `.env` direkt im App-Verzeichnis (dort, wo `artisan` liegt):

| Umgebung | Datei auf dem Server | URL |
|---|---|---|
| Live | `/var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live/.env` | https://zimmimail.de/E11LifeOS |
| Staging | `/var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging/.env` | https://zimmimail.de/E11LifeOS-staging |

(Die Pfade entsprechen den GitHub-Secrets `APP_PATH_LIVE` / `APP_PATH_STAGING`.)

**Achtung:** Da Staging schon deployt wird, gibt es dort bereits eine `.env`, sonst
würde der Deploy abbrechen. Vorher sichern und nur die fehlenden Zeilen ergänzen:

```bash
cd /var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-staging
cp .env .env.backup-$(date +%F)
nano .env
```

Neu anlegen (z. B. Live): Vorlage per Plesk-Dateimanager oder `scp` hochladen und in
`.env` umbenennen, dann ausfüllen und die Rechte einschränken:

```bash
chmod 600 .env
```

## 2. Nach jeder Änderung

Die App liest die `.env` beim Start. Deshalb danach neu starten (kein `config:cache`,
der Deploy verzichtet bewusst darauf):

```bash
cd <App-Verzeichnis>
/opt/plesk/php/8.4/bin/php artisan config:clear
pm2 restart e11lifeos-app-staging --update-env      # Live: e11lifeos-app
pm2 restart e11lifeos-reverb-staging --update-env   # Live: e11lifeos-reverb
```

Alternativ einfach neu deployen (GitHub → Actions → „Run workflow“).

## 3. Scheduler und Hintergrund-Worker

Erinnerungen, Kurs-Updates, News, Banksync, Instagram-Abruf, wiederkehrende Rechnungen,
Mahnungen, Backups und die Importe der Admin-Seite laufen über den Laravel-Scheduler und
einen Queue-Worker. Beide richtet der Deploy automatisch als PM2-Prozesse ein
(`e11lifeos-scheduler(-staging)` und `e11lifeos-queue(-staging)`), unter demselben Benutzer
wie die App. **Ein Cronjob in Plesk ist nicht nötig** – und sollte entfernt werden, falls
vorhanden: Er läuft unter einem anderen Benutzer, der nicht in `storage/` schreiben darf
(Fehler „Unable to create a directory …/storage/app/private“).

Ob der Scheduler läuft, zeigt die Status-Box unter **Servereinstellungen**.

## 4. Die Werte im Einzelnen

Legende: **Pflicht** = ohne läuft die App nicht · **Funktion** = nötig für die
genannte Funktion · **Optional** = Verbesserung/Ersatz.

### App

| Variable | | Bedeutung / Wert |
|---|---|---|
| `APP_ENV` | Pflicht | `production` (Live) bzw. `staging` |
| `APP_KEY` | Pflicht | Verschlüsselungsschlüssel (Sitzungen, gespeicherte Bank-PINs, Pushover-Token). Erzeugen mit `php artisan key:generate --show`. **Nie ändern**, sonst sind verschlüsselte Daten verloren. Live und Staging bekommen je einen eigenen. |
| `APP_DEBUG` | Pflicht | Auf dem Server immer `false` (sonst sieht jeder Fehlermeldungen mit Interna). |
| `APP_URL`, `ASSET_URL` | Pflicht | Volle Adresse **inkl. Pfad**, z. B. `https://zimmimail.de/E11LifeOS`. Wird für Links, Uploads, Kalender-Abo, Mobile-App und PWA gebraucht. |
| `APP_LOCALE`, `APP_TIMEZONE` | Pflicht | `de` und `Europe/Berlin` stehen lassen. |
| `LOG_LEVEL` | Optional | `warning` schreibt nur Probleme in `storage/logs/laravel.log`, `debug` alles. |

### Datenbank

| Variable | | Bedeutung / Wert |
|---|---|---|
| `DB_CONNECTION` | Pflicht | `mysql` |
| `DB_HOST`, `DB_PORT` | Pflicht | `127.0.0.1` und `3306` (MySQL auf demselben Server) |
| `DB_DATABASE` | Pflicht | `e11lifeos_live` bzw. `e11lifeos_staging` (siehe `docs/deployment.md`, Schritt 2) |
| `DB_USERNAME`, `DB_PASSWORD` | Pflicht | Der dort angelegte MySQL-Benutzer und sein Passwort |
| `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE` | Pflicht | Alle `database` lassen. |

### Echtzeit-Updates (Reverb)

Damit Änderungen anderer Haushaltsmitglieder sofort erscheinen.

| Variable | | Bedeutung / Wert |
|---|---|---|
| `BROADCAST_CONNECTION` | Pflicht | `reverb` |
| `REVERB_APP_ID` | Pflicht | Beliebige Zahl, z. B. `481516` |
| `REVERB_APP_KEY` | Pflicht | Zufallswert, z. B. `openssl rand -hex 16`. **Muss** mit der GitHub-Variable `REVERB_APP_KEY_LIVE` bzw. `REVERB_APP_KEY_STAGING` übereinstimmen (sie wird beim Build ins JavaScript eingebaut). |
| `REVERB_APP_SECRET` | Pflicht | Zufallswert, z. B. `openssl rand -hex 32`. Geheim. |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Pflicht | `zimmimail.de`, `443`, `https` |
| `REVERB_PUBLISH_HOST`, `REVERB_PUBLISH_PORT`, `REVERB_PUBLISH_SCHEME` | Empfohlen | Wohin der Server selbst Live-Updates schickt: `127.0.0.1`, der Reverb-Port (`3011` Live, `3021` Staging), `http`. Ohne diese Werte geht es an die öffentliche Adresse – dort leitet Apache aber nur den WebSocket-Pfad weiter, die Updates scheitern dann („Pusher error: <!DOCTYPE html>“). Alternativ in den Server-Einstellungen unter „Live-Updates (Reverb)“ nur den Port eintragen. |
| `VITE_REVERB_PATH` | Pflicht | `/E11LifeOS` bzw. `/E11LifeOS-staging` |

### E-Mail

Für tägliche Erinnerungen, „Passwort vergessen“ und im Business-Bereich
den Versand von Rechnungen, Angeboten und Mahnungen.

| Variable | | Bedeutung / Wert |
|---|---|---|
| `MAIL_MAILER` | Funktion | `smtp`. Mit `log` wird nichts verschickt (Mails landen nur im Log). |
| `MAIL_HOST`, `MAIL_PORT` | Funktion | SMTP-Server des Postfachs. Bei einem Plesk-Postfach meist `zimmimail.de` und `587`. |
| `MAIL_SCHEME` | Funktion | `null` für Port 587 (STARTTLS), `smtps` für Port 465 |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Funktion | Zugangsdaten des Postfachs (in Plesk unter „E-Mail“ anlegen, z. B. `lifeos@zimmimail.de`) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Funktion | Absender, den Empfänger sehen. Die Adresse sollte zum Postfach passen, sonst landen Mails im Spam. |

### KI (Claude)

| Variable | | Bedeutung / Wert |
|---|---|---|
| `ANTHROPIC_API_KEY` | Funktion | API-Schlüssel von https://console.anthropic.com → „API Keys“. Nötig für Rezept-Import aus URL/Foto/PDF, Verträge und Kontoauszüge auslesen, Tag-Vorschläge, „Frag dein Second Brain“ und die Instagram-Analyse. Kostet pro Nutzung, in der Console lässt sich ein Monatslimit setzen. |
| `ANTHROPIC_MODEL` | Optional | Standard `claude-opus-5` stehen lassen |

### Web-Push

| Variable | | Bedeutung / Wert |
|---|---|---|
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Funktion | Einmalig mit `php artisan webpush:vapid` erzeugen und die zwei ausgegebenen Zeilen übernehmen. **Nie ändern**, sonst muss jedes Gerät Benachrichtigungen neu einschalten. Pro Umgebung ein eigenes Paar. |
| `VAPID_SUBJECT` | Funktion | Kontakt für die Push-Dienste, z. B. `mailto:deine@email.de` |

### Backups

| Variable | | Bedeutung / Wert |
|---|---|---|
| `BACKUP_PATH` | Optional | Ordner für die nächtlichen ZIP-Backups (Datenbank + Dateien). Leer = `storage/backups` in der App. Besser außerhalb, z. B. `/var/www/vhosts/zimmimail.de/backups/e11lifeos-live`. |
| `BACKUP_KEEP` | Optional | Wie viele Backups behalten werden (Standard 14) |
| `BACKUP_REMOTE_URL`, `_USERNAME`, `_PASSWORD` | Empfohlen | WebDAV-Ordner für eine Kopie außer Haus (z. B. Hetzner Storage Box, Nextcloud, NAS). Jedes Backup wird verschlüsselt hochgeladen, alte Kopien werden aufgeräumt (`BACKUP_REMOTE_KEEP`, Standard 30). |
| `BACKUP_ENCRYPTION_PASSWORD` | Mit Kopie außer Haus Pflicht | Passwort für die Verschlüsselung der Kopien. **Ohne dieses Passwort lässt sich kein Backup wiederherstellen** – im Passwortmanager ablegen. Entschlüsseln: `php artisan backup:decrypt datei.zip.enc` |
| `SECURITY_REQUIRE_TWO_FACTOR` | Optional | `true` = alle Mitglieder müssen die Zwei-Faktor-Anmeldung einrichten, bevor sie die App nutzen können |
| `ERROR_REPORT_EMAIL` | Empfohlen | Adresse für Fehlermeldungen vom Server (höchstens einmal pro Fehler und Stunde); die letzten Fehler stehen auch auf der Admin-Seite |

### Portfolio: Banksync (FinTS)

| Variable | | Bedeutung / Wert |
|---|---|---|
| `FINTS_PRODUCT_ID` | Funktion | Jede Software, die per FinTS mit Banken spricht, braucht eine registrierte Produkt-ID. Kostenlos beantragen bei der Deutschen Kreditwirtschaft: https://www.hbci-zka.de/register/prod_register.htm (Formular ausfüllen, die ID kommt nach einigen Tagen per Mail). Ohne sie lehnen die Banken den Abruf ab (Meldung 9078). Bank-Zugangsdaten gibt jeder selbst unter Portfolio → Banksync ein, sie gehören nicht in die `.env`. |

### Portfolio: Instagram-Analyse

| Variable | | Bedeutung / Wert |
|---|---|---|
| `APIFY_API_KEY` | Funktion | Instagram hat keine offene Schnittstelle, der Abruf läuft über Apify. Konto auf https://apify.com (das Gratis-Kontingent sollte für 20 Beiträge alle 6 Stunden reichen), dann **Settings → API & Integrations → Personal API token**. Zusätzlich braucht es `ANTHROPIC_API_KEY` für die Auswertung. |
| `INSTAGRAM_PROFILE` | Optional | Account ohne `@`, Standard `heikothieme.club` |
| `INSTAGRAM_LIMIT` | Optional | Beiträge pro Abruf, Standard 20 |
| `INSTAGRAM_PUSH_KEY` | Optional | Nur wenn Videos von einem anderen Gerät (z. B. dem Raspberry Pi des alten Portfolio-Managers) an `POST /api/instagram/push` geschickt werden. Selbst erzeugen: `openssl rand -hex 32`. Dasselbe Passwort im sendenden Gerät als Header `X-Push-Key` eintragen. |

### Portfolio: optionale Dienste

| Variable | | Bedeutung / Wert |
|---|---|---|
| `TWELVE_DATA_KEY` | Optional | Ersatz-Kursquelle, falls Yahoo Finance keine Daten liefert. Gratis-Schlüssel auf https://twelvedata.com |
| `OPENFIGI_KEY` | Optional | Beim Broker-Import werden ISIN/WKN über OpenFIGI in Börsenkürzel übersetzt. Ohne Schlüssel geht es auch, nur langsamer bei vielen Wertpapieren. Gratis auf https://www.openfigi.com/api |
| `PUSHOVER_APP_TOKEN` | Optional | Standard-App-Token für Pushover-Benachrichtigungen (https://pushover.net → „Create an Application“). Den persönlichen User-Key trägt jeder unter Portfolio → Einstellungen ein. Dort kann auch ein eigener App-Token stehen, dann wird dieser Wert nicht gebraucht. |

Keine neue Variable nötig für die **Mobile-API** (Expo-App): Die App meldet sich mit
E-Mail und Passwort an und nutzt `APP_URL` + `/api/v1`.

### paperless-ngx und Mail-Weiterleitung

Keine Variable nötig: Jede Person verbindet ihre paperless-Instanz selbst im Profil
(Adresse + API-Token) und entscheidet dort, ob sie nur für sie selbst oder für den ganzen Haushalt gilt.

E-Mails kommen über paperless in die App (PHP braucht dafür kein IMAP):

1. In paperless unter **E-Mail → E-Mail-Konten** das Postfach eintragen (z. B. `belege@deine-domain.de`).
2. Unter **E-Mail → E-Mail-Regeln** eine Regel anlegen: Anhänge verarbeiten (oder die ganze
   Mail als PDF) und den Posteingangs-Tag vergeben.
3. Rechnungen und Briefe an dieses Postfach weiterleiten. Sie erscheinen in der App unter
   **Inbox → paperless-Posteingang** und lassen sich dort als Ausgabe, Immobilien-Rechnung,
   Business-Beleg oder Aufgabe ablegen. Das Dokument bleibt in paperless und wird nur verknüpft.

### VPN (WireGuard) ins Heimnetz

Wird normalerweise unter **Server-Einstellungen → VPN (WireGuard)** eingetragen; alternativ
`VPN_ENABLED`, `VPN_WIREGUARD_CONFIG`, `VPN_NETWORKS`, `VPN_SOCKS_PORT`.

1. In der FritzBox unter *Internet → Freigaben → VPN (WireGuard)* „Einzelnes Gerät verbinden“
   wählen und die Konfigurationsdatei herunterladen – je eine für Staging und Live.
2. Den Inhalt in das Feld „WireGuard-Konfiguration“ kopieren, „VPN eingeschaltet“ auf Ja, speichern.
3. Mit „Verbindung testen“ z. B. `http://192.168.178.20:8000` (paperless) prüfen.

Technik: Der Deploy lädt einmalig `wireproxy` (`php artisan vpn:install`) und startet den
PM2-Prozess `e11lifeos-vpn(-staging)` (`php artisan vpn:run`). Er braucht keine Root-Rechte und
stellt einen lokalen SOCKS5-Proxy bereit. Nur Anfragen an Adressen in den eingetragenen Netzen
(leer = AllowedIPs der Konfiguration) laufen darüber – paperless und das WebDAV-Backup nutzen
ihn automatisch, alles andere geht wie bisher direkt ins Internet.

### Einmalige Datenübernahmen

In der Vorlage auskommentiert (`#`). Nur für den Import das `#` entfernen, ausfüllen,
App neu starten (Abschnitt 2), Import ausführen, danach wieder auskommentieren.

| Variablen | Import-Befehl |
|---|---|
| `PORTFOLIO_LEGACY_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD` – MySQL-Zugang des alten Portfolio-Managers | `php artisan portfolio:import-legacy deine@email.de` |
| `REZEPTEAPP_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD` – MySQL-Zugang der RezepteApp2 | `php artisan recipes:import-rezepteapp deine@email.de` (Optionen siehe README) |

Liegt die alte Datenbank auf demselben Server, ist der Host `127.0.0.1`. Die
Zugangsdaten stehen in der Konfiguration der alten App (beim Portfolio-Manager in
`config/database.php` auf dem Server).

## 5. Reihenfolge für den Start

1. Pflichtwerte (App, Datenbank, Reverb) ausfüllen, `APP_KEY` und VAPID erzeugen.
2. App neu starten (Abschnitt 2) und prüfen, ob die Seite lädt.
3. Cron einrichten (Abschnitt 3).
4. E-Mail und `ANTHROPIC_API_KEY` ergänzen.
5. Portfolio-Werte ergänzen, sobald vorhanden (FinTS-ID dauert ein paar Tage).
6. Altdaten importieren, dann die Import-Zugänge wieder auskommentieren.

Tipp: Erst auf Staging testen, dann dieselben Dienst-Schlüssel (Anthropic, Apify,
FinTS …) in die Live-`.env` übernehmen. `APP_KEY`, Reverb- und VAPID-Werte bekommt
jede Umgebung dagegen einzeln.
