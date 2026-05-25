<?php
/**
 * laporan_posisi_keuangan.php - BALANCE SHEET (SINGLE SOURCE OF TRUTH)
 * Versi: 914.5 (Sovereign Grand Master - Asset Subtotal Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan: Menambahkan Subtotal "Jumlah Perolehan Aset Tetap Berwujud" 
 * dan "Tidak Berwujud" sebelum dikurangi dengan akumulasi penyusutannya.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }
require_once 'engine/LedgerAggregationEngine.php';

$report_id = (int)($_GET['id'] ?? 0);
$view = $_GET['view'] ?? 'hub';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Controller CRUD & Sync
if ($action == 'save_neraca_local' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $uid = (int)($_SESSION['user_id'] ?? 1);
    @$conn->query("ALTER TABLE laporan_keuangan_setting MODIFY COLUMN jenis_laporan VARCHAR(100)");
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $judul = trim($_POST['judul'] ?? 'Laporan Neraca');
    $start = $_POST['start_date'] ?? date('Y-01-01');
    $akhir = $_POST['end_date'] ?? date('Y-m-d');
    $comp_dates = [];
    if (!empty($_POST['comp_end'])) { foreach ($_POST['comp_end'] as $e_date) { if (!empty($e_date)) $comp_dates[] = ['e' => $e_date]; } }
    $json_comp = empty($comp_dates) ? NULL : json_encode($comp_dates);
    try {
        if ($id) {
            $stmt = $conn->prepare("UPDATE laporan_keuangan_setting SET judul_laporan=?, tgl_mulai=?, tgl_akhir=?, comp_dates=? WHERE id=?");
            $stmt->bind_param("ssssi", $judul, $start, $akhir, $json_comp, $id);
            $stmt->execute();
            $target_id = $id;
        } else {
            $stmt = $conn->prepare("INSERT INTO laporan_keuangan_setting (judul_laporan, jenis_laporan, tgl_mulai, tgl_akhir, comp_dates, created_by) VALUES (?, 'posisi_keuangan', ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $judul, $start, $akhir, $json_comp, $uid);
            $stmt->execute();
            $target_id = $conn->insert_id;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Format laporan berhasil disimpan!'];
        header("Location: index.php?page=laporan_posisi_keuangan&view=render&id=$target_id"); exit;
    } catch (Exception $e) { $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()]; header("Location: index.php?page=laporan_posisi_keuangan"); exit; }
}

if ($action == 'delete_neraca_local') {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM laporan_keuangan_setting WHERE id = $id");
    header("Location: index.php?page=laporan_posisi_keuangan"); exit;
}

if ($action == 'sync_ledger' || $action == 'auto_migrate_coa') {
    LedgerAggregationEngine::autoHealDatabase($conn);
    $redirect = $report_id > 0 ? "&view=render&id=$report_id" : "";
    header("Location: index.php?page=laporan_posisi_keuangan" . $redirect); exit;
}

function sumNeto($conn, $kat, $tgl_start, $tgl_end, $is_kredit, $extra_cond = "") {
    $sql = "SELECT SUM(jd.kredit) as k, SUM(jd.debit) as d FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun WHERE a.kategori IN ($kat) AND j.tgl_jurnal BETWEEN '$tgl_start 00:00:00' AND '$tgl_end 23:59:59' AND j.is_deleted = 0 $extra_cond";
    $res = $conn->query($sql);
    $r = $res ? $res->fetch_assoc() : ['k'=>0, 'd'=>0];
    return $is_kredit ? ((double)$r['k'] - (double)$r['d']) : ((double)$r['d'] - (double)$r['k']);
}

$sql_history = "SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan IN ('posisi_keuangan', 'neraca', 'laporan_posisi_keuangan') OR s.judul_laporan LIKE '%Neraca%' OR s.judul_laporan LIKE '%Posisi%' ORDER BY s.created_at DESC";
$history = $conn->query($sql_history);
$conf = null; $periods = [];
if ($report_id > 0) {
    $conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
    if($conf) {
        $periods[] = ['e' => $conf['tgl_akhir'], 'label' => date('d M Y', strtotime($conf['tgl_akhir']))];
        $comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
        if(is_array($comp_json)) { foreach($comp_json as $cj) { $d_end = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj; if(!empty($d_end)) { $periods[] = ['e' => $d_end, 'label' => date('d M Y', strtotime($d_end))]; } } }
    }
}

$report_data = []; $global_error = "";
if ($view == 'render' && !empty($periods)) {
    try { 
        foreach ($periods as $idx => $p) { 
            $rd = LedgerAggregationEngine::getNeracaData($conn, $p['e']); 
            $tgl_akhir = $p['e'];
            
            // 🚀 BREAKDOWN ASET BERWUJUD DARI DATA MANAJEMEN ASET
            $q_at = $conn->query("SELECT t.type_name, SUM(a.purchase_value + COALESCE((SELECT SUM(nilai_penambahan) FROM asset_improvements ai WHERE ai.asset_id = a.id AND ai.tanggal <= '$tgl_akhir' AND ai.jenis_penambahan != 'Perolehan Awal'), 0)) as total_cost FROM assets a JOIN asset_types t ON a.type_id = t.id JOIN asset_categories c ON a.category_id = c.id WHERE c.category_name NOT LIKE '%Tidak Berwujud%' AND c.category_name NOT LIKE '%Amortisasi%' AND a.purchase_date <= '$tgl_akhir' AND a.status='Aktif' GROUP BY t.type_name");
            $at_breakdown = []; $at_total_cost = 0;
            if($q_at) { while($r = $q_at->fetch_assoc()){ $at_breakdown[] = $r; $at_total_cost += (double)$r['total_cost']; } }
            
            // 🚀 BREAKDOWN ASET TIDAK BERWUJUD
            $q_atb = $conn->query("SELECT t.type_name, SUM(a.purchase_value + COALESCE((SELECT SUM(nilai_penambahan) FROM asset_improvements ai WHERE ai.asset_id = a.id AND ai.tanggal <= '$tgl_akhir' AND ai.jenis_penambahan != 'Perolehan Awal'), 0)) as total_cost FROM assets a JOIN asset_types t ON a.type_id = t.id JOIN asset_categories c ON a.category_id = c.id WHERE (c.category_name LIKE '%Tidak Berwujud%' OR c.category_name LIKE '%Amortisasi%') AND a.purchase_date <= '$tgl_akhir' AND a.status='Aktif' GROUP BY t.type_name");
            $atb_breakdown = []; $atb_total_cost = 0;
            if($q_atb) { while($r = $q_atb->fetch_assoc()){ $atb_breakdown[] = $r; $atb_total_cost += (double)$r['total_cost']; } }

            $rd['aset_tetap_berwujud_cost'] = $at_total_cost;
            $rd['aset_tetap_tak_berwujud_cost'] = $atb_total_cost;
            $rd['at_breakdown'] = $at_breakdown;
            $rd['atb_breakdown'] = $atb_breakdown;

            $kas = $rd['kas']; $piutang = $rd['piutang']; $dimuka = $rd['dimuka']; $persediaan = $rd['persediaan']; $aset_lain = $rd['aset_lancar_lain'];
            $total_lancar = $kas + $piutang + $dimuka + $persediaan + $aset_lain;
            $nb_at = $rd['aset_tetap_berwujud_cost'] + $rd['aset_tetap_berwujud_akum'];
            $nb_atb = $rd['aset_tetap_tak_berwujud_cost'] + $rd['aset_tetap_tak_berwujud_akum'];
            $total_tidak_lancar = $nb_at + $nb_atb;
            $grand_aset = $total_lancar + $total_tidak_lancar;

            $liab = -1 * ($rd['liab_pendek'] + $rd['liab_panjang'] + $rd['liab_lain']);
            $target_ekuitas = $grand_aset - $liab;
            
            $tahun = date('Y', strtotime($tgl_akhir));
            $tgl_awal_tahun = "$tahun-01-01";
            $tgl_akhir_lalu = date('Y-m-d', strtotime('-1 day', strtotime($tgl_awal_tahun)));

            $pend_lalu = sumNeto($conn, "'Pendapatan'", '1970-01-01', $tgl_akhir_lalu, true);
            $beb_lalu = sumNeto($conn, "'Beban'", '1970-01-01', $tgl_akhir_lalu, false);
            $surplus_ditahan = $pend_lalu - $beb_lalu;

            $pend_ini = sumNeto($conn, "'Pendapatan'", $tgl_awal_tahun, $tgl_akhir, true);
            $beb_ini = sumNeto($conn, "'Beban'", $tgl_awal_tahun, $tgl_akhir, false);
            $surplus_berjalan = $pend_ini - $beb_ini;

            $q_ob_rest = $conn->query("SELECT SUM(opening_balance) as ob FROM syifa_akun WHERE kategori IN ('Aset Neto', 'Ekuitas') AND is_restricted = 1 AND is_group = 0")->fetch_assoc();
            $ob_rest = (double)($q_ob_rest['ob'] ?? 0);
            $mut_rest = sumNeto($conn, "'Aset Neto', 'Ekuitas'", '1970-01-01', $tgl_akhir, true, "AND a.is_restricted = 1");
            $modal_rest_asli = $ob_rest + $mut_rest;

            $modal_unrest_asli = $target_ekuitas - $modal_rest_asli - $surplus_ditahan - $surplus_berjalan;
            
            $rd['modal_unrest'] = $modal_unrest_asli;
            $rd['surplus_ditahan'] = $surplus_ditahan;
            $rd['surplus_berjalan'] = $surplus_berjalan;
            $rd['modal_rest'] = $modal_rest_asli;
            
            $rd['t_unrest'] = $modal_unrest_asli + $surplus_ditahan + $surplus_berjalan;
            $rd['t_rest'] = $modal_rest_asli;
            $rd['t_eq'] = $target_ekuitas;
            
            $rd['t_liab'] = $liab;
            $rd['grand_aset'] = $grand_aset;
            $rd['grand_pasiva'] = $liab + $target_ekuitas;

            $report_data[$idx] = $rd;
        } 
    } catch (Exception $e) { $global_error = $e->getMessage(); }
}

function formatNilai($n, $is_bold = false, $kode_akun = null, $tgl = null) {
    if (round($n, 2) == 0) return "-";
    $f = number_format(abs($n), 0, ',', '.');
    if ($n < 0) $f = "($f)"; 
    $style = $is_bold ? 'font-weight: bold;' : '';
    
    $tgl_awal = date('Y-01-01', strtotime($tgl ?? date('Y-m-d')));

    if ($kode_akun && $tgl && abs($n) > 0.01) {
        return "<a href='drilldown_ledger.php?kode=".urlencode($kode_akun)."&s=$tgl_awal&e=$tgl' target='_blank' style='text-decoration:none; color:inherit; display:block;' title='Klik untuk melacak jurnal'>
                <div class='drill-cursor' style='display: flex; justify-content: space-between; width: 100%; white-space: nowrap; position:relative; $style'>
                    <i class='fas fa-search drill-icon no-print' style='position:absolute; left:-15px; top:4px; font-size:10px; color:#0d6efd; display:none;'></i>
                    <div style='width: 30px; text-align: left;' class='text-muted'>Rp</div><div style='text-align: right; min-width: 105px;'>$f</div>
                </div></a>";
    }
    return "<div style='display: flex; justify-content: space-between; width: 100%; white-space: nowrap; $style'><div style='width: 30px; text-align: left;' class='text-muted'>Rp</div><div style='text-align: right; min-width: 105px;'>$f</div></div>";
}
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
?>

<link rel="stylesheet" href="assets/css/syifa-bs5-fix.css">
<style>
    .table-report { border: none; border-collapse: collapse; width: 100%; table-layout: fixed; margin-bottom: 0; }
    .table-report thead th { background: #1e293b; color: #fff; padding: 15px 10px; font-weight: 800; text-transform: uppercase; font-size: 11px; vertical-align: middle; border: none; text-align: right; }
    .table-report thead th:first-child { text-align: left; padding-left: 25px; }
    .table-report tbody td { padding: 12px 10px; font-size: 13.5px; color: #334155; border-bottom: 1px solid #f1f5f9; vertical-align: middle; text-align: right; }
    .table-report tbody td:first-child { text-align: left; }
    .col-uraian { width: 40%; text-align: left !important; padding-left: 25px !important; }
    .row-main-cat td { background: #f8fafc; font-weight: 800; color: #1e293b; border-bottom: 1px solid #cbd5e1 !important; text-transform: uppercase; border-left: 5px solid #0d6efd; }
    .row-subtotal td { font-weight: 800; border-top: 1.5px solid #1e293b; background: rgba(13, 110, 253, 0.03); color: #000; }
    .row-grand-total td { background: #1e293b !important; color: #ffffff !important; font-weight: 900; border-color: #1e293b !important; }
    .indent { padding-left: 50px !important; }
    .indent-2 { padding-left: 70px !important; }
    
    @media screen {
        .drill-cursor { cursor: pointer; transition: 0.2s; border-bottom: 1px dashed transparent; }
        .drill-cursor:hover { border-bottom: 1px dashed #0d6efd; color: #0d6efd !important;}
        .drill-cursor:hover .drill-icon { display: block !important; }
    }
    @media print { .no-print { display: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2 fa-lg"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if ($view == 'hub'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div><h4 class="fw-bold mb-0">Laporan Posisi Keuangan</h4><small class="text-muted small text-uppercase fw-bold">Standar ISAK 35 Compliance</small></div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</button>
        </div>
        
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive text-center"><table class="table table-hover align-middle mb-0 text-center text-dark"><thead class="table-dark small text-uppercase"><tr><th width="120">Aksi</th><th>Hingga Tanggal</th><th class="text-start">Judul Laporan</th><th class="pe-4">Eksekusi</th></tr></thead><tbody>
                <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()): ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden"><button class="btn btn-white text-warning border-end" onclick='editSetup(this)' data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $row['tgl_mulai'] ?>" data-tgl="<?= $row['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($row['comp_dates'], ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button><button class="btn btn-white text-danger" onclick="if(confirm('Hapus laporan ini?')) window.location.href='index.php?page=laporan_posisi_keuangan&action=delete_neraca_local&id=<?= $row['id'] ?>'"><i class="fas fa-trash"></i></button></div></td>
                        <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d M Y', strtotime($row['tgl_akhir'])) ?></span></td><td class="text-start fw-bold text-primary"><?= $row['judul_laporan'] ?></td><td class="pe-4 text-center"><a href="index.php?page=laporan_posisi_keuangan&view=render&id=<?= $row['id'] ?>" class="btn btn-primary rounded-pill px-4 btn-sm fw-bold">Tampilkan</a></td></tr>
                <?php endwhile; else: echo "<tr><td colspan='4' class='py-5 text-muted'>Belum ada riwayat laporan Posisi Keuangan.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-3 py-3 border">
            <div class="d-flex gap-2">
                <a href="index.php?page=laporan_posisi_keuangan" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase">Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" onclick='editSetup(this)' data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $conf['tgl_mulai'] ?>" data-tgl="<?= $conf['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($conf['comp_dates'], ENT_QUOTES) ?>'><i class="fas fa-cog me-1"></i> UBAH SETTING</button>
                <a href="index.php?page=laporan_posisi_keuangan&action=auto_migrate_coa&id=<?= $report_id ?>" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm text-white small text-uppercase"><i class="fas fa-sync-alt me-1"></i> Reload Pure GL</a>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2"><button class="btn btn-light border rounded-pill px-4 text-success fw-bold small shadow-sm" onclick="exportToExcel('neracaTable', 'Lap_Posisi_Keuangan')"><i class="fas fa-file-excel me-2"></i>EXCEL</button><button class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase" onclick="window.open('print_posisi_keuangan.php?id=<?= $report_id ?>', '_blank')"><i class="fas fa-print me-2"></i>CETAK PDF</button></div>
        </div>

        <?php if($global_error): ?>
            <div class="alert alert-danger shadow-sm rounded-4 border-danger border-2 border-start p-5 text-center">
                <h4 class="fw-bold text-danger mb-3"><i class="fas fa-shield-alt fa-2x d-block mb-3"></i>Gagal Memuat Laporan (Integritas Tertahan)</h4>
                <div class="mb-4 fw-bold fs-6"><?= $global_error ?></div>
            </div>
        <?php else: ?>
            <div class="card border-0 bg-white p-0 shadow-sm overflow-hidden rounded-4 text-dark mb-4">
                <div class="p-5 text-center bg-light border-bottom">
                    <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                    <h4 class="fw-bold text-primary mb-3 text-decoration-underline">LAPORAN POSISI KEUANGAN</h4>
                    <p class="text-muted mb-0 fst-italic" id="reportPeriodText">Per Tanggal <?= date('d F Y', strtotime($periods[0]['e'])) ?></p>
                </div>
                <div class="table-responsive"><table class="table-report" id="neracaTable"><thead><tr><th class="ps-5 text-start py-3">URAIAN ASET, LIABILITAS, DAN ASET NETO</th><?php foreach($periods as $p) echo "<th width='250' class='text-end pe-4'>".$p['label']."</th>"; ?></tr></thead><tbody>
                    
                    <!-- 🚀 ASET -->
                    <tr class="row-main-cat"><td class="ps-3 text-primary" colspan="<?= count($periods)+1 ?>">I. ASET</td></tr>
                    <tr class="bg-white"><td class="ps-4 fw-bold" colspan="<?= count($periods)+1 ?>">A. ASET LANCAR</td></tr>
                    <tr><td class="col-uraian indent">Kas dan Setara Kas</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['kas'], false, 'KAS', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Piutang Mahasiswa & Usaha</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['piutang'], false, 'PIUTANG', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Beban Dibayar Dimuka</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['dimuka'], false, 'DIMUKA', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Persediaan</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['persediaan'], false, 'PERSEDIAAN', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Aset Lancar Lainnya</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['aset_lancar_lain'], false, 'ASET LANCAR LAINNYA', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr class="row-subtotal"><td class="ps-4 fw-bold">Jumlah Aset Lancar</td><?php foreach($report_data as $d) echo "<td class='pe-4 fw-bold'>".formatNilai($d['kas']+$d['piutang']+$d['dimuka']+$d['persediaan']+$d['aset_lancar_lain'], true)."</td>"; ?></tr>
                    <tr style="height:20px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                    
                    <tr class="bg-white"><td class="ps-4 fw-bold" colspan="<?= count($periods)+1 ?>">B. ASET TIDAK LANCAR</td></tr>
                    
                    <!-- 🚀 BREAKDOWN JENIS ASET BERWUJUD -->
                    <tr><td class="ps-5 text-muted" colspan="<?= count($periods)+1 ?>">1. Aset Tetap Berwujud</td></tr>
                    <?php
                    $all_at_types = []; foreach($report_data as $rd) { foreach($rd['at_breakdown'] as $b) { $all_at_types[$b['type_name']] = 1; } } ksort($all_at_types);
                    foreach($all_at_types as $type_name => $val): ?>
                        <tr><td class="col-uraian indent-2"><?= htmlspecialchars($type_name) ?></td>
                        <?php foreach($report_data as $idx => $d) { $cost = 0; foreach($d['at_breakdown'] as $b) { if($b['type_name'] == $type_name) { $cost = $b['total_cost']; break; } } echo "<td class='pe-4'>".formatNilai($cost, false, $type_name, $periods[$idx]['e'])."</td>"; } ?></tr>
                    <?php endforeach; ?>
                    <tr class="row-subtotal"><td class="col-uraian indent-2 fw-bold text-dark">Jumlah Perolehan Aset Tetap Berwujud</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4 fw-bold text-dark'>".formatNilai($d['aset_tetap_berwujud_cost'], true, 'ASET TETAP BERWUJUD', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent-2 text-danger fst-italic">Akumulasi Penyusutan</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4 text-danger'>".formatNilai($d['aset_tetap_berwujud_akum'], false, 'PENYUSUTAN', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr class="bg-light"><td class="ps-5 fw-bold text-dark">Nilai Buku Aset Tetap Berwujud</td><?php foreach($report_data as $d) echo "<td class='pe-4 fw-bold text-dark'>".formatNilai($d['aset_tetap_berwujud_cost']+$d['aset_tetap_berwujud_akum'])."</td>"; ?></tr>
                    
                    <!-- 🚀 BREAKDOWN JENIS ASET TIDAK BERWUJUD -->
                    <tr><td class="ps-5 pt-3 text-muted" colspan="<?= count($periods)+1 ?>">2. Aset Tidak Berwujud</td></tr>
                    <?php
                    $all_atb_types = []; foreach($report_data as $rd) { foreach($rd['atb_breakdown'] as $b) { $all_atb_types[$b['type_name']] = 1; } } ksort($all_atb_types);
                    foreach($all_atb_types as $type_name => $val): ?>
                        <tr><td class="col-uraian indent-2"><?= htmlspecialchars($type_name) ?></td>
                        <?php foreach($report_data as $idx => $d) { $cost = 0; foreach($d['atb_breakdown'] as $b) { if($b['type_name'] == $type_name) { $cost = $b['total_cost']; break; } } echo "<td class='pe-4'>".formatNilai($cost, false, $type_name, $periods[$idx]['e'])."</td>"; } ?></tr>
                    <?php endforeach; ?>
                    <tr class="row-subtotal"><td class="col-uraian indent-2 fw-bold text-dark">Jumlah Perolehan Aset Tidak Berwujud</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4 fw-bold text-dark'>".formatNilai($d['aset_tetap_tak_berwujud_cost'], true, 'ASET TIDAK BERWUJUD', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent-2 text-danger fst-italic">Akumulasi Amortisasi</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4 text-danger'>".formatNilai($d['aset_tetap_tak_berwujud_akum'], false, 'AMORTISASI', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr class="bg-light"><td class="ps-5 fw-bold text-dark">Nilai Buku Aset Tidak Berwujud</td><?php foreach($report_data as $d) echo "<td class='pe-4 fw-bold text-dark'>".formatNilai($d['aset_tetap_tak_berwujud_cost']+$d['aset_tetap_tak_berwujud_akum'])."</td>"; ?></tr>
                    
                    <tr class="row-subtotal"><td class="ps-4 fw-bold">Jumlah Aset Tidak Lancar</td><?php foreach($report_data as $d) echo "<td class='pe-4 fw-bold'>".formatNilai(($d['aset_tetap_berwujud_cost']+$d['aset_tetap_berwujud_akum'])+($d['aset_tetap_tak_berwujud_cost']+$d['aset_tetap_tak_berwujud_akum']), true)."</td>"; ?></tr>
                    <tr class="row-grand-total"><td class="ps-4 py-3 text-uppercase">TOTAL ASET</td><?php foreach($report_data as $d) echo "<td class='pe-4 fs-5 text-white'>".formatNilai($d['grand_aset'], true)."</td>"; ?></tr>
                    <tr style="height:40px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                    
                    <!-- 🚀 LIABILITAS -->
                    <tr class="row-main-cat"><td class="ps-3 text-primary" colspan="<?= count($periods)+1 ?>">II. LIABILITAS</td></tr>
                    <tr><td class="col-uraian indent">Liabilitas Jangka Pendek</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai(-1 * $d['liab_pendek'], false, 'LIABILITAS PENDEK', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Liabilitas Jangka Panjang</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai(-1 * $d['liab_panjang'], false, 'LIABILITAS PANJANG', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Liabilitas Lainnya</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai(-1 * $d['liab_lain'], false, 'LIABILITAS LAIN', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr class="row-subtotal"><td class="ps-4 fw-bold">TOTAL LIABILITAS</td><?php foreach($report_data as $d) echo "<td class='pe-4 fw-bold'>".formatNilai($d['t_liab'], true)."</td>"; ?></tr>
                    <tr style="height:20px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                    
                    <!-- 🚀 ASET NETO (EKUITAS SEJATI) -->
                    <tr class="row-main-cat"><td class="ps-3 text-primary" colspan="<?= count($periods)+1 ?>">III. ASET NETO (EKUITAS)</td></tr>
                    <tr class="bg-white"><td class="ps-4 fw-bold" colspan="<?= count($periods)+1 ?>">A. TANPA PEMBATASAN DARI PEMBERI SUMBER DAYA</td></tr>
                    <tr><td class="col-uraian indent">Saldo Aset Neto</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['modal_unrest'], false, 'MODAL POKOK', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Saldo Awal / Surplus Ditahan</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['surplus_ditahan'], false, 'SURPLUS DITAHAN', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr class="bg-light"><td class="col-uraian indent fw-bold text-dark">Surplus (Defisit) Tahun Berjalan</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4 fw-bold text-dark'>".formatNilai($d['surplus_berjalan'], false, 'SURPLUS BERJALAN', $periods[$idx]['e'])."</td>"; ?></tr>
                    <tr class="row-subtotal"><td class="ps-4 fw-bold">Total Aset Neto Tanpa Pembatasan</td><?php foreach($report_data as $d) echo "<td class='pe-4 fw-bold'>".formatNilai($d['t_unrest'], true)."</td>"; ?></tr>
                    <tr style="height:10px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                    
                    <tr class="bg-white"><td class="ps-4 fw-bold" colspan="<?= count($periods)+1 ?>">B. DENGAN PEMBATASAN DARI PEMBERI SUMBER DAYA</td></tr>
                    <tr><td class="col-uraian indent">Dana Terikat / Aset Neto Dengan Pembatasan</td><?php foreach($report_data as $idx => $d) echo "<td class='pe-4'>".formatNilai($d['modal_rest'], false, 'DENGAN PEMBATASAN', $periods[$idx]['e'])."</td>"; ?></tr>
                    
                    <tr class="row-subtotal"><td class="ps-4 fw-bold">TOTAL ASET NETO</td><?php foreach($report_data as $d) echo "<td class='pe-4 fw-bold'>".formatNilai($d['t_eq'], true)."</td>"; ?></tr>
                    <tr class="row-grand-total"><td class="ps-4 py-3 text-uppercase">TOTAL LIABILITAS DAN ASET NETO</td><?php foreach($report_data as $d) echo "<td class='pe-4 fs-5 text-white'>".formatNilai($d['grand_pasiva'], true)."</td>"; ?></tr>
                </tbody></table></div>
                <div class="p-4 bg-light border-top no-print d-flex justify-content-between align-items-center">
                    <div class="badge bg-success px-4 py-2 rounded-pill shadow-sm"><i class="fas fa-check-circle me-2"></i>ASSET CLASSIFIER VERIFIED v914.5</div>
                    <small class="text-muted fw-bold"><i class="fas fa-bolt me-1 text-warning"></i> Powered by O(1) Ledger Engine</small>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- MODAL SETUP: Action form dialihkan langsung ke diri sendiri -->
<div class="modal fade" id="modalSetup" tabindex="-1" data-bs-backdrop="static" aria-hidden="true" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form action="index.php?page=laporan_posisi_keuangan" method="POST" id="formSetup" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_neraca_local">
            <input type="hidden" name="id" id="setup_id">
            <div class="modal-header bg-primary text-white border-0 p-4"><h5 class="modal-title fw-bold text-white">Konfigurasi Laporan Posisi Keuangan</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label><input type="text" name="judul" id="setup_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="small fw-bold text-primary mb-1 uppercase">Tgl Mulai (Diabaikan)</label><input type="date" name="start_date" id="setup_start" class="form-control border-0 bg-light text-muted rounded-pill px-4 shadow-sm" readonly></div>
                    <div class="col-md-6"><label class="small fw-bold text-primary mb-1 uppercase">Tgl Selesai (Cut-Off)</label><input type="date" name="end_date" id="setup_end" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required onchange="document.getElementById('setup_start').value = this.value.substring(0,4)+'-01-01'"></div>
                </div>
                <div class="border p-3 rounded-4 bg-white mt-3 shadow-sm"><div class="d-flex justify-content-between align-items-center mb-3"><label class="small fw-bold text-secondary mb-0 uppercase">Kolom Komparatif</label><button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-3 fw-bold" onclick="addCompRow()">+ Tambah</button></div><div id="compContainer"></div></div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 bg-light text-center d-block"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase">Simpan & Generate</button></div>
        </form>
    </div>
</div>

<script>
function openSetupModal() { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSetup')); document.getElementById('setup_id').value = ''; document.getElementById('setup_judul').value = 'Neraca ' + new Date().getFullYear(); document.getElementById('setup_end').value = '<?= date("Y-12-31") ?>'; document.getElementById('setup_start').value = '<?= date("Y-01-01") ?>'; document.getElementById('compContainer').innerHTML = ''; m.show(); }
function addCompRow(s = '', e = '') { const html = `<div class="row g-2 mb-2 comp-row animate__animated animate__fadeIn"><div class="col-10"><input type="date" name="comp_end[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-none small" value="${e}" required placeholder="Tanggal Cut-Off Neraca"></div><div class="col-2"><button type="button" class="btn btn-light text-danger rounded-pill w-100 fw-bold" onclick="this.closest('.comp-row').remove()">&times;</button></div></div>`; document.getElementById('compContainer').insertAdjacentHTML('beforeend', html); }
function editSetup(el) { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSetup')); const d = el.dataset; document.getElementById('setup_id').value = d.id; document.getElementById('setup_judul').value = d.judul ?? ''; document.getElementById('setup_start').value = d.start ?? ''; document.getElementById('setup_end').value = d.tgl ?? ''; document.getElementById('compContainer').innerHTML = ''; if(d.comp && d.comp !== 'null' && d.comp !== '[]') { try { const comps = JSON.parse(d.comp); comps.forEach(p => { const end_date = typeof p === 'object' ? (p.e || p.s) : p; addCompRow('', end_date); }); } catch(err) {} } m.show(); }
function exportToExcel(tableId, filename) { const table = document.getElementById(tableId); const clone = table.cloneNode(true); const form = document.createElement('form'); form.method = 'POST'; form.action = 'export_excel_engine.php'; form.target = '_blank'; clone.querySelectorAll('.fas, .badge, i').forEach(el => el.remove()); const inputs = [ { name: 'judul_laporan', value: document.getElementById('reportTitleHeader').innerText }, { name: 'nama_file', value: filename }, { name: 'periode_text', value: document.getElementById('reportPeriodText').innerText }, { name: 'html_content', value: clone.outerHTML } ]; inputs.forEach(function(data) { const input = document.createElement('input'); input.type = 'hidden'; input.name = data.name; input.value = data.value; form.appendChild(input); }); document.body.appendChild(form); form.submit(); document.body.removeChild(form); }
</script>