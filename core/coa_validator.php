<?php
/**
 * coa_validator.php - ACCOUNTING FIREWALL
 * Deskripsi: Mencegah jurnal masuk ke akun yang tidak valid secara tata kelola.
 */

function validateAccountForPosting($conn, $kode_akun) {

    $stmt = $conn->prepare("
        SELECT 
            kode_akun,
            nama_akun,
            is_group,
            allow_posting,
            is_system_lock,
            normal_balance,
            report_group
        FROM syifa_akun
        WHERE kode_akun = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $kode_akun);
    $stmt->execute();

    $res = $stmt->get_result();

    if ($res->num_rows == 0) {
        throw new Exception("COA Firewall: Akun [$kode_akun] tidak ditemukan di dalam database.");
    }

    $akun = $res->fetch_assoc();

    // 1. Blokir jika Akun adalah Induk (Grup)
    if ($akun['is_group'] == 1) {
        throw new Exception("COA Firewall: Penolakan akses! Akun [{$akun['kode_akun']} - {$akun['nama_akun']}] adalah akun GROUP/INDUK. Transaksi hanya boleh menggunakan akun Detail.");
    }

    // 2. Blokir jika Akun dimatikan hak postingnya
    if ($akun['allow_posting'] == 0) {
        throw new Exception("COA Firewall: Akun [{$akun['kode_akun']} - {$akun['nama_akun']}] saat ini dikunci dan tidak diizinkan menerima transaksi (allow_posting = 0).");
    }

    return $akun;
}
?>