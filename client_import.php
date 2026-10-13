<?php
declare(strict_types=1);

const CLIENT_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const CLIENT_IMPORT_MAX_ROWS = 5000;

function client_import_normalize_header(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header));
    $header = mb_strtolower($header);
    return trim((string)preg_replace('/[^a-z0-9]+/u', '_', $header), '_');
}

function client_import_header_aliases(): array
{
    return [
        'client_name' => ['client_name', 'name', 'client', 'customer_name', 'customer'],
        'client_phone' => ['mobile_number', 'mobile', 'phone', 'phone_number', 'contact_number'],
        'client_email' => ['email', 'email_address', 'client_email'],
        'company_name' => ['company_name', 'company', 'business_name', 'organization'],
        'client_address' => ['address', 'client_address', 'company_address'],
    ];
}

function client_import_column_map(array $headers): array
{
    $normalized = array_map(static fn(mixed $value): string => client_import_normalize_header((string)$value), $headers);
    $map = [];
    foreach (client_import_header_aliases() as $field => $aliases) {
        foreach ($aliases as $alias) {
            $index = array_search($alias, $normalized, true);
            if ($index !== false) {
                $map[$field] = (int)$index;
                break;
            }
        }
    }
    if (!isset($map['client_name']) || !isset($map['client_phone'])) {
        throw new InvalidArgumentException('The spreadsheet must include Client Name and Mobile Number columns.');
    }
    return $map;
}

function client_import_csv_rows(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('The CSV file could not be opened.');
    try {
        $firstLine = fgets($handle);
        if ($firstLine === false) return [];
        $delimiters = [',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t"), ';' => substr_count($firstLine, ';')];
        arsort($delimiters);
        $delimiter = (string)array_key_first($delimiters);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(static fn(mixed $value): string => trim((string)$value), $row);
            if (count($rows) > CLIENT_IMPORT_MAX_ROWS + 1) throw new InvalidArgumentException('The spreadsheet can contain up to ' . number_format(CLIENT_IMPORT_MAX_ROWS) . ' client rows.');
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

function client_import_xml_text(SimpleXMLElement $node): string
{
    $texts = $node->xpath('.//*[local-name()="t"]');
    if (!$texts) return trim((string)$node);
    return implode('', array_map(static fn(SimpleXMLElement $text): string => (string)$text, $texts));
}

function client_import_zip_files(string $path): array
{
    $archive = file_get_contents($path);
    if ($archive === false) throw new RuntimeException('The XLSX file could not be read.');
    $eocd = strrpos($archive, "\x50\x4b\x05\x06");
    if ($eocd === false || strlen($archive) < $eocd + 22) throw new InvalidArgumentException('The XLSX file is invalid.');
    $end = unpack('vdisk/vcentral_disk/ventries_disk/ventries/Vcentral_size/Vcentral_offset', substr($archive, $eocd + 4, 16));
    $position = (int)$end['central_offset'];
    $entries = [];
    $inflatedBytes = 0;
    for ($index = 0; $index < (int)$end['entries']; $index++) {
        if ($position < 0 || strlen($archive) < $position + 46) throw new InvalidArgumentException('The XLSX directory is incomplete.');
        if (substr($archive, $position, 4) !== "\x50\x4b\x01\x02") throw new InvalidArgumentException('The XLSX directory is invalid.');
        $header = unpack('vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/vcomment_length/vdisk/vinternal/Vexternal/Vlocal_offset', substr($archive, $position + 4, 42));
        $name = substr($archive, $position + 46, (int)$header['name_length']);
        $wanted = $name === 'xl/sharedStrings.xml' || preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name);
        if ($wanted) {
            if (((int)$header['flags'] & 1) !== 0) throw new InvalidArgumentException('Password-protected XLSX files cannot be imported.');
            if ((int)$header['uncompressed'] > 25 * 1024 * 1024 || $inflatedBytes + (int)$header['uncompressed'] > 35 * 1024 * 1024) throw new InvalidArgumentException('The XLSX worksheet is too large to import safely.');
            $local = (int)$header['local_offset'];
            if ($local < 0 || strlen($archive) < $local + 30) throw new InvalidArgumentException('The XLSX content is incomplete.');
            if (substr($archive, $local, 4) !== "\x50\x4b\x03\x04") throw new InvalidArgumentException('The XLSX content is invalid.');
            $localLengths = unpack('vname/vextra', substr($archive, $local + 26, 4));
            $dataStart = $local + 30 + (int)$localLengths['name'] + (int)$localLengths['extra'];
            $compressed = substr($archive, $dataStart, (int)$header['compressed']);
            $contents = match ((int)$header['method']) {
                0 => $compressed,
                8 => function_exists('gzinflate') ? gzinflate($compressed) : false,
                default => false,
            };
            if ($contents === false || strlen($contents) !== (int)$header['uncompressed']) throw new InvalidArgumentException('The XLSX worksheet compression is unsupported or damaged.');
            $entries[$name] = $contents;
            $inflatedBytes += strlen($contents);
        }
        $position += 46 + (int)$header['name_length'] + (int)$header['extra_length'] + (int)$header['comment_length'];
    }
    return $entries;
}

function client_import_xlsx_rows(string $path): array
{
    if (!function_exists('simplexml_load_string')) throw new RuntimeException('XLSX import requires the PHP SimpleXML extension. Enable it on the server or upload a CSV file.');
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('The XLSX file is invalid or cannot be opened.');
        $entryNames = [];
        for ($index = 0; $index < $zip->numFiles; $index++) $entryNames[] = (string)$zip->getNameIndex($index);
        $readEntry = static function (string $name) use ($zip): string|false {
            $details = $zip->statName($name);
            if (is_array($details) && (int)($details['size'] ?? 0) > 25 * 1024 * 1024) throw new InvalidArgumentException('The XLSX worksheet is too large to import safely.');
            return $zip->getFromName($name);
        };
    } else {
        $files = client_import_zip_files($path);
        $entryNames = array_keys($files);
        $readEntry = static fn(string $name): string|false => $files[$name] ?? false;
    }
    try {
        $sharedStrings = [];
        $sharedXml = $readEntry('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sharedRoot = simplexml_load_string($sharedXml);
            if ($sharedRoot === false) throw new InvalidArgumentException('The XLSX shared strings are invalid.');
            $nodes = $sharedRoot->xpath('//*[local-name()="si"]') ?: [];
            foreach ($nodes as $node) $sharedStrings[] = client_import_xml_text($node);
        }

        $worksheets = [];
        foreach ($entryNames as $name) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) $worksheets[] = $name;
        }
        natsort($worksheets);
        $sheetName = reset($worksheets);
        if ($sheetName === false) throw new InvalidArgumentException('No worksheet was found in the XLSX file.');
        $sheetXml = $readEntry($sheetName);
        $sheet = $sheetXml !== false ? simplexml_load_string($sheetXml) : false;
        if ($sheet === false) throw new InvalidArgumentException('The first XLSX worksheet is invalid.');

        $rows = [];
        foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
            $row = [];
            foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $attributes = $cell->attributes();
                $reference = (string)($attributes['r'] ?? '');
                if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $match)) continue;
                $column = 0;
                foreach (str_split(strtoupper($match[1])) as $letter) $column = ($column * 26) + ord($letter) - 64;
                $column--;
                $type = (string)($attributes['t'] ?? '');
                if ($type === 'inlineStr') {
                    $inline = $cell->xpath('./*[local-name()="is"]');
                    $value = $inline ? client_import_xml_text($inline[0]) : '';
                } else {
                    $valueNodes = $cell->xpath('./*[local-name()="v"]');
                    $raw = $valueNodes ? (string)$valueNodes[0] : '';
                    $value = $type === 's' ? (string)($sharedStrings[(int)$raw] ?? '') : $raw;
                }
                $row[$column] = trim($value);
            }
            if ($row !== []) {
                $lastColumn = max(array_keys($row));
                $rows[] = array_replace(array_fill(0, $lastColumn + 1, ''), $row);
            }
            if (count($rows) > CLIENT_IMPORT_MAX_ROWS + 1) throw new InvalidArgumentException('The spreadsheet can contain up to ' . number_format(CLIENT_IMPORT_MAX_ROWS) . ' client rows.');
        }
        return $rows;
    } finally {
        if (isset($zip)) $zip->close();
    }
}

function read_client_import_file(array $file): array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Select an Excel or CSV file to upload.');
    if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The client file upload failed. Please try again.');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > CLIENT_IMPORT_MAX_BYTES) throw new InvalidArgumentException('Upload a non-empty file no larger than 5 MB.');
    $path = (string)($file['tmp_name'] ?? '');
    if ($path === '' || !is_file($path)) throw new InvalidArgumentException('The uploaded client file is unavailable.');
    $extension = mb_strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    return match ($extension) {
        'csv' => client_import_csv_rows($path),
        'xlsx' => client_import_xlsx_rows($path),
        default => throw new InvalidArgumentException('Upload an .xlsx or .csv file.'),
    };
}

function import_clients_from_rows(array $rows, string $duplicateMode = 'skip'): array
{
    if (!in_array($duplicateMode, ['skip', 'update'], true)) throw new InvalidArgumentException('Select a valid duplicate handling option.');
    while ($rows && count(array_filter(array_map('trim', array_map('strval', $rows[0])))) === 0) array_shift($rows);
    if (!$rows) throw new InvalidArgumentException('The spreadsheet is empty.');
    $columnMap = client_import_column_map(array_shift($rows));
    if (count($rows) > CLIENT_IMPORT_MAX_ROWS) throw new InvalidArgumentException('The spreadsheet can contain up to ' . number_format(CLIENT_IMPORT_MAX_ROWS) . ' client rows.');

    $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'processed' => 0, 'errors' => []];
    foreach ($rows as $offset => $row) {
        $sheetRow = $offset + 2;
        $values = [];
        foreach ($columnMap as $field => $index) $values[$field] = trim((string)($row[$index] ?? ''));
        if (count(array_filter($values, static fn(string $value): bool => $value !== '')) === 0) continue;
        $result['processed']++;
        try {
            $phone = normalize_phone((string)($values['client_phone'] ?? ''));
            $email = mb_strtolower((string)($values['client_email'] ?? ''));
            $phoneClient = query_one('SELECT * FROM clients WHERE phone=?', [$phone]);
            $emailClient = $email !== '' ? query_one("SELECT * FROM clients WHERE lower(COALESCE(email, ''))=?", [$email]) : false;
            if ($phoneClient && $emailClient && (int)$phoneClient['id'] !== (int)$emailClient['id']) {
                throw new InvalidArgumentException('The mobile number and email belong to different existing clients.');
            }
            $existing = $phoneClient ?: $emailClient;
            if ($existing && $duplicateMode === 'skip') {
                $result['skipped']++;
                $result['errors'][] = ['row' => $sheetRow, 'type' => 'Skipped', 'message' => 'An existing client has this mobile number or email.'];
                continue;
            }
            $input = [
                'client_name' => (string)($values['client_name'] ?? ''),
                'client_phone' => $phone,
                'client_email' => $email,
                'company_name' => (string)($values['company_name'] ?? ''),
                'client_address' => (string)($values['client_address'] ?? ''),
            ];
            if ($existing) {
                foreach ([['client_email', 'email'], ['company_name', 'company_name'], ['client_address', 'address']] as [$field, $column]) {
                    if ($input[$field] === '') $input[$field] = (string)($existing[$column] ?? '');
                }
                update_client_account((int)$existing['id'], $input);
                $result['updated']++;
            } else {
                create_client_account($input);
                $result['created']++;
            }
        } catch (Throwable $error) {
            $result['failed']++;
            if (count($result['errors']) < 100) $result['errors'][] = ['row' => $sheetRow, 'type' => 'Failed', 'message' => $error instanceof PDOException ? 'The row could not be saved.' : $error->getMessage()];
        }
    }
    if ($result['processed'] === 0) throw new InvalidArgumentException('No client records were found below the header row.');
    return $result;
}

function import_clients_file(array $file, string $duplicateMode = 'skip'): array
{
    return import_clients_from_rows(read_client_import_file($file), $duplicateMode);
}
