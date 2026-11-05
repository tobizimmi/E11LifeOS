# Changelog

Alle wichtigen Änderungen an E11LifeOS. Die oberste Versionsnummer ist die aktuelle Version;
jeder Deploy bekommt zusätzlich eine fortlaufende Build-Nummer.

## [1.83.0] – 2026-11-05

### Neu
- **KI-Essensplaner.** Neuer Bereich `Rezepte → Planer`: aus den Vorlieben (Budget, Supermarkt, PLZ, Personen, Tage, gewünschte Mahlzeiten, Allergene, Gerichtsarten, Küchen, Abneigungen, vorhandene Küchengeräte, Meal-Prep) erstellt die KI einen Wochenplan für **einen** Supermarkt – inklusive erfundener Rezepte, konsolidierter Einkaufsliste mit Preisschätzung und Gesamtkosten gegen das Budget. Die Rezepte lassen sich in die Rezepte-App und den Wochenplan übernehmen, die Einkaufsliste wird direkt angelegt.
- **Wunschliste: automatische Preis-Recherche und bessere Übersicht.** Aus der hinterlegten Artikel-URL wird je Wunsch regelmäßig der Online-Preis ermittelt (JSON-LD/OpenGraph) mit Tiefstpreis und Benachrichtigung bei Preisrückgang. Dazu Filtern nach Kategorie, Sortieren (Priorität, Preis, größte Ersparnis, neueste, Name) und ein „Preis prüfen"-Knopf.
- **Abo-Radar.** Ein täglicher Abgleich der erkannten wiederkehrenden Zahlungen meldet proaktiv, wenn ein **neues Abo** auftaucht oder ein bekanntes **teurer** wird (ab +5 % und mindestens 1 €), mit Link zu den Vertragskosten. Der erste Lauf merkt sich nur den Stand, ohne zu benachrichtigen.
- **Sammlungen wie in Notion: Datei-/Bild-Spalten und Bericht.** Die frei definierbaren Tabellen/Datenbanken lassen sich jetzt um Spalten vom Typ **Bilder** und **Dateien/Anhänge** erweitern (Upload je Eintrag, Bildvorschau als Miniatur). Zusätzlich erzeugt ein **PDF-Bericht** aus einer Sammlung alle (gefilterten) Einträge mit ihren Spaltenwerten, Notizen und eingebetteten Bildern im PDF-Design des Haushalts.

### Sicherheit
- **symfony-Komponenten auf v8.1.11.** Schließt zehn am 04.11.2026 gemeldete Sicherheitshinweise in `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing`, `symfony/serializer` und `symfony/yaml` (u. a. CVE-2026-118514, -118515 und -118516).

## [1.82.0] – 2026-10-29

### Neu
- **Portfolio: Drei-Drittel-Strategie-Coach.** Neuer Bereich `Portfolio → Strategie`, der das Depot entlang der antizyklischen Drei-Drittel-Strategie auswertet: eine **Depot-Gesundheit** gegen die Grundregeln (höchstens 3 % je Wert = Klumpenrisiko-Warnung, mindestens fünf Branchen, rund 30 Positionen) mit Ampel-Status und Gesamtscore, den **Drei-Drittel-Kaufstatus** je Position (ein Drittel je Kauf, mit dem Trigger-Kurs für das nächste Drittel bei −10/−15 % unter dem letzten Kauf) sowie **Verkaufssignale** gegen den Durchschnittseinstand (+25 % erstes Drittel, +35 % zweites Drittel, Trailing-Stop ab +30 %, spätestens +100 %). Hinweis, keine Anlageempfehlung. Zusätzlich als API für die native App: `GET /api/v1/strategy`.

### Sicherheit
- **symfony/mime aktualisiert (CVE-2026-45070).** Patch-Update von v8.1.6 auf v8.1.10 gegen eine Header-Injection-Schwachstelle in den Mime-Parameternamen.
- **PHP 8.4 als Zielversion festgelegt.** Die Composer-Anforderung wurde von `^8.3` auf `^8.4` angehoben (die Abhängigkeiten, u. a. Symfony 8.1, setzen real PHP ≥ 8.4.1 voraus) und die Plattform auf die Server-Version 8.4.27 gepinnt, damit Entwicklung, CI und Deploy konsistent auf derselben PHP-Version auflösen.

## [1.81.2] – 2026-10-26

### Sicherheit
- **Node.js auf 24 LTS angehoben.** Build und Deploy nutzen jetzt Node 24 (Active LTS bis ~April 2028) statt des auslaufenden Node 20, das keine Sicherheitsupdates mehr erhält (u. a. die Brotli-RCE CVE-2026-111497 „Node2Shell" ist erst ab 24.22.1 gepatcht). Node dient bei uns nur zum Asset-Bauen und als PM2-Prozessmanager; die Anwendung selbst ist PHP, daher betrifft die Lücke unseren Code nicht – der Schritt hält die Werkzeugkette aber auf einer unterstützten, gepflegten Version. Server-Anleitung dazu: `docs/node-aktualisieren.md`.

## [1.81.1] – 2026-10-22

### Behoben
- **„Seite abgelaufen" (419) abgefangen.** Wenn die App nach längerer Zeit wieder geöffnet wird und die Sitzung abgelaufen ist, erschien bisher Livewires englischer Dialog „This page has expired." Die Seite lädt sich jetzt still neu und übernimmt dank „Angemeldet bleiben" wieder die Anmeldung, statt den Nutzer mit einem Hinweis zu unterbrechen.
- **Sitzung bleibt länger bestehen.** Die Standard-Sitzungsdauer wurde von 2 Stunden auf 30 Tage angehoben, damit die wie eine native App genutzte Oberfläche angemeldet bleibt und 419-Fehler kaum noch auftreten.

## [1.81.0] – 2026-10-21

### Neu
- **App-API: Seiten/Notizen bearbeiten.** Neue Endpunkte für den „Second Brain"-Bereich, damit die native App Seiten offline lesen und schreiben kann: `GET /api/v1/pages`, `POST /api/v1/pages`, `GET|PUT|DELETE /api/v1/pages/{id}`. Der Seiteninhalt ist Markdown (content.body); eine Seite mit Unterseiten wird nicht gelöscht. Haushaltsbezogen abgesichert.

## [1.80.0] – 2026-10-21

### Neu
- **App-API: Reisen bearbeiten.** Neue Schreib-Endpunkte, damit die native App unterwegs den Reiseverlauf (Stationen), die Packliste und Buchungen pflegen kann: `POST /api/v1/trips/{id}/stops` + `PUT|DELETE /api/v1/trip-stops/{id}`, `POST /api/v1/trips/{id}/packing` + `PUT|DELETE /api/v1/trip-packing/{id}` sowie `POST /api/v1/trips/{id}/bookings` + `PUT|DELETE /api/v1/trip-bookings/{id}`. Alles haushaltsbezogen abgesichert.

## [1.79.0] – 2026-10-21

### Neu
- **App-API: Reisen lesen.** Neue Endpunkte `GET /api/v1/trips` und `GET /api/v1/trips/{id}` liefern Reisen mit Reiseverlauf (Stationen), Orten, Packliste und Buchungen – damit die native App eine Reise unterwegs offline anzeigen kann. Das Bearbeiten von Reisen in der App folgt als nächste Stufe.

## [1.78.0] – 2026-10-21

### Neu
- **App-API für Offline-Bearbeitung erweitert.** Neue Endpunkte unter `/api/v1`, damit die native App weitere Bereiche offline lesen und schreiben und bei Verbindung synchronisieren kann: Sammlungen/Einträge (Notizen, Haushaltsvorrat, eigene Datenbanken) mit Eigenschaften (`GET /collections`, `GET/POST /collections/{id}/items`, `PUT/DELETE /items/{id}`) sowie Portfolio-Positionen (`POST /portfolios/{id}/positions`, `PUT/DELETE /positions/{id}`). Alles haushalts- bzw. kontobezogen abgesichert.

## [1.77.1] – 2026-10-21

### Behoben
- **Familien-Bildschirm: Uhr nicht mehr hinter der Statusleiste.** Die große Uhr saß am iPhone-Tablet zu weit oben und wurde von der Systemleiste (Uhrzeit/Akku) überlagert. Die Ansicht berücksichtigt jetzt den sicheren Bereich (Notch/Statusleiste) oben und an den Rändern.

## [1.77.0] – 2026-10-21

### Neu
- **Google-Kalender-Anbindung (Vorbereitung + Termin-Import).** Im Profil lässt sich der Google Kalender verbinden (OAuth); verbundene Google-Termine erscheinen danach als eigene Quelle „Google Kalender" im LifeOS-Kalender und werden stündlich aktualisiert. Die Anbindung bleibt verborgen, bis die Google-Zugangsdaten am Server hinterlegt sind (`GOOGLE_CALENDAR_*`). Das Zurückschreiben von LifeOS-Terminen nach Google folgt als nächster Schritt.

## [1.76.0] – 2026-10-21

### Neu
- **Familien-Bildschirm (Kiosk-Dashboard).** Neue, großflächige Vollbild-Ansicht fürs Küchen-Tablet: große Uhr & Datum, die heutige Agenda (Termine, Aufgaben, Essensplan, Fristen) und die offene Einkaufsliste – ohne Menü-Drumherum und mit automatischer Aktualisierung (alle 60 s). Erreichbar über „Familien-Bildschirm" im Menü.

## [1.75.0] – 2026-10-21

### Neu
- **Einkaufsliste aktualisiert sich live.** Die Einkaufsliste lädt sich bei geöffnetem Tab automatisch alle 30 Sekunden neu. Fügt ein Haushaltsmitglied am Handy etwas hinzu oder hakt es ab, erscheint das auf dem Küchen-Tablet oder dem zweiten Gerät ohne manuelles Neuladen.

## [1.74.0] – 2026-10-21

### Neu
- **Sammlungen: Zusammenfassung pro Zahlenspalte wählbar.** Unter einer Tabelle zeigte LifeOS bei Zahlenspalten bisher immer die Summe. Jetzt lässt sich pro Spalte in den Sammlungs-Einstellungen wählen, wie zusammengefasst wird: **Summe (Σ), Durchschnitt (Ø), Minimum (↓), Maximum (↑) oder Anzahl (#)** – Notion-artige Rollups.

## [1.73.0] – 2026-10-21

### Neu
- **Datenübersicht im Profil (Datenhoheit).** Das Profil zeigt jetzt „Deine Daten auf einen Blick": wie viele Seiten, Aufgaben, Inbox-Einträge, Datenbanken, Anhänge, Buchungen und Depots du hast, plus welche Dienste verbunden sind (paperless, Bank, Smart Home, Push, Kalender-Abos). Direkt darüber/darunter liegt der vollständige Export – transparent an einem Ort.

## [1.72.0] – 2026-10-21

### Neu
- **Vorabpauschale-Rechner im Portfolio.** Die Steuer-Seite hat jetzt einen eigenen Abschnitt für die **Vorabpauschale** thesaurierender Fonds/ETFs: Wert zu Jahresanfang und -ende, Ausschüttungen und Fondsart (Teilfreistellung 30/15/60/0 %) eingeben – der Rechner zeigt Basisertrag (Basiszins × 70 %), die Vorabpauschale (gedeckelt auf den Wertzuwachs, abzüglich Ausschüttungen) und die geschätzte Steuer (25 % + Soli, optional Kirchensteuer). Basiszins-Tabelle 2023–2026 hinterlegt.

## [1.71.0] – 2026-10-21

### Neu
- **Kategorie-Regeln auf alle Buchungen anwenden.** In den Finanzen gibt es bei den gemerkten Kategorien jetzt den Button **„Regeln auf alle Buchungen anwenden"**. Damit werden alle nicht von Hand gesetzten Buchungen anhand deiner Regeln und des Stichwort-Katalogs neu einsortiert – praktisch nach einem Import, der vor dem Anlegen einer Regel lief, oder nach dem Ändern von Regeln. Von Hand gesetzte (gesperrte) Kategorien bleiben unangetastet.

## [1.70.0] – 2026-10-21

### Neu
- **Fristen werden zu Aufgaben.** Ablaufende Dokumente/Ausweise (Notfallordner) und Fahrzeug-Termine (TÜV/Inspektion, Service) landen jetzt automatisch als **To-Do** in deiner Aufgabenliste, sobald sie in ihre Vorlaufzeit kommen – mit Fälligkeitsdatum, Zuweisung und Rücklink. Ergänzt die bestehenden Kalender-Einträge und Push-Erinnerungen; jede Frist wird genau einmal angelegt. (Verträge & Garantien aus Sammlungen erzeugen weiterhin über den Vertrags-Assistenten Aufgaben.)

## [1.69.0] – 2026-10-21

### Neu
- **Vermögensplan nutzt Portfolio-Sparpläne.** Unter der Sparrate gibt es jetzt Schnellauswahl-Chips: **„Aus Cashflow"** (Überschuss aus der Cashflow-Analyse, inkl. Mieten & Dividenden) und **„Aus Sparplänen"** (monatliche Summe deiner aktiven Portfolio-Sparpläne, wöchentlich/quartalsweise automatisch umgerechnet). So lässt sich die FIRE-Hochrechnung mit einem Tipp auf echte Zahlen stützen.

## [1.68.0] – 2026-10-20

### Behoben
- **„Anlegen" in der unteren Leiste öffnete die Suche.** Der „+"-Button (und das Tastenkürzel **N**) öffnet die Befehlsleiste jetzt im **Anlegen-Modus**: Mit sofort sichtbaren Optionen (Neue Notiz, Neue Aufgabe, Einkaufsliste, Neuer Termin) und dem Hinweis, dass man einfach lostippen kann, um etwas als Aufgabe, Einkauf oder Inbox-Eintrag zu erfassen. Im Suchmodus (Lupe / „/") bleibt alles wie gehabt.

## [1.67.0] – 2026-10-20

### Neu
- **Vermögensplan (FIRE).** Neuer Bereich unter „Geld", der dein aktuelles Nettovermögen und deine monatliche Sparrate aus der Cashflow-Analyse nimmt und in die Zukunft hochrechnet. Du siehst deine **FIRE-Zahl** (Jahresausgaben ÷ Entnahmerate, klassische 4-%-Regel), wann dein Vermögen dein Ziel erreicht (Jahr + Dauer) und wie viel **passives Einkommen** pro Monat am Ziel herauskommt. Rendite, Entnahmerate, Sparrate, Ausgaben und ein eigenes Zielvermögen sind frei einstellbar; dazu eine Verlaufskurve und eine Meilenstein-Tabelle alle 5 Jahre.

## [1.66.0] – 2026-10-20

### Neu
- **Blöcke anordnen.** Im Seiten-/Notiz-Editor lässt sich der aktuelle Block jetzt per **Alt + ↑ / ↓** nach oben oder unten verschieben (Notion-artiges Umsortieren). Bewusst ohne das offizielle Drag-&-Drop-Paket umgesetzt, da dieses den gesamten Kollaborations-/Yjs-Stack mitgezogen hätte.

## [1.65.0] – 2026-10-20

### Neu
- **Inbox-Einträge mit Seiten, Aufgaben & Co. verknüpfen.** Beim Öffnen eines Inbox-Eintrags gibt es jetzt den Bereich **„Verknüpfungen"** (wie bei Seiten und Aufgaben): den Eintrag mit Seiten, Aufgaben, Einträgen oder Rezepten verbinden und sehen, was auf ihn verweist. Damit sind Inbox und ToDos mit den Seiten durchgängig verzahnt.

## [1.64.0] – 2026-10-20

### Neu
- **Mehrspalten-Layout auf Seiten (Notion-Blöcke).** Über das „/"-Menü lassen sich **zwei oder drei Spalten** einfügen; darin kann beliebiger Inhalt (Text, Listen, Bilder, Callouts …) stehen. Auf breiten Bildschirmen stehen die Spalten nebeneinander, auf dem Handy werden sie automatisch untereinander gestapelt.

## [1.63.0] – 2026-10-20

### Neu
- **Mehr Text-Stile im Seiten-/Notiz-Editor (Notion/Craft).** Über das Auswahl-Menü lassen sich Textstellen jetzt **einfärben** (8 Farben) und schnell in **Überschrift 1/2/3** umwandeln (vorher nur H2). Die Farben werden hell- und dunkelmodus-gerecht dargestellt und bleiben beim Speichern erhalten.

## [1.62.0] – 2026-10-20

### Verbessert
- **App-Gefühl der installierten Web-App (PWA) verbessert.** Auf dem iPhone/iPad startet die zum Home-Bildschirm hinzugefügte App jetzt im **Vollbild-App-Modus** (ohne Browser-Leiste) mit passender Statusleiste und App-Titel. Das App-Icon bietet beim langen Drücken **Schnellaktionen** (Heute, Aufgaben, Neue Notiz, Dokument scannen). Zusätzlich saubere App-Kennung, Kategorien und Behebung der Manifest-Warnung beim Teilen.

## [1.61.0] – 2026-10-20

### Neu
- **Notizen komfortabler bearbeiten (wie Craft).** Beim Bearbeiten einer Notiz gibt es jetzt ein eigenes Titelfeld und denselben Rich-Editor wie bei den Seiten: Formatierung (fett/kursiv/durchgestrichen/markiert/Code/Link), per **„/"** Überschriften, Listen, Checklisten, Tabellen, Callouts und Umschalt-Abschnitte sowie **[[Verlinkungen]]** zu anderen Seiten – statt wie bisher nur ein einfaches Textfeld.

## [1.60.1] – 2026-10-19

### Behoben
- **Echtzeit/Live-Updates unter Unterpfad repariert.** Läuft die App unter einem Pfad (z. B. „/E11LifeOS-staging"), ging die Kanal-Autorisierung fälschlich an „/broadcasting/auth" auf der Domain-Wurzel → 404. Sie nutzt jetzt die App-Basis-URL. Außerdem verursachte der Reconnect-Handler einen unbehandelten JavaScript-Fehler („$refresh is not a function"), der die Editor-Bedienung (u. a. „Liste/Datenbank einfügen") stören konnte – jetzt abgesichert.

## [1.60.0] – 2026-10-19

### Neu
- **Datenbanken direkt auf Seiten anlegen (wie in Notion).** Auf einer Seite gibt es jetzt den Befehl **„/Datenbank"** (und im Editor „📋 Liste einfügen" → Gruppe „Neue Datenbank"): damit legst du mitten im Text eine neue Datenbank an – leer oder aus einer Vorlage (Projekte, Haushaltsbuch, Verträge, Inventar, Lesezeichen, Tagebuch, Gewohnheiten, Leseliste). Sie wird als **lebendiger Block** in die Seite eingebettet und immer aktuell angezeigt. Vorher ging das nur mit einer schon vorhandenen Sammlung.
- **Eingebettete Datenbanken als Tabelle.** Eine eingebettete Sammlung mit Eigenschaften wird auf der Seite jetzt als **kompakte Tabelle** mit Spalten (Status, Datum, Person, Betrag …) angezeigt statt nur als Titelliste – jeder Eintrag verlinkt in die volle Datenbank zum Bearbeiten.

## [1.59.1] – 2026-10-19

### Behoben
- **Dokumente-Archiv: Dokument öffnen ging nicht.** Beim Tippen auf ein Dokument lud die App zwar, blieb auf dem Handy aber bei der Übersicht stehen – die Detailansicht öffnete sich weit unterhalb außerhalb des Bildschirms. Das Dokument öffnet sich jetzt als eigenständige Ansicht über der Liste (am Rechner weiterhin in der Spalte daneben) und springt sichtbar auf. Zusätzlich wird das Dokument nur noch einmal geladen statt bei jeder Interaktion erneut – das macht das Öffnen über VPN deutlich schneller.

## [1.59.0] – 2026-10-19

### Neu
- **Dokumente-Archiv: paperless voll im Tool verwalten.** Neuer Bereich **„Dokumente"** (unter Vorsorge & Dokumente): alle paperless-Dokumente durchsuchen (Volltext), nach **Tag, Korrespondent, Dokumenttyp und Datum filtern**, sortieren und blättern. In der Detailansicht: Vorschau öffnen, **Metadaten bearbeiten** (Titel, Tags, Korrespondent, Dokumenttyp, Datum), **Notizen** hinzufügen, herunterladen und **löschen** (mit Sicherheitsabfrage). Die Dokumente bleiben in paperless – das Tool liest/steuert nur über die API (VPN-fähig).

## [1.58.1] – 2026-10-18

### Neu
- **Scan: paperless-Tags, Korrespondent & Dokumenttyp direkt vergeben.** Beim Dokument-Scan lassen sich jetzt vorhandene **Tags** (Mehrfachauswahl), ein **Korrespondent** und ein **Dokumenttyp** aus paperless auswählen – sie werden direkt beim Upload mitgegeben. Die Auswahl wird auch in der Offline-Queue gespeichert, sodass sie beim späteren Nachladen erhalten bleibt. Der Posteingang-Tag wird wie bisher automatisch ergänzt.

## [1.58.0] – 2026-10-18

### Neu
- **Dokumente scannen (mehrseitig) → paperless.** Neue Seite „Dokument scannen" (📷 in der Inbox): mit der Handy-Kamera mehrere Seiten nacheinander aufnehmen, automatische Optimierung (Dokumentmodus: graustufig, mehr Kontrast), Seiten sortieren/löschen, alles zu **einer PDF** zusammenfügen und an paperless senden. Alternativ aus Fotos/Dateien.
- **Offline-Sicherung für Scans.** Ist paperless gerade nicht erreichbar (z. B. VPN/Server down), wird die PDF **zwischengespeichert** und automatisch hochgeladen, sobald die Verbindung wieder steht (Scheduler alle 5 Minuten). Ein Scan geht so nie verloren; wartende Scans werden angezeigt.

### Geändert
- **Inbox: Paperless-Bereich einklappbar** und standardmäßig **zugeklappt**, damit die eigentlichen Inbox-Einträge nicht untergehen. Der Zustand wird pro Gerät gemerkt.

## [1.57.1] – 2026-10-12

### Neu
- **Tabellenzeilen/-spalten einfügen und löschen**: Steht der Cursor in einer Tabelle, zeigt die Seiten-Bearbeitung jetzt eine kleine Werkzeugleiste (+/− Zeile, +/− Spalte, Tabelle löschen).
- **Spaltenbreite auch per Fingertipp ändern** (↔ − / ↔ +) – als Alternative zum Ziehen am Spaltenrand, das sich auf dem Handy kaum treffen lässt.

### Behoben
- **Ein Codeblock mit HTML-ähnlichem Text (z. B. `<div>…</div>` als Beispielcode) sperrte die ganze Seite dauerhaft auf den rohen Markdown-Modus** – ohne „/“-Menü, ohne Tabellen, ohne Formatierung. Die Prüfung schaut jetzt nicht mehr in Codeblöcke/Inline-Code hinein, da deren spitze Klammern dort nur Text sind, kein echtes HTML.

## [1.57.0] – 2026-10-12

### Neu
- **Tabellenspalten in Seiten lassen sich jetzt per Ziehen verbreitern/verschmälern** – wie bei Notion. Die Breite wird beim Speichern gemerkt und beim nächsten Öffnen der Seite wieder hergestellt.
- **Sammlungen in Seiten einbetten** („Linked Database“): über „📋 Liste einfügen“ lässt sich jede eigene Sammlung – optional mit einer ihrer gespeicherten Ansichten (Filter/Sortierung) – live in eine Seite einfügen. Die Einträge sind direkt verlinkt, ein nicht mehr sichtbares oder gelöschtes Sammlung-Embed zeigt einen Hinweis statt eines Fehlers.
- **Einträge einer Sammlung in eine Aufgabe umwandeln**: Button „✅ Task“ in der Detailansicht eines Eintrags verschiebt ihn in die Aufgabenliste, Titel und Notiz bleiben erhalten.
- **Ganze Seiten in eine Aufgabe oder auf die Einkaufsliste übernehmen**: neue Buttons im Seitenkopf. Seiten mit Unterseiten lassen sich (noch) nicht in eine Aufgabe umwandeln, um Unterseiten nicht verwaist zurückzulassen.
- **Checklisten-Zeilen in Seiten auf die Einkaufsliste übernehmen**: zweiter Button neben dem bestehenden „→ Aufgabe“-Button an jeder Checklisten-Zeile.

## [1.56.0] – 2026-10-11

### Neu
- **Wochenplan-Einträge bearbeiten.** Ein Stift-Button neben dem Löschen-Button öffnet den bekannten Dialog vorausgefüllt – Tippfehler bei Freitext-Einträgen oder die Portionsgröße lassen sich jetzt korrigieren, ohne den Eintrag neu anzulegen.

### Sicherheit
- **Zwei-Faktor-Pflicht gilt jetzt auch für die Mobile-App-Anmeldung** (vorher nur im Browser erzwungen).
- **Bank-Zugangsadresse (FinTS) wird geprüft:** nur noch öffentlich erreichbare Server erlaubt, wie schon bei Paperless und Netzlaufwerken.
- Die Bank-PIN bleibt nach dem Speichern/Abbrechen nicht mehr im Formular stehen.
- Der Zugangs-Token für Siri-Kurzbefehle landet nicht mehr in Fehlerberichten.
- CSV-Exporte (Steuer, EÜR, Kostenaufteilung) sind jetzt gegen präparierte Bank-Buchungen abgesichert, die beim Öffnen in Excel Formeln ausführen könnten.
- Geteilte paperless-ngx-Dokumente: nur noch tatsächlich verlinkte Dokumente abrufbar.
- Smart-Home-Webhook-Adressen werden jetzt wie Freigabelinks gehasht statt im Klartext gespeichert.
- Login, Registrierung und Reise-Ordner-Passwörter sperren jetzt zusätzlich nach wiederholten Fehlversuchen.
- `.env.example` setzt produktionstaugliche Standardwerte (Debug aus, 2FA-Pflicht an).

### Behoben
- **Monatlich wiederkehrende Aufgaben** (z. B. „zum 31.“) behalten bei pünktlichem Abhaken dauerhaft ihren Tag, statt über kurze Monate langsam auf einen früheren Tag abzudriften; manuelles Verschieben per Drag & Drop übernimmt den neuen Tag korrekt.
- Gleichzeitiges Abhaken desselben Einkaufslisten-Artikels durch zwei Haushaltsmitglieder führte zu einem Serverfehler.
- Ein Fehler beim Bank-Depot-Abgleich konnte die gespeicherten Positionen unwiederbringlich löschen.
- Echte Bank-Duplikate (gleicher Tag/Betrag/Empfänger, z. B. zwei Abhebungen) wurden beim Import fälschlich als „bereits vorhanden“ übersprungen.
- Live-Updates (Reverb) synchronisieren nach einer kurzen Verbindungsunterbrechung jetzt wieder korrekt, statt veraltete Daten stehen zu lassen.
- Benachrichtigungen (Erinnerungen, Wochenrückblick) laufen jetzt über die Warteschlange mit Wiederholung – ein einzelner Fehler (z. B. ein E-Mail-Timeout) lässt nicht mehr alle folgenden Mitglieder leer ausgehen.

### Geändert
- Die interne Systemprüfung (Admin → System) erkennt jetzt auch eine gestörte Datenbank- oder Live-Verbindung.

### Neu
- **Siri: Notizen als Ziel + ein Kurzbefehl für alles.** Die Schnellerfassung per Token-Adresse kennt jetzt auch **Notizen** (`…/quick/note`) – die erste Zeile wird zum Titel, der Rest zum Text, abgelegt im Notizen-Bereich. Zusätzlich gibt es die Adresse **`…/quick/auto`**: Beginnt das Diktat mit einem Schlüsselwort – „Aufgabe …“, „Notiz …“, „Inbox …“ (sonst Einkaufsliste) – wird das passende Ziel automatisch gewählt. So reicht **ein einziger Kurzbefehl** statt vier. In Profil → Mobile App ist „Automatisch (per Schlüsselwort)“ als neues Standard-Ziel wählbar.
- Mehrere Artikel in einem Diktat (z. B. „2 Liter Milch, Butter und 6 Eier“) werden – wie bei der Einkaufsliste schon bisher – als **einzelne Einträge** erkannt; das gilt jetzt auch über `…/quick/auto`.

## [1.55.2] – 2026-10-11

### Behoben
- **Profilseite lud nicht mehr**: In der Siri-Karte (Profil → Mobile App) fehlte das schließende `>` des umschließenden `<div>`-Tags, wodurch das HTML/Alpine-Markup brach und die Seite hängen blieb. Tag wieder korrekt geschlossen; der Token wird weiterhin per `$wire.entangle` live übernommen und in der Web-Adresse URL-codiert.

## [1.55.1] – 2026-10-10

### Behoben
- **Siri-Kurzbefehl funktioniert jetzt zuverlässig**: Die bisherige Anleitung mit JSON-Body + „Authorization"-Header war fehleranfällig (es landete nichts in der Liste). Neu gibt es einen schlanken Endpunkt, bei dem **Token und Text in der Web-Adresse** stehen (`…/api/v1/quick/shopping?token=…&text=…`). Der Kurzbefehl braucht damit nur **eine Aktion** („Inhalte von URL abrufen", GET) ohne Header/Body. In Profil → Mobile App wird die fertige Adresse (mit eingetragenem Token) je Ziel – Einkaufsliste, Aufgabe, Inbox – zum Kopieren angezeigt.

## [1.55.0] – 2026-10-10

### Neu
- **Notizen-Bereich** (Menü → Notizen): Schnelle Notizen, aufgebaut wie Seiten – einfach lostippen, die erste Zeile wird zur Überschrift. Notizen liegen in einer eigenen „Notizen"-Ablage und tauchen nicht zwischen den Seiten auf, bis du sie per **„In Seiten einordnen"** in den Seitenbaum übernimmst (dann sind sie eine normale Seite mit allen Blöcken). Bearbeiten und Löschen direkt in der Liste.

## [1.54.2] – 2026-10-10

### Geändert
- **Siri-Kurzbefehle überarbeitet** (Profil → Mobile App): Der herunterladbare Kurzbefehl funktionierte auf iPhones nicht („kann nicht ausgeführt werden"), weil Apple seit iOS 15 Kurzbefehle signiert und selbst erzeugte Dateien nicht zuverlässig importiert werden. Der Download wurde daher entfernt und durch eine klare Ein-Minuten-Anleitung mit **vorausgefülltem Zugangs-Token** ersetzt. Neu: Du kannst das **Ziel wählen** – Einkaufsliste, Aufgabe oder Inbox (die Inbox ordnet die Notiz automatisch ein). Die passende API-URL wird je Ziel angezeigt.

## [1.54.1] – 2026-10-10

### Neu
- **Fertiger Siri-Kurzbefehl zum Herunterladen** (Profil → Mobile App): Statt der manuellen Anleitung gibt es jetzt einen Button, der einen **fertigen iOS-Kurzbefehl** (mit bereits eingetragenem Zugangs-Token) herunterlädt – öffnen, „Kurzbefehl hinzufügen" tippen, fertig. Die manuelle Einrichtung bleibt als einklappbarer Fallback erhalten.

### Behoben
- Rezepte: Downloads (Kochbuch-PDF, ZIP-Export, Rezept-PDF/JSON/Markdown, Wochenplan-PDF) wurden in der installierten Web-App nur angezeigt, aber nicht gespeichert. Sie öffnen jetzt zum Herunterladen (neuer Tab / `download`), sodass die Datei auch in der Web-App sicher gespeichert werden kann.

## [1.54.0] – 2026-10-10

### Neu
- **Diagramme & Vermieten-vs-selbst-Vergleich im Immobilien-Rechner** (Immobilien → Wirtschaftlichkeit): Die Prognose wird jetzt zusätzlich grafisch dargestellt – ein **Vermögensaufbau-Chart** (Immobilienwert und Eigenkapital im Objekt über die Jahre, die Lücke ist die Restschuld) und ein **Miete-&-Cashflow-Chart** (Jahresmiete gegen Cashflow nach Steuern). Dazu ein **„Vermieten vs. selbst nutzen"-Vergleich**: Vermieten (Miete, AfA, Steuer) gegen Selbstnutzen (gesparte Kaltmiete steuerfrei, Verkauf ohne Spekulationssteuer) mit Gesamtergebnis und Hinweis, welche Variante vorn liegt.

## [1.53.1] – 2026-10-10

### Behoben
- Bank verbinden (PSD2/GoCardless): Beim Öffnen von „Bank verbinden" kam es zu einem Server-Fehler (500), wenn die GoCardless-Zugangsdaten fehlten oder ungültig waren (die Bank-Schnittstelle antwortete mit 401). Statt eines Absturzes erscheint jetzt ein verständlicher Hinweis, die Secret-ID/den Key in den Servereinstellungen zu prüfen.

## [1.53.0] – 2026-10-10

### Neu
- **Finanzierungs-Controlling für Immobilien** (Objekt → Controlling): Neuer Reiter, der die Finanzierung begleitet. KPI-Kacheln zeigen Gesamtkosten, geplante Finanzierung (inkl. Hinweis auf eine Deckungslücke), bereits Abgerufenes und die Renovierung bisher. **Eigenkapital-Einsätze und Darlehensabrufe** lassen sich je Darlehen erfassen – geplant/„beantragt" gegen das, was die Bank tatsächlich ausgezahlt hat, mit Fortschrittsbalken pro Abruf. Dazu eine **Gewerke-Auslastung** (geplant vs. in Rechnung gestellt vs. bezahlt je Gewerk) und die **laufenden Kosten pro Monat** als Balkengrafik.

## [1.52.3] – 2026-10-10

### Neu
- **Per Siri zur Einkaufsliste hinzufügen** (Profil → Mobile App): Anleitung und ein per Knopfdruck erzeugter Zugangs-Token für einen iOS-Kurzbefehl, der Diktiertes an die Einkaufsliste schickt – auch freihändig über „Hey Siri". Mehrere Artikel auf einmal funktionieren ebenfalls, z. B. „2 Liter Milch, Butter und 6 Eier"; eine führende Menge je Artikel wird erkannt und doppelte Einträge werden wie beim manuellen Hinzufügen zusammengefasst. Neuer API-Endpunkt `POST /api/v1/shopping/quick`.

## [1.52.2] – 2026-10-10

### Behoben
- Einkaufsliste: Der Barcode-Scanner zeigte auf dem iPhone nur ein schwarzes Bild. Die Kamera wird jetzt erst gestartet, nachdem das Scanner-Fenster sichtbar ist (iOS zeigte sonst ein eingefrorenes Standbild). Zusätzlich `autoplay`/`webkit-playsinline` gesetzt.

## [1.52.1] – 2026-10-10

### Behoben
- Rezepte-Import (und Portfolio-Altdaten-Import) aus der Admin-Seite schlug mit „Access denied … (using password: NO)" fehl, obwohl die Zugangsdaten unter Einstellungen hinterlegt waren. Ursache: Der Hintergrund-Job eines langlaufenden Queue-Workers nutzte die Konfiguration vom Start-Zeitpunkt. Der Job wendet die gespeicherten Einstellungen jetzt vor jedem Lauf frisch an und baut die Alt-Datenbankverbindungen neu auf.

## [1.52.0] – 2026-10-06

### Neu
- **Bank verbinden ohne FinTS** (Portfolio → Banking): Konten lassen sich jetzt zusätzlich über die offizielle **PSD2-Schnittstelle** (GoCardless Bank Account Data) verbinden – auch für Banken, die FinTS abgeschaltet haben. Bank suchen, einmal bei der Bank bestätigen, danach landen **Salden und Umsätze automatisch in den Finanzen** (täglicher Abruf; manuell per „Jetzt abrufen"). Die 90-Tage-Zustimmung wird überwacht; läuft sie ab, kommt eine Erinnerung zum Neu-Verbinden.
- Hinweis: PSD2 deckt **Zahlungskonten** ab (Giro/Karte), **keine Wertpapierdepots** – Depots laufen weiter über FinTS. Die Funktion erscheint nur, wenn die GoCardless-Zugangsdaten als Umgebungs-Secrets hinterlegt sind.

## [1.51.0] – 2026-10-06

### Neu
- **Meilenstein-Planung mit ausführlichen Texten und Bildern** (Administration → Roadmap): Jeder Meilenstein hat jetzt neben der Kurzbeschreibung ein Feld für eine **ausführliche Erklärung** (Hintergrund, Anforderungen, Akzeptanzkriterien, offene Fragen) und kann **Bilder** (Mockups, Screenshots, Skizzen) mit Bildunterschrift bekommen. Beides erscheint auf der Roadmap-Seite und wird **beim Export für Claude mitgenommen**: Hat ein Meilenstein Bilder, ist der Download ein **ZIP**, das das Markdown (inkl. ausführlicher Texte und Bildverweise) zusammen mit den Bildern bündelt.

## [1.50.0] – 2026-10-06

### Neu
- **Gewinn-Alarme per Knopfdruck**: Beim Bearbeiten einer Portfolio-Position richtet ein Button auf einen Schlag Benachrichtigungen bei **+5 %, +10 %, +20 % und +30 %** über deinem durchschnittlichen Kaufpreis ein. Die Zielkurse werden automatisch berechnet und angezeigt; wird ein Kurs erreicht, kommt eine Push-Benachrichtigung (und per Pushover, falls eingerichtet). Bereits eingerichtete Stufen bleiben erhalten, ein zweiter Klick legt nichts doppelt an. Die Alarme erscheinen mit „📈 +X %"-Kennzeichnung unter Portfolio → Alarme.

## [1.49.2] – 2026-10-06

### Behoben
- Rezept-Detailseite: Das „⋯"-Menü öffnete am Smartphone an einer ungünstigen Stelle (zu weit am Rand). Die Aktionsleiste ist auf kleinen Displays jetzt rechtsbündig, der „⋯"-Button sitzt rechts und das Menü klappt vollständig im sichtbaren Bereich auf.

## [1.49.1] – 2026-10-06

### Behoben
- Rezept-Detailseite: Das „⋯"-Menü wurde am Smartphone von der abgerundeten Karte abgeschnitten und war nicht sichtbar. Das Menü klappt jetzt vollständig auf.

## [1.49.0] – 2026-10-06

### Neu
- Rezepte **mehrfach auswählen**: In der Rezeptliste (Karten, Liste, Tabelle) lassen sich mehrere Rezepte antippen; eine schwebende Leiste macht daraus gezielt ein **Kochbuch (PDF)** oder einen **ZIP-Export** – genau die ausgewählten, unabhängig vom Filter.
- **Wochenplan als PDF**: Der Essensplan einer Woche lässt sich als übersichtliches PDF herunterladen (Wochenplan → „Woche als PDF").

## [1.48.0] – 2026-10-06

### Neu
- Rezepte aus Dateien importieren – ohne KI: schema.org-Rezept-JSON (eigener Export, Mealie u. v. a.), eine Liste mehrerer Rezepte oder ein **Paprika-Archiv** (`.paprikarecipes`). Eine Datei öffnet das Rezept zur Kontrolle, mehrere werden auf einmal importiert (Rezepte → Import → „Rezept-Datei").
- Sammel-Export: Alle Rezepte des aktuellen Filters als **ZIP** (ein schema.org-JSON je Rezept inkl. Foto) – ideal für Backup oder Umzug in eine andere App (Rezepte-Liste → „Als ZIP exportieren").

## [1.47.0] – 2026-10-05

### Neu
- Rezepte teilen & mitnehmen: Jedes Rezept lässt sich als **Rezept-Datei (JSON im schema.org-Standard)** oder als **Text (Markdown)** exportieren (Rezept → ⋯ → Exportieren) – so wandert es in andere Koch-Apps oder in eine Nachricht.
- Geteilte Rezeptseiten enthalten jetzt strukturierte schema.org-Daten (JSON-LD). Dadurch erkennt Google das Rezept und andere Rezept-Apps können es direkt importieren.

### Verbessert
- Geteilte Rezeptseite neu gestaltet (einfacher bedienbar beim Mitkochen): Zutaten und Schritte lassen sich per Tippen abhaken (bleibt auf dem Gerät gespeichert), eine schwebende Kopfleiste mit „Drucken", sauberes Druck-Layout, größere Tippflächen mit sofortigem Feedback und bessere Lesbarkeit.
- Rezept-Menü (⋯) aufgeräumt: Export-Aktionen gruppiert, destruktive Aktionen abgesetzt, mit ruhiger Einblend-Animation.

## [1.46.0] – 2026-10-05

### Neu
- Datenschutz: KI-Anbieter wählbar (Admin → Server-Einstellungen → KI). „Anthropic API (USA)" wie bisher oder „AWS Bedrock" – damit läuft Claude in einer AWS-Region deiner Wahl (z. B. Frankfurt), sodass die Daten in der EU bleiben. Umschaltbar ohne Code-Änderung; AWS-Zugangsdaten nur nötig, wenn der Server keine eigenen hat.

## [1.45.0] – 2026-10-05

### Verbessert
- Immobilien-Wirtschaftlichkeit professioneller bewertet: Das Urteil richtet sich jetzt nach der Gesamtrendite (IRR inkl. Tilgung, Steuer, Wertzuwachs) statt allein nach dem Cashflow. Ein negativer Cashflow wird als Zuzahlung erklärt, deren Tilgungsanteil ins eigene Vermögen fließt.
- Neue Kennzahlen: operativer Cashflow (deckt die Miete Kosten und Zinsen?), Rendite pro Jahr (IRR) und der Tilgungsanteil der monatlichen Rate.

## [1.44.0] – 2026-10-05

### Neu
- Immobilien-Wirtschaftlichkeit als Komplettbild: Verkaufsszenario am Ende der Haltedauer (Verkaufspreis, Verkaufskosten, Restschuld, Spekulationssteuer bei Verkauf innerhalb von zehn Jahren, Netto-Verkaufserlös), Gesamtrendite inkl. interner Zinsfuß (IRR) und Vergleich mit einer Alternativanlage.
- Förderungen fließen in die Kalkulation ein: einmaliger Zuschuss senkt den Kapitalbedarf, ein günstiger Förderkredit (z. B. KfW) wird mit dem Bankdarlehen zu einem Mischzins verrechnet.
- Detaillierte Renovierungskalkulation: Einzelposten (Dach, Heizung, Bad …) werden zur Renovierungssumme addiert und mit der Berechnung gespeichert.
- Kauf-Checkliste „Vor dem Kauf: worauf achten“ (Lage, Gebäude, Unterlagen, Geld, Vertrag) direkt in der Wirtschaftlichkeitsprüfung.

## [1.43.0] – 2026-10-05

### Neu
- Support: Anfragen lassen sich direkt per E-Mail beantworten – die Antwort auf die Benachrichtigungs-Mail landet automatisch im Ticket (nur von der eigenen Adresse der anfragenden Person, ohne zitierten Verlauf, mit Anhängen nach Virenprüfung).
- Textbausteine für das Support-Team: wiederkehrende Antworten mit Platzhaltern ({name}, {number}, {subject}, {agent}) und optionalem Folgestatus, im Ticket per Auswahl einfügbar.
- Admin-Übersicht: Konten und Aktivität (7/30 Tage), Speicher pro Haushalt, Support-Warteschlange und Fehlertrend der letzten 14 Tage auf einen Blick.

## [1.42.0] – 2026-10-05

### Neu
- „Module & Abo“ (Benutzermenü): zeigt für jeden lizenzpflichtigen Bereich den Status (Test, Lizenz, freigegeben bis, gesperrt) und den Preis. Inhaber/innen starten einen kostenlosen Testzeitraum einmal pro Haushalt; alle anderen fragen eine Lizenz an – die Anfrage landet als Ticket im Support.
- Admin → Module: Testtage und Preistext je lizenzpflichtigem Modul einstellbar.

## [1.41.0] – 2026-10-05

### Verbessert
- Rechteverwaltung lückenlos: Gesperrte oder nicht lizenzierte Bereiche tauchen auch nicht mehr in der Suche, im Kalender, in den Kacheln der Übersicht und in der App (API) auf.

## [1.40.1] – 2026-10-05

### Behoben
- „Log-Dateien: Für die App nicht beschreibbar: laravel.log“: Web-App und Hintergrundprozesse (Scheduler, Queue) laufen auf dem Server unter verschiedenen Benutzern und sperrten sich gegenseitig aus derselben Datei aus. Hintergrundprozesse schreiben jetzt in eine eigene `laravel-cli.log`; die Systemprüfung bewertet nur die eigene Datei und verschiebt eine gesperrte sofort zur Seite.

## [1.40.0] – 2026-10-05

### Neu
- Roadmap: Punkte (Aufgaben, Feature-Wünsche, Bugs) direkt in einem Meilenstein oder im Backlog anlegen – zusätzlich zu den Bugs und Wünschen aus dem Support.
- Export für Claude: die ganze Roadmap oder ein einzelner Meilenstein als Markdown zum Kopieren oder als .md-Datei – mit Beschreibungen, technischem Kontext (Seite, Version, Browser) und internen Notizen, nach Priorität sortiert. Namen, E-Mail-Adressen und Links mit Tokens der Anfragenden sind nie enthalten.

### Behoben
- Fehlermeldung „Scheduled command [] failed with exit code [1]“: Der minütliche Scheduler-Herzschlag galt als fehlgeschlagen, wenn der Datenbank-Cache beim Schreiben keine Zeile änderte. Geplante Aufgaben werten Rückgabewerte nicht mehr als Fehler.

## [1.39.0] – 2026-10-05

### Neu
- Listen einfügen: In Seiten und Aufgaben-Notizen fügt „/“ → „Liste einfügen“ (oder der Link „📋 Liste einfügen“ über dem Editor) Packlisten, Checklisten, eigene Vorlagen und Einkaufslisten als abhakbare Checkliste ein – auch ohne Reise. Einkaufslisten lassen sich zusätzlich live einbetten und zeigen dann immer die aktuellen offenen Einträge.
- Aufgaben: „Liste als Unteraufgaben einfügen“ übernimmt eine Pack-, Check- oder Einkaufsliste als Unteraufgaben (ohne Doppelte).

### Behoben
- Administration auf dem Handy: Das Bereichsmenü rutschte nach oben weg und ließ sich nicht bedienen. Auswahlblätter öffnen jetzt immer unten am Bildschirm.

## [1.38.0] – 2026-10-05

### Neu
- Hilfe & Support: Über „Hilfe & Support“ in der Seitenleiste schreiben Mitglieder Anfragen (Frage, Problem, Bug, Feature-Wunsch, Konto) mit Bereich, Dringlichkeit und bis zu drei Screenshots. Sie sehen den Verlauf, antworten, markieren als gelöst und bewerten die Hilfe; neue Antworten kommen per Push, Glocke und E-Mail.
- Administration › Support: Ticketverwaltung wie in einem Helpdesk – Ansichten (alle ungelösten, nicht zugewiesen, meine, neu, überfällig, wartend, Bugs, Feature-Wünsche, gelöst), Suche nach Betreff, #Nummer, Name oder E-Mail, Prioritätsfilter und Kennzahlen (offene Tickets, Ø erste Antwort, Zufriedenheit).
- Tickets bearbeiten: öffentliche Antworten und interne Notizen, „Senden als“ Status, Zuweisung, Priorität, Typ, Bereich, Tags und Meilenstein – jede Änderung steht im Verlauf. Zielzeiten für die erste Antwort je Priorität (4 h bis 72 h) mit Überfällig-Hinweis; gelöste Tickets schließen sich nach 7 Tagen.
- Administration › Roadmap: Meilensteine mit Version, Zieldatum, Status und Fortschritt; Bugs und Feature-Wünsche aus dem Support werden eingeplant, alles andere steht im Backlog. Interne Tickets für Ideen aus dem Team.
- Datenschutz: Anhänge sehen nur die anfragende Person und Administratoren; wird ein Konto gelöscht, gehen seine Anfragen samt Dateien mit.

## [1.37.1] – 2026-10-05

### Behoben
- Notizen in Aufgaben und Sammlungseinträgen: Beim Tippen im Editor (z. B. „/“ für eine Checkliste) erschien nach kurzer Pause „500 Server Error“. Das automatische Speichern ist nur noch auf Seiten aktiv, wo es hingehört; Einträge speichern weiter mit „Speichern“.

## [1.37.0] – 2026-10-05

### Neu
- Administration › Nutzer: alle Konten mit Haushalten und Rolle, letzter Anmeldung, zweitem Faktor und Registrierungsdatum, mit Suche. Eigener Reiter „Administratoren“; Administratorrechte vergeben oder entziehen nur nach erneuter Passworteingabe (nie für sich selbst).
- Rechteverwaltung: Jeder Bereich (Reisen, Gesundheit, Finanzen, Smart Home …) lässt sich pro Haushalt und pro Konto freigeben oder sperren, auf Wunsch bis zu einem Datum. Gesperrte Bereiche verschwinden aus dem Menü und sind nicht mehr aufrufbar; die Daten bleiben erhalten. Sperren gehen immer vor.
- Administration › Module: pro Bereich „Inklusive“ oder „Lizenzpflichtig“, mit Übersicht aller aktiven Freigaben, Sperren und Lizenzen. Das Datenmodell ist für ein späteres Lizenzsystem vorbereitet (Herkunft Administrator/Lizenz/Testzeitraum, Gültigkeitszeitraum, Referenz).
- Haushaltsseite: Vom Administrator gesperrte oder lizenzpflichtige Zusatzmodule sind gekennzeichnet.

## [1.36.2] – 2026-10-05

### Verbessert
- Untermenüs überall einheitlich: Finanzen, Karriere, Reisen, Notfallordner, Haus, Journal, Wissen, Lernen, Vorsorge, Kostenteilung, Fahrzeuge, Arbeit, Vermietung, Gesundheit, Smart Home, Business, Rezept-Import und Administration nutzen dieselbe Navigation – bis vier Bereiche als Segmentschalter, mehr als Blatt auf dem Handy bzw. gruppierte Leiste auf größeren Bildschirmen.
- Lange Bereichsnamen werden auf dem Handy sauber umbrochen statt abgeschnitten.

## [1.36.1] – 2026-10-05

### Verbessert
- Untermenüs (Portfolio, Business, Immobilien): Auf dem Handy öffnet der aktuelle Bereich ein Blatt mit allen Bereichen in Gruppen – kein seitliches Scrollen mehr. Auf größeren Bildschirmen sind alle Bereiche auf einen Blick sichtbar und nach Gruppen getrennt.
- Seitlich scrollende Leisten bleiben in sich, zeigen keinen Scrollbalken mehr und blenden an den Rändern aus.

### Behoben
- In der installierten App lud ein schräges Wischen am Seitenanfang (z. B. im Untermenü) die Seite neu: Nur ein klar senkrechtes Herunterziehen außerhalb seitlich scrollender Bereiche löst noch das Neuladen aus.

## [1.36.0] – 2026-10-05

### Neu
- Administration als eigener Bereich mit Reitern: System (Systemzustand, Status, Fehler, Hintergrundaufgaben), Haushalte, Servereinstellungen und Admin-Protokoll.
- Haushalte verwalten: neue Haushalte anlegen (mit bestehendem Konto als Inhaber/in oder per Einladungslink), umbenennen, Mitglieder hinzufügen, Rollen ändern und entfernen, Registrierungslinks erstellen.
- Datenschutzkonform: Admins sehen nur Namen, Mitglieder und Mengen – nie Inhalte. Exporte enthalten nur die gemeinsamen Daten (keine privaten Einträge, keine Zugangsdaten), sind mit AES-256 und eigenem Passwort verschlüsselt, und die Inhaber/innen bekommen einen Hinweis.
- Haushalt löschen: mit Namensbestätigung und erneuter Passworteingabe, inklusive aller Dateien; auf Wunsch auch die Konten von Mitgliedern, die danach zu keinem Haushalt mehr gehören.
- Admin-Protokoll: jede Aktion von Administrator:innen mit Zeit, Gerät und IP-Adresse.

### Verbessert
- Wer sich als Erste/r mit einem Einladungslink eines von der Administration angelegten Haushalts registriert, wird Inhaber/in.

## [1.35.2] – 2026-10-05

### Behoben
- Live-Verbindung zu Home Assistant: Eine halb geschlossene Verbindung kann den Prozess nicht mehr in eine Dauerschleife mit voller CPU-Last bringen – sie wird erkannt und neu aufgebaut, mit wachsender Wartezeit zwischen den Versuchen.

## [1.35.1] – 2026-10-05

### Behoben
- Eine Log-Datei, die von einem anderen Benutzer neu angelegt wurde (z. B. durch die Log-Rotation), ließ geplante Aufgaben abbrechen: Schreibfehler im Log brechen nichts mehr ab, der Wächter und der Deploy legen eine nicht beschreibbare Log-Datei beiseite, und der Systemzustand zeigt das Problem an.
- Lehnt Apify den Schlüssel ab (401/402/403), erscheint kein Fehler mehr alle sechs Stunden – Admins bekommen höchstens einmal am Tag den Hinweis, Schlüssel und Kontingent zu prüfen.

## [1.35.0] – 2026-10-05

### Neu
- Automationen per KI-Entwurf: einfach beschreiben („Wenn der Letzte geht, frag, ob alle Lichter ausgehen sollen“) – die KI füllt den Editor aus, du prüfst, testest und speicherst. Lässt sich unter Admin › KI abschalten.
- Neue Auslöser: Wetter heute (Regen, Höchst-/Tiefsttemperatur), Müllabfuhr morgen, Mail an die Haushaltsadresse (z. B. Paketankündigung), Fristen aus Sammlungen (z. B. Kündigungsfrist), Geburtstage, Mindesthaltbarkeit im Vorrat, Kontostand und „Gerät bleibt in einem Zustand“ (z. B. Tür 10 Minuten offen).
- Neue Aktionen: Einkaufsliste, Kalendereintrag, E-Mail an dich oder den Haushalt, Sprachausgabe auf einem Lautsprecher (TTS), Szenen-Schnappschuss (Lichter merken und wiederherstellen) und Webhook.
- ODER-Bedingungen: „Eines davon“ fasst mehrere Bedingungen zusammen.
- Platzhalter in Texten: {reason}, {automation}, {time}, {date}, {weekday}, {weather} und {state:sensor.…}.
- Probelauf im Editor: zeigt, was jetzt passieren würde – ohne etwas zu schalten oder zu senden.
- Verlauf aller Ausführungen mit Grund und geprüften Bedingungen.
- Pakete mit einem Klick: Urlaub, Morgen, Gute Nacht, Gäste, Energie sparen und Sicherheit; Automationen, die noch ein Gerät brauchen, starten pausiert.

## [1.34.0] – 2026-10-05

### Neu
- Smart Home live: Eine dauerhafte Verbindung zu Home Assistant (über das VPN oder die öffentliche Adresse) liefert jede Zustandsänderung sofort – Automationen reagieren in Sekunden statt Minuten; Bereiche aus Home Assistant werden automatisch übernommen.
- Verbindungswächter: Ist Home Assistant zweimal hintereinander nicht erreichbar, bekommen die Haushaltsbesitzer eine Push-Nachricht – und eine Entwarnung, sobald es wieder geht. Der Systemzustand zeigt zusätzlich, ob das VPN läuft.
- Automationen fragen per Push nach: Aktion „Per Push fragen“ mit zwei Knöpfen direkt in der Benachrichtigung (z. B. „Ausschalten“ / „Anlassen“); die folgenden Aktionen laufen erst nach „Ja“. Ohne Knöpfe auf dem Handy öffnet sich eine Seite zum Antworten. Neue Vorlage: „Alle sind weg → fragen, ob das Licht ausgehen soll“.
- Dashboards: neue Karten für Kamera (Live-Bild), Wetter, „Heute“ und Szenen; Karten lassen sich an Bedingungen knüpfen („nur zeigen, wenn …“).
- Wandtablet-Ansicht: Dashboard im Vollbild mit Uhr, bleibt wach und dimmt nachts.
- Ein Dashboard lässt sich auf der Übersicht anzeigen („☆ Auf Übersicht zeigen“).
- App-Schnittstelle: Die App kann melden, ob man zu Hause oder unterwegs ist – zählt für Anwesenheits-Automationen mit.

## [1.33.0] – 2026-10-05

### Neu
- Übersicht › „Mein Tag“: alles mit Datum von heute aus allen Bereichen in einer Zeitleiste (Aufgaben, Termine, Fristen, Essen, Kalender-Abos), dazu „Als Nächstes in 20 Min.“, Wetter, überfällige und heute erledigte Aufgaben. Morgens „Dein Morgen“, abends „Dein Abend“ mit Vorschau auf morgen; Aufgaben direkt abhaken (mit Rückgängig).
- Ein Eingabefeld für alles („✨ Automatisch“ in der Schnellerfassung und in der Befehlsleiste): „Einkauf: Milch, Brot“ → Einkaufsliste, „50 € Tanken“ → Ausgabe in Finanzen, „Zahnarzt Do 10 Uhr“ → Termin, „Licht Wohnzimmer an“ → Gerät, „Urlaubsmodus an“ → Modus, alles andere → Inbox. Unter dem Feld steht vorab, was daraus wird; mit Rückgängig.
- Menü anpassen: Module anpinnen (oben im Menü), ausblenden (bleiben über die Suche erreichbar), „Oft genutzt“ lernt mit, Schnellstart-Vorlagen (Alles, Familie & Haushalt, Schwerpunkt Geld, Minimal) – auch als erster Schritt für neue Mitglieder.
- App-Schnittstelle: „Mein Tag“, Widgets (nächster Termin, offene Einkäufe, Energie, Wetter), das Eingabefeld für alles sowie Smart Home (Geräte, Modi, Dashboards, Energie; schalten mit Bestätigung für Schlösser).

### Verbessert
- Aufgaben verstehen kurze Wochentage („Do 10 Uhr“, „Fr.“).

## [1.32.0] – 2026-10-04

### Neu
- Admin › Systemzustand: Zeitplaner, Hintergrundaufgaben, Warteschlange, Speicherplatz, tägliche Sicherung, Kopie außerhalb des Servers und Wiederherstellungstest auf einen Blick.
- Wächter: alle zehn Minuten geprüft – Admins bekommen eine Push-Nachricht (und E-Mail), wenn etwas rot wird, und eine Entwarnung, wenn es wieder passt.
- Serverfehler kommen zusätzlich als Push aufs Handy (derselbe Fehler höchstens einmal pro Stunde).
- Wiederherstellungstest jede Nacht auf Sonntag: Die neueste Sicherung wird geprüft (Archiv, alle Tabellen mit allen Zeilen, alle Dateien) und in eine Testdatenbank eingespielt; jede Sicherung enthält dafür jetzt ein Inhaltsverzeichnis.
- Langsame Seiten: jeder Aufruf wird gemessen; die langsamsten Seiten der Woche und Aufrufe über einer Sekunde (mit Anzahl der Datenbankabfragen) stehen im Systemzustand.

## [1.31.0] – 2026-10-04

### Neu
- Smart Home › eigene Dashboards: beliebig viele Dashboards (gemeinsam oder „Nur für mich“) mit Name und Symbol, als eigene Reiter neben dem Überblick.
- Karten für Geräte, Überschriften und den Energiefluss; ein ganzer Bereich (z. B. Wohnzimmer) lässt sich mit einem Klick samt Überschrift hinzufügen.
- Pro Karte wählbar: Anzeigeart (Kachel, große Taste, großer Wert, Ring-Anzeige mit Min/Max, Regler, Verlaufsdiagramm über 6 Std. bis 7 Tage, kompakte Zeile mit Schalter), Größe (klein, breit, groß, ganze Breite) und ein eigener Titel.
- Karten per Drag & Drop anordnen; angeboten werden nur Anzeigearten, die zum Gerät passen.

## [1.30.1] – 2026-10-04

### Behoben
- VPN mit vollem Tunnel (AllowedIPs = 0.0.0.0/0, z. B. UniFi): Adressen im privaten Heimnetz (192.168.x.x, 10.x.x.x, 172.16–31.x.x) gelten jetzt als über das VPN erreichbar – Home Assistant, evcc und NAS lassen sich damit eintragen.
- Die Meldung „Diese Adresse ist nicht erlaubt“ sagt jetzt, wo das Heimnetz eingetragen wird.

## [1.30.0] – 2026-10-04

### Neu
- Smart Home › Energie: PV-Ertrag, Verbrauch, Netzbezug, Einspeisung und Laden heute mit Tageskurve, letzte 30 Tage mit Autarkie – aufgezeichnet alle fünf Minuten aus evcc.
- Übersicht: Kacheln „Energie heute“ und „Lieblingsgeräte“ (mit ★ markierte Geräte direkt schalten); Energie auch im Morgen-Briefing und im Wochenrückblick.
- Zählerstände aus Home-Assistant-Sensoren landen jede Nacht automatisch unter „Zuhause › Verbrauch“; das tägliche Laden in evcc wird mit kWh und Kosten (Strompreis des Stromzählers) im Fahrtenbuch des zugeordneten Autos eingetragen.
- Regler auf den Gerätekacheln: Helligkeit, Solltemperatur der Heizung, Position der Rollläden.
- Automationen – neue Auslöser: Sonnenaufgang/-untergang (mit Versatz), Termin beginnt bald, Aufgabe wird fällig, Budget erreicht, Kommen und Gehen (Personen aus Home Assistant); neue Bedingungen: draußen dunkel/hell, wer zu Hause ist; neue Aktionen: Warten (Minuten), evcc-Ladeplan „bis Uhrzeit auf X %“; evcc-Werte je Ladepunkt; weitere Vorlagen.
- Befehlsleiste: Automationen ausführen, Modi umschalten und Geräte schalten.

### Sicherheit
- Schlösser, Tore, Ventile und Alarmanlagen werden nur nach einer Rückfrage geschaltet und im Sicherheitsprotokoll vermerkt.
- Haushalt: pro Mitglied einstellbar, ob es Smart-Home-Geräte schalten oder nur ansehen darf.
- Geänderte Werte aktualisieren offene Smart-Home-Seiten und die Übersicht live.

## [1.29.0] – 2026-10-04

### Neu
- Smart Home (neues Modul unter „Zuhause & Familie“): Home Assistant und evcc verbinden – im Heimnetz über das VPN, mit Nabu Casa bzw. externer Adresse als Rückfall, wenn das VPN nicht steht.
  - Geräte aus Home Assistant auswählen, nach Räumen ordnen, Werte sehen und Licht, Schalter, Szenen und Skripte per Tippen schalten.
  - evcc: Energiefluss (PV, Verbrauch, Netz/Einspeisung, Akku), Ladepunkte mit Fahrzeug und Ladestand, Lademodus (Aus/Schnell/Min+PV/Nur PV) und Ladelimit umschalten.
  - Home Assistant kann Änderungen sofort per Webhook melden; sonst werden die Werte jede Minute abgefragt.
- Automationen für das ganze Tool: „Wenn … nur wenn … dann …“.
  - Auslöser: Kalendereintrag mit Stichwort (z. B. „Urlaub“), Reisen, Urlaub aus der Arbeitszeit, Modi, Uhrzeit, Werte aus Home Assistant und evcc oder nur von Hand.
  - Bedingungen: Modus an/aus, Zeitfenster, Wochentage, Kalendereintrag heute, Werte aus Home Assistant.
  - Aktionen: Home-Assistant-Dienste, Anwesenheitssimulation mit Lichtern, Lademodus und Ladelimit in evcc, Modus schalten, Benachrichtigung, Aufgabe anlegen.
  - Modi für den Haushalt (Urlaubsmodus, Niemand zu Hause, Gäste, Nacht) – von Hand oder per Automation geschaltet.
  - Vorlagen, z. B. „Urlaub im Kalender → Urlaubsmodus → abends Anwesenheit simulieren“, Protokoll jeder Ausführung und „Jetzt ausführen“.

## [1.28.3] – 2026-10-03

### Behoben
- Rezepte › Instagram-Import wie in der RezepteApp: der Beitrag wird wie auf dem iPhone gelesen (Beschreibung und Foto, auch gekürzte Texte), das Foto wird mit passendem Referer geladen.
- Neues Feld „Beitragstext (optional)“ beim Link-Import: eingefügter Text hat Vorrang, damit die Mengen vollständig sind.
- Gibt Instagram nur das Foto heraus, öffnet sich das Formular trotzdem mit dem Foto und einem Hinweis.

## [1.28.2] – 2026-10-03

### Behoben
- Rezepte: Import per Link funktioniert wieder mit Instagram-Beiträgen und Reels – der Beitragstext wird gelesen (Einbettung, Link-Vorschau oder – falls eingerichtet – Apify) und von der KI in ein Rezept mit Foto umgewandelt. Gibt Instagram den Text nicht heraus, steht ein klarer Hinweis da.

## [1.28.1] – 2026-10-03

### Behoben
- VPN (WireGuard): eine FRITZ!Box-Konfiguration mit Suchdomain bei „DNS“ (z. B. „192.168.178.1, fritz.box“) bringt wireproxy nicht mehr sofort zum Abbruch; die Verbindung bekommt beim Start bis zu 10 Sekunden Zeit, und statt „wireproxy hat sich sofort beendet“ steht der Grund da (Exit-Code und Ergebnis der Konfigurationsprüfung).
- VPN-Verbindungstest: läuft wireproxy nicht, nennt der Test den Grund aus dem VPN-Status statt nur „Failed to connect to 127.0.0.1“.

## [1.28.0] – 2026-10-03

### Neu
- Push-Benachrichtigungen bringen ihren Text jetzt verschlüsselt mit (Web-Push-Standard RFC 8291) – sie erscheinen auf dem iPhone auch dann vollständig, wenn die App gerade nicht angemeldet ist.
- Erinnerungen (Aufgaben mit Uhrzeit, Medikamente, Gewohnheiten) gehen mit hoher Priorität raus und werden sofort angezeigt.
- Profil › Push: Diagnose je Gerät – über welchen Dienst (z. B. Apple) es läuft, ob der Text verschlüsselt mitkommt, wann zuletzt zugestellt wurde und der letzte Fehler; Geräte lassen sich entfernen. Abgelaufene Anmeldungen werden automatisch gelöscht.
- Optional: alle Benachrichtigungen zusätzlich über Pushover (Schlüssel aus den Portfolio-Einstellungen), dringende mit hoher Priorität.

### Behoben
- „Angemeldet bleiben“ funktioniert wieder: Geräte, die mit „Angemeldet bleiben“ angemeldet sind, werden nicht mehr nach 30 Minuten Inaktivität abgemeldet.
- Nach dem Abmelden (und nach dem Löschen des Kontos) landest du wieder auf der Anmeldeseite von E11LifeOS statt auf der Plesk-Seite von zimmimail.de.

## [1.27.0] – 2026-10-03

### Neu
- Tagebuch & Gewohnheiten (unter „Alltag“ › Routinen & Tagebuch, nur für dich sichtbar):
  - Gewohnheiten abhaken oder zählen (z. B. 8 Gläser Wasser) – täglich, an bestimmten Wochentagen oder x-mal pro Woche, mit Push-Erinnerung zur Uhrzeit, solange sie noch offen ist.
  - Serie, beste Serie, Erfolgsquote und Gewohnheitsstärke (ein verpasster Tag kostet nur ein wenig) sowie eine Jahresansicht.
  - Tagebuch pro Tag: Stimmung, Aktivitäten, Fragen für morgens und abends, Notizen und Fotos – Texte verschlüsselt.
  - „Der Tag in E11LifeOS“ mit Terminen, erledigten Aufgaben und Reisen und „An diesem Tag“ aus früheren Jahren.
  - Auswertung: Stimmung der letzten 90 Tage, welche Aktivitäten und Gewohnheiten mit guter Stimmung zusammenhängen.
- Übersicht: Kachel „Gewohnheiten heute“ zum Abhaken; der Wochenrückblick zeigt Gewohnheiten und Stimmung.

## [1.26.0] – 2026-10-03

### Neu
- Aktien: Punktesystem wie im „Aktien- und Börsenführerschein“ – 0–3 Punkte je Bewertungsfaktor, doppelte Gewichtung für KGV, Börsensegment, 52-Wochen-Hoch/Tief, Portfoliostreuung, Eigenkapital/Schulden, KBV, Dividende, Umsatz und Ertrag, Nachhaltigkeit, Value, Growth und Technische Analyse, einfache für Geschäftsmodell, Marktstellung, Internationalität, Bekanntheitsgrad, Nachrichten, Analysten und Handelbarkeit – mit Zwischensummen und Endergebnis.
- Kennzahlen werden automatisch bewertet; Geschäftsmodell, Ruf & Co. bewertest du selbst per Tippen und kannst jede Punktzahl überstimmen.
- Portfolio-Einstellungen: eigene Gewichtung je Faktor (aus, ×1, ×2, ×3) – auch als „kreative Variante“ mit dreifacher Gewichtung wie im Buch.

## [1.25.1] – 2026-10-03

### Behoben
- Portfolio: „Zur Watchlist“ funktioniert wieder bei Empfehlungen, Entdecken und Instagram.
- Positionen: beim Anlegen lassen sich Kaufgebühren angeben – sie fließen in den durchschnittlichen Kaufkurs ein.
- Handy: Seiten werden nicht mehr breiter als der Bildschirm (iOS zoomte z. B. bei Vertragskosten heraus) – lange Auswahlfelder und Tippflächen am Rand bleiben im Bildschirm; die Vertragstabelle zeigt Anbieter und Kündigungsdatum unter dem Namen.
- Finanzen › Budgets: der Kategoriename bleibt lesbar, wenn ein Budget gesetzt ist.
- Listen: „Zurück“ (z. B. „← Alle Rezepte“) führt an dieselbe Stelle der Liste zurück, mit den gewählten Filtern.
- Hinweis „Neu“ an der Versionsnummer erscheint wieder nach einem Update.

## [1.25.0] – 2026-10-03

### Neu
- Reisen: aufklappbare „Tipps für …“ in der Übersicht – kurze Einführung aus Wikipedia, sehenswerte Orte und Restaurants & Cafés, mit einem Tipp direkt als Idee in den Plan.
- Reisen: Plan nach Kategorie anzeigen (z. B. alle geplanten und möglichen Restaurants) und Orte als besucht abhaken – auch Ideen ohne Tag.
- Reisekarte: „Orte in der Nähe“ lassen sich nach Art (Sehenswürdigkeiten, Essen & Trinken, Aktivitäten, Einkaufen …) ein- und ausblenden und direkt auf der Karte auswählen.

### Sicherheit
- Oberfläche auf Tailwind CSS 4 umgestellt – damit ist eine Sicherheitslücke in den Build-Werkzeugen von Tailwind 3 behoben. Das Aussehen bleibt gleich.

## [1.24.0] – 2026-10-02

### Neu
- Portfolio-Einstellungen: Abschnitt „Automatische Abläufe“ zeigt, wann Kurse, Kursalarme, Sparpläne, Markt-Scan mit Kennzahlen, News und Instagram automatisch laufen.

### Verbessert
- Insiderbarometer: „Weitere Aktien im Markt“ zeigt jetzt Aktien aus dem Markt-Scan, die nicht in deinem Depot oder auf der Watchlist sind – mit Knopf zum Nachladen weiterer Insidergeschäfte.

## [1.23.0] – 2026-10-02

### Neu
- Vertragskosten: neuen Vertrag direkt anlegen (Liste wählbar, optional mit Zahlung aus Finanzen) und unter „Alle weiteren Zahlungen“ jede Abbuchung als Vertrag anlegen oder mit einem verknüpfen – auch einmalige und jährliche.

## [1.22.1] – 2026-10-01

### Verbessert
- Vorsorgecheck: Bei Versicherungen lässt sich jetzt jede Abbuchung aus Finanzen auswählen – nicht nur erkannte regelmäßige Zahlungen. So passen auch Jahresbeiträge, die bisher nur einmal abgebucht wurden.

## [1.22.0] – 2026-10-01

### Neu
- Portfolio: neue Seite „Marktanalyse“ von oben nach unten wie im „Aktien- und Börsenführerschein“ – Globalanalyse (Indizes, Zinsen, VIX, Währung, Rohstoffe) mit Börsenampel, Branchenanalyse (Branchen-ETFs im Vergleich zum S&P 500) und das Punktesystem für deine Aktien.
- Aktien: Punktesystem (0–2 Punkte je Kriterium für Bewertung, Rentabilität, Wachstum, Verschuldung, Dividende, Chart, Branche und Markt) mit Einschätzung auf jeder Aktienseite.

## [1.21.0] – 2026-10-01

### Neu
- Aktien: weitere Kennzahlen – EBIT, EBITDA, KCV (Kurs-Cashflow-Verhältnis), EV/EBITDA, Free-Cashflow-Rendite – mit Einordnung und Verlauf über die Zeit.
- Aktien: Chartanalyse mit 200-Tage-Linie im Chart, Trend, Golden/Death Cross, RSI, Momentum, 52-Wochen-Spanne, Unterstützung/Widerstand und Volatilität.
- Aktien: eigene Notizen und Hinweise zu jeder Aktie im Depot oder auf der Watchlist.

## [1.20.0] – 2026-10-01

### Neu
- Karriere (unter „Zuhause & Familie“): Werdegang mit Jobs, Schule & Studium, Schulungen und Meilensteinen; Schulungen mit Zertifikat (paperless oder Upload) und Ablaufdatum-Hinweis; Arbeitsverträge mit Gehalt, Kündigungsfrist, Urlaub und Unterlagen; Lebenslauf mit PDF; Bewerbungen mit Status und Vorschlägen aus der Jobbörse der Bundesagentur für Arbeit sowie Suchlinks zu StepStone, Indeed, LinkedIn und XING. Nur für dich sichtbar.

## [1.19.0] – 2026-10-01

### Neu
- Cashflow-Analyse: „Werte aus Finanzen“ – pro Finanzkategorie festlegen, wie sie zählt (Gehalt, Kapitaleinkommen, passives oder sonstiges Einkommen, Ausgabe, Erstattung, nicht berücksichtigen). Auch mit „nur eigene Einträge“ lassen sich einzelne Kategorien dazunehmen.

## [1.18.0] – 2026-10-01

### Neu
- Finanzen: Buchungen nach „mit Beleg“ / „ohne Beleg“ filtern (z. B. Gehaltsabrechnungen für die Steuer finden), Anzahl der Belege direkt an der Buchung.
- Finanzen: Rechnung aus paperless an der Buchung „bezahlt markieren“ (Tag „Bezahlt“ und Notiz mit der Buchung) oder ohne Rechnung einen Buchungsbeleg nach paperless schicken. Die paperless-Suche startet mit dem Empfänger.

## [1.17.0] – 2026-10-01

### Neu
- Packlisten: gemeinsame Liste für alle und eine persönliche Liste pro Person, die nur sie selbst sieht – beide aus Vorlagen befüllbar.
- Eigene Vorlagen: eine Packliste als Vorlage speichern (auch nur für dich) und sie für Reisen, Aufgaben (Checkliste) und neue Seiten nutzen.

## [1.16.0] – 2026-10-01

### Neu
- Reisen: Kommentare und Vorschläge zum Reiseplan – auch über den Reiseordner und die persönlichen Teilnehmer-Links. Vorschläge werden im Tab „Plan“ freigegeben (als Idee oder an einem Tag) oder abgelehnt; die Organisatoren werden benachrichtigt.

## [1.15.0] – 2026-10-01

### Neu
- Reiseordner: zeigt jetzt auch den Tagesplan, weitere Ideen, ausgewählte Seiten mit Infos und Links – einstellbar unter „Gruppe & Reiseordner“, Notizen nur auf Wunsch.

## [1.14.0] – 2026-10-01

### Neu
- Reisen: Tab „Entdecken“ mit Kategorien (Sehenswürdigkeiten, Museen, Restaurants, Cafés, Bars, Natur, Aktivitäten, Shopping), Bildern, Links zu Speisekarte, Fotos und Website.
- Reisen: Orte als besucht abhaken – sie verschwinden aus der Liste und lassen sich wieder einblenden; Orte direkt einem Tag zuplanen.

## [1.13.0] – 2026-10-01

### Neu
- Versionsnummer mit Build in Staging und Live sowie diese Seite „Neuigkeiten“ mit allen Änderungen.

## [1.12.0] – 2026-10-01

### Neu
- Vorsorgecheck: Versicherungen (z. B. Berufsunfähigkeit) direkt anlegen und mit ihrer Abbuchung aus Finanzen verknüpfen.
- Vorsorgecheck: falsch erkannte Verträge ablehnen, nicht erkannte Versicherungen selbst zuordnen, Versicherungsbeiträge ohne Vertrag anlegen oder verknüpfen.
- Vertragskosten: Zahlungen ohne Vertrag mit einem bestehenden Vertrag verknüpfen.
- Neues App-Icon und Logo.

### Behoben
- Live-Updates (Reverb) auf Live: die App sendet jetzt an den Port, mit dem Reverb gestartet wird.

## [1.11.0] – 2026-10-01

### Neu
- Seiten-Checklisten: Enddatum mit `//15.10.`, Verantwortliche mit `@Name`, Punkt als Aufgabe anlegen und verlinken.
- Vorräte: Lebensmittel und Verbrauchsartikel mit Mindestbestand, Haltbarkeit und Einkaufsliste.
- Portfolio: Insiderbarometer, Analysten, Umsatz und Gewinn, Pläne aus den News, Positions-Check.
- Mail-Eingang: eigener IMAP-Abruf, Einrichtung auf der Admin-Seite.

### Verbessert
- Dark Mode: Auswahl- und Formularfelder im ganzen Tool lesbar.
- Reisen: Ortsnamen in der Sprache des Tools.

### Behoben
- Seiten: abgehakte Punkte auch in der Leseansicht durchgestrichen.
- Reisen: Verschieben der Zeile „Noch nichts geplant“ führte zu einer Fehlerseite.

## [1.10.0] – 2026-09-30

### Neu
- Mail-Eingang pro Haushalt mit eigener Adresse: Buchungen zur Reise, Anhänge nach paperless, Rest in die Inbox.
- Aufgaben-Kanban, Aufgaben aus Mails, Seiten-Versionen und Vorlagengalerie, Suche inklusive OCR, gebündelte Benachrichtigungen, neu gegliedertes Menü.
- Garantien, Budget-Töpfe, Impfpass und Arzttermine, Geschenkideen.
- Reisen: Buchungen per Mail, Wetter pro Tag, Fremdwährung, offline verfügbar, PDF- und Kalender-Export.
- Rezepte: lange nicht gekocht, Reste, Kochbücher, Nährwerte pro Tag, Wochenplan per Drag & Drop.

## [1.9.0] – 2026-09

### Neu
- Reiseplanung mit Tagesplan, interaktiver Karte, Tagesrouten mit Wegzeiten und vorgeladenen Orten.
- PDF-Export im neuen Design mit einfachem Vorlagen-Editor.
- Rezepte: Einkaufsliste mit zusammengefassten Einheiten, Vorrat abziehen, Haltbarkeit.

## [1.8.0] – 2026-09

### Neu
- Kostenteilung, Wunschliste mit Leistbarkeits-Check, Orte & Merkliste, Arbeitszeit & Urlaub, Gruppenreisen mit geschütztem Reiseordner.
- Immobilien-Wirtschaftlichkeit, Portfolio-Wissen, persönliche Cashflow- und Spartipps, Erkenntnisse.
- NAS-Zugriff über VPN, Google Drive und iCloud, Spaces für Seiten, Gruppen zum Teilen über Haushalte hinweg.

### Sicherheit
- Login-Benachrichtigung, Sitzungen, Sicherheitsprotokoll, Inaktivitäts-Sperre, Passkeys, Virenscan, strengere Content-Security-Policy.

## [1.7.0] – 2026-08

### Neu
- Notfallordner, Fahrzeuge, Zuhause (Wartung & Verbrauch), Gesundheit & Familie, Menschen & Anlässe.
- Versicherungs- und Rentencheck, Cashflow-Analyse nach „Rich Dad Poor Dad“, Wissensbereich.
- Banking: Kontostand-Prognose, Abos mit Verträgen abgleichen, Benachrichtigungen.
- Reisen und Taschengeld & Kinder-Aufgaben.

## [1.6.0] – 2026-08

### Neu
- Einkaufslisten mit Läden und Gängen, globaler Kalender, Benachrichtigungscenter, Nettovermögen.
- Vermietung, Immobilien, Angebotsvergleiche, KI-Assistent mit Aktionen.

## [1.5.0] – 2026-07

### Neu
- Portfolio mit Kursdaten, Depots, Broker-Import, Analyse, Empfehlungen, Bank-Sync (FinTS) und Instagram-Analyse.
- Business: Angebote, Rechnungen, Mahnwesen, Zeiterfassung, XRechnung, USt-Voranmeldung.

## [1.0.0] – 2026-06

### Neu
- Second Brain für Haushalte: Seiten, Aufgaben, Inbox, Sammlungen, Rezepte, Finanzen, Kalender-Abo, Offline-Erfassung, Web-Push und automatische Backups.
