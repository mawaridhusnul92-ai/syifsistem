<?php
/**
 * LedgerSnapshotEngine.php - ERP LEVEL O(1) PERFORMANCE GENERATOR
 * Versi: 2.0 (True Cumulative & Deleted Guard Edition)
 * Perbaikan:
 * 1. FILTER MUTLAK: Mengabaikan jurnal yang is_deleted = 1 (Void).
 * 2. ANTI DOUBLE COUNT: Menggabungkan Opening Balance langsung ke dalam nilai 
 * Net Balance Snapshot, sehingga saat dibaca, sistem menerima angka Final murni.
 */

class LedgerSnapshotEngine {
    public static function buildSnapshot($conn, $tahun, $bulan) {
        
        // 1. Buat Tabel (Struktur ERP Besar)
        $conn->query("
            CREATE TABLE IF NOT EXISTS syifa_ledger_snapshot (
                id INT AUTO_INCREMENT PRIMARY KEY,
                periode_tahun INT(4) NOT NULL,
                periode_bulan INT(2) NOT NULL,
                kode_akun VARCHAR(50) NOT NULL,
                saldo_debit DECIMAL(20,2) DEFAULT 0,
                saldo_kredit DECIMAL(20,2) DEFAULT 0,
                net_balance DECIMAL(20,2) DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_snapshot_periode_akun (periode_tahun, periode_bulan, kode_akun)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        
        $end_date = date("Y-m-t", strtotime("$tahun-$bulan-01"));
        
        $conn->begin_transaction();
        try {
            // Bersihkan snapshot lama di bulan yang sama
            $conn->query("DELETE FROM syifa_ledger_snapshot WHERE periode_tahun = $tahun AND periode_bulan = $bulan");
            
            // PULL AGREGASI LIFE-TO-DATE DARI JURNAL (MURNI & GABUNGAN OB)
            // Relasi ditarik dari syifa_akun agar SEMUA akun tercatat di snapshot meskipun belum ada mutasi jurnal.
            $sql = "
                INSERT INTO syifa_ledger_snapshot (periode_tahun, periode_bulan, kode_akun, saldo_debit, saldo_kredit, net_balance)
                SELECT 
                    $tahun, $bulan, 
                    a.kode_akun, 
                    COALESCE(mut.td, 0) AS total_debit, 
                    COALESCE(mut.tk, 0) AS total_kredit,
                    (CASE WHEN a.saldo_normal = 'D' THEN ABS(COALESCE(a.opening_balance, 0)) ELSE -ABS(COALESCE(a.opening_balance, 0)) END) + COALESCE(mut.td, 0) - COALESCE(mut.tk, 0) AS final_net_balance
                FROM syifa_akun a
                LEFT JOIN (
                    SELECT jd.kode_akun, SUM(jd.debit) as td, SUM(jd.kredit) as tk
                    FROM syifa_jurnal_detail jd
                    JOIN syifa_jurnal j ON jd.jurnal_id = j.id
                    WHERE j.tgl_jurnal <= '$end_date' 
                    -- MENCEGAH FATAL BUG: Abaikan jurnal yang terhapus/void
                    AND (j.is_deleted = 0 OR j.is_deleted IS NULL)
                    GROUP BY jd.kode_akun
                ) mut ON a.kode_akun = mut.kode_akun
                WHERE a.is_group = 0
            ";
            $conn->query($sql);
            
            $conn->commit();
            return true;
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
?>