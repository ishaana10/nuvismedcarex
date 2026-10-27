<?php
/**
 * Download the inventory report as a CSV, so it can be opened in a
 * spreadsheet or sent to an accountant.
 */
require_once __DIR__ . '/../core/SessionBootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

requireAuth();
requireAccess('inventory', 'view');

$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to   = (string) ($_GET['to'] ?? date('Y-m-d'));
if (!strtotime($from)) $from = date('Y-m-01');
if (!strtotime($to)) $to = date('Y-m-d');

$service = \ClinicFlow\Shared\Container::getInstance()->get(\ClinicFlow\Services\InventoryService::class);
$report = $service->getReport($from, $to);
$alerts = $service->getAlerts();
$clinic = nxClinicName();

$slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($clinic)) ?: 'clinic';
$filename = trim($slug, '-') . '-inventory-' . $from . '-to-' . $to . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // so Excel reads the accents

$row = fn(array $cells) => fputcsv($out, $cells);

$row([$clinic . ' — inventory report']);
$row(['Period', $from, 'to', $to]);
$row(['Generated', date('Y-m-d H:i')]);
$row([]);

$row(['Summary']);
$row(['Items in catalogue', $report['totals']['items']]);
$row(['Units in stock', $report['totals']['units']]);
$row(['Value at cost', number_format($report['totals']['cost_value'], 2, '.', '')]);
$row(['Value at selling price', number_format($report['totals']['retail_value'], 2, '.', '')]);
$row(['Units received', $report['movements']['in']]);
$row(['Spent on stock', number_format($report['movements']['spend'], 2, '.', '')]);
$row(['Units used', $report['movements']['out']]);
$row([]);

$row(['Stock by category']);
$row(['Category', 'Items', 'Units', 'Value at cost', 'Value at selling price', 'Needing reorder']);
foreach ($report['by_category'] as $cat) {
    $row([
        $cat['category'], $cat['items'], $cat['units'],
        number_format($cat['cost_value'], 2, '.', ''),
        number_format($cat['retail_value'], 2, '.', ''),
        $cat['low'],
    ]);
}
$row([]);

$row(['Most movement in the period']);
$row(['Item', 'Received', 'Used']);
foreach ($report['top_moving'] as $item) {
    $row([$item['name'], $item['in'], $item['out']]);
}
$row([]);

$row(['Needs attention']);
$row(['Status', 'Item', 'SKU', 'Category', 'In stock', 'Reorder at', 'Expiry']);
foreach ([['Expired', 'expired'], ['Low or out of stock', 'reorder'], ['Expiring soon', 'expiring']] as [$label, $key]) {
    foreach ($alerts[$key] as $item) {
        $row([
            $label, $item['name'], $item['sku'], $item['category'],
            $item['current_stock'], $item['min_threshold'],
            $item['expiry_date'] ?? '',
        ]);
    }
}

fclose($out);
exit;
