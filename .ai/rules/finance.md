# Finanzen

UI: `resources/views/livewire/finance/index.blade.php` (Tabs) + `resources/views/finance/partials/*`
(`report`, `transactions`, `accounts`). Logik: `app/Support/Finance/*`. Models: `Transaction`, `BankAccount`,
`FinanceCategory`.

## Report (`app/Support/Finance/FinanceReport.php`)
- `FinanceReport::build($transactions, $from, $to, $options)` liefert `income`, `expenses`, `categories`,
  `byAccount`, 50/30/20-Gruppen (`needs`/`wants`/`savings`), `savingsTransfers`, Vergleichsperiode (`compare`).
- **Umbuchungen** (`category === 'transfer'`) zählen standardmäßig **nicht** in Einnahmen/Ausgaben
  (`includeTransfers` zum Einschließen). **Sparen** (Gruppe `savings`) ebenso optional (`includeSavings`).
- `byAccount`: Aufschlüsselung je Konto über `data_get($group->first(), 'account.name')` (Larastan-sicher statt
  Nullsafe links von `??`).
- Perioden/Bereiche: `app/Support/Finance/FinancePeriod.php` (`range('6m')`, `CUSTOM`, `customRange`,
  `previousRange`).

## Kategorien (`app/Support/Finance/FinanceCategories.php`)
- `all()` = feste (`fixed()`, jeweils `custom=false`) + eigene (`custom()`). `group($key)` ordnet
  `needs/wants/savings/income/transfer`. `isCustom()`, `label()`, `exists()`, `flush()` (Cache leeren – in Tests im
  `setUp`).
- Eigene Kategorien: Model `FinanceCategory` (household_id, user_id, key `cat_…`, label, icon, group). Haushalt wird
  über `Auth::user()->currentHousehold()?->id` aufgelöst und memoisiert. Löschen verschiebt Buchungen auf `other`.

## Buchungen & Import
- `Transaction`: u. a. `bank_account_id`, `amount`, `booked_on`, `counterparty`, `purpose`, `category`,
  `duplicate_of_id`, `review_ignored_at`. Beziehung `account` (BankAccount).
- Inline bearbeiten/löschen in `index.blade.php`: `editTransaction/updateTransaction/deleteTransaction`
  (`txEdit`-Array). Konto-Tab: `setBalance($accountId,$value)` setzt `balance`+`balance_on`.
- Import: `app/Support/Finance/BankCsvImporter.php` `importBookings()` – Within-Batch-Dedup (`$seen`) +
  per-Buchung try/catch (`Throwable`), damit eine kaputte Zeile nicht den ganzen Lauf kippt.
- **Umbuchungs-/Duplikaterkennung**: `app/Support/Finance/TransactionReview.php` (`transfers(...)` findet Paare
  gegenläufiger Beträge zwischen eigenen Konten). Bulk-Bestätigung `confirmAllTransfers()` in `index.blade.php`
  setzt beide Seiten auf `category='transfer'`.

## Hinweise
- Kontostände/Netto: Finance-Konten (BankAccount) speisen `net-worth`/`wealth-plan`. Bank-synchronisierte Konten
  aktualisieren `balance` automatisch (siehe `app/Support/Banking/BankSync::storeBalances`).
