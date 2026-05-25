<?php
/**
 * forgot_password.php - SISTEM PEMULIHAN SANDI
 * Deskripsi: Memvalidasi email dan men-generate link reset password.
 */
session_start();
require_once 'config/koneksi.php';

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? AND status = 1");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour')); // Berlaku 1 jam
            
            // Simpan token ke DB
            $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $upd->bind_param("ssi", $token, $expires, $user['id']);
            $upd->execute();
            
            // SIMULASI PENGIRIMAN EMAIL (Karena server lokal/XAMPP biasa tidak punya SMTP)
            // Di environment asli, letakkan fungsi mail() atau PHPMailer di sini.
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            $msg_type = 'success';
            $msg = "Link pemulihan berhasil di-generate.<br><br><b>Simulasi Link Email (Klik):</b><br><a href='$reset_link' class='fw-bold text-success' style='word-break: break-all;'>$reset_link</a>";
            
        } else {
            $msg_type = 'danger';
            $msg = "Email tidak ditemukan atau akun sedang diblokir.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Sandi | SYIFA ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .reset-card { width: 100%; max-width: 400px; background: white; padding: 40px; border-radius: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); text-align: center; }
        .icon-box { width: 70px; height: 70px; background: rgba(13, 110, 253, 0.1); color: #0d6efd; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 28px; }
    </style>
</head>
<body>

<div class="reset-card">
    <div class="icon-box"><i class="fas fa-key"></i></div>
    <h5 class="fw-bold mb-1">Lupa Kata Sandi?</h5>
    <p class="text-muted small mb-4">Masukkan email yang terdaftar. Kami akan mengirimkan tautan untuk mengatur ulang sandi Anda.</p>

    <?php if($msg): ?>
        <div class="alert alert-<?= $msg_type ?> text-start small mb-4 border-0 shadow-sm"><?= $msg ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="email" name="email" class="form-control rounded-pill px-4 py-2 mb-4 bg-light border-0" placeholder="Alamat Email..." required autofocus>
        <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm mb-3">KIRIM LINK PEMULIHAN</button>
        <a href="login.php" class="text-decoration-none text-muted small fw-bold"><i class="fas fa-arrow-left me-1"></i> Kembali ke Login</a>
    </form>
</div>

</body>
</html>