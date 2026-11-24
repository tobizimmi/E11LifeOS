# Modul-Landkarte (wo liegt was)

Große, modulare Laravel-App (Haushalts-/„Second-Brain"-OS). Muster überall gleich:
- **Logik**: `app/Support/<Bereich>/…` (Support-Klassen) + `app/Models/…`.
- **UI**: `resources/views/livewire/<modul>/*.blade.php` (Volt) + `resources/views/<modul>/partials/…`.
- **Routen**: `routes/web.php` (Web), `routes/api.php` (Mobile-API, versioniert `/api/v1/…`), `routes/console.php`,
  `routes/auth.php`, `routes/channels.php`.
- **Commands**: `app/Console/Commands/…` (geplante Jobs; Scheduler in `routes/console.php`).

So findet man ein Modul schnell: `resources/views/livewire/<modul>/` + `grep -ril <stichwort> app/Support`.

## Kern-Bereiche (eigene Regel-Datei)
- **Portfolio / Depot / Bank-Sync** → `portfolio.md`. Support: `app/Support/Portfolio/*`, `app/Support/Banking/*`.
  UI: `livewire/portfolio/*`. Models: `Portfolio*`, `Bank*`, `Security`, `WatchlistItem`, `NewsArticle`,
  `InstagramPost`, `PriceAlert`.
- **Finanzen** → `finance.md`. Support: `app/Support/Finance/*`. UI: `livewire/finance/*`, `livewire/cashflow/*`.
  Models: `Transaction`, `BankAccount`, `FinanceCategory`.
- **Mail** → `mail.md`. Support: `app/Support/Mail/*`. UI: `livewire/mail/*`. Models: `Mail*`.
- **Benachrichtigungen** → `notifications.md`. `app/Support/WebPush.php`, `InAppNotifications.php`,
  `app/Enums/NotificationCategory.php`, `public/service-worker.js`.

## Weitere Module (Support-Ordner ↔ Livewire-Ordner)
| Bereich | Support | Livewire-Views |
|---|---|---|
| Aufgaben/Fokus/Heute | `TaskInput`, `TaskLists`, `TaskPages`, `TodayView`, `DeadlineTasks` (Life) | `tasks`, `focus`, `review` |
| Notizen/Wiki/Seiten | `PageTree`, `PageTemplates`, `PageRevisions`, `PageMover`, `NoteMarkdown`, `Mentions` | `notes`, `pages`, `spaces` |
| Sammlungen/DB | `Collections/*`, `CollectionTemplates`, `CollectionReport`, `InsertableLists` | `collections` |
| Einkauf/Vorrat/Rezepte | `Shopping/*`, `ShoppingLists`, `Pantry`, `Recipe*`, `MealPlan*`, `IngredientUnits` | `shopping-list`, `pantry`, `recipes` |
| Kalender | `Calendar/*`, `CalendarEvents`, `CalendarFeed`, `IcsCalendar`, `Recurrence` | `calendar` |
| Wissen/Lernen | `Knowledge/*`, `BrainAi`, `BrainSearch`, `WeeklyInsights` | `knowledge`, `brain`, `learnings` |
| Dokumente/Paperless | `Paperless/*`, `Drives/*`, `Sharing` | `documents`, `drives`, `paperless-inbox` |
| Haushalt/Personen/Kinder | `Life/*` (PocketMoney, HabitTracker, GroupTrip, TripItinerary …) | `household`, `people`, `kids`, `groups` |
| Immobilien/Miete/Fahrzeuge | `RealEstate/*`, `PropertyDisplay` | `real-estate`, `rental`, `vehicles` |
| Verträge/Briefe/Karten | `ContractAssistant`, `ContractOverview`, `LetterTemplates`, `CardCode`, `CardImages` | `contracts`, `letters`, `loyalty-cards` |
| Business/Karriere/Rechnungen | `Business/*`, `Career/*` | `business`, `career`, `work` |
| Vermögen/Netto/Cashflow | `wealth-plan`, `net-worth`, `cashflow`, `splits` (Modelle + Finance/Portfolio) | `wealth-plan`, `net-worth` |
| Smart Home / Wetter / VPN | `SmartHome/*`, `ConnectionWatch`, `Weather`, `Vpn` | `smart-home`, `house` |
| Lesezeichen/Links | `Bookmark*`, `PageBookmarks`, `Links`, `LinkPreview`, `ExternalLinks` | `bookmarks`, `links-panel` |
| Reisen | `Life/TripItinerary`, `GroupTrip` | `trips` |
| Helpdesk/Admin/Betrieb | `Helpdesk/*`, `Admin/*`, `Operations/*`, `Onboarding`, `ActivityLog` | `admin`, `support`, `provision`, `emergency` |
| Scan/Barcode/QR | `Barcode`, `QrCode`, `CardCode`, `ProductLookup`, `SmartCapture`, `QuickCapture` | `scan`, `quick-capture` |
| Journal/Zitate/Gewohnheiten | `Life/HabitTracker`, `MorningBriefing`, `WeeklyDigest` | `journal`, `quotes` |
| Wunschliste | `WishlistPrices`, `PriceFinder` | `wishlist` |
| Profil/2FA/Einstellungen | `TwoFactor`, `AppSettings`, `Addons`, `Navigation`, `DashboardWidgets` | `profile`, `modules`, `navigation`, `dashboard` |
| Inbox/Schnellerfassung | `InboxActions`, `QuickCapture`, `ConvertsToTask`, `TaskInput` | `inbox` |
| KI-Infrastruktur | `StructuredAi` (Basis), `AiFeatures`, `AiException`, diverse `*Ai` | — |

## Querschnitt
- **Concerns**: `app/Livewire/Concerns/` (`InteractsWithHousehold`, `InteractsWithPortfolio`,
  `InteractsWithBusiness`, `StoresAttachments`, `ProvidesLinkTargets`, `SuggestsTags`).
- **Anhänge/Uploads**: `StoresAttachments`, `PrefixAwareSignedUploadUrl`, `VideoPoster`.
- **Backups/DB**: `app/Support/Backup/*`, `DatabaseDump`, `DatabaseDump`.
- **Mobile-API**: `routes/api.php`, Tests `tests/Feature/Api*Test.php` (Muster für neue Endpunkte).
- **Suche**: globale Palette `livewire/search-palette.blade.php`, `BrainSearch`.
