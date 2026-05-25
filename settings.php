<?php
/**
 * settings.php - PUSAT PENGATURAN GLOBAL ERP SYIFA
 * Versi: 43.0 (Sovereign Grand Master - Dynamic Browser Title Edition)
 * Perbaikan Mutlak: 
 * Menyuntikkan kolom `browser_title` ke dalam tabel sys_appearance untuk 
 * mendukung fitur White-Label mutlak hingga ke penamaan Tab Browser.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

if (!function_exists('cleanNum')) {
    function cleanNum($val) { return (double)str_replace(['.', ','], '', $val ?? 0); }
}

// =========================================================================
// 1. BACKEND CONTROLLER CENTRAL (PENGOLAH DATA POST & GET)
// =========================================================================

// --- 🛡️ THE BULLETPROOF AUTO-HEALER TABLES ---
try {
    $conn->query("CREATE TABLE IF NOT EXISTS system_signatures ( id INT AUTO_INCREMENT PRIMARY KEY, doc_type VARCHAR(50) NOT NULL, sign_role VARCHAR(100) NOT NULL, sign_name VARCHAR(100) NULL, sign_position VARCHAR(100) NULL )");
    $conn->query("CREATE TABLE IF NOT EXISTS sys_smtp ( id INT AUTO_INCREMENT PRIMARY KEY, mail_host VARCHAR(100), mail_port VARCHAR(10), mail_username VARCHAR(100), mail_password VARCHAR(100), mail_encryption VARCHAR(20), mail_from_name VARCHAR(100), mail_from_address VARCHAR(100) )");
    $conn->query("INSERT IGNORE INTO sys_smtp (id, mail_host) VALUES (1, 'smtp.example.com')");
    
    $conn->query("CREATE TABLE IF NOT EXISTS sys_appearance ( id INT AUTO_INCREMENT PRIMARY KEY, login_bg VARCHAR(255) DEFAULT '', font_family VARCHAR(100) DEFAULT 'Inter, sans-serif', font_size VARCHAR(20) DEFAULT '13px', primary_color VARCHAR(20) DEFAULT '#0d6efd', outline_color VARCHAR(20) DEFAULT '#e2e8f0' )");
    $conn->query("INSERT IGNORE INTO sys_appearance (id) VALUES (1)");

    // 🛡️ Pengecekan Eksistensi Kolom Secara Eksplisit (Termasuk Browser Title)
    $new_cols = [
        'app_name' => "VARCHAR(100) DEFAULT 'Enterprise Resource Planning System'",
        'app_slogan' => "VARCHAR(100) DEFAULT 'Integrated Financial & Asset System'",
        'sidebar_title' => "VARCHAR(50) DEFAULT 'SYIFA ERP'",
        'tab_style' => "VARCHAR(20) DEFAULT 'modern'",
        'browser_title' => "VARCHAR(100) DEFAULT 'SYIFA ERP System'"
    ];

    foreach ($new_cols as $col_name => $col_type) {
        $check_col = $conn->query("SHOW COLUMNS FROM sys_appearance LIKE '$col_name'");
        if ($check_col && $check_col->num_rows == 0) {
            $conn->query("ALTER TABLE sys_appearance ADD COLUMN $col_name $col_type");
        }
    }
    
    $check_col_sp = $conn->query("SHOW COLUMNS FROM system_profile LIKE 'sidebar_slogan'");
    if ($check_col_sp && $check_col_sp->num_rows == 0) {
        $conn->query("ALTER TABLE system_profile ADD COLUMN sidebar_slogan VARCHAR(100) DEFAULT 'Financial Intelligence Center'");
    }
} catch (Exception $e) {}

// 📥 ACTION: BACKUP DATABASE (DITANGKAP VIA GET)
if (isset($_GET['action_db']) && $_GET['action_db'] == 'download_backup') {
    ob_end_clean();
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while($row = $result->fetch_row()) { $tables[] = $row[0]; }
    
    $sql_dump = "-- SYIFA ERP DATABASE BACKUP\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    
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

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="Backup_Database_ERP_'.date('Ymd_His').'.sql"');
    echo $sql_dump;
    exit;
}

// 🚀 TANGKAP SEMUA FORM POST DARI MENU PENGATURAN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $action_smtp = $_POST['action_smtp'] ?? '';
    $action_db = $_POST['action_db'] ?? '';
    $action_appr = $_POST['action_appr'] ?? '';
    
    $conn->begin_transaction();
    try {
        // --- ACTION PENGATURAN UMUM ---
        if (!empty($action)) {
            // Profil Institusi
            if ($action == 'save_profile') {
                $stmt = $conn->prepare("UPDATE system_profile SET institution_name=?, short_name=?, address=?, city=?, province=?, website=?, email=?, phone=? WHERE id=1");
                $stmt->bind_param("ssssssss", $_POST['institution_name'], $_POST['short_name'], $_POST['address'], $_POST['city'], $_POST['province'], $_POST['website'], $_POST['email'], $_POST['phone']);
                $stmt->execute();

                if (!empty($_FILES['logo']['name'])) {
                    $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $logo_name = 'logo_inst_' . time() . '.' . $ext;
                    move_uploaded_file($_FILES['logo']['tmp_name'], 'assets/img/' . $logo_name);
                    $conn->query("UPDATE system_profile SET logo='$logo_name' WHERE id=1");
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profil institusi berhasil diperbarui.'];
            }

            // Tanda Tangan Dokumen
            if ($action == 'save_signature') {
                $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
                $doc_type = $conn->real_escape_string($_POST['doc_type']);
                $sign_role = $conn->real_escape_string($_POST['sign_role']);
                $sign_name = $conn->real_escape_string($_POST['sign_name']);
                $sign_pos = $conn->real_escape_string($_POST['sign_position']);

                if ($id) {
                    $stmt = $conn->prepare("UPDATE system_signatures SET doc_type=?, sign_role=?, sign_name=?, sign_position=? WHERE id=?");
                    $stmt->bind_param("ssssi", $doc_type, $sign_role, $sign_name, $sign_pos, $id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO system_signatures (doc_type, sign_role, sign_name, sign_position) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $doc_type, $sign_role, $sign_name, $sign_pos);
                }
                $stmt->execute();
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Penandatangan dokumen berhasil dikonfigurasi.'];
            }

            if ($action == 'delete_signature') {
                $id = (int)$_POST['id'];
                $conn->query("DELETE FROM system_signatures WHERE id = $id");
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Penandatangan berhasil dihapus dari daftar.'];
            }

            // Akun COA
            if ($action == 'save_account') {
                $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
                $kode = $conn->real_escape_string(trim($_POST['kode']));
                $nama = $conn->real_escape_string(trim($_POST['nama']));
                $kategori = $conn->real_escape_string($_POST['kategori']);
                $is_group = (int)$_POST['is_group'];
                $parent = !empty($_POST['parent_kode']) ? $conn->real_escape_string($_POST['parent_kode']) : NULL;
                $ob = cleanNum($_POST['opening_balance'] ?? 0);
                $cf = $conn->real_escape_string($_POST['cash_flow_category'] ?? 'NONE');

                $nb = $conn->real_escape_string($_POST['normal_balance'] ?? 'DEBIT');
                $rg = !empty($_POST['report_group']) ? $conn->real_escape_string($_POST['report_group']) : NULL;
                $lock = isset($_POST['is_system_lock']) ? 1 : 0;
                $allow = ($is_group == 1) ? 0 : 1;

                $check = $conn->query("SELECT id FROM syifa_akun WHERE kode_akun = '$kode' " . ($id ? "AND id != $id" : ""))->num_rows;
                if ($check > 0) throw new Exception("Kode Akun [$kode] sudah terdaftar. Gunakan kode lain.");

                if ($id) {
                    $stmt = $conn->prepare("UPDATE syifa_akun SET kode_akun=?, nama_akun=?, kategori=?, is_group=?, parent_kode=?, opening_balance=?, cash_flow_category=?, normal_balance=?, report_group=?, is_system_lock=?, allow_posting=? WHERE id=?");
                    $stmt->bind_param("sssisdsssiii", $kode, $nama, $kategori, $is_group, $parent, $ob, $cf, $nb, $rg, $lock, $allow, $id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO syifa_akun (kode_akun, nama_akun, kategori, is_group, parent_kode, opening_balance, cash_flow_category, is_active, normal_balance, report_group, is_system_lock, allow_posting) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)");
                    $stmt->bind_param("sssisdsssii", $kode, $nama, $kategori, $is_group, $parent, $ob, $cf, $nb, $rg, $lock, $allow);
                }
                
                if (!$stmt->execute()) { throw new Exception("Gagal menyimpan data akun: " . $stmt->error); }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Bagan Akun & Tata Kelola berhasil disimpan.'];
            }

            if ($action == 'delete_account') {
                $id = (int)$_POST['id'];
                $cek = $conn->query("SELECT is_system_lock FROM syifa_akun WHERE id=$id")->fetch_assoc();
                if($cek && $cek['is_system_lock'] == 1) { throw new Exception("Akun sistem tidak boleh dihapus."); }
                if (!$conn->query("DELETE FROM syifa_akun WHERE id = $id")) { throw new Exception('Gagal hapus: Pastikan tidak ada transaksi aktif yang mengikat akun ini di Buku Besar.'); }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Akun COA berhasil dihapus dari sistem.'];
            }

            // Default Accounts
            if ($action == 'save_default_accounts') {
                if (isset($_POST['def_coa']) && is_array($_POST['def_coa'])) {
                    foreach ($_POST['def_coa'] as $kode_setting => $coa_id) {
                        $val_coa = !empty($coa_id) ? (int)$coa_id : "NULL";
                        $ks_clean = $conn->real_escape_string($kode_setting);
                        $conn->query("UPDATE setting_akun_default SET coa_id = $val_coa WHERE kode_setting = '$ks_clean'");
                    }
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Pemetaan akun otomatis berhasil diperbarui.'];
                }
            }

            // Auto Number
            if ($action == 'save_auto_number') {
                if (isset($_POST['module_key']) && is_array($_POST['module_key'])) {
                    $stmt = $conn->prepare("UPDATE sys_auto_number SET prefix=?, format=?, seq_length=?, reset_type=? WHERE module_key=?");
                    foreach ($_POST['module_key'] as $i => $key) {
                        $prefix = $_POST['prefix'][$i];
                        $format = $_POST['format'][$i];
                        $length = (int)$_POST['seq_length'][$i];
                        $reset  = $_POST['reset_type'][$i];
                        $stmt->bind_param("ssiss", $prefix, $format, $length, $reset, $key);
                        $stmt->execute();
                    }
                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Format penomoran dokumen berhasil diperbarui.'];
                }
            }
            $conn->commit();
            header("Location: " . $_SERVER['HTTP_REFERER']); exit;
        }

        // --- 🚀 ACTION PENGATURAN PREFERENSI (TEMA & TAMPILAN) ---
        if (!empty($action_appr)) {
            if ($action_appr == 'save_appearance') {
                $font = $conn->real_escape_string($_POST['font_family']);
                $size = $conn->real_escape_string($_POST['font_size']);
                $primary = $conn->real_escape_string($_POST['primary_color']);
                $outline = $conn->real_escape_string($_POST['outline_color']);
                
                // Menangkap Konfigurasi Nama Aplikasi, Slogan, dan Tab Style
                $app_name = $conn->real_escape_string($_POST['app_name']);
                $app_slogan = $conn->real_escape_string($_POST['app_slogan']);
                $sidebar_title = $conn->real_escape_string($_POST['sidebar_title']);
                $tab_style = $conn->real_escape_string($_POST['tab_style']);
                
                // 🛡️ TANGKAP JUDUL TAB BROWSER
                $browser_title = $conn->real_escape_string($_POST['browser_title'] ?? 'SYIFA ERP System');
                
                $sidebar_slogan = $conn->real_escape_string($_POST['sidebar_slogan'] ?? 'Financial Intelligence Center');
                $conn->query("UPDATE system_profile SET sidebar_slogan='$sidebar_slogan' WHERE id=1");

                $conn->query("UPDATE sys_appearance SET font_family='$font', font_size='$size', primary_color='$primary', outline_color='$outline', app_name='$app_name', app_slogan='$app_slogan', sidebar_title='$sidebar_title', tab_style='$tab_style', browser_title='$browser_title' WHERE id=1");
                
                if (!empty($_FILES['login_bg']['name'])) {
                    $ext = pathinfo($_FILES['login_bg']['name'], PATHINFO_EXTENSION);
                    $bg_name = 'login_bg_' . time() . '.' . $ext;
                    move_uploaded_file($_FILES['login_bg']['tmp_name'], 'assets/img/' . $bg_name);
                    $conn->query("UPDATE sys_appearance SET login_bg='$bg_name' WHERE id=1");
                }
                
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Preferensi Tema dan Tampilan berhasil diperbarui secara global.'];
            }
            $conn->commit();
            header("Location: index.php?page=pengaturan_sistem&view=appearance"); exit;
        }

        // --- ACTION PENGATURAN SMTP EMAIL ---
        if (!empty($action_smtp) && $action_smtp == 'save_smtp') {
            $host = $conn->real_escape_string($_POST['mail_host']);
            $port = $conn->real_escape_string($_POST['mail_port']);
            $user = $conn->real_escape_string($_POST['mail_username']);
            $pass = $conn->real_escape_string($_POST['mail_password']);
            $enc  = $conn->real_escape_string($_POST['mail_encryption']);
            $fname= $conn->real_escape_string($_POST['mail_from_name']);
            $faddr= $conn->real_escape_string($_POST['mail_from_address']);

            $conn->query("UPDATE sys_smtp SET mail_host='$host', mail_port='$port', mail_username='$user', mail_password='$pass', mail_encryption='$enc', mail_from_name='$fname', mail_from_address='$faddr' WHERE id=1");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Kredensial SMTP Email berhasil diperbarui.'];
            $conn->commit();
            header("Location: index.php?page=pengaturan_sistem&view=smtp"); exit;
        }

        // --- ACTION RESTORE DATABASE ---
        if (!empty($action_db) && $action_db == 'restore_db') {
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] == UPLOAD_ERR_OK) {
                $file_name = strtolower($_FILES['backup_file']['name']);
                if (!str_ends_with($file_name, '.sql')) {
                    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Format file harus .sql!'];
                } else {
                    $sql_content = file_get_contents($_FILES['backup_file']['tmp_name']);
                    if ($conn->multi_query($sql_content)) {
                        do { if ($res = $conn->store_result()) { $res->free(); } } while ($conn->more_results() && $conn->next_result());
                        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Database ERP berhasil dipulihkan (Restore) ke kondisi semula!'];
                    } else {
                        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal memulihkan database: ' . $conn->error];
                    }
                }
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal mengunggah file SQL.'];
            }
            $conn->commit();
            header("Location: index.php?page=pengaturan_sistem&view=backup"); exit;
        }

    } catch (Exception $e) { 
        $conn->rollback(); 
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()]; 
        header("Location: " . $_SERVER['HTTP_REFERER']); exit;
    }
}

$view = $_GET['view'] ?? 'menu';
?>

<style>
    .card-setting { transition: 0.3s; cursor: pointer; border: 1px solid #f1f5f9; border-radius: 20px; overflow: hidden; }
    .card-setting:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.08) !important; border-color: var(--bs-primary); }
    .view-container { animation: fadeInUp 0.5s ease-out; }
    @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    
    .icon-box-settings {
        width: 65px; height: 65px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 16px; margin: 0 auto 15px;
    }
</style>

<div class="container-fluid py-4">
    
    <!-- 🚀 NAVIGATION HEADER TERPADU DENGAN TOMBOL KEMBALI -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4">
        <div class="d-flex align-items-center gap-3">
            <?php if($view != 'menu'): ?>
                <a href="?page=pengaturan_sistem&view=menu" class="btn btn-outline-dark btn-sm rounded-pill px-4 fw-bold shadow-sm text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
            <?php endif; ?>
            <div>
                <h4 class="fw-bold text-dark mb-0">
                    <i class="fas <?= $view == 'menu' ? 'fa-tools' : 'fa-cog' ?> me-2 text-primary"></i>
                    <?= match($view) {
                        'profile'     => 'Profil Institusi & Logo',
                        'signature'   => 'Pengaturan Tanda Tangan',
                        'coa'         => 'Bagan Akun (COA)',
                        'default_acc' => 'Pemetaan Akun Otomatis',
                        'autonum'     => 'Format Penomoran Dokumen',
                        'appearance'  => 'Tema & Tampilan',
                        'smtp'        => 'Pengaturan Email & SMTP',
                        'backup'      => 'Database Backup & Restore',
                        default       => 'Pengaturan Sistem Global'
                    } ?>
                </h4>
                <small class="text-muted fw-bold">Konfigurasi Parameter Inti ERP</small>
            </div>
        </div>
       
        <?php if($view == 'signature'): ?>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow" onclick="modalAddSig()"><i class="fas fa-plus-circle me-2"></i>Tambah TTD Baru</button>
        <?php endif; ?>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-info-circle me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="view-container">
        <?php if($view == 'menu'): ?>
            <div class="row g-4">
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=profile'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #e0f2fe; color: #0284c7;">
                            <i class="fas fa-university fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Profil Institusi</h6>
                        <p class="small text-muted mb-0">Kelola identitas & logo perusahaan.</p>
                    </div>
                </div>
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=signature'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #fae8ff; color: #a21caf;">
                            <i class="fas fa-file-signature fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Tanda Tangan Dokumen</h6>
                        <p class="small text-muted mb-0">Atur otorisator per jenis laporan.</p>
                    </div>
                </div>
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=coa'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #dcfce7; color: #166534;">
                            <i class="fas fa-sitemap fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Struktur Akun (COA)</h6>
                        <p class="small text-muted mb-0">Manajemen hirarki & kode akun.</p>
                    </div>
                </div>
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=default_acc'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #f3e8ff; color: #7e22ce;">
                            <i class="fas fa-project-diagram fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Akun Otomatis</h6>
                        <p class="small text-muted mb-0">Mapping jurnal sistem belakang layar.</p>
                    </div>
                </div>
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=autonum'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #fef3c7; color: #b45309;">
                            <i class="fas fa-list-ol fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Format Penomoran</h6>
                        <p class="small text-muted mb-0">Atur prefix untuk invoice & kuitansi.</p>
                    </div>
                </div>
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=appearance'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #fce7f3; color: #db2777;">
                            <i class="fas fa-paint-roller fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Tema & Tampilan</h6>
                        <p class="small text-muted mb-0">Nama Sistem, Latar Login & Warna Tab.</p>
                    </div>
                </div>
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=smtp'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #e0e7ff; color: #6d28d9;">
                            <i class="fas fa-envelope-open-text fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Email & SMTP</h6>
                        <p class="small text-muted mb-0">Konfigurasi blast notifikasi email.</p>
                    </div>
                </div>
                <div class="col-md-3" onclick="location.href='?page=pengaturan_sistem&view=backup'">
                    <div class="card card-setting shadow-sm bg-white h-100 p-4 text-center">
                        <div class="icon-box-settings" style="background-color: #ccfbf1; color: #0891b2;">
                            <i class="fas fa-database fa-2x"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">Backup & Restore</h6>
                        <p class="small text-muted mb-0">Amankan dan pulihkan data SQL.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-4 shadow-sm p-4 border">
                <?php 
                    switch($view) {
                        case 'profile': include 'profile.php'; break;
                        case 'signature': include 'signature.php'; break;
                        case 'coa': include 'kode_akun.php'; break;
                        case 'autonum': include 'auto_number.php'; break;
                        case 'default_acc': include 'akun_default.php'; break;
                        case 'appearance': include 'appearance_setting.php'; break;
                        case 'smtp': include 'smtp_setting.php'; break;
                        case 'backup': include 'backup_restore.php'; break;
                        default: echo "Halaman tidak ditemukan."; break;
                    }
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php ob_end_flush(); ?>