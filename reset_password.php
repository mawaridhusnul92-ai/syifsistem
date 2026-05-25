<?php
/**
 * reset_password.php - HALAMAN PEMULIHAN SANDI ERP
 * Versi: 1.0 (Sovereign Recovery UI Edition)
 * Deskripsi: Antarmuka tempat user memasukkan password baru mereka setelah
 * mengklik tautan rahasia yang dikirim melalui email.
 */
session_start();
require_once 'config/koneksi.php';

$token = $_GET['token'] ?? '';
if(empty($token)) { die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h4>Akses Ditolak: Token pemulihan sandi tidak valid atau tidak ditemukan.</h4></div>"); }

// Cek apakah tokennya asli dan belum kadaluarsa
$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare("SELECT id, name FROM users WHERE reset_token = ? AND reset_expiry > ? AND status = 1 LIMIT 1");
$stmt->bind_param("ss", $token, $now);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Jika valid, bool = true
$is_valid = $user ? true : false;

// Tarik Pengaturan Tema & Profil agar UI seragam dengan halaman login
$appr = null; $profile = null;
try {
    $appr = $conn->query("SELECT * FROM sys_appearance WHERE id=1")->fetch_assoc();
    $profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
} catch (Exception $e) {}

$bg_img = !empty($appr['login_bg']) ? "assets/img/" . $appr['login_bg'] : "https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=2069&auto=format&fit=crop";
$primary_color = !empty($appr['primary_color']) ? $appr['primary_color'] : '#0d6efd';
$font_family = !empty($appr['font_family']) ? $appr['font_family'] : "'Inter', sans-serif";

$app_name = !empty($appr['app_name']) ? $appr['app_name'] : 'SYIFA ERP SYSTEM';
$logo = !empty($profile['logo']) ? "assets/img/" . $profile['logo'] : "";

// Tangkap Error saat reset gagal (misal salah ketik konfirmasi password)
$err_msg = '';
if(isset($_GET['err'])) {
    $err = $_GET['err'];
    if($err == 'empty') $err_msg = "Semua kolom password wajib diisi!";
    elseif($err == 'mismatch') $err_msg = "Konfirmasi password baru tidak cocok. Coba lagi.";
    elseif($err == 'invalid') $err_msg = "Sesi pemulihan tidak valid atau sudah kedaluwarsa.";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang Password - <?= htmlspecialchars($app_name) ?></title>
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
        
        .btn-login { background-color: <?= $primary_color ?>; border: none; border-radius: 50px; padding: 14px; font-weight: 800; font-size: 15px; letter-spacing: 1px; color: white; transition: 0.3s; width: 100%; margin-top: 10px; display: block; text-decoration: none; }
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
                <i class="fas fa-shield-alt fa-3x mb-3" style="color: <?= $primary_color ?>;"></i>
            <?php endif; ?>
            <h4 class="fw-bold text-dark mb-1">PEMULIHAN SANDI</h4>
            <p class="text-muted small fw-bold">Atur Ulang Kunci Akses Akun Anda</p>
        </div>
        
        <div class="login-body">
            <?php if ($err_msg): ?>
                <div class="alert alert-danger rounded-3 small fw-bold py-3 border-0 shadow-sm text-center mb-4">
                    <i class="fas fa-exclamation-circle me-1"></i> <?= $err_msg ?>
                </div>
            <?php endif; ?>

            <?php if($is_valid): ?>
                <div class="alert alert-success border-0 bg-success bg-opacity-10 text-dark small fw-bold rounded-3 shadow-sm mb-4 text-center">
                    Sesi pemulihan untuk <b><?= htmlspecialchars($user['name']) ?></b> Valid.<br>Silakan buat password baru Anda di bawah ini.
                </div>
                <form action="login_action.php" method="POST">
                    <input type="hidden" name="action" value="reset_password_submit">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    
                    <div class="input-group-custom">
                        <label class="small fw-bold text-muted mb-1 px-1">Ketik Password Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="new_password" id="pass1" class="form-control" placeholder="Sandi baru..." required autofocus>
                            <i class="fas fa-eye eye-icon toggle-eye" data-target="pass1"></i>
                        </div>
                    </div>
                    
                    <div class="input-group-custom mt-3">
                        <label class="small fw-bold text-muted mb-1 px-1">Konfirmasi Password Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-check-circle input-icon"></i>
                            <input type="password" name="confirm_password" id="pass2" class="form-control" placeholder="Ketik ulang sandi baru..." required>
                            <i class="fas fa-eye eye-icon toggle-eye" data-target="pass2"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-login mt-4"><i class="fas fa-save me-2"></i> SIMPAN PASSWORD BARU</button>
                </form>
            <?php else: ?>
                <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger small fw-bold rounded-3 shadow-sm mb-4 text-center p-4">
                    <i class="fas fa-times-circle fa-2x mb-2 d-block"></i>
                    Tautan pemulihan ini tidak valid, sudah digunakan sebelumnya, atau telah kedaluwarsa (kadaluarsa lewat dari 1 jam).
                </div>
                <a href="login.php" class="btn-login text-center"><i class="fas fa-arrow-left me-2"></i> KEMBALI KE LOGIN</a>
            <?php endif; ?>
            
            <div class="mt-4 text-center">
                <small class="text-muted" style="font-size: 10px;">&copy; <?= date('Y') ?> Divisi IT. Hak Cipta Dilindungi.</small>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.toggle-eye').forEach(icon => {
            icon.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const inp = document.getElementById(targetId);
                if(inp.type === 'password') {
                    inp.type = 'text'; this.classList.replace('fa-eye', 'fa-eye-slash');
                } else {
                    inp.type = 'password'; this.classList.replace('fa-eye-slash', 'fa-eye');
                }
            });
        });
    </script>
</body>
</html>