<?php
/**
 * anggaran_unit.php - UNIT BUDGETING & EXECUTIVE COCKPIT
 * Versi: 159.0 (Sovereign Grand Master - Omni-Prefix Filter)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak: 
 * Menerapkan Omni-Prefix Expansion Engine. Realisasi di-query murni menggunakan 
 * "LIKE 'kode%'" dari unit_coa_map agar transaksi pada akun anak masuk ke 
 * dalam grafik dan KPI dashboard unit secara otomatis!
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

if(!isset($is_dashboard_mode) && function_exists('guardPage')) { guardPage('anggaran_unit'); }

$tahun = $_GET['tahun'] ?? date('Y');
$active_tab = $_GET['tab'] ?? 'dashboard';
if ($active_tab == 'manajemen') { $active_tab = 'dashboard'; }

$sub_view = $_GET['sub'] ?? 'live'; 
$view_report_id = $_GET['view_report'] ?? null;
if($view_report_id) $sub_view = 'detail'; 

$user_id = $_SESSION['user_id'];
$user_role_id = $_SESSION['role_id'];

$f_mulai = $_GET['f_mulai'] ?? date('Y-m-01');
$f_akhir = $_GET['f_akhir'] ?? date('Y-m-d');

$sql_role = "SELECT r.is_ka_unit, r.unit_id, r.role_name, u.jabatan_workflow FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = '$user_id'";
$u_role_res = $conn->query($sql_role);
$u_role = $u_role_res ? $u_role_res->fetch_assoc() : null;

$workflow_auth = strtoupper($u_role['jabatan_workflow'] ?? '');
$mapped_unit_id = (int)($u_role['unit_id'] ?? 0);
$is_ka_unit = ($u_role && $u_role['is_ka_unit'] == 1);

$is_superadmin_root = ($user_role_id == 1);
$is_global_admin = ($is_superadmin_root || $mapped_unit_id === 0);

if ($is_global_admin) {
    $selected_unit_id = (isset($_GET['unit_id']) && $_GET['unit_id'] !== '') ? (int)$_GET['unit_id'] : null;
} else {
    $selected_unit_id = $mapped_unit_id;
}
$my_unit_id = $selected_unit_id ?: 0;

global $current_permissions;
$tab_keys = ['dash' => '', 'input' => '', 'approval' => '', 'monitor' => '', 'validasi' => ''];
$q_tabs = $conn->query("SELECT menu_key, menu_name FROM menus WHERE menu_name LIKE '%Dashboard Progress%' OR menu_name LIKE '%Pengajuan Anggaran%' OR menu_name LIKE '%Approval%' OR menu_name LIKE '%Monitoring & Laporan%' OR menu_name LIKE '%Validasi%'");
if ($q_tabs) {
    while($row = $q_tabs->fetch_assoc()) {
        $name = strtolower($row['menu_name']);
        if (strpos($name, 'dashboard progress') !== false) $tab_keys['dash'] = $row['menu_key'];
        if (strpos($name, 'pengajuan anggaran') !== false) $tab_keys['input'] = $row['menu_key'];
        if (strpos($name, 'approval') !== false) $tab_keys['approval'] = $row['menu_key'];
        if (strpos($name, 'monitoring & laporan') !== false) $tab_keys['monitor'] = $row['menu_key'];
        if (strpos($name, 'validasi') !== false) $tab_keys['validasi'] = $row['menu_key'];
    }
}

$has_dash = ($tab_keys['dash'] == '') ? true : (isset($current_permissions[$tab_keys['dash']]) && $current_permissions[$tab_keys['dash']]['can_view'] == 1);
$has_input = ($tab_keys['input'] == '') ? true : (isset($current_permissions[$tab_keys['input']]) && $current_permissions[$tab_keys['input']]['can_view'] == 1);
$has_appr = ($tab_keys['approval'] == '') ? true : (isset($current_permissions[$tab_keys['approval']]) && $current_permissions[$tab_keys['approval']]['can_view'] == 1);
$has_mon  = ($tab_keys['monitor'] == '') ? true : (isset($current_permissions[$tab_keys['monitor']]) && $current_permissions[$tab_keys['monitor']]['can_view'] == 1);
$has_val  = ($tab_keys['validasi'] == '') ? true : (isset($current_permissions[$tab_keys['validasi']]) && $current_permissions[$tab_keys['validasi']]['can_view'] == 1);

if ($is_superadmin_root) {
    $has_dash = true; $has_input = true; $has_appr = true; $has_mon = true; $has_val = true;
}

$can_dash = $has_dash;
$can_input = $has_input;
$can_approve = $is_global_admin && in_array($workflow_auth, ['CHECKER', 'APPROVER', 'PIMPINAN', 'ALL']) && $has_appr;
$can_validate = $is_global_admin && $has_val; 
$can_monitor = $has_mon;

// --- DATA MASTER ---
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$unit_list = $conn->query("SELECT * FROM m_unit ORDER BY nama_unit ASC")->fetch_all(MYSQLI_ASSOC);
$coa_beban = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE kategori='Beban' AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

if(!function_exists('safeQuerySum')){
    function safeQuerySum($conn, $sql) { $res = $conn->query($sql); if ($res && $res->num_rows > 0) { $r = $res->fetch_row(); return (double)($r[0] ?? 0); } return 0; }
}

// =========================================================================
// 🚀 THE OMNI-PREFIX EXPANSION ENGINE (MENGHANCURKAN BUG SALDO HILANG)
// =========================================================================
function getExpandedCoasSQL($conn, $base_coas) {
    if(empty($base_coas)) return "''";
    $likes = [];
    foreach($base_coas as $c) {
        $p = rtrim($c, '0');
        if(substr($p, -1) == '-' || substr($p, -1) == '.') $p = substr($p, 0, -1);
        if(strlen($p) < 4) $p = substr($c, 0, 4);
        $likes[] = "kode_akun LIKE '$p%'";
    }
    $cond = implode(" OR ", $likes);
    $res = [];
    $q = $conn->query("SELECT kode_akun FROM syifa_akun WHERE $cond AND is_active=1");
    if($q) while($r = $q->fetch_assoc()) $res[] = "'" . $r['kode_akun'] . "'";
    return empty($res) ? "''" : implode(",", array_unique($res));
}

$summary = ['pagu'=>0, 'pagu_tambahan'=>0, 'salur'=>0, 'real'=>0, 'sisa'=>0, 'kas_unit'=>0, 'serapan'=>0, 'mengendap'=>0];
$monthly_real = array_fill(1, 12, 0); 

$status_filters_notif = [];
if (in_array($workflow_auth, ['CHECKER', 'ALL']) || $is_superadmin_root) { $status_filters_notif[] = "'SUBMITTED'"; $status_filters_notif[] = "''"; }
if (in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root) { $status_filters_notif[] = "'CHECKED'"; }
$status_in_notif = !empty($status_filters_notif) ? implode(",", $status_filters_notif) : "'UNKNOWN'";

$notif_count = safeQuerySum($conn, "SELECT COUNT(*) FROM anggaran_unit_pengajuan WHERE status IN ($status_in_notif) AND tahun='$tahun'");

$unit_mapped_coas = [];
if($selected_unit_id) {
    $res_m = $conn->query("SELECT kode_akun FROM unit_coa_map WHERE unit_id = $selected_unit_id");
    while($rm = $res_m->fetch_assoc()) $unit_mapped_coas[] = $rm['kode_akun'];
} else {
    $res_m = $conn->query("SELECT kode_akun FROM unit_coa_map");
    while($rm = $res_m->fetch_assoc()) $unit_mapped_coas[] = $rm['kode_akun'];
}
$unit_mapped_coas = array_unique($unit_mapped_coas);

$komp_labels = []; $komp_values = [];
if(!empty($unit_mapped_coas)) {
    // Kueri asli (Strict) untuk Pagu
    $coa_list_sql_strict = "'" . implode("','", $unit_mapped_coas) . "'";
    $summary['pagu'] = safeQuerySum($conn, "SELECT SUM(nominal_pagu) FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND status='Disetujui' AND kode_akun IN ($coa_list_sql_strict) AND sumber_data IN ('RAPB', 'UNIT_APPROVED')");
    
    // 🚀 TERAPKAN OMNI-PREFIX UNTUK REALISASI JURNAL
    $coa_list_sql_omni = getExpandedCoasSQL($conn, $unit_mapped_coas);
    
    $summary['real'] = safeQuerySum($conn, "SELECT SUM(jd.debit - jd.kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun IN ($coa_list_sql_omni) AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted=0");
    
    $sql_tr = "SELECT MONTH(j.tgl_jurnal) as bulan, SUM(jd.debit - jd.kredit) as total FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun IN ($coa_list_sql_omni) AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted=0 GROUP BY MONTH(j.tgl_jurnal)";
    $q_tr = $conn->query($sql_tr);
    while($row_tr = $q_tr->fetch_assoc()) { $monthly_real[(int)$row_tr['bulan']] = (double)$row_tr['total']; }

    $q_komp = $conn->query("SELECT j.keterangan as aktivitas, SUM(jd.debit - jd.kredit) as total FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun IN ($coa_list_sql_omni) AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted=0 GROUP BY j.keterangan HAVING total > 0 ORDER BY total DESC LIMIT 6");
    if($q_komp) {
        while($rk = $q_komp->fetch_assoc()) {
            $raw_label = trim($rk['aktivitas']) ?: 'Tanpa Keterangan';
            $komp_labels[] = strlen($raw_label) > 22 ? substr($raw_label, 0, 22).'...' : $raw_label;
            $komp_values[] = (double)$rk['total'];
        }
    }
}
$summary['sisa'] = max(0, $summary['pagu'] - $summary['real']);
$summary['serapan'] = ($summary['pagu'] > 0) ? ($summary['real'] / $summary['pagu']) * 100 : 0;
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .kpi-value { font-size: 26px !important; font-weight: 900 !important; line-height: 1.2; }
    .kpi-label { font-size: 11px !important; font-weight: 800 !important; text-transform: uppercase; opacity: 0.95; margin-bottom: 6px; }
    .nav-tabs .nav-link { font-weight: 800; color: #64748b; border: none; padding: 15px 25px; transition: 0.3s; border-radius: 12px 12px 0 0; text-decoration: none; }
    .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 4px solid #0d6efd; background: #fff; }
    .kpi-box { border: none; border-radius: 24px; color: #fff; padding: 25px; min-height: 120px; display: flex; flex-direction: column; justify-content: center; box-shadow: 0 10px 20px rgba(0,0,0,0.1); transition: 0.3s; }
    .bg-pagu { background: linear-gradient(135deg, #1e40af, #3b82f6); }
    .bg-real { background: linear-gradient(135deg, #15803d, #22c55e); }
    .bg-sisa { background: linear-gradient(135deg, #b45309, #f59e0b); }
    .progress-bar-thick { height: 28px; background-color: #e2e8f0; border-radius: 50px; overflow: hidden; position: relative; display: flex; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1); }
    .progress-fill-thick { height: 100%; transition: width 1s ease-in-out; display: flex; align-items: center; justify-content: flex-end; padding-right: 15px; color: white; font-weight: bold; font-size: 12px; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark text-center">
    
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 text-dark text-center">
        <div class="d-flex align-items-center gap-3 text-start">
            <?php if(!isset($is_dashboard_mode)): ?>
                <a href="index.php?page=dashboard" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
            <?php endif; ?>
            <div>
                <h4 class="fw-bold mb-0 text-dark">Anggaran Unit: <?= $selected_unit_id ? ($conn->query("SELECT nama_unit FROM m_unit WHERE id=".(int)$selected_unit_id)->fetch_row()[0] ?? 'Unit') : 'Analisa Global' ?></h4>
                <small class="text-muted small fw-bold uppercase">Executive Budgeting Cockpit</small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <?php if($is_global_admin): ?>
            <form method="GET" class="d-flex bg-light rounded-pill px-3 border align-items-center shadow-sm text-dark">
                <input type="hidden" name="page" value="anggaran_unit"><input type="hidden" name="tab" value="<?= $active_tab ?>">
                <span class="small fw-bold text-muted me-2">FILTER:</span>
                <select name="unit_id" class="form-select border-0 bg-transparent fw-bold text-primary shadow-none" onchange="this.form.submit()" style="width:230px;">
                    <option value="">-- Analisa Global --</option>
                    <?php foreach($unit_list as $ul) echo "<option value='{$ul['id']}' ".($selected_unit_id==$ul['id']?'selected':'').">{$ul['kode_unit']}</option>"; ?>
                </select>
                <select name="tahun" class="form-select border-0 bg-transparent fw-bold text-primary shadow-none" onchange="this.form.submit()" style="width:100px;">
                    <?php for($y=date('Y')+1; $y>=2024; $y--) echo "<option value='$y' ".($tahun==$y?'selected':'').">$y</option>"; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🚀 KENDALI TABS -->
    <ul class="nav nav-tabs mb-4 border-bottom-0 text-dark" id="unitTabs" role="tablist">
        <?php if($can_dash): ?>
        <li class="nav-item"><button class="nav-link <?= $active_tab=='dashboard'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button"><i class="fas fa-chart-pie me-2"></i>Dashboard Progres</button></li>
        <?php endif; ?>
        
        <?php if($can_input): ?>
        <li class="nav-item"><button class="nav-link <?= $active_tab=='input'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-pengajuan" type="button"><i class="fas fa-file-contract me-2"></i>Pengajuan Anggaran</button></li>
        <?php endif; ?>
        
        <?php if($can_approve): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='approval'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-approval" type="button"><i class="fas fa-gavel me-2"></i>Approval <?php if($notif_count > 0): ?><span class="badge bg-danger rounded-pill ms-1"><?= (int)$notif_count ?></span><?php endif; ?></button></li>
        <?php endif; ?>
        
        <?php if($can_monitor): ?>
            <li class="nav-item"><button class="nav-link <?= ($active_tab=='monitoring' || $active_tab=='arsip' || $active_tab=='detail')?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-monitoring" type="button"><i class="fas fa-search-dollar me-2"></i>Monitoring & Laporan</button></li>
        <?php endif; ?>
        
        <?php if($can_validate): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='validasi'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-validasi" type="button"><i class="fas fa-check-circle me-2 text-warning"></i>Validasi Laporan</button></li>
        <?php endif; ?>
    </ul>

    <!-- ALERT PESAN -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4 text-dark text-start">
            <i class="fas fa-info-circle me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="tab-content" id="unitTabsContent">

        <!-- 2. TAB DASHBOARD PROGRES -->
        <?php if($can_dash): ?>
        <div class="tab-pane fade <?= $active_tab=='dashboard'?'show active':'' ?>" id="tab-dashboard">
            <div class="row g-3 mb-4 text-start">
                <div class="col-md-4"><div class="kpi-box bg-pagu"><div class="kpi-label">Pagu Disetujui (<?= $tahun ?>)</div><div class="kpi-value">Rp <?= number_format($summary['pagu']) ?></div></div></div>
                <div class="col-md-4"><div class="kpi-box bg-real"><div class="kpi-label">Realisasi / Terpakai</div><div class="kpi-value">Rp <?= number_format($summary['real']) ?></div></div></div>
                <div class="col-md-4"><div class="kpi-box bg-sisa"><div class="kpi-label">Sisa Anggaran</div><div class="kpi-value">Rp <?= number_format($summary['sisa']) ?></div></div></div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white text-start">
                <div class="d-flex justify-content-between align-items-end mb-2">
                    <span class="fw-bold text-dark uppercase small">Status Penyerapan Anggaran</span>
                    <span class="fw-bold text-primary fs-5"><?= round($summary['serapan'], 1) ?>% Terpakai</span>
                </div>
                <div class="progress-bar-thick"><div class="progress-fill-thick <?= $summary['serapan'] > 80 ? 'bg-danger' : 'bg-primary' ?>" style="width: <?= min(100, $summary['serapan']) ?>%;"></div></div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-8">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white text-start">
                        <h6 class="fw-bold text-dark mb-4"><i class="fas fa-chart-area me-2 text-primary"></i>Grafik Penyerapan Bulanan</h6>
                        <div class="chart-wrapper" style="height:300px;"><canvas id="chartLineTrend"></canvas></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100 bg-white text-start">
                        <h6 class="fw-bold text-dark mb-4"><i class="fas fa-chart-pie me-2 text-warning"></i>Komposisi Penggunaan Dana</h6>
                        <div class="chart-wrapper" style="height: 250px !important;"><canvas id="chartDonutAlokasi"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 3. TAB PENGAJUAN ANGGARAN -->
        <?php if($can_input): ?>
        <div class="tab-pane fade <?= $active_tab=='input'?'show active':'' ?>" id="tab-pengajuan">
             <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark mb-4 text-center">
                <div class="card-header bg-dark text-white p-3 fw-bold small text-uppercase text-start">Form Pengajuan Anggaran Unit</div>
                <div class="card-body p-4 text-dark text-center">
                    <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                    <form action="budget_unit_action.php" method="POST" id="formBulkProposal">
                        <input type="hidden" name="action" value="save_proposal_bulk">
                        <input type="hidden" name="id" id="edit_id" value="">
                        <input type="hidden" name="tahun" value="<?= $tahun ?>">
                        <div class="row g-3 mb-4 text-center text-dark text-start">
                            <div class="col-md-3">
                                <label class="small fw-bold text-muted mb-1 uppercase">Unit Pengaju</label>
                                <select name="unit_id" id="prop_unit" class="form-select rounded-pill border shadow-sm px-3 text-center" required>
                                    <?php if(!$is_global_admin) { 
                                        $sel_uid = (int)$selected_unit_id; 
                                        $unm_q = $conn->query("SELECT kode_unit FROM m_unit WHERE id=$sel_uid"); 
                                        $unm = ($unm_q && $unm_q->num_rows > 0) ? $unm_q->fetch_row()[0] : 'Unknown'; 
                                        echo "<option value='$selected_unit_id'>$unm</option>"; 
                                    } else { 
                                        foreach($unit_list as $u) echo "<option value='{$u['id']}'>{$u['kode_unit']}</option>"; 
                                    } ?>
                                </select>
                            </div>
                            <div class="col-md-3"><label class="small fw-bold text-danger mb-1 uppercase">Jenis Pengajuan</label><select name="jenis_pengajuan" id="prop_jenis" class="form-select rounded-pill border shadow-sm px-3 text-center fw-bold" required><option value="TAMBAHAN_PAGU" selected>TAMBAHAN PAGU</option><option value="REALISASI">REALISASI ANGGARAN</option></select></div>
                            <div class="col-md-3"><label class="small fw-bold text-muted mb-1 uppercase">Program Utama</label><input type="text" name="master_program" id="prop_master_prog" class="form-control rounded-pill border shadow-sm px-3 text-center" placeholder="Nama Program" required></div>
                            <div class="col-md-3"><label class="small fw-bold text-muted mb-1 uppercase">Justifikasi</label><input type="text" name="master_detail" id="prop_master_det" class="form-control rounded-pill border shadow-sm px-3 text-center" placeholder="Detail..."></div>
                        </div>
                        <div id="ws_unit_body" class="text-center"></div>
                        <div class="text-center mt-4">
                            <button type="button" class="btn btn-primary btn-sm rounded-pill px-4 me-2" onclick="addBudgetRow()"><i class="fas fa-plus me-1"></i> Tambah Baris</button> 
                            <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-4 me-2 d-none" id="btnCancelEdit" onclick="cancelEditProposal()"><i class="fas fa-times me-1"></i> Batal Ubah</button> 
                            <button type="submit" name="status_to" value="DRAFT" class="btn btn-warning btn-sm rounded-pill px-4 shadow-sm fw-bold text-dark"><i class="fas fa-save me-1"></i> SIMPAN DRAFT</button>
                            <button type="submit" name="status_to" value="SUBMITTED" class="btn btn-success btn-sm rounded-pill px-4 ms-2 shadow-sm fw-bold"><i class="fas fa-paper-plane me-1"></i> KIRIM PENGAJUAN</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <div class="alert alert-warning border-0 shadow-sm text-center">
                            <i class="fas fa-lock fa-2x mb-2 d-block opacity-50"></i>
                            Hak Akses Dibatasi. Anda hanya memiliki izin untuk <b>Melihat (View)</b> data pengajuan.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark shadow-sm text-center">
                <div class="card-header bg-light text-dark p-3 fw-bold small text-uppercase text-start">Log Status Pengajuan Aktif Sisi Unit</div>
                <div class="table-responsive text-dark text-center">
                    <table class="table table-hover align-middle mb-0 text-center text-dark">
                        <thead class="bg-light small fw-bold text-dark text-center"><tr><th class="ps-4 text-start">Program & Kegiatan</th><th>Tipe</th><th class="text-end">Jumlah</th><th>Status</th><th width="140" class="text-end pe-4">Aksi</th></tr></thead>
                        <tbody>
                            <?php 
                            $sel_uid_query = $selected_unit_id ? "AND a.unit_id=".(int)$selected_unit_id : "";
                            $res_h = $conn->query("SELECT a.*, IFNULL(u.nama_unit, 'UNIT PUSAT/GENERAL') as nama_unit FROM anggaran_unit_pengajuan a LEFT JOIN m_unit u ON a.unit_id = u.id WHERE a.status != 'DELETED' AND a.tahun='$tahun' $sel_uid_query ORDER BY a.id DESC");
                            
                            if($res_h && $res_h->num_rows > 0): while($h = $res_h->fetch_assoc()): 
                                $st_cls = match($h['status']) { 'REJECTED'=>'danger', 'APPROVED'=>'success', 'CHECKED'=>'primary', 'SUBMITTED'=>'info text-dark', ''=>'info text-dark', default=>'warning text-dark' };
                                $st_lbl = ($h['status'] == 'SUBMITTED' || $h['status'] == '') ? 'DIKIRIM' : $h['status'];
                                $is_editable = ($h['status'] == 'DRAFT' || $h['status'] == 'REJECTED'); 
                            ?>
                            <tr>
                                <td class="text-start ps-4 text-dark"><b><?= htmlspecialchars($h['program']) ?></b><br><small><?= htmlspecialchars($h['kegiatan']) ?></small><?php if($is_global_admin): ?><div class="small text-muted italic mt-1"><i class="fas fa-building me-1"></i><?= $h['nama_unit'] ?></div><?php endif; ?></td>
                                <td><span class="badge bg-light text-primary border small"><?= $h['jenis_pengajuan'] ?? 'TAMBAHAN_PAGU' ?></span></td>
                                <td class="text-end fw-bold text-dark">Rp <?= number_format($h['jumlah_pengajuan'] ?? $h['nominal_pengajuan'] ?? 0) ?></td>
                                <td><span class="badge bg-<?= $st_cls ?> rounded-pill px-3 fw-bold"><?= $st_lbl ?></span></td>
                                <td class="text-end pe-4">
                                    <div class="btn-group btn-group-sm rounded-pill overflow-hidden border shadow-sm bg-white text-center">
                                        <?php if($is_editable): ?>
                                            <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?><a href="budget_unit_action.php?action=submit_pengajuan&id=<?= $h['id'] ?>&tahun=<?= $tahun ?>" class="btn btn-white text-success border-end px-3" title="Kirim Pengajuan"><i class="fas fa-paper-plane"></i></a><button class="btn btn-white text-warning border-end px-3" onclick='editProposal(<?= json_encode($h, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' title="Ubah Pengajuan"><i class="fas fa-edit"></i></button><?php endif; ?>
                                            <?php if(defined('RBAC_DEL') && RBAC_DEL): ?><a href="budget_unit_action.php?action=delete_pengajuan&id=<?= $h['id'] ?>&tahun=<?= $tahun ?>" class="btn btn-white text-danger px-3" title="Hapus Draf" onclick="return confirm('Yakin ingin membuang draf ini?')"><i class="fas fa-trash"></i></a><?php endif; ?>
                                            <?php if((!defined('RBAC_EDIT') || !RBAC_EDIT) && (!defined('RBAC_DEL') || !RBAC_DEL)): ?><i class="fas fa-eye text-primary opacity-50 px-3 py-2"></i><?php endif; ?>
                                        <?php else: ?><div class="btn btn-light text-muted px-4 disabled" title="Terkunci - Menunggu Proses Pusat"><i class="fas fa-lock"></i></div><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: echo '<tr><td colspan="5" class="py-5 text-muted italic">Belum ada log pengajuan untuk tahun '.$tahun.'.</td></tr>'; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 4. TAB APPROVAL -->
        <?php if($can_approve): ?>
        <div class="tab-pane fade <?= $active_tab=='approval'?'show active':'' ?>" id="tab-approval">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 bg-white text-dark shadow-sm text-center">
                <div class="card-header bg-primary text-white p-4 fw-bold text-start">Antrean Approval (Validasi Otoritas)</div>
                <div class="table-responsive text-dark text-center">
                    <table class="table table-hover align-middle mb-0 text-center text-dark text-center">
                        <thead class="bg-light small fw-bold text-dark text-center"><tr><th class="ps-4 text-start text-dark">Unit Pengaju & Program</th><th class="text-end">Usulan Dana</th><th width="450">Proses Otoritas</th></tr></thead>
                        <tbody>
                        <?php 
                        $res_app = $conn->query("SELECT a.*, IFNULL(u.nama_unit, 'UNIT PUSAT / GENERAL') as nama_unit FROM anggaran_unit_pengajuan a LEFT JOIN m_unit u ON a.unit_id = u.id WHERE a.tahun='$tahun' AND a.status IN ($status_in_notif) ORDER BY a.created_at ASC");
                        if($res_app && $res_app->num_rows > 0): while($app = $res_app->fetch_assoc()): 
                            $is_checked = ($app['status'] == 'CHECKED');
                            $btn_label = ($app['status'] == 'SUBMITTED' || $app['status'] == '') ? 'Validasi' : 'Approve';
                            $step_badge = $is_checked ? '<span class="badge bg-primary ms-2 small">Menunggu Pimpinan</span>' : '<span class="badge bg-warning text-dark ms-2 small">Menunggu Checker</span>';
                            $can_process = false;
                            if (($app['status'] == 'SUBMITTED' || $app['status'] == '') && (in_array($workflow_auth, ['CHECKER', 'ALL']) || $is_superadmin_root)) $can_process = true;
                            if ($app['status'] == 'CHECKED' && (in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root)) $can_process = true;
                        ?>
                            <tr>
                                <td class="ps-4 text-start text-dark"><b><?= htmlspecialchars($app['nama_unit']) ?></b> <?= $step_badge ?><br><small class="text-primary"><?= htmlspecialchars($app['program']) ?> - <?= htmlspecialchars($app['kegiatan']) ?></small></td>
                                <td class="text-end fw-bold text-primary fs-6">Rp <?= number_format($app['jumlah_pengajuan'] ?? $app['nominal_pengajuan'] ?? 0) ?></td>
                                <td class="p-3 text-center">
                                    <?php if($can_process): ?>
                                    <form action="budget_unit_action.php" method="POST" class="d-flex gap-2 align-items-center justify-content-center text-dark text-center">
                                        <input type="hidden" name="action" value="workflow_action"><input type="hidden" name="id" value="<?= $app['id'] ?>">
                                        <input type="text" name="jumlah_disetujui" class="form-control form-control-sm text-end fw-bold shadow-sm" value="<?= number_format($app['jumlah_pengajuan'] ?? $app['nominal_pengajuan'] ?? 0, 0, '', '') ?>" onkeyup="fmtRp(this)" style="width: 120px;">
                                        <input type="text" name="catatan_approval" class="form-control form-control-sm text-start shadow-sm" placeholder="Catatan/Alasan..." style="width: 160px;">
                                        <input type="hidden" name="decision" id="decision_<?= $app['id'] ?>">
                                        <button type="button" class="btn btn-success btn-sm px-3 shadow-sm rounded-3 fw-bold" onclick="submitApproval(<?= $app['id'] ?>,'APPROVE')" title="Setujui Usulan"><i class="fas fa-check me-1"></i> <?= $btn_label ?></button>
                                        <button type="button" class="btn btn-danger btn-sm px-3 shadow-sm rounded-3 fw-bold" onclick="submitApproval(<?= $app['id'] ?>,'REJECT')" title="Tolak Usulan"><i class="fas fa-times me-1"></i> Tolak</button>
                                    </form>
                                    <?php else: ?><div class="text-muted small italic px-4 py-2 border rounded-pill bg-light d-inline-block"><i class="fas fa-lock me-2"></i>Menunggu Otoritas Level Lain</div><?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; else: echo '<tr><td colspan="3" class="text-center py-5 text-muted small italic text-dark">Antrean persetujuan kosong.</td></tr>'; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Riwayat Batal Approve -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark shadow-sm text-center">
                <div class="card-header bg-dark text-white p-3 fw-bold small text-uppercase text-start">Riwayat Keputusan & Batal Approve</div>
                <div class="table-responsive text-dark">
                    <table class="table table-hover align-middle mb-0 text-center text-dark text-center">
                        <thead class="bg-light small fw-bold text-dark text-center"><tr><th class="ps-4 text-start text-dark">Unit Pengaju & Program</th><th>Status</th><th class="text-end">Nominal Disetujui</th><th width="100" class="text-end pe-4">Batal</th></tr></thead>
                        <tbody>
                        <?php 
                        $res_riwayat = $conn->query("SELECT a.*, IFNULL(u.nama_unit, 'UNIT PUSAT / GENERAL') as nama_unit FROM anggaran_unit_pengajuan a LEFT JOIN m_unit u ON a.unit_id = u.id WHERE a.tahun='$tahun' AND a.status IN ('CHECKED', 'APPROVED', 'REJECTED') ORDER BY a.updated_at DESC LIMIT 20");
                        if($res_riwayat && $res_riwayat->num_rows > 0): while($rw = $res_riwayat->fetch_assoc()): 
                            $can_cancel = false;
                            if ($rw['status'] == 'APPROVED' && (in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root)) $can_cancel = true;
                            if ($rw['status'] == 'CHECKED' && (in_array($workflow_auth, ['CHECKER', 'ALL']) || $is_superadmin_root)) $can_cancel = true;
                        ?>
                                <tr>
                                    <td class="ps-4 text-start text-dark"><b><?= htmlspecialchars($rw['nama_unit']) ?></b><br><small class="text-primary"><?= htmlspecialchars($rw['program']) ?></small></td>
                                    <td><span class="badge bg-<?= match($rw['status']){'APPROVED'=>'success','CHECKED'=>'primary',default=>'danger'} ?> rounded-pill px-3"><?= $rw['status'] ?></span></td>
                                    <td class="text-end fw-bold text-primary text-dark">Rp <?= number_format($rw['jumlah_disetujui'] ?: ($rw['jumlah_pengajuan'] ?? $rw['nominal_pengajuan'] ?? 0)) ?></td>
                                    <td class="text-end pe-4 text-center">
                                        <?php if($can_cancel): ?><a href="budget_unit_action.php?action=cancel_workflow_decision&id=<?= $rw['id'] ?>&tahun=<?= $tahun ?>" class="btn btn-sm btn-light text-danger rounded-circle border shadow-sm" onclick="return confirm('Batalkan keputusan ini dan kembalikan statusnya ke antrean sebelumnya?')"><i class="fas fa-undo"></i></a>
                                        <?php else: ?><button class="btn btn-sm btn-light text-muted rounded-circle border shadow-none" disabled title="Akses Batal Khusus Otoritas Terkait"><i class="fas fa-ban"></i></button><?php endif; ?>
                                    </td>
                                </tr>
                        <?php endwhile; else: echo '<tr><td colspan="4" class="text-center py-5 text-muted small italic text-dark text-center">Belum ada riwayat persetujuan.</td></tr>'; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 5. TAB MONITORING & ARSIP LAPORAN -->
        <?php if($can_monitor): ?>
        <div class="tab-pane fade <?= ($active_tab=='monitoring' || $active_tab=='arsip' || $active_tab=='detail')?'show active':'' ?>" id="tab-monitoring">
            <div class="row g-4 mb-4">
                <?php if($is_global_admin): ?>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white h-100">
                        <div class="card-header bg-white p-4 border-bottom text-center">
                            <h6 class="fw-bold text-primary mb-0 text-start"><i class="fas fa-sitemap me-2"></i>Daftar Unit Kerja</h6>
                        </div>
                        <div class="card-body p-2" style="max-height: 600px; overflow-y: auto;">
                            <div class="list-group list-group-flush">
                                <?php foreach($unit_list as $u): ?>
                                <a href="index.php?page=anggaran_unit&tab=monitoring&sub=arsip&unit_id=<?= $u['id'] ?>" class="list-group-item list-group-item-action <?= $selected_unit_id==$u['id']?'bg-primary text-white fw-bold shadow-sm':'text-dark' ?> border-0 mb-1 rounded-3 d-flex align-items-center py-3 px-3" style="font-size: 0.88rem; transition: 0.2s;">
                                    <i class="fas fa-building me-3 <?= $selected_unit_id==$u['id']?'text-white':'text-muted opacity-50' ?> fa-lg"></i>
                                    <span class="text-truncate fw-bold"><?= htmlspecialchars($u['kode_unit']) ?></span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="<?= $is_global_admin ? 'col-md-9' : 'col-md-12' ?>">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white h-100">
                        <!-- VIEW: LIVE MUTASI -->
                        <?php if($sub_view == 'live'): ?>
                            <div class="card-header bg-white p-4 d-flex justify-content-between align-items-center border-bottom">
                                <div class="text-start"><h5 class="fw-bold mb-0 text-dark">Live Mutasi Kas Unit</h5><small class="text-muted">Pantauan real-time arus kas buku besar.</small></div>
                                <div class="btn-group rounded-pill p-1 bg-light border shadow-sm">
                                    <a href="?page=anggaran_unit&tab=monitoring&sub=live&unit_id=<?= $selected_unit_id ?>" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold">Arus Kas</a>
                                    <a href="?page=anggaran_unit&tab=monitoring&sub=arsip&unit_id=<?= $selected_unit_id ?>" class="btn btn-sm btn-light rounded-pill px-4 text-muted border-0">Arsip Laporan</a>
                                </div>
                            </div>
                            <div class="card-body p-4 bg-light">
                                <form method="GET" class="row g-2 align-items-end mb-4 text-start">
                                    <input type="hidden" name="page" value="anggaran_unit"><input type="hidden" name="tab" value="monitoring"><input type="hidden" name="sub" value="live"><input type="hidden" name="unit_id" value="<?= $selected_unit_id ?>">
                                    <div class="col-md-4"><label class="small fw-bold text-muted mb-1">Mulai</label><input type="date" name="f_mulai" class="form-control rounded-pill border-0 shadow-sm px-3" value="<?= $f_mulai ?>"></div>
                                    <div class="col-md-4"><label class="small fw-bold text-muted mb-1">Akhir</label><input type="date" name="f_akhir" class="form-control rounded-pill border-0 shadow-sm px-3" value="<?= $f_akhir ?>"></div>
                                    <div class="col-md-4"><button type="submit" class="btn btn-primary w-100 rounded-pill shadow-sm fw-bold py-2">FILTER DATA</button></div>
                                </form>
                                <?php
                                $sel_uid_safe = (int)$selected_unit_id;
                                $u_kas_res = $conn->query("SELECT kas_bank_akun FROM m_unit WHERE id=$sel_uid_safe");
                                $u_kas = ($u_kas_res && $u_kas_res->num_rows > 0) ? $u_kas_res->fetch_assoc()['kas_bank_akun'] : '';
                                
                                if($u_kas): 
                                    $bal_awal = safeQuerySum($conn, "SELECT SUM(debit - kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$u_kas' AND j.tgl_jurnal < '$f_mulai 00:00:00'");
                                ?>
                                <div class="table-responsive rounded-4 border bg-white shadow-sm">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-dark text-white small text-center"><tr><th>TGL</th><th class="text-start ps-4">URAIAN</th><th class="text-end">DEBET</th><th class="text-end">KREDIT</th><th class="text-end pe-4">SALDO</th></tr></thead>
                                        <tbody>
                                            <tr class="fw-bold bg-light text-primary"><td colspan="4" class="text-start ps-4">SALDO AWAL (<?= date('d/m/Y', strtotime($f_mulai)) ?>)</td><td class="text-end pe-4">Rp <?= number_format($bal_awal) ?></td></tr>
                                            <?php 
                                            $mutasi = $conn->query("SELECT j.tgl_jurnal, j.keterangan, jd.debit, jd.kredit FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$u_kas' AND j.tgl_jurnal BETWEEN '$f_mulai 00:00:00' AND '$f_akhir 23:59:59' ORDER BY j.tgl_jurnal ASC");
                                            $curr = $bal_awal;
                                            if($mutasi && $mutasi->num_rows > 0):
                                                while($m = $mutasi->fetch_assoc()): $curr += ($m['debit'] - $m['kredit']); ?>
                                                <tr><td class="text-center small"><?= date('d/m/y', strtotime($m['tgl_jurnal'])) ?></td><td class="text-start ps-4"><?= $m['keterangan'] ?></td><td class="text-end text-success"><?= $m['debit']>0?number_format($m['debit']):'-' ?></td><td class="text-end text-danger"><?= $m['kredit']>0?number_format($m['kredit']):'-' ?></td><td class="text-end pe-4 fw-bold text-dark"><?= number_format($curr) ?></td></tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr><td colspan="5" class="text-center py-4 text-muted italic text-dark">Tidak ada transaksi.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: echo "<div class='alert alert-warning rounded-4 shadow-sm text-center border-warning border'><i class='fas fa-exclamation-triangle me-2'></i>Rekening kas unit belum di-mapping.</div>"; endif; ?>
                            </div>

                        <!-- VIEW: ARSIP LAPORAN DRAF/MENUNGGU -->
                        <?php elseif($sub_view == 'arsip'): ?>
                            <div class="card-header bg-white p-4 d-flex justify-content-between align-items-center border-bottom">
                                <div class="text-start"><h5 class="fw-bold mb-0 text-dark">Daftar Laporan Mutasi</h5><small class="text-muted">Penyimpanan Snapshot Laporan Kas.</small></div>
                                <div class="btn-group rounded-pill p-1 bg-light border shadow-sm">
                                    <a href="?page=anggaran_unit&tab=monitoring&sub=live&unit_id=<?= $selected_unit_id ?>" class="btn btn-sm btn-light rounded-pill px-4 text-muted border-0">Arus Kas</a>
                                    <a href="?page=anggaran_unit&tab=monitoring&sub=arsip&unit_id=<?= $selected_unit_id ?>" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold">Daftar Laporan</a>
                                </div>
                            </div>
                            <div class="card-body p-4 bg-light">
                                <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                                    <button class="btn btn-success rounded-pill px-4 mb-4 fw-bold shadow-sm" onclick="newReportModal()"><i class="fas fa-file-invoice me-2"></i>GENERATE LAPORAN BARU</button>
                                <?php endif; ?>
                                <div class="table-responsive rounded-4 border bg-white shadow-sm">
                                    <table class="table table-hover align-middle mb-0 text-center">
                                        <thead class="bg-dark text-white small"><tr><th>Aksi</th><th class="text-start ps-4">Judul Dokumen</th><th>Periode</th><th>Status</th><th class="text-end pe-4">Saldo Akhir</th></tr></thead>
                                        <tbody>
                                            <?php 
                                            $sel_uid_safe = (int)$selected_unit_id;
                                            $sql_arsip = "SELECT r.*, u.nama_unit FROM anggaran_unit_reports r JOIN m_unit u ON r.unit_id = u.id WHERE r.unit_id = $sel_uid_safe ORDER BY r.id DESC";
                                            $r_lap = $conn->query($sql_arsip);
                                            if($r_lap && $r_lap->num_rows > 0): while($dl=$r_lap->fetch_assoc()): 
                                                $p_awal = $dl['periode_awal'] ?? $dl['tgl_mulai'] ?? date('Y-m-01');
                                                $p_akhir = $dl['periode_akhir'] ?? $dl['tgl_selesai'] ?? date('Y-m-d');
                                                $st_lap = $dl['status'];
                                            ?>
                                            <tr><td><div class="btn-group btn-group-sm rounded-pill overflow-hidden border shadow-sm text-dark bg-white text-center">
                                                        <a href="?page=anggaran_unit&tab=monitoring&sub=detail&view_report=<?= $dl['id'] ?>&unit_id=<?= $selected_unit_id ?>" class="btn btn-white text-primary text-center" title="Buka Detail"><i class="fas fa-search-plus text-center"></i></a>
                                                        <?php if(!empty($dl['file_bukti'])): ?>
                                                            <a href="uploads/laporan_unit/<?= htmlspecialchars($dl['file_bukti']) ?>" target="_blank" class="btn btn-white text-info border-start text-center" title="Lihat Dokumen Upload"><i class="fas fa-file-pdf text-center"></i></a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if(in_array($st_lap, ['DRAFT', 'REVISI'])): ?>
                                                            <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                                                <button class="btn btn-white text-warning border-start text-center" onclick='editReportModal(<?= htmlspecialchars(json_encode($dl), ENT_QUOTES, 'UTF-8') ?>)' title="Ubah Draf Laporan"><i class="fas fa-edit text-center"></i></button>
                                                            <?php endif; ?>
                                                            <?php if(defined('RBAC_DEL') && RBAC_DEL): ?>
                                                                <a href="budget_unit_action.php?action=delete_report&id=<?= $dl['id'] ?>" class="btn btn-white text-danger border-start text-center" onclick="return confirm('Hapus draf ini permanen?')"><i class="fas fa-trash text-center"></i></a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div></td>
                                                <td class="fw-bold text-dark text-start ps-4 text-dark">
                                                    <?= htmlspecialchars($dl['nama_laporan'] ?? 'Laporan') ?><br><small class="text-muted"><?= htmlspecialchars($dl['nama_unit']) ?></small>
                                                    <?php if($st_lap == 'REVISI' && !empty($dl['catatan_revisi'])): ?>
                                                        <div class="small text-danger mt-1 fw-bold"><i class="fas fa-exclamation-triangle me-1"></i>Koreksi: <?= htmlspecialchars($dl['catatan_revisi']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small text-dark text-center"><?= date('d/m/y', strtotime($p_awal)) ?> - <?= date('d/m/y', strtotime($p_akhir)) ?></td>
                                                <td><span class="badge bg-<?= match($st_lap) { 'MENUNGGU'=>'info', 'DISETUJUI'=>'success', 'REVISI'=>'danger', default=>'warning text-dark' } ?> rounded-pill px-3"><?= $st_lap ?></span></td>
                                                <td class="fw-bold text-primary text-end pe-4 text-center">Rp <?= number_format($dl['saldo_akhir'] ?? 0) ?></td></tr>
                                            <?php endwhile; else: echo "<tr><td colspan='5' class='py-5 text-muted small italic text-center text-dark text-center text-center'>Belum ada riwayat laporan mutasi. Silakan generate baru.</td></tr>"; endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        <!-- VIEW: DETAIL ARSIP LAPORAN -->
                        <?php elseif ($sub_view == 'detail' && $view_report_id): 
                            $v_id_safe = (int)$view_report_id;
                            $rep_query = $conn->query("SELECT r.*, u.nama_unit, u.kas_bank_akun FROM anggaran_unit_reports r JOIN m_unit u ON r.unit_id = u.id WHERE r.id = $v_id_safe");
                            $rep = ($rep_query && $rep_query->num_rows > 0) ? $rep_query->fetch_assoc() : null;
                            
                            if($rep):
                                $p_awal_rep = $rep['periode_awal'] ?? $rep['tgl_mulai'] ?? date('Y-m-01');
                                $p_akhir_rep = $rep['periode_akhir'] ?? $rep['tgl_selesai'] ?? date('Y-m-d');
                        ?>
                            <div class="card-header bg-dark text-white p-4 d-flex justify-content-between align-items-center">
                                <div class="text-start"><h5 class="fw-bold mb-0">Rincian Arsip Laporan</h5><small class="opacity-75"><?= htmlspecialchars($rep['nama_laporan'] ?? 'Laporan') ?></small></div>
                                <a href="?page=anggaran_unit&tab=monitoring&sub=arsip&unit_id=<?= $rep['unit_id'] ?>" class="btn btn-sm btn-light rounded-pill px-4 fw-bold text-dark">KEMBALI</a>
                            </div>
                            <div class="card-body p-4 bg-light">
                                <div class="row text-center mb-4 g-3">
                                    <div class="col-md-4"><div class="bg-white p-3 rounded-4 shadow-sm border"><small class="text-muted d-block fw-bold mb-1">TOTAL MASUK</small><h4 class="text-success fw-bold mb-0">Rp <?= number_format($rep['total_debet'] ?? $rep['total_masuk'] ?? 0) ?></h4></div></div>
                                    <div class="col-md-4"><div class="bg-white p-3 rounded-4 shadow-sm border"><small class="text-muted d-block fw-bold mb-1">TOTAL KELUAR</small><h4 class="text-danger fw-bold mb-0">Rp <?= number_format($rep['total_kredit'] ?? $rep['total_keluar'] ?? 0) ?></h4></div></div>
                                    <div class="col-md-4"><div class="bg-white p-3 rounded-4 shadow-sm border border-primary"><small class="text-muted d-block fw-bold mb-1">SALDO AKHIR</small><h4 class="text-primary fw-bold mb-0">Rp <?= number_format($rep['saldo_akhir'] ?? 0) ?></h4></div></div>
                                </div>
                                <div class="d-flex gap-2 justify-content-center mt-3 mb-4">
                                    <a href="laporan_anggaran_unit.php?id=<?= $rep['id'] ?>" target="_blank" class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm"><i class="fas fa-print me-2"></i>Cetak Laporan</a>
                                    <?php if(in_array(($rep['status'] ?? 'DRAFT'), ['DRAFT', 'REVISI'])): ?>
                                        <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                            <button class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="modalUpload(<?= $rep['id'] ?>, <?= $rep['unit_id'] ?>)"><i class="fas fa-paper-plane me-2"></i>Kirim Laporan</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body p-0 border-top bg-white">
                                <div class="p-5 text-center border-bottom pb-4 mb-4 text-center">
                                    <h3 class="fw-bold text-dark mb-1 text-center"><?= strtoupper($profile['institution_name'] ?? 'STIKes Yarsi Pontianak') ?></h3>
                                    <p class="text-muted small mb-0 text-center"><?= $profile['address'] ?? '' ?>, <?= $profile['city'] ?? '' ?></p>
                                    <hr class="my-4">
                                    <h4 class="fw-bold mb-1 text-center text-center">LAPORAN MUTASI KAS UNIT</h4>
                                    <div class="fs-5 text-primary fw-bold text-uppercase text-center"><?= htmlspecialchars($rep['nama_unit']) ?></div>
                                    <div class="small text-muted fw-bold mt-2 text-center text-center">PERIODE: <?= date('d F Y', strtotime($p_awal_rep)) ?> s/d <?= date('d F Y', strtotime($p_akhir_rep)) ?></div>
                                </div>
                                <div class="table-responsive text-dark text-center px-4 pb-4">
                                    <table class="table table-bordered align-middle text-dark text-center mb-0">
                                        <thead class="table-light text-center small fw-bold text-center">
                                            <tr><th>TANGGAL</th><th class="text-start ps-3">URAIAN TRANSAKSI</th><th width="150" class="text-end pe-3">DEBET</th><th width="150" class="text-end pe-3">KREDIT</th><th width="180" class="text-end pe-3">SALDO AKHIR</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr class="fw-bold bg-light"><td colspan="2" class="ps-3 text-start">SALDO AWAL PERIODE (SNAPSHOT)</td><td colspan="2"></td><td class="text-end pe-3 text-primary">Rp <?= number_format($rep['saldo_awal'] ?? 0) ?></td></tr>
                                            <?php 
                                                $k_id = $rep['kas_bank_akun'];
                                                $res_d = $conn->query("SELECT j.tgl_jurnal, j.keterangan, jd.debit, jd.kredit FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$k_id' AND j.tgl_jurnal BETWEEN '$p_awal_rep 00:00:00' AND '$p_akhir_rep 23:59:59' ORDER BY j.tgl_jurnal ASC, j.id ASC");
                                                $run_bal = $rep['saldo_awal'] ?? 0; 
                                                if($res_d && $res_d->num_rows > 0):
                                                while($d = $res_d->fetch_assoc()): $run_bal += ($d['debit'] - $d['kredit']); ?>
                                            <tr><td class="text-center small text-center"><?= date('d/m/y', strtotime($d['tgl_jurnal'])) ?></td><td class="ps-3 text-start small"><?= $d['keterangan'] ?></td><td class="text-end pe-3 text-success"><?= $d['debit'] > 0 ? number_format($d['debit']) : '-' ?></td><td class="text-end pe-3 text-danger"><?= $d['kredit'] > 0 ? number_format($d['kredit']) : '-' ?></td><td class="text-end pe-3 fw-bold text-center text-center"><?= number_format($run_bal) ?></td></tr>
                                            <?php endwhile; endif; ?>
                                            <tr class="fw-bold bg-primary bg-opacity-10 text-primary text-center"><td colspan="2" class="text-end pe-4 text-center">TOTAL MUTASI & SALDO AKHIR</td><td class="text-end pe-3 text-center"><?= number_format($rep['total_debet'] ?? $rep['total_masuk'] ?? 0) ?></td><td class="text-end pe-3 text-center"><?= number_format($rep['total_kredit'] ?? $rep['total_keluar'] ?? 0) ?></td><td class="text-end pe-3 text-center text-center">Rp <?= number_format($rep['saldo_akhir'] ?? 0) ?></td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: echo "<div class='p-5 text-center text-muted italic'><i class='fas fa-file-excel fa-3x opacity-25 mb-3 d-block'></i> Laporan tidak ditemukan atau Anda tidak memiliki akses.</div>"; endif; ?>
                        
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- NEW TAB 6: VALIDASI CHECKER -->
        <?php if($can_validate): ?>
        <div class="tab-pane fade <?= $active_tab=='validasi'?'show active':'' ?>" id="tab-validasi">
            <?php if(file_exists('anggaran_unit_validasi.php')) include 'anggaran_unit_validasi.php'; else echo "<div class='alert alert-warning m-4'>Modul validasi laporan belum dipasang.</div>"; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- MODALS -->
<div class="modal fade" id="mdlReport" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="budget_unit_action.php" method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_new_report">
            <div class="modal-header bg-dark text-white p-4 border-0 text-center d-block">
                <h5 class="modal-title fw-bold text-white text-center">Buat Laporan Mutasi Baru</h5>
            </div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3 text-center">
                    <label class="small fw-bold text-muted uppercase">Nama Laporan</label>
                    <input type="text" name="nama_laporan" class="form-control rounded-pill border-0 shadow-sm px-4 fw-bold text-center" placeholder="Nama Laporan..." required>
                </div>
                <div class="mb-3 text-center">
                    <label class="small fw-bold text-primary uppercase">Pilih Unit / Lembaga</label>
                    <select name="target_unit_id" class="form-select rounded-pill border-0 shadow-sm px-3 text-center fw-bold text-dark" required>
                        <?php if(!$is_global_admin): 
                            $sel_uid = (int)$selected_unit_id;
                            $unm_q = $conn->query("SELECT nama_unit FROM m_unit WHERE id=$sel_uid");
                            $unm = ($unm_q && $unm_q->num_rows > 0) ? $unm_q->fetch_row()[0] : 'Unknown';
                        ?>
                            <option value="<?= $selected_unit_id ?>"><?= $unm ?></option>
                        <?php else: ?>
                            <option value="">-- Pilih Unit --</option>
                            <?php foreach($unit_list as $ul) { echo "<option value='{$ul['id']}'>{$ul['nama_unit']}</option>"; } ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="mb-3 text-center">
                    <label class="small fw-bold text-muted uppercase">Periode Tanggal</label>
                    <div class="input-group shadow-sm rounded-pill overflow-hidden border-0">
                        <input type="date" name="tgl_mulai" class="form-control border-0 px-3 text-center" required>
                        <span class="input-group-text border-0 bg-white">s/d</span>
                        <input type="date" name="tgl_selesai" class="form-control border-0 px-3 text-center" required>
                    </div>
                </div>
                <div class="mb-0 text-center mt-4">
                    <label class="small fw-bold text-primary uppercase d-block mb-2"><i class="fas fa-file-upload me-1"></i> Upload Bukti Transaksi (PDF/ZIP)</label>
                    <input type="file" name="file_bukti" class="form-control rounded-pill border-0 shadow-sm px-4 py-2 bg-white fw-bold" accept=".pdf,.zip,.rar" required>
                    <small class="text-muted mt-2 d-block" style="font-size: 10px;">Format: PDF, ZIP, atau RAR. Laporan beserta file ini akan divalidasi oleh Checker.</small>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white text-center d-block">
                <div class="row g-2">
                    <div class="col-6">
                        <button type="button" class="btn btn-light w-100 rounded-pill py-3 fw-bold shadow-sm text-muted" data-bs-dismiss="modal">BATALKAN</button>
                    </div>
                    <div class="col-6">
                        <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">GENERATE & DRAF</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="mdlEditReport" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="budget_unit_action.php" method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="update_report">
            <input type="hidden" name="report_id" id="edit_report_id">
            <div class="modal-header bg-warning text-dark p-4 border-0 text-center d-block">
                <h5 class="modal-title fw-bold text-center">Ubah Data Laporan Mutasi</h5>
            </div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3 text-center">
                    <label class="small fw-bold text-muted uppercase">Nama Laporan</label>
                    <input type="text" name="nama_laporan" id="edit_nama_laporan" class="form-control rounded-pill border-0 shadow-sm px-4 fw-bold text-center" required>
                </div>
                <div class="mb-3 text-center">
                    <label class="small fw-bold text-muted uppercase">Periode Tanggal</label>
                    <div class="input-group shadow-sm rounded-pill overflow-hidden border-0">
                        <input type="date" name="tgl_mulai" id="edit_tgl_mulai" class="form-control border-0 px-3 text-center" required>
                        <span class="input-group-text border-0 bg-white">s/d</span>
                        <input type="date" name="tgl_selesai" id="edit_tgl_selesai" class="form-control border-0 px-3 text-center" required>
                    </div>
                </div>
                <div class="mb-3 text-center">
                    <label class="small fw-bold text-primary uppercase d-block mb-2"><i class="fas fa-file-upload me-1"></i> Upload Bukti Pengganti (Opsional)</label>
                    <input type="file" name="file_bukti" class="form-control rounded-pill border-0 shadow-sm px-4 py-2 bg-white fw-bold" accept=".pdf,.zip,.rar">
                    <small class="text-muted mt-2 d-block" style="font-size: 10px;">Biarkan kosong jika tidak ingin mengganti file sebelumnya.</small>
                </div>
                <div class="p-2 bg-info bg-opacity-10 rounded-3 small text-info italic border border-info text-center">
                    <i class="fas fa-sync-alt me-1"></i>Sistem akan melakukan Rekalkulasi Jurnal secara otomatis berdasarkan rentang tanggal baru.
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white text-center d-block">
                <div class="row g-2">
                    <div class="col-6">
                        <button type="button" class="btn btn-light w-100 rounded-pill py-3 fw-bold shadow-sm text-muted" data-bs-dismiss="modal">BATALKAN</button>
                    </div>
                    <div class="col-6">
                        <button type="submit" class="btn btn-warning w-100 rounded-pill py-3 fw-bold shadow">SIMPAN PERUBAHAN</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="mdlUpload" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark text-center modal-sm">
        <form action="budget_unit_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-center">
            <input type="hidden" name="action" value="submit_report_to_arsip">
            <input type="hidden" name="report_id" id="upload_report_id">
            <input type="hidden" name="unit_id" id="upload_unit_id">
            <div class="modal-body p-5 bg-light text-dark text-center">
                <i class="fas fa-paper-plane fa-4x text-success mb-4 animate__animated animate__bounceIn"></i>
                <h5 class="fw-bold mb-3">Kirim laporan sekarang?</h5>
                <p class="text-muted small mb-0">Laporan beserta dokumen bukti yang telah diunggah akan dikunci dan dikirim ke meja Checker untuk proses validasi.</p>
            </div>
            <div class="modal-footer p-3 border-0 bg-white d-block text-center">
                <div class="row g-2">
                    <div class="col-6">
                        <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold shadow-sm text-muted" data-bs-dismiss="modal">BATALKAN</button>
                    </div>
                    <div class="col-6">
                        <button type="submit" class="btn btn-success w-100 rounded-pill py-2 fw-bold shadow text-center">KIRIM</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function initDashboardCharts() {
    const formatVal = v => { if (v >= 1000000) return (v/1000000).toFixed(1) + ' Jt'; if (v >= 1000) return (v/1000).toFixed(0) + ' Rb'; return v; };
    
    const ctxL = document.getElementById('chartLineTrend');
    if(ctxL) { 
        new Chart(ctxL, { 
            type: 'line', 
            data: { 
                labels: ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"], 
                datasets: [{ label: 'Realisasi Aktual (Rp)', borderColor: '#0d6efd', backgroundColor: 'rgba(13, 110, 253, 0.1)', data: <?= json_encode(array_values($monthly_real)) ?>, fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: '#fff', borderWidth: 3 }] 
            }, 
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: v => formatVal(v) } } }, plugins: { legend: { display: false } } } 
        }); 
    }

    const ctxD = document.getElementById('chartDonutAlokasi');
    if(ctxD && <?= !empty($komp_values) ? 'true' : 'false' ?>) { 
        new Chart(ctxD, { 
            type: 'doughnut', 
            data: { 
                labels: <?= json_encode($komp_labels) ?>, 
                datasets: [{ data: <?= json_encode($komp_values) ?>, backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b'], borderWidth: 0, hoverOffset: 4 }] 
            }, 
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } } 
        }); 
    }
}

function newReportModal() { new bootstrap.Modal(document.getElementById('mdlReport')).show(); }
function editReportModal(data) { document.getElementById('edit_report_id').value = data.id; document.getElementById('edit_nama_laporan').value = data.nama_laporan; document.getElementById('edit_tgl_mulai').value = data.periode_awal || data.tgl_mulai; document.getElementById('edit_tgl_selesai').value = data.periode_akhir || data.tgl_selesai; new bootstrap.Modal(document.getElementById('mdlEditReport')).show(); }
function modalUpload(rep_id, unit_id) { document.getElementById('upload_report_id').value = rep_id; document.getElementById('upload_unit_id').value = unit_id; new bootstrap.Modal(document.getElementById('mdlUpload')).show(); }

function editProposal(h) { 
    document.getElementById('edit_id').value = h.id; 
    document.getElementById('prop_unit').value = h.unit_id; 
    document.getElementById('prop_jenis').value = h.jenis_pengajuan || 'TAMBAHAN_PAGU'; 
    document.getElementById('prop_master_prog').value = h.program; 
    document.getElementById('prop_master_det').value = h.kegiatan || h.justifikasi || '';
    document.getElementById('ws_unit_body').innerHTML = ''; 
    
    addBudgetRow(); 
    
    const rows = document.getElementById('ws_unit_body').querySelectorAll('.row'); 
    rows[0].querySelector('input[name="uraian[]"]').value = h.kegiatan; 
    
    const coa_code = h.coa_kode || h.kode_akun;
    rows[0].querySelector('select[name="coa_kode[]"]').value = coa_code; 
    
    const jml = h.nominal_pengajuan || h.jumlah_pengajuan || 0;
    rows[0].querySelector('input[name="jumlah[]"]').value = new Intl.NumberFormat('id-ID').format(jml); 
    
    document.getElementById('btnCancelEdit').classList.remove('d-none');
    window.scrollTo({ top: document.getElementById('formBulkProposal').offsetTop - 100, behavior: 'smooth' });
}

function cancelEditProposal() {
    document.getElementById('formBulkProposal').reset();
    document.getElementById('edit_id').value = '';
    document.getElementById('ws_unit_body').innerHTML = ''; 
    document.getElementById('btnCancelEdit').classList.add('d-none');
    addBudgetRow();
}

document.addEventListener("DOMContentLoaded", function() {
    if (typeof initDashboardCharts === 'function') {
        initDashboardCharts(); 
    }
    if(!document.getElementById('edit_id').value) addBudgetRow();
});

function submitApproval(id, decision){
    const form = document.getElementById('decision_'+id).closest('form');
    const inputJml = form.querySelector('input[name="jumlah_disetujui"]');
    const inputCatatan = form.querySelector('input[name="catatan_approval"]');
    
    if(decision === 'REJECT' && inputCatatan && inputCatatan.value.trim() === '') {
        alert('Catatan/Alasan wajib diisi jika menolak usulan!');
        inputCatatan.focus();
        return;
    }
    
    document.getElementById('decision_'+id).value = decision;
    if (inputJml) { inputJml.value = inputJml.value.replace(/\D/g, ""); }
    form.querySelectorAll('button').forEach(b => b.disabled = true);
    form.submit();
}

function fmtRp(el) { let v = el.value.replace(/\D/g, ""); el.value = new Intl.NumberFormat('id-ID').format(v); }
function addBudgetRow() { const body = document.getElementById('ws_unit_body'); const div = document.createElement('div'); div.className = 'row g-2 mb-2 text-center'; div.innerHTML = `<div class=\"col-5 text-dark\"><input type=\"text\" name=\"uraian[]\" class=\"form-control border-0 bg-light small text-center text-dark\" placeholder=\"Uraian\" required></div><div class=\"col-4 text-dark\"><select name=\"coa_kode[]\" class=\"form-select border-0 bg-light small text-center text-dark\" required><option value=\"\">-- Akun --</option><?php foreach($coa_beban as $c) echo "<option value='{$c['kode_akun']}'>{$c['kode_akun']} - {$c['nama_akun']}</option>"; ?></select></div><div class=\"col-2 text-dark\"><input type=\"text\" name=\"jumlah[]\" class=\"form-control border-0 bg-light fw-bold text-end text-dark\" onkeyup=\"fmtRp(this)\" placeholder=\"0\" required></div><div class=\"col-1 text-dark\"><button type=\"button\" class=\"btn btn-link text-danger text-center\" onclick=\"this.closest('.row').remove()\"><i class=\"fas fa-times-circle\"></i></button></div>`; body.appendChild(div); }
</script>

<style>.nav-tabs .nav-link{color:#64748b;font-weight:700;border:none;padding:15px 25px;transition:0.3s;border-radius:12px 12px 0 0;}.nav-tabs .nav-link.active{color:#0d6efd;border-bottom:4px solid #0d6efd;background:rgba(13, 110, 253, 0.05);}.table-hover tbody tr:hover{background-color:#f8fafc;}</style>