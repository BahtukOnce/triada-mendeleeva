<?php
declare(strict_types=1);

// Минимальный читатель XLSX (значения ячеек, без стилей).
// Возвращает: ['Имя листа' => [rowNum => [colNum => value]]], нумерация с 1.

function xlsx_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n;
}

function xlsx_load(string $path, ?callable $sheetFilter = null): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("xlsx: cannot open $path");
    }

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $txt = '';
                foreach ($si->r as $r) {
                    $txt .= (string)$r->t;
                }
                $shared[] = $txt;
            }
        }
    }

    $wb = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
    $rels = new SimpleXMLElement($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    foreach ($rels->Relationship as $rel) {
        $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    }

    $result = [];
    foreach ($wb->sheets->sheet as $sheet) {
        $name = (string)$sheet['name'];
        if ($sheetFilter !== null && !$sheetFilter($name)) {
            continue;
        }
        $rid = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $target = $relMap[$rid] ?? '';
        if ($target === '') {
            continue;
        }
        if (!str_starts_with($target, 'xl/')) {
            $target = 'xl/' . ltrim($target, '/');
        }
        $xml = $zip->getFromName($target);
        if ($xml === false) {
            continue;
        }
        $result[$name] = xlsx_parse_sheet($xml, $shared);
    }
    $zip->close();
    return $result;
}

function xlsx_parse_sheet(string $xml, array $shared): array
{
    $rows = [];
    $reader = new XMLReader();
    $reader->XML($xml);
    $curRow = 0;
    $curCol = 0;
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }
        if ($reader->name === 'row') {
            $curRow = (int)$reader->getAttribute('r') ?: ($curRow + 1);
            $curCol = 0;
        } elseif ($reader->name === 'c') {
            $ref = $reader->getAttribute('r');
            if ($ref && preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                $curCol = xlsx_col_index($m[1]);
            } else {
                $curCol++;
            }
            $type = $reader->getAttribute('t') ?? '';
            $cellXml = $reader->readOuterXml();
            $val = null;
            if ($type === 'inlineStr') {
                if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $cellXml, $m2)) {
                    $val = html_entity_decode($m2[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                }
            } elseif (preg_match('/<v>(.*?)<\/v>/s', $cellXml, $m2)) {
                $raw = html_entity_decode($m2[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                if ($type === 's') {
                    $val = $shared[(int)$raw] ?? '';
                } elseif ($type === 'b') {
                    $val = $raw === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $val = $raw;
                }
            }
            if ($val !== null && $val !== '') {
                $rows[$curRow][$curCol] = $val;
            }
        }
    }
    $reader->close();
    return $rows;
}

// Значение ячейки (1-based row/col), '' если пусто
function xc(array $sheet, int $row, int $col): string
{
    return trim((string)($sheet[$row][$col] ?? ''));
}
