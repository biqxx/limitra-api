<?php

namespace App\Services\Reports\Formatters;

use RuntimeException;
use ZipArchive;

class XlsxFormatter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public function format(array $headers, iterable $rows): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_report_');
        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary file for XLSX report.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempFile);
            throw new RuntimeException('Failed to initialize ZipArchive for XLSX export.');
        }

        // 1. [Content_Types].xml
        $contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;
        $zip->addFromString('[Content_Types].xml', trim($contentTypes));

        // 2. _rels/.rels
        $rootRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
        $zip->addFromString('_rels/.rels', trim($rootRels));

        // 3. xl/_rels/workbook.xml.rels
        $workbookRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;
        $zip->addFromString('xl/_rels/workbook.xml.rels', trim($workbookRels));

        // 4. xl/workbook.xml
        $workbook = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Report" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
        $zip->addFromString('xl/workbook.xml', trim($workbook));

        // 5. xl/worksheets/sheet1.xml
        $sheetXml = $this->buildWorksheetXml($headers, $rows);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();

        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($content === false) {
            throw new RuntimeException('Failed to read generated XLSX file.');
        }

        return $content;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    private function buildWorksheetXml(array $headers, iterable $rows): string
    {
        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            throw new RuntimeException('Failed to open memory buffer for worksheet XML.');
        }

        fwrite($buffer, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n");
        fwrite($buffer, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'."\n");
        fwrite($buffer, '<sheetData>'."\n");

        // Row 1: Header row
        $rowIndex = 1;
        fwrite($buffer, '<row r="1">'."\n");
        foreach ($headers as $colIndex => $header) {
            $cellRef = $this->columnLetter($colIndex).$rowIndex;
            $escaped = htmlspecialchars((string) $header, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            fwrite($buffer, '<c r="'.$cellRef.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>'."\n");
        }
        fwrite($buffer, '</row>'."\n");

        // Subsequent rows
        foreach ($rows as $row) {
            $rowIndex++;
            fwrite($buffer, '<row r="'.$rowIndex.'">'."\n");
            foreach ($headers as $colIndex => $header) {
                $cellRef = $this->columnLetter($colIndex).$rowIndex;
                $val = $row[$header] ?? '';

                if ($val === null || $val === '') {
                    fwrite($buffer, '<c r="'.$cellRef.'" t="inlineStr"><is><t></t></is></c>'."\n");
                } elseif (is_numeric($val) && ! str_starts_with((string) $val, '0') || $val === '0' || $val === 0) {
                    fwrite($buffer, '<c r="'.$cellRef.'"><v>'.(string) $val.'</v></c>'."\n");
                } else {
                    if (is_bool($val)) {
                        $strVal = $val ? 'true' : 'false';
                    } elseif (is_array($val) || is_object($val)) {
                        $strVal = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $strVal = (string) $val;
                    }
                    $escaped = htmlspecialchars($strVal, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    fwrite($buffer, '<c r="'.$cellRef.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>'."\n");
                }
            }
            fwrite($buffer, '</row>'."\n");
        }

        fwrite($buffer, '</sheetData>'."\n");
        fwrite($buffer, '</worksheet>'."\n");

        rewind($buffer);
        $result = stream_get_contents($buffer);
        fclose($buffer);

        return $result !== false ? $result : '';
    }

    private function columnLetter(int $colIndex): string
    {
        $letter = '';
        while ($colIndex >= 0) {
            $letter = chr(($colIndex % 26) + 65).$letter;
            $colIndex = intdiv($colIndex, 26) - 1;
        }

        return $letter;
    }
}
