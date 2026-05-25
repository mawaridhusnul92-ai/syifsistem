<?php
/**
 * mailer_engine.php - ENGINE PENGIRIM EMAIL SMTP VIA PHPMAILER
 * Digunakan untuk mengirim Slip Gaji dan Link Lupa Password.
 * Pastikan folder PHPMailer berada di: assets/phpmailer/src/
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Melakukan load library PHPMailer
require_once __DIR__ . '/assets/phpmailer/src/Exception.php';
require_once __DIR__ . '/assets/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/assets/phpmailer/src/SMTP.php';

function kirim_email_smtp($conn, $email_tujuan, $nama_tujuan, $subject, $body, $attachment_path = null, $attachment_name = null) {
    // Ambil konfigurasi SMTP dari database
    $q_smtp = $conn->query("SELECT * FROM sys_smtp WHERE id=1");
    if (!$q_smtp || $q_smtp->num_rows == 0) return false;
    $smtp_config = $q_smtp->fetch_assoc();

    $mail = new PHPMailer(true);
    try {
        // Konfigurasi Server SMTP
        $mail->isSMTP();
        $mail->Host       = $smtp_config['mail_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_config['mail_username'];
        $mail->Password   = $smtp_config['mail_password'];
        
        // Aturan Enkripsi InfinityFree (Otomatis menyesuaikan port)
        $enc = strtolower($smtp_config['mail_encryption']);
        $mail->SMTPSecure = ($enc == 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : (($enc == 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : '');
        
        if (empty($mail->SMTPSecure) && $smtp_config['mail_port'] == 587) $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        if (empty($mail->SMTPSecure) && $smtp_config['mail_port'] == 465) $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        
        $mail->Port       = $smtp_config['mail_port'];

        // Info Pengirim & Penerima
        $mail->setFrom($smtp_config['mail_from_address'], $smtp_config['mail_from_name']);
        $mail->addAddress($email_tujuan, $nama_tujuan);

        // Attachment (Jika ada file fisik)
        if ($attachment_path && file_exists($attachment_path)) {
            $mail->addAttachment($attachment_path, $attachment_name);
        }

        // Konten HTML
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // 🚀 KEMBALI MENGGUNAKAN BODY DINAMIS (Agar Tombol Tautan Slip Gaji Tidak Hilang)
        $mail->Body    = $body;

        // Eksekusi Kirim
        $mail->send();
        return true;
    } catch (Exception $e) {
        // Catat error di background
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>