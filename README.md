# E11LifeOS

Ein „Second Brain“ für den Haushalt: Notizen, Aufgaben, Einkaufsliste und eine
Inbox zum schnellen Erfassen – geteilt mit dem Haushalt oder privat, in Echtzeit
synchronisiert und als PWA auf dem Handy nutzbar.

Vision und Roadmap: [`docs/vision.md`](docs/vision.md) ·
Deployment: [`docs/deployment.md`](docs/deployment.md)

## Funktionen

- **Übersicht** – offene Aufgaben, Einkaufsliste, zuletzt bearbeitete Notizen und Inbox auf einen Blick, mit Schnellerfassung.
- **Inbox** – erst erfassen, später einsortieren: als Aufgabe, als Notiz oder auf die Einkaufsliste.
- **Notizen** – Markdown, Verknüpfungen mit `[[Notiztitel]]` (fehlende Notizen per Klick anlegen), „Verlinkt von“-Rückverweise, Tags mit Filter.
- **Aufgaben** – sortierbar per Drag & Drop.
- **Einkaufsliste** – abhaken, per Wischen löschen.
- **Privat oder geteilt** – Notizen, Aufgaben und Inbox-Einträge können privat sein; nur die erstellende Person sieht sie.
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
- `app/Models/Item.php` – Notizen, Aufgaben und Inbox-Einträge (Feld `type`), inkl. Sichtbarkeit, Tags und Wikilinks
- `app/Support/NoteMarkdown.php` – sicheres Markdown-Rendering mit Wikilinks
- `lang/de.json`, `lang/de/` – deutsche Übersetzungen
