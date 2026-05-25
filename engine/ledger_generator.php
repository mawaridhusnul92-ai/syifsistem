<?php
/**
 * ledger_generator.php - TRUE SNAPSHOT LEDGER ENGINE
 * Deskripsi: Menghitung mutasi per bulan dan menyimpannya permanen.
 * Menghindari kalkulasi berulang dari tabel jurnal.
 */
function generateLedgerSnapshot($conn, $tahun, $bulan) {
    // 1. Ambil semua akun aktif
    $akuns = $conn->query("SELECT kode_akun, opening_balance, saldo_normal FROM syifa_akun WHERE is_group = 0 AND is_active = 1");
    
    while ($a = $akuns->fetch_assoc()) {
        $kode = $a['kode_akun'];
        $saldo_normal = $a['saldo_normal'];
        
        // 2. Cari Saldo Akhir Bulan Sebelumnya
        $bln_lalu = $bulan - 1;
        $thn_lalu = $tahun;
        if ($bln_lalu == 0) {
            $bln_lalu = 12;
            $thn_lalu = $tahun - 1;
        }
        
        $q_prev = $conn->query("SELECT saldo_akhir FROM syifa_saldo_akun WHERE kode_akun = '$kode' AND tahun = $thn_lalu AND bulan = $bln_lalu");
        $saldo_awal_bln_ini = ($q_prev && $q_prev->num_rows > 0) ? (double)$q_prev->fetch_assoc()['saldo_akhir'] : (double)$a['opening_balance'];

        // 3. Hitung Mutasi Bulan INI Saja (Sangat Cepat!)
        $q_mut = $conn->query("SELECT SUM(jd.debit) as d, SUM(jd.kredit) as k 
                               FROM syifa_jurnal_detail jd 
                               JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                               WHERE jd.kode_akun = '$kode' 
                               AND YEAR(j.tgl_jurnal) = $tahun 
                               AND MONTH(j.tgl_jurnal) = $bulan");
        $mut = $q_mut->fetch_assoc();
        $mut_d = (double)($mut['d'] ?? 0);
        $mut_k = (double)($mut['k'] ?? 0);

        // 4. Kalkulasi Saldo Akhir
        if ($saldo_normal == 'D') {
            $saldo_akhir = $saldo_awal_bln_ini + $mut_d - $mut_k;
        } else {
            $saldo_akhir = $saldo_awal_bln_ini + $mut_k - $mut_d;
        }

        // 5. Simpan / Timpa ke Snapshot
        $stmt = $conn->prepare("INSERT INTO syifa_saldo_akun (kode_akun, tahun, bulan, saldo_awal, mutasi_debit, mutasi_kredit, saldo_akhir) 
                                VALUES (?, ?, ?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                saldo_awal = VALUES(saldo_awal), mutasi_debit = VALUES(mutasi_debit), mutasi_kredit = VALUES(mutasi_kredit), saldo_akhir = VALUES(saldo_akhir)");
        $stmt->bind_param("siidddd", $kode, $tahun, $bulan, $saldo_awal_bln_ini, $mut_d, $mut_k, $saldo_akhir);
        $stmt->execute();
    }
    return true;
}
?>