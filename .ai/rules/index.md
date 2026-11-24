# .ai/rules – Index (Second Brain)

Kurze, gepflegte Referenz zur Codebasis, damit Agenten **nicht den ganzen Code lesen** müssen,
sondern gezielt nachschlagen und greppen. Vor dem Bearbeiten einer Datei: die Regel-Datei(en)
lesen, deren Glob den Pfad trifft. Für die Modul-Landkarte (wo liegt was) → `map.md`.

> Pflege-Regel: Wer eine hier beschriebene Struktur/Konvention ändert, aktualisiert die passende
> Regel-Datei im selben Commit. Keine Tagebuch-Einträge, nur dauerhaft gültige Fakten.

## Glob → Regel-Datei

| Pfad-Glob | Regel-Datei | Inhalt |
|---|---|---|
| `**/*` (immer zuerst) | `conventions.md` | Deploy-Flow, Volt, Household, Benachrichtigungen, Pint/PHPStan, de.json, Tests, Cloud-Env-Fallen |
| alle Pfade (Orientierung) | `map.md` | Modul-Landkarte: Modul → Support-Klassen / Livewire-Views / Models |
| `app/Support/Portfolio/**`, `resources/views/livewire/portfolio/**`, `app/Support/Banking/**`, `app/Models/Portfolio*`, `app/Models/Bank*` | `portfolio.md` | Depot, Bank-Sync (FinTS/Enable Banking), Kaufkurs/Einstand, Positionen, Bewertung, News, Übersicht |
| `app/Support/Finance/**`, `resources/views/livewire/finance/**`, `app/Models/Transaction.php`, `app/Models/BankAccount.php`, `app/Models/FinanceCategory.php` | `finance.md` | Finanzen: Report, Kategorien, Buchungen, Konten, Umbuchungen |
| `app/Support/Mail/**`, `resources/views/livewire/mail/**`, `app/Models/Mail*` | `mail.md` | Mail-Modul: Sync, Senden, Swipe, Anhänge, Benachrichtigungen |
| `app/Support/WebPush.php`, `app/Enums/NotificationCategory.php`, `app/Support/InAppNotifications.php`, `app/Support/Portfolio/Notifier.php`, `public/service-worker.js` | `notifications.md` | Web-Push, Pushover, In-App-Glocke, Kategorien/Spuren |

Trifft kein spezifischer Glob: `conventions.md` + `map.md` reichen zur Orientierung, dann gezielt greppen.
