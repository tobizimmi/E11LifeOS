# Mail-Modul

UI: `resources/views/livewire/mail/index.blade.php`. Logik: `app/Support/Mail/*`. Models: `MailAccount`,
`MailMessage` (`app/Models/Mail*`). Commands: `app/Console/Commands/SyncMailAccounts.php`.

## Sync & Senden
- `MailSync` zieht neue Mails per IMAP in `mail_messages` (Ordner, uid, is_read …). `MailSender` verschickt per SMTP
  (CC/BCC, Anhänge, Signatur, Entwürfe). HTML-Ansicht wird sanitisiert.
- **OOM-Schutz** bei großen Anhängen: `app/Support/Mail/MimeMessage.php` – `ensureMemoryHeadroom()` (hebt
  `memory_limit` via `@ini_set` auf ≥512M), `MAX_PART_BYTES = 30 MiB`, überspringt zu große Parts.
- `MailIntake` triggert Automationen bei eingehender Mail; Anhänge können nach **Paperless** übernommen werden.

## Neue-Mail-Push
- `SyncMailAccounts::notifyNewMail()` sendet pro Konto eine Push mit `category: NotificationCategory::Mail->value`
  (→ Spur „Mails", getrennt vom Rest). Nur wenn `account->notify_new_mail`.

## Swipe & interne Nutzung
- `app/Support/Mail/MailSwipeActions.php`: `all()` → archive/trash/read/flag/none (je `method,label,icon,bg,leave`).
  Defaults: `DEFAULT_RIGHT='flag'`, `DEFAULT_LEFT='archive'`. Pro Nutzer in `users.mail_swipe_right/left`.
- `index.blade.php`: `#[Computed] swipe()` (aufgelöste Aktionen + rightKey/leftKey), `saveSwipe($side,$action)`,
  Swipe-Reihen via Alpine-Helfer `e11Swipe` (`resources/js/app.js`: `onRight/onLeft`, `leaveOnRight/Left`, armt bei
  35 % Breite, Haptik, Rubberband; bewegter Teil braucht `x-ref="content"` + Pointer-Handler).
- Interne Weiterverwendung: `toNote($id)` (QuickCapture::note), `downloadAttachment($id,$index): ?StreamedResponse`.
- Dynamische Swipe-Hintergrundfarben brauchen ein verstecktes Safelist-`<span class="hidden bg-… ">` im Blade.
