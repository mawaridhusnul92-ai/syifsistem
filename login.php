<?php
/**
 * login.php - GERBANG SISTEM ERP SYIFA
 * Versi: 8.2 (Sovereign Grand Master - Fallback Localhost Ready)
 * Perbaikan Mutlak: 
 * Menambahkan popup khusus jika email gagal terkirim karena sistem dijalankan di Localhost, 
 * memastikan pengembang tetap bisa mencoba fitur lupa password tanpa butuh akses internet/SMTP.
 */
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: index.php?page=dashboard");
    exit;
}

require_once 'config/koneksi.php';

// Tarik Pengaturan Tema & Profil
$appr = null; $profile = null;
try {
    $appr = $conn->query("SELECT * FROM sys_appearance WHERE id=1")->fetch_assoc();
    $profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
} catch (Exception $e) {}

// Terapkan Preferensi User atau Fallback Default
$bg_img = !empty($appr['login_bg']) ? "assets/img/" . $appr['login_bg'] : "https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=2069&auto=format&fit=crop";
$primary_color = !empty($appr['primary_color']) ? $appr['primary_color'] : '#0d6efd';
$font_family = !empty($appr['font_family']) ? $appr['font_family'] : "'Inter', sans-serif";

$app_name = !empty($appr['app_name']) ? $appr['app_name'] : 'SYIFA ERP SYSTEM';
$app_slogan = !empty($appr['app_slogan']) ? $appr['app_slogan'] : 'Enterprise Resource Planning System';
$logo = !empty($profile['logo']) ? "assets/img/" . $profile['logo'] : "";

// Tangkap Pesan Error/Sukses
$err_msg = '';
if(isset($_GET['err'])) {
    $err = $_GET['err'];
    if($err == 'empty') $err_msg = "Username dan Password wajib diisi.";
    elseif($err == 'blocked') $err_msg = "Akses Ditolak: Akun Anda telah dinonaktifkan.";
    elseif($err == 'notfound') $err_msg = "Username tidak terdaftar di sistem.";
    elseif($err == 'wrongpass') $err_msg = "Kunci Sandi (Password) salah.";
    elseif($err == 'empty_email') $err_msg = "Alamat email wajib diisi untuk pemulihan.";
    // 🚀 THE FIX: Pesan Error SMTP jika koneksi / password SMTP gagal di Hosting
    elseif($err == 'smtp_failed') $err_msg = "Gagal mengirim email pemulihan. Pastikan Pengaturan SMTP (Host, Port, atau App Password) di sistem sudah benar.";
    else $err_msg = "Terjadi kesalahan sistem.";
}

$success_msg = '';
if(isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    if($msg == 'reset_sent') {
        $success_msg = "Tautan pemulihan password telah dikirim ke email Anda (Periksa folder Inbox atau Spam).";
    } elseif($msg == 'reset_success') {
        $success_msg = "Password berhasil diubah! Silakan masuk dengan sandi baru Anda.";
    } elseif($msg == 'reset_local') {
        // 🚀 SMART LOCALHOST FALLBACK
        $token = $_GET['token'] ?? '';
        $success_msg = "<div class='text-start p-2'>
                            <b class='text-danger d-block mb-1'><i class='fas fa-tools'></i> MODE DEV (LOCALHOST) AKTIF</b>
                            Server email offline. Gunakan tautan darurat ini untuk menguji Reset Sandi: 
                            <a href='reset_password.php?token=$token' class='btn btn-sm btn-success mt-2 fw-bold text-white shadow-sm w-100 text-decoration-none'>KLIK DI SINI UNTUK RESET</a>
                        </div>";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars($app_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: <?= $font_family ?>; background: url('<?= $bg_img ?>') no-repeat center center fixed; background-size: cover; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(5px); z-index: 1; }
        .login-card { background: rgba(255, 255, 255, 0.95); border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); z-index: 2; overflow: hidden; width: 100%; max-width: 450px; position: relative; }
        .login-header { padding: 40px 30px 20px; text-align: center; }
        .login-body { padding: 0 40px 40px; }
        .logo-img { max-height: 80px; margin-bottom: 15px; }
        
        .form-control { border-radius: 10px; padding: 12px 18px 12px 45px; border: 1.5px solid #cbd5e1; background: #f8fafc; font-size: 14px; font-weight: 600; color: #1e293b; transition: 0.3s; }
        .form-control:focus { border-color: <?= $primary_color ?>; box-shadow: 0 0 0 4px <?= $primary_color ?>30; background: #fff; }
        
        .btn-login { background-color: <?= $primary_color ?>; border: none; border-radius: 50px; padding: 14px; font-weight: 800; font-size: 15px; letter-spacing: 1px; color: white; transition: 0.3s; width: 100%; margin-top: 10px; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 20px <?= $primary_color ?>40; color: white; }
        
        .input-group-custom { margin-bottom: 1rem; }
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-icon { position: absolute; left: 15px; color: #94a3b8; z-index: 5; pointer-events: none; }
        .eye-icon { position: absolute; right: 15px; color: #94a3b8; cursor: pointer; z-index: 5; transition: 0.2s; }
        .eye-icon:hover { color: <?= $primary_color ?>; }
    </style>
</head>
<body>
    <div class="overlay"></div>
    <div class="login-card animate__animated animate__zoomIn">
        <div class="login-header">
            <?php if($logo): ?>
                <img src="<?= $logo ?>" class="logo-img" alt="Logo">
            <?php else: ?>
                <i class="fas fa-university fa-3x mb-3" style="color: <?= $primary_color ?>;"></i>
            <?php endif; ?>
            <h4 class="fw-bold text-dark mb-1"><?= strtoupper($app_name) ?></h4>
            <p class="text-muted small fw-bold"><?= htmlspecialchars($app_slogan) ?></p>
        </div>
        
        <div class="login-body">
            <?php if ($err_msg): ?>
                <div class="alert alert-danger rounded-3 small fw-bold py-3 border-0 shadow-sm text-center mb-4">
                    <i class="fas fa-exclamation-circle me-1"></i> <?= $err_msg ?>
                </div>
            <?php endif; ?>

            <?php if ($success_msg): ?>
                <div class="alert alert-warning rounded-3 small fw-bold py-3 border border-warning shadow-sm text-center mb-4 text-dark">
                    <?= $success_msg ?>
                </div>
            <?php endif; ?>

            <form action="login_action.php" method="POST">
                <div class="input-group-custom">
                    <label class="small fw-bold text-muted mb-1 px-1">Username Akses</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="username" class="form-control" placeholder="Ketik username..." required autofocus>
                    </div>
                </div>
                
                <div class="input-group-custom mt-3">
                    <label class="small fw-bold text-muted mb-1 px-1">Kunci Keamanan</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" id="passInput" class="form-control" placeholder="Masukkan password..." required>
                        <i class="fas fa-eye eye-icon" id="togglePass"></i>
                    </div>
                </div>
                
                <button type="submit" class="btn-login mt-4"><i class="fas fa-sign-in-alt me-2"></i> MASUK SISTEM</button>
            </form>
            
            <!-- 🚀 TAUTAN LUPA PASSWORD -->
            <div class="mt-4 text-center">
                <a href="#" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal" class="text-decoration-none small fw-bold" style="color: <?= $primary_color ?>;"><i class="fas fa-unlock-alt me-1"></i> Lupa Password / Kunci Akses?</a>
            </div>

            <div class="mt-4 text-center">
                <small class="text-muted" style="font-size: 10px;">&copy; <?= date('Y') ?> Divisi IT. Hak Cipta Dilindungi.<br>Secure Authentication Engine v8.2</small>
            </div>
        </div>
    </div>

    <!-- 🚀 MODAL LUPA PASSWORD (SELF-SERVICE) -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header border-0 p-4" style="background-color: <?= $primary_color ?>; color: white; border-radius: 20px 20px 0 0;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-shield-alt me-2"></i>Pemulihan Password</h5>
                    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="login_action.php" method="POST">
                    <input type="hidden" name="action" value="forgot_password">
                    <div class="modal-body p-4 bg-light text-dark">
                        <div class="alert alert-info border-0 bg-info bg-opacity-10 text-dark small fw-bold rounded-3 shadow-sm mb-4">
                            <i class="fas fa-info-circle me-1"></i> Masukkan alamat email yang terdaftar pada akun Anda. Tautan rahasia untuk mereset sandi akan dikirim ke email tersebut.
                        </div>
                        <div class="input-group-custom">
                            <label class="small fw-bold text-muted mb-1 px-1">Email Terdaftar</label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" name="email" class="form-control" placeholder="contoh: anda@institusi.ac.id" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer p-3 border-0 bg-white d-block text-center" style="border-radius: 0 0 20px 20px;">
                        <button type="submit" class="btn-login" style="margin-top:0;">KIRIM LINK PEMULIHAN</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('togglePass').addEventListener('click', function() {
            const inp = document.getElementById('passInput');
            if(inp.type === 'password') {
                inp.type = 'text'; this.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                inp.type = 'password'; this.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    </script>
</body>
</html>