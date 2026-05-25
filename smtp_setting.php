<?php
/**
 * smtp_setting.php - EMAIL & NOTIFICATION ENGINE
 * Tempat mengatur kredensial pengiriman email (Gmail/SMTP lainnya).
 */
if(!isset($conn)) { require_once 'config/koneksi.php'; }

$action = $_POST['action_smtp'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action == 'save_smtp') {
    $host = $conn->real_escape_string($_POST['mail_host']);
    $port = $conn->real_escape_string($_POST['mail_port']);
    $user = $conn->real_escape_string($_POST['mail_username']);
    $pass = $conn->real_escape_string($_POST['mail_password']);
    $enc  = $conn->real_escape_string($_POST['mail_encryption']);
    $fname= $conn->real_escape_string($_POST['mail_from_name']);
    $faddr= $conn->real_escape_string($_POST['mail_from_address']);

    $conn->query("UPDATE sys_smtp SET mail_host='$host', mail_port='$port', mail_username='$user', mail_password='$pass', mail_encryption='$enc', mail_from_name='$fname', mail_from_address='$faddr' WHERE id=1");
    
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Kredensial SMTP Email berhasil diperbarui.'];
    header("Location: index.php?page=pengaturan_sistem&view=smtp"); exit;
}

$smtp = $conn->query("SELECT * FROM sys_smtp WHERE id=1")->fetch_assoc();
?>

<div class="animate__animated animate__fadeIn">
    <div class="alert alert-info border-0 bg-info bg-opacity-10 text-dark shadow-sm rounded-4 mb-4 p-4">
        <h6 class="fw-bold mb-2"><i class="fas fa-envelope-open-text me-2 text-info"></i>Mesin Notifikasi Email (SMTP):</h6>
        <p class="mb-0 small">Masukkan kredensial server email Anda (seperti Gmail SMTP, SendGrid, atau Mailgun). Sistem akan menggunakan akses ini untuk secara otomatis mengirimkan <b>Slip Gaji</b> kepada pegawai dan <b>Invoice Jatuh Tempo</b> kepada mahasiswa.</p>
    </div>

    <form action="settings.php" method="POST" class="card border-0 shadow-none">
        <input type="hidden" name="action_smtp" value="save_smtp">
        <div class="row g-4">
            <div class="col-md-6">
                <label class="small fw-bold text-muted mb-1">Mail Host (Server)</label>
                <input type="text" name="mail_host" class="form-control bg-light border-0 shadow-sm py-2 px-3 fw-bold" value="<?= $smtp['mail_host'] ?? 'smtp.gmail.com' ?>" required>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-muted mb-1">Mail Port</label>
                <input type="text" name="mail_port" class="form-control bg-light border-0 shadow-sm py-2 px-3 fw-bold" value="<?= $smtp['mail_port'] ?? '587' ?>" required>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-muted mb-1">Encryption</label>
                <select name="mail_encryption" class="form-select bg-light border-0 shadow-sm py-2 px-3 fw-bold text-primary">
                    <option value="tls" <?= ($smtp['mail_encryption']??'')=='tls'?'selected':'' ?>>TLS</option>
                    <option value="ssl" <?= ($smtp['mail_encryption']??'')=='ssl'?'selected':'' ?>>SSL</option>
                    <option value="none" <?= ($smtp['mail_encryption']??'')=='none'?'selected':'' ?>>NONE</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-muted mb-1">Mail Username (Email Account)</label>
                <input type="text" name="mail_username" class="form-control bg-light border-0 shadow-sm py-2 px-3" value="<?= $smtp['mail_username'] ?? '' ?>" required>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-muted mb-1">Mail Password (App Password)</label>
                <input type="password" name="mail_password" class="form-control bg-light border-0 shadow-sm py-2 px-3" value="<?= $smtp['mail_password'] ?? '' ?>" required>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-muted mb-1">Pengirim (From Name)</label>
                <input type="text" name="mail_from_name" class="form-control bg-light border-0 shadow-sm py-2 px-3 fw-bold" value="<?= $smtp['mail_from_name'] ?? 'SYIFA ERP System' ?>" required>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-muted mb-1">Email Pengirim (From Address)</label>
                <input type="email" name="mail_from_address" class="form-control bg-light border-0 shadow-sm py-2 px-3 fw-bold" value="<?= $smtp['mail_from_address'] ?? 'noreply@institusi.ac.id' ?>" required>
            </div>
        </div>
        <div class="mt-4 text-end">
            <button type="submit" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg"><i class="fas fa-save me-2"></i>SIMPAN KONFIGURASI SMTP</button>
        </div>
    </form>
</div>