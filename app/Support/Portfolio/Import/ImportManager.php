<?php

namespace App\Support\Portfolio\Import;

class ImportManager
{
    private static array $parsers = [
        'trade_republic' => TradeRepublicParser::class,
        'dkb' => DKBParser::class,
        'ing' => INGParser::class,
        'smartbroker' => SmartBrokerParser::class,
        'comdirect' => ComdirectParser::class,
        'consorsbank' => ConsorsParser::class,
        'flatex' => FlatexParser::class,
        'onvista' => OnVistaParser::class,
        'outbank' => OutbankParser::class,
        'finanzguru' => FinanzguruParser::class,
        'generic' => GenericCsvParser::class,
    ];

    /**
     * CSV-Inhalt parsen und Transaktionsliste zurückgeben.
     *
     * @return array ['transactions' => [...], 'errors' => [...], 'skipped' => int]
     */
    public static function parse(string $csvContent, string $broker): array
    {
        $parserClass = self::$parsers[$broker] ?? self::$parsers['generic'];
        /** @var AbstractParser $parser */
        $parser = new $parserClass;

        return $parser->parse($csvContent);
    }

    /**
     * Auto-Erkennung des Brokers anhand der CSV-Header
     */
    public static function detectBroker(string $csvContent): string
    {
        // BOM entfernen und erste 10 Zeilen für die Erkennung nutzen
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent);
        $firstLines = implode("\n", array_slice(explode("\n", $content), 0, 10));

        // Trade Republic: expliziter Name ODER typisches Spaltenformat
        // Format: Datum;Typ;Symbol;ISIN;... oder Datum;Typ;ISIN;Symbol;...
        if (stripos($firstLines, 'trade republic') !== false
         || preg_match('/^Datum;Typ;(?:Symbol|ISIN|Ticker)/im', $firstLines)
         || preg_match('/^Datum;Typ;[^;]+;(?:Symbol|ISIN)/im', $firstLines)) {
            return 'trade_republic';
        }

        // DKB
        if (stripos($firstLines, 'DKB') !== false
         || stripos($firstLines, 'Deutsche Kreditbank') !== false
         || preg_match('/^"Kontonummer:"/im', $firstLines)) {
            return 'dkb';
        }

        // SmartBroker (vor ING prüfen, da kein Namens-Overlap)
        if (stripos($firstLines, 'SmartBroker') !== false
         || stripos($firstLines, 'Baader Bank') !== false) {
            return 'smartbroker';
        }

        // Finanzguru (vor ING, weil Format eindeutig)
        if (stripos($firstLines, 'Finanzguru') !== false
         || (stripos($firstLines, 'Buchungsdatum') !== false && stripos($firstLines, 'Kategorie') !== false)) {
            return 'finanzguru';
        }

        // Outbank
        if (stripos($firstLines, 'Outbank') !== false
         || (stripos($firstLines, 'Verwendungszweck') !== false
          && stripos($firstLines, 'Konto') !== false
          && stripos($firstLines, 'Bank') !== false)) {
            return 'outbank';
        }

        // comdirect
        if (stripos($firstLines, 'comdirect') !== false
         || preg_match('/^"?Buchungstag"?;"?Umsatzart"?/im', $firstLines)
         || preg_match('/comdirect bank/im', $firstLines)) {
            return 'comdirect';
        }

        // Consorsbank / BNP Paribas
        if (stripos($firstLines, 'consors') !== false
         || stripos($firstLines, 'BNP Paribas') !== false
         || preg_match('/^"?Orderreferenz"?;/im', $firstLines)
         || preg_match('/Handelsdatum.*Produktart/im', $firstLines)) {
            return 'consorsbank';
        }

        // flatex
        if (stripos($firstLines, 'flatex') !== false
         || stripos($firstLines, 'FinTech Group') !== false
         || preg_match('/^"?Buchungsdatum"?;"?Geschäftsart"?;"?Wertpapierbezeichnung"?/im', $firstLines)) {
            return 'flatex';
        }

        // OnVista
        if (stripos($firstLines, 'onvista') !== false
         || stripos($firstLines, 'OnVista Bank') !== false
         || preg_match('/^"?Buchungsdatum"?;"?Geschäftsart"?;"?Wertpapier"?;"?ISIN"?/im', $firstLines)) {
            return 'onvista';
        }

        // ING – LETZTER Check, weil "ING" in Firmennamen sehr häufig vorkommt.
        // Nur matchen wenn eindeutige ING-Kennzeichen vorhanden sind.
        if (stripos($firstLines, 'ING-DiBa') !== false
         || preg_match('/\bING\b/', $firstLines)  // Ganzes Wort "ING"
         || preg_match('/Umsätze EUR-Konto/im', $firstLines)
         || preg_match('/^Wertpapier;ISIN;WKN;Anzahl/im', $firstLines)) {
            return 'ing';
        }

        return 'generic';
    }
}
