# Benachrichtigungen (Web-Push, In-App, Pushover)

Zentrale Klasse: `app/Support/WebPush.php`. In-App-Glocke: `app/Support/InAppNotifications.php` (Laravel
`notifications`-Tabelle, `type='app'`, `data = {title, body, url, category}`). Kategorien:
`app/Enums/NotificationCategory.php` (`mail`/`important`/`general`, backed enum; `fromValue()`, `label()`,
`icon()`, `bundleTag()`, `bundleTitle()`). Service-Worker: `public/service-worker.js`. Models: `PushMessage`
(hat Spalte `category`), `PushSubscription`.

## `WebPush::send(user, title, body, url=null, urgent=false, pushover=true, actions=[], category=null)`
- `category ??= urgent ? 'important' : 'general'`. Immer zuerst `InAppNotifications::record(..., $lane)`; dann
  Pushover (falls `pushover_all`), dann Web-Push an alle `PushSubscription` (verschlüsselt wo möglich, sonst leere
  Push → SW holt Texte nach). `PushMessage` wird mit `category` angelegt.
- **Quiet Hours** (`push_quiet_from/until`): nicht-urgente Pushes warten und kommen gebündelt
  (`releaseQuiet()`, Command `ReleaseQuietPushes`). Urgent kommt sofort (Urgency `high`).

## Spuren/Bündelung (`present()`)
- Wartende Nachrichten werden **pro Kategorie** gruppiert; je Spur mit ≥3 Nachrichten eine Sammelmeldung mit
  eigenem `bundleTag()` (`e11-bundle-mail|important|general`) → auf dem Gerät getrennt. Einzelkarten via `card()`
  (Tag `e11-<id>`). Mail-Sammelmeldung verlinkt `mail.index`, sonst `notifications.index`.
- Krypto (VAPID ES256, RFC 8291 aes128gcm) ist in `WebPush` selbst implementiert (ohne Extra-Paket);
  `isConfigured()` braucht `services.webpush.public_key/private_key`.

## In-App-Center & Einstellungen
- `livewire/notifications/index.blade.php`: Filter **Alle / 📬 Mails / ❗ Wichtig** (`where('data->category', …)`,
  JSON-Pfad – geht in MySQL & SQLite), Kategorie-Label je Zeile.
- Profil: `livewire/profile/push-notifications-form.blade.php` (Geräte, Quiet Hours, Bündeln, Pushover, Testpush).

## Weiterleitungen
- Portfolio-Benachrichtigungen laufen über `app/Support/Portfolio/Notifier.php` `send(..., category=null)` →
  `WebPush::send(category:)` + Pushover. News/Alerts nutzen `important`.
- Tests: `tests/Feature/PushNotificationsTest.php`, `NotificationCenterTest.php`. Achtung: ein Test-Stub, der
  `WebPush::send` überschreibt, muss die **volle Signatur** inkl. `?string $category = null` tragen, sonst Fatal
  (inkompatible Signatur).
