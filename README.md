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
- **Aufgaben** – eingebaute Sammlung mit Status, Fälligkeit, Zuständigkeit und Wiederholung (täglich/wöchentlich/monatlich/jährlich – abgehakt springt die Aufgabe zum nächsten Termin); als Board, Tabelle oder Kalender; Überfälliges wird hervorgehoben.
- **Kalender** – Monatsansicht für jede Sammlung mit Datumsfeld: Einträge per Drag & Drop umplanen, direkt an einem Tag anlegen.
- **Sammlungen** – eigene Tabellen/Boards wie Notion-Datenbanken: Eigenschaften (Text, Zahl, Datum, Auswahl, Checkbox, Person, Link), Vorlagen (Projekte, Verträge, Leseliste), jeder Eintrag mit eigenen Notizen.
- **Einkaufsliste** – abhaken, per Wischen löschen.
- **Rezepte (Addon)** – Rezeptsammlung mit Foto und weiteren Bildern (Galerie), Zutaten (mit Abschnitten), Zubereitungsschritten, Portionsrechner, Kategorien, Tags, Favoriten, Bewertungen (Sterne + Notiz pro Person), Archiv und Duplizieren; ausgewählte Zutaten landen mit einem Klick (umgerechnet) auf der Einkaufsliste. „Was kann ich kochen?“ sucht nach vorhandenen Zutaten. Pro Haushalt ein-/ausschaltbar (Haushalt → Addons).
- **Kochmodus** – Schritt für Schritt im Vollbild, Timer aus dem Schritttext („10 Minuten“) oder per Schnellwahl, Alarm mit Ton/Vibration, Bildschirm bleibt an; am Ende bewerten.
- **Wochenplan** – Rezepte oder freie Einträge („Reste“) pro Tag und Mahlzeit planen, per Drag & Drop verschieben; die Zutaten der Woche zusammengefasst auf die Einkaufsliste; „Heute auf dem Speiseplan“ auf der Übersicht.
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
