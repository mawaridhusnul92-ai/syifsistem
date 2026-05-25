<?php
/**
 * auto_backup_email.php - DISASTER RECOVERY EMAIL ENGINE (Cron Job)
 * Versi: 2.1 (Sovereign Grand Master - Clean Token Edition)
 * Deskripsi: Skrip latar belakang untuk auto-backup database ke email.
 * Perbaikan Mutlak: 
 * Mengubah token menjadi 'syifa_backup_mail' (tanpa karakter spesial) 
 * agar tidak diblokir oleh sistem URL Encoding server InfinityFree.
 */

$is_cli = (php_sapi_name() === 'cli');

// 🚀 TOKEN BARU YANG BERSIH DAN AMAN DARI URL ENCODING
$has_token = isset($_GET['token']) && $_GET['token'] === 'syifa_backup_mail';

if (!$is_cli && !$has_token) {
    http_response_code(403);
    die("Akses Ditolak: Skrip ini hanya dapat dieksekusi oleh Background Processor atau Token tidak valid.");
}

require_once dirname(__DIR__) . '/config/koneksi.php';

echo "[".date('Y-m-d H:i:s')."] MEMULAI AUTO EMAIL BACKUP ENGINE...\n";

try {
    // 🚀 THE INDEPENDENT AUTO-HEALER (Akan tereksekusi mulus setelah lolos token)
    $conn->query("CREATE TABLE IF NOT EXISTS sys_backup_email (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email_penerima VARCHAR(150) NOT NULL,
        is_active TINYINT(1) DEFAULT 0,
        last_sent DATETIME NULL
    )");
    
    $cek_email = $conn->query("SELECT id FROM sys_backup_email LIMIT 1");
    if($cek_email && $cek_email->num_rows == 0) {
        $conn->query("INSERT INTO sys_backup_email (email_penerima, is_active) VALUES ('admin@institusi.com', 0)");
    }
    // -------------------------------------------------------------------------

    // 1. CEK KONFIGURASI EMAIL
    $conf = $conn->query("SELECT * FROM sys_backup_email WHERE id=1")->fetch_assoc();
    if (!$conf || $conf['is_active'] != 1 || empty($conf['email_penerima'])) {
        die("[".date('Y-m-d H:i:s')."] PROSES DIBATALKAN: Fitur Auto Email Backup belum diaktifkan di menu Pengaturan -> Backup & Restore.\n");
    }

    $to_email = $conf['email_penerima'];
    echo "[".date('Y-m-d H:i:s')."] Menyiapkan paket untuk dikirim ke: $to_email\n";

    // 2. GENERATE SQL DUMP
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while($row = $result->fetch_row()) { $tables[] = $row[0]; }
    
    $filename_base = 'Backup_ERP_' . date('Ymd_His');
    $sql_dump = "-- SYIFA ERP DATABASE BACKUP (AUTO-MAILER)\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    
    foreach($tables as $table) {
        $row2 = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sql_dump .= $row2[1] . ";\n\n";
        
        $res = $conn->query("SELECT * FROM `$table`");
        if ($res->num_rows > 0) {
            $sql_dump .= "INSERT INTO `$table` VALUES \n";
            $rows_data = [];
            while($row = $res->fetch_assoc()) {
                $vals = array_map(function($v) use ($conn) { 
                    return $v === null ? "NULL" : "'" . $conn->real_escape_string($v) . "'"; 
                }, array_values($row));
                $rows_data[] = "(" . implode(", ", $vals) . ")";
            }
            $sql_dump .= implode(",\n", $rows_data) . ";\n\n";
        }
    }
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // 3. KOMPRESI AGAR BISA MASUK EMAIL (GZIP)
    $gz_data = gzencode($sql_dump, 9);
    $attachment_name = $filename_base . '.sql.gz';
    $encoded_content = chunk_split(base64_encode($gz_data));

    // 4. MERAKIT EMAIL (MULTIPART/MIXED HEADER)
    $subject = "🔒 [AMAN] Auto Backup Database SYIFA ERP - " . date('d M Y');
    $boundary = md5(time());
    
    $from_email = "no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'syifa-erp.local');
    
    $headers = "From: SYIFA ERP System <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
    
    $message = "--$boundary\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= "<h3>Auto Backup Selesai!</h3>
                 <p>Terlampir adalah salinan arsip database sistem (SQL Dump) yang digenerate secara otomatis oleh Cron Job.</p>
                 <p><b>Waktu Backup:</b> " . date('d F Y, H:i:s') . "</p>
                 <p><i>*Ekstrak file .gz ini menggunakan WinRAR/7Zip untuk mendapatkan file .sql murni.</i></p>
                 <hr><small>Sovereign System by Syifa ERP</small>\r\n\r\n";
                 
    $message .= "--$boundary\r\n";
    $message .= "Content-Type: application/x-gzip; name=\"$attachment_name\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"$attachment_name\"\r\n\r\n";
    $message .= $encoded_content . "\r\n\r\n";
    $message .= "--$boundary--";

    // 5. KIRIM EMAIL MUTLAK
    if (mail($to_email, $subject, $message, $headers)) {
        $conn->query("UPDATE sys_backup_email SET last_sent = NOW() WHERE id=1");
        echo "[".date('Y-m-d H:i:s')."] BERHASIL: Email Backup telah terkirim ke $to_email beserta lampiran $attachment_name.\n";
    } else {
        echo "[".date('Y-m-d H:i:s')."] GAGAL: Server SMTP/Mail tidak mengizinkan pengiriman email. (Pastikan hosting Anda mendukung fungsi mail() bawaan PHP).\n";
    }

} catch (Exception $e) {
    echo "[".date('Y-m-d H:i:s')."] GAGAL KRITIKAL: " . $e->getMessage() . "\n";
}
?>