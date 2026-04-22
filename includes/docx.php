<?php

/**
 * Minimal .docx generator (WordprocessingML) without external libraries.
 * Produces a DOCX containing a single table.
 */

function docxEscapeText(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function docxCell(string $text, int $widthTwips = 0, bool $bold = false, int $fontSizeHalfPoints = 18): string
{
    $safe = docxEscapeText($text);
    $w = $widthTwips > 0 ? '<w:tcW w:w="' . (int) $widthTwips . '" w:type="dxa"/>' : '<w:tcW w:w="0" w:type="auto"/>';
    $fontSize = max(14, (int) $fontSizeHalfPoints);
    $rPr = '<w:rPr>'
        . ($bold ? '<w:b/>' : '')
        . '<w:sz w:val="' . $fontSize . '"/>'
        . '<w:szCs w:val="' . $fontSize . '"/>'
        . '</w:rPr>';

    return '<w:tc>'
        . '<w:tcPr>' . $w . '</w:tcPr>'
        . '<w:p><w:r>' . $rPr . '<w:t xml:space="preserve">' . $safe . '</w:t></w:r></w:p>'
        . '</w:tc>';
}

function docxTableRow(array $cellsXml): string
{
    return '<w:tr>' . implode('', $cellsXml) . '</w:tr>';
}

function docxBuildDocumentXmlWithTable(
    string $title,
    array $headers,
    array $rows,
    array $columnWidthsTwips = [],
    bool $landscape = false
): string
{
    $title = trim($title);
    $titleXml = $title !== ''
        ? '<w:p><w:r><w:rPr><w:b/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr><w:t xml:space="preserve">' . docxEscapeText($title) . '</w:t></w:r></w:p>'
        : '';

    $tblGrid = '';
    if (!empty($columnWidthsTwips)) {
        $gridCols = array_map(static function ($w): string {
            $w = (int) $w;
            return $w > 0 ? '<w:gridCol w:w="' . $w . '"/>' : '<w:gridCol/>';
        }, $columnWidthsTwips);
        $tblGrid = '<w:tblGrid>' . implode('', $gridCols) . '</w:tblGrid>';
    }

    $headerCells = [];
    foreach ($headers as $i => $h) {
        $headerCells[] = docxCell((string) $h, (int) ($columnWidthsTwips[$i] ?? 0), true, 16);
    }
    $bodyRowsXml = [docxTableRow($headerCells)];

    foreach ($rows as $row) {
        $cells = [];
        foreach ($headers as $i => $_) {
            $cells[] = docxCell((string) ($row[$i] ?? ''), (int) ($columnWidthsTwips[$i] ?? 0), false, 15);
        }
        $bodyRowsXml[] = docxTableRow($cells);
    }

    $tbl = '<w:tbl>'
        . '<w:tblPr>'
        . '<w:tblW w:w="0" w:type="auto"/>'
        . '<w:tblLook w:val="04A0"/>'
        . '</w:tblPr>'
        . $tblGrid
        . implode('', $bodyRowsXml)
        . '</w:tbl>';

    $pageWidth = $landscape ? 16838 : 11906;
    $pageHeight = $landscape ? 11906 : 16838;
    $pageOrientation = $landscape ? 'landscape' : 'portrait';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>'
        . $titleXml
        . $tbl
        . '<w:sectPr>'
        . '<w:pgSz w:w="' . $pageWidth . '" w:h="' . $pageHeight . '" w:orient="' . $pageOrientation . '"/>'
        . '<w:pgMar w:top="720" w:right="540" w:bottom="720" w:left="540" w:header="450" w:footer="450" w:gutter="0"/>'
        . '</w:sectPr>'
        . '</w:body>'
        . '</w:document>';
}

function docxBuildBytes(string $documentXml): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive не доступен (нужно расширение PHP zip).');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'docx_');
    if ($tmp === false) {
        throw new RuntimeException('Не удалось создать временный файл.');
    }
    $tmpDocx = $tmp . '.docx';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($tmpDocx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Не удалось создать DOCX архив.');
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>');

    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');

    $zip->close();

    $bytes = (string) file_get_contents($tmpDocx);
    @unlink($tmpDocx);

    if ($bytes === '') {
        throw new RuntimeException('DOCX получился пустым.');
    }

    return $bytes;
}

/**
 * Build a DOCX table for participants export.
 *
 * Expected row keys:
 * - id
 * - public_number (optional)
 * - participant_fio
 * - age
 * - region
 * - applicant_email
 * - applicant_fio
 * - organization_name
 * - work_status (code or label)
 */
function buildParticipantsDocxBytes(array $rows, string $title = 'Список участников'): string
{
    $headers = [
        'Номер участника',
        'ФИО участника',
        'Возраст',
        'Регион',
        'email-заявителя',
        'ФИО заявителя',
        'Название организации',
        'Статус работы',
    ];

    $tableRows = [];
    foreach ($rows as $row) {
        $displayNumber = trim((string) ($row['public_number'] ?? ''));
        if ($displayNumber === '' && function_exists('getParticipantDisplayNumber')) {
            $displayNumber = trim((string) getParticipantDisplayNumber((array) $row));
        }
        if ($displayNumber === '') {
            $displayNumber = (string) ((int) ($row['id'] ?? 0));
        }
        $displayNumber = ltrim($displayNumber, '#');
        if ($displayNumber !== '') {
            $displayNumber = '#' . $displayNumber;
        }

        $status = trim((string) ($row['work_status'] ?? ''));
        if ($status !== '' && function_exists('getWorkStatusLabel')) {
            // If status is a known code, convert to label; otherwise keep as-is.
            $maybe = getWorkStatusLabel($status);
            $status = $maybe !== '' ? $maybe : $status;
        }

        $tableRows[] = [
            $displayNumber,
            (string) ($row['participant_fio'] ?? ''),
            (string) ($row['age'] ?? ''),
            (string) ($row['region'] ?? ''),
            (string) ($row['applicant_email'] ?? ''),
            (string) ($row['applicant_fio'] ?? ''),
            (string) ($row['organization_name'] ?? ''),
            $status,
        ];
    }

    // Tuned for A4 landscape with tighter margins and compact type.
    $widths = [900, 1700, 700, 1100, 1500, 1600, 1800, 1000];
    $xml = docxBuildDocumentXmlWithTable($title, $headers, $tableRows, $widths, true);
    return docxBuildBytes($xml);
}

/**
 * Fetch participant rows for export.
 * Optional filters:
 * - contest_id
 * - application_id
 * - only_accepted (bool)
 */
function fetchParticipantsRowsForDocxExport(array $filters = []): array
{
    global $pdo;

    $contestId = max(0, (int) ($filters['contest_id'] ?? 0));
    $applicationId = max(0, (int) ($filters['application_id'] ?? 0));
    $onlyAccepted = !empty($filters['only_accepted']);

    $where = [];
    $params = [];
    if ($contestId > 0) {
        $where[] = 'a.contest_id = ?';
        $params[] = $contestId;
    }
    if ($applicationId > 0) {
        $where[] = 'a.id = ?';
        $params[] = $applicationId;
    }
    if ($onlyAccepted) {
        $where[] = "COALESCE(w.status, 'pending') = 'accepted'";
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            p.id AS id,
            p.public_number AS public_number,
            COALESCE(NULLIF(TRIM(p.fio), ''), '') AS participant_fio,
            p.age AS age,
            COALESCE(NULLIF(TRIM(p.region), ''), '') AS region,
            u.email AS applicant_email,
            COALESCE(NULLIF(TRIM(a.parent_fio), ''), NULLIF(TRIM(CONCAT_WS(' ', u.surname, u.name, u.patronymic)), ''), u.email) AS applicant_fio,
            COALESCE(NULLIF(TRIM(p.organization_name), ''), NULLIF(TRIM(u.organization_name), ''), '') AS organization_name,
            COALESCE(w.status, 'pending') AS work_status
        FROM participants p
        INNER JOIN applications a ON a.id = p.application_id
        INNER JOIN users u ON u.id = a.user_id
        LEFT JOIN works w ON w.participant_id = p.id AND w.application_id = p.application_id
        $whereSql
        ORDER BY p.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
