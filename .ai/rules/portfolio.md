# Portfolio / Depot / Bank-Sync

UI: `resources/views/livewire/portfolio/*.blade.php` (Overview = `index.blade.php`, Nav-Tabs in
`resources/views/portfolio/partials/nav.blade.php`). Logik: `app/Support/Portfolio/*`, `app/Support/Banking/*`.

## Positionen & Bewertung
- **`Positions::recalculate(Portfolio)`** baut `portfolio_positions` aus `portfolio_transactions` neu auf
  (Average-Cost). `buy`/`transfer_in` erhöhen `cost` (+Fees, /Wechselkurs) & `quantity`; `sell`/`transfer_out`
  entnehmen zum Durchschnitt; `split`: `price` = Faktor. **Manuelle** Positionen (`manual=true`) ohne Transaktionen
  überleben; eine `cost_basis_override` überlebt ebenfalls (wird in `recalculate` nicht angefasst).
- **`PortfolioPosition`**: `costBasis()` = `quantity * effectiveBuyPrice()`, `effectiveBuyPrice()` =
  `cost_basis_override ?? avg_buy_price`. Flags/Helfer: `cost_basis_override` (manueller Einstand),
  `cost_basis_estimated` (Import hatte keinen verlässlichen Einstand → aktueller Kurs gebucht),
  `cost_basis_override_stale` (Menge beim Import geändert), `needsManualCost()`, `overrideNeedsReview()`.
- **`Valuation::rows()`** (`app/Support/Portfolio/Valuation.php`): `gain = value − cost`,
  `value = toEuro(quote) * quantity`, `cost = toEuro(costBasis(), currency)`. Quotes aus `MarketData` (gecacht,
  `QUOTE_TTL`). `MarketData::toEuro()` behandelt `GBp/GBX` (/100) und FX-Raten.

## Bank-Sync (`app/Support/Banking/BankSync.php`)
- `run($connection, $operation, $pin)` mit Operationen `test|accounts|statement|depot`; TAN-/Decoupled-Flow über
  `pending_state`. Fehler werden generisch gemeldet (kein Port-Scan-Oracle), Details nur ins Log.
- `BANKS`-Preset (ING, DKB, comdirect, Consorsbank, Smartbroker/Baader, flatex, Sparkasse, custom).
- **Depot-Abgleich**: `compare()` (ISIN→Symbol, Mengendiff) → `applyToPortfolio($connection, 'add'|'replace')`.
  `add` gleicht nur gemeldete Bestände an; `replace` bucht zusätzlich nicht mehr gemeldete Positionen aus
  (`bookOutMissingPositions`). Erzeugt `transfer_in/out`-Transaktionen, dann `Positions::recalculate`.
- **Einstandskurs-Plausibilität** `costBasisFor(BankDepotPosition): {price, estimated}`: MT535-Einstandskurs
  (`:70E:`) ist oft fehlend/falsch skaliert → nur übernehmen, wenn 0,1×–10× des aktuellen Kurses; sonst aktueller
  Kurs + `estimated=true` (→ `cost_estimated` auf der Transaktion → `cost_basis_estimated` auf der Position →
  Badge „⚠ Kaufkurs setzen"). Gilt für **alle** Wertpapiere (keine Sonderfälle).
- **Mengenänderung bei manuellem Einstand**: `flagChangedOverrides()` vergleicht Mengen vor/nach Abgleich; bei
  Änderung einer Position mit `cost_basis_override` → `cost_basis_override_stale=true` + Push (Kategorie
  `important`) mit Link ins Depot.
- UI-Seite `livewire/portfolio/banking.blade.php`: `applyToPortfolio()` leitet nach Erfolg per
  `redirect(route('portfolio.index', ['depot' => …]), navigate:true)` + Flash `portfolioReconciled` ins Depot.

## Manuellen Kaufkurs bearbeiten
- `livewire/portfolio/index.blade.php`: `editPosition`/`savePosition`. Für **nicht-manuelle** (importierte)
  Positionen setzt das Feld `positionForm.avg_buy_price` die `cost_basis_override` (leer = löschen), inkl. Gebühren,
  und setzt `cost_basis_override_stale=false`. Vorbefüllt mit `cost_basis_override ?? avg_buy_price`.

## FinTS-Bibliothek (`nemiah/php-fints` 4.1)
- `FinTsClient.php` nutzt `GetDepotAufstellung` (MT535). Patch `patches/nemiah-phpfints-hkwpd-v6.patch` ergänzt
  HKWPD/HIWPD **v6** (ING/Smartbroker/DKB). Deploy: `deploy.yml` wendet den Patch nach `composer install --no-scripts`
  explizit an; zusätzlich `composer.json` `post-install/update-cmd` → `apply-fints-patch` (Scripts sind nicht im
  Content-Hash, daher ohne Lock-Neugenerierung ok). MT535-Holding-Getter: `getAmount` (Stück), `getPrice`
  (akt. Kurs/Stück), `getAcquisitionPrice` (Einstand/Stück, unzuverlässig), `getValue` (Gesamtwert).
- Enable Banking: `app/Support/Banking/BankLinkSync.php` (IBAN auf 255 gekappt, Konto-Isolation). IBAN-Spalte
  via Migration auf 255 geweitet.

## Overview-Einblicke (`index.blade.php` + `portfolio/partials/insights.blade.php`)
- Auf-/zuklappbarer Block „Einblicke & Empfehlungen" (`insightsOpen`, in Session gemerkt, Default zu; schwere
  Daten nur bei offen geladen). Vier Blöcke aus vorhandenen Quellen: `PortfolioRecommendation` (Top, Score),
  `NewsArticle` (Depot+Watchlist), `StrategyCoach::forUser($user,$valuation)['signals']` (To-dos), neuester
  analysierter `InstagramPost` (summary + key_points = „Markteinschätzung/Thieme").
- News: `app/Support/Portfolio/NewsFeed.php` holt Markt-Feeds + Google-News pro Wertpapier → `news_articles`;
  `notify(Notifier)` pusht Schlagzeilen zu gehaltenen+Watchlist-Werten (Kategorie `important`).
- Empfehlungen: `RecommendationEngine` (Strategien conservative/moderate/aggressive/thieme), vorab berechnet in
  `PortfolioRecommendation`. Strategie: `StrategyCoach` (Drei-Drittel-Strategie, Verkaufssignale gg. Einstand).
