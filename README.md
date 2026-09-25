# E11LifeOS

Ein „Second Brain“ für den Haushalt: Seiten, Aufgaben, Einkaufsliste und eine
Inbox zum schnellen Erfassen – geteilt mit dem Haushalt oder privat, in Echtzeit
synchronisiert und als PWA auf dem Handy nutzbar.

Vision und Roadmap: [`docs/vision.md`](docs/vision.md) ·
Deployment: [`docs/deployment.md`](docs/deployment.md)

## Funktionen

- **Seitenleiste** – Navigation wie in Notion: Übersicht, Inbox, Haushalt, geteilter und privater Seitenbaum.
- **Übersicht** – offene Aufgaben, Einkaufsliste, zuletzt bearbeitete Seiten und Inbox auf einen Blick, mit Schnellerfassung.
- **Inbox** – erst erfassen, später einsortieren: als Aufgabe, als Seite oder auf die Einkaufsliste.
- **Seiten** – beliebig verschachtelbar (Unterseiten, Pfadanzeige, Icons), Editor wie in Notion (Tiptap: „/“-Befehlsmenü, Formatierungsleiste, Checklisten, Tabellen), Verknüpfungen mit `[[Seitentitel]]` inkl. Vorschlägen (fehlende Seiten per Klick anlegen), „Verlinkt von“-Rückverweise, Tags mit Filter, Checklisten direkt in der Leseansicht abhaken. Gespeichert wird Markdown.
- **Aufgaben** – eingebaute Sammlung mit Status, Fälligkeit und Zuständigkeit; als Board (Drag & Drop zwischen Spalten) oder Tabelle; Überfälliges wird hervorgehoben.
- **Sammlungen** – eigene Tabellen/Boards wie Notion-Datenbanken: Eigenschaften (Text, Zahl, Datum, Auswahl, Checkbox, Person, Link), Vorlagen (Projekte, Verträge, Leseliste), jeder Eintrag mit eigenen Notizen.
- **Einkaufsliste** – abhaken, per Wischen löschen.
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
- `lang/de.json`, `lang/de/` – deutsche Übersetzungen
