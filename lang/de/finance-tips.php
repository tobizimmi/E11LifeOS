<?php

/*
 * Curated tips for the knowledge area (financing, stock market, taxes, wealth building).
 * General information, no advice; amounts as of 2025 – limits change, so tips say so where
 * it matters. Keys are stable: favourites refer to them.
 */

return [
    'categories' => [
        'financing' => ['label' => 'Finanzierung', 'icon' => '🏦'],
        'stocks' => ['label' => 'Börse & Geldanlage', 'icon' => '📈'],
        'taxes' => ['label' => 'Steuern', 'icon' => '🧾'],
        'wealth' => ['label' => 'Vermögen & Cashflow', 'icon' => '💡'],
        'richdad' => ['label' => 'Aus den Rich-Dad-Büchern', 'icon' => '📚'],
    ],

    'items' => [
        // ── Financing ─────────────────────────────────────────────
        'equity-share' => [
            'category' => 'financing',
            'title' => 'Eigenkapital: Nebenkosten zuerst decken',
            'summary' => 'Banken finanzieren den Kaufpreis gern – die Kaufnebenkosten solltest du aus eigener Tasche zahlen.',
            'body' => [
                'Grunderwerbsteuer, Notar, Grundbuch und ggf. Makler machen je nach Bundesland grob 7 bis 15 % des Kaufpreises aus. Diese Kosten schaffen keinen Gegenwert, den die Bank beleihen kann.',
                'Faustregel: Mindestens die Nebenkosten, besser zusätzlich 10 bis 20 % des Kaufpreises als Eigenkapital. Jeder Prozentpunkt weniger Beleihung senkt meist den Zinssatz.',
                'Behalte trotzdem einen Notgroschen zurück – ein Haus ohne Rücklage für die erste Reparatur ist riskant.',
            ],
        ],
        'fixed-rate' => [
            'category' => 'financing',
            'title' => 'Zinsbindung passend zur Restschuld wählen',
            'summary' => 'Lange Zinsbindung kostet etwas mehr, schützt aber vor steigenden Zinsen bei der Anschlussfinanzierung.',
            'body' => [
                'Entscheidend ist, wie hoch die Restschuld am Ende der Zinsbindung noch ist. Ist sie groß, trifft dich ein Zinsanstieg hart.',
                'Nach 10 Jahren kannst du ein Darlehen mit längerer Zinsbindung laut § 489 BGB mit sechs Monaten Frist kündigen – eine lange Bindung ist also nach hinten flexibel.',
                'Vergleiche Angebote mit gleicher Zinsbindung und gleichem Tilgungssatz, sonst sind die Effektivzinsen nicht vergleichbar.',
            ],
        ],
        'repayment-rate' => [
            'category' => 'financing',
            'title' => 'Tilgung nicht zu niedrig ansetzen',
            'summary' => 'Mit 1 % Tilgung dauert die Rückzahlung oft über 40 Jahre – 2 bis 3 % sind meist sinnvoller.',
            'body' => [
                'Die monatliche Rate besteht aus Zins und Tilgung. Bei niedriger Tilgung zahlst du lange vor allem Zinsen.',
                'Plane die Rate so, dass sie auch bei einem Einkommensrückgang tragbar bleibt – und nutze lieber Sondertilgungen für Extra-Geld.',
            ],
        ],
        'special-repayment' => [
            'category' => 'financing',
            'title' => 'Sondertilgungsrecht vereinbaren',
            'summary' => '5 bis 10 % Sondertilgung pro Jahr kosten oft wenig Aufpreis und sparen viel Zins.',
            'body' => [
                'Bonus, Steuererstattung oder Erbe lassen sich damit direkt in die Entschuldung stecken.',
                'Frage auch nach einem Tilgungssatzwechsel – damit kannst du die Rate später anpassen, ohne die Bank zu wechseln.',
            ],
        ],
        'forward-loan' => [
            'category' => 'financing',
            'title' => 'Anschlussfinanzierung früh planen',
            'summary' => 'Ein Forward-Darlehen sichert den Zins bis zu einige Jahre im Voraus – gegen einen Aufschlag.',
            'body' => [
                'Rund drei Jahre vor Ende der Zinsbindung lohnt ein Vergleich. Die bisherige Bank ist selten automatisch die günstigste.',
                'Trage das Ende der Zinsbindung im Tool bei deinem Darlehen ein, dann erinnert dich der Kalender rechtzeitig.',
            ],
        ],
        'kfw' => [
            'category' => 'financing',
            'title' => 'Förderkredite und Zuschüsse prüfen',
            'summary' => 'KfW und Landesbanken fördern Kauf, Neubau und energetische Sanierung – Anträge fast immer vor Vertragsabschluss.',
            'body' => [
                'Viele Programme verlangen den Antrag, bevor du einen Kauf- oder Handwerkervertrag unterschreibst. Danach ist die Förderung oft verloren.',
                'Im Immobilienbereich des Tools findest du passende Förderprogramme mit Fristen.',
            ],
        ],
        'debt-order' => [
            'category' => 'financing',
            'title' => 'Teure Schulden zuerst abbauen',
            'summary' => 'Dispo- und Ratenkredite kosten oft deutlich mehr, als eine Geldanlage sicher bringt.',
            'body' => [
                'Sortiere deine Schulden nach Zinssatz und tilge die teuersten zuerst („Lawinen-Methode“). Motivierender kann es sein, kleine Schulden zuerst zu erledigen („Schneeball-Methode“).',
                'Einen dauerhaft genutzten Dispo kann eine günstigere Umschuldung ersetzen.',
            ],
        ],

        // ── Stocks & investing ────────────────────────────────────
        'emergency-fund' => [
            'category' => 'stocks',
            'title' => 'Erst Notgroschen, dann Börse',
            'summary' => 'Drei bis sechs Monatsausgaben auf dem Tagesgeldkonto verhindern Notverkäufe im Crash.',
            'body' => [
                'Wer in einer Krise Aktien verkaufen muss, um eine Autoreparatur zu zahlen, realisiert Verluste zum schlechtesten Zeitpunkt.',
                'Lege den Notgroschen als Sparziel im Tool an und verknüpfe es mit deinem Tagesgeldkonto.',
            ],
        ],
        'etf-savings-plan' => [
            'category' => 'stocks',
            'title' => 'Breit gestreuter ETF-Sparplan',
            'summary' => 'Regelmäßig in einen weltweit gestreuten Index-ETF investieren ist für viele der einfachste Einstieg.',
            'body' => [
                'Ein Weltindex verteilt dein Geld auf tausende Unternehmen und viele Länder. Einzelne Pleiten fallen kaum ins Gewicht.',
                'Mit einem Sparplan kaufst du bei niedrigen Kursen automatisch mehr Anteile (Durchschnittskosteneffekt) und musst den Markt nicht timen.',
                'Plane mindestens zehn bis 15 Jahre ein – kürzere Zeiträume können auch mit Verlust enden.',
            ],
        ],
        'costs' => [
            'category' => 'stocks',
            'title' => 'Kosten sind die sicherste Rendite',
            'summary' => '1 % weniger Kosten pro Jahr macht über Jahrzehnte einen großen Unterschied.',
            'body' => [
                'Achte auf laufende Kosten (TER), Ordergebühren, Depotgebühren und Ausgabeaufschläge. Aktiv gemanagte Fonds sind im Schnitt deutlich teurer als ETFs und schlagen ihren Index langfristig selten.',
                'Durch den Zinseszins wirken Kosten wie eine umgekehrte Rendite: Sie mindern nicht nur das Kapital, sondern auch alle späteren Erträge darauf.',
            ],
        ],
        'diversification' => [
            'category' => 'stocks',
            'title' => 'Nicht alles auf eine Karte setzen',
            'summary' => 'Einzelaktien, Branchen und Länder streuen – auch den eigenen Arbeitgeber nicht übergewichten.',
            'body' => [
                'Klumpenrisiken entstehen schnell: viel Geld in einer Aktie, einer Branche oder im eigenen Land. Hängt dann noch dein Job an derselben Branche, trifft dich eine Krise doppelt.',
                'Die Portfolio-Analyse zeigt dir die Verteilung nach Branchen und Regionen.',
            ],
        ],
        'rebalancing' => [
            'category' => 'stocks',
            'title' => 'Einmal im Jahr neu gewichten',
            'summary' => 'Rebalancing hält dein Risiko dort, wo du es haben willst.',
            'body' => [
                'Wenn Aktien stark steigen, wächst ihr Anteil – und damit dein Risiko. Einmal pro Jahr auf die Zielgewichtung zurückzugehen, bringt Disziplin: teuer Gewordenes etwas verkaufen, günstig Gewordenes nachkaufen.',
                'Oft reicht es, neue Sparraten gezielt in die untergewichtete Anlage zu lenken – dann fallen keine Steuern auf Verkäufe an.',
            ],
        ],
        'emotions' => [
            'category' => 'stocks',
            'title' => 'Crashs aussitzen statt verkaufen',
            'summary' => 'Wer in Panik verkauft, verpasst oft die Erholung – die besten Börsentage liegen meist nahe an den schlechtesten.',
            'body' => [
                'Kursrückgänge von 20 % und mehr kommen immer wieder vor. Historisch haben sich breite Märkte erholt, auch wenn das Jahre dauern kann.',
                'Schreibe dir deine Strategie auf (z. B. als Seite im Tool) und lies sie, bevor du in einer Krise etwas änderst.',
            ],
        ],
        'accumulating' => [
            'category' => 'stocks',
            'title' => 'Thesaurierend oder ausschüttend?',
            'summary' => 'Thesaurierende Fonds legen Erträge automatisch wieder an, ausschüttende zahlen sie aus.',
            'body' => [
                'Für den Vermögensaufbau ist Wiederanlage praktisch. Ausschüttungen helfen, den Sparerpauschbetrag jedes Jahr zu nutzen, und eignen sich als laufendes Einkommen – etwa später im Ruhestand.',
                'Auch bei thesaurierenden Fonds fällt jährlich eine kleine Vorabpauschale an; sie wird beim Verkauf angerechnet.',
            ],
        ],

        // ── Taxes ─────────────────────────────────────────────────
        'saver-allowance' => [
            'category' => 'taxes',
            'title' => 'Freistellungsauftrag verteilen',
            'summary' => 'Kapitalerträge bis 1.000 € (Verheiratete 2.000 €) im Jahr bleiben steuerfrei – aber nur mit Freistellungsauftrag bei der Bank.',
            'body' => [
                'Verteile den Sparerpauschbetrag (Stand 2025) auf die Banken, bei denen tatsächlich Zinsen, Dividenden oder Gewinne anfallen. Nicht genutzte Beträge verfallen am Jahresende.',
                'Zu viel einbehaltene Abgeltungsteuer holst du über die Anlage KAP in der Steuererklärung zurück.',
            ],
        ],
        'guenstigerpruefung' => [
            'category' => 'taxes',
            'title' => 'Günstigerprüfung bei niedrigem Einkommen',
            'summary' => 'Liegt dein persönlicher Steuersatz unter 25 %, kann das Finanzamt Kapitalerträge niedriger besteuern.',
            'body' => [
                'Das gilt z. B. in Elternzeit, im Studium oder im Ruhestand. Du beantragst die Günstigerprüfung in der Anlage KAP.',
            ],
        ],
        'loss-offset' => [
            'category' => 'taxes',
            'title' => 'Verlusttöpfe kennen',
            'summary' => 'Verluste aus Aktien werden nur mit Gewinnen aus Aktien verrechnet – andere Verluste breiter.',
            'body' => [
                'Die Bank führt die Verlusttöpfe automatisch und überträgt sie ins nächste Jahr. Hast du Depots bei mehreren Banken, kannst du bis zum 15. Dezember eine Verlustbescheinigung beantragen und die Verluste in der Steuererklärung mit Gewinnen der anderen Bank verrechnen.',
            ],
        ],
        'home-office' => [
            'category' => 'taxes',
            'title' => 'Homeoffice-Pauschale nutzen',
            'summary' => '6 € je Homeoffice-Tag, höchstens 1.260 € im Jahr (Stand 2025) – auch ohne eigenes Arbeitszimmer.',
            'body' => [
                'Die Pauschale gehört zu den Werbungskosten. Sie lohnt sich nur, wenn du zusammen mit anderen Kosten über die Werbungskostenpauschale von 1.230 € kommst.',
                'Führe über die Tage kurz Buch – das Tool kann dafür eine Sammlung oder Gewohnheit sein.',
            ],
        ],
        'work-costs' => [
            'category' => 'taxes',
            'title' => 'Werbungskosten sammeln',
            'summary' => 'Fahrtkosten, Arbeitsmittel, Fortbildung, Bewerbungen und Fachliteratur senken die Steuer.',
            'body' => [
                'Arbeitsmittel bis 800 € netto kannst du sofort voll absetzen. Die Entfernungspauschale gilt pro Arbeitstag für die einfache Strecke – unabhängig vom Verkehrsmittel.',
                'Markiere passende Buchungen in Finanzen mit einer Steuer-Kategorie, dann erscheinen sie in der Steuerjahr-Übersicht.',
            ],
        ],
        'household-services' => [
            'category' => 'taxes',
            'title' => 'Handwerker und Haushaltshilfe absetzen',
            'summary' => '20 % der Arbeitskosten direkt von der Steuer abziehen – für Handwerker bis 1.200 €, für haushaltsnahe Dienste bis 4.000 € im Jahr.',
            'body' => [
                'Es zählen nur Arbeits-, Maschinen- und Fahrtkosten, keine Materialkosten – deshalb sollten sie auf der Rechnung getrennt ausgewiesen sein. Bezahle per Überweisung, Barzahlungen werden nicht anerkannt (§ 35a EStG, Stand 2025).',
                'Auch die Nebenkostenabrechnung der Mietwohnung enthält oft solche Posten (Hausmeister, Gartenpflege, Treppenreinigung).',
            ],
        ],
        'energy-renovation' => [
            'category' => 'taxes',
            'title' => 'Energetische Sanierung im Eigenheim',
            'summary' => 'Für selbst genutztes Wohneigentum: 20 % der Kosten über drei Jahre verteilt von der Steuer abziehen (§ 35c EStG).',
            'body' => [
                'Gefördert werden z. B. Dämmung, Fenster, Heizung. Die ausführende Fachfirma muss eine Bescheinigung nach amtlichem Muster ausstellen. Eine Doppelförderung mit KfW- oder BAFA-Zuschüssen für dieselbe Maßnahme ist ausgeschlossen – vorher rechnen, was sich mehr lohnt.',
            ],
        ],
        'rental-costs' => [
            'category' => 'taxes',
            'title' => 'Vermietung: alle Werbungskosten erfassen',
            'summary' => 'Zinsen, Abschreibung, Verwaltung, Reparaturen und Fahrten zur Wohnung mindern die Steuer auf Mieteinnahmen.',
            'body' => [
                'Achtung bei Sanierungen in den ersten drei Jahren nach dem Kauf: Übersteigen sie netto 15 % des Gebäudewerts, werden sie zu „anschaffungsnahen Herstellungskosten“ und nur über die Abschreibung berücksichtigt.',
                'Die Steuer-Ansicht im Immobilienbereich rechnet das für dich vor.',
            ],
        ],
        'tax-return-deadline' => [
            'category' => 'taxes',
            'title' => 'Freiwillige Steuererklärung lohnt sich oft',
            'summary' => 'Wer nicht zur Abgabe verpflichtet ist, hat vier Jahre Zeit – und bekommt häufig Geld zurück.',
            'body' => [
                'Arbeitnehmer bekommen im Schnitt eine Erstattung. Für freiwillige Erklärungen gilt eine Frist von vier Jahren ab Ende des Steuerjahres.',
                'Die Checklisten-Vorlage „Steuererklärung“ im Tool hilft beim Sammeln der Unterlagen.',
            ],
        ],

        // ── Wealth & cash flow (in the spirit of "Rich Dad Poor Dad") ─
        'assets-vs-liabilities' => [
            'category' => 'wealth',
            'title' => 'Vermögenswert oder Verbindlichkeit?',
            'summary' => 'Ein Vermögenswert bringt dir Geld ein, eine Verbindlichkeit kostet dich jeden Monat Geld.',
            'body' => [
                'So definiert es Robert Kiyosaki in „Rich Dad Poor Dad“: Aktien mit Dividenden, vermietete Wohnungen oder ein Business sind Vermögenswerte. Das selbst genutzte Haus, das Auto oder teure Hobbys sind nach dieser Sicht Verbindlichkeiten – sie verursachen laufende Kosten.',
                'Die Cashflow-Analyse im Tool sortiert deine Werte genau so ein.',
            ],
        ],
        'pay-yourself-first' => [
            'category' => 'wealth',
            'title' => 'Zahle dich zuerst selbst',
            'summary' => 'Direkt nach dem Gehaltseingang einen festen Betrag in Vermögenswerte stecken – nicht was am Monatsende übrig bleibt.',
            'body' => [
                'Ein Dauerauftrag am Tag nach dem Gehalt auf das Depot- oder Sparkonto macht Sparen automatisch. Was übrig bleibt, darfst du ausgeben.',
            ],
        ],
        'passive-income' => [
            'category' => 'wealth',
            'title' => 'Passives Einkommen aufbauen',
            'summary' => 'Finanziell frei bist du, wenn Einnahmen aus Vermögenswerten deine Ausgaben decken.',
            'body' => [
                'Dividenden, Zinsen, Mieteinnahmen und Gewinne aus einem Business zählen dazu. Verfolge die Quote „passives Einkommen ÷ Ausgaben“ – sie ist dein Fortschritt aus dem „Hamsterrad“.',
            ],
        ],
        'lifestyle-inflation' => [
            'category' => 'wealth',
            'title' => 'Gehaltserhöhungen nicht verkonsumieren',
            'summary' => 'Steigt das Einkommen, steigen oft die Ausgaben gleich mit – dann bleibt die Sparquote gleich.',
            'body' => [
                'Lege einen Teil jeder Gehaltserhöhung sofort zusätzlich in deinen Sparplan. So wächst dein Vermögen schneller, ohne dass du Verzicht spürst.',
            ],
        ],
        // ── From Robert Kiyosaki's other books ─────────────────────
        'cashflow-quadrant' => [
            'category' => 'richdad',
            'title' => 'Der Cashflow-Quadrant: E, S, B und I',
            'summary' => 'Angestellte (E) und Selbstständige (S) tauschen Zeit gegen Geld – Unternehmer (B) und Investoren (I) lassen Systeme und Kapital arbeiten.',
            'body' => [
                'In „Rich Dad’s Cashflow Quadrant“ teilt Kiyosaki Einkommen danach ein, woher es kommt: E = Employee (Gehalt), S = Self-employed (du bist das Geschäft), B = Business Owner (ein System läuft auch ohne dich), I = Investor (dein Geld arbeitet).',
                'Finanzielle Freiheit entsteht fast immer auf der rechten Seite. Der Weg führt meist über ein Standbein im I-Quadranten – etwa ein Depot oder eine vermietete Wohnung – neben dem Job.',
                'Die Cashflow-Analyse zeigt dir, wie sich dein Einkommen heute auf die vier Quadranten verteilt.',
            ],
        ],
        'five-financial-iqs' => [
            'category' => 'richdad',
            'title' => 'Die fünf Finanz-IQs',
            'summary' => 'Mehr verdienen, Geld schützen, gut budgetieren, Geld hebeln und dein Finanzwissen verbessern.',
            'body' => [
                'Aus „Increase Your Financial IQ“: 1. Mehr Geld verdienen. 2. Dein Geld schützen – vor allem vor unnötigen Steuern und Risiken. 3. Budgetieren – gut leben und trotzdem investieren. 4. Hebeln – wie viel Rendite dein Überschuss erwirtschaftet. 5. Finanzinformation verbessern – verstehen, worin du investierst.',
                'Prüfe dich jedes Jahr: In welchem der fünf Bereiche hast du den größten Hebel?',
            ],
        ],
        'wealth-in-days' => [
            'category' => 'richdad',
            'title' => 'Wohlstand misst man in Tagen',
            'summary' => 'Wie viele Tage könntest du leben, ohne zu arbeiten? Diese Zahl ist dein Wohlstand.',
            'body' => [
                'Kiyosaki definiert Wohlstand nicht in Euro, sondern in Zeit: Einkommen aus Vermögenswerten geteilt durch die monatlichen Ausgaben. Deckt es die Ausgaben vollständig, bist du frei – egal wie hoch das Vermögen auf dem Papier ist.',
                'Die Kachel „Wohlstand pro Monat“ zeigt, wie viele Tage im Monat dein Vermögensertrag schon abdeckt.',
            ],
        ],
        'good-vs-bad-debt' => [
            'category' => 'richdad',
            'title' => 'Gute Schulden, schlechte Schulden',
            'summary' => 'Gute Schulden bezahlt jemand anderes – etwa der Mieter. Schlechte Schulden zahlst du selbst.',
            'body' => [
                'Ein Kredit für eine vermietete Wohnung, deren Miete Zins und Tilgung trägt, ist nach Kiyosaki gute Schuld. Ratenkredite für Auto, Urlaub oder Konsum sind schlechte Schulden – sie nehmen Geld aus deiner Tasche.',
                'Auch gute Schulden sind ein Risiko: Leerstand oder steigende Zinsen können sie schnell zu schlechten machen. Plane immer eine Reserve ein.',
            ],
        ],
        'doodads' => [
            'category' => 'richdad',
            'title' => 'Doodads – Scheinvermögen',
            'summary' => 'Dinge, die sich wie Vermögen anfühlen, aber jeden Monat Geld kosten: großes Auto, Boot, Luxusartikel.',
            'body' => [
                'Kiyosaki nennt sie „Doodads“. Sie stehen oft auf der Vermögensseite der klassischen Bilanz, bringen aber kein Geld ein. Die reichen Leute kaufen sie erst, wenn ihre Vermögenswerte sie bezahlen.',
                'Im Tool werden solche Werte getrennt ausgewiesen – dein „Nettovermögen nach Rich Dad“ zählt sie nicht mit.',
            ],
        ],
        'mind-your-own-business' => [
            'category' => 'richdad',
            'title' => 'Kümmere dich um dein eigenes Geschäft',
            'summary' => 'Dein Geschäft ist deine Vermögensspalte – nicht dein Arbeitgeber.',
            'body' => [
                'Behalte den Job, aber baue nebenbei Vermögenswerte auf, die dir gehören: Depot, Immobilien, ein kleines Business, Lizenzen. Jeder Euro Überschuss sollte dort landen, bevor er in Verbindlichkeiten fließt.',
            ],
        ],
        'rat-race' => [
            'category' => 'richdad',
            'title' => 'Raus aus dem Hamsterrad',
            'summary' => 'Mehr Gehalt allein befreit nicht – wenn die Ausgaben mitwachsen, dreht sich das Rad nur schneller.',
            'body' => [
                'Im „Rat Race“ steckt, wer jede Gehaltserhöhung in höhere Ausgaben und Kredite steckt. Ausweg: Überschüsse konsequent in Vermögenswerte lenken, bis deren Einkommen die Ausgaben übersteigt.',
                'Der Balken „Raus aus dem Hamsterrad“ zeigt deinen Fortschritt.',
            ],
        ],
        'unfair-advantage' => [
            'category' => 'richdad',
            'title' => 'Die fünf unfairen Vorteile',
            'summary' => 'Wissen, Steuern, Schulden, Risiko und Vergütung – wer sie versteht, hat einen Vorsprung.',
            'body' => [
                'In „Unfair Advantage“ beschreibt Kiyosaki fünf Vorteile finanzieller Bildung: Wissen, legale Steuergestaltung (etwa Abschreibungen bei Immobilien), kluger Einsatz von Schulden, Risiko durch Wissen senken statt meiden, und Einkommen aus Vermögenswerten statt nur aus Arbeit.',
                'Für Deutschland heißt das zum Beispiel: AfA und Werbungskosten bei Vermietung kennen, Verlustverrechnung nutzen und Kredite nur für Vermögenswerte aufnehmen.',
            ],
        ],
        'investor-levels' => [
            'category' => 'richdad',
            'title' => 'Die 90/10-Regel und Anlegertypen',
            'summary' => 'Laut „Rich Dad’s Guide to Investing“ besitzen 10 % der Menschen 90 % des Geldes – der Unterschied liegt vor allem in Wissen und Plan.',
            'body' => [
                'Kiyosaki beschreibt Anleger in Stufen – vom „Ich habe kein Geld zum Anlegen“ über Sparer und „Ich überlasse das anderen“ bis zum informierten Investor, der sein Vermögen selbst plant.',
                'Der erste Schritt ist ein schriftlicher Finanzplan: Wie viel passives Einkommen brauchst du, bis wann, und mit welchen Vermögenswerten willst du dorthin?',
            ],
        ],
        'financial-education-kids' => [
            'category' => 'richdad',
            'title' => 'Finanzbildung für Kinder',
            'summary' => 'Kinder lernen den Umgang mit Geld am besten zu Hause – mit Taschengeld, eigenen Zielen und Gesprächen.',
            'body' => [
                'In „Rich Kid Smart Kid“ empfiehlt Kiyosaki, Kinder früh mit Geld spielen zu lassen: Taschengeld aufteilen (ausgeben, sparen, spenden), ein erstes Sparziel verfolgen, das Brettspiel „Cashflow“ spielen und Fragen stellen wie „Ist das ein Vermögenswert oder eine Verbindlichkeit?“.',
            ],
        ],

    ],

    'disclaimer' => 'Allgemeine Informationen, keine Steuer-, Rechts- oder Anlageberatung. Beträge und Grenzen ändern sich – im Zweifel aktuelle Regeln prüfen oder Beratung einholen.',
];
