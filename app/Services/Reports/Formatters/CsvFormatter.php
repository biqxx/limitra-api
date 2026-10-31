<?php

namespace App\Services\Reports\Formatters;

class CsvFormatter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function format(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open memory buffer for CSV generation.');
        }

        // Add UTF-8 BOM so Excel opens UTF-8 encoded files with correct character display
        fwrite($handle, "\xEF\xBB\xBF");

        // Write sanitized headers
        fputcsv($handle, array_map([$this, 'sanitizeValue'], $headers));

        foreach ($rows as $row) {
            $formattedRow = [];
            foreach ($headers as $header) {
                $val = $row[$header] ?? '';
                $formattedRow[] = $this->sanitizeValue($val);
            }
            fputcsv($handle, $formattedRow);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }

    private function sanitizeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $str = (string) $value;

        // Prevent CSV formula injection in spreadsheet viewers
        if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $str = "'".$str;
        }

        return $str;
    }
}
