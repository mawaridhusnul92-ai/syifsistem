<?php
/**
 * laporan_kas_detail.php - PUSAT LAPORAN ARUS KAS (METODE TIDAK LANGSUNG - ISAK 35)
 * Versi: 47.5 (UI/UX Refined - Sovereign Drill-Down Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan: Injeksi penuh fitur Forensik Drill-Down dengan link khusus pada 
 * setiap baris nilai Arus Kas.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// =========================================================================
// 🚀 FORENSIC AJAX ENGINE: MENANGKAP TRANSAKSI PENYEBAB SUSPENSE
// =========================================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] == 'get_suspense') {
    ob_clean();
    header('Content-Type: application/json');
    $s = $conn->real_escape_string($_GET['s']);
    $e = $conn->real_escape_string($_GET['e']);
    
    $sql = "
        SELECT j.no_jurnal, j.tgl_jurnal, j.keterangan, jd.kode_akun, a.nama_akun, a.kategori, jd.debit, jd.kredit,
        'Mutasi Kas ke Ekuitas Langsung (Bypass Laba/Rugi)' as suspect_reason
        FROM syifa_jurnal_detail jd
        JOIN syifa_jurnal j ON jd.jurnal_id = j.id
        LEFT JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
        WHERE j.tgl_jurnal BETWEEN '$s' AND '$e'
        AND a.kategori = 'Aset Neto'
        AND j.id IN (SELECT jd2.jurnal_id FROM syifa_jurnal_detail jd2 JOIN syifa_akun a2 ON jd2.kode_akun=a2.kode_akun WHERE a2.is_cash_account=1 OR a2.kategori IN('Kas','Bank') OR a2.kode_akun LIKE '1-11%')
        
        UNION ALL
        
        SELECT j.no_jurnal, j.tgl_jurnal, j.keterangan, jd.kode_akun, IFNULL(a.nama_akun, 'TIDAK TERDAFTAR'), IFNULL(a.kategori, 'KOSONG'), jd.debit, jd.kredit,
        'Kategori Akun Tidak Dikenali / Akun Terhapus' as suspect_reason
        FROM syifa_jurnal_detail jd
        JOIN syifa_jurnal j ON jd.jurnal_id = j.id
        LEFT JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
        WHERE j.tgl_jurnal BETWEEN '$s' AND '$e'
        AND (a.kategori IS NULL OR a.kategori NOT IN ('Aset','Liabilitas','Pendapatan','Beban','Aset Neto', 'Liabilitas Jangka Panjang'))
        AND j.id IN (SELECT jd2.jurnal_id FROM syifa_jurnal_detail jd2 JOIN syifa_akun a2 ON jd2.kode_akun=a2.kode_akun WHERE a2.is_cash_account=1 OR a2.kategori IN('Kas','Bank') OR a2.kode_akun LIKE '1-11%')
        
        UNION ALL
        
        SELECT j.no_jurnal, j.tgl_jurnal, j.keterangan, '-' as kode_akun, 'JURNAL RUSAK' as nama_akun, 'ERROR' as kategori, SUM(jd.debit) as debit, SUM(jd.kredit) as kredit,
        'Jurnal Tidak Balance (Debit != Kredit)' as suspect_reason
        FROM syifa_jurnal_detail jd
        JOIN syifa_jurnal j ON jd.jurnal_id = j.id
        WHERE j.tgl_jurnal BETWEEN '$s' AND '$e'
        GROUP BY j.id, j.no_jurnal, j.tgl_jurnal, j.keterangan
        HAVING ROUND(SUM(jd.debit), 2) != ROUND(SUM(jd.kredit), 2)
    ";
    
    $res = $conn->query($sql);
    $data = [];
    if ($res) {
        while($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode($data);
    exit;
}

// =========================================================================
// 🚀 AUTO-HEAL: COA STRUCTURAL UPGRADE
// =========================================================================
$check_col = $conn->query("SHOW COLUMNS FROM syifa_akun LIKE 'is_cash_account'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE syifa_akun ADD COLUMN is_cash_account TINYINT(1) DEFAULT 0 AFTER is_active");
    $conn->query("UPDATE syifa_akun SET is_cash_account = 1 WHERE kategori IN ('Kas', 'Bank') OR kode_akun LIKE '1-11%' OR kode_akun LIKE '111%' OR kode_akun LIKE '1.11%'");
}

$view = $_GET['view'] ?? 'hub';
$report_id = (int)($_GET['id'] ?? 0);

$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$history = $conn->query("SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan = 'kas_detail' ORDER BY s.created_at DESC");

$periods = [];
$conf = null;
if ($report_id > 0) {
    $res_conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id");
    if ($res_conf && $res_conf->num_rows > 0) {
        $conf = $res_conf->fetch_assoc();
        $periods[] = [
            's' => $conf['tgl_mulai'], 
            'e' => $conf['tgl_akhir'], 
            'label' => date('d M Y', strtotime($conf['tgl_akhir']))
        ];
        
        $comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
        if(is_array($comp_json)) { 
            foreach($comp_json as $cj) { 
                $d_start = is_array($cj) ? ($cj['s'] ?? '') : '';
                $d_end = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj;
                if(!empty($d_end)) {
                    $periods[] = [
                        's' => (!empty($d_start)) ? $d_start : date('Y-01-01', strtotime($d_end)), 
                        'e' => $d_end, 
                        'label' => date('d M Y', strtotime($d_end))
                    ]; 
                }
            } 
        }
    }
}

// =========================================================================
// 🚀 SOVEREIGN CALCULATION ENGINE (PURE ISAK 35)
// =========================================================================

$CASH_COND = "(a.is_cash_account = 1 OR a.kategori IN ('Kas', 'Bank') OR a.kode_akun LIKE '1-11%' OR a.kode_akun LIKE '1.11%')";
$CASH_COND_AK = "(ak.is_cash_account = 1 OR ak.kategori IN ('Kas', 'Bank') OR ak.kode_akun LIKE '1-11%' OR ak.kode_akun LIKE '1.11%')";

function getNetSurplusUnrestricted($s, $e, $conn) {
    $sql = "SELECT SUM(jd.kredit - jd.debit) as net FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun 
            WHERE a.kategori IN ('Pendapatan','Beban') 
            AND j.tgl_jurnal BETWEEN '$s' AND '$e'";
    return (double)($conn->query($sql)->fetch_assoc()['net'] ?? 0);
}

function getDepreciationFromModule($is_berwujud, $s, $e, $conn) {
    $cat_filter = $is_berwujud ? "ac.category_name NOT LIKE '%Tidak Berwujud%'" : "ac.category_name LIKE '%Tidak Berwujud%'";
    $sql = "SELECT SUM(ad.nilai_susut) as total 
            FROM asset_depreciation ad 
            JOIN syifa_jurnal j ON ad.jurnal_id = j.id
            JOIN assets a ON ad.asset_id = a.id 
            JOIN asset_categories ac ON a.category_id = ac.id 
            WHERE $cat_filter 
            AND j.tgl_jurnal BETWEEN '$s' AND '$e'";
    $res = $conn->query($sql);
    if (!$res) return 0;
    return (double)($res->fetch_assoc()['total'] ?? 0);
}

function getDeltaWCPure($kode_prefix, $s, $e, $conn) {
    $sql = "SELECT SUM(jd.kredit - jd.debit) as net_cash_effect
            FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
            WHERE (a.kode_akun LIKE '$kode_prefix%' OR a.parent_kode = '$kode_prefix')
              AND j.tgl_jurnal BETWEEN '$s' AND '$e'
              AND NOT EXISTS (
                  SELECT 1 FROM syifa_jurnal_detail eq 
                  JOIN syifa_akun a_eq ON eq.kode_akun = a_eq.kode_akun
                  WHERE eq.jurnal_id = j.id AND a_eq.kategori IN ('Aset Neto', 'Aset Tetap', 'Aset Tidak Berwujud')
              )";
    return (double)($conn->query($sql)->fetch_assoc()['net_cash_effect'] ?? 0);
}

function getDeltaWCOthers($type, $s, $e, $conn) {
    global $CASH_COND;
    if ($type == 'Aset') {
        $filter = "(a.kode_akun LIKE '1-1%' AND a.kode_akun NOT LIKE '1-11%' AND a.kode_akun NOT LIKE '1-12%' AND a.kode_akun NOT LIKE '1-13%')";
    } else {
        $filter = "(a.kode_akun LIKE '2-%' AND a.kode_akun NOT LIKE '2-11%' AND a.kode_akun NOT LIKE '2-2%')";
    }
    
    $sql = "SELECT SUM(jd.kredit - jd.debit) as net_cash_effect
            FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
            WHERE $filter
              AND NOT $CASH_COND
              AND j.tgl_jurnal BETWEEN '$s' AND '$e'
              AND NOT EXISTS (
                  SELECT 1 FROM syifa_jurnal_detail eq 
                  JOIN syifa_akun a_eq ON eq.kode_akun = a_eq.kode_akun
                  WHERE eq.jurnal_id = j.id AND a_eq.kategori IN ('Aset Neto', 'Aset Tetap', 'Aset Tidak Berwujud')
              )";
    return (double)($conn->query($sql)->fetch_assoc()['net_cash_effect'] ?? 0);
}

function getAssetAcquisitionPureCash($kode_prefix, $s, $e, $conn) {
    global $CASH_COND_AK;
    $sql = "SELECT SUM(jd.debit - jd.kredit) as total 
            FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
            WHERE (a.kode_akun LIKE '$kode_prefix%' OR a.parent_kode = '$kode_prefix')
              AND j.tgl_jurnal BETWEEN '$s' AND '$e'
              AND EXISTS (
                  SELECT 1 FROM syifa_jurnal_detail k 
                  JOIN syifa_akun ak ON k.kode_akun = ak.kode_akun
                  WHERE k.jurnal_id = j.id AND $CASH_COND_AK AND k.kredit > 0
              )";
    return -1 * (double)($conn->query($sql)->fetch_assoc()['total'] ?? 0);
}

function getFinancingFlowPureCash($s, $e, $conn) {
    global $CASH_COND_AK;
    $sql = "SELECT SUM(jd.kredit - jd.debit) as net_cash_effect 
            FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
            WHERE a.kategori IN ('Aset Neto', 'Liabilitas Jangka Panjang') AND a.is_restricted = 1
              AND j.tgl_jurnal BETWEEN '$s' AND '$e'
              AND EXISTS (
                  SELECT 1 FROM syifa_jurnal_detail k 
                  JOIN syifa_akun ak ON k.kode_akun = ak.kode_akun
                  WHERE k.jurnal_id = j.id AND $CASH_COND_AK
              )";
    return (double)($conn->query($sql)->fetch_assoc()['net_cash_effect'] ?? 0);
}

function getCashBalanceLTD($date, $conn) {
    global $CASH_COND;
    $q_init = $conn->query("SELECT SUM(opening_balance) as init FROM syifa_akun a WHERE $CASH_COND AND is_group=0")->fetch_assoc();
    $q_mut = $conn->query("SELECT SUM(jd.debit - jd.kredit) as mut FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun WHERE $CASH_COND AND j.tgl_jurnal <= '$date'")->fetch_assoc();
    return (double)($q_init['init'] ?? 0) + (double)($q_mut['mut'] ?? 0);
}

// 🚀 OMNI-FORMATTER DENGAN DRILL DOWN INJECTION
function fmtAudFlow($n, $isBold = false, $kode_akun = null, $s = null, $e = null) {
    if (round($n, 2) == 0) $f = "-";
    else {
        $f = number_format(abs($n), 0, ',', '.');
        if ($n < 0) $f = "($f)";
    }
    
    $weight = $isBold ? "900" : "normal";
    
    if ($kode_akun && $s && $e && abs($n) > 0.01) {
        return "<a href='drilldown_ledger.php?kode=".urlencode($kode_akun)."&s=$s&e=$e' target='_blank' class='text-decoration-none text-dark' title='Klik untuk Melacak Jurnal'>
                <div class='drill-cursor' style='display: flex; justify-content: flex-end; width: 100%; font-weight: $weight; white-space: nowrap; position: relative;'>
                    <i class='fas fa-search drill-icon no-print' style='position:absolute; left:-15px; top:4px; font-size:10px; color:#0d6efd; display:none;'></i>
                    <div style='width: 30px; text-align: left;' class='text-muted'>Rp</div><div style='text-align: right; min-width: 105px;'>$f</div>
                </div></a>";
    }
    
    return "<div style='display: flex; justify-content: flex-end; width: 100%; font-weight: $weight; white-space: nowrap;'><div style='width: 30px; text-align: left;' class='text-muted'>Rp</div><div style='text-align: right; min-width: 105px;'>$f</div></div>";
}

function fmtAudFlowLink($n, $s, $e) {
    if ($n == 0) return "-";
    $f = number_format(abs($n), 0, ',', '.');
    if ($n < 0) $f = "($f)";
    return "<a href='javascript:void(0)' onclick=\"drillDownSuspense('$s', '$e')\" class='text-danger text-decoration-underline' style='display: flex; justify-content: flex-end; width: 100%; font-weight: 900; white-space: nowrap;' title='Klik untuk Melacak Jurnal Pelaku'><div style='width: 30px; text-align: left;'>Rp</div><div style='text-align: right; min-width: 105px;'>$f</div></a>";
}
?>

<link rel="stylesheet" href="assets/css/syifa-bs5-fix.css">
<style>
    .table-cash { border: none; border-collapse: collapse; width: 100%; table-layout: fixed; margin-bottom: 0; }
    .table-cash thead th { background: #1e293b; color: #fff; padding: 15px 10px; font-weight: 800; text-transform: uppercase; font-size: 11px; vertical-align: middle; border: none; }
    .table-cash tbody td { padding: 12px 10px; font-size: 13.5px; color: #334155; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .col-uraian { width: 45%; text-align: left !important; padding-left: 25px !important; }
    .row-main-cat { background: #f8fafc; font-weight: 800; color: #1e293b; border-bottom: 1px solid #cbd5e1 !important; text-transform: uppercase; border-left: 5px solid #0d6efd; }
    .row-subtotal { font-weight: 800; border-top: 1.5px solid #1e293b; background: rgba(13, 110, 253, 0.03); }
    .row-grand-total { background: #1e293b !important; color: #fff !important; font-weight: 900; }
    .indent { padding-left: 50px !important; }
    .indent-2 { padding-left: 80px !important; }
    
    @media screen {
        .drill-cursor { cursor: pointer; transition: 0.2s; border-bottom: 1px dashed transparent; }
        .drill-cursor:hover { border-bottom: 1px dashed #0d6efd; color: #0d6efd !important;}
        .drill-cursor:hover .drill-icon { display: block !important; }
    }
    @media print { .no-print { display: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">

    <?php if ($view == 'hub'): ?>
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0 text-dark">Laporan Arus Kas</h4>
                    <small class="text-muted small text-uppercase fw-bold">Audit Rekonsiliasi Kas (Standar ISAK 35)</small>
                </div>
            </div>
            <button type="button" class="btn btn-success rounded-pill px-4 fw-bold shadow text-uppercase" onclick="openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</button>
        </div>
        
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0 text-center"><thead class="table-dark small text-uppercase"><tr><th>Aksi</th><th>Hingga Tanggal</th><th class="text-start">Judul Laporan</th><th class="pe-4">Eksekusi</th></tr></thead><tbody>
                <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()) { ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden">
                                <button class="btn btn-white text-warning border-end" 
                                        data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" 
                                        data-start="<?= $row['tgl_mulai'] ?>" data-end="<?= $row['tgl_akhir'] ?>" 
                                        data-comp='<?= htmlspecialchars($row['comp_dates'], ENT_QUOTES) ?>'
                                        onclick='editSetup(this)' title="Ubah"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-white text-danger" onclick="handleDelete(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button>
                            </div></td>
                        <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d M Y', strtotime($row['tgl_akhir'])) ?></span></td>
                        <td class="text-start fw-bold text-primary"><?= $row['judul_laporan'] ?></td>
                        <td class="pe-4 text-center"><a href="index.php?page=laporan_kas_detail&view=render&id=<?= $row['id'] ?>" class="btn btn-primary rounded-pill px-4 btn-sm fw-bold shadow-sm">Tampilkan</a></td></tr>
                <?php } else: echo "<tr><td colspan='4' class='py-5 text-muted'>Belum ada riwayat laporan.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-3 py-3 border text-dark">
            <div class="d-flex gap-2 align-items-center">
                <a href="index.php?page=laporan_kas_detail" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase">Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" 
                        onclick='editSetup(this)' 
                        data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" 
                        data-start="<?= $conf['tgl_mulai'] ?>" data-end="<?= $conf['tgl_akhir'] ?>" 
                        data-comp='<?= htmlspecialchars($conf['comp_dates'], ENT_QUOTES) ?>'><i class="fas fa-cog me-1"></i> UBAH SETTING</button>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2">
                <button class="btn btn-light border rounded-pill px-4 text-success fw-bold small shadow-sm" onclick="exportToExcelNeraca('cashFlowTable', 'Lap_Arus_Kas')"><i class="fas fa-file-excel me-2"></i>EXCEL</button>
                <button class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase" onclick="window.open('print_arus_kas.php?id=<?= $report_id ?>', '_blank')"><i class="fas fa-print me-2"></i>CETAK PDF</button>
            </div>
        </div>

        <div class="card border-0 bg-white p-0 shadow-sm overflow-hidden rounded-4 text-dark mb-4">
            <div class="p-5 text-center bg-light border-bottom">
                <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                <h4 class="fw-bold text-primary mb-3 text-decoration-underline">LAPORAN ARUS KAS</h4>
                <p class="text-muted mb-0 italic" id="reportPeriodText">Periode yang berakhir pada Tanggal <?= date('d F Y', strtotime($periods[0]['e'])) ?></p>
            </div>

            <div class="table-responsive"><table class="table-cash" id="cashFlowTable">
                <thead><tr><th class="ps-5">URAIAN ARUS KAS</th><?php foreach($periods as $p) echo "<th class='text-end pe-4'>".$p['label']."</th>"; ?></tr></thead>
                <tbody>
                    <?php 
                    $cash_op = []; $cash_inv = []; $cash_fin = []; $cash_unmapped = [];
                    ?>
                    <tr class="row-main-cat"><td class="ps-3" colspan="<?= count($periods)+1 ?>">I. ARUS KAS DARI AKTIVITAS OPERASI</td></tr>
                    
                    <tr><td class="col-uraian">Kenaikan (Penurunan) Aset Bersih Terkumpul berjalan</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getNetSurplusUnrestricted($p['s'], $p['e'], $conn), false, 'SURPLUS BERJALAN', $p['s'], $p['e'])."</td>"; ?></tr>
                    
                    <tr><td class="col-uraian indent">Penyusutan Aset Tetap (+)</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDepreciationFromModule(true, $p['s'], $p['e'], $conn), false, 'PENYUSUTAN', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Amortisasi Aset Tak Berwujud (+)</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDepreciationFromModule(false, $p['s'], $p['e'], $conn), false, 'AMORTISASI', $p['s'], $p['e'])."</td>"; ?></tr>
                    
                    <tr class="fw-bold"><td class="col-uraian indent">Perubahan Modal Kerja (Selisih Saldo Kas):</td><td colspan="<?= count($periods) ?>"></td></tr>
                    
                    <tr><td class="col-uraian indent-2">Piutang Mahasiswa & Usaha</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDeltaWCPure('1-12', $p['s'], $p['e'], $conn), false, '1-12', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent-2">Biaya Dibayar Dimuka</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDeltaWCPure('1-13', $p['s'], $p['e'], $conn), false, '1-13', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent-2">Aset Lancar Lainnya</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDeltaWCOthers('Aset', $p['s'], $p['e'], $conn), false, 'ASET LANCAR LAINNYA', $p['s'], $p['e'])."</td>"; ?></tr>
                    
                    <tr><td class="col-uraian indent-2">Utang Usaha (Termasuk Utang BPJS)</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDeltaWCPure('2-11', $p['s'], $p['e'], $conn), false, '2-11', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent-2">Utang Pajak</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDeltaWCPure('2-2', $p['s'], $p['e'], $conn), false, '2-2', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent-2">Kewajiban Jangka Pendek Lainnya</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getDeltaWCOthers('Liabilitas', $p['s'], $p['e'], $conn), false, 'LIABILITAS LAIN', $p['s'], $p['e'])."</td>"; ?></tr>
                    
                    <tr class="row-subtotal"><td class="ps-3 fw-bold">Arus Kas Bersih dari Aktivitas Operasi</td>
                        <?php foreach($periods as $idx => $p){ 
                            $v = getNetSurplusUnrestricted($p['s'], $p['e'], $conn) + getDepreciationFromModule(true, $p['s'], $p['e'], $conn) + getDepreciationFromModule(false, $p['s'], $p['e'], $conn)
                                 + getDeltaWCPure('1-12', $p['s'], $p['e'], $conn) + getDeltaWCPure('1-13', $p['s'], $p['e'], $conn) + getDeltaWCOthers('Aset', $p['s'], $p['e'], $conn)
                                 + getDeltaWCPure('2-11', $p['s'], $p['e'], $conn) + getDeltaWCPure('2-2', $p['s'], $p['e'], $conn) + getDeltaWCOthers('Liabilitas', $p['s'], $p['e'], $conn);
                            $cash_op[$idx] = $v;
                            echo "<td class='text-end pe-4'>".fmtAudFlow($v, true)."</td>"; 
                        } ?>
                    </tr>

                    <tr class="row-main-cat"><td class="ps-3" colspan="<?= count($periods)+1 ?>">II. ARUS KAS DARI AKTIVITAS INVESTASI</td></tr>
                    <tr><td class="col-uraian indent">Perolehan Aset Tetap Berwujud</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getAssetAcquisitionPureCash('1-21', $p['s'], $p['e'], $conn), false, '1-21', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr><td class="col-uraian indent">Perolehan Aset Tidak Berwujud</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudFlow(getAssetAcquisitionPureCash('1-22', $p['s'], $p['e'], $conn), false, '1-22', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr class="row-subtotal"><td class="ps-3 fw-bold">Arus Kas Bersih dari Aktivitas Investasi</td>
                        <?php foreach($periods as $idx => $p){ 
                            $v = getAssetAcquisitionPureCash('1-21', $p['s'], $p['e'], $conn) + getAssetAcquisitionPureCash('1-22', $p['s'], $p['e'], $conn);
                            $cash_inv[$idx] = $v;
                            echo "<td class='text-end pe-4'>".fmtAudFlow($v, true)."</td>"; 
                        } ?>
                    </tr>

                    <tr class="row-main-cat"><td class="ps-3" colspan="<?= count($periods)+1 ?>">III. ARUS KAS DARI AKTIVITAS PENDANAAN</td></tr>
                    <tr><td class="col-uraian indent">Penerimaan Pendanaan Aset Neto Terikat</td><?php foreach($periods as $idx => $p) echo "<td>".fmtAudFlow(getFinancingFlowPureCash($p['s'], $p['e'], $conn), false, 'DENGAN PEMBATASAN', $p['s'], $p['e'])."</td>"; ?></tr>
                    <tr class="row-subtotal"><td class="ps-3 fw-bold">Arus Kas Bersih dari Aktivitas Pendanaan</td>
                        <?php foreach($periods as $idx => $p){ 
                            $v = getFinancingFlowPureCash($p['s'], $p['e'], $conn);
                            $cash_fin[$idx] = $v;
                            echo "<td class='text-end pe-4'>".fmtAudFlow($v, true)."</td>"; 
                        } ?>
                    </tr>

                    <tr style="height:35px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                    <tr class="fw-bold"><td class="ps-3 uppercase">KENAIKAN (PENURUNAN) BERSIH KAS (KALKULASI)</td>
                        <?php foreach($periods as $idx => $p){ 
                            $calc_delta = $cash_op[$idx] + $cash_inv[$idx] + $cash_fin[$idx];
                            echo "<td class='text-end pe-4'>".fmtAudFlow($calc_delta)."</td>"; 
                        } ?>
                    </tr>
                    
                    <?php
                    // 🛡️ THE ULTIMATE AUDIT SUSPENSE
                    $has_unmapped = false;
                    foreach($periods as $idx => $p) {
                        $actual_start = getCashBalanceLTD(date('Y-m-d', strtotime($p['s'] . ' -1 day')), $conn);
                        $actual_end = getCashBalanceLTD($p['e'], $conn);
                        $actual_delta = $actual_end - $actual_start;
                        $calc_delta = $cash_op[$idx] + $cash_inv[$idx] + $cash_fin[$idx];
                        $cash_unmapped[$idx] = $actual_delta - $calc_delta;
                        if (abs($cash_unmapped[$idx]) > 0.01) $has_unmapped = true;
                    }
                    if ($has_unmapped): ?>
                        <tr><td class="col-uraian small italic text-danger fw-bold"><i class="fas fa-search-dollar me-2"></i>Penyesuaian Selisih Kas (Klik Angka untuk Forensic Audit)</td>
                        <?php foreach($periods as $idx => $p) {
                            echo "<td class='pe-4 text-danger'>".fmtAudFlowLink($cash_unmapped[$idx], $p['s'], $p['e'])."</td>"; 
                        } ?>
                        </tr>
                    <?php endif; ?>

                    <tr class="fw-bold"><td class="ps-3 uppercase mt-3 pt-3 border-top border-dark">KAS PADA AWAL PERIODE</td><?php foreach($periods as $p) echo "<td class='text-end pe-4 pt-3 border-top border-dark'>".fmtAudFlow(getCashBalanceLTD(date('Y-m-d', strtotime($p['s'] . ' -1 day')), $conn))."</td>"; ?></tr>
                    
                    <tr class="row-grand-total">
                        <td class="ps-4 py-3 text-white">KAS PADA AKHIR PERIODE (SINKRON NERACA BUKU BESAR)</td>
                        <?php foreach($periods as $p) echo "<td class='text-end pe-4 fs-5 text-white'>Rp ".number_format(getCashBalanceLTD($p['e'], $conn), 0, ',', '.')."</td>"; ?>
                    </tr>
                </tbody>
            </table></div>
            
            <div class="p-4 bg-light border-top no-print d-flex justify-content-between">
                <div class="badge bg-success px-4 py-2 rounded-pill shadow-sm"><i class="fas fa-check-circle me-2"></i>PURE INDIRECT METHOD VERIFIED v47.5</div>
                <small class="text-muted fw-bold">Sovereign Financial Hub</small>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- 🚀 MODAL FORENSIC AUDIT (X-RAY VIEW) -->
<div class="modal fade" id="modalSuspense" tabindex="-1" style="z-index: 9999;">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-danger text-white border-0 p-4">
                <div>
                    <h5 class="modal-title fw-bold"><i class="fas fa-search-dollar me-2"></i>Forensic Audit: Unmapped Transactions</h5>
                    <small class="text-white-50">Menelusuri Jurnal Anomali (Penyebab Selisih Arus Kas)</small>
                </div>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 bg-light">
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-dark mb-0" style="font-size: 0.85rem;">
                        <thead class="table-dark small text-center text-uppercase">
                            <tr>
                                <th width="100">Tanggal</th>
                                <th width="140">No Bukti</th>
                                <th class="text-start ps-3">Uraian Transaksi & Akun Pelaku</th>
                                <th width="120" class="text-end">Debit (Kas)</th>
                                <th width="120" class="text-end pe-3">Kredit (Kas)</th>
                                <th width="250" class="text-center">Alasan Suspense</th>
                            </tr>
                        </thead>
                        <tbody id="suspenseBody">
                            <!-- Hasil Injeksi AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer p-3 bg-white border-top border-danger border-2">
                <button type="button" class="btn btn-outline-danger rounded-pill px-5 fw-bold shadow-sm" data-bs-dismiss="modal">Tutup Audit</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL SETUP -->
<div class="modal fade" id="modalSetup" tabindex="-1" data-bs-backdrop="static" aria-hidden="true" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form action="financial_action.php" method="POST" id="formSetup" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_report_setup">
            <input type="hidden" name="jenis_laporan" value="kas_detail">
            <input type="hidden" name="metode" value="Akrual">
            <input type="hidden" name="id" id="setup_id">
            <div class="modal-header bg-primary text-white border-0 p-4">
                <h5 class="modal-title fw-bold text-white">Konfigurasi Laporan Arus Kas</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label>
                    <input type="text" name="judul" id="setup_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required placeholder="Contoh: Laporan Arus Kas 2026">
                </div>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Dari Tanggal Utama</label>
                        <input type="date" name="start_date" id="setup_start" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Sampai Tanggal Utama</label>
                        <input type="date" name="end_date" id="setup_end" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required>
                    </div>
                </div>

                <div class="border p-3 rounded-4 bg-white mt-3 shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <label class="small fw-bold text-secondary mb-0 uppercase">Kolom Komparatif (Rentang Perbandingan)</label>
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
/** 🚀 FORENSIC AJAX CALLER */
function drillDownSuspense(start, end) {
    const mEl = document.getElementById('modalSuspense');
    const m = new bootstrap.Modal(mEl);
    const sBody = document.getElementById('suspenseBody');
    
    sBody.innerHTML = '<tr><td colspan="6" class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x text-danger mb-3 d-block"></i><span class="fw-bold">Mesin Forensik sedang membedah ribuan jurnal...</span></td></tr>';
    m.show();

    fetch(`index.php?page=laporan_kas_detail&ajax_action=get_suspense&s=${start}&e=${end}`)
    .then(r => r.json())
    .then(data => {
        let html = '';
        if(data.length === 0) {
            html = '<tr><td colspan="6" class="text-center p-5 text-muted fw-bold italic"><i class="fas fa-check-circle text-success fa-2x mb-2 d-block"></i>Tidak ditemukan anomali jurnal. Selisih mungkin berasal dari input manual Saldo Awal akun yang tidak seimbang.</td></tr>';
        } else {
            data.forEach(d => {
                let debit = parseFloat(d.debit) || 0;
                let kredit = parseFloat(d.kredit) || 0;
                
                html += `<tr>
                    <td class="text-center text-muted small">${d.tgl_jurnal.split(' ')[0]}</td>
                    <td class="text-center"><code>${d.no_jurnal}</code></td>
                    <td class="ps-3 text-start">
                        <div class="fw-bold text-dark mb-1">${d.keterangan}</div>
                        <span class="badge bg-light border text-danger"><i class="fas fa-crosshairs me-1"></i> ${d.kode_akun} - ${d.nama_akun}</span>
                    </td>
                    <td class="text-end text-success fw-bold">${debit > 0 ? new Intl.NumberFormat('id-ID').format(debit) : '-'}</td>
                    <td class="text-end text-danger fw-bold pe-3">${kredit > 0 ? new Intl.NumberFormat('id-ID').format(kredit) : '-'}</td>
                    <td class="text-center px-2 py-3"><span class="badge bg-danger rounded-pill px-3 py-2 text-wrap" style="line-height:1.3;">${d.suspect_reason}</span></td>
                </tr>`;
            });
        }
        sBody.innerHTML = html;
    }).catch(err => {
        sBody.innerHTML = '<tr><td colspan="6" class="text-center p-5 text-danger fw-bold">Gagal terhubung ke Server Forensik!</td></tr>';
    });
}


function openSetupModal() { 
    const modalEl = document.getElementById('modalSetup');
    if(!modalEl) return;
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); 
    document.getElementById('setup_id').value = ''; 
    document.getElementById('setup_judul').value = 'Laporan Arus Kas ' + new Date().getFullYear(); 
    document.getElementById('setup_start').value = '<?= date("Y-01-01") ?>';
    document.getElementById('setup_end').value = '<?= date("Y-m-d") ?>';
    document.getElementById('compContainer').innerHTML = '';
    bsModal.show(); 
}

function addCompRow(s = '', e = '') {
    const html = `<div class="row g-2 mb-2 comp-row animate__animated animate__fadeIn">
        <div class="col-5">
            <input type="date" name="comp_start[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-none small" value="${s}" required>
        </div>
        <div class="col-5">
            <input type="date" name="comp_end[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-none small" value="${e}" required>
        </div>
        <div class="col-2">
            <button type="button" class="btn btn-light text-danger rounded-pill w-100 fw-bold" onclick="this.closest('.comp-row').remove()">&times;</button>
        </div>
    </div>`;
    document.getElementById('compContainer').insertAdjacentHTML('beforeend', html);
}

function editSetup(el) { 
    const modalEl = document.getElementById('modalSetup');
    if(!modalEl) return;
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl); 
    const d = el.dataset;
    document.getElementById('setup_id').value = d.id; 
    document.getElementById('setup_judul').value = d.judul ?? ''; 
    document.getElementById('setup_start').value = d.start ?? ''; 
    document.getElementById('setup_end').value = d.end ?? ''; 
    document.getElementById('compContainer').innerHTML = '';
    
    if(d.comp && d.comp !== 'null' && d.comp !== '[]') { 
        try { 
            const comps = JSON.parse(d.comp);
            comps.forEach(p => {
                addCompRow(p.s, p.e); 
            }); 
        } catch(err) { console.error("Error load comp dates", err); } 
    }
    bsModal.show(); 
}

function handleDelete(id) { 
    if(confirm('Hapus arsip laporan arus kas ini secara permanen?')) { 
        window.location.href = `financial_action.php?action=delete_setting&id=${id}&target=laporan_kas_detail`; 
    } 
}

function exportToExcelNeraca(tableId, filename) {
    const table = document.getElementById(tableId);
    const judul = document.getElementById('reportTitleHeader').innerText;
    const periode = document.getElementById('reportPeriodText').innerText;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'export_excel_engine.php';
    form.target = '_blank';

    const inputs = [
        { name: 'judul_laporan', value: judul },
        { name: 'nama_file', value: filename },
        { name: 'periode_text', value: periode },
        { name: 'html_content', value: table.outerHTML }
    ];

    inputs.forEach(data => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = data.name;
        input.value = data.value;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
</script>