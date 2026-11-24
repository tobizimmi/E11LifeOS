# Konventionen & Fallen (immer gültig)

Stack: Laravel (PHP 8.5 lokal/CI, **Cloud-Container nur 8.3**), Livewire **Volt** (Single-File-Components),
Tailwind **v4** (kein `tailwind.config.js`, Auto-Content-Detection, `@source` in `app.css`), Alpine.js.

## Deploy-Flow (wichtig!)
- Branches: Feature-Branch → `develop` (**Staging**) → `main` (**Live**). Entwicklungs-Branch dieser Session:
  `claude/e11lifeos-second-brain-re5ulu`.
- **Standing Order des Users:** erst **nur auf Staging** (`develop`), Live (`main`) **nur nach ausdrücklicher Freigabe**.
- Merge je mit `git merge --no-ff --no-edit`, jeder Push einzeln (Netzwerk-Retries mit Backoff). Nach Release
  `main → Feature-Branch` zurückmergen. Feature-Branch nach jedem develop-Merge zurück-synchen.
- CI-Status: `gh api repos/tobizimmi/E11LifeOS/commits/<sha>/check-runs`. Checks: `tests / tests`,
  `tests / tests-mysql`, `tests / security` (= composer/npm audit + **PHPStan/Larastan**).
- Version: oberster Eintrag in `CHANGELOG.md` ist die aktuelle Version (gelesen von `app/Support/AppVersion.php`).
  Jede nutzersichtbare Änderung bekommt einen CHANGELOG-Eintrag (deutsch, Abschnitt „Neu/Verbessert/Behoben (…)").
- Commit-Footer: `Co-Authored-By: Claude …` + `Claude-Session: …` (siehe Session-Reminder). Committer-E-Mail muss
  `noreply@anthropic.com` sein, sonst „Unverified".

## Volt / Livewire
- Komponenten sind Single-File: `new #[Layout('layouts.app')] class extends Component { … }` + Blade darunter.
- Daten an die View per `with()` **oder** `#[Computed]` – pro Komponente konsistent bleiben (bestehende prüfen).
- Household-Scope: Concern `InteractsWithHousehold` → `currentUser()`, `householdId`, `currentHousehold()`,
  `resolveHousehold()` (in `mount()`). Portfolio: `InteractsWithPortfolio` → `portfolios()`, `watchlist()`,
  `portfolioSettings()`. Immer über diese Helfer scopen (Mandantentrennung!).
- Neue Artisan-/Model-Dateien via `php artisan make:…` erzeugen (Konventionen). Modelle nutzen das
  `#[Fillable([...])]`-Attribut statt `$fillable`.

## Benachrichtigungen (Kurz; Details in notifications.md)
- Zentral: `WebPush::send($user, $title, $body, $url, urgent, pushover, actions, category)`. `category` ist eine
  `App\Enums\NotificationCategory` (`mail` / `important` / `general`). Default: urgent→important, sonst general.
- Immer auch In-App-Glocke (`InAppNotifications::record`). Mail-Pushes → `mail`-Spur, News/Alerts → `important`.

## Code-Stil / statische Analyse
- **Pint** vor Abschluss: `vendor/bin/pint --dirty --format agent` (im Cloud-Container oft nicht lauffähig →
  dann CI machen lassen). Importe nutzen statt voll-qualifizierter Namen (`\App\…`) – `fully_qualified_strict_types`
  schlägt sonst in Tests/Code zu. Importe alphabetisch (`ordered_imports`).
- **Larastan/PHPStan** (Level 5, Baseline `phpstan-baseline.neon`): Larastan liest **Migrationen**, um Model-
  Properties zu typen. Properties auf **untypisierten** Relations-Items (z. B. `foreach ($portfolio->transactions()…`
  → Items sind `Model`) lösen `property.notFound` aus → Baseline-Eintrag nötig (Format: siehe vorhandene Einträge,
  Tabs!). Generische Collections sind **invariant** (`Collection<string,float>` ≠ `Collection<non-falsy-string,float>`)
  → im Zweifel `array<...>` statt `Collection` übergeben. `$model->relation` (z. B. `$connection->user`) ist oft
  `Model|null` → vor Nutzung `instanceof` prüfen.

## Übersetzungen `lang/de.json`
- **Insertion-Order, NICHT sortiert.** Neue Keys **anhängen** (nie das File neu sortieren/serialisieren – riesiger
  Diff). Sicher per kleinem Python-Skript mit `OrderedDict` und `json.dump(..., ensure_ascii=False, indent=4)` +
  Trailing-Newline. Danach `json.load` zur Validierung.
- Dynamische Tailwind-Klassennamen aus PHP brauchen ein verstecktes Safelist-`<span class="hidden …">` im Blade.

## Tests
- PHPUnit (`php artisan test --compact`, `--filter=…`, Pfad). Feature-Tests bevorzugt, Factories nutzen (Default-
  Werte beachten – z. B. `fees` wird zufällig gesetzt → in Kaufwert-Tests explizit `'fees' => 0`). Verhaltens-/
  Logikänderungen brauchen Tests; reine Copy/Layout-Änderungen nicht.
- `vendor/bin/phpunit` geht auch. Vor Push: engste sinnvolle Test-Teilmenge grün.

## Cloud-Container-Fallen (diese Session-Umgebung)
- `vendor/` ist oft **unvollständig** (Composer-Install scheitert an GitHub-Auth), PHP ist **8.3** statt 8.5 →
  **lokale Testläufe oft unmöglich** → auf CI verlassen. `php -l` zur Syntaxprüfung geht immer.
- `nemiah/php-fints` liegt als Git-Mirror im Composer-Cache (`/root/.cache/composer/vcs/…phpFinTS.git`) – bei Bedarf
  per `git archive <sha> | tar -x` nach `vendor/nemiah/php-fints/` entpacken. Patch: `patches/nemiah-phpfints-hkwpd-v6.patch`.
- `git push` gelegentlich mit Backoff wiederholen. GitHub nur via `gh api` (REST), **nicht** `gh pr/issue` (GraphQL gesperrt).
