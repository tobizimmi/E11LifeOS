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
- **Inbox** – erst erfassen, später einsortieren: als Aufgabe, als Seite oder auf die Einkaufsliste; `#tags` im Text werden zu Tags.
- **Erinnerungen** – täglich um 7:00 per E-Mail und/oder Push-Benachrichtigung aufs Handy/den Computer: fällige und überfällige Aufgaben sowie Termine aus Sammlungen, z. B. Kündigungsfristen (im Profil einschalten; auf dem iPhone nach „Zum Home-Bildschirm“).
- **Seiten** – beliebig verschachtelbar (Unterseiten, Pfadanzeige, Icons), Editor wie in Notion (Tiptap: „/“-Befehlsmenü, Formatierungsleiste, Checklisten, Tabellen), Verknüpfungen mit `[[Seitentitel]]` inkl. Vorschlägen (fehlende Seiten per Klick anlegen), „Verlinkt von“-Rückverweise, Tags mit Filter, Checklisten direkt in der Leseansicht abhaken, Bilder und Dateien per Knopf, „/“-Befehl, Drag & Drop oder Einfügen (privat gespeichert, sichtbar wie die Seite), Seiten in der Seitenleiste per Drag & Drop sortieren und verschieben. Gespeichert wird Markdown.
- **Aufgaben** – eigene Aufgabenseite, einfach und schnell:
  - Smart-Listen „Heute“ (inkl. Überfälligem), „Geplant“, „Alle“, „Meine“ und „Erledigt“, gruppiert nach Datum.
  - Schnelleingabe in natürlicher Sprache, getippt oder diktiert: „Zahnarzt anrufen morgen !hoch @Anna #gesundheit“ oder „Müll rausbringen jeden Dienstag“. Erkannte Angaben erscheinen als Chips und lassen sich per × wieder in den Titel zurücknehmen.
  - Priorität, Unteraufgaben, Notizen, Wiederholung (abgehakt springt die Aufgabe zum nächsten Termin), Zuständigkeit, Tags und Verknüpfungen im Detail-Panel.
  - Abhaken mit „Rückgängig“; auf dem Handy nach rechts wischen = erledigt, nach links = auf morgen.
  - Mehrfachauswahl: mehrere Aufgaben auf einmal erledigen, verschieben, priorisieren, zuweisen oder löschen.
  - Dieselben Aufgaben bleiben als Board, Tabelle oder Kalender verfügbar.
- **Kalender** – Monats- und Wochenansicht für jede Sammlung mit Datumsfeld: Einträge per Drag & Drop umplanen, direkt an einem Tag anlegen; künftige Termine wiederkehrender Aufgaben als Vorschau.
- **Sammlungen** – eigene Tabellen/Boards wie Notion-Datenbanken: Eigenschaften (Text, Zahl, Datum, Auswahl, Mehrfachauswahl, Checkbox, Person, Link, Verknüpfung), jeder Eintrag mit eigenen Notizen und Dateien; Filter pro Eigenschaft (z. B. „Fällig: überfällig“, „Zuständig: ich“) und gespeicherte Ansichten als Reiter, Summen für Zahlen und Checkboxen, E-Mail-Erinnerung für Datumsfelder, Einträge per Klick auf die Einkaufsliste.
- **Module als Vorlagen** – Verträge & Dokumente (Kündigungsfrist mit Erinnerung), Haushaltsbuch, Vorräte (Haltbarkeit mit Erinnerung), Lesezeichen / Später lesen, Tagebuch (privat), Gewohnheiten, Projekte, Leseliste.
- **Einkaufsliste** – abhaken, per Wischen löschen, auch offline lesbar (zuletzt geladener Stand), Produkte per Barcode-Scanner eintragen (Kamera auf Android und iPhone, alternativ Nummer eintippen; Daten von Open Food Facts).
- **Rezepte (Addon)** – Rezeptsammlung mit Foto und weiteren Bildern (Galerie), Zutaten (mit Abschnitten), Zubereitungsschritten, Portionsrechner, Kategorien, Tags, Favoriten, Bewertungen (Sterne + Notiz pro Person), Archiv und Duplizieren; ausgewählte Zutaten landen mit einem Klick (umgerechnet) auf der Einkaufsliste. „Was kann ich kochen?“ sucht nach vorhandenen Zutaten. Pro Haushalt ein-/ausschaltbar (Haushalt → Addons).
- **Rezepte importieren** – per Link (liest die Rezeptdaten der Seite direkt, sonst die KI), Foto/Screenshot (auch Kochbuchseiten und Handschrift), PDF oder eingefügtem Text; das Formular öffnet vorausgefüllt zum Prüfen. Dazu Nährwerte pro Portion (von Hand oder per KI geschätzt), PDF-Export und öffentliche Links zum Teilen (jederzeit abschaltbar).
- **Kochmodus** – Schritt für Schritt im Vollbild, Timer aus dem Schritttext („10 Minuten“) oder per Schnellwahl, Alarm mit Ton/Vibration, Bildschirm bleibt an; am Ende bewerten.
- **Wochenplan** – Rezepte oder freie Einträge („Reste“) pro Tag und Mahlzeit planen, per Drag & Drop verschieben; die Zutaten der Woche zusammengefasst auf die Einkaufsliste; „Heute auf dem Speiseplan“ auf der Übersicht.
- **Verknüpfungen überall** – Seiten, Aufgaben, Einträge, Inbox und Rezepte lassen sich untereinander verlinken: im Text über `[[` (Vorschläge für alles), per Knopf „Verknüpfen“ oder über die Eigenschaft „Verknüpfung“. Jedes Ziel zeigt „Verknüpfungen“ und „Verlinkt von“.
- **Frag dein Second Brain (KI, optional)** – Fragen in eigenen Worten (auch per Spracheingabe oder aus der Suche heraus), z. B. „Wann endet die Autoversicherung?“; die Antwort stützt sich nur auf Seiten, Aufgaben, Einträge und Rezepte, die du sehen darfst, und verlinkt ihre Quellen. Dazu Tag-Vorschläge per ✨ bei Seiten und Einträgen.
- **Privat oder geteilt** – Seiten, Aufgaben und Inbox-Einträge können privat sein; nur die erstellende Person sieht sie.
- **Globale Suche** – `Strg/Cmd + K` durchsucht alle Module und Tags.
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

Rezept-Import von Fotos, PDFs und Text, die Nährwert-Schätzung, „Frag dein Second Brain“ und Tag-Vorschläge nutzen die Claude-API. Dafür in der `.env` auf dem Server:

```bash
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-opus-5   # optional, Standard
```

Ohne Schlüssel bleiben diese Funktionen ausgeblendet; der Import per Link funktioniert für die meisten Rezeptseiten trotzdem (strukturierte Rezeptdaten). An die Claude-API gehen nur die Inhalte, die beim Import bzw. der Schätzung ausgewertet werden; bei einer Frage die bis zu 15 passendsten Inhalte, die die fragende Person sehen darf, bei Tag-Vorschlägen Titel und Text der Seite bzw. des Eintrags.

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

Die täglichen E-Mails verschickt der Laravel-Scheduler. Auf dem Server einmalig einrichten, z. B. per Cron:

```bash
* * * * * cd /pfad/zur/app && php artisan schedule:run >> /dev/null 2>&1
```

Dazu müssen die `MAIL_*`-Einstellungen in der `.env` auf einen echten Mailserver zeigen.

Für Push-Benachrichtigungen einmalig ein Schlüsselpaar erzeugen und die ausgegebenen Zeilen in die `.env` übernehmen (danach `php artisan config:cache`, falls genutzt):

```bash
php artisan webpush:vapid
```

Die Schlüssel später nicht mehr ändern, sonst muss jedes Gerät die Benachrichtigungen neu einschalten. Pushes enthalten selbst keinen Text; der Service Worker holt die Nachricht nach dem Wecken aus der App. Es ist also kein zusätzliches Paket nötig, und über die Push-Dienste von Google, Apple oder Mozilla laufen keine Inhalte.

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
