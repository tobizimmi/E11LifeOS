# E11LifeOS

Ein „Second Brain“ für den Haushalt: Seiten, Aufgaben, Einkaufsliste und eine
Inbox zum schnellen Erfassen – geteilt mit dem Haushalt oder privat, in Echtzeit
synchronisiert und als PWA auf dem Handy nutzbar.

Vision und Roadmap: [`docs/vision.md`](docs/vision.md) ·
Deployment: [`docs/deployment.md`](docs/deployment.md)

## Funktionen

- **Seitenleiste** – Navigation wie in Notion: Übersicht, Inbox, Haushalt, geteilter und privater Seitenbaum.
- **Übersicht** – offene Aufgaben, Einkaufsliste, zuletzt bearbeitete Seiten und Inbox auf einen Blick, mit Schnellerfassung.
- **Spracheingabe** – 🎤 in Schnellerfassung, bei neuen Aufgaben/Einträgen und in der Einkaufsliste (Spracherkennung des Browsers).
- **Schnell erfassen** (Übersicht) – wahlweise in die Inbox oder direkt als Aufgabe (mit Datum, Uhrzeit, !Priorität, @Name).
- **Übersicht nach Wunsch** – Kacheln ein-/ausblenden und sortieren („⚙ Übersicht anpassen“), u. a. Fristen der nächsten 30 Tage aus allen Sammlungen und Fixkosten pro Monat (aus Verträgen).
- **Inbox** – erst erfassen, später einsortieren: als Aufgabe, als Seite oder auf die Einkaufsliste; `#tags` im Text werden zu Tags.
- **Erinnerungen** – Aufgaben mit Uhrzeit („morgen 18 Uhr“) erinnern genau dann per Push (ohne Push-Gerät per E-Mail); wer eine Aufgabe zugewiesen bekommt, erhält sofort einen Push. Dazu täglich um 7:00 per E-Mail und/oder Push-Benachrichtigung aufs Handy/den Computer: fällige und überfällige Aufgaben sowie Termine aus Sammlungen, z. B. Kündigungsfristen (im Profil einschalten; auf dem iPhone nach „Zum Home-Bildschirm“).
- **Seiten** – beliebig verschachtelbar (Unterseiten, Pfadanzeige, Icons), Editor wie in Notion (Tiptap: „/“-Befehlsmenü, Formatierungsleiste, Checklisten, Tabellen), Verknüpfungen mit `[[Seitentitel]]` inkl. Vorschlägen (fehlende Seiten per Klick anlegen), „Verlinkt von“-Rückverweise, Tags mit Filter, Checklisten direkt in der Leseansicht abhaken, Bilder und Dateien per Knopf, „/“-Befehl, Drag & Drop oder Einfügen (privat gespeichert, sichtbar wie die Seite), Seiten in der Seitenleiste per Drag & Drop sortieren und verschieben. Gespeichert wird Markdown.
- **Aufgaben** – eigene Aufgabenseite, einfach und schnell:
  - Smart-Listen „Heute“ (inkl. Überfälligem), „Geplant“, „Alle“, „Meine“ und „Erledigt“, gruppiert nach Datum.
  - Schnelleingabe in natürlicher Sprache, getippt oder diktiert: „Zahnarzt anrufen morgen !hoch @Anna #gesundheit“ oder „Müll rausbringen jeden Dienstag“. Erkannte Angaben erscheinen als Chips und lassen sich per × wieder in den Titel zurücknehmen.
  - Priorität, Unteraufgaben, Notizen, Wiederholung (abgehakt springt die Aufgabe zum nächsten Termin), Zuständigkeit, Tags und Verknüpfungen im Detail-Panel.
  - Abhaken mit „Rückgängig“; auf dem Handy nach rechts wischen = erledigt, nach links = auf morgen.
  - Mehrfachauswahl: mehrere Aufgaben auf einmal erledigen, verschieben, priorisieren, zuweisen oder löschen.
  - Wochenrückblick in „Erledigt“: diese und letzte Woche erledigt, noch überfällig; erledigte Aufgaben nach Tag gruppiert.
  - Dieselben Aufgaben bleiben als Board, Tabelle oder Kalender verfügbar.
- **Kalender-Abo** – offene Aufgaben (mit Uhrzeit und Wiederholung) und Termine mit Erinnerung, z. B. Kündigungsfristen, als abonnierbarer Kalender für iPhone, Google oder Outlook (persönlicher Link im Profil, jederzeit erneuer- oder abschaltbar).
- **Kalender** – Monats- und Wochenansicht für jede Sammlung mit Datumsfeld: Einträge per Drag & Drop umplanen, direkt an einem Tag anlegen; künftige Termine wiederkehrender Aufgaben als Vorschau.
- **Sammlungen** – eigene Datenbanken wie in Notion, in fünf Ansichten (Karten, Liste, Tabelle, Board, Kalender; sichtbare Felder und Standardansicht wählbar). Neue Einträge und Änderungen über ein übersichtliches Formular-Popup, Details in einem Popup mit formatierten Werten (Beträge in €, Fristen mit Countdown und farbiger Warnung). Außerdem: Eigenschaften (Text, Zahl, Datum, Auswahl, Mehrfachauswahl, Checkbox, Person, Link, Verknüpfung), jeder Eintrag mit eigenen Notizen und Dateien; Filter pro Eigenschaft (z. B. „Fällig: überfällig“, „Zuständig: ich“) und gespeicherte Ansichten als Reiter, Summen für Zahlen und Checkboxen, E-Mail-Erinnerung für Datumsfelder, Einträge per Klick auf die Einkaufsliste.
- **Formularaufbau & eigene Vorlagen**:
  - Felder sortieren (↑/↓) und als Pflichtfeld markieren.
  - Standardwerte vorgeben, z. B. Datum „in 1 Woche“, Person „wer den Eintrag anlegt“.
  - Hinweistexte unter dem Feld.
  - Jede Sammlung lässt sich „⧉ Als Vorlage speichern“ und unter „Neue Sammlung“ wiederverwenden (nur Aufbau, keine Einträge).
- **Import aus CSV/Excel** – Tabellen (.csv, .xlsx) als Einträge übernehmen:
  - Spalten Titel, vorhandenen Feldern oder neuen Feldern zuordnen; der passende Typ (Datum, Zahl, Auswahl, Checkbox, Link) wird erkannt.
  - Deutsche Datums- und Zahlenformate werden verstanden.
- **Kommentare & Verlauf** – an jedem Eintrag und jeder Aufgabe:
  - Die Person, die den Eintrag angelegt hat, und die zuständige Person bekommen einen Push.
  - Auf Wunsch zeigt der Verlauf, wer was geändert hat.
- **Einladungslinks** – statt nur Code: Link kopieren oder teilen, der Code wird bei der Registrierung automatisch eingetragen.
- **Dokumente an Einträgen** – PDFs, Fotos und Dateien direkt im Formular oder in der Detailansicht anhängen (mit Vorschau); „✨ Aus Dokument ausfüllen“ lässt die KI Anbieter, Kosten, Fristen usw. aus einem Vertrag oder Foto in die Felder übernehmen.
- **Module als Vorlagen** – Verträge & Dokumente (Kündigungsfrist mit Erinnerung), Haushaltsbuch, Vorräte (Haltbarkeit mit Erinnerung), Lesezeichen / Später lesen, Tagebuch (privat), Gewohnheiten, Projekte, Leseliste.
- **Offline** – Übersicht, Aufgaben, Inbox und Einkaufsliste öffnen auch ohne Netz (zuletzt geladener Stand); neue Inbox-Einträge und Aufgaben werden offline gespeichert und automatisch gesendet, sobald das Gerät wieder online ist.
- **Einkaufsliste** – abhaken, per Wischen löschen, auch offline lesbar (zuletzt geladener Stand), Produkte per Barcode-Scanner eintragen (Kamera auf Android und iPhone, alternativ Nummer eintippen; Daten von Open Food Facts).
- **Rezepte (Addon)** – Rezeptsammlung mit Foto und weiteren Bildern (Galerie), Zutaten (mit Abschnitten), Zubereitungsschritten, Portionsrechner, Kategorien, Tags, Favoriten, Bewertungen (Sterne + Notiz pro Person), Archiv und Duplizieren; ausgewählte Zutaten landen mit einem Klick (umgerechnet) auf der Einkaufsliste. „Was kann ich kochen?“ sucht nach vorhandenen Zutaten. Pro Haushalt ein-/ausschaltbar (Haushalt → Addons).
- **Rezepte importieren** – per Link (liest die Rezeptdaten der Seite direkt, sonst die KI), Foto/Screenshot (auch Kochbuchseiten und Handschrift), PDF oder eingefügtem Text; das Formular öffnet vorausgefüllt zum Prüfen. Dazu Nährwerte pro Portion (von Hand oder per KI geschätzt), PDF-Export und öffentliche Links zum Teilen (jederzeit abschaltbar).
- **Kochmodus** – Schritt für Schritt im Vollbild, Timer aus dem Schritttext („10 Minuten“) oder per Schnellwahl, Alarm mit Ton/Vibration, Bildschirm bleibt an; am Ende bewerten.
- **Wochenplan** – Rezepte oder freie Einträge („Reste“) pro Tag und Mahlzeit planen, per Drag & Drop verschieben; die Zutaten der Woche zusammengefasst auf die Einkaufsliste; „Heute auf dem Speiseplan“ auf der Übersicht.
- **Verknüpfungen überall** – Seiten, Aufgaben, Einträge, Inbox und Rezepte lassen sich untereinander verlinken: im Text über `[[` (Vorschläge für alles), per Knopf „Verknüpfen“ oder über die Eigenschaft „Verknüpfung“. Jedes Ziel zeigt „Verknüpfungen“ und „Verlinkt von“.
- **Frag dein Second Brain (KI, optional)** – Fragen in eigenen Worten (auch per Spracheingabe oder aus der Suche heraus), z. B. „Wann endet die Autoversicherung?“; die Antwort stützt sich nur auf Seiten, Aufgaben, Einträge und Rezepte, die du sehen darfst, und verlinkt ihre Quellen. Dazu Tag-Vorschläge per ✨ bei Seiten und Einträgen.
- **Finanzen (privat)** – Kontoauszüge als CSV importieren (Sparkasse, Volksbank, ING, DKB, comdirect, N26 u. a. sowie Outbank mit mehreren Konten und Kategorien; doppelte Buchungen werden übersprungen), automatische Kategorien mit lernenden Regeln („immer“), Bericht mit Einnahmen/Ausgaben pro Monat, Kategorien, 50/30/20-Check, wiederkehrenden Zahlungen inkl. Preiserhöhungen und konkretem Sparpotenzial; optional eine KI-Finanzanalystin und KI-Kategorisierung, Bericht als PDF. Bankdaten sieht nur die Person, die sie importiert hat.
- **Cashflow-Analyse (privat)** – Einnahmen-/Ausgabenrechnung und Bilanz nach „Rich Dad Poor Dad“: Einkommen aus Arbeit, Portfolio (Dividenden) und passiv (Nettokaltmiete, Gewerbegewinn), Ausgaben aus den Kontoumsätzen, Vermögenswerte gegen Verbindlichkeiten (selbst genutzte Immobilie getrennt), Grad der finanziellen Freiheit, Cashflow-Muster und nächste Schritte.
- **Finanzwissen** – kuratierte Tipps zu Finanzierung, Börse, Steuern und Vermögensaufbau zum Durchklicken und Durchsuchen, aktuelle Neuerungen (Steuern, Börse, Finanzierung, neue Gesetze) aus Google News; beides per Stern als Favorit merken.
- **Notfallordner (privat)** – Dokumente & Ausweise (mit Ablaufdatum und Erinnerung), Vorsorge & Testament, wichtige Kontakte, digitales Leben und Wünsche mit Checkliste und Fortschritt je Bereich; Konten, Depots, Immobilien, Kredite und Verträge werden automatisch übernommen. Details verschlüsselt gespeichert, PDF zum Ausdrucken. Vertrauenspersonen bekommen einen persönlichen Link, fragen im Notfall Zugriff an und sehen den Ordner nach einer Wartezeit – außer du lehnst ab.
- **Fahrzeuge** – Fahrzeugakte mit HU/TÜV, Inspektion (Datum oder km) und Reifenwechsel von O bis O inklusive Erinnerungen und Kalender; Protokoll für Tanken/Laden, Werkstatt, Reifen und sonstige Kosten mit Verbrauch pro 100 km, Kosten pro Monat und pro km (inkl. Kfz-Steuer und Versicherung); Fahrtenbuch nach dienstlich/Arbeitsweg/privat mit absetzbarer km-Pauschale. Geteilt im Haushalt oder privat.
- **Business (privat)** – Rechnungen, Angebote und Buchhaltung für Selbstständige und Kleingewerbe:
  - Geschäftsdaten mit Kleinunternehmerregelung (§ 19 UStG) oder Umsatzsteuer, Logo und Akzentfarbe für die PDFs.
  - Kunden und ein Katalog mit Leistungen und Produkten (mit einem Klick in Rechnungen und Angeboten).
  - Angebote mit Gültigkeit, Status angenommen/abgelehnt und Umwandlung in eine Rechnung.
  - Rechnungen mit Positionen, Rabatten, Entwürfen, lückenlosen Nummernkreisen (Rechnungen, Angebote, Gutschriften), PDF im DIN-5008-Layout mit allen Pflichtangaben, Teilzahlungen, Status offen/teilbezahlt/überfällig/bezahlt.
  - Storno per Stornorechnung und Gutschriften mit Erstattung.
  - Versand per E-Mail mit PDF-Anhang und Kundenportal-Link (Rechnung ansehen, PDF laden, Angebot online annehmen).
  - Mahnwesen: Zahlungserinnerung, 1. und 2. Mahnung mit Frist und Gebühren, per E-Mail oder als Brief-PDF; Push bei neu überfälligen Rechnungen (`business:overdue-notices`).
  - Wiederkehrende Rechnungen (monatlich bis jährlich), automatisch gestellt und auf Wunsch verschickt (`business:run-recurring`, täglich).
  - Zeiterfassung mit Timer, Stundensätzen und Abrechnung als Rechnungsentwurf.
  - Bankabgleich: passende Kontobewegungen aus Finanzen werden als Zahlung vorgeschlagen.
  - E-Rechnung als XRechnung (CII) mit Leitweg-ID; Umsatzsteuer-Voranmeldung (Kz 81/86/66/83) je Monat oder Quartal.
  - Belege für Ausgaben und Einnahmen ohne Rechnung, mit Foto/PDF und optionalem KI-Auslesen.
  - Jahresabschluss als Einnahmenüberschussrechnung nach tatsächlichen Zahlungseingängen, gegliedert wie die Anlage EÜR (Bewirtung 70 %, Vorsteuer, Kleinunternehmer-Grenzen, Checkliste für ELSTER), als PDF und CSV.
  - Keine Steuerberatung.
- **Portfolio (privat, Addon)** – Portfolio-Manager für Aktien, ETFs, Fonds und Krypto:
  - Mehrere Depots, Positionen nach Durchschnittskostenmethode aus Käufen, Verkäufen, Splits, Dividenden und Gebühren; Positionen ohne Historie auch von Hand, mit Labels, Notizen, Stop-Loss und Kursziel.
  - Kurse über Yahoo Finance (ohne Schlüssel), Kurshistorie optional über Twelve Data (`TWELVE_DATA_KEY`), ISIN/WKN-Auflösung über OpenFIGI; Fremdwährungen in Euro umgerechnet.
  - Import der CSV-Exporte von Trade Republic, DKB, ING, Smartbroker+, comdirect, Consorsbank, flatex, OnVista, Outbank und Finanzguru (plus beliebige CSV); PDF-Abrechnungen und Screenshots per KI. Doppelte Buchungen werden übersprungen.
  - Überblick mit Depotwert, Tagesveränderung, Gewinn/Verlust und Wertverlauf, Watchlist mit 52-Wochen-Spanne, Wertpapierseite mit Kursverlauf.
  - Analyse: Rendite im Vergleich zum Vergleichsindex, Volatilität, maximaler Rückgang, Aufteilung nach Position, Anlageklasse, Branche, Land und Währung, Korrelationsmatrix.
  - Dividenden pro Monat, Jahr und Wertpapier mit Erwartung für die nächsten 12 Monate; Steuern nach FIFO mit Verlusttöpfen, Sparerpauschbetrag, Soli und Kirchensteuer.
  - Rebalancing mit Zielgewichten (auch „nur kaufen“), Sparpläne mit automatischer Ausführung (`portfolio:execute-savings-plans`), Kursalarme sowie Stop-Loss/Kursziel-Hinweise per Push und Pushover (`portfolio:check-alerts`).
  - Kaufempfehlungen nach Strategie (konservativ, ausgewogen, offensiv, Thieme oder eigene Kriterien: Rücksetzer vom Hoch, RSI, 200-Tage-Linie, Qualität aus KGV, Verschuldung, Wachstum, Marge) mit Backtest; täglicher Marktscan über Yahoo-Screener mit Kennzahlen (`portfolio:market-scan`).
  - Entdecken mit Marktübersicht (DAX, S&P 500, Gold, Bitcoin …) und Screener-Listen; News aus Finanz-Feeds und Google News je Wertpapier mit Push für neue Schlagzeilen (`portfolio:fetch-news`).
  - Banksync per FinTS/HBCI (ING, DKB, comdirect, Consorsbank, Smartbroker+, flatex, Sparkasse, beliebige Bank): Depotbestände abrufen und mit einem Depot abgleichen, Kontoumsätze der letzten 90 Tage direkt in Finanzen importieren; TAN oder Freigabe in der Banking-App; PIN nur auf Wunsch verschlüsselt gespeichert, dann täglicher Abruf (`banking:sync`). Benötigt eine registrierte FinTS-Produkt-ID (`FINTS_PRODUCT_ID`).
  - Instagram-Analyse eines Börsen-Accounts (Standard @heikothieme.club): Beiträge über Apify abrufen (`APIFY_API_KEY`) oder per `POST /api/instagram/push` (Schlüssel `INSTAGRAM_PUSH_KEY`) einspielen, KI erkennt Aktie, Stimmung, Einstiegskurs, Stop-Loss und Tranchen; Anzeige mit aktuellem Kurs, Watchlist und Kursalarmen, Push bei neuen Ideen (`portfolio:instagram`, alle 6 Stunden).
  - Mobile API für die Expo-App unter `/api/v1` (Anmeldung mit E-Mail und Passwort, danach Bearer-Token): Depots, Positionen, Verlauf, Umsätze, Watchlist, Kurse, Suche, Empfehlungen und Einstellungen; außerdem Aufgaben (Liste, Schnelleingabe, abhaken), Kalender, Benachrichtigungen, Einkaufsliste und Nettovermögen. Angemeldete Geräte lassen sich im Profil abmelden.
  - Wochenübersicht per E-Mail und/oder Push (Profil: Tag und Uhrzeit, Vorschau, „Jetzt senden“): erledigte, überfällige und anstehende Aufgaben, Fristen, Wochenplan, Einkaufsliste, Depot-Entwicklung mit Terminen, Ausgaben der Woche und offene Rechnungen – jeweils nur für Module, die das Mitglied nutzen darf (`digest:weekly`, stündlich).
  - Haushalt: Der Inhaber schaltet Rezepte, Finanzen, Business und Portfolio für einzelne Mitglieder aus; Navigation, Übersicht, Suche, KI, Kalender-Abo und API beachten das.
  - Verzahnt mit LifeOS: Portfolio-Kachel auf der Übersicht (Wert, heute, größte Bewegungen, nächste Termine), Wertpapiere in der Suche, „Aufgabe erstellen“ auf Wertpapier-, Empfehlungs- und Instagram-Karten (privat, #portfolio, mit Link), „Erwähnt in“ zeigt Seiten und Aufgaben zum Wertpapier, Sparpläne und erwartete Dividenden im Kalender-Abo, „Frag dein Second Brain“ kennt das eigene Depot.
  - Einstellungen wie im alten Portfolio-Manager: eigene Kriterien (Stabilität, KGV, Verschuldung), Pushover an/aus mit Testnachricht, Alter der gepushten News, überwachte Wertpapiere (Kurse/News auch ohne Depot oder Watchlist) und feste ISIN/WKN-Zuordnungen für den Broker-Import. Alle Werte (in Euro) und die Oberfläche (Deutsch) sind fest; Standardwährung und Sprache aus dem alten Manager gibt es daher nicht mehr.
  - `portfolio:update-prices` aktualisiert Kurse und Depotwerte alle 15 Minuten; `portfolio:import-legacy {email}` übernimmt alle Daten aus dem alten Portfolio-Manager (Verbindung `PORTFOLIO_LEGACY_DB_*`).
- **Privat oder geteilt** – Seiten, Aufgaben und Inbox-Einträge können privat sein; nur die erstellende Person sieht sie.
- **Globale Suche** – `Strg/Cmd + K` durchsucht alle Module und Tags; mehrere Wörter werden in beliebiger Reihenfolge gefunden.
- **Haushalt** – Registrierung nur per Einladungscode, Echtzeit-Sync via Laravel Reverb.
- **Datenschutz** – JSON-Export aller eigenen Daten, Kontolöschung (private Einträge werden gelöscht, geteilte bleiben dem Haushalt erhalten).

## Technik

Laravel 13 · Livewire 3 / Volt · Laravel Reverb · Tailwind CSS 3 · SQLite (lokal) / MySQL (Server)

## Lokale Entwicklung

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate

# Einladungscode für den ersten Haushalt erzeugen
php artisan app:generate-household-invite

composer run dev   # Server, Queue, Logs und Vite
```

Registrierung unter `/register` mit dem erzeugten Code.

## KI-Funktionen (optional)

Rezept-Import von Fotos, PDFs und Text, die Nährwert-Schätzung, „Frag dein Second Brain“, Tag-Vorschläge, die Finanzanalystin und das Beleg-Auslesen nutzen die Claude-API. Dafür in der `.env` auf dem Server:

```bash
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-5   # optional, Standard
```

Ohne Schlüssel bleiben diese Funktionen ausgeblendet; der Import per Link funktioniert für die meisten Rezeptseiten trotzdem (strukturierte Rezeptdaten). An die Claude-API gehen nur die Inhalte, die beim Import bzw. der Schätzung ausgewertet werden; bei einer Frage die bis zu 15 passendsten Inhalte, die die fragende Person sehen darf, bei Tag-Vorschlägen Titel und Text der Seite bzw. des Eintrags; für die Finanzanalyse nur Summen pro Kategorie und wiederkehrende Zahlungen (beim KI-Kategorisieren Empfänger, Verwendungszweck und Betrag offener Buchungen – nie Kontonummern).

## Rezepte aus der RezepteApp2 übernehmen

Einmalig auf dem Server (im App-Verzeichnis), nachdem der Deploy gelaufen ist:

```bash
# 1. Zugang zur RezepteApp2-Datenbank in die .env eintragen
REZEPTEAPP_DB_HOST=127.0.0.1
REZEPTEAPP_DB_DATABASE=rezepteapp2
REZEPTEAPP_DB_USERNAME=rezepteapp2
REZEPTEAPP_DB_PASSWORD=...

# 2. Probelauf – zeigt nur, was übernommen würde
php artisan recipes:import-rezepteapp deine@email.de --dry-run

# 3. Import (Fotos werden aus dem Upload-Ordner der RezepteApp2 kopiert)
php artisan recipes:import-rezepteapp deine@email.de \
  --uploads-path=/var/www/vhosts/zimmimail.de/httpdocs/RezepteApp2/uploads
```

- Übernommen werden Rezepte, Kategorien, Tags, Favoriten, Bewertungen, Wochenplan, Titel- und weitere Fotos des RezepteApp2-Kontos mit derselben E-Mail (anderes Konto: `--source-email=...`).
- Die Rezepte landen geteilt im Haushalt der angegebenen Person (`--private` für nur privat).
- Der Befehl kann gefahrlos mehrfach laufen: Bereits übernommene Rezepte werden übersprungen, in LifeOS geänderte bleiben unangetastet.
- Fotos, die nicht im Upload-Ordner liegen, werden per URL geladen (`--uploads-url=https://zimmimail.de/RezepteApp2/uploads` als Fallback).

## Geplante Aufgaben (Erinnerungen)

Die täglichen E-Mails und die Erinnerungen zur Uhrzeit (jede Minute geprüft) verschickt der Laravel-Scheduler. Auf Staging/Live startet der Deploy ihn und den Queue-Worker automatisch als PM2-Prozesse (siehe `docs/deployment.md`). Anderswo einmalig einrichten, z. B. per Cron:

```bash
* * * * * cd /pfad/zur/app && php artisan schedule:run >> /dev/null 2>&1
```

Dazu müssen die `MAIL_*`-Einstellungen in der `.env` auf einen echten Mailserver zeigen.

Für Push-Benachrichtigungen einmalig ein Schlüsselpaar erzeugen und die ausgegebenen Zeilen in die `.env` übernehmen (danach `php artisan config:cache`, falls genutzt):

```bash
php artisan webpush:vapid
```

Die Schlüssel später nicht mehr ändern, sonst muss jedes Gerät die Benachrichtigungen neu einschalten. Pushes enthalten selbst keinen Text; der Service Worker holt die Nachricht nach dem Wecken aus der App. Es ist also kein zusätzliches Paket nötig, und über die Push-Dienste von Google, Apple oder Mozilla laufen keine Inhalte.

## Backups

Jede Nacht um 3:15 legt `php artisan backup:run` (über den Scheduler, siehe oben) eine ZIP-Datei an: `database.sql` (vollständiger SQL-Dump, ohne `mysqldump`) und `files/` (alle hochgeladenen Bilder und Dateien aus `storage/app`). Standardmäßig landen sie in `storage/backups`, die letzten 14 werden behalten. Über `BACKUP_PATH` und `BACKUP_KEEP` in der `.env` lässt sich das ändern. Am besten einen Ordner wählen, der zusätzlich vom Server weg kopiert wird.

Wiederherstellen: ZIP entpacken, `database.sql` in eine leere Datenbank einspielen (z. B. `mysql -u … -p datenbank < database.sql`) und den Inhalt von `files/` nach `storage/app/` kopieren. Die `.env` ist bewusst nicht enthalten (Passwörter); sie sollte separat gesichert werden.

## Tests & Code-Stil

```bash
php artisan test
vendor/bin/pint
```

GitHub Actions führt Tests und Pint bei jedem Push und Pull Request aus;
ein Deploy (Push auf `main`/`develop`) startet nur, wenn beides grün ist.

## Aufbau

- `resources/views/livewire/` – Seiten und Komponenten (Volt, PHP und Blade in einer Datei)
- `app/Livewire/Concerns/InteractsWithHousehold.php` – gemeinsame Haushaltslogik aller Module (Haushalt auflösen, Sichtbarkeit, Echtzeit-Refresh)
- `app/Models/Item.php` – Seiten (`type = note`), Aufgaben und Inbox-Einträge, inkl. Sichtbarkeit, Hierarchie, Tags und Wikilinks
- `app/Support/PageTree.php` – Seitenbaum (Unterseiten, Pfad, Zyklusschutz)
- `app/Models/Collection.php`, `CollectionProperty.php` – Sammlungen, Eigenschaften, Aufgaben-Sammlung (Status ↔ erledigt)
- `app/Support/NoteMarkdown.php` – sicheres Markdown-Rendering mit Wikilinks und abhakbaren Checklisten
- `resources/js/editor.js` – Tiptap-Editor (liest/schreibt Markdown, wird nur beim Bearbeiten nachgeladen)
- `app/Support/Addons.php` – abschaltbare Addons pro Haushalt (Middleware `addon:…`)
- `app/Models/Recipe.php`, `RecipeCategory.php`, `RecipeRating.php`, `MealPlan.php`, `resources/views/livewire/recipes/` – Rezepte-Addon (inkl. Wochenplan und Kochmodus); Fotos liegen privat unter `storage/app/private/recipes` und werden nur berechtigten Mitgliedern ausgeliefert
- `app/Console/Commands/ImportRezepteApp.php` – Import aus der RezepteApp2
- `lang/de.json`, `lang/de/` – deutsche Übersetzungen
