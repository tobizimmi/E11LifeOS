# Live einrichten – Schritt für Schritt

Ziel: E11LifeOS läuft unter **https://zimmimail.de/E11LifeOS**, ohne dass du dich per SSH
auf dem Server anmelden musst. Die `.env` kommt aus einem GitHub-Secret, den Rest erledigen
die GitHub-Abläufe.

**Was automatisch passiert**

- **„Server einrichten“** (GitHub → Actions) schreibt die `.env` auf den Server:
  - Fehlende Werte werden ergänzt.
  - Vorhandene Werte bleiben stehen, außer du wählst „overwrite“.
  - Der `APP_KEY` wird nie ersetzt.
  - Die alte Datei wird als `.env.backup-<Datum>` gesichert.
  - Im Log stehen nur die Namen der Werte, nie die Werte selbst.
- **Deploy** (bei jedem Merge nach `main`) spielt den Code ein und legt die Datenbanktabellen an bzw.
  aktualisiert sie. Außerdem startet er die Server-Prozesse (App, Echtzeit, Zeitplaner, Warteschlange)
  und zeigt beim allerersten Mal den **Einladungslink für dein erstes Konto**. Dieses erste Konto wird
  automatisch Administrator.

Du brauchst etwa 30 Minuten. Die Schritte 1 bis 5 machst du, Schritt 6 übernimmt Claude nach
deiner Freigabe.

---

## Vorab: Läuft Live schon?

Auf dem Server laufen bereits die Live-Prozesse `e11lifeos-app` und `e11lifeos-reverb` mit einer
älteren Version. Die `.env` und die Datenbank von Live gibt es also schon. Dann gilt:

- **Schritte 1 bis 5 entfallen.** Weiter mit **Schritt 6** (Freigabe).
- Der Deploy auf Live erstellt **vor** dem Aktualisieren der Datenbank automatisch ein Backup
  (Datenbank und Dateien). Schlägt das fehl, bricht er ab, bevor etwas geändert wird.
- Vorhandene Konten und Daten bleiben erhalten. Es wird kein neuer Einladungslink erzeugt.
- Fehlen der Live-`.env` Werte, zeigt die Checkliste im Deploy-Log das an. Nachtragen kannst du sie
  in der App (Servereinstellungen) oder über Schritt 4c/5 mit einem Secret, das nur die fehlenden
  Zeilen enthält. Bestehende Werte bleiben dabei unverändert.
- „Server einrichten“ für Live erst **nach** der Freigabe verwenden. Die alte Version auf dem Server
  kennt `app:install` noch nicht.

Nur für eine **komplett neue** Live-Installation gelten die Schritte 1 bis 5.

## Schritt 1 – Datenbank anlegen (Plesk)

1. In Plesk anmelden → **Websites & Domains** → `zimmimail.de` → **Datenbanken** → **Datenbank hinzufügen**.
2. Datenbankname: `e11lifeos_live`
3. Benutzer: neuen Benutzer anlegen (z. B. `e11lifeos_live`) oder den vorhandenen `e11lifeos`
   auswählen. Passwort mit **Generieren** erzeugen.
4. **Datenbankname, Benutzer und Passwort sofort im Passwort-Manager speichern.**

## Schritt 2 – Apache-Weiterleitung prüfen (Plesk)

Plesk → `zimmimail.de` → **Apache & nginx-Einstellungen** → **Zusätzliche Apache-Direktiven**.
Dort müssen die vier **Live**-Zeilen stehen, **unter** den Staging-Zeilen:

```apache
ProxyPass /E11LifeOS/app ws://127.0.0.1:3011/app
ProxyPassReverse /E11LifeOS/app ws://127.0.0.1:3011/app
ProxyPass /E11LifeOS http://127.0.0.1:3010
ProxyPassReverse /E11LifeOS http://127.0.0.1:3010
```

Fehlen sie, ergänzen und speichern (Details: [deployment.md](deployment.md), Abschnitt 5).

## Schritt 3 – Schlüssel erzeugen (Mac-Terminal)

Öffne das Terminal auf deinem Mac und führe diese vier Befehle aus. Jedes Ergebnis **sofort im
Passwort-Manager speichern** (Eintrag „E11LifeOS Live“):

```bash
echo "base64:$(openssl rand -base64 32)"   # → APP_KEY
echo "$RANDOM$RANDOM"                       # → REVERB_APP_ID
openssl rand -hex 16                        # → REVERB_APP_KEY
openssl rand -hex 32                        # → REVERB_APP_SECRET
```

> **Der `APP_KEY` ist der wichtigste Wert.** Damit werden Einstellungen, Gesundheits- und
> Notfalldaten, Bankzugänge usw. verschlüsselt. Geht er verloren oder ändert er sich, sind diese
> Daten und auch die Backups nicht mehr lesbar. Er darf sich nach dem ersten Start nie mehr ändern.

## Schritt 4 – Werte in GitHub hinterlegen

Auf GitHub: Repository **E11LifeOS** → **Settings** → **Secrets and variables** → **Actions**.

**a) Vorhandene Secrets prüfen** (Reiter *Secrets*). Diese müssen schon da sein:
`SSH_PRIVATE_KEY`, `SSH_USER`, `SSH_HOST_LIVE`, `APP_PATH_LIVE`.
Fehlt `SSH_HOST_LIVE` oder `APP_PATH_LIVE`, gib Claude Bescheid. Die Werte entsprechen denen für
Staging, der Pfad ist `/var/www/vhosts/zimmimail.de/httpdocs/E11LifeOS-live`.

**b) Variable anlegen** (Reiter *Variables* → **New repository variable**):

| Name | Wert |
|---|---|
| `REVERB_APP_KEY_LIVE` | der `REVERB_APP_KEY` aus Schritt 3 |

(Der Schlüssel ist öffentlich und wird in die App-Dateien eingebaut, deshalb ist er eine
Variable und kein Secret.)

**c) Secret `ENV_FILE_LIVE` anlegen** (Reiter *Secrets* → **New repository secret**):

- Name: `ENV_FILE_LIVE`
- Wert: den folgenden Block kopieren und die fünf Stellen in `<…>` durch deine Werte aus
  Schritt 1 und 3 ersetzen:

```dotenv
APP_NAME=E11LifeOS
APP_ENV=production
APP_KEY=<APP_KEY aus Schritt 3>
APP_DEBUG=false
APP_URL=https://zimmimail.de/E11LifeOS
ASSET_URL=https://zimmimail.de/E11LifeOS
APP_LOCALE=de
APP_FALLBACK_LOCALE=en
APP_TIMEZONE=Europe/Berlin
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=e11lifeos_live
DB_USERNAME=<Datenbank-Benutzer aus Schritt 1>
DB_PASSWORD="<Datenbank-Passwort aus Schritt 1>"

SESSION_DRIVER=database
SESSION_LIFETIME=120
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<REVERB_APP_ID aus Schritt 3>
REVERB_APP_KEY=<REVERB_APP_KEY aus Schritt 3>
REVERB_APP_SECRET=<REVERB_APP_SECRET aus Schritt 3>
REVERB_HOST=zimmimail.de
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_PUBLISH_HOST=127.0.0.1
REVERB_PUBLISH_PORT=3011
REVERB_PUBLISH_SCHEME=http
```

Mehr gehört nicht hinein. E-Mail, KI-Schlüssel, Backups usw. trägst du später bequem in der App ein
(Schritt 8). Dort sind sie ebenfalls verschlüsselt gespeichert und haben Vorrang vor der `.env`.

## Schritt 5 – „Server einrichten“ starten

1. GitHub → **Actions** → links **Deploy E11LifeOS** → rechts **Run workflow**.
2. **Use workflow from: `develop`** auswählen. Erst dann erscheinen die Auswahlfelder.
3. `action`: **server-einrichten** · `environment`: **live** · `overwrite`: **aus**
4. **Run workflow** klicken und nach 1–2 Minuten den Lauf öffnen → Job **server-setup / setup**.

Im Schritt „Write .env on the server“ sollte stehen:

```
.env created.
Added: APP_NAME, APP_ENV, APP_KEY, …
```

Im Schritt „Install / update the app“ steht beim ersten Mal:
„The code is not on this server yet“. Das ist richtig so.

> **Tipp – Probelauf auf Staging (optional, vorher):** Lege ein Secret `ENV_FILE_STAGING` mit nur
> einer Zeile `APP_NAME=E11LifeOS` an und starte den Ablauf mit `environment: staging`. An der
> Staging-`.env` ändert sich nichts (der Wert ist gleich). Du siehst aber, dass die Verbindung
> klappt, und bekommst die Checkliste von Staging angezeigt.

## Schritt 6 – Freigabe für Live (Claude)

Sag Claude: **„Live-Freigabe erteilt“**. Claude merged dann `develop` nach `main`. Der Deploy auf Live
startet automatisch:

- Tests laufen.
- Der Code wird eingespielt.
- Die Datenbanktabellen werden angelegt.
- Die Server-Prozesse starten.

Im Deploy-Lauf (Actions → **Deploy E11LifeOS** → Lauf auf `main` → Job **deploy** → Schritt
„Run server-side deployment tasks“) steht ganz unten:

```
Erstes Konto: innerhalb von 3 Tagen registrieren unter
  https://zimmimail.de/E11LifeOS/register?code=XXXX-XXXX
```

Darunter steht die Checkliste mit den offenen Punkten.

## Schritt 7 – Erstes Konto anlegen

1. Den Link aus Schritt 6 öffnen und registrieren: Name, E-Mail, Passwort und den Namen deines
   Haushalts.
2. Du bist jetzt **Administrator**.
3. Profil → **Zwei-Faktor-Anmeldung** bzw. **Passkey** einrichten (empfohlen).

## Schritt 8 – Einstellungen in der App

Profil-Menü → **Servereinstellungen**:

- **E-Mail:** Mailserver, Postfach, Passwort und Absender. Danach „Test-Mail senden“.
- **Sicherheit:** E-Mail-Adresse für Fehlerberichte.
- **Backup:** Backup-Passwort (**im Passwort-Manager speichern!**) und die Kopie außer Haus
  (WebDAV, z. B. Storage Box).
- **KI:** Claude-API-Schlüssel (optional).
- Weitere nach Bedarf: Kursdaten, Banking-Produkt-ID, Google Drive, VPN …

Die Push-Schlüssel für Benachrichtigungen wurden bei der Installation schon erzeugt.

## Schritt 9 – Kurztest

- Anmelden und abmelden, auch auf dem Handy. Die App kannst du zum Home-Bildschirm hinzufügen.
- Profil → Benachrichtigungen einschalten → Test-Push.
- Eine Aufgabe anlegen: Sie erscheint sofort auf einem zweiten Gerät (Echtzeit).
- Servereinstellungen → der Zeitplaner wird als „läuft“ angezeigt.
- Am nächsten Morgen (mit Kopie außer Haus): Unter Servereinstellungen → Backups steht „Letzte Kopie außer Haus“.

---

## Später etwas an der `.env` ändern

1. Secret `ENV_FILE_LIVE` bearbeiten. Du kannst auch nur die geänderten Zeilen eintragen.
2. „Server einrichten“ mit `environment: live` und **`overwrite: an`** starten.
3. Danach einmal deployen oder Claude Bescheid geben, damit die Prozesse die neuen Werte lesen.

Der `APP_KEY` wird dabei nie überschrieben. Weicht er ab, steht im Log eine Warnung.

## Wenn etwas schiefgeht

| Meldung im Log | Lösung |
|---|---|
| `The GitHub secret ENV_FILE_LIVE is missing` | Schritt 4c: Das Secret fehlt oder ist falsch geschrieben. |
| `APP_KEY is empty` | Im Secret fehlt die Zeile `APP_KEY=base64:…` (Schritt 3). |
| `Could not connect to the server via SSH` | Secrets `SSH_HOST_LIVE` / `SSH_USER` / `SSH_PRIVATE_KEY` prüfen. |
| `Die Datenbank ist nicht erreichbar` | Datenbankname, Benutzer und Passwort im Secret mit Plesk vergleichen, dann mit `overwrite: an` erneut starten. |
| `.env missing` beim Deploy | Schritt 5 wurde noch nicht ausgeführt. |
| Seite zeigt 503/Proxy-Fehler | Schritt 2 (Apache-Weiterleitung) prüfen. |
| Einladungslink abgelaufen | „Server einrichten“ mit `environment: live` erneut starten. Solange es noch kein Konto gibt, kommt ein neuer Link. |

Der Einladungslink steht im Actions-Log. Er gilt nur einmal und nur 3 Tage, und im Repository
können ihn nur Personen mit Zugriff sehen.
