	<?php
/**
 * emergency_admin.php - SOVEREIGN RECOVERY TOOL
 * Alat ini akan memaksa database untuk mereset/membuat akun Super Admin.
 * PERINGATAN: HAPUS FILE INI SETELAH ANDA BERHASIL LOGIN!
 */
require_once 'config/koneksi.php';

$message = "";

// 🚀 EKSEKUTOR PEMULIHAN
if (isset($_POST['recover'])) {
    // Kredensial Darurat yang akan diatur
    $username = "admin_syifa";
    $password = "rahasia123";
    $pass_hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        // 1. Pastikan Role Superadmin (ID 1) tersedia
        $conn->query("INSERT IGNORE INTO roles (id, role_name) VALUES (1, 'Superadmin')");

        // 2. Cek apakah username sudah ada
        $cek = $conn->query("SELECT id FROM users WHERE name = '$username'");
        
        if ($cek && $cek->num_rows > 0) {
            // Jika ada, paksa ganti passwordnya dan pastikan aktif
            $conn->query("UPDATE users SET password = '$pass_hash', status = 1, role_id = 1 WHERE name = '$username'");
            $message = "<div style='color: #10b981; font-weight: bold;'>✅ Akses Berhasil Dipulihkan!</div>
                        Gunakan Kredensial Berikut:<br>
                        Username: <b>$username</b><br>
                        Password: <b>$password</b>";
        } else {
            // Jika tidak ada, buat akun baru
            $conn->query("INSERT INTO users (name, email, password, role_id, status) VALUES ('$username', 'admin@syifa.com', '$pass_hash', 1, 1)");
            $message = "<div style='color: #10b981; font-weight: bold;'>✅ Akun Super Admin Baru Tercipta!</div>
                        Gunakan Kredensial Berikut:<br>
                        Username: <b>$username</b><br>
                        Password: <b>$password</b>";
        }
    } catch (Exception $e) {
        $message = "<div style='color: #ef4444; font-weight: bold;'>❌ Gagal: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Emergency Recovery</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f172a; color: white; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .box { background: #1e293b; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); text-align: center; max-width: 400px; border-top: 5px solid #ef4444; }
        .btn { background: #ef4444; color: white; border: none; padding: 15px 30px; font-size: 16px; font-weight: bold; border-radius: 50px; cursor: pointer; margin-top: 20px; transition: 0.3s; width: 100%; }
        .btn:hover { background: #dc2626; box-shadow: 0 5px 15px rgba(239,68,68,0.4); }
        .msg { background: #f8fafc; color: #334155; padding: 20px; border-radius: 10px; margin-top: 20px; font-size: 14px; text-align: left; border-left: 5px solid #10b981; }
        .btn-login { display: block; background: #3b82f6; color: white; text-decoration: none; padding: 15px; border-radius: 50px; font-weight: bold; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="margin-top: 0; color: #ef4444;">🛡️ KUNCI DARURAT</h2>
        <p style="font-size: 13px; color: #94a3b8;">Klik tombol di bawah ini untuk mengatur ulang akses Administrator Sistem ke pengaturan pabrik.</p>
        
        <?php if(empty($message)): ?>
            <form method="POST">
                <button type="submit" name="recover" class="btn">RESET SUPER ADMIN SEKARANG</button>
            </form>
        <?php else: ?>
            <div class="msg"><?= $message ?></div>
            <a href="login.php" class="btn-login">KEMBALI KE HALAMAN LOGIN</a>
        <?php endif; ?>
    </div>
</body>
</html>