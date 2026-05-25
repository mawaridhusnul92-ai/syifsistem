<?php
/**
 * ajax_student_suggestions.php - API PENCARIAN MAHASISWA REAL-TIME
 * Versi: 2.0 (Security Hardened - Prepared Statement Edition)
 * Perbaikan: Perlindungan mutlak dari SQL Injection (Tahap 2).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

// Threshold minimal 2 karakter agar responsif
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// PERBAIKAN TAHAP 2: MENGGUNAKAN PREPARED STATEMENT
$stmt = $conn->prepare("
    SELECT nim, nama 
    FROM syifa_mahasiswa 
    WHERE nim LIKE ? OR nama LIKE ? 
    ORDER BY nama ASC LIMIT 10
");

$like_query = "%$query%";
$stmt->bind_param("ss", $like_query, $like_query);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $data[] = [
            'nim'  => $row['nim'],
            'nama' => htmlspecialchars($row['nama'])
        ];
    }
}

echo json_encode($data);
exit;