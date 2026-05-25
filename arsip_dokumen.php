<?php
/**
 * arsip_dokumen.php - ENTERPRISE DOCUMENT MANAGEMENT SYSTEM (EDMS)
 * Versi: 171.0 (Executive Visibility Tooltip Edition)
 * Perbaikan: 
 * Mengubah label visibilitas untuk Laporan Unit dari "Otoritas Publik" menjadi
 * "Otoritas Keuangan" dengan penjelasan tooltip yang tepat sasaran.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

if(function_exists('guardPage')) { guardPage('arsip_dokumen'); }

$user_id = (int)$_SESSION['user_id'];
$view = $_GET['view'] ?? 'list';

// =========================================================================
// 1. ENGINE AUTO-HEALER DDL (MEMASTIKAN KOLOM BARU TERSEDIA SECARA OTOMATIS)
// =========================================================================
try {
    $conn->query("ALTER TABLE arsip_dokumen ADD COLUMN kategori_dokumen VARCHAR(100) DEFAULT 'Umum'");
    $conn->query("ALTER TABLE arsip_dokumen ADD COLUMN visibility_type VARCHAR(50) DEFAULT 'SEMUA'");
    $conn->query("ALTER TABLE arsip_dokumen ADD COLUMN visible_to_units TEXT NULL");
} catch (Exception $e) { /* Abaikan jika sudah ada */ }

// =========================================================================
// 2. BACKEND HANDLER: UNGGAH DOKUMEN UMUM & HAPUS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload_arsip_umum') {
    $judul = $conn->real_escape_string($_POST['judul']);
    $kategori = $conn->real_escape_string($_POST['kategori_dokumen']);
    $vis_type = $conn->real_escape_string($_POST['visibility_type']);
    $unit_uploader = (int)($_SESSION['unit_id'] ?? 0);
    
    // Susun String Indexing untuk akses Unit Tertentu (Format: [1][5][10])
    $visible_units = '';
    if ($vis_type == 'UNIT_SPESIFIK' && !empty($_POST['unit_akses'])) {
        foreach ($_POST['unit_akses'] as $u_id) {
            $visible_units .= "[" . (int)$u_id . "]";
        }
    }

    $file_name = null;
    if (!empty($_FILES['dokumen_file']['name'])) {
        $ext = pathinfo($_FILES['dokumen_file']['name'], PATHINFO_EXTENSION);
        $file_name = 'DOC_UMUM_' . time() . '_' . rand(1000,9999) . '.' . $ext;
        $path_dir = 'uploads/arsip_umum/';
        if (!is_dir($path_dir)) mkdir($path_dir, 0777, true);
        move_uploaded_file($_FILES['dokumen_file']['tmp_name'], $path_dir . $file_name);
    }

    if ($file_name) {
        $file_path = 'uploads/arsip_umum/' . $file_name;
        $stmt = $conn->prepare("INSERT INTO arsip_dokumen (unit_id, ref_type, judul, file_path, status, uploaded_at, uploaded_by, kategori_dokumen, visibility_type, visible_to_units) VALUES (?, 'DOKUMEN_UMUM', ?, ?, 'DISETUJUI', NOW(), ?, ?, ?, ?)");
        $stmt->bind_param("ississs", $unit_uploader, $judul, $file_path, $user_id, $kategori, $vis_type, $visible_units);
        
        if ($stmt->execute()) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dokumen berhasil diunggah ke Pusat Arsip.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal menyimpan data ke database.'];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'File dokumen gagal diproses.'];
    }
    header("Location: index.php?page=arsip_dokumen");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'delete_umum' && isset($_GET['id'])) {
    $del_id = (int)$_GET['id'];
    $cek = $conn->query("SELECT file_path, uploaded_by FROM arsip_dokumen WHERE id = $del_id AND ref_type = 'DOKUMEN_UMUM'")->fetch_assoc();
    
    // Keamanan: Hanya Uploader atau Superadmin yang bisa hapus dokumen umum
    $role_name_del = strtolower($_SESSION['role_name'] ?? '');
    if ($cek && ($cek['uploaded_by'] == $user_id || $role_name_del == 'superadmin' || $role_name_del == 'admin')) {
        if (!empty($cek['file_path']) && file_exists($cek['file_path'])) {
            @unlink($cek['file_path']);
        }
        $conn->query("DELETE FROM arsip_dokumen WHERE id = $del_id");
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Dokumen umum berhasil dihapus secara permanen.'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Akses ditolak. Anda tidak memiliki hak menghapus dokumen ini.'];
    }
    header("Location: index.php?page=arsip_dokumen");
    exit;
}

// =========================================================================
// 3. ROLE & VISIBILITY RESOLVER (MATA-MATA SISTEM)
// =========================================================================
$sql_role = "SELECT r.role_name, r.unit_id, r.is_ka_unit FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = '$user_id'";
$u_role = $conn->query($sql_role)->fetch_assoc();

$role_name = strtolower($u_role['role_name'] ?? 'user');

$is_checker = in_array($role_name, ['checker', 'spi', 'admin', 'superadmin', 'pimpinan']) ? 1 : 0;
$is_global_admin = in_array($role_name, ['admin', 'superadmin']) ? 1 : 0;
$is_pimpinan = in_array($role_name, ['pimpinan', 'superadmin', 'admin']) ? 1 : 0;
$is_ka_unit = $u_role['is_ka_unit'] ?? 0;
$mapped_unit_id = (int)($u_role['unit_id'] ?? 0);

$f_unit = $_GET['f_unit'] ?? '';
$f_kategori = $_GET['f_kategori'] ?? '';

// =========================================================================
// 4. SMART QUERY BUILDER DENGAN ISOLASI TINGKAT TINGGI & LEGACY SUPPORT
// =========================================================================
$where_clauses = ["(a.status = 'DISETUJUI' OR a.status IS NULL OR a.status = '')"];

// Filter Kategori dari UI
if ($f_kategori) {
    if ($f_kategori == 'LAPORAN_UNIT') {
        $where_clauses[] = "a.ref_type = 'LAPORAN_UNIT'";
    } else {
        $where_clauses[] = "a.ref_type = 'DOKUMEN_UMUM' AND a.kategori_dokumen = '" . $conn->real_escape_string($f_kategori) . "'";
    }
}

// Filter Unit
if ($f_unit && $is_global_admin) {
    $where_clauses[] = "a.unit_id = " . (int)$f_unit;
}

// RBAC VISIBILITY ENGINE
$rbac_sql = "";
if ($is_global_admin) {
    $rbac_sql = "1=1"; 
} else {
    $my_unit_tag = "\\[" . $mapped_unit_id . "\\]"; 
    
    $rbac_sql = "
    (
        (a.ref_type = 'LAPORAN_UNIT' AND (a.unit_id = $mapped_unit_id OR $is_checker = 1))
        OR
        (a.ref_type = 'DOKUMEN_UMUM' AND (
            a.uploaded_by = $user_id 
            OR a.visibility_type = 'SEMUA' 
            OR (a.visibility_type = 'PIMPINAN' AND $is_pimpinan = 1)
            OR (a.visibility_type = 'UNIT_SPESIFIK' AND a.visible_to_units LIKE '%$my_unit_tag%')
        ))
    )";
}

$where_clauses[] = $rbac_sql;
$where_final = "WHERE " . implode(" AND ", $where_clauses);

$sql_arsip = "SELECT a.*, u.nama_unit, u.kode_unit, r.nama_laporan, r.periode_awal, r.periode_akhir, r.tgl_mulai, r.tgl_selesai, user.name as nama_pengunggah 
              FROM arsip_dokumen a
              LEFT JOIN m_unit u ON a.unit_id = u.id
              LEFT JOIN anggaran_unit_reports r ON a.ref_id = r.id
              LEFT JOIN users user ON a.uploaded_by = user.id
              $where_final ORDER BY a.id DESC";
$res_arsip = $conn->query($sql_arsip);

// Ambil Daftar Unit untuk Dropdown Upload Modal
$list_all_units = $conn->query("SELECT id, nama_unit, kode_unit FROM m_unit ORDER BY nama_unit ASC")->fetch_all(MYSQLI_ASSOC);
?>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-success border-4 text-dark">
        <div class="mb-3 mb-md-0 text-start">
            <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-archive me-2 text-success"></i>Pusat Arsip Dokumen Digital</h4>
            <small class="text-muted fw-bold">Enterprise Document Management System (EDMS) Terintegrasi.</small>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-light text-primary border border-primary rounded-pill px-4 py-2 shadow-sm d-flex align-items-center">
                <i class="fas fa-shield-alt me-2"></i> Hak Akses: <?= strtoupper($role_name) ?>
            </span>
            <?php if($view == 'list'): ?>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#mdlUploadUmum">
                <i class="fas fa-cloud-upload-alt me-2"></i> Unggah Dokumen
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4 text-dark text-start">
            <i class="fas fa-info-circle me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if($view == 'list'): ?>
        <!-- FILTER BAR -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden">
            <div class="card-body p-3">
                <form method="GET" class="row g-2 align-items-center text-start">
                    <input type="hidden" name="page" value="arsip_dokumen">
                    
                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-0 px-3 text-muted"><i class="fas fa-tags"></i></span>
                            <select name="f_kategori" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                                <option value="">-- Semua Kategori Dokumen --</option>
                                <option value="LAPORAN_UNIT" <?= $f_kategori=='LAPORAN_UNIT'?'selected':'' ?>>Laporan Mutasi Keuangan Unit</option>
                                <option value="Laporan" <?= $f_kategori=='Laporan'?'selected':'' ?>>Laporan</option>
                                <option value="Surat Keputusan" <?= $f_kategori=='Surat Keputusan'?'selected':'' ?>>Surat Keputusan (SK)</option>
                                <option value="Bukti Transaksi" <?= $f_kategori=='Bukti Transaksi'?'selected':'' ?>>Bukti Transaksi Khusus</option>
                                <option value="Lainnya" <?= $f_kategori=='Lainnya'?'selected':'' ?>>Lainnya</option>
                            </select>
                        </div>
                    </div>

                    <?php if($is_global_admin): ?>
                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-0 px-3 text-muted"><i class="fas fa-building"></i></span>
                            <select name="f_unit" class="form-select border-0 bg-light fw-bold" onchange="this.form.submit()">
                                <option value="">-- Semua Unit / Lembaga --</option>
                                <?php foreach($list_all_units as $u) echo "<option value='{$u['id']}' ".($f_unit==$u['id']?'selected':'').">{$u['nama_unit']}</option>"; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.88rem;">
                    <thead class="table-dark small text-uppercase text-muted fw-bold">
                        <tr>
                            <th width="120">Tgl Upload</th>
                            <th class="text-start ps-4">Judul Dokumen</th>
                            <th width="130">Unit Pemilik</th>
                            <th width="160">Akses Visibilitas</th>
                            <th width="140">Lampiran Fisik</th>
                            <th width="80" class="text-center pe-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($res_arsip && $res_arsip->num_rows > 0): while($d = $res_arsip->fetch_assoc()): 
                            $is_laporan_unit = ($d['ref_type'] == 'LAPORAN_UNIT');
                            
                            $doc_title = $d['judul'];
                            if ($is_laporan_unit) {
                                if (!empty($d['nama_laporan'])) {
                                    $doc_title = $d['nama_laporan'];
                                } else {
                                    $parts = explode(' - ', $doc_title);
                                    $doc_title = trim($parts[0]);
                                }
                            }
                            
                            $badge_kat = "";
                            if($is_laporan_unit) {
                                $badge_kat = "<span class='badge bg-primary rounded-pill px-2 mt-1' style='font-size:9px;'><i class='fas fa-file-invoice-dollar me-1'></i>LAPORAN KEUANGAN</span>";
                            } else {
                                $kat_color = match($d['kategori_dokumen']) { 'Surat Keputusan'=>'danger', 'Bukti Transaksi'=>'warning text-dark', 'Laporan'=>'info', default=>'secondary' };
                                $badge_kat = "<span class='badge bg-{$kat_color} rounded-pill px-2 mt-1' style='font-size:9px;'><i class='fas fa-file-alt me-1'></i>".strtoupper($d['kategori_dokumen'])."</span>";
                            }

                            // 🛡️ PERBAIKAN: Tooltip & Warna Label Visibilitas Laporan Unit
                            $vis_text = "";
                            if ($is_laporan_unit) {
                                $vis_text = "<span class='badge bg-light text-warning border border-warning px-2 py-1 text-dark' data-bs-toggle='tooltip' data-bs-placement='top' title='Hanya dapat dilihat oleh Pimpinan dan Bagian Keuangan (Otoritas Terbatas)'><i class='fas fa-lock me-1'></i>Otoritas Keuangan</span>";
                            } else {
                                $vis_text = match($d['visibility_type']) {
                                    'SEMUA' => "<span class='badge bg-light text-success border border-success px-2 py-1' data-bs-toggle='tooltip' data-bs-placement='top' title='Dapat dilihat oleh semua orang yang memiliki akun SYIFA ERP'><i class='fas fa-globe me-1'></i>Semua Orang</span>",
                                    'SENDIRI' => "<span class='badge bg-light text-secondary border border-secondary px-2 py-1'><i class='fas fa-lock me-1'></i>Privat (Sendiri)</span>",
                                    'PIMPINAN' => "<span class='badge bg-light text-warning border border-warning px-2 py-1 text-dark'><i class='fas fa-user-tie me-1'></i>Pimpinan Saja</span>",
                                    'UNIT_SPESIFIK' => "<span class='badge bg-light text-info border border-info px-2 py-1' data-bs-toggle='tooltip' data-bs-placement='top' title='Hanya dapat dilihat oleh unit/lembaga tertentu yang telah diberikan akses khusus'><i class='fas fa-users-cog me-1'></i>Unit Terbatas</span>",
                                    default => ""
                                };
                            }
                        ?>
                        <tr>
                            <td class="text-center">
                                <div class="fw-bold text-dark"><?= $d['uploaded_at'] ? date('d/m/Y', strtotime($d['uploaded_at'])) : '-' ?></div>
                                <small class="text-muted"><?= $d['uploaded_at'] ? date('H:i', strtotime($d['uploaded_at'])) : '' ?></small>
                            </td>
                            <td class="text-start ps-4">
                                <a href="?page=arsip_dokumen&view=preview&id=<?= $d['id'] ?>" class="text-decoration-none text-dark fw-bold fs-6 d-block hover-zoom" title="Tinjau detail dokumen">
                                    <i class="fas fa-file-pdf text-danger me-1"></i> <?= htmlspecialchars($doc_title) ?>
                                </a>
                                <?= $badge_kat ?>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-4 py-2 fw-bold shadow-sm" style="font-family: 'JetBrains Mono', monospace; font-size: 12px; letter-spacing: 0.5px;">
                                    <?= $d['kode_unit'] ?? 'SYS' ?>
                                </span>
                            </td>
                            <td><?= $vis_text ?></td>
                            <td>
                                <?php if(!empty($d['file_path'])): ?>
                                    <a href="<?= htmlspecialchars($d['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold shadow-sm"><i class="fas fa-download me-1"></i> File PDF</a>
                                <?php else: ?>
                                    <span class="text-muted small italic opacity-50">Tidak Ada File</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-3">
                                <?php 
                                    $can_delete = $is_global_admin || (!$is_laporan_unit && $d['uploaded_by'] == $user_id);
                                ?>
                                <div class="btn-group btn-group-sm rounded-pill overflow-hidden border shadow-sm">
                                    <a href="?page=arsip_dokumen&view=preview&id=<?= $d['id'] ?>" class="btn btn-white text-primary px-2" title="Lihat"><i class="fas fa-eye"></i></a>
                                    <?php if($can_delete): ?>
                                        <button class="btn btn-white text-danger border-start px-2" onclick="hapusArsipEDMS(<?= (int)$d['id'] ?>, '<?= $d['ref_type'] ?>')" title="Hapus Dokumen"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="6" class="py-5 text-center text-muted italic">
                                <i class="fas fa-folder-open fa-4x opacity-25 mb-3 text-secondary d-block"></i>
                                Belum ada dokumen yang tersimpan di dalam repositori arsip saat ini.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php elseif ($view == 'preview'): ?>
        <!-- VIEW: DEDICATED PDF PREVIEW (SMART DUAL-MODE) -->
        <?php 
            $arsip_id = (int)$_GET['id'];
            
            $sql_doc = "SELECT a.*, u.nama_unit, r.nama_laporan, r.periode_awal, r.periode_akhir, r.tgl_mulai, r.tgl_selesai, user.name as nama_pengunggah 
                        FROM arsip_dokumen a
                        LEFT JOIN m_unit u ON a.unit_id = u.id
                        LEFT JOIN anggaran_unit_reports r ON a.ref_id = r.id
                        LEFT JOIN users user ON a.uploaded_by = user.id
                        WHERE a.id = $arsip_id";
            
            $res_doc = $conn->query($sql_doc);
            $doc = ($res_doc && $res_doc->num_rows > 0) ? $res_doc->fetch_assoc() : null;
            
            if(!$doc) {
                echo "<div class='alert alert-danger rounded-4 shadow-sm fw-bold text-center py-4'><i class='fas fa-exclamation-triangle fa-2x mb-3 d-block'></i>Dokumen tidak ditemukan atau akses ditolak.</div>";
            } else {
                $file_url = $doc['file_path'];
                $has_file = (!empty($file_url) && file_exists($file_url));
                $is_laporan_unit = ($doc['ref_type'] == 'LAPORAN_UNIT');
                
                $doc_title_view = $doc['judul'];
                if ($is_laporan_unit) {
                    $doc_title_view = !empty($doc['nama_laporan']) ? $doc['nama_laporan'] : trim(explode(' - ', $doc['judul'])[0]);
                }
        ?>
        <div class="row g-4 text-start">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100 bg-white">
                    <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0"><i class="fas fa-file-pdf me-2 text-danger"></i>File Viewer: <?= htmlspecialchars($doc_title_view) ?></h6>
                        <div class="d-flex gap-2">
                            <?php if($has_file): ?>
                            <a href="<?= htmlspecialchars($file_url) ?>" target="_blank" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-bold shadow-sm"><i class="fas fa-external-link-alt me-1"></i> Buka Penuh</a>
                            <?php endif; ?>
                            <a href="?page=arsip_dokumen" class="btn btn-sm btn-light rounded-pill px-4 fw-bold text-dark"><i class="fas fa-arrow-left me-1"></i> KEMBALI</a>
                        </div>
                    </div>
                    <div class="card-body p-0 bg-light d-flex align-items-center justify-content-center" style="min-height: 750px;">
                        <?php if($has_file): ?>
                            <iframe src="<?= htmlspecialchars($file_url) ?>" width="100%" height="100%" style="border:none; min-height: 750px;"></iframe>
                        <?php else: ?>
                            <div class="text-center text-muted">
                                <i class="fas fa-file-excel fa-4x opacity-25 mb-3"></i>
                                <h5>File dokumen fisik tidak ditemukan di server.</h5>
                                <code class="small"><?= htmlspecialchars($file_url) ?></code>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-4">
                    <div class="card-header bg-primary text-white p-3"><h6 class="fw-bold mb-0"><i class="fas fa-info-circle me-2"></i>Metadata Dokumen</h6></div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Judul Dokumen</small>
                            <div class="fw-bold fs-6 text-dark"><?= htmlspecialchars($doc_title_view) ?></div>
                        </div>
                        <div class="mb-3">
                            <small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Lembaga / Unit Pemilik</small>
                            <div class="fw-bold text-primary"><?= htmlspecialchars($doc['nama_unit'] ?? 'Sistem Global') ?></div>
                        </div>

                        <?php if($is_laporan_unit): 
                            $p_awal = $doc['periode_awal'] ?? $doc['tgl_mulai'] ?? '';
                            $p_akhir = $doc['periode_akhir'] ?? $doc['tgl_selesai'] ?? '';
                        ?>
                            <div class="mb-3"><small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Kategori</small><span class="badge bg-primary px-3 mt-1 rounded-pill">Laporan Mutasi Keuangan</span></div>
                            <div class="mb-3"><small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Periode Audit</small><div class="fw-bold text-dark"><?= ($p_awal ? date('d M Y', strtotime($p_awal)) : '-') ?> s/d <?= ($p_akhir ? date('d M Y', strtotime($p_akhir)) : '-') ?></div></div>
                            <div class="mb-0"><small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Waktu Disetujui</small><div class="text-dark fw-bold"><?= !empty($doc['approved_at']) ? date('d M Y, H:i', strtotime($doc['approved_at'])) : date('d M Y, H:i', strtotime($doc['uploaded_at'])) ?></div></div>
                            
                            <hr class="my-4">
                            <?php if(!empty($doc['ref_id'])): ?>
                            <div class="mb-1 text-center">
                                <label class="small fw-bold text-success mb-2 d-block text-start"><i class="fas fa-print me-1"></i> Laporan Jurnal Sistem</label>
                                <a href="laporan_anggaran_unit.php?id=<?= $doc['ref_id'] ?>" target="_blank" class="btn btn-dark w-100 rounded-pill fw-bold shadow py-3">
                                    <i class="fas fa-file-invoice-dollar me-2"></i> BUKA LAPORAN SISTEM
                                </a>
                                <small class="text-muted d-block mt-2" style="font-size: 10px;">Akses untuk melihat rekapitulasi data saldo & mutasi secara otomatis.</small>
                            </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <div class="mb-3"><small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Kategori Dokumen</small><div class="fw-bold text-dark"><?= strtoupper(htmlspecialchars($doc['kategori_dokumen'])) ?></div></div>
                            <div class="mb-3"><small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Waktu Diunggah</small><div class="fw-bold text-dark"><?= date('d F Y, H:i', strtotime($doc['uploaded_at'])) ?> WIB</div></div>
                            <div class="mb-3"><small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Diupload Oleh</small><div class="fw-bold text-dark"><i class="fas fa-user-edit me-1 text-muted"></i><?= htmlspecialchars($doc['nama_pengunggah'] ?? 'System') ?></div></div>
                            
                            <hr class="my-3">
                            <div class="mb-0">
                                <small class="text-muted fw-bold d-block uppercase mb-1" style="font-size:10px;">Aturan Visibilitas (Keamanan Akses)</small>
                                <?php 
                                    if ($doc['visibility_type'] == 'SEMUA') echo "<div class='alert alert-success border-0 py-2 px-3 small fw-bold mb-0'><i class='fas fa-globe me-2'></i>Dokumen ini bersifat Publik/Dilihat Semua.</div>";
                                    else if ($doc['visibility_type'] == 'SENDIRI') echo "<div class='alert alert-secondary border-0 py-2 px-3 small fw-bold mb-0'><i class='fas fa-lock me-2'></i>Dokumen ini bersifat Privat (Hanya Anda).</div>";
                                    else if ($doc['visibility_type'] == 'PIMPINAN') echo "<div class='alert alert-warning border-0 py-2 px-3 small fw-bold mb-0 text-dark'><i class='fas fa-user-tie me-2'></i>Dokumen Khusus Level Pimpinan.</div>";
                                    else if ($doc['visibility_type'] == 'UNIT_SPESIFIK') echo "<div class='alert alert-info border-0 py-2 px-3 small fw-bold mb-0'><i class='fas fa-users-cog me-2'></i>Dibatasi untuk Unit Tertentu.</div>";
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>
    <?php endif; ?>
</div>

<!-- MODAL UPLOAD DOKUMEN UMUM (EDMS) -->
<div class="modal fade" id="mdlUploadUmum" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-start">
        <form action="?page=arsip_dokumen" method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="upload_arsip_umum">
            <div class="modal-header bg-primary text-white p-4 border-0 d-flex justify-content-between align-items-center">
                <h5 class="modal-title fw-bold"><i class="fas fa-cloud-upload-alt me-2"></i>Unggah Dokumen Arsip</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Judul Dokumen</label>
                    <input type="text" name="judul" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="Contoh: SK Rektor Tahun 2026..." required>
                </div>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Jenis Dokumen</label>
                        <select name="kategori_dokumen" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold text-dark" required>
                            <option value="Laporan">Laporan</option>
                            <option value="Surat Keputusan">Surat Keputusan (SK)</option>
                            <option value="Bukti Transaksi">Bukti Transaksi</option>
                            <option value="Lainnya">Lainnya / Umum</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Pilih File (PDF Diutamakan)</label>
                        <input type="file" name="dokumen_file" class="form-control rounded-pill border-0 shadow-sm px-3 bg-white" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                    </div>
                </div>

                <hr class="my-4">
                <label class="small fw-bold text-danger mb-2 uppercase d-block"><i class="fas fa-shield-alt me-1"></i>Pengaturan Hak Akses (Visibility)</label>
                
                <div class="bg-white p-3 rounded-4 shadow-sm border">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="visibility_type" id="vis_semua" value="SEMUA" checked onclick="toggleUnitSelect(false)">
                        <label class="form-check-label fw-bold text-dark" for="vis_semua">Dilihat Semua (Publik Internal)</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="visibility_type" id="vis_sendiri" value="SENDIRI" onclick="toggleUnitSelect(false)">
                        <label class="form-check-label fw-bold text-dark" for="vis_sendiri">Simpan Sendiri (Private)</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="visibility_type" id="vis_pimpinan" value="PIMPINAN" onclick="toggleUnitSelect(false)">
                        <label class="form-check-label fw-bold text-dark" for="vis_pimpinan">Khusus Pimpinan (Top Level Only)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="visibility_type" id="vis_unit" value="UNIT_SPESIFIK" onclick="toggleUnitSelect(true)">
                        <label class="form-check-label fw-bold text-dark" for="vis_unit">Khusus Unit Lain (Pilih Unit)</label>
                    </div>

                    <div class="mt-3 d-none p-3 bg-light rounded-3 border" id="boxPilihUnit">
                        <label class="small fw-bold text-muted mb-2">Ceklis Unit yang dizinkan melihat:</label>
                        <div style="max-height: 150px; overflow-y: auto;">
                            <?php foreach($list_all_units as $ul): ?>
                            <div class="form-check mb-1">
                                <input class="form-check-input chk-unit-akses" type="checkbox" name="unit_akses[]" value="<?= $ul['id'] ?>" id="chk_u_<?= $ul['id'] ?>">
                                <label class="form-check-label small text-dark" for="chk_u_<?= $ul['id'] ?>">
                                    <?= htmlspecialchars($ul['nama_unit']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow"><i class="fas fa-upload me-2"></i> UPLOAD & SIMPAN DOKUMEN</button>
            </div>
        </form>
    </div>
</div>

<style>
    .hover-zoom { transition: 0.2s; }
    .hover-zoom:hover { color: #0d6efd !important; text-decoration: underline !important; }
    .btn-white { background: #fff; border: none; transition: 0.2s; }
    .btn-white:hover { background: #f8f9fa; color: #0d6efd !important; }
    
    .tooltip-inner { background-color: #1e293b; color: #fff; font-size: 11px; padding: 8px 12px; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .tooltip.bs-tooltip-top .tooltip-arrow::before { border-top-color: #1e293b; }
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

function toggleUnitSelect(show) {
    const box = document.getElementById('boxPilihUnit');
    const checkboxes = document.querySelectorAll('.chk-unit-akses');
    if (show) {
        box.classList.remove('d-none');
        checkboxes.forEach(chk => chk.checked = false);
    } else {
        box.classList.add('d-none');
    }
}

function hapusArsipEDMS(id, type) {
    let msg = 'Apakah Anda yakin ingin menghapus dokumen arsip ini secara permanen?';
    let url = '';
    
    if (type === 'LAPORAN_UNIT') {
        msg = 'PENTING: Menghapus Laporan Unit ini akan membatalkan status terkirim dan mengembalikannya ke DRAFT pada unit pengirim. Lanjutkan?';
        url = `budget_unit_action.php?action=delete_arsip_laporan&id=${id}`;
    } else {
        url = `?page=arsip_dokumen&action=delete_umum&id=${id}`;
    }

    if(confirm(msg)) {
        window.location.href = url;
    }
}
</script>