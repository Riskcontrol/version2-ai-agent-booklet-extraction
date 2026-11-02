<?php

namespace App\Services;

use App\Models\VerificationBatch;
use App\Models\VerificationRecord;
use App\Models\VerificationResult;
use ZipArchive;

class VerificationExportService
{
    private function rows(VerificationBatch $batch): array
    {
        $summary = [['Certificate name', 'Report name', 'Qualification', 'Status', 'Details', 'Certificate file',
            'Certificate page', 'Report file', 'Report page', 'Manual pairing']];
        $details = [['Certificate name', 'Field', 'Certificate value', 'Report value', 'Comparison', 'Certificate file', 'Certificate page', 'Report file', 'Report page']];
        $results = VerificationResult::where('batch_id', $batch->id)->with(['certificate', 'report'])->orderBy('id')->get();
        foreach ($results as $result) {
            $c = $result->certificate;
            $r = $result->report;
            $summary[] = [$c->fields['name'] ?? '', $r?->fields['name'] ?? '', $c->fields['qualification'] ?? '',
                $result->status, $result->reason, $c->filename, $c->page, $r?->filename ?? '', $r?->page ?? '', $result->manual ? 'Yes' : 'No'];
            foreach ($result->comparisons as $comparison) {
                $details[] = [$c->fields['name'] ?? '', $comparison['field'], $comparison['certificate'], $comparison['report'],
                    $comparison['state'], $c->filename, $c->page, $r?->filename ?? '', $r?->page ?? ''];
            }
        }
        $used = $results->pluck('report_id')->filter()->all();
        foreach (VerificationRecord::where('batch_id', $batch->id)->where('role', 'report')->whereNotIn('id', $used)->get() as $report) {
            $summary[] = ['', $report->fields['name'] ?? '', $report->fields['qualification'] ?? '', 'Needs review',
                'Report has no paired certificate.', '', '', $report->filename, $report->page, 'No'];
        }
        return [$summary, $details];
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="10" width="30" customWidth="1"/></cols><sheetData>';
        foreach ($rows as $index => $row) {
            $xml .= '<row r="'.($index + 1).'">';
            foreach ($row as $column => $value) {
                $letter = chr(65 + $column);
                $xml .= '<c r="'.$letter.($index + 1).'" t="inlineStr" s="'.($index === 0 ? '1' : '0').'"><is><t xml:space="preserve">'.$this->xml((string) $value).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        return $xml.'</sheetData><autoFilter ref="A1:'.chr(64 + count($rows[0])).count($rows).'"/></worksheet>';
    }

    public function download(VerificationBatch $batch, string $format)
    {
        [$summary, $details] = $this->rows($batch);
        $filename = 'verification-'.$batch->id.'-revision-'.$batch->revision;
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($summary) {
                $stream = fopen('php://output', 'w');
                foreach ($summary as $row) {
                    // Spreadsheet applications must not execute document text as formulas.
                    $safe = array_map(fn ($v) => preg_match('/^[\s]*[=+@\-]/u', (string) $v) ? "'".$v : $v, $row);
                    fputcsv($stream, $safe, ',', '"', '');
                }
                fclose($stream);
            }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $path = tempnam(sys_get_temp_dir(), 'verification-xlsx-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            abort(500, 'Could not create Excel download.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Results" sheetId="1" r:id="rId1"/><sheet name="Field comparisons" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($summary));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheet($details));
        $zip->close();
        return response()->download($path, $filename.'.xlsx', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
