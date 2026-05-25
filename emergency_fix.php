<?php
/**
 * emergency_fix.php - ALAT PEMULIHAN AKSES DARURAT SYIFA ERP
 * Deskripsi: Mereset kredensial admin dan menyinkronkan hak akses Superadmin.
 * Gunakan hanya saat terkunci dari sistem!
 */
require_once 'config/koneksi.php';

// KONFIGURASI DARURAT
$email_target = 'admin@syifa.com'; // Pastikan email ini yang Bos masukkan saat login
$password_baru = 'admin123';
$hash_password = password_hash($password_baru, PASSWORD_BCRYPT);

echo "<div style='font-family:sans-serif; padding:20px; border:1px solid #ddd; border-radius:10px; max-width:600px; margin:50px auto;'>";
echo "<h2 style='color:#2c3e50; border-bottom:2px solid #eee; padding-bottom:10px;'>?? SYIFA ERP Recovery Tool</h2>";

try {
    // 1. Pastikan Struktur Role Tersedia (Pondasi)
    $conn->query("CREATE TABLE IF NOT EXISTS roles (id INT PRIMARY KEY, role_name VARCHAR(50)) ENGINE=InnoDB");
    $conn->query("INSERT IGNORE INTO roles (id, role_name) VALUES (1, 'Superadmin')");

    // 2. Cari Akun atau Buat Baru jika Tidak Ada
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email_target);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if ($user) {
        $id = $user['id'];
        $stmt_upd = $conn->prepare("UPDATE users SET password = ?, role_id = 1, status = 1 WHERE id = ?");
        $stmt_upd->bind_param("si", $hash_password, $id);
        $stmt_upd->execute();
        echo "<p style='color:green;'>?? <b>SUKSES:</b> Akun <b>$email_target</b> telah diperbarui dan diaktifkan.</p>";
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO users (name, email, password, role_id, status) VALUES ('Administrator', ?, ?, 1, 1)");
        $stmt_ins->bind_param("ss", $email_target, $hash_password);
        $stmt_ins->execute();
        echo "<p style='color:blue;'>?? <b>SUKSES:</b> Akun baru <b>Administrator</b> telah dibuat.</p>";
    }

    // 3. Sinkronisasi Hak Akses (Pencegah Error Sidebar)
    // Mengambil semua menu yang terdaftar untuk diberikan ke Superadmin
    $conn->query("DELETE FROM role_permissions WHERE role_id = 1");
    $conn->query("INSERT INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) 
                  SELECT 1, id, 1, 1, 1, 1 FROM menus");
    
    echo "<p style='color:green;'>?? <b>SUKSES:</b> Hak akses penuh telah disuntikkan ke role Superadmin.</p>";

    echo "<div style='background:#f8f9fa; padding:15px; border-radius:5px; margin-top:20px;'>";
    echo "<strong>Kredensial Login Sekarang:</strong><br>";
    echo "Email/User: <code style='color:#d63939;'>$email_target</code><br>";
    echo "Password: <code style='color:#d63939;'>$password_baru</code>";
    echo "</div>";

    echo "<p style='margin-top:20px;'><a href='index.php' style='display:inline-block; padding:10px 20px; background:#2c3e50; color:white; text-decoration:none; border-radius:5px;'>KEMBALI KE LOGIN</a></p>";
    echo "<small style='color:red;'>*Segera hapus file ini setelah Bos berhasil masuk demi keamanan!</small>";

} catch (Exception $e) {
    echo "<h3 style='color:red;'>? GAGAL: " . $e->getMessage() . "</h3>";
    echo "<p>Pastikan koneksi database Bos aktif di XAMPP.</p>";
}

echo "</div>";