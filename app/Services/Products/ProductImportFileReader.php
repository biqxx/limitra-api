<?php

namespace App\Services\Products;

use RuntimeException;
use ZipArchive;

class ProductImportFileReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function read(string $filePath, string $format): array
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Import file [{$filePath}] does not exist.");
        }

        return match (strtolower($format)) {
            'xlsx' => $this->readXlsx($filePath),
            default => $this->readCsv($filePath),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open CSV file [{$filePath}].");
        }

        // Strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if ($header === false || empty($header)) {
            fclose($handle);

            return [];
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                continue; // skip completely empty lines
            }

            $rowCount = count($row);
            $headerCount = count($headers);

            if ($rowCount < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif ($rowCount > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }

            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readXlsx(string $filePath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Failed to read XLSX package.');
        }

        // 1. Read shared strings if present
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml !== false) {
            $xml = @simplexml_load_string($sharedStringsXml);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // 2. Read sheet1.xml
        $sheetXmlContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXmlContent === false) {
            return [];
        }

        $sheetXml = @simplexml_load_string($sheetXmlContent);
        if ($sheetXml === false || ! isset($sheetXml->sheetData->row)) {
            return [];
        }

        $matrix = [];
        foreach ($sheetXml->sheetData->row as $row) {
            $rowIndex = (int) $row['r'];
            $rowValues = [];

            foreach ($row->c as $cell) {
                $cellRef = (string) $cell['r'];
                $colLetters = preg_replace('/[0-9]/', '', $cellRef);
                $colIndex = $this->columnLetterToIndex($colLetters);

                $cellType = (string) $cell['t'];
                $val = '';

                if ($cellType === 's') { // shared string
                    $sIndex = (int) $cell->v;
                    $val = $sharedStrings[$sIndex] ?? '';
                } elseif ($cellType === 'inlineStr') {
                    $val = (string) $cell->is->t;
                } elseif (isset($cell->v)) {
                    $val = (string) $cell->v;
                }

                $rowValues[$colIndex] = trim($val);
            }

            if (! empty($rowValues)) {
                $maxCol = max(array_keys($rowValues));
                $normalizedRow = [];
                for ($i = 0; $i <= $maxCol; $i++) {
                    $normalizedRow[$i] = $rowValues[$i] ?? '';
                }
                $matrix[$rowIndex] = $normalizedRow;
            }
        }

        if (empty($matrix)) {
            return [];
        }

        ksort($matrix);
        $headerRow = array_shift($matrix);
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headerRow);

        $results = [];
        $headerCount = count($headers);

        foreach ($matrix as $row) {
            $rowCount = count($row);
            if ($rowCount < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif ($rowCount > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }

            if (! empty(array_filter($row, fn ($val) => trim((string) $val) !== ''))) {
                $results[] = array_combine($headers, $row);
            }
        }

        return $results;
    }

    private function columnLetterToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        $len = strlen($letters);

        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
