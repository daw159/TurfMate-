<?php
declare(strict_types=1);

/** TurfMate | Reports & Analytics | CSV downloader */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_reports_export_data.php';

/** Prevent spreadsheet formula injection when customers enter untrusted text. */
function tm_x_csv_safe(mixed $value): string
{
    $text = (string) ($value ?? '');
    // Preserve legitimate negative numeric values while blocking injected formulas.
    if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $text) === 1) {
        return $text;
    }
    // Excel can treat leading whitespace then =, +, -, @ as formulas.
    if (preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $text) === 1) {
        return "'" . $text;
    }
    return $text;
}

try {
    $facilities = tm_x_options($pdo, $ownerId);
    $config = tm_x_config($_GET, $facilities);
    $report = tm_x_report($pdo, $ownerId, $config, 20001);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit($exception->getMessage());
} catch (PDOException $exception) {
    error_log('TurfMate CSV export: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Could not generate the report. Check the Apache error log.');
}

$filename = sprintf(
    'turfmate_%s_%s_%s.csv',
    $config['type'],
    $config['from'],
    $config['to']
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    throw new RuntimeException('Unable to open CSV output stream.');
}

fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM helps Excel recognize non-English names.
fputcsv($output, array_values($report['columns']));
$columnKeys = array_keys($report['columns']);

foreach (array_slice($report['rows'], 0, 20000) as $row) {
    $cells = [];
    foreach ($columnKeys as $key) {
        $cells[] = tm_x_csv_safe($row[$key] ?? '');
    }
    fputcsv($output, $cells);
}

fclose($output);
exit;
