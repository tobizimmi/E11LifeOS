<?php

namespace App\Support\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads a table from a CSV file (any delimiter, UTF-8 or Windows-1252) or the first sheet of an
 * Excel .xlsx file. The first non-empty row is the header.
 */
class TableFileReader
{
    public const MAX_ROWS = 2000;

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function read(string $path, string $originalName): array
    {
        $rows = str_ends_with(strtolower($originalName), '.xlsx') ? $this->xlsxRows($path) : $this->csvRows((string) file_get_contents($path));
        $rows = array_values(array_filter($rows, fn (array $row) => implode('', array_map('trim', $row)) !== ''));

        if (count($rows) < 2) {
            throw new RuntimeException(__('The file needs a header row and at least one row of data.'));
        }

        $headers = array_map(fn ($value) => trim((string) $value), array_shift($rows));
        $width = count($headers);

        foreach ($headers as $index => $header) {
            $headers[$index] = $header !== '' ? mb_substr($header, 0, 100) : __('Column :number', ['number' => $index + 1]);
        }

        $rows = array_map(fn (array $row) => array_map(fn ($value) => trim((string) $value), array_slice(array_pad($row, $width, ''), 0, $width)), array_slice($rows, 0, self::MAX_ROWS));

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csvRows(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($contents, "\n") ?: '';
        $delimiter = collect([';', ',', "\t", '|'])->sortByDesc(fn (string $candidate) => substr_count($firstLine, $candidate))->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($value) => (string) $value, $row);

            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        fclose($stream);

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function xlsxRows(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('Excel files cannot be read on this server – please save the table as CSV.'));
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('The Excel file could not be opened.'));
        }

        $strings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

        if ($sharedXml !== false) {
            foreach ($this->xml($sharedXml)->si as $item) {
                // Rich text consists of several runs.
                $strings[] = isset($item->t) ? (string) $item->t : implode('', array_map(fn ($run) => (string) $run->t, iterator_to_array($item->r, false)));
            }
        }

        $sheetXml = $zip->getFromName($this->firstSheetPath($zip));
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException(__('The Excel file contains no table.'));
        }

        $rows = [];

        foreach ($this->xml($sheetXml)->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $column = (string) $cell['r'] !== '' ? $this->columnIndex((string) $cell['r']) : count($cells);
                $type = (string) $cell['t'];
                $cells[$column] = match ($type) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => (string) $cell->is->t,
                    'b' => (string) $cell->v === '1' ? '1' : '0',
                    default => (string) $cell->v,
                };
            }

            if ($cells !== []) {
                $filled = array_fill(0, max(array_keys($cells)) + 1, '');
                $rows[] = array_replace($filled, $cells);
            }

            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $relations !== false) {
            $sheet = $this->xml($workbook)->sheets->sheet[0] ?? null;
            $relationId = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';

            foreach ($this->xml($relations)->Relationship as $relation) {
                if ((string) $relation['Id'] === $relationId) {
                    $target = ltrim((string) $relation['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($reference));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function xml(string $contents): SimpleXMLElement
    {
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false) {
            throw new RuntimeException(__('The Excel file could not be read.'));
        }

        return $xml;
    }
}
