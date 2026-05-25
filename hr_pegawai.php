<?php
/**
 * hr_pegawai.php - DATABASE SDM INSTITUSI
 * Versi: 16.3 (Sovereign Grand Master - Ultimate UI Match & Schema Fix)
 * Perbaikan: 
 * 1. Menyelaraskan nama kolom menjadi `status_pegawai` agar data muncul kembali.
 * 2. Menyelaraskan ukuran dan warna tombol Import/Tambah PERSIS seperti modul Mahasiswa.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

if(function_exists('guardPage')) { guardPage('pegawai'); }

$search = $_GET['q'] ?? '';
$saran_jabatan = $conn->query("SELECT DISTINCT jabatan FROM hr_pegawai WHERE jabatan != '' ORDER BY jabatan ASC");

// Pagination
$limit = 20;
$page_num = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
if ($page_num < 1) $page_num = 1;
$offset = ($page_num - 1) * $limit;

// ??? FIX MUTLAK: Filter dilonggarkan agar semua data tampil
$where = "1=1";
if ($search) {
    $q_esc = $conn->real_escape_string($search);
    $where .= " AND (nama_lengkap LIKE '%$q_esc%' OR nip LIKE '%$q_esc%' OR unit_kerja LIKE '%$q_esc%')";
}

$total_rows = $conn->query("SELECT COUNT(id) as t FROM hr_pegawai WHERE $where")->fetch_assoc()['t'] ?? 0;
$total_pages = ceil($total_rows / $limit);

$pegawai = $conn->query("SELECT * FROM hr_pegawai WHERE $where ORDER BY nama_lengkap ASC LIMIT $limit OFFSET $offset");
?>

<style>
    .avatar-circle { width: 40px; height: 40px; background: #f1f5f9; color: #64748b; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; }
    .table-hr thead th { background: #1e293b !important; color: #fff !important; font-size: 10px; text-transform: uppercase; padding: 12px 15px; border: none; }
    .table-hr tbody td { font-size: 13px; border-bottom: 1px dashed #e2e8f0; padding: 15px; vertical-align: middle; color: #334155; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4">
        <div>
            <h4 class="fw-bold text-dark mb-0"><i class="fas fa-users-cog me-2 text-primary"></i>Database Pegawai Aktif</h4>
            <small class="text-muted fw-bold">Kelola data SDM dan status keaktifan institusi SYIFA.</small>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="input-group shadow-sm rounded-pill overflow-hidden border" style="width: 250px;">
                <input type="hidden" name="page" value="pegawai">
                <input type="text" name="q" class="form-control border-0 bg-light px-3 fw-bold" placeholder="Cari NIP atau Nama..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button type="submit" class="btn btn-primary px-3 border-0"><i class="fas fa-search"></i></button>
            </form>
            
            <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
            <!-- ??? PERBAIKAN TOMBOL: Identik 100% dengan Modul Mahasiswa (btn-lg, kapital, ikon) -->
            <button class="btn btn-success btn-lg rounded-pill fw-bold shadow-sm text-uppercase" onclick="showModalImportPegawai()">
                <i class="fas fa-file-csv me-2"></i>IMPORT DATA CSV
            </button>
            <button class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm text-uppercase" onclick="showModalPegawai()">
                <i class="fas fa-plus me-2"></i>TAMBAH PEGAWAI
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas <?= $_SESSION['flash']['type']=='success'?'fa-check-circle':'fa-exclamation-triangle' ?> me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- MAIN TABLE CARD -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-dark">
                <thead class="bg-light small text-uppercase fw-bold text-muted">
                    <tr>
                        <th class="ps-4 py-3">Identitas Pegawai</th>
                        <th class="py-3">Jabatan & Unit</th>
                        <th class="py-3 text-center">Status Kerja</th>
                        <th class="py-3">Kontak Info</th>
                        <th class="text-center pe-4 py-3" width="120">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if($pegawai && $pegawai->num_rows > 0): while($p = $pegawai->fetch_assoc()): 
                        $status_aktif = (int)($p['status_aktif'] ?? 0);
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-user-circle fa-2x text-muted me-3 opacity-25"></i>
                                <div>
                                    <div class="fw-bold text-dark mb-1"><?= strtoupper($p['nama_lengkap']) ?></div>
                                    <code class="small text-muted bg-transparent p-0 border-0" style="font-size: 11px;"><?= $p['nip'] ?></code>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold small text-dark"><?= $p['jabatan'] ?: '<span class="text-muted italic">Staf Umum</span>' ?></div>
                            <div class="text-muted small"><?= $p['unit_kerja'] ?></div>
                        </td>
                        <td class="text-center">
                            <!-- ??? FIX MUTLAK: Menggunakan status_pegawai sesuai nama kolom di DB asli -->
                            <span class="badge bg-light text-dark border px-3 py-1 mb-1 d-inline-block"><?= $p['status_pegawai'] ?? 'Tetap' ?></span><br>
                            <span class="small <?= $status_aktif ? 'text-success' : 'text-danger' ?> fw-bold" style="font-size: 10px;">
                                <i class="fas fa-circle me-1" style="font-size:7px;"></i><?= $status_aktif ? 'AKTIF BEKERJA' : 'NON-AKTIF' ?>
                            </span>
                        </td>
                        <td class="small text-muted fw-bold">
                            <i class="fas fa-envelope me-2"></i> <?= $p['email'] ?: '-' ?><br>
                            <i class="fas fa-phone me-2"></i> <?= $p['no_hp'] ?: '-' ?>
                        </td>
                        <td class="text-center pe-4">
                            <div class="btn-group btn-group-sm rounded-pill border overflow-hidden shadow-sm bg-white">
                                <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                    <button class="btn btn-white text-warning border-end px-3" onclick='editPegawai(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8") ?>)' title="Ubah Data"><i class="fas fa-edit"></i></button>
                                <?php endif; ?>
                                <?php if(defined('RBAC_DEL') && RBAC_DEL): ?>
                                    <button class="btn btn-white text-danger px-3" onclick="confirmDelete(<?= $p['id'] ?>)" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted italic">Belum ada data pegawai yang terdaftar.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- PAGINATION -->
        <?php if($total_pages > 1): ?>
        <div class="card-footer bg-white border-top p-3 d-flex justify-content-center">
            <nav><ul class="pagination pagination-sm mb-0 shadow-sm rounded-pill overflow-hidden">
                <li class="page-item <?= ($page_num <= 1) ? 'disabled' : '' ?>"><a class="page-link px-3" href="?page=pegawai&q=<?= urlencode($search) ?>&page_num=<?= $page_num - 1 ?>">Sebelumnya</a></li>
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= ($i == $page_num) ? 'active' : '' ?>"><a class="page-link" href="?page=pegawai&q=<?= urlencode($search) ?>&page_num=<?= $i ?>"><?= $i ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= ($page_num >= $total_pages) ? 'disabled' : '' ?>"><a class="page-link px-3" href="?page=pegawai&q=<?= urlencode($search) ?>&page_num=<?= $page_num + 1 ?>">Selanjutnya</a></li>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ?? MODAL IMPORT PURE CSV PEGAWAI -->
<div class="modal fade" id="modalImportPegawai" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form action="hr_action.php" method="POST" enctype="multipart/form-data" class="modal-content rounded-4 border-0 shadow-lg text-dark">
            <input type="hidden" name="action" value="import_pegawai">
            <div class="modal-header bg-success text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-csv me-2"></i>Import Data Pegawai (CSV)</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-start">
                <div class="alert bg-success bg-opacity-10 border border-success border-opacity-25 rounded-4 mb-4 small shadow-sm text-dark">
                    <h6 class="fw-bold mb-2 text-success"><i class="fas fa-info-circle me-1"></i> Panduan Import CSV:</h6>
                    <ol class="mb-0 ps-3" style="line-height: 1.6; color: #1e293b !important;">
                        <li style="color: #1e293b !important;">Unduh template referensi melalui tombol di bawah. Template menggunakan koma atau titik-koma.</li>
                        <li style="color: #1e293b !important;">Isi data SDM Anda sesuai format kolom. Jangan mengubah nama judul kolom di baris pertama.</li>
                        <li style="color: #1e293b !important;">Buka di Excel dan simpan <strong style="color: #1e293b !important;">(Save As)</strong> menggunakan format <b style="color: #1e293b !important;">CSV (Comma delimited) (*.csv)</b>.</li>
                        <li style="color: #1e293b !important;">Unggah file CSV Anda ke form di bawah ini. Mesin baru kami kini kebal terhadap error titik-koma/koma! Jika NIP sudah ada, sistem otomatis memperbaruinya.</li>
                    </ol>
                </div>
                <div class="text-center mb-4 border-bottom pb-4 border-secondary border-opacity-25">
                    <a href="hr_action.php?action=download_template_pegawai" class="btn btn-outline-success rounded-pill px-5 py-2 fw-bold shadow-sm"><i class="fas fa-download me-2"></i>Unduh Template (.CSV)</a>
                </div>
                <div class="mb-2 text-center position-relative">
                    <label class="small fw-bold text-muted mb-3 text-uppercase"><i class="fas fa-upload me-1"></i> Unggah File CSV Anda Di Sini</label>
                    <input type="file" name="file_import" id="file_import_csv" class="form-control form-control-lg border-success border-2 shadow-sm rounded-4 px-4 py-3 bg-white text-center fw-bold" accept=".csv" required onchange="validateCSV(this)">
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white">
                <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill py-3 fw-bold shadow-sm text-uppercase">Proses Import CSV Sekarang</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL FORM PEGAWAI MANUAL -->
<div class="modal fade" id="modalPegawai" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form action="hr_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_pegawai">
            <input type="hidden" name="id" id="peg_id">
            <div class="modal-header bg-primary text-white p-4 border-0">
                <h5 class="modal-title fw-bold" id="peg_title"><i class="fas fa-user-edit me-2"></i>Formulir Data Pegawai</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-start">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="small fw-bold text-muted mb-1 uppercase">NIP / Nomor Induk</label>
                        <input type="text" name="nip" id="peg_nip" class="form-control rounded-pill border-0 shadow-sm px-3 fw-bold" required>
                    </div>
                    <div class="col-md-8">
                        <label class="small fw-bold text-muted mb-1 uppercase">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" id="peg_nama" class="form-control rounded-pill border-0 shadow-sm px-3" required>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Jabatan (Opsional)</label>
                        <input type="text" name="jabatan" id="peg_jabatan" list="dataJabatan" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="Contoh: Dosen">
                        <datalist id="dataJabatan">
                            <?php while($sj = $saran_jabatan->fetch_assoc()): ?>
                                <option value="<?= $sj['jabatan'] ?>">
                            <?php endwhile; ?>
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Unit Kerja / Bagian</label>
                        <input type="text" name="unit_kerja" id="peg_unit" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="Contoh: BAU / S1 Keperawatan">
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Tipe Pekerja</label>
                        <!-- ??? FIX MUTLAK: Menggunakan status_pegawai sesuai skema Database asli -->
                        <select name="status_pegawai" id="peg_status" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold">
                            <option value="Tetap">Tetap</option>
                            <option value="Kontrak">Kontrak</option>
                            <option value="Honorer">Honorer</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Status Aktif</label>
                        <select name="status_aktif" id="peg_aktif" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold text-primary">
                            <option value="1">Aktif Bekerja</option>
                            <option value="0">Tidak Aktif (Resign)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Rekening Bank</label>
                        <input type="text" name="rekening_bank" id="peg_rek" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="No Rekening">
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Nomor WhatsApp/HP</label>
                        <input type="text" name="no_hp" id="peg_hp" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="081xxx">
                    </div>
                    <div class="col-md-12">
                        <label class="small fw-bold text-muted mb-1 uppercase">Email</label>
                        <input type="email" name="email" id="peg_email" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="email@institusi.ac.id">
                    </div>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white d-block text-center">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow uppercase">SIMPAN DATA PEGAWAI</button>
            </div>
        </form>
    </div>
</div>

<script>
function showModalImportPegawai() {
    new bootstrap.Modal(document.getElementById('modalImportPegawai')).show();
}

function showModalPegawai(data = null) {
    const m = new bootstrap.Modal(document.getElementById('modalPegawai'));
    document.getElementById('peg_title').innerHTML = data ? '<i class="fas fa-edit me-2"></i>Ubah Data Pegawai' : '<i class="fas fa-user-plus me-2"></i>Registrasi Pegawai Baru';
    document.getElementById('peg_id').value = data ? data.id : '';
    document.getElementById('peg_nip').value = data ? data.nip : '';
    document.getElementById('peg_nama').value = data ? data.nama_lengkap : '';
    document.getElementById('peg_jabatan').value = data ? data.jabatan : '';
    document.getElementById('peg_unit').value = data ? data.unit_kerja : '';
    document.getElementById('peg_status').value = data ? data.status_pegawai : 'Tetap';
    document.getElementById('peg_aktif').value = data ? data.status_aktif : '1';
    document.getElementById('peg_rek').value = data ? data.rekening_bank : '';
    document.getElementById('peg_hp').value = data ? data.no_hp : '';
    
    const emailField = document.getElementById('peg_email');
    if (emailField) { emailField.value = data && data.email ? data.email : ''; }
    
    m.show();
}

function editPegawai(data) { showModalPegawai(data); }

function confirmDelete(id) {
    if(confirm('PENTING: Nonaktifkan / Hapus data pegawai ini?')) {
        location.href = `hr_action.php?action=delete_pegawai&id=${id}`;
    }
}

function validateCSV(el) {
    const fileName = el.value.toLowerCase();
    if (fileName !== '' && !fileName.endsWith('.csv')) {
        alert("?? FORMAT TIDAK DIIZINKAN!\n\nDemi keamanan sistem, harap hanya mengunggah file dengan ekstensi murni .csv (Comma Delimited).");
        el.value = ''; 
    }
}
</script>

<style>
    .btn-light { background: #fff; border: 1px solid #dee2e6; transition: 0.2s; }
    .btn-light:hover { background: #f8f9fa; border-color: #cbd5e1; }
    .btn-white { background: #fff; border: none; transition: 0.2s; }
    .btn-white:hover { background: #f8f9fa; color: #0d6efd !important; }
</style>