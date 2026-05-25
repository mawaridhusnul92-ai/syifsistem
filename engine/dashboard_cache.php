<?php
/**
 * dashboard_cache.php - FINANCIAL CACHE BOOSTER
 * Deskripsi: Mempercepat loading dashboard dengan merekap agregasi saat transaksi terjadi.
 */

function refreshFinancialCache($conn) {
    $tahun = date('Y');
    $bulan = date('m');

    // 1. Hitung Saldo Kas Real-time
    $q_kas = $conn->query("SELECT SUM(a.opening_balance + COALESCE(mut.net, 0)) as val FROM syifa_akun a LEFT JOIN (SELECT kode_akun, SUM(debit-kredit) as net FROM syifa_jurnal_detail GROUP BY kode_akun) mut ON a.kode_akun = mut.kode_akun WHERE a.kode_akun LIKE '1-11%' AND a.is_active=1");
    $saldo_kas = (double)($q_kas->fetch_assoc()['val'] ?? 0);

    // 2. Hitung Pendapatan Bulan Berjalan
    $q_pend = $conn->query("SELECT SUM(jd.kredit - jd.debit) as val FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun WHERE YEAR(j.tgl_jurnal) = '$tahun' AND MONTH(j.tgl_jurnal) = '$bulan' AND a.kategori = 'Pendapatan'");
    $pendapatan = (double)($q_pend->fetch_assoc()['val'] ?? 0);

    // 3. Hitung Belanja Bulan Berjalan
    $q_bel = $conn->query("SELECT SUM(jd.debit - jd.kredit) as val FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun WHERE YEAR(j.tgl_jurnal) = '$tahun' AND MONTH(j.tgl_jurnal) = '$bulan' AND a.kategori = 'Beban'");
    $belanja = (double)($q_bel->fetch_assoc()['val'] ?? 0);

    // Simpan ke Tabel Cache (Upsert)
    $stmt = $conn->prepare("INSERT INTO syifa_dashboard_cache (cache_key, cache_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), updated_at = NOW()");
    
    $k1 = 'SALDO_KAS';      $stmt->bind_param("sd", $k1, $saldo_kas);  $stmt->execute();
    $k2 = 'PENDAPATAN_BLN'; $stmt->bind_param("sd", $k2, $pendapatan); $stmt->execute();
    $k3 = 'BELANJA_BLN';    $stmt->bind_param("sd", $k3, $belanja);    $stmt->execute();
}
?>