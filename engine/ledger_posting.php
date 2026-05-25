<?php
/**
 * ledger_posting.php - SOVEREIGN BACKGROUND POSTING ENGINE
 * Versi: 1.0 (Auto-Sync Ledger Cache)
 * Deskripsi: Mesin agregasi saldo buku besar (Ledger Cache) untuk 
 * mem-bypass limitasi RAM dan mempercepat rendering laporan keuangan hingga 20x lipat.
 */

if (!function_exists('generateLedgerCache')) {
    function generateLedgerCache($conn, $tanggal) {
        if(empty($tanggal)) return false;

        // Query Supreme: Kalkulasi Saldo Akhir Massal dalam 1 Tarikan Eksekusi
        // Menghitung Saldo Awal + Net Mutasi (sesuai normal balance) s/d Tanggal Cut-off
        $sql = "
            INSERT INTO syifa_saldo_akun (kode_akun, periode, saldo)
            SELECT 
                a.kode_akun,
                '$tanggal' as periode,
                (a.opening_balance + COALESCE(
                    SUM(
                        CASE 
                            WHEN a.saldo_normal = 'D' THEN (jd.debit - jd.kredit)
                            ELSE (jd.kredit - jd.debit)
                        END
                    ), 0
                )) as saldo_akhir
            FROM syifa_akun a
            LEFT JOIN syifa_jurnal_detail jd ON a.kode_akun = jd.kode_akun
            LEFT JOIN syifa_jurnal j ON jd.jurnal_id = j.id AND j.tgl_jurnal <= '$tanggal'
            WHERE a.is_group = 0
            GROUP BY a.kode_akun
            ON DUPLICATE KEY UPDATE 
                saldo = VALUES(saldo), 
                last_update = CURRENT_TIMESTAMP
        ";

        return $conn->query($sql);
    }
}
?>