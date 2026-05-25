<?php
/**
 * fetch_suggestions.php - REAL-TIME SEARCH ENGINE (GLOBAL)
 * Versi: 2.0 (Security Hardened - Prepared Statement Edition)
 * Perbaikan: Perlindungan mutlak dari SQL Injection.
 */
require_once 'config/koneksi.php';

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// PERBAIKAN TAHAP 2: MENGGUNAKAN PREPARED STATEMENT
$stmt = $conn->prepare("
    SELECT nim, nama 
    FROM syifa_mahasiswa 
    WHERE nim LIKE ? OR nama LIKE ? 
    LIMIT 10
");

$like_q = "%$q%";
$stmt->bind_param("ss", $like_q, $like_q);
$stmt->execute();
$res = $stmt->get_result();

$suggestions = [];
while ($row = $res->fetch_assoc()) {
    $suggestions[] = [
        'value' => $row['nim'],
        'label' => "[" . $row['nim'] . "] " . $row['nama'],
        'name'  => htmlspecialchars($row['nama'])
    ];
}

header('Content-Type: application/json');
echo json_encode($suggestions);
exit;