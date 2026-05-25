<?php
/**
 * backup_restore.php - DISASTER RECOVERY & STAGING ENGINE
 * Versi: 7.0 (Enterprise Server Staging & Auto Email Backup Edition)
 * STATUS: FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak:
 * 1. Menerapkan Auto-Healer untuk konfigurasi Auto Email Backup.
 * 2. Menambahkan UI Pengaturan Email Backup di Sidebar Kiri.
 */
if(!isset($conn)) { require_once 'config/koneksi.php'; }

$action = $_POST['action_db'] ?? $_GET['action_db'] ?? '';

// =========================================================================
// 🚀 PERSIAPAN DIREKTORI BACKUP SERVER & AUTO-HEALER TABEL
// =========================================================================
$backup_dir = __DIR__ . '/db_backups/';
if (!file_exists($backup_dir)) {
    @mkdir($backup_dir, 0777, true);
    @file_put_contents($backup_dir . 'index.html', '');
}

try {
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
} catch (Exception $e) {}

// =========================================================================
// 🚀 1. SIMPAN KONFIGURASI AUTO EMAIL BACKUP
// =========================================================================
if ($action == 'save_email_config') {
    $email = $conn->real_escape_string($_POST['email_penerima']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $conn->query("UPDATE sys_backup_email SET email_penerima = '$email', is_active = $is_active WHERE id = 1");
    
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Konfigurasi Auto Email Backup berhasil diperbarui! Pastikan Anda telah mengatur Cron Job.'];
    header("Location: index.php?page=pengaturan_sistem&view=backup"); exit;
}

// =========================================================================
// 🚀 2. BACKUP DATABASE (AUTO-SAVE STAGING & AUTO-DOWNLOAD)
// =========================================================================
if ($action == 'download_backup') {
    $custom_name = trim($_POST['backup_name'] ?? 'Backup_ERP');
    $custom_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $custom_name); 
    if(empty($custom_name)) $custom_name = 'Backup_ERP';
    
    $filename = $custom_name . '_' . date('Ymd_His') . '.sql';
    
    while (ob_get_level()) { @ob_end_clean(); } 
    
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while($row = $result->fetch_row()) { $tables[] = $row[0]; }
    
    $sql_dump = "-- SYIFA ERP DATABASE BACKUP\n-- Nama File: $filename\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    
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

    file_put_contents($backup_dir . $filename, $sql_dump);
    
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Berhasil! Database <b>$filename</b> telah diamankan di server dan <b>sedang diunduh otomatis</b> ke komputer Anda."];
    $_SESSION['auto_download'] = $filename;
    
    header("Location: index.php?page=pengaturan_sistem&view=backup");
    exit;
}

// =========================================================================
// 🚀 3. UPLOAD FILE DATABASE KE STAGING SERVER (TIDAK LANGSUNG RESTORE)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action == 'upload_db') {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] == UPLOAD_ERR_OK) {
        $file_name = strtolower($_FILES['backup_file']['name']);
        if (!str_ends_with($file_name, '.sql')) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Akses Ditolak: Format file harus murni .sql!'];
        } else {
            $safe_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $_FILES['backup_file']['name']);
            if(file_exists($backup_dir . $safe_name)) {
                $safe_name = date('Ymd_Hi_') . $safe_name;
            }
            move_uploaded_file($_FILES['backup_file']['tmp_name'], $backup_dir . $safe_name);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "File <b>$safe_name</b> berhasil diunggah ke Staging Server. Silakan klik tombol 'Terapkan' di tabel sebelah kanan untuk memulihkannya."];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Terjadi kesalahan saat mengunggah file SQL.'];
    }
    header("Location: index.php?page=pengaturan_sistem&view=backup"); exit;
}

// =========================================================================
// 🚀 4. APPLY / RESTORE DATABASE DARI DAFTAR SERVER
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action == 'apply_db') {
    $filename = basename($_POST['file_name']); 
    $filepath = $backup_dir . $filename;
    
    if (file_exists($filepath) && str_ends_with(strtolower($filename), '.sql')) {
        $sql_content = file_get_contents($filepath);
        if ($conn->multi_query($sql_content)) {
            do { if ($res = $conn->store_result()) { $res->free(); } } while ($conn->more_results() && $conn->next_result());
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Sovereign Restore Berhasil! Database ERP kini telah dipulihkan ke posisi file <b>$filename</b>."];
            
            if(class_exists('GlobalLogger')) {
                @GlobalLogger::log($conn, $_SESSION['user_id'] ?? 1, 'Perbarui', 'System', 'database', 0, "Melakukan Restore Full Database dari file: $filename", null, null);
            }
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal memulihkan database. Terdapat kesalahan query: ' . $conn->error];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'File SQL tidak ditemukan di server atau format tidak sah.'];
    }
    header("Location: index.php?page=pengaturan_sistem&view=backup"); exit;
}

// =========================================================================
// 🚀 5. HAPUS DATABASE DARI DAFTAR SERVER
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action == 'delete_db') {
    $filename = basename($_POST['file_name']); 
    $filepath = $backup_dir . $filename;
    if (file_exists($filepath)) {
        unlink($filepath);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Arsip database <b>$filename</b> berhasil dihapus dari server."];
    }
    header("Location: index.php?page=pengaturan_sistem&view=backup"); exit;
}

// =========================================================================
// 🚀 6. DOWNLOAD DATABASE DARI DAFTAR SERVER (FORCE DOWNLOAD)
// =========================================================================
if ($action == 'download_from_list') {
    $filename = basename($_REQUEST['file_name'] ?? ''); 
    $filepath = $backup_dir . $filename;
    
    if (!empty($filename) && file_exists($filepath)) {
        while (ob_get_level()) { @ob_end_clean(); }
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream'); 
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        
        readfile($filepath);
        exit;
    }
}

// =========================================================================
// 🚀 PEMBACA DAFTAR FILE (STAGING SERVER LIST)
// =========================================================================
$files = [];
if (file_exists($backup_dir)) {
    $scan = scandir($backup_dir);
    foreach ($scan as $file) {
        if ($file != '.' && $file != '..' && str_ends_with(strtolower($file), '.sql')) {
            $files[] = [
                'name' => $file,
                'size' => filesize($backup_dir . $file),
                'time' => filemtime($backup_dir . $file)
            ];
        }
    }
    usort($files, function($a, $b) {
        return $b['time'] - $a['time'];
    });
}

// Ambil Konfigurasi Email
$em_conf = $conn->query("SELECT * FROM sys_backup_email WHERE id=1")->fetch_assoc();
?>

<div class="animate__animated animate__fadeIn">
    
    <div class="mt-2 mb-4 p-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-4 d-flex align-items-center">
        <i class="fas fa-shield-alt fa-2x text-warning me-3"></i>
        <div>
            <h6 class="fw-bold text-dark mb-1">Staging Area & Proteksi Data</h6>
            <small class="text-muted fw-bold">Pemisahan Upload dan Restore memastikan Anda dapat mengamankan file <code>.sql</code> terlebih dahulu di server sebelum memutuskan untuk mengeksekusi Pemulihan (Terapkan) sistem.</small>
        </div>
    </div>

    <div class="row g-4">
        <!-- ==================================================== -->
        <!-- SISI KIRI: KONTROL BACKUP, UPLOAD & AUTO EMAIL       -->
        <!-- ==================================================== -->
        <div class="col-lg-4">
            
            <!-- KOTAK BACKUP MANUAL -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4 border-start border-info border-5">
                <div class="d-flex align-items-center mb-3">
                    <i class="fas fa-cloud-download-alt fa-2x text-info me-3"></i>
                    <h5 class="fw-bold text-dark mb-0">Backup Sistem</h5>
                </div>
                <p class="text-muted small">Buat salinan instan. Beri nama file dan sistem akan otomatis mengunduh (download) sekaligus menyimpannya ke daftar server.</p>
                
                <form action="index.php?page=pengaturan_sistem&view=backup" method="POST">
                    <input type="hidden" name="action_db" value="download_backup">
                    <div class="mb-4">
                        <label class="small fw-bold text-dark mb-1">Nama File Backup</label>
                        <div class="input-group input-group-sm shadow-sm rounded-pill overflow-hidden border">
                            <input type="text" name="backup_name" class="form-control border-0 bg-light px-3 py-2 fw-bold" placeholder="Contoh: Backup_Tutup_Buku" value="Backup_ERP_<?= date('dMy_Hi') ?>" required>
                            <span class="input-group-text border-0 bg-light fw-bold">.sql</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-info text-white w-100 rounded-pill fw-bold shadow-sm">
                        <i class="fas fa-download me-2"></i>BUAT & UNDUH BACKUP
                    </button>
                </form>
            </div>

            <!-- KOTAK AUTO BACKUP EMAIL -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4 border-start border-success border-5">
                <div class="d-flex align-items-center mb-3">
                    <i class="fas fa-envelope-open-text fa-2x text-success me-3"></i>
                    <h5 class="fw-bold text-dark mb-0">Auto Email Backup</h5>
                </div>
                <p class="text-muted small">Sistem akan mengirimkan lampiran database <code>.sql</code> ke email Anda secara otomatis jika dipicu oleh Cron Job.</p>
                
                <form action="index.php?page=pengaturan_sistem&view=backup" method="POST">
                    <input type="hidden" name="action_db" value="save_email_config">
                    <div class="mb-3">
                        <label class="small fw-bold text-dark mb-1">Email Penerima Backup</label>
                        <input type="email" name="email_penerima" class="form-control border shadow-sm rounded-pill px-3 py-2 fw-bold" placeholder="admin@email.com" value="<?= htmlspecialchars($em_conf['email_penerima'] ?? '') ?>" required>
                    </div>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="chkEmailActive" <?= ($em_conf['is_active'] == 1) ? 'checked' : '' ?>>
                        <label class="form-check-label small fw-bold text-success" for="chkEmailActive">Aktifkan Pengiriman Email</label>
                    </div>
                    <div class="p-2 bg-light border rounded-3 mb-3 small">
                        <span class="fw-bold text-muted d-block mb-1">URL Trigger (Cron Job):</span>
                        <code style="font-size: 10px; word-wrap: break-word;">http://<?= $_SERVER['HTTP_HOST'] ?>/engine/auto_backup_email.php?token=sY!f4_b4ckUp_M4iL</code>
                    </div>
                    <button type="submit" class="btn btn-success text-white w-100 rounded-pill fw-bold shadow-sm">
                        <i class="fas fa-save me-2"></i>SIMPAN SETTING EMAIL
                    </button>
                </form>
            </div>

            <!-- KOTAK UPLOAD (RESTORE STAGING) -->
            <div class="card border-0 shadow-sm rounded-4 bg-white p-4 border-start border-danger border-5">
                <div class="d-flex align-items-center mb-3">
                    <i class="fas fa-upload fa-2x text-danger me-3"></i>
                    <h5 class="fw-bold text-dark mb-0">Upload Database</h5>
                </div>
                <p class="text-muted small">Unggah <code>.sql</code> dari perangkat lokal Anda ke Daftar Server di sebelah kanan.</p>
                
                <form action="index.php?page=pengaturan_sistem&view=backup" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action_db" value="upload_db">
                    <div class="input-group input-group-sm shadow-sm rounded-pill overflow-hidden border border-danger border-opacity-25 mb-3">
                        <input type="file" name="backup_file" class="form-control border-0 bg-light px-3 py-2 fw-bold" accept=".sql" required>
                    </div>
                    <button type="submit" class="btn btn-outline-danger w-100 rounded-pill fw-bold shadow-sm">
                        <i class="fas fa-arrow-right me-2"></i>UPLOAD KE SERVER
                    </button>
                </form>
            </div>

        </div>

        <!-- ==================================================== -->
        <!-- SISI KANAN: DAFTAR DATABASE DI UPLOAD (SERVER)       -->
        <!-- ==================================================== -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 bg-white p-0 h-100 overflow-hidden">
                <div class="card-header bg-dark text-white p-4 border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0"><i class="fas fa-server text-warning me-2"></i>Daftar Database Server</h5>
                        <small class="opacity-75">Manajemen arsip SQL yang siap diterapkan atau diunduh.</small>
                    </div>
                    <span class="badge bg-white text-dark shadow-sm px-3 py-2 rounded-pill"><i class="fas fa-file-code me-1"></i> <?= count($files) ?> Arsip</span>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-uppercase small text-muted">
                            <tr>
                                <th class="ps-4">Nama File Backup</th>
                                <th>Ukuran</th>
                                <th>Tgl. Upload / Buat</th>
                                <th class="text-center pe-4" width="160">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($files)): foreach($files as $f): 
                                $size = $f['size'];
                                if ($size >= 1048576) { $size_str = number_format($size / 1048576, 2) . ' MB'; }
                                else { $size_str = number_format($size / 1024, 2) . ' KB'; }
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><i class="fas fa-database text-muted me-2"></i><?= htmlspecialchars($f['name']) ?></div>
                                </td>
                                <td><span class="badge bg-light text-dark border px-2 shadow-sm"><?= $size_str ?></span></td>
                                <td class="small fw-bold text-muted"><?= date('d/m/Y H:i', $f['time']) ?></td>
                                <td class="text-center pe-4">
                                    <div class="d-flex justify-content-center gap-1">
                                        <form action="index.php?page=pengaturan_sistem&view=backup" method="POST" class="d-inline" onsubmit="return confirm('PENGHANCURAN DATA DIMULAI:\nAnda yakin ingin Menerapkan file [ <?= $f['name'] ?> ] ini?\n\nSELURUH DATABASE SAAT INI AKAN DITIMPA TOTAL (OVERWRITE) AND TIDAK BISA KEMBALI!')">
                                            <input type="hidden" name="action_db" value="apply_db">
                                            <input type="hidden" name="file_name" value="<?= htmlspecialchars($f['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-success rounded-3 shadow-sm px-2" title="Terapkan ke Sistem (Restore)"><i class="fas fa-play"></i></button>
                                        </form>

                                        <form action="index.php?page=pengaturan_sistem&view=backup" method="POST" class="d-inline">
                                            <input type="hidden" name="action_db" value="download_from_list">
                                            <input type="hidden" name="file_name" value="<?= htmlspecialchars($f['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-primary text-white rounded-3 shadow-sm px-2" title="Unduh ke Perangkat"><i class="fas fa-download"></i></button>
                                        </form>

                                        <form action="index.php?page=pengaturan_sistem&view=backup" method="POST" class="d-inline" onsubmit="return confirm('Hapus permanen file backup [ <?= $f['name'] ?> ] dari daftar server?')">
                                            <input type="hidden" name="action_db" value="delete_db">
                                            <input type="hidden" name="file_name" value="<?= htmlspecialchars($f['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 shadow-sm px-2" title="Hapus Arsip"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted fw-bold">
                                <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-25"></i>
                                Belum ada file database di dalam Staging Server.<br>Silakan Buat Backup atau Unggah file.
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<?php if(isset($_SESSION['auto_download'])): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const downloadUrl = 'index.php?page=pengaturan_sistem&view=backup&action_db=download_from_list&file_name=<?= urlencode($_SESSION['auto_download']) ?>';
        const a = document.createElement('a');
        a.href = downloadUrl;
        a.download = '<?= htmlspecialchars($_SESSION['auto_download']) ?>';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    });
</script>
<?php unset($_SESSION['auto_download']); endif; ?>