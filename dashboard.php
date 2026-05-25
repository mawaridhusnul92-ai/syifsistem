<?php
/**
 * dashboard.php - SMART ROUTER DASHBOARD (ENTERPRISE EDITION)
 * Versi: 100.0 (Sovereign Grand Master - Dynamic Role Filter)
 * Memisahkan tampilan secara otomatis tanpa "Akses Ditolak":
 * 1. Pimpinan / Keuangan / Superadmin -> Melihat Dashboard Eksekutif
 * 2. Kepala Unit -> Melihat Dashboard Anggaran Unit masing-masing secara spesifik
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$user_id = $_SESSION['user_id'] ?? 0;
$role_id = $_SESSION['role_id'] ?? 0;

// 1. Cek Otoritas Role
$sql_role = "SELECT r.is_ka_unit, r.role_name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = '$user_id'";
$q_role = $conn->query($sql_role);
$u_role = $q_role ? $q_role->fetch_assoc() : null;

$role_name = strtoupper($u_role['role_name'] ?? '');

// 2. Tentukan Siapa Saja Yang Berhak Melihat Angka Eksekutif (Global)
$is_executive = ($role_id == 1 || in_array($role_name, ['PIMPINAN', 'SUPERADMIN', 'ADMIN', 'KEUANGAN', 'YAYASAN', 'REKTOR', 'SPI']));

// 3. Routing Dinamis Mutlak
if ($is_executive) {
    // TAMPILKAN DASHBOARD EKSEKUTIF (Global View untuk Pimpinan)
    require_once 'dashboard_eksekutif.php';
} else {
    // TAMPILKAN DASHBOARD UNIT (Filtered View Khusus Kepala Unit)
    // Bypass parameter agar memuat tab dashboard unit dengan aman
    $_GET['tab'] = 'dashboard';
    $is_dashboard_mode = true; 
    require_once 'anggaran_unit.php';
}
?>