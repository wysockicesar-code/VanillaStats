<?php
// Export Analytics Data
@session_start();

// Check if logged in
if (!isset($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

// Load configuration
$config = require __DIR__ . '/config.php';

// Initialize database
$db = new SQLite3($config['database']);

// Get export format
$format = $_GET['format'] ?? 'csv';
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$dateFrom = date('Y-m-d H:i:s', strtotime("-$days days"));

// Get data
$result = $db->query("SELECT * FROM visits WHERE created_at >= '$dateFrom' ORDER BY created_at DESC");

if ($format === 'csv') {
    // CSV Export
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="analytics-export-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, ['ID', 'Site URL', 'Page URL', 'Referrer', 'Browser', 'OS', 'Device', 'Country', 'Date/Time']);
    
    // CSV Data
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['site_url'],
            $row['page_url'],
            $row['referrer'],
            $row['browser'],
            $row['os'],
            $row['device'],
            $row['country'],
            $row['created_at']
        ]);
    }
    
    fclose($output);
    
} elseif ($format === 'json') {
    // JSON Export
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="analytics-export-' . date('Y-m-d') . '.json"');
    
    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $data[] = $row;
    }
    
    echo json_encode($data, JSON_PRETTY_PRINT);
}

$db->close();
exit;
