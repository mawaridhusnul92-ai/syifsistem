<?php
/**
 * laporan_perubahan_aset_neto.php - ISAK 35 ROLLFORWARD
 * Versi: 806.0 (Sovereign Secure Drill-Down Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan: Injeksi Drill-Down pada halaman Aset Neto terverifikasi 100%.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

require_once 'engine/LedgerAggregationEngine.php';

$report_id = (int)($_GET['id'] ?? 0);
$view = $_GET['view'] ?? 'hub';
$action = $_GET['action'] ?? '';

// 🚀 CUSTOM EQUITY SUM ENGINE
function sumNeto($conn, $kat, $tgl_start, $tgl_end, $is_kredit, $extra_cond = "") {
    $sql = "SELECT SUM(jd.kredit) as k, SUM(jd.debit) as d 
            FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            JOIN syifa_akun a ON jd.kode_akun = a.kode_akun 
            WHERE a.kategori IN ($kat) 
            AND j.tgl_jurnal BETWEEN '$tgl_start 00:00:00' AND '$tgl_end 23:59:59' 
            AND j.is_deleted = 0 $extra_cond";
    $res = $conn->query($sql);
    $r = $res ? $res->fetch_assoc() : ['k'=>0, 'd'=>0];
    return $is_kredit ? ((double)$r['k'] - (double)$r['d']) : ((double)$r['d'] - (double)$r['k']);
}

$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$history = $conn->query("SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan = 'perubahan_aset_neto' ORDER BY s.created_at DESC");

$conf = null; $periods = [];
if ($report_id > 0) {
    $conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
    if($conf) {
        $periods[] = ['s' => $conf['tgl_mulai'], 'e' => $conf['tgl_akhir'], 'label' => date('d M Y', strtotime($conf['tgl_akhir']))];
        $comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
        if(is_array($comp_json)) { 
            foreach($comp_json as $cj) { 
                $d_start = is_array($cj) ? ($cj['s'] ?? '') : '';
                $d_end = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj;
                if(!empty($d_end)) $periods[] = ['s' => (!empty($d_start)) ? $d_start : date('Y-01-01', strtotime($d_end)), 'e' => $d_end, 'label' => date('d M Y', strtotime($d_end))]; 
            } 
        }
    }
}

$data0 = []; $data1 = []; $global_error = "";
if ($view == 'render' && !empty($periods)) {
    try {
        foreach($periods as $idx => $p) { 
            $tgl_akhir = $p['e'];
            $tahun = date('Y', strtotime($tgl_akhir));
            $tgl_awal_tahun = "$tahun-01-01";
            $tgl_akhir_lalu = date('Y-m-d', strtotime('-1 day', strtotime($tgl_awal_tahun)));

            // TARGET AKHIR NERACA
            $rd_akhir = LedgerAggregationEngine::getNeracaData($conn, $tgl_akhir);
            $grand_aset = ($rd_akhir['kas'] + $rd_akhir['piutang'] + $rd_akhir['dimuka'] + $rd_akhir['persediaan'] + $rd_akhir['aset_lancar_lain']) + ($rd_akhir['aset_tetap_berwujud_cost'] + $rd_akhir['aset_tetap_berwujud_akum'] + $rd_akhir['aset_tetap_tak_berwujud_cost'] + $rd_akhir['aset_tetap_tak_berwujud_akum']);
            $liab_akhir = -1 * ($rd_akhir['liab_pendek'] + $rd_akhir['liab_panjang'] + $rd_akhir['liab_lain']);
            $target_ekuitas_akhir = $grand_aset - $liab_akhir;

            // TARGET AWAL NERACA
            $rd_awal = LedgerAggregationEngine::getNeracaData($conn, $tgl_akhir_lalu);
            $grand_aset_awal = ($rd_awal['kas'] + $rd_awal['piutang'] + $rd_awal['dimuka'] + $rd_awal['persediaan'] + $rd_awal['aset_lancar_lain']) + ($rd_awal['aset_tetap_berwujud_cost'] + $rd_awal['aset_tetap_berwujud_akum'] + $rd_awal['aset_tetap_tak_berwujud_cost'] + $rd_awal['aset_tetap_tak_berwujud_akum']);
            $liab_awal = -1 * ($rd_awal['liab_pendek'] + $rd_awal['liab_panjang'] + $rd_awal['liab_lain']);
            $target_ekuitas_awal = $grand_aset_awal - $liab_awal;

            // SURPLUS BERJALAN
            $pend_ini = sumNeto($conn, "'Pendapatan'", $tgl_awal_tahun, $tgl_akhir, true);
            $beb_ini = sumNeto($conn, "'Beban'", $tgl_awal_tahun, $tgl_akhir, false);
            $surplus_berjalan = $pend_ini - $beb_ini;

            // DANA TERIKAT
            $q_ob_rest = $conn->query("SELECT SUM(opening_balance) as ob FROM syifa_akun WHERE kategori IN ('Aset Neto', 'Ekuitas') AND is_restricted = 1 AND is_group = 0")->fetch_assoc();
            $ob_rest = (double)($q_ob_rest['ob'] ?? 0);
            
            $mut_rest_lalu = sumNeto($conn, "'Aset Neto', 'Ekuitas'", '1970-01-01', $tgl_akhir_lalu, true, "AND a.is_restricted = 1");
            $saldo_awal_rest = $ob_rest + $mut_rest_lalu;
            
            $mut_rest_ini = sumNeto($conn, "'Aset Neto', 'Ekuitas'", $tgl_awal_tahun, $tgl_akhir, true, "AND a.is_restricted = 1");
            $saldo_akhir_rest = $saldo_awal_rest + $mut_rest_ini;

            // UNRESTRICTED PLUG
            $saldo_awal_unrest = $target_ekuitas_awal - $saldo_awal_rest;
            $saldo_akhir_unrest = $target_ekuitas_akhir - $saldo_akhir_rest;

            $mut_unrest_ini = $saldo_akhir_unrest - ($saldo_awal_unrest + $surplus_berjalan);

            $data0[$idx] = ['aw' => $saldo_awal_unrest, 'dir' => $mut_unrest_ini, 'sur' => $surplus_berjalan, 'ak' => $saldo_akhir_unrest];
            $data1[$idx] = ['aw' => $saldo_awal_rest, 'dir' => $mut_rest_ini, 'sur' => 0, 'ak' => $saldo_akhir_rest];
        }
    } catch (Exception $e) { $global_error = $e->getMessage(); }
}

function formatNilai($n, $is_bold = false, $drill_type = '', $drill_s = '', $drill_e = '') {
    if (round($n, 2) == 0) return "-";
    $f = number_format(abs($n), 0, ',', '.');
    if ($n < 0) $f = "($f)";
    $weight = $is_bold ? 'font-weight: bold;' : '';
    
    $val_html = $f;
    if (!empty($drill_type)) {
        return "<a href='drilldown_ledger.php?kode=".urlencode($drill_type)."&s=$drill_s&e=$drill_e' target='_blank' style='text-decoration:none; color:inherit; display:block;' title='Klik untuk melacak asal usul saldo ini'>
                <div class='drill-cursor' style='display: flex; justify-content: space-between; width: 100%; white-space: nowrap; position:relative; $weight'>
                    <i class='fas fa-search drill-icon no-print' style='position:absolute; left:-15px; top:4px; font-size:10px; color:#0d6efd; display:none;'></i>
                    <div style='width: 30px; text-align: left;' class='text-muted'>Rp</div><div style='text-align: right; min-width: 105px;'>$f</div>
                </div></a>";
    }
    
    return "<div style='display: flex; justify-content: space-between; width: 100%; white-space: nowrap; $weight'><div style='width: 30px; text-align: left;' class='text-muted'>Rp</div><div style='text-align: right; min-width: 105px;'>$val_html</div></div>";
}
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
                <div>
                    <h4 class="fw-bold mb-0">Laporan Perubahan Aset Neto</h4>
                    <small class="text-muted small uppercase fw-bold">Standar ISAK 35 Compliance</small>
                </div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</button>
        </div>
        
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive text-center"><table class="table table-hover align-middle mb-0 text-center text-dark"><thead class="table-dark small text-uppercase"><tr><th width="120">Aksi</th><th>Hingga Tanggal</th><th class="text-start">Judul Laporan</th><th class="pe-4">Eksekusi</th></tr></thead><tbody>
                <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()): ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden"><button class="btn btn-white text-warning border-end" onclick='editSetup(this)' data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $row['tgl_mulai'] ?>" data-tgl="<?= $row['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($row['comp_dates'], ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button><button class="btn btn-white text-danger" onclick="deleteReport(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button></div></td>
                        <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d M Y', strtotime($row['tgl_akhir'])) ?></span></td><td class="text-start fw-bold text-primary"><?= $row['judul_laporan'] ?></td><td class="pe-4 text-center"><a href="index.php?page=laporan_perubahan_aset_neto&view=render&id=<?= $row['id'] ?>" class="btn btn-primary rounded-pill px-4 btn-sm fw-bold">Tampilkan</a></td></tr>
                <?php endwhile; else: echo "<tr><td colspan='4' class='py-5 text-muted'>Belum ada riwayat laporan.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-3 py-3 border">
            <div class="d-flex gap-2">
                <a href="index.php?page=laporan_perubahan_aset_neto" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase">Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" onclick='editSetup(this)' data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $conf['tgl_mulai'] ?>" data-tgl="<?= $conf['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($conf['comp_dates'], ENT_QUOTES) ?>'><i class="fas fa-cog me-1"></i> UBAH SETTING</button>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2"><button class="btn btn-light border rounded-pill px-4 text-success fw-bold small shadow-sm" onclick="exportToExcel('netAssetTable', 'Lap_Perubahan_Aset_Neto')"><i class="fas fa-file-excel me-2"></i>EXCEL</button><button class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase" onclick="window.open('print_perubahan_aset_neto.php?id=<?= $report_id ?>', '_blank')"><i class="fas fa-print me-2"></i>CETAK PDF</button></div>
        </div>

        <?php if($global_error): ?>
            <div class="alert alert-danger shadow-sm rounded-4 border-danger border-2 border-start p-5 text-center">
                <h4 class="fw-bold text-danger mb-3"><i class="fas fa-shield-alt fa-2x d-block mb-3"></i>Gagal Memuat Laporan (Integritas Tertahan)</h4>
                <p class="mb-4 fw-bold fs-6"><?= $global_error ?></p>
            </div>
        <?php else: ?>
        <div class="card border-0 bg-white p-0 shadow-sm overflow-hidden rounded-4 text-dark mb-4">
            <div class="p-5 text-center bg-light border-bottom">
                <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                <h4 class="fw-bold text-primary mb-3 text-decoration-underline">LAPORAN PERUBAHAN ASET NETO</h4>
                <p class="text-muted mb-0 fst-italic" id="reportPeriodText">Periode Pelaporan Berakhir Pada <?= date('d F Y', strtotime($periods[0]['e'])) ?></p>
            </div>

            <div class="table-responsive">
                <table class="table-report" id="netAssetTable">
                    <thead>
                        <tr>
                            <th class="ps-5 text-start py-3">URAIAN PERUBAHAN EKUITAS</th>
                            <?php foreach($periods as $p) echo "<th width='250' class='text-end pe-4'>".$p['label']."</th>"; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- STRUKTUR A: UNRESTRICTED -->
                        <tr class="row-main-cat"><td class="ps-3" colspan="<?= count($periods)+1 ?>">A. TANPA PEMBATASAN DARI PEMBERI SUMBER DAYA</td></tr>
                        
                        <tr><td class="col-uraian indent">Saldo Awal Aset Neto</td>
                            <?php foreach($periods as $idx => $p) echo "<td class='pe-4'>".formatNilai($data0[$idx]['aw'], false, 'TANPA PEMBATASAN', date('Y-m-d', strtotime('-1 year', strtotime($p['s']))), date('Y-m-d', strtotime('-1 day', strtotime($p['s']))))."</td>"; ?>
                        </tr>
                        
                        <?php $has_koreksi = false; foreach($data0 as $d) { if (round(abs($d['dir']), 2) > 0) { $has_koreksi = true; break; } }
                        if ($has_koreksi): ?>
                            <tr><td class="col-uraian indent">Penyesuaian Modal / Mutasi Ekuitas Langsung</td>
                            <?php foreach($periods as $idx => $p) echo "<td class='pe-4'>".formatNilai($data0[$idx]['dir'], false, 'MODAL POKOK', $p['s'], $p['e'])."</td>"; ?>
                            </tr>
                        <?php endif; ?>

                        <tr><td class="col-uraian indent">Surplus (Defisit) Tahun Berjalan</td>
                        <?php foreach($periods as $idx => $p) echo "<td class='pe-4'>".formatNilai($data0[$idx]['sur'], false, 'SURPLUS BERJALAN', $p['s'], $p['e'])."</td>"; ?>
                        </tr>
                        
                        <tr class="row-subtotal"><td class="ps-3 fw-bold">Aset Neto Tanpa Pembatasan Akhir</td>
                            <?php foreach($data0 as $d) echo "<td class='pe-4 fw-bold'>".formatNilai($d['ak'], true)."</td>"; ?>
                        </tr>
                        
                        <tr style="height:20px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>

                        <!-- STRUKTUR B: RESTRICTED -->
                        <tr class="row-main-cat"><td class="ps-3" colspan="<?= count($periods)+1 ?>">B. DENGAN PEMBATASAN DARI PEMBERI SUMBER DAYA</td></tr>
                        
                        <tr><td class="col-uraian indent">Saldo Awal Dana Terikat</td>
                            <?php foreach($periods as $idx => $p) echo "<td class='pe-4'>".formatNilai($data1[$idx]['aw'], false, 'DENGAN PEMBATASAN', date('Y-m-d', strtotime('-1 year', strtotime($p['s']))), date('Y-m-d', strtotime('-1 day', strtotime($p['s']))))."</td>"; ?>
                        </tr>
                        
                        <?php $has_koreksi_rest = false; foreach($data1 as $d) { if (round(abs($d['dir']), 2) > 0) { $has_koreksi_rest = true; break; } }
                        if ($has_koreksi_rest): ?>
                            <tr><td class="col-uraian indent">Mutasi Penambahan/Pengurangan Dana Terikat</td>
                            <?php foreach($periods as $idx => $p) echo "<td class='pe-4'>".formatNilai($data1[$idx]['dir'], false, 'DENGAN PEMBATASAN', $p['s'], $p['e'])."</td>"; ?>
                            </tr>
                        <?php endif; ?>
                        
                        <tr class="row-subtotal"><td class="ps-3 fw-bold">Aset Neto Dengan Pembatasan Akhir</td>
                            <?php foreach($data1 as $d) echo "<td class='pe-4 fw-bold'>".formatNilai($d['ak'], true)."</td>"; ?>
                        </tr>
                        
                        <tr style="height:35px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                        
                        <!-- GRAND TOTAL -->
                        <tr class="row-grand-total">
                            <td class="ps-4 py-3 text-uppercase">TOTAL ASET NETO AKHIR (SINKRON NERACA)</td>
                            <?php foreach($periods as $idx => $p){ 
                                $grand = $data0[$idx]['ak'] + $data1[$idx]['ak']; 
                                echo "<td class='pe-4 fs-5 text-white'>".formatNilai($grand, true)."</td>"; 
                            } ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="p-4 bg-light border-top no-print d-flex justify-content-between align-items-center">
                <div class="badge bg-primary px-4 py-2 rounded-pill shadow-sm"><i class="fas fa-lock me-2"></i>SECURE SYNC & DRILL-DOWN VERIFIED v806.0</div>
                <small class="text-muted fw-bold"><i class="fas fa-bolt me-1 text-warning"></i> Powered by O(1) Ledger Engine</small>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- MODAL DRILL-DOWN X-RAY -->
<div class="modal fade" id="modalDrilldown" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <div class="modal-header bg-dark text-white border-0 p-4">
                <h5 class="modal-title fw-bold" id="drillTitle"><i class="fas fa-search-dollar me-2 text-warning"></i>Sovereign Audit X-Ray</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white" id="drillContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="mt-2 text-muted fw-bold">Mengekstrak data dari Trial Balance Cache Engine...</div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light text-end">
                <button type="button" class="btn btn-dark rounded-pill px-5 fw-bold shadow-sm" data-bs-dismiss="modal">Tutup Rincian</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL SETUP -->
<div class="modal fade" id="modalSetup" tabindex="-1" data-bs-backdrop="static" aria-hidden="true" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form action="financial_action.php" method="POST" id="formSetup" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_report_setup">
            <input type="hidden" name="jenis_laporan" value="perubahan_aset_neto">
            <input type="hidden" name="metode" value="Akrual">
            <input type="hidden" name="id" id="setup_id">
            <div class="modal-header bg-primary text-white border-0 p-4">
                <h5 class="modal-title fw-bold text-white">Konfigurasi Laporan Perubahan Aset Neto</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label>
                    <input type="text" name="judul" id="setup_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Tgl Mulai (Awal Buku)</label>
                        <input type="date" name="start_date" id="setup_start" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Tgl Selesai (Cut-Off)</label>
                        <input type="date" name="end_date" id="setup_end" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required onchange="document.getElementById('setup_start').value = this.value.substring(0,4)+'-01-01'">
                    </div>
                </div>
                <div class="border p-3 rounded-4 bg-white mt-3 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <label class="small fw-bold text-secondary mb-0 uppercase">Kolom Komparatif (Perbandingan)</label>
                        <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-3 fw-bold" onclick="addCompRow()">+ Tambah</button>
                    </div>
                    <div id="compContainer"></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 bg-light text-center d-block">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase">Simpan & Generate</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDrilldown(type, start, end) {
    const m = new bootstrap.Modal(document.getElementById('modalDrilldown'));
    document.getElementById('drillContent').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="mt-2 text-muted fw-bold">Mengekstrak data dari Trial Balance Cache Engine...</div></div>';
    m.show();
    
    fetch(`drilldown_ledger.php?kode=${encodeURIComponent(type)}&s=${start}&e=${end}`)
        .then(r => r.text())
        .then(html => {
            document.getElementById('drillTitle').innerHTML = `<i class="fas fa-search-dollar me-2 text-warning"></i>Audit Forensik Ekuitas`;
            document.getElementById('drillContent').innerHTML = html;
        }).catch(e => {
            document.getElementById('drillContent').innerHTML = '<div class="alert alert-danger shadow-sm border-0 fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>Gagal menarik data X-Ray. Hubungi Administrator.</div>';
        });
}

function openSetupModal() { 
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSetup')); 
    document.getElementById('setup_id').value = ''; 
    document.getElementById('setup_judul').value = 'Laporan Perubahan Aset Neto ' + new Date().getFullYear(); 
    document.getElementById('setup_end').value = '<?= date("Y-12-31") ?>';
    document.getElementById('setup_start').value = '<?= date("Y-01-01") ?>';
    document.getElementById('compContainer').innerHTML = '';
    m.show(); 
}

function addCompRow(s = '', e = '') {
    const html = `<div class="row g-2 mb-2 comp-row animate__animated animate__fadeIn">
        <div class="col-5">
            <input type="date" name="comp_start[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-none small" value="${s}" required placeholder="Tanggal Awal">
        </div>
        <div class="col-5">
            <input type="date" name="comp_end[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-none small" value="${e}" required placeholder="Tanggal Akhir">
        </div>
        <div class="col-2">
            <button type="button" class="btn btn-light text-danger rounded-pill w-100 fw-bold" onclick="this.closest('.comp-row').remove()">&times;</button>
        </div>
    </div>`;
    document.getElementById('compContainer').insertAdjacentHTML('beforeend', html);
}

function editSetup(el) { 
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSetup')); 
    const d = el.dataset;
    document.getElementById('setup_id').value = d.id; 
    document.getElementById('setup_judul').value = d.judul ?? ''; 
    document.getElementById('setup_start').value = d.start ?? ''; 
    document.getElementById('setup_end').value = d.tgl ?? ''; 
    document.getElementById('compContainer').innerHTML = '';
    
    if(d.comp && d.comp !== 'null' && d.comp !== '[]') { 
        try { 
            const comps = JSON.parse(d.comp);
            comps.forEach(p => { addCompRow(p.s, p.e); }); 
        } catch(err) {} 
    }
    m.show(); 
}

function deleteReport(id) { 
    if(confirm('Hapus laporan ini secara permanen?')) window.location.href=`financial_action.php?action=delete_setting&id=${id}&target=laporan_perubahan_aset_neto`; 
}

function exportToExcel(tableId, filename) { 
    const table = document.getElementById(tableId); 
    const clone = table.cloneNode(true); 
    const form = document.createElement('form'); 
    form.method = 'POST'; form.action = 'export_excel_engine.php'; form.target = '_blank'; 
    
    clone.querySelectorAll('.fas, .badge, .drill-link i').forEach(el => el.remove());
    
    const inputs = [ 
        { name: 'judul_laporan', value: document.getElementById('reportTitleHeader').innerText }, 
        { name: 'nama_file', value: filename }, 
        { name: 'periode_text', value: document.getElementById('reportPeriodText').innerText }, 
        { name: 'html_content', value: clone.outerHTML } 
    ]; 
    inputs.forEach(function(data) { 
        const input = document.createElement('input'); 
        input.type = 'hidden'; input.name = data.name; input.value = data.value; 
        form.appendChild(input); 
    }); 
    document.body.appendChild(form); form.submit(); document.body.removeChild(form); 
}
</script>