<?php
/**
 * login_action.php - GATEWAY KEAMANAN ERP SYIFA
 * Versi: 2026.9 ULTIMATE - 500 ERROR BULLETPROOF EDITION
 * Perbaikan Mutlak: 
 * Mencegah pemanggilan library PHPMailer pada saat inisialisasi awal.
 * Engine hanya akan dipanggil (include) saat benar-benar dibutuhkan
 * di blok eksekusi Lupa Password, sehingga proses Login normal dijamin 100% lancar.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$action = $_POST['action'] ?? '';

// =========================================================================
// 🛡️ THE AUTO-HEALER (Menyiapkan Kolom Keamanan Kebal Error)
// =========================================================================
try {
    $cek_rt = $conn->query("SHOW COLUMNS FROM users LIKE 'reset_token'");
    if ($cek_rt && $cek_rt->num_rows == 0) {
        @$conn->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(100) NULL");
        @$conn->query("ALTER TABLE users ADD COLUMN reset_expiry DATETIME NULL");
    }
} catch (Exception $e) {}

// =========================================================================
// 🚀 TAHAP 1: EKSEKUTOR KIRIM LINK PEMULIHAN KE EMAIL (FORGOT PASSWORD)
// =========================================================================
if ($action === 'forgot_password') {
    $email = trim($_POST['email'] ?? '');
    if (empty($email)) { header("Location: login.php?err=empty_email"); exit; }

    $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? AND status = 1 LIMIT 1");
    if($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
    } else { $user = null; }

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE id = ?");
        $upd->bind_param("ssi", $token, $expiry, $user['id']);
        $upd->execute();

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $current_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $reset_link = $protocol . $domainName . $current_path . "/reset_password.php?token=" . $token;

        $to = $email;
        $subject = "Pemulihan Kunci Akses (Password) Akun ERP";
        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;'>
                <h2 style='color: #0d6efd; text-align: center;'>Pemulihan Password</h2>
                <p>Halo <b>{$user['name']}</b>,</p>
                <p>Sistem menerima permintaan untuk mengatur ulang kata sandi (password) akun Anda. Jika ini bukan Anda, segera abaikan pesan ini. Akun Anda tetap aman.</p>
                <p>Silakan klik tombol aman di bawah ini untuk mengatur ulang kata sandi Anda. Demi keamanan, tautan ini akan hangus dalam waktu 1 jam.</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$reset_link}' style='background-color: #0d6efd; color: white; padding: 12px 25px; text-decoration: none; border-radius: 50px; font-weight: bold; display: inline-block;'>ATUR ULANG PASSWORD</a>
                </div>
                <hr style='border: 0; border-top: 1px solid #e2e8f0;'>
                <p style='font-size: 11px; color: #64748b; text-align: center;'>Pesan ini dikirim secara otomatis oleh Sistem Cerdas ERP.<br>Harap tidak membalas email ini.</p>
            </div>
        </body>
        </html>
        ";

        // 🛡️ THE ABSOLUTE 500 ERROR FIX: Safe Include Strategy (On Demand)
        $mail_sent = false;
        
        // Cek dulu apakah file fisik PHPMailer BENAR-BENAR ADA untuk menghindari Fatal Crash
        $phpmailer_path = __DIR__ . '/assets/phpmailer/src/PHPMailer.php';
        $mailer_engine_path = __DIR__ . '/mailer_engine.php';
        
        if (file_exists($phpmailer_path) && file_exists($mailer_engine_path)) {
            try {
                // Hanya muat file ini jika sedang dibutuhkan
                require_once $mailer_engine_path;
                
                if (function_exists('kirim_email_smtp')) {
                    $mail_sent = kirim_email_smtp($conn, $to, $user['name'], $subject, $message);
                }
            } catch (Throwable $t) {
                // Tangkap error secara diam-diam agar tidak merusak tampilan (Error 500)
                error_log("Gagal memuat PHPMailer: " . $t->getMessage());
                $mail_sent = false;
            }
        }

        // 🚀 THE FIX: Jika Gagal Kirim Email via PHPMailer
        if (!$mail_sent) {
             $is_local = ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1' || strpos($_SERVER['SERVER_NAME'], '192.168.') !== false);
             
             if ($is_local) {
                 header("Location: login.php?msg=reset_local&token=" . $token);
             } else {
                 header("Location: login.php?err=smtp_failed");
             }
             exit;
        }
    }
    
    header("Location: login.php?msg=reset_sent");
    exit;
}

// =========================================================================
// 🚀 TAHAP 2: EKSEKUTOR UBAH PASSWORD BARU (DARI HALAMAN RESET)
// =========================================================================
if ($action === 'reset_password_submit') {
    $token = trim($_POST['token'] ?? '');
    $pass1 = $_POST['new_password'] ?? '';
    $pass2 = $_POST['confirm_password'] ?? '';

    if (empty($token) || empty($pass1) || empty($pass2)) { header("Location: reset_password.php?token=$token&err=empty"); exit; }
    if ($pass1 !== $pass2) { header("Location: reset_password.php?token=$token&err=mismatch"); exit; }

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expiry > ? AND status = 1 LIMIT 1");
    $stmt->bind_param("ss", $token, $now);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user) {
        $pass_hash = password_hash($pass1, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?");
        $upd->bind_param("si", $pass_hash, $user['id']);
        $upd->execute();
        
        header("Location: login.php?msg=reset_success"); exit;
    } else {
        header("Location: reset_password.php?token=$token&err=invalid"); exit;
    }
}

// =========================================================================
// 🚀 TAHAP 3: SISTEM LOGIN NORMAL (USERNAME & PASSWORD)
// =========================================================================
$username_input = trim($_POST['username'] ?? ''); 
$pass_input     = trim($_POST['password'] ?? '');

if (empty($username_input) || empty($pass_input)) { header("Location: login.php?err=empty"); exit; }

try {
    $sql = "SELECT * FROM users WHERE name = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username_input);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        if ((int)$user['status'] !== 1) { header("Location: login.php?err=blocked"); exit; }

        $is_valid = false;
        $db_pass = $user['password'];

        if (password_verify($pass_input, $db_pass)) { $is_valid = true; } 
        elseif ($pass_input === $db_pass) { $is_valid = true; }

        if ($is_valid) {
            $_SESSION['user_id']   = (int)$user['id'];
            $_SESSION['name']      = $user['name'];
            $_SESSION['email']     = $user['email']; 
            $_SESSION['role_id']   = (int)($user['role_id'] ?? 1);
            
            $r_sql = $conn->query("SELECT role_name, landing_page FROM roles WHERE id = " . $_SESSION['role_id']);
            $r_data = $r_sql->fetch_assoc();
            $_SESSION['role_name'] = $r_data['role_name'] ?? 'Guest';
            $landing_page = !empty($r_data['landing_page']) ? $r_data['landing_page'] : 'dashboard';

            $_SESSION['permissions'] = [];
            $role_id = $_SESSION['role_id'];
            $res_p = $conn->query("SELECT m.menu_key, rp.can_view, rp.can_add, rp.can_edit, rp.can_delete FROM role_permissions rp JOIN menus m ON rp.menu_id = m.id WHERE rp.role_id = $role_id");
            if ($res_p) { while ($p = $res_p->fetch_assoc()) { $_SESSION['permissions'][$p['menu_key']] = $p; } }

            $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . $_SESSION['user_id']);
            header("Location: index.php?page=$landing_page"); exit;
        } else { header("Location: login.php?err=wrongpass"); exit; }
    } else { header("Location: login.php?err=notfound"); exit; }
} catch (Exception $e) { header("Location: login.php?err=system"); exit; }
ob_end_flush();
?>