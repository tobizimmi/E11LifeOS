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

### Rezepte-Addon – Etappe R1 ✅
- Addon-System (pro Haushalt ein-/ausschaltbar); Rezepte mit Foto, Zutaten/Abschnitten, Schritten, Portionsrechner, Kategorien, Tags, Favoriten, Archiv, „Gekocht“-Zähler.
- Zutaten-Auswahl umgerechnet auf die LifeOS-Einkaufsliste; Suche, Export, Kontolöschung berücksichtigen Rezepte.
- Import aus der RezepteApp2 (`php artisan recipes:import-rezepteapp`).

### Rezepte-Addon – Etappe R2 ✅
- Kochmodus (Vollbild, Schritt für Schritt, mehrere Timer mit Alarm, Wake Lock, Bewertung am Ende).
- Wochenplan pro Haushalt (Rezepte oder freie Einträge, Drag & Drop, Zutaten der Woche zusammengefasst auf die Einkaufsliste, Karte auf der Übersicht). Private Rezepte erzeugen private Planeinträge.
- Bewertungen (1–5 Sterne + Notiz, eine pro Person), Sortierung „Beste Bewertung“; Suche nach vorhandenen Zutaten; Duplizieren; weitere Fotos mit Galerie.
- Import übernimmt auch Bewertungen, Wochenplan und weitere Fotos; Export und Kontolöschung berücksichtigen die neuen Daten.

### Rezepte-Addon – Etappe R3 ✅
- Import per Link (schema.org JSON-LD/Microdata, sonst Claude), Foto/Screenshot, PDF und Text über die Claude-API (offizielles PHP-SDK, strukturierte Ausgabe); Entwurf öffnet im Formular. Schutz vor Abrufen ins lokale Netz.
- Nährwerte pro Portion (manuell oder per KI geschätzt, gekennzeichnet), PDF-Export (dompdf), öffentliche Links (`/r/{token}`, abschaltbar, nicht indexiert).
- Barcode-Scanner in der Einkaufsliste (BarcodeDetector des Browsers, auf dem iPhone die nachgeladene ZXing-Bibliothek, Eingabe als Fallback, Open Food Facts).

### Notion-Ansatz – Seitenbaum & Dateien ✅
- Seiten in der Seitenleiste per Drag & Drop sortieren und in andere (aufgeklappte) Seiten verschieben; ohne Handsortierung bleibt es alphabetisch.
- Bilder und Dateien in Seiten (Knopf, „/“-Befehl, Drag & Drop, Einfügen); Bilder im Editor sichtbar. Dateien liegen privat und sind so sichtbar wie die Seite.

### Phase 3 (Teil 2) – Organisation ✅
- Kalender mit Wochenansicht und Vorschau künftiger Termine wiederkehrender Aufgaben.
- Tägliche Erinnerungs-E-Mail (Scheduler, im Profil einschaltbar).
- Einkaufsliste offline lesbar (Service Worker, Kopie wird beim Abmelden gelöscht).
- Tags in Aufgaben, Sammlungs-Einträgen und Inbox (`#tag` beim Erfassen), Tag-Filter in Sammlungen.

### Phase 4 (Teil 1) – Haushalts-Module ✅
- Module als strukturierte Sammlungs-Vorlagen: Verträge & Dokumente, Haushaltsbuch, Vorräte, Lesezeichen / Später lesen, Tagebuch (privat), Gewohnheiten.
- E-Mail-Erinnerung für Datumsfelder (z. B. „Kündigen bis“ 30 Tage vorher, „Haltbar bis“ 3 Tage vorher) in der täglichen Mail.
- Summen in Tabellen (Zahlen, abgehakte Checkboxen) und pro Board-Spalte; Dateien in Eintrags-Notizen; Einträge auf die Einkaufsliste.

### Verknüpfungen überall ✅
- Gemeinsame Link-Tabelle (`links`) für Seiten, Aufgaben, Einträge, Inbox und Rezepte; Quellen: Text (`[[Seite]]`, interne Links `/items/{id}`, `/recipes/{id}`), Eigenschaft „Verknüpfung“, Knopf „Verknüpfen“.
- Panel „Verknüpfungen / Verlinkt von“ auf Seiten, Einträgen und Rezepten; `[[`-Vorschläge im Editor für alle Typen; Sichtbarkeit wird auf beiden Seiten geprüft.
- Eigenschaftstypen „Mehrfachauswahl“ und „Verknüpfung“.

## Nächste Schritte

### Phase 3 – Organisation (Rest)
- Erinnerungen zusätzlich per Web-Push (braucht VAPID-Schlüssel und ein Push-Paket).

### Phase 4 – Weitere Module
- Sammlungen: gespeicherte Filter.

### Phase 5 – KI (optional)
- Tag-Vorschläge, Zusammenfassungen, Fragen an das eigene Second Brain (z. B. über die Claude-API). Neue Abhängigkeiten nur nach Absprache.
