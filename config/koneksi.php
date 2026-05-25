<?php
/**
 * koneksi.php - GLOBAL ENGINE ERP SYIFA
 * Versi: 51.0 (Sovereign Kernel - Soft Cache Invalidation Edition)
 * Deskripsi: Menyimpan koneksi DB, Security Guard, dan Global Calculation Engine.
 * Perbaikan: Injeksi InvalidateSnapshotsFromDate & is_valid flag pada EOM Snapshot.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$db_host = 'sql212.infinityfree.com';
$db_user = 'if0_41865968';
$db_pass = 'Gwveb7FY8xeXL1';
$db_name = 'if0_41865968_syifa';

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+07:00'"); 

} catch (Exception $e) {
    die("
    <div style='font-family:sans-serif; padding:50px; text-align:center;'>
        <h2 style='color:#e74c3c;'>Koneksi Database Terputus</h2>
        <p style='color:#7f8c8d;'>Sistem tidak dapat terhubung ke server database Syifa.</p>
        <div style='background:#f9f9f9; padding:15px; display:inline-block; border-radius:10px; border:1px solid #eee;'>
            <small>Pesan Error: " . $e->getMessage() . "</small>
        </div>
        <br><br>
        <button onclick='location.reload()' style='padding:10px 20px; background:#3498db; color:white; border:none; border-radius:5px; cursor:pointer;'>Coba Hubungkan Kembali</button>
    </div>");
}

if (!function_exists('getAccountCode')) {
    function getAccountCode($conn, $setting_key) {
        $key = $conn->real_escape_string($setting_key);
        $sql = "SELECT a.kode_akun 
                FROM setting_akun_default s 
                JOIN syifa_akun a ON s.coa_id = a.id 
                WHERE s.kode_setting = '$key' LIMIT 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            return $res->fetch_assoc()['kode_akun'];
        }
        return null;
    }
}

if (!function_exists('getNextNumber')) {
    function getNextNumber($conn, $module_key) {
        $m_key = $conn->real_escape_string($module_key);
        $sql = "SELECT * FROM sys_auto_number WHERE module_key = '$m_key' AND is_active = 1 FOR UPDATE";
        $res = $conn->query($sql);
        $conf = $res->fetch_assoc();

        if (!$conf) return strtoupper(substr($module_key, 0, 3)) . "-" . date('YmdHis');

        $now = date('Y-m-d');
        $cur_month = date('m'); 
        $cur_year = date('Y');
        $last_reset = $conf['last_reset_date'];
        $last_m = $last_reset ? date('m', strtotime($last_reset)) : '';
        $last_y = $last_reset ? date('Y', strtotime($last_reset)) : '';

        $new_seq = (int)$conf['last_number'] + 1;
        if ($conf['reset_type'] == 'Monthly' && $cur_month != $last_m) $new_seq = 1;
        elseif ($conf['reset_type'] == 'Yearly' && $cur_year != $last_y) $new_seq = 1;

        $conn->query("UPDATE sys_auto_number SET last_number = $new_seq, last_reset_date = '$now' WHERE id = {$conf['id']}");
        $seq_padded = str_pad($new_seq, $conf['seq_length'], '0', STR_PAD_LEFT);
        $output = str_replace(['{PREFIX}', '{YEAR}', '{MONTH}', '{SEQ}'], [$conf['prefix'], $cur_year, $cur_month, $seq_padded], $conf['format']);
        return $output;
    }
}

if (!function_exists('guardPage')) {
    function guardPage($menu_key) {
        global $current_permissions;
        if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1) return true;
        
        if (!isset($current_permissions[$menu_key]) || (int)$current_permissions[$menu_key]['can_view'] !== 1) {
            echo "<div class='container-fluid py-5'><div class='alert alert-danger rounded-4 shadow-sm p-5 text-center'>
                    <i class='fas fa-shield-alt fa-3x mb-4 opacity-25'></i>
                    <h4 class='fw-bold'>Akses Ditolak</h4>
                    <p class='text-muted'>Otoritas Matrix Anda [<b>$menu_key</b>] belum diaktifkan oleh Administrator.</p>
                    <a href='index.php?page=dashboard' class='btn btn-danger rounded-pill px-4'>Kembali ke Dashboard</a>
                  </div></div>";
            exit;
        }
        return true;
    }
}

// =========================================================================
// ?? THE HOLY GRAIL: SNAPSHOT ENGINE WITH VALIDITY FLAG
// =========================================================================
if (!function_exists('runEOMSnapshot')) {
    function runEOMSnapshot($conn, $tahun, $bulan) {
        // Create table with is_valid just in case
        $conn->query("CREATE TABLE IF NOT EXISTS `syifa_saldo_akun_eom` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `kode_akun` varchar(20) NOT NULL,
          `tahun` year(4) NOT NULL,
          `bulan` int(2) NOT NULL,
          `saldo` decimal(18,2) NOT NULL DEFAULT 0.00,
          `is_valid` tinyint(1) NOT NULL DEFAULT 1,
          `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_eom` (`kode_akun`,`tahun`,`bulan`),
          KEY `idx_periode` (`tahun`,`bulan`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $last_date = date('Y-m-t', strtotime("$tahun-$bulan-01"));
        
        // ?? UPDATE: Saat rebuild, paksa is_valid = 1
        $sql_sync = "
            INSERT INTO syifa_saldo_akun_eom (kode_akun, tahun, bulan, saldo, is_valid)
            SELECT a.kode_akun, $tahun, $bulan,
                (COALESCE(a.opening_balance, 0) + COALESCE(SUM(CASE WHEN a.saldo_normal = 'D' THEN (mut.debit - mut.kredit) ELSE (mut.kredit - mut.debit) END), 0)),
                1
            FROM syifa_akun a
            LEFT JOIN (
                SELECT jd.kode_akun, jd.debit, jd.kredit FROM syifa_jurnal_detail jd 
                JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                WHERE j.tgl_jurnal <= '$last_date' AND j.is_deleted = 0
            ) mut ON a.kode_akun = mut.kode_akun
            WHERE a.is_group = 0 GROUP BY a.kode_akun
            ON DUPLICATE KEY UPDATE 
                saldo = VALUES(saldo), 
                is_valid = 1, 
                last_update = CURRENT_TIMESTAMP
        ";
        $conn->query($sql_sync);
    }
}

// ?? FUNGSI INVALIDASI (Sesuai Instruksi Pimpinan)
if (!function_exists('invalidateSnapshotsFromDate')) {
    function invalidateSnapshotsFromDate($conn, $tgl_edit) {
        $tahun = (int)date('Y', strtotime($tgl_edit));
        $bulan = (int)date('n', strtotime($tgl_edit));

        $conn->query("
            UPDATE syifa_saldo_akun_eom
            SET is_valid = 0
            WHERE (tahun > $tahun)
               OR (tahun = $tahun AND bulan >= $bulan)
        ");
    }
}

// ?? TRIGGER PENGHUBUNG KESELURUH MODUL (Otomatis panggil Invalidasi)
if (!function_exists('triggerEventLedger')) {
    function triggerEventLedger($conn, $tgl_jurnal) {
        invalidateSnapshotsFromDate($conn, $tgl_jurnal);
    }
}

if (!function_exists('formatRp')) {
    function formatRp($n) { 
        return "Rp " . number_format($n ?? 0, 0, ',', '.'); 
    }
}
?>