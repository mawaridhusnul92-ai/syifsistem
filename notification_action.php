<?php
/**
 * notification_action.php - ENGINE PEMBERSIH NOTIFIKASI
 * Menangani fungsi "Tandai Semua Sudah Dibaca" via AJAX tanpa reload berat.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Akses Ditolak']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action == 'mark_all_read') {
    // Sapu bersih semua notifikasi yang belum terbaca milik user ini
    $conn->query("UPDATE syifa_notifications SET is_read = 1, status = 'read' WHERE user_id = $uid AND is_read = 0");
    echo json_encode(['status' => 'success']);
    exit;
}
?>