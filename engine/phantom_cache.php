<?php
/**
 * phantom_cache.php - THE PHANTOM CACHE SYSTEM (Cron Job Engine)
 * Versi: 1.0 (Sovereign Grand Master - Background Processor)
 * Deskripsi: Skrip ini dirancang untuk dijalankan oleh CRON JOB setiap malam 
 * (Misal: pukul 00:01). Tugasnya adalah memindai seluruh Buku Besar, menjumlahkan 
 * mutasi, dan membekukan hasilnya (Snapshot) ke tabel cache untuk mempercepat 
 * loading Laporan Eksekutif dan Dashboard.
 */

// 🛡️ SECURITY GUARD: Mencegah eksekusi sembarangan dari browser publik
// Script ini hanya bisa dijalankan melalui CLI (Cron) atau jika memiliki token khusus.
$is_cli = (php_sapi_name() === 'cli');
$has_token = isset($_GET['token']) && $_GET['token'] === 'sY!f4_AuT0_C4ch3';

if (!$is_cli && !$has_token) {
    http_response_code(403);
    die("Akses Ditolak: Skrip ini hanya dapat dieksekusi oleh Background Processor.");
}

// Sesuaikan path koneksi karena file berada di dalam folder engine/
require_once dirname(__DIR__) . '/config/koneksi.php';

echo "[".date('Y-m-d H:i:s')."] MEMULAI PHANTOM CACHE ENGINE...\n";

try {
    // 1. BUAT TABEL CACHE (AUTO-HEAL SCHEMA)
    $conn->query("CREATE TABLE IF NOT EXISTS sys_phantom_cache (
        kode_akun VARCHAR(50) PRIMARY KEY,
        saldo_akhir DOUBLE NOT NULL DEFAULT 0,
        last_updated DATETIME NOT NULL
    )");

    echo "[".date('Y-m-d H:i:s')."] Tabel cache diverifikasi.\n";

    // 2. KUMPULKAN MASTER COA
    $sql_akun = "SELECT kode_akun, saldo_normal, normal_balance, opening_balance FROM syifa_akun WHERE is_group=0 AND is_active=1";
    $res_akun = $conn->query($sql_akun);

    $count = 0;
    
    // Matikan auto-commit agar kueri ribuan baris diproses dalam RAM (Sangat Cepat)
    $conn->begin_transaction();

    $stmt_update = $conn->prepare("INSERT INTO sys_phantom_cache (kode_akun, saldo_akhir, last_updated) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE saldo_akhir = VALUES(saldo_akhir), last_updated = NOW()");

    while ($a = $res_akun->fetch_assoc()) {
        $kode = $a['kode_akun'];
        $sn = strtoupper($a['saldo_normal'] ?? '');
        $nb = strtoupper($a['normal_balance'] ?? '');
        $is_kredit = ($sn == 'K' || $nb == 'KREDIT');
        
        $ob = (double)$a['opening_balance'];

        // SEDOT MUTASI SEPANJANG MASA HINGGA DETIK INI (NOW)
        $q_mutasi = $conn->query("
            SELECT SUM(jd.debit) as d, SUM(jd.kredit) as k 
            FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            WHERE jd.kode_akun = '$kode' AND j.is_deleted = 0
        ");
        $mutasi = $q_mutasi->fetch_assoc();
        
        $mut_d = (double)($mutasi['d'] ?? 0);
        $mut_k = (double)($mutasi['k'] ?? 0);

        // KALKULASI RUMUS SEJATI
        if ($is_kredit) {
            $saldo_akhir = $ob + $mut_k - $mut_d;
        } else {
            $saldo_akhir = $ob + $mut_d - $mut_k;
        }

        // BEKUKAN KE TABEL CACHE
        $stmt_update->bind_param("sd", $kode, $saldo_akhir);
        $stmt_update->execute();
        
        $count++;
    }

    $conn->commit();
    echo "[".date('Y-m-d H:i:s')."] BERHASIL: $count akun telah dibekukan (Snapshot) ke dalam Cache.\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "[".date('Y-m-d H:i:s')."] GAGAL: " . $e->getMessage() . "\n";
}
?>