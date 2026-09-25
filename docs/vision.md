# E11LifeOS – Vision & Roadmap

## Vision

E11LifeOS bündelt die Werkzeuge, die ein Haushalt im Alltag braucht, an einem
Ort: ein gemeinsames „Second Brain“, in dem Gedanken schnell erfasst, sinnvoll
verknüpft und wiedergefunden werden – und in dem jede Person trotzdem einen
privaten Bereich hat.

Leitlinien:

- **Erfassen muss schneller sein als Vergessen.** Schnellerfassung auf der Übersicht, Inbox zum späteren Einsortieren.
- **Alles ist ein Eintrag.** Notizen, Aufgaben und Inbox-Einträge liegen in der gemeinsamen Tabelle `items` (Feld `type`), damit Suche, Tags, Sichtbarkeit und künftige Module einheitlich funktionieren.
- **Geteilt als Standard, privat auf Wunsch.** Nur die erstellende Person kann einen Eintrag privat stellen oder wieder teilen.
- **Verknüpfen statt sortieren.** `[[Wikilinks]]`, Rückverweise und Tags statt tiefer Ordnerstrukturen.
- **Datenhoheit.** Selbst gehostet, DSGVO-Export, keine Daten bei Dritten.

## Stand

### MVP ✅
Haushalt mit Einladungscodes, Notizen, Aufgaben, Einkaufsliste, Echtzeit-Sync (Reverb), PWA-Manifest, Datenexport, Deployment auf den Strato-VPS.

### Phase 1 – Fundament ✅
- Tests und Pint laufen in GitHub Actions; der Deploy hängt von grünen Tests ab.
- Oberfläche auf Deutsch, Zeitzone `Europe/Berlin`.
- Übersicht (Dashboard) mit offenen Aufgaben, Einkaufsliste, letzten Notizen, Inbox und Schnellerfassung.
- Gemeinsame Haushaltslogik für alle Module (`InteractsWithHousehold`).

### Phase 2 – Kern des Second Brain ✅
- Private und geteilte Einträge (Notizen, Aufgaben, Inbox).
- Markdown in Notizen, `[[Wikilinks]]` mit „Verlinkt von“-Rückverweisen.
- Tags als eigene Tabelle (`tags`, `item_tag`), normalisiert und pro Haushalt.
- Globale Suche über alle Module (`Strg/Cmd + K`).
- Inbox mit Umwandlung in Aufgabe, Notiz oder Einkaufslisten-Eintrag.

### Notion-Ansatz – Schritt A: Seitenleiste & Seitenbaum ✅
- Notizen heißen jetzt Seiten und lassen sich beliebig verschachteln (Unterseiten, Pfadanzeige, Icons).
- Seitenleiste statt oberer Navigation: Übersicht, Inbox, Haushalt, geteilter und privater Seitenbaum.
- Löschen einer Seite verschiebt ihre Unterseiten eine Ebene nach oben; Zyklen werden verhindert.

### Notion-Ansatz – Schritt B: Editor ✅
- Tiptap-Editor mit „/“-Befehlsmenü, Formatierungsleiste, Checklisten, Tabellen und `[[`-Autovervollständigung.
- Speicherformat bleibt Markdown (keine Datenmigration; Suche, Wikilinks, Export unverändert). Seiten mit Bildern/HTML öffnen im Markdown-Modus.
- Checklisten lassen sich in der Leseansicht direkt abhaken.

### Notion-Ansatz – Schritt C: Sammlungen ✅
- Sammlungen mit Eigenschaften (Text, Zahl, Datum, Auswahl, Checkbox, Person, Link), Tabellen- und Board-Ansicht, Detail-Panel mit Notizen.
- Vorlagen: Projekte, Verträge, Leseliste.
- Aufgaben sind die eingebaute Sammlung mit Status, Fälligkeit und Zuständigkeit (Status und „erledigt“ bleiben synchron); Übersicht zeigt Überfälliges zuerst.

### Phase 3 (Teil 1) – Kalender & wiederkehrende Aufgaben ✅
- Kalenderansicht (Monat) für alle Sammlungen mit Datumsfeld; Drag & Drop zwischen Tagen und in „Ohne Datum“, Einträge direkt an einem Tag anlegen.
- Aufgaben mit Wiederholung (täglich, wöchentlich, monatlich, jährlich): Abhaken verschiebt die Aufgabe auf den nächsten Termin nach heute und öffnet sie wieder; kurzer Hinweis mit dem neuen Datum.

## Nächste Schritte

### Notion-Ansatz – nächste Ausbaustufen
- Seiten per Drag & Drop im Seitenbaum verschieben und sortieren.
- Bilder/Dateien in Seiten (Upload), danach auch im Editor darstellbar.

### Phase 3 – Organisation
- Kalender: Wochenansicht, künftige Wiederholungen als Vorschau anzeigen.
- Erinnerungen per Web-Push oder E-Mail über den Laravel-Scheduler.
- Offline-Modus für die Einkaufsliste (Service Worker mit Cache).
- Tags auch in Aufgaben und Inbox pflegen.

### Phase 4 – Weitere Module
- Sammlungen: Eigenschaftstypen „Mehrfachauswahl“ und „Verknüpfung“ (zu Seiten/Einträgen), gespeicherte Filter.
- Verträge & Dokumente mit Kündigungsfristen und Erinnerung.
- Rezepte: Integration der bestehenden, separat entwickelten Rezepte-App (inkl. „Zutaten auf die Einkaufsliste“).
- Vorräte, Haushaltsbuch, Lesezeichen / Später lesen, Tagebuch & Gewohnheiten.

### Phase 5 – KI (optional)
- Tag-Vorschläge, Zusammenfassungen, Fragen an das eigene Second Brain (z. B. über die Claude-API). Neue Abhängigkeiten nur nach Absprache.
