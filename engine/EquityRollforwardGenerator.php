<?php
/**
 * EquityRollforwardGenerator.php - PURE EQUITY ACCOUNTING
 * Versi: 300.0 (Sovereign Grand Master - Anti-Balancing Figure)
 * Perbaikan: Ekuitas (Aset Neto) tidak lagi dihitung dari Aset - Liabilitas.
 * Ekuitas dihitung MURNI dari Akun 3- (Aset Neto) dan Pergerakan Laba/Rugi.
 */
class EquityRollforwardGenerator
{
    private static function autoHealDatabase($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS `syifa_equity_rollforward` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `tahun` INT(4) NOT NULL, `bulan` INT(2) NOT NULL,
            `is_restricted` TINYINT(1) NOT NULL DEFAULT 0,
            `saldo_awal` DECIMAL(20,2) DEFAULT 0.00,
            `mutasi_operasi` DECIMAL(20,2) DEFAULT 0.00,
            `mutasi_non_operasi` DECIMAL(20,2) DEFAULT 0.00,
            `saldo_akhir` DECIMAL(20,2) DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_periode_rest` (`tahun`, `bulan`, `is_restricted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    public static function generate($conn, $year, $month)
    {
        self::autoHealDatabase($conn);
        $curr_start = "$year-" . sprintf("%02d", $month) . "-01 00:00:00";
        $curr_end = date('Y-m-t', strtotime($curr_start)) . " 23:59:59";
        
        $prev_year = $year; $prev_month = $month - 1;
        if ($prev_month == 0) { $prev_month = 12; $prev_year--; }

        // Dapatkan versi cache terbaru dari bulan lalu
        $v_stmt = $conn->prepare("SELECT MAX(version) as v FROM syifa_trial_balance_cache WHERE tahun=? AND bulan=?");
        $v_stmt->bind_param("ii", $prev_year, $prev_month);
        $v_stmt->execute();
        $prev_version = (int)($v_stmt->get_result()->fetch_assoc()['v'] ?? 1);

        foreach ([0, 1] as $is_rest) {
            // 1. SALDO AWAL EKUITAS: Ditarik murni dari akun kategori Aset Neto (Prefix 3) pada cache bulan lalu
            $sql_awal = "SELECT SUM(tbc.saldo) as total 
                         FROM syifa_trial_balance_cache tbc 
                         JOIN syifa_akun a ON tbc.kode_akun = a.kode_akun 
                         WHERE tbc.tahun=? AND tbc.bulan=? AND tbc.version=? 
                         AND a.kategori='Aset Neto' AND a.is_group=0 AND a.is_restricted=?";
            $stmt_aw = $conn->prepare($sql_awal);
            $stmt_aw->bind_param("iiii", $prev_year, $prev_month, $prev_version, $is_rest);
            $stmt_aw->execute();
            $saldo_awal = (double)($stmt_aw->get_result()->fetch_assoc()['total'] ?? 0);

            // 2. MUTASI OPERASI (SURPLUS/DEFISIT): Ditarik murni dari Jurnal Pendapatan - Beban bulan ini
            $sql_surp = "SELECT SUM(CASE WHEN a.kategori='Pendapatan' THEN (jd.kredit - jd.debit) ELSE -(jd.debit - jd.kredit) END) as net 
                         FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id=j.id JOIN syifa_akun a ON jd.kode_akun=a.kode_akun 
                         WHERE a.kategori IN ('Pendapatan','Beban') AND a.is_restricted=? AND j.tgl_jurnal BETWEEN ? AND ? AND j.is_deleted=0";
            $stmt_op = $conn->prepare($sql_surp);
            $stmt_op->bind_param("iss", $is_rest, $curr_start, $curr_end);
            $stmt_op->execute();
            $mutasi_operasi = (double)($stmt_op->get_result()->fetch_assoc()['net'] ?? 0);

            // 3. MUTASI NON OPERASI: Reklasifikasi atau Jurnal langsung ke akun Aset Neto
            $sql_non = "SELECT SUM(CASE WHEN a.saldo_normal='K' THEN (jd.kredit - jd.debit) ELSE (jd.debit - jd.kredit) END) as net 
                        FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id=j.id JOIN syifa_akun a ON jd.kode_akun=a.kode_akun 
                        WHERE a.kategori='Aset Neto' AND a.is_restricted=? AND j.tgl_jurnal BETWEEN ? AND ? AND j.is_deleted=0";
            $stmt_non = $conn->prepare($sql_non);
            $stmt_non->bind_param("iss", $is_rest, $curr_start, $curr_end);
            $stmt_non->execute();
            $mutasi_non_operasi = (double)($stmt_non->get_result()->fetch_assoc()['net'] ?? 0);

            // 4. SALDO AKHIR MURNI
            $saldo_akhir = $saldo_awal + $mutasi_operasi + $mutasi_non_operasi;

            $stmt_save = $conn->prepare("INSERT INTO syifa_equity_rollforward (tahun, bulan, is_restricted, saldo_awal, mutasi_operasi, mutasi_non_operasi, saldo_akhir) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE saldo_awal=VALUES(saldo_awal), mutasi_operasi=VALUES(mutasi_operasi), mutasi_non_operasi=VALUES(mutasi_non_operasi), saldo_akhir=VALUES(saldo_akhir)");
            $stmt_save->bind_param("iiidddd", $year, $month, $is_rest, $saldo_awal, $mutasi_operasi, $mutasi_non_operasi, $saldo_akhir);
            $stmt_save->execute();
        }
    }
}
?>