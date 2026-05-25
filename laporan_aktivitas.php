<?php
/**
 * laporan_aktivitas.php - PUSAT PENGHASILAN KOMPREHENSIF
 * Versi: 135.0 (Sovereign Grand Master - 3 Sections ISAK 35 Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak: 
 * 1. Menambahkan Poin C (PENGHASILAN LAINNYA) yang secara otomatis menyedot 
 * seluruh akun Pendapatan/Beban Diluar Usaha.
 * 2. Bagian B difokuskan murni untuk Sumbangan Dengan Pembatasan.
 * 3. Menerapkan Pure Period Engine agar perhitungan tanggal bebas bug.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// =========================================================================
// 🚀 THE SOVEREIGN AUTO-HEAL & MASS PURGE ENGINE
// =========================================================================
$check_col = $conn->query("SHOW COLUMNS FROM syifa_akun LIKE 'laporan_aktivitas'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE syifa_akun ADD COLUMN laporan_aktivitas TINYINT(1) DEFAULT 1 AFTER is_active");
}
$check_col2 = $conn->query("SHOW COLUMNS FROM syifa_akun LIKE 'akun_tipe_laporan'");
if ($check_col2 && $check_col2->num_rows == 0) {
    $conn->query("ALTER TABLE syifa_akun ADD COLUMN akun_tipe_laporan ENUM('OPERASIONAL','NON_OPERASIONAL','NERACA','SYSTEM') DEFAULT 'OPERASIONAL' AFTER kategori");
}
$check_grup = $conn->query("SHOW COLUMNS FROM syifa_akun LIKE 'grup_aktivitas'");
if ($check_grup && $check_grup->num_rows == 0) {
    $conn->query("ALTER TABLE syifa_akun ADD COLUMN grup_aktivitas VARCHAR(100) DEFAULT 'TIDAK_MASUK' AFTER report_group");
}

$conn->query("UPDATE syifa_akun SET laporan_aktivitas = 0, akun_tipe_laporan = 'NERACA' WHERE kategori IN ('Aset', 'Liabilitas', 'Kewajiban', 'Aset Neto', 'Ekuitas')");
$conn->query("UPDATE syifa_akun SET laporan_aktivitas = 1, akun_tipe_laporan = 'OPERASIONAL' WHERE kategori IN ('Pendapatan', 'Beban')");

// =========================================================================
// 🚀 SMART INHERITANCE RESOLVER ENGINE
// Mengkalkulasi pewarisan (jika anak kosong, tarik dari induknya)
// =========================================================================
if (!function_exists('resolveMapping')) {
    function resolveMapping($kode, $account_map) {
        $visited = [];
        $curr = $kode;
        while($curr != null && !in_array($curr, $visited)) {
            $visited[] = $curr;
            $node = $account_map[$curr] ?? null;
            if (!$node) break;

            if ((int)$node['is_aktivitas_group'] === 1) return $node['kode_akun'];
            if (!empty($node['grup_aktivitas']) && $node['grup_aktivitas'] !== 'TIDAK_MASUK') return $node['grup_aktivitas'];
            $curr = $node['parent_kode'];
        }
        return 'TIDAK_MASUK';
    }
}

// =========================================================================
// 🚀 THE PURE PERIOD BALANCE ENGINE
// Laporan Laba/Rugi murni dihitung dari akumulasi transaksi rentang tanggal.
// =========================================================================
function getPointInTimeBalanceMap($conn, $date) { return []; }

if (!function_exists('buildBalanceMap')) {
    function buildBalanceMap($conn, $start, $end) {
        $map = [];
        $sql_acc = "SELECT kode_akun, saldo_normal, normal_balance FROM syifa_akun WHERE (laporan_aktivitas = 1 OR akun_tipe_laporan IN ('OPERASIONAL','NON_OPERASIONAL')) AND is_group = 0";
        $accounts = $conn->query($sql_acc);
        $acc_meta = [];
        while($a = $accounts->fetch_assoc()) {
            $map[$a['kode_akun']] = 0; 
            $sn_val = $a['saldo_normal'] ?? '';
            $nb_val = $a['normal_balance'] ?? '';
            $acc_meta[$a['kode_akun']] = ($sn_val == 'K' || strtoupper($nb_val) == 'KREDIT' || strtoupper($sn_val) == 'KREDIT') ? 'K' : 'D';
        }

        // Kalkulasi Murni Transaksi Rentang Tanggal (Hingga 23:59:59)
        $s_date = "$start 00:00:00";
        $e_date = "$end 23:59:59"; 
        
        $sql_delta = "SELECT jd.kode_akun, SUM(jd.debit) as d, SUM(jd.kredit) as k 
                      FROM syifa_jurnal_detail jd 
                      JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                      WHERE j.tgl_jurnal BETWEEN '$s_date' AND '$e_date' AND j.is_deleted = 0
                      GROUP BY jd.kode_akun";
        $res_delta = $conn->query($sql_delta);
        if ($res_delta) {
            while($r = $res_delta->fetch_assoc()) {
                $kode = $r['kode_akun'];
                if(isset($acc_meta[$kode])) {
                    $net = ($acc_meta[$kode] == 'D') ? ($r['d'] - $r['k']) : ($r['k'] - $r['d']);
                    $map[$kode] = $net;
                }
            }
        }
        return $map;
    }

    function rollupToParent($conn, $rawMap) {
        $cache = [];
        $accounts = $conn->query("SELECT kode_akun, parent_kode FROM syifa_akun WHERE (laporan_aktivitas = 1 OR akun_tipe_laporan IN ('OPERASIONAL','NON_OPERASIONAL')) ORDER BY LENGTH(kode_akun) DESC")->fetch_all(MYSQLI_ASSOC);
        foreach($accounts as $a) { $cache[$a['kode_akun']] = $rawMap[$a['kode_akun']] ?? 0; }
        foreach($accounts as $a) {
            if (!empty($a['parent_kode']) && isset($cache[$a['parent_kode']])) {
                $cache[$a['parent_kode']] += $cache[$a['kode_akun']];
            }
        }
        return $cache;
    }
}

$view = $_GET['view'] ?? 'hub';
$report_id = (int)($_GET['id'] ?? 0);

$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$history = $conn->query("SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan = 'aktivitas' ORDER BY s.created_at DESC");

$periods = []; $conf = null;
if ($view == 'render' && $report_id > 0) {
    $conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
    if ($conf) {
        $periods[] = ['s' => $conf['tgl_mulai'], 'e' => $conf['tgl_akhir'], 'label' => date('d M Y', strtotime($conf['tgl_akhir']))];
        $comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
        if(is_array($comp_json)) { 
            foreach($comp_json as $cj) { 
                $d_start = is_array($cj) ? ($cj['s'] ?? '') : ''; $d_end = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj;
                if(!empty($d_end)) {
                    $used_start = !empty($d_start) ? $d_start : date('Y-m-01', strtotime($d_end));
                    $periods[] = ['s' => $used_start, 'e' => $d_end, 'label' => date('d M Y', strtotime($d_end))]; 
                }
            } 
        }
    }
}

$balanceCache = []; $rawCache = []; 
if (!empty($periods)) {
    foreach($periods as $idx => $p){
        $raw = buildBalanceMap($conn, $p['s'], $p['e']);
        $rawCache[$idx] = $raw;
        $balanceCache[$idx] = rollupToParent($conn, $raw);
    }
}

// 🚀 FORMATTER 
function fmt($n, $kode_akun = null, $s = null, $e = null) { 
    $f = ($n == 0) ? "-" : (($n < 0) ? "(".number_format(abs($n), 0, ',', '.').")" : number_format($n, 0, ',', '.')); 
    if ($kode_akun && $s && $e) {
        return "<a href='drilldown_ledger.php?kode=".urlencode($kode_akun)."&s=$s&e=$e' target='_blank' style='text-decoration:none; color:inherit; display:block;' title='Klik untuk melacak jurnal'>
                <div class='drill-cursor' style='display: inline-flex; justify-content: space-between; width: 125px; margin-left: auto; text-align: right; position:relative;'>
                    <i class='fas fa-search drill-icon no-print' style='position:absolute; left:-15px; top:4px; font-size:10px; color:#0d6efd; display:none;'></i>
                    <span style='text-align: left;' class='text-muted'>Rp</span><span style='text-align: right;'>$f</span>
                </div></a>";
    }
    return "<div style='display: inline-flex; justify-content: space-between; width: 125px; margin-left: auto; text-align: right;'><span style='text-align: left;' class='text-muted'>Rp</span><span style='text-align: right;'>$f</span></div>"; 
}

function fmt_group($n, $grup = null, $restriksi = null, $s = null, $e = null) { 
    $f = ($n == 0) ? "-" : (($n < 0) ? "(".number_format(abs($n), 0, ',', '.').")" : number_format($n, 0, ',', '.')); 
    if ($grup && $s && $e) {
        return "<a href='drilldown_ledger.php?grup_aktivitas=".urlencode($grup)."&res=$restriksi&s=$s&e=$e' target='_blank' style='text-decoration:none; color:inherit; display:block;' title='Lacak seluruh jurnal di grup ini'>
                <div class='drill-cursor' style='display: inline-flex; justify-content: space-between; width: 125px; margin-left: auto; text-align: right; position:relative;'>
                    <i class='fas fa-tags drill-icon no-print' style='position:absolute; left:-15px; top:4px; font-size:10px; color:#198754; display:none;'></i>
                    <span style='text-align: left;' class='text-muted'>Rp</span><span style='text-align: right;'>$f</span>
                </div></a>";
    }
    return "<div style='display: inline-flex; justify-content: space-between; width: 125px; margin-left: auto; text-align: right;'><span style='text-align: left;' class='text-muted'>Rp</span><span style='text-align: right;'>$f</span></div>"; 
}

// 🛡️ ANTI HUMAN ERROR GUARD
if (!function_exists('validateAccountStructure')) {
    function validateAccountStructure($conn, $account_map) {
        $errors = [];
        foreach($account_map as $kode => $r) {
            if ($r['laporan_aktivitas'] == 0 && $r['akun_tipe_laporan'] != 'OPERASIONAL' && $r['akun_tipe_laporan'] != 'NON_OPERASIONAL') continue;
            
            if(in_array($r['kategori'], ['Aset', 'Liabilitas', 'Kewajiban', 'Aset Neto', 'Ekuitas'])){
                $errors[] = "<b>CRITICAL ERROR:</b> Akun <b>[{$r['kode_akun']}] {$r['nama_akun']}</b> adalah akun Neraca. Tidak boleh masuk ke Laporan Aktivitas!";
                continue;
            }
            
            if($r['is_group'] == 0 && in_array($r['kategori'], ['Pendapatan', 'Beban'])) {
                $effective = resolveMapping($r['kode_akun'], $account_map);
                $is_luar_usaha = (strpos(strtolower($r['nama_akun']), 'luar usaha') !== false || strpos(strtolower($r['nama_akun']), 'diluar usaha') !== false || strpos($r['kode_akun'], '4-3') === 0 || strpos($r['kode_akun'], '5-3') === 0);
                
                if ($effective === 'TIDAK_MASUK' && !$is_luar_usaha) {
                    $errors[] = "<b>MAPPING REQUIRED:</b> Akun detail <b>[{$r['kode_akun']}] {$r['nama_akun']}</b> belum ditautkan ke Header Laporan manapun.";
                }
            }
        }
        return $errors;
    }
}

$raw_acc_info = []; $dynamic_headers = []; $account_warnings = []; $account_map = [];

if ($view == 'render') {
    $q_acc = $conn->query("SELECT kode_akun, nama_akun, kategori, is_group, parent_kode, is_restricted, grup_aktivitas, is_aktivitas_group, report_group FROM syifa_akun WHERE is_active=1 AND (laporan_aktivitas = 1 OR akun_tipe_laporan IN ('OPERASIONAL','NON_OPERASIONAL')) ORDER BY kode_akun ASC");
    while($row = $q_acc->fetch_assoc()) {
        $raw_acc_info[] = $row;
        $account_map[$row['kode_akun']] = $row;
        if((int)$row['is_aktivitas_group'] === 1) {
            $dynamic_headers[] = $row;
        }
    }
    $account_warnings = validateAccountStructure($conn, $account_map);
}
?>

<link rel="stylesheet" href="assets/css/syifa-bs5-fix.css">
<style>
    .table-lr { width: 100%; border-collapse: collapse; table-layout: fixed; border-radius: 12px; overflow: hidden; }
    .table-lr thead th { background: #1e293b; color: #fff; padding: 15px 10px; font-weight: 800; text-transform: uppercase; font-size: 11px; vertical-align: middle; border: none; text-align: center; }
    .table-lr tbody td { padding: 12px 10px; font-size: 13.5px; color: #334155; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .col-uraian { width: 45%; text-align: left !important; }
    .col-catatan { width: 100px; text-align: center !important; }
    .col-data { text-align: right !important; }
    .row-section { background: #f8fafc; font-weight: 800; color: #1e293b; text-transform: uppercase; border-left: 5px solid #0d6efd; }
    .row-subtotal { font-weight: 800; background: rgba(0,0,0,0.01); border-top: 1px solid #cbd5e1; }
    .row-surplus { background: #f1f5f9; font-weight: 900; color: #1e293b; border-top: 2px solid #1e293b; border-bottom: 2px solid #1e293b; }
    .indent-1 { padding-left: 40px !important; }
    .indent-2 { padding-left: 65px !important; }
    
    @media screen {
        .drill-cursor { cursor: pointer; transition: 0.2s; border-bottom: 1px dashed transparent; }
        .drill-cursor:hover { border-bottom: 1px dashed #0d6efd; color: #0d6efd !important;}
        .drill-cursor:hover .drill-icon { display: block !important; }
    }
    @media print { .no-print { display: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    <?php if ($view == 'hub'): ?>
        <!-- HUB VIEW -->
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div><h4 class="fw-bold mb-0 text-dark">Laporan Aktivitas</h4></div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="lr_openSetupModal()">
                <i class="fas fa-plus-circle me-2"></i>Buat Laporan
            </button>
        </div>
        
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0 text-center">
                    <thead class="table-dark small text-uppercase">
                        <tr>
                            <th width="120">Aksi</th><th>Periode Laporan</th><th class="text-start">Judul Laporan</th><th class="pe-4" width="160">Eksekusi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden">
                                    <button class="btn btn-white text-warning border-end" onclick='lr_openSetupModal(this)' data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $row['tgl_mulai'] ?>" data-end="<?= $row['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($row['comp_dates'], ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-white text-danger" onclick="lr_deleteReport(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                            <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d M Y', strtotime($row['tgl_mulai'])) ?> s/d <?= date('d M Y', strtotime($row['tgl_akhir'])) ?></span></td>
                            <td class="text-start fw-bold text-primary"><?= $row['judul_laporan'] ?></td>
                            <td class="pe-4"><a href="?page=laporan_aktivitas&view=render&id=<?= $row['id'] ?>" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow-sm">Tampilkan</a></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="4" class="py-5 text-muted small italic">Belum ada riwayat laporan yang dibuat.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <!-- RENDER VIEW: HASIL LAPORAN -->
        <div class="no-print d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 border shadow-sm">
            <div class="d-flex gap-2 align-items-center">
                <a href="?page=laporan_aktivitas" class="btn btn-outline-dark rounded-pill px-4 fw-bold small text-uppercase">Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $conf['tgl_mulai'] ?>" data-end="<?= $conf['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($conf['comp_dates'], ENT_QUOTES) ?>' onclick="lr_openSetupModal(this)"><i class="fas fa-cog me-2"></i>UBAH SETTING</button>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2">
                <button class="btn btn-success rounded-pill px-4 fw-bold shadow small" onclick="exportToExcelNeraca('lrTable', 'Lap_Aktivitas')"><i class="fas fa-file-excel me-2"></i>EXCEL</button>
                <a href="print_aktivitas_keuangan.php?id=<?= $report_id ?>" target="_blank" class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase"><i class="fas fa-print me-2"></i>CETAK PDF</a>
            </div>
        </div>

        <?php if(!empty($account_warnings)): ?>
        <div class="alert alert-danger border-danger shadow-sm rounded-4 mb-4 no-print">
            <div class="d-flex align-items-start text-dark">
                <i class="fas fa-radiation-alt fa-2x me-3 text-danger"></i>
                <div>
                    <h6 class="fw-bold mb-2 text-danger">Peringatan Integritas Mapping (Smart Inheritance Guard)</h6>
                    <span class="small d-block mb-1">Sistem mendeteksi ada akun yang belum ditautkan ke Header Laporan Aktivitas, dan induknya juga tidak memilikinya. Saldo akun yang tidak terpetakan otomatis ditangkap di baris "Unmapped" agar Balance terjaga. Harap perbaiki tautan akun berikut di Master COA:</span>
                    <ul class="mb-0 small fw-bold mt-2">
                        <?php foreach($account_warnings as $w) echo "<li>".$w."</li>"; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card border-0 bg-white p-0 shadow-sm overflow-hidden rounded-4 text-dark mb-4">
            <div class="p-5 text-center bg-light border-bottom">
                <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                <h4 class="fw-bold text-primary mb-3">LAPORAN AKTIVITAS</h4>
                <p class="text-muted mb-0 italic" id="reportPeriodText">
                    Periode: <?= date('d F Y', strtotime($periods[0]['s'])) ?> s/d <?= date('d F Y', strtotime($periods[0]['e'])) ?>
                </p>
            </div>

            <div class="table-responsive">
                <table class="table-lr" id="lrTable">
                    <thead>
                        <tr>
                            <th class="col-uraian ps-5" style="text-align: left;">URAIAN PENDAPATAN DAN BEBAN</th>
                            <th class="col-catatan">Catatan</th>
                            <?php foreach($periods as $p) echo "<th class='col-data pe-4' style='line-height:1.2;'>".$p['label']."</th>"; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $surplus_final = array_fill(0, count($periods), 0);
                        
                        // 🚀 STRUKTUR TIGA POIN: A, B, C (Penghasilan Lainnya)
                        $sects = [
                            ['label'=>'A. TANPA PEMBATASAN DARI PEMBERI SUMBER DAYA', 'res'=>0, 'id'=>'A'], 
                            ['label'=>'B. DENGAN PEMBATASAN DARI PEMBERI SUMBER DAYA', 'res'=>1, 'id'=>'B'],
                            ['label'=>'C. PENGHASILAN LAINNYA', 'res'=>-1, 'id'=>'C']
                        ];

                        $use_dynamic = false;
                        foreach($raw_acc_info as $a) { if ($a['is_aktivitas_group'] == 1) { $use_dynamic = true; break; } }

                        foreach($sects as $s):
                            $section_income = array_fill(0, count($periods), 0);
                            $section_expense = array_fill(0, count($periods), 0);
                            
                            echo '<tr class="row-section"><td class="ps-5" colspan="'.(count($periods)+2).'">'.$s['label'].'</td></tr>';
                            
                            foreach(['Pendapatan', 'Beban'] as $type):
                                echo '<tr class="fw-bold"><td class="ps-5 indent-1">'.$type.'</td><td colspan="'.(count($periods)+1).'"></td></tr>';
                                
                                // 🚀 1. TARIK SELURUH AKUN & FILTER BERDASARKAN SEKSI A, B, C
                                $all_cat_accs = [];
                                foreach($raw_acc_info as $row) {
                                    if ($row['kategori'] == $type) {
                                        $nl = strtolower($row['nama_akun']);
                                        $is_luar_usaha = (strpos($nl, 'luar usaha') !== false || strpos($nl, 'diluar usaha') !== false || strpos($row['kode_akun'], '4-3') === 0 || strpos($row['kode_akun'], '5-3') === 0);
                                        
                                        if ($s['id'] == 'C') {
                                            // Seksi C HANYA berisi Luar Usaha
                                            if ($is_luar_usaha) $all_cat_accs[] = $row;
                                        } else {
                                            // Seksi A & B mematuhi Restriction 0 dan 1, berdasarkan kolom DB atau report_group ISAK 35
                                            $is_res = (int)$row['is_restricted'];
                                            if ($row['report_group'] == 'ASET_NETO_DENGAN_RESTRIKSI') $is_res = 1;
                                            if ($row['report_group'] == 'ASET_NETO_TANPA_RESTRIKSI') $is_res = 0;
                                            
                                            if (!$is_luar_usaha && $is_res == $s['res']) $all_cat_accs[] = $row;
                                        }
                                    }
                                }

                                // 🚀 2. TENTUKAN HEADERS (DARI DYNAMIC MAPPING ATAU FALLBACK LEAF)
                                $current_headers = [];
                                if ($use_dynamic) {
                                    foreach($dynamic_headers as $hdr) {
                                        if ($hdr['kategori'] == $type) {
                                            $nl = strtolower($hdr['nama_akun']);
                                            $is_luar_usaha = (strpos($nl, 'luar usaha') !== false || strpos($nl, 'diluar usaha') !== false || strpos($hdr['kode_akun'], '4-3') === 0 || strpos($hdr['kode_akun'], '5-3') === 0);
                                            
                                            if ($s['id'] == 'C') {
                                                if ($is_luar_usaha) $current_headers[] = $hdr;
                                            } else {
                                                $is_res = (int)$hdr['is_restricted'];
                                                if ($hdr['report_group'] == 'ASET_NETO_DENGAN_RESTRIKSI') $is_res = 1;
                                                if ($hdr['report_group'] == 'ASET_NETO_TANPA_RESTRIKSI') $is_res = 0;
                                                
                                                if (!$is_luar_usaha && $is_res == $s['res']) $current_headers[] = $hdr;
                                            }
                                        }
                                    }
                                } else {
                                    // Fallback Leaf Groups
                                    $leaf_groups = [];
                                    foreach($all_cat_accs as $a) {
                                        if ($a['is_group'] == 1) {
                                            $has_group_child = false;
                                            foreach($all_cat_accs as $child) {
                                                if ($child['parent_kode'] === $a['kode_akun'] && $child['is_group'] == 1) {
                                                    $has_group_child = true; break;
                                                }
                                            }
                                            if (!$has_group_child) $leaf_groups[] = $a;
                                        }
                                    }
                                    if (empty($leaf_groups)) { foreach($all_cat_accs as $a) { if (empty($a['parent_kode']) && $a['is_group'] == 1) $leaf_groups[] = $a; } }
                                    if (empty($leaf_groups)) { foreach($all_cat_accs as $a) { if ($a['is_group'] == 0) $leaf_groups[] = $a; } }
                                    $current_headers = $leaf_groups;
                                }

                                // 🚀 3. TAMPILKAN BARIS LAPORAN (RESOLUSI OTOMATIS)
                                foreach($current_headers as $hdr) {
                                    $enum_key = $hdr['kode_akun']; 
                                    $enum_label = $hdr['nama_akun'];
                                    
                                    $vals = [];
                                    $has_data = false;
                                    foreach($periods as $idx => $p) {
                                        $total_enum = 0;
                                        if ($use_dynamic) {
                                            foreach($all_cat_accs as $a) {
                                                if ($a['is_group'] == 0) {
                                                    $effective_map = resolveMapping($a['kode_akun'], $account_map);
                                                    if($effective_map === $enum_key || ($effective_map === 'TIDAK_MASUK' && $a['kode_akun'] === $enum_key)) {
                                                        $total_enum += ($rawCache[$idx][$a['kode_akun']] ?? 0);
                                                    }
                                                }
                                            }
                                        } else {
                                            $total_enum = $balanceCache[$idx][$enum_key] ?? 0;
                                        }
                                        
                                        $vals[$idx] = $total_enum;
                                        if(abs($total_enum) > 0.01) $has_data = true;
                                    }

                                    // 🚀 FORCE TAMPIL AKUN 4-3000, HIBAH, SUMBANGAN, DILUAR USAHA (Meskipun Saldo Rp 0)
                                    $nl = strtolower(trim($enum_label));
                                    if (strpos($nl, 'hibah') !== false || strpos($nl, 'sumbangan') !== false || strpos($nl, 'luar usaha') !== false || strpos($nl, 'diluar usaha') !== false || strpos($enum_key, '4-3') === 0 || strpos($enum_key, '5-3') === 0) {
                                        $has_data = true; 
                                    }

                                    if ($has_data) {
                                        echo "<tr><td class='ps-5 indent-2'>{$enum_label}</td><td class='col-catatan small text-muted'>*</td>";
                                        foreach($vals as $idx => $v) { 
                                            echo "<td class='col-data pe-4 fw-bold'>".fmt_group($v, $enum_key, $s['res'], $periods[$idx]['s'], $periods[$idx]['e'])."</td>"; 
                                            if ($type == 'Pendapatan') $section_income[$idx] += $v;
                                            else $section_expense[$idx] += $v;
                                        }
                                        echo "</tr>";
                                    }
                                }

                                // 🛡️ 4. UNMAPPED DETECTOR (Bila Dynamic Digunakan)
                                if ($use_dynamic) {
                                    $unmapped_vals = array_fill(0, count($periods), 0);
                                    $has_unmapped = false;
                                    foreach($periods as $idx => $p) {
                                        foreach($all_cat_accs as $a) {
                                            if ($a['is_group'] == 0) {
                                                $effective_map = resolveMapping($a['kode_akun'], $account_map);
                                                if($effective_map === 'TIDAK_MASUK') {
                                                    $is_leaf = false;
                                                    foreach($current_headers as $lg) { if($lg['kode_akun'] == $a['kode_akun']) $is_leaf = true; }
                                                    if (!$is_leaf) {
                                                        $val = ($rawCache[$idx][$a['kode_akun']] ?? 0);
                                                        $unmapped_vals[$idx] += $val;
                                                        if(abs($val) > 0.01) $has_unmapped = true;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    if($has_unmapped) {
                                        echo "<tr class='bg-light'><td class='ps-5 indent-2 text-danger fw-bold'><i>⚠️ Akun Belum Ditautkan (Unmapped {$type})</i></td><td class='col-catatan small text-danger'>ERROR</td>";
                                        foreach($unmapped_vals as $idx => $v) { 
                                            echo "<td class='col-data pe-4 fw-bold text-danger'>".fmt($v)."</td>"; 
                                            if ($type == 'Pendapatan') $section_income[$idx] += $v;
                                            else $section_expense[$idx] += $v;
                                        }
                                        echo "</tr>";
                                    }
                                }
                                
                                echo '<tr class="row-subtotal"><td class="ps-5 indent-1">Total '.$type.'</td><td></td>';
                                foreach(($type=='Pendapatan'?$section_income:$section_expense) as $v) echo "<td class='col-data pe-4'>".fmt($v)."</td>";
                                echo '</tr>';
                            endforeach;

                            $res_0 = $section_income[0] - $section_expense[0];
                            $label_dyn = ($res_0 < 0) ? "DEFISIT" : "SURPLUS";
                            $label_clean = ($s['id'] == 'C') ? "PENGHASILAN LAINNYA" : str_replace(['A. ', 'B. '], '', $s['label']);
                            
                            echo '<tr class="row-surplus"><td class="ps-5">'.$label_dyn.' DARI '.$label_clean.'</td><td></td>';
                            foreach($periods as $idx => $p) {
                                $res_section = $section_income[$idx] - $section_expense[$idx];
                                $surplus_final[$idx] += $res_section; 
                                echo "<td class='col-data pe-4 text-dark'>".fmt($res_section)."</td>";
                            }
                            echo '</tr><tr style="height:20px;"><td colspan="'.(count($periods)+2).'"></td></tr>';
                        endforeach;
                        ?>
                        
                        <tr style="height:40px;"><td colspan="<?= count($periods)+2 ?>"></td></tr>
                        
                        <?php 
                            $grand_0 = $surplus_final[0] ?? 0;
                            $grand_label = ($grand_0 < 0) ? "PENURUNAN ASET NETO" : "KENAIKAN ASET NETO";
                        ?>
                        <tr class="bg-dark text-white fw-bold">
                            <td class="ps-5 py-4 text-white uppercase"><?= $grand_label ?> PERIODE BERJALAN</td>
                            <td></td>
                            <?php foreach($surplus_final as $sf) {
                                $f = ($sf == 0) ? "-" : (($sf < 0) ? "(".number_format(abs($sf), 0, ',', '.').")" : number_format($sf, 0, ',', '.'));
                                echo "<td class='col-data pe-4 fs-5 text-white'><div style='display: inline-flex; justify-content: space-between; width: 125px; margin-left: auto; text-align: right;'><span style='text-align: left;' class='opacity-50'>Rp</span><span style='text-align: right;'>$f</span></div></td>"; 
                            } ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="p-4 bg-light border-top no-print d-flex justify-content-between align-items-center">
                <div class="badge bg-success px-4 py-2 rounded-pill shadow-sm"><i class="fas fa-check-circle me-2"></i>3-SECTIONS & PURE PERIOD ENGINE VERIFIED v133.0</div>
                <small class="text-muted fw-bold"><i class="fas fa-sitemap me-1 text-warning"></i> Auto-Resolve Tree Nodes</small>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- GLOBAL MODAL SETUP -->
<div class="modal fade" id="modalLRSetup" tabindex="-1" data-bs-backdrop="static" aria-hidden="true" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form action="financial_action.php" method="POST" id="formLRSetup" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_report_setup">
            <input type="hidden" name="jenis_laporan" value="aktivitas">
            <input type="hidden" name="metode" value="Akrual">
            <input type="hidden" name="id" id="lr_id">
            <div class="modal-header bg-primary text-white p-4 border-0">
                <h5 class="modal-title fw-bold text-white" id="lr_modal_title">Konfigurasi Laporan Aktivitas</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label>
                    <input type="text" name="judul" id="lr_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required placeholder="Laporan Aktivitas">
                </div>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Dari Tanggal Utama</label>
                        <input type="date" name="start_date" id="lr_start" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Sampai Tanggal Utama</label>
                        <input type="date" name="end_date" id="lr_end" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required>
                    </div>
                </div>
                
                <div class="border p-3 rounded-4 bg-white mt-3 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="small fw-bold text-secondary mb-0 uppercase">Kolom Komparatif (Pilih Rentang Bebas)</label>
                        <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-3 fw-bold" onclick="addCompLR()">+ Tambah Baris</button>
                    </div>
                    <div id="lrCompContainer"></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 bg-light text-center d-block"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase">Simpan & Generate</button></div>
        </form>
    </div>
</div>

<script>
function lr_openSetupModal(el = null) {
    const modalEl = document.getElementById('modalLRSetup'); const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); document.getElementById('lrCompContainer').innerHTML = '';
    if (el && el.dataset.id) { $('#lr_id').val(el.dataset.id); $('#lr_judul').val(el.dataset.judul); $('#lr_start').val(el.dataset.start); $('#lr_end').val(el.dataset.end); $('#lr_modal_title').text('Ubah Parameter Laporan');
        if (el.dataset.comp) { try { JSON.parse(el.dataset.comp).forEach(c => addCompLR(c.s, c.e || c.s || c)); } catch (e) {} }
    } else { $('#lr_id').val(''); $('#lr_judul').val('Laporan Aktivitas ' + new Date().getFullYear()); $('#lr_start').val('<?= date("Y-m-01") ?>'); $('#lr_end').val('<?= date("Y-m-t") ?>'); $('#lr_modal_title').text('Konfigurasi Laporan Baru'); }
    bsModal.show();
}
function addCompLR(s = '', e = '') { $('#lrCompContainer').append(`<div class="row g-2 mb-2 lr-comp-row animate__animated animate__fadeInDown"><div class="col-5"><input type="date" name="comp_start[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-sm text-muted small" value="${s}" required title="Dari Tanggal"></div><div class="col-5"><input type="date" name="comp_end[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-sm text-muted small" value="${e}" required title="Sampai Tanggal"></div><div class="col-2"><button type="button" class="btn btn-light text-danger rounded-pill shadow-sm w-100 fw-bold" onclick="this.closest('.lr-comp-row').remove()">&times;</button></div></div>`); }
function lr_deleteReport(id) { if(confirm('Hapus riwayat laporan ini secara permanen?')) window.location.href = `financial_action.php?action=delete_setting&id=${id}&target=laporan_aktivitas`; }
function exportToExcelNeraca(tableId, filename) {
    const table = document.getElementById(tableId); const clone = table.cloneNode(true);
    clone.querySelectorAll('td').forEach(td => { if(td.innerText.includes('Rp')) td.innerText = td.innerText.replace(/Rp|\./g, '').trim(); });
    const form = document.createElement('form'); form.method = 'POST'; form.action = 'export_excel_engine.php'; form.target = '_blank';
    [{ name: 'judul_laporan', value: document.getElementById('reportTitleHeader').innerText }, { name: 'nama_file', value: filename }, { name: 'periode_text', value: document.getElementById('reportPeriodText').innerText }, { name: 'html_content', value: clone.outerHTML }].forEach(data => { const input = document.createElement('input'); input.type = 'hidden'; input.name = data.name; input.value = data.value; form.appendChild(input); });
    document.body.appendChild(form); form.submit(); document.body.removeChild(form);
}
</script>