# Changelog

Alle wichtigen Änderungen an E11LifeOS. Die oberste Versionsnummer ist die aktuelle Version;
jeder Deploy bekommt zusätzlich eine fortlaufende Build-Nummer.

## [1.28.1] – 2026-10-03

### Behoben
- VPN (WireGuard): eine FRITZ!Box-Konfiguration mit Suchdomain bei „DNS“ (z. B. „192.168.178.1, fritz.box“) bringt wireproxy nicht mehr sofort zum Abbruch; die Verbindung bekommt beim Start bis zu 10 Sekunden Zeit, und statt „wireproxy hat sich sofort beendet“ steht der Grund da (Exit-Code und Ergebnis der Konfigurationsprüfung).

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
