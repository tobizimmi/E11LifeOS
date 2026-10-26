# Node.js auf dem Server aktualisieren

Node.js dient bei E11LifeOS **nur** zum Bauen der Frontend-Assets (Vite, läuft in
der GitHub-CI) und als **PM2-Prozessmanager** auf dem Strato-Server. Die Anwendung
selbst ist PHP; die WebSockets laufen über Laravel Reverb (PHP/ReactPHP). Node-
Sicherheitslücken (z.B. die Brotli-RCE **CVE-2026-111497 „Node2Shell"**) treffen
unseren Code daher nicht direkt – trotzdem soll die Node-Laufzeit auf einer
unterstützten, gepflegten Version bleiben.

- **CI/Build:** steht über `.github/workflows/tests.yml` und `deploy.yml`
  (`node-version`) auf **Node 24 LTS**. Änderung dort genügt, die CI zieht sie beim
  nächsten Lauf.
- **Server (PM2-Laufzeit):** muss **separat** aktualisiert werden – siehe unten.

Zielversion: **Node 24 LTS** (Active LTS bis ~April 2028; die Sicherheitsfixes vom
Okt 2026 sind ab 24.22.1 enthalten). Node 20 erhält keine Updates mehr.

## 1. Aktuelle Installation erkennen

```bash
node -v            # aktuelle Version
which node         # Pfad, z.B. /usr/bin/node, /usr/local/bin/node oder ~/.nvm/...
ls -d ~/.nvm 2>/dev/null && echo "→ nvm vorhanden" || echo "→ kein nvm"
dpkg -l | grep -i nodejs   # per apt/NodeSource installiert?
```

> **PATH-Falle:** Das Deploy-Skript läuft nicht-interaktiv mit
> `PATH=/usr/local/bin:/usr/bin:/bin`. `node` **und** `pm2` müssen danach weiterhin
> unter `/usr/local/bin` oder `/usr/bin` erreichbar sein, sonst findet der Deploy sie
> nicht mehr.

## 2a. Variante A – per apt / NodeSource (System-Node, root-Server) — empfohlen

Wenn `which node` auf `/usr/bin/node` zeigt bzw. `dpkg` `nodejs` listet:

```bash
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
sudo apt-get install -y nodejs
node -v                        # muss v24.22.1 oder höher zeigen
sudo npm install -g pm2@latest # pm2 unter neuem Node neu aufsetzen
```

Vorteil: Node landet auf `/usr/bin` → der Deploy-PATH findet es automatisch.

## 2b. Variante B – per nvm (liegt unter `~/.nvm`)

```bash
nvm install 24
nvm alias default 24
nvm use 24
npm install -g pm2@latest
node -v

# WICHTIG wegen der PATH-Falle: node & pm2 auf den Deploy-PATH verlinken
sudo ln -sf "$(nvm which 24)" /usr/local/bin/node
sudo ln -sf "$(dirname "$(nvm which 24)")/pm2" /usr/local/bin/pm2
/usr/local/bin/node -v && /usr/local/bin/pm2 -v   # verifizieren
```

## 3. PM2 unter dem neuen Node neu starten (beide Varianten)

Der PM2-Daemon bleibt sonst am alten Node hängen:

```bash
pm2 save        # aktuelle Prozessliste sichern
pm2 kill        # Daemon beenden (läuft am alten Node)
pm2 resurrect   # unter neuem Node wieder starten
node -v && pm2 -v
pm2 ls          # app, reverb, scheduler, queue, vpn … alle „online"?
```

Danach kurz die Seite und die WebSockets (Reverb) testen.

## 4. Zugehörige Server-Punkte (einmalig prüfen)

- **`SESSION_LIFETIME`** in der Live-`.env` auf `43200` (30 Tage) setzen, damit die
  wie eine native App genutzte Oberfläche angemeldet bleibt (gegen HTTP 419
  „Seite abgelaufen"). Danach Config-Cache leeren (`php artisan config:clear`; der
  Deploy erledigt das sonst selbst).
- **Webserver-HTTP/2-Lücken** (Apache mod_http2 CVE-2026-23918 → ab 2.4.67;
  „HTTP/2 Bomb" CVE-2026-49975): werden vom Webserver **vor** der App gepatcht.
  Managed/Shared Hosting → Strato spielt das ein (ggf. beim Support bestätigen).
  Eigener vServer/root → `sudo apt update && sudo apt upgrade` und Dienst neu laden.
- **System-libbrotli** (nutzt libcurl bei PHP): kommt über dieselben OS-Updates
  (`libbrotli ≥ 1.0.10/1.1.1`). Nur bei eigenem Server relevant; unser Code entpackt
  keine fremden Brotli-Daten.
