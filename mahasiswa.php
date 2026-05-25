<?php
/**
 * mahasiswa.php - MANAJEMEN DATA MAHASISWA & VALIDASI KEAKTIFAN
 * Versi: 32.0 (Sovereign Grand Master - Absolute Tab Visibility Edition)
 * Perbaikan Mutlak: 
 * MENGHAPUS limitasi `mhs_data_list` lama. Karena halaman ini sudah dikunci 
 * secara absolut oleh Gatekeeper di index.php, maka siapapun yang bisa masuk 
 * ke sini otomatis berhak melihat Daftar Mahasiswa dan Tab Generate.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$search = $_GET['q'] ?? '';

// =========================================================================
// 🚀 ENGINE VISIBILITAS TAB MENU (DIPERBAIKI MUTLAK)
// =========================================================================
// Karena halaman ini terkunci oleh Gatekeeper utama, maka user yang berhasil 
// masuk ke sini dipastikan memiliki izin. Kita buka kedua tab secara mutlak.
$allowed_tabs = ['list', 'generate'];

$active_tab = $_GET['tab'] ?? ($allowed_tabs[0] ?? 'list');
if (!in_array($active_tab, $allowed_tabs) && count($allowed_tabs) > 0) {
    $active_tab = $allowed_tabs[0]; 
}

// FILTER GLOBAL
$gen_tahun = $_GET['gen_tahun'] ?? '';
$gen_prodi = $_GET['gen_prodi'] ?? '';
$gen_angkatan = $_GET['gen_angkatan'] ?? '';
$gen_sistem = $_GET['gen_sistem'] ?? '';
$gen_status = $_GET['gen_status'] ?? ''; 

// TANGKAP PARAMETER PAGINATION 
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$allowed_limits = [20, 50, 100, 200];
if (!in_array($limit, $allowed_limits)) { $limit = 20; }

$page_num = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
if ($page_num < 1) { $page_num = 1; }
$offset = ($page_num - 1) * $limit;

// 🚀 URL BUILDER BERSIH UNTUK PAGINATION
$qs_params = $_GET;
unset($qs_params['page_num']); 
unset($qs_params['limit']); 
$base_qs = http_build_query($qs_params);

// MASTER DATA
$tahun_aktif_res = $conn->query("SELECT kode_tahun, nama_tahun FROM mhs_tahun_akademik WHERE is_active=1 LIMIT 1");
$tahun_aktif_global = ($tahun_aktif_res && $tahun_aktif_res->num_rows > 0) ? $tahun_aktif_res->fetch_assoc() : ['kode_tahun' => '', 'nama_tahun' => 'Belum Set'];

$prodis = $conn->query("SELECT * FROM mhs_prodi ORDER BY nama_prodi ASC")->fetch_all(MYSQLI_ASSOC);
$sistems = $conn->query("SELECT * FROM mhs_sistem_kuliah ORDER BY nama_sistem ASC")->fetch_all(MYSQLI_ASSOC);
$list_tahun = $conn->query("SELECT * FROM mhs_tahun_akademik ORDER BY kode_tahun DESC")->fetch_all(MYSQLI_ASSOC);
$tahun_masuk_list = $conn->query("SELECT * FROM mhs_tahun_masuk ORDER BY kode_masuk DESC")->fetch_all(MYSQLI_ASSOC);

function resolveAngkatanDisplay($kode, $master) {
    if (empty($kode)) return '-';
    foreach ($master as $m) { if ($m['kode_masuk'] == $kode) return $m['nama_masuk']; }
    if (strlen($kode) == 4) return $kode . ' <span class="text-danger fw-bold" style="font-size:10px;">[SINKRONKAN]</span>';
    return $kode;
}
?>

<style>
    .pagination { border-radius: 50px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .page-item .page-link { border: none; color: #475569; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
    .page-item.active .page-link { background-color: var(--bs-primary); color: white; border-radius: 50px; }
    .page-item.disabled .page-link { background-color: transparent; color: #cbd5e1; cursor: not-allowed; pointer-events: none; }
    
    .btn-light { background: #fff; border: 1px solid #dee2e6; transition: 0.2s; } 
    .btn-light:hover { background: #f8f9fa; border-color: #cbd5e1; }
    .hover-bg-white:hover { background-color: #ffffff !important; opacity: 0.9; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4">
        <div>
            <h4 class="fw-bold text-primary mb-0"><i class="fas fa-users me-2"></i>Database Mahasiswa</h4>
            <small class="text-muted fw-bold">Periode Aktif: <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1 ms-1"><?= $tahun_aktif_global['nama_tahun'] ?></span></small>
        </div>
        <div class="d-flex gap-2">
            <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
            <button class="btn btn-success btn-lg rounded-pill fw-bold shadow-sm text-uppercase" onclick="showModalImport()">
                <i class="fas fa-file-csv me-2"></i>IMPORT DATA CSV
            </button>
            <button class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm text-uppercase" onclick="showModalMhs()">
                <i class="fas fa-plus me-2"></i>TAMBAH MAHASISWA
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
        <div class="card-header bg-white py-3 border-0 border-bottom">
            <ul class="nav nav-pills mb-0">
                <?php if(in_array('list', $allowed_tabs)): ?>
                <li class="nav-item"><button class="nav-link <?= $active_tab == 'list' ? 'active shadow-sm rounded-pill px-4' : 'rounded-pill px-4 text-muted' ?> fw-bold" data-bs-toggle="tab" data-bs-target="#list">Daftar Mahasiswa</button></li>
                <?php endif; ?>

                <?php if(in_array('generate', $allowed_tabs)): ?>
                <li class="nav-item"><button class="nav-link <?= $active_tab == 'generate' ? 'active shadow-sm rounded-pill px-4' : 'rounded-pill px-4 text-muted' ?> fw-bold" data-bs-toggle="tab" data-bs-target="#generate">Generate Status Aktif</button></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card-body p-0">
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show m-4 rounded-4 shadow-sm border-0">
                    <i class="fas <?= $_SESSION['flash']['type']=='success'?'fa-check-circle':'fa-exclamation-triangle' ?> me-2"></i><?= $_SESSION['flash']['msg'] ?>
                    <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <div class="tab-content">
                <!-- TAB 1: LIST MAHASISWA -->
                <?php if(in_array('list', $allowed_tabs)): ?>
                <div class="tab-pane fade <?= $active_tab == 'list' ? 'show active' : '' ?> p-4" id="list">
                    <form method="GET" class="row g-3 mb-4 bg-light p-4 rounded-4 shadow-sm align-items-end">
                        <input type="hidden" name="page" value="mahasiswa">
                        <input type="hidden" name="tab" value="list">
                        <input type="hidden" name="limit" value="<?= $limit ?>" id="limitFormFilter">
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-2">PROGRAM STUDI</label>
                            <select name="gen_prodi" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3"><option value="">Semua Prodi</option><?php foreach($prodis as $p) echo "<option value='{$p['id']}' ". ($gen_prodi == $p['id'] ? 'selected' : '') .">{$p['nama_prodi']}</option>"; ?></select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted mb-2">ANGKATAN</label>
                            <select name="gen_angkatan" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3"><option value="">Semua</option><?php foreach($tahun_masuk_list as $tm) echo "<option value='{$tm['kode_masuk']}' ".($gen_angkatan==$tm['kode_masuk']?'selected':'').">{$tm['nama_masuk']}</option>"; ?></select>
                        </div>
                        <div class="col-md-2">
                            <label class="small fw-bold text-muted mb-2">SISTEM KULIAH</label>
                            <select name="gen_sistem" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3"><option value="">Semua</option><?php foreach($sistems as $s) echo "<option value='{$s['kode_sistem']}' ".($gen_sistem==$s['kode_sistem']?'selected':'').">{$s['nama_sistem']}</option>"; ?></select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-2">CARI MAHASISWA / NIM</label>
                            <div class="input-group input-group-lg shadow-sm rounded-pill overflow-hidden border-0">
                                <span class="input-group-text bg-white border-0 text-muted px-4"><i class="fas fa-search"></i></span>
                                <input type="text" name="q" class="form-control border-0 bg-white px-3 fw-bold" placeholder="Ketik Nama atau NIM..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm flex-grow-1">CARI DATA</button>
                        </div>
                    </form>

                    <div class="table-responsive border-0 shadow-sm rounded-top-4 bg-white">
                        <table class="table table-striped table-hover align-middle mb-0 text-center">
                            <thead class="table-dark small text-uppercase">
                                <tr><th class="ps-4 text-start" width="100">Aksi</th><th>NIM</th><th class="text-start">Nama Lengkap</th><th>Prodi</th><th>Angkatan</th><th>Sistem</th></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $where = "1=1";
                                if(!empty($gen_prodi)) $where .= " AND m.prodi_id = '" . $conn->real_escape_string($gen_prodi) . "'";
                                if(!empty($gen_angkatan)) $where .= " AND m.angkatan = '" . $conn->real_escape_string($gen_angkatan) . "'";
                                if(!empty($gen_sistem)) $where .= " AND m.sistem_kuliah = '" . $conn->real_escape_string($gen_sistem) . "'";
                                if(!empty($search)) {
                                    $q_esc = $conn->real_escape_string($search);
                                    $where .= " AND (m.nama LIKE '%$q_esc%' OR m.nim LIKE '%$q_esc%')";
                                }

                                $res_count = $conn->query("SELECT COUNT(m.nim) as total FROM syifa_mahasiswa m LEFT JOIN mhs_prodi p ON m.prodi_id = p.id WHERE $where");
                                $total_rows = $res_count ? (int)$res_count->fetch_assoc()['total'] : 0;
                                $total_pages = ceil($total_rows / $limit);

                                $res = $conn->query("SELECT m.*, p.nama_prodi FROM syifa_mahasiswa m LEFT JOIN mhs_prodi p ON m.prodi_id = p.id WHERE $where ORDER BY m.nim ASC LIMIT $limit OFFSET $offset");
                                
                                if($res && $res->num_rows > 0):
                                while($row = $res->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td class="ps-4 text-start"><div class="btn-group btn-group-sm rounded-pill border bg-white overflow-hidden shadow-sm">
                                        <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                        <button class="btn btn-light text-warning border-end border-0 hover-bg-white" onclick='showModalMhs(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8") ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                                        <?php endif; ?>
                                        <?php if(defined('RBAC_DEL') && RBAC_DEL): ?>
                                        <button class="btn btn-light text-danger border-0 hover-bg-white" onclick='confirmDeleteMhs(<?= htmlspecialchars(json_encode($row['nim']), ENT_QUOTES, "UTF-8") ?>)' title="Hapus"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                        <?php if(!defined('RBAC_EDIT') && !defined('RBAC_DEL')): ?>
                                        <button class="btn btn-light text-muted border-0 hover-bg-white disabled"><i class="fas fa-lock"></i></button>
                                        <?php endif; ?>
                                    </div></td>
                                    <td><code class="bg-light px-3 py-2 border rounded-pill text-dark fw-bold"><?= htmlspecialchars($row['nim']) ?></code></td>
                                    <td class="text-start fw-bold text-dark"><?= strtoupper(htmlspecialchars($row['nama'])) ?></td>
                                    <td><small class="fw-bold text-muted"><?= htmlspecialchars($row['nama_prodi'] ?? '-') ?></small></td>
                                    <td><span class="badge <?= (strlen($row['angkatan']) == 4)?'bg-warning bg-opacity-10 text-warning':'bg-primary bg-opacity-10 text-primary' ?> border px-3 py-2 rounded-pill"><?= resolveAngkatanDisplay($row['angkatan'], $tahun_masuk_list) ?></span></td>
                                    <td><span class="badge bg-light text-dark border fw-bold rounded-pill px-3 py-2"><?= htmlspecialchars($row['sistem_kuliah']) ?></span></td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr>
                                    <td colspan='6' class='py-5 text-muted text-center'>
                                        <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-25"></i>
                                        <b class="fs-5">Database Mahasiswa Kosong.</b><br>
                                        Tidak ada mahasiswa yang ditemukan. Silakan klik tombol <b>"Import Data CSV"</b> di pojok kanan atas untuk mengunggah ulang data Anda.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if($total_pages > 0): ?>
                    <div class="card-footer bg-white border border-top-0 p-3 d-flex justify-content-between align-items-center flex-wrap rounded-bottom-4 shadow-sm">
                        <div class="d-flex align-items-center gap-2 mb-2 mb-md-0">
                            <span class="small text-muted fw-bold">Tampilkan:</span>
                            <select class="form-select form-select-sm border shadow-sm rounded-pill text-primary fw-bold" style="width: 80px;" onchange="changeLimitMhs(this.value)">
                                <?php foreach($allowed_limits as $l) echo "<option value='$l' ".($limit == $l ? 'selected' : '').">$l</option>"; ?>
                            </select>
                            <span class="small text-muted ms-2">Menampilkan <?= min($total_rows, $offset + 1) ?> - <?= min($total_rows, $offset + $limit) ?> dari total <b><?= number_format($total_rows) ?></b> data</span>
                        </div>

                        <nav>
                            <ul class="pagination pagination-sm mb-0 shadow-sm rounded-pill overflow-hidden">
                                <li class="page-item <?= ($page_num <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link <?= ($page_num <= 1) ? 'text-muted' : 'text-primary fw-bold' ?> px-3" 
                                       href="<?= ($page_num <= 1) ? '#' : '?'.$base_qs.'&page_num='.($page_num - 1).'&limit='.$limit ?>">
                                        <i class="fas fa-chevron-left me-1"></i> Sebelumnya
                                    </a>
                                </li>
                                <?php 
                                $start_page = max(1, $page_num - 2);
                                $end_page = min($total_pages, $page_num + 2);
                                if($start_page > 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                                for($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?= ($i == $page_num) ? 'active' : '' ?>">
                                        <a class="page-link <?= ($i == $page_num) ? 'fw-bold' : 'text-dark' ?>" href="?<?= $base_qs ?>&page_num=<?= $i ?>&limit=<?= $limit ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; 
                                if($end_page < $total_pages) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                                ?>
                                <li class="page-item <?= ($page_num >= $total_pages) ? 'disabled' : '' ?>">
                                    <a class="page-link <?= ($page_num >= $total_pages) ? 'text-muted' : 'text-primary fw-bold' ?> px-3" 
                                       href="<?= ($page_num >= $total_pages) ? '#' : '?'.$base_qs.'&page_num='.($page_num + 1).'&limit='.$limit ?>">
                                        Selanjutnya <i class="fas fa-chevron-right ms-1"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- TAB 2: GENERATE STATUS AKTIF -->
                <?php if(in_array('generate', $allowed_tabs)): ?>
                <div class="tab-pane fade <?= $active_tab == 'generate' ? 'show active' : '' ?> p-4" id="generate">
                    <div class="bg-light p-4 rounded-4 shadow-sm border-0 mb-4">
                        <form method="GET" class="row g-2 align-items-end">
                            <input type="hidden" name="page" value="mahasiswa"><input type="hidden" name="tab" value="generate">
                            <div class="col-md-2">
                                <label class="small fw-bold text-muted mb-2 text-uppercase" style="font-size: 10px;">Periode Akademik</label>
                                <select name="gen_tahun" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3 fw-bold text-dark" required>
                                    <?php foreach($list_tahun as $lt) echo "<option value='{$lt['kode_tahun']}' ". (($gen_tahun ?: $tahun_aktif_global['kode_tahun']) == $lt['kode_tahun'] ? 'selected' : '') .">{$lt['nama_tahun']}</option>"; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="small fw-bold text-muted mb-2 text-uppercase" style="font-size: 10px;">Prodi</label>
                                <select name="gen_prodi" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3 fw-bold text-dark">
                                    <option value="">Semua Prodi</option>
                                    <?php foreach($prodis as $p) echo "<option value='{$p['id']}' ". ($gen_prodi == $p['id'] ? 'selected' : '') .">{$p['nama_prodi']}</option>"; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-bold text-muted mb-2 text-uppercase" style="font-size: 10px;">Angkatan</label>
                                <select name="gen_angkatan" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3 fw-bold text-dark">
                                    <option value="">Semua</option>
                                    <?php foreach($tahun_masuk_list as $tm) echo "<option value='{$tm['kode_masuk']}' ".($gen_angkatan==$tm['kode_masuk']?'selected':'').">{$tm['nama_masuk']}</option>"; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-bold text-muted mb-2 text-uppercase" style="font-size: 10px;">Sistem</label>
                                <select name="gen_sistem" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3 fw-bold text-dark">
                                    <option value="">Semua</option>
                                    <?php foreach($sistems as $s) echo "<option value='{$s['kode_sistem']}' ".($gen_sistem==$s['kode_sistem']?'selected':'').">{$s['nama_sistem']}</option>"; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="small fw-bold text-muted mb-2 text-uppercase" style="font-size: 10px;">Status Aktif</label>
                                <select name="gen_status" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-3 fw-bold text-dark">
                                    <option value="">Semua</option>
                                    <option value="Aktif" <?= ($gen_status == 'Aktif') ? 'selected' : '' ?>>Sudah Aktif</option>
                                    <option value="Belum" <?= ($gen_status == 'Belum') ? 'selected' : '' ?>>Belum Aktif</option>
                                </select>
                            </div>
                            <div class="col-md-1 d-flex">
                                <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold rounded-pill shadow-sm" title="Terapkan Filter"><i class="fas fa-filter"></i></button>
                            </div>
                        </form>
                    </div>

                    <form id="formGenerate" action="mhs_action.php" method="POST">
                        <input type="hidden" name="action" value="generate_keaktifan">
                        <input type="hidden" name="kode_tahun" value="<?= $gen_tahun ?: $tahun_aktif_global['kode_tahun'] ?>">
                        
                        <div class="d-flex justify-content-between mb-3 align-items-center">
                            <h6 class="fw-bold mb-0 text-dark">Daftar Terpilih (<?= $gen_tahun ?: $tahun_aktif_global['kode_tahun'] ?>)</h6>
                            <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                            <button type="button" class="btn btn-success btn-lg fw-bold px-5 rounded-pill shadow-sm" onclick="submitGenerate()">
                                <i class="fas fa-check-circle me-2"></i>Validasi Status Aktif
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="table-responsive border-0 rounded-4 shadow-sm bg-white">
                            <table class="table table-striped table-hover align-middle mb-0 text-center">
                                <thead class="table-dark small text-uppercase">
                                    <tr>
                                        <th width="50" class="text-center"><input type="checkbox" id="checkAll" class="form-check-input" style="width:18px;height:18px;"></th>
                                        <th>NIM</th><th class="text-start">Mahasiswa</th><th>Prodi</th><th>Sistem</th><th>Angkatan</th><th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $tahun_target_gen = $gen_tahun ?: $tahun_aktif_global['kode_tahun'];
                                    
                                    $sql_gen = "SELECT m.*, p.nama_prodi, k.status_aktif as current_status_aktif 
                                                FROM syifa_mahasiswa m 
                                                LEFT JOIN mhs_prodi p ON m.prodi_id = p.id 
                                                LEFT JOIN mhs_keaktifan_semester k ON m.nim = k.nim AND k.kode_tahun = '" . $conn->real_escape_string($tahun_target_gen) . "' 
                                                WHERE 1=1";
                                                
                                    if(!empty($gen_prodi)) $sql_gen .= " AND m.prodi_id = '" . $conn->real_escape_string($gen_prodi) . "'";
                                    if(!empty($gen_angkatan)) $sql_gen .= " AND m.angkatan = '" . $conn->real_escape_string($gen_angkatan) . "'";
                                    if(!empty($gen_sistem)) $sql_gen .= " AND m.sistem_kuliah = '" . $conn->real_escape_string($gen_sistem) . "'";
                                    
                                    if($gen_status == 'Aktif') {
                                        $sql_gen .= " AND k.status_aktif = 'Aktif'";
                                    } elseif($gen_status == 'Belum') {
                                        $sql_gen .= " AND (k.status_aktif IS NULL OR k.status_aktif != 'Aktif')";
                                    }
                                    
                                    $sql_gen .= " ORDER BY m.nim ASC";
                                    
                                    $res_gen = $conn->query($sql_gen);
                                    if($res_gen && $res_gen->num_rows > 0): while($rm = $res_gen->fetch_assoc()): 
                                        $is_aktif = ($rm['current_status_aktif'] == 'Aktif');
                                    ?>
                                    <tr>
                                        <td class="text-center">
                                            <?php if(!$is_aktif): ?>
                                                <input type="checkbox" name="mhs_selected[]" value="<?= $rm['nim'] ?>" class="form-check-input check-mhs border-secondary" style="width:18px;height:18px;">
                                            <?php else: ?>
                                                <input type="checkbox" class="form-check-input opacity-25" disabled title="Sudah Aktif">
                                            <?php endif; ?>
                                        </td>
                                        <td><code class="bg-light px-3 py-2 border rounded-pill text-dark fw-bold"><?= $rm['nim'] ?></code></td>
                                        <td class="text-start fw-bold text-dark"><?= strtoupper(htmlspecialchars($rm['nama'])) ?></td>
                                        <td><small class="fw-bold text-muted"><?= htmlspecialchars($rm['nama_prodi']) ?></small></td>
                                        <td><span class="badge bg-light text-dark border fw-bold rounded-pill px-3 py-2"><?= htmlspecialchars($rm['sistem_kuliah']) ?></span></td>
                                        <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-2"><?= resolveAngkatanDisplay($rm['angkatan'], $tahun_masuk_list) ?></span></td>
                                        <td>
                                            <?php if($is_aktif): ?>
                                                <span class="badge bg-success rounded-pill px-3 py-2 shadow-sm"><i class="fas fa-check-circle me-1"></i> Aktif</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3 py-2"><i class="fas fa-times-circle me-1"></i> Belum</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: echo "<tr><td colspan='7' class='py-5 text-muted italic'>Tidak ada mahasiswa ditemukan berdasarkan filter.</td></tr>"; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MODAL FORM MAHASISWA -->
<div class="modal fade" id="modalMhs" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form action="mhs_action.php" method="POST" class="modal-content rounded-4 border-0 shadow-lg">
            <input type="hidden" name="action" value="save_mhs">
            <input type="hidden" name="old_nim" id="mhs_old_nim">
            <input type="hidden" name="id" id="mhs_id">
            
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold" id="mhs_modal_title">Informasi Mahasiswa</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-3">
                    <div class="col-md-4"><label class="small fw-bold text-muted mb-2 text-uppercase">NIM</label><input type="text" name="nim" id="mhs_nim" class="form-control form-control-lg border-0 shadow-sm rounded-pill px-4 fw-bold" required></div>
                    <div class="col-md-8"><label class="small fw-bold text-muted mb-2 text-uppercase">Nama Lengkap</label><input type="text" name="nama" id="mhs_nama" class="form-control form-control-lg border-0 shadow-sm rounded-pill px-4" required></div>
                    <div class="col-md-6"><label class="small fw-bold text-muted mb-2 text-uppercase">Prodi</label><select name="prodi_id" id="mhs_prodi" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-4" required><option value="">-- Pilih Prodi --</option><?php foreach($prodis as $p) echo "<option value='{$p['id']}'>{$p['nama_prodi']}</option>"; ?></select></div>
                    <div class="col-md-6"><label class="small fw-bold text-muted mb-2 text-uppercase">Sistem Kuliah</label><select name="sistem_kuliah" id="mhs_sistem" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-4" required><option value="">-- Pilih --</option><?php foreach($sistems as $s) echo "<option value='{$s['kode_sistem']}'>{$s['nama_sistem']}</option>"; ?></select></div>
                    <div class="col-md-6"><label class="small fw-bold text-muted mb-2 text-uppercase">Tahun Masuk (Angkatan)</label><select name="angkatan" id="mhs_angkatan" class="form-select form-select-lg border-0 shadow-sm rounded-pill px-4 fw-bold text-primary" required><option value="">-- Pilih --</option><?php foreach($tahun_masuk_list as $tm) echo "<option value='{$tm['kode_masuk']}'>{$tm['nama_masuk']}</option>"; ?></select></div>
                    <div class="col-md-6"><label class="small fw-bold text-muted mb-2 text-uppercase">No. HP</label><input type="text" name="telepon" id="mhs_telepon" class="form-control form-control-lg border-0 shadow-sm rounded-pill px-4"></div>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white"><button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill py-3 fw-bold shadow-sm">SIMPAN DATA MAHASISWA</button></div>
        </form>
    </div>
</div>

<!-- 🚀 MODAL IMPORT CSV (WARNA TEKS JELAS TERBACA) -->
<div class="modal fade" id="modalImport" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form action="mhs_action.php" method="POST" enctype="multipart/form-data" class="modal-content rounded-4 border-0 shadow-lg">
            <input type="hidden" name="action" value="import_mhs">
            <div class="modal-header bg-success text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-csv me-2"></i>Import Massal Mahasiswa</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert bg-success bg-opacity-10 border border-success border-opacity-25 rounded-4 mb-4 small shadow-sm">
                    <h6 class="fw-bold mb-2" style="color: #047857 !important;"><i class="fas fa-info-circle me-1"></i> Panduan Import Cepat & Akurat:</h6>
                    <ol class="mb-0 ps-3" style="line-height: 1.6; color: #1e293b !important;">
                        <li style="color: #1e293b !important;">Unduh template referensi melalui tombol di bawah.</li>
                        <li style="color: #1e293b !important;">Isi data mahasiswa. Anda kini <b style="color: #1e293b !important;">dapat mengetik Nama Prodi secara langsung</b> (Misal: <i style="color: #1e293b !important;">S1 Keperawatan</i>) tanpa perlu mengingat ID angka. Sistem akan memetakannya secara cerdas.</li>
                        <li style="color: #1e293b !important;">Buka di Excel dan simpan <strong style="color: #1e293b !important;">(Save As)</strong> menggunakan format <b style="color: #1e293b !important;">CSV (Comma delimited) (*.csv)</b>.</li>
                        <li style="color: #1e293b !important;">Unggah file CSV yang telah Anda simpan ke form di bawah.</li>
                    </ol>
                </div>
                
                <div class="text-center mb-4 border-bottom pb-4 border-secondary border-opacity-25">
                    <a href="mhs_action.php?action=download_template_mhs" class="btn btn-outline-success rounded-pill px-5 py-2 fw-bold shadow-sm">
                        <i class="fas fa-download me-2"></i>Unduh Smart Template CSV
                    </a>
                </div>

                <div class="mb-2 position-relative text-center">
                    <label class="small fw-bold text-muted mb-3 text-uppercase"><i class="fas fa-upload me-1"></i> Unggah File CSV Anda Di Sini</label>
                    <input type="file" name="file_import" class="form-control form-control-lg border-success border-2 shadow-sm rounded-4 px-4 py-3 bg-white text-center fw-bold" accept=".csv" required>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white">
                <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill py-3 fw-bold shadow-sm">PROSES IMPORT SEKARANG</button>
            </div>
        </form>
    </div>
</div>

<script>
function showModalMhs(data = null) {
    const modal = new bootstrap.Modal(document.getElementById('modalMhs'));
    document.getElementById('mhs_modal_title').innerHTML = data ? '<i class="fas fa-edit me-2"></i>Ubah Data Mahasiswa' : '<i class="fas fa-user-plus me-2"></i>Registrasi Mahasiswa Baru';
    
    document.getElementById('mhs_old_nim').value = data ? data.nim : '';
    const idInput = document.getElementById('mhs_id');
    if (idInput) { idInput.value = data && data.id ? data.id : ''; }
    
    document.getElementById('mhs_nim').value = data ? data.nim : '';
    document.getElementById('mhs_nama').value = data ? data.nama : '';
    document.getElementById('mhs_prodi').value = data ? data.prodi_id : '';
    document.getElementById('mhs_sistem').value = data ? data.sistem_kuliah : '';
    document.getElementById('mhs_angkatan').value = data ? data.angkatan : '';
    document.getElementById('mhs_telepon').value = data ? (data.telepon || '') : '';
    modal.show();
}

function showModalImport() {
    new bootstrap.Modal(document.getElementById('modalImport')).show();
}

function changeLimitMhs(val) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('limit', val);
    urlParams.set('page_num', 1);
    window.location.search = urlParams.toString();
}

function confirmDeleteMhs(nim) {
    if(confirm('Peringatan: Yakin ingin menghapus data mahasiswa (NIM: ' + nim + ') secara permanen?\n\n(Catatan: Sistem akan membuang tagihan Draf/Belum lunas milik mahasiswa ini secara otomatis. Tagihan yang sudah dibayar tidak akan dihapus demi audit).')) {
        const f = document.createElement('form'); f.method='POST'; f.action='mhs_action.php'; 
        const iAct = document.createElement('input'); iAct.type='hidden'; iAct.name='action'; iAct.value='delete_mhs';
        const iNim = document.createElement('input'); iNim.type='hidden'; iNim.name='nim'; iNim.value=nim;
        f.appendChild(iAct); f.appendChild(iNim);
        document.body.appendChild(f); 
        f.submit();
    }
}

document.getElementById('checkAll')?.addEventListener('change', function() {
    const isChecked = this.checked;
    document.querySelectorAll('.check-mhs:not([disabled])').forEach(cb => {
        cb.checked = isChecked;
    });
});

function submitGenerate() {
    const checked = document.querySelectorAll('.check-mhs:checked');
    if (checked.length === 0) { alert('Silakan pilih setidaknya satu mahasiswa yang belum aktif.'); return; }
    if (confirm('Validasi keaktifan untuk ' + checked.length + ' mahasiswa?')) { document.getElementById('formGenerate').submit(); }
}
</script>