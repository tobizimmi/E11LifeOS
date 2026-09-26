<?php

namespace App\Support\Portfolio\Import;

/**
 * ING (ehemals ING-DiBa) Broker CSV-Export Parser
 *
 * ING exportiert mit Semikolon-Trennung, die ersten Zeilen enthalten Kontodaten.
 * Format: Buchungstag;Wertstellung;Auftraggeber/Empfänger;Buchungstext;Verwendungszweck;Betrag;...
 * Depot-Export: Wertpapier;ISIN;WKN;Anzahl;Kaufkurs;Akt. Kurs;Kurszeit;Wert;Währung
 */
class INGParser extends AbstractParser
{
    private const TYPE_MAP = [
        'kauf' => 'buy',
        'sparplan' => 'buy',
        'dividende' => 'dividend',
        'ausschüttung' => 'dividend',
        'zinsen' => 'dividend',
        'verkauf' => 'sell',
        'einbuchung' => 'transfer_in',
        'ausbuchung' => 'transfer_out',
    ];

    public function parse(string $csvContent): array
    {
        $rows = $this->parseCsvLines($csvContent, ';');

        $headerIdx = -1;
        $header = [];

        foreach ($rows as $i => $row) {
            $norm = array_map(fn ($c) => strtolower(trim(str_replace('"', '', $c))), $row);

            // Transaktionshistorie-Format
            if ((in_array('isin', $norm) || in_array('wertpapier', $norm))
                && (in_array('anzahl', $norm) || in_array('menge', $norm) || in_array('stück', $norm))) {
                $header = $norm;
                $headerIdx = $i;
                break;
            }
        }

        if ($headerIdx < 0) {
            $this->errors[] = __('No :broker format recognised. Please export the securities account transactions.', ['broker' => 'ING']);

            return $this->result([]);
        }

        $colMap = array_flip($header);
        $transactions = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = array_map(fn ($c) => trim(str_replace('"', '', $c)), $rows[$i]);
            if (count($row) < 4 || empty($row[0])) {
                continue;
            }

            try {
                // ING-Format: erste Spalte ist oft der Typ oder Wertpapierbezeichnung
                $rawType = strtolower($row[$colMap['art'] ?? $colMap['typ'] ?? $colMap['buchungstext'] ?? $colMap['vorgang'] ?? 0] ?? '');
                $type = null;

                foreach (self::TYPE_MAP as $k => $v) {
                    if (str_contains($rawType, $k)) {
                        $type = $v;
                        break;
                    }
                }
                if (! $type) {
                    $type = 'buy';
                } // ING-Depot-Export: alles Positionen = Bestand

                $name = $row[$colMap['wertpapier'] ?? $colMap['bezeichnung'] ?? $colMap['name'] ?? 0] ?? '';
                $isin = $row[$colMap['isin'] ?? 1] ?? '';
                $qty = $this->parseAmount($row[$colMap['anzahl'] ?? $colMap['menge'] ?? $colMap['stück'] ?? 3] ?? '0');
                $price = $this->parseAmount($row[$colMap['kurs'] ?? $colMap['kaufkurs'] ?? $colMap['preis'] ?? 4] ?? '0');
                $fees = $this->parseAmount($row[$colMap['provision'] ?? $colMap['gebühren'] ?? $colMap['kosten'] ?? ''] ?? '0');
                $curr = strtoupper($row[$colMap['währung'] ?? $colMap['ccy'] ?? ''] ?? 'EUR');
                $rawDate = $row[$colMap['buchungstag'] ?? $colMap['datum'] ?? $colMap['buchungsdatum'] ?? ''] ?? '';
                $date = $this->parseGermanDate($rawDate) ?? date('Y-m-d');

                if (! $isin && ! $name) {
                    $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);

                    continue;
                }

                $symbol = $isin ?: preg_replace('/[^A-Z0-9.]/', '', strtoupper($name));

                $transactions[] = [
                    'symbol' => $symbol,
                    'name' => $name,
                    'isin' => $isin ?: null,
                    'type' => $type,
                    'quantity' => abs($qty),
                    'price' => abs($price),
                    'fees' => abs($fees),
                    'taxes' => 0,
                    'currency' => $curr ?: 'EUR',
                    'exchange_rate' => 1,
                    'transaction_date' => $date,
                    'broker' => 'ING',
                ];
            } catch (\Throwable $e) {
                $this->errors[] = __('Line :line: invalid data.', ['line' => $i]);
            }
        }

        return $this->result($transactions);
    }
}
