<?php
/**
 * print_kas_summary.php - SUPREME PRINT ENGINE (CASH SUMMARY ISAK 35)
 * Versi: 2.1 (Grand Master - Precise Alignment & Currency Sync Edition)
 * Perbaikan: Sinkronisasi penjajaran saldo, Penambahan simbol Rp universal, & Deteksi Akun Otomatis.
 * Deskripsi: Menyajikan mutasi kas masuk/keluar per akun lawan secara komparatif dengan format audit.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$report_id = (int)($_GET['id'] ?? 0);
if ($report_id <= 0) die("ID Laporan tidak valid.");

// --- 1. AMBIL DATA MASTER & PROFIL ---
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
if (!$conf) die("Arsip laporan tidak ditemukan.");

$periods = [];
// Periode Utama
$periods[] = ['s' => $conf['tgl_mulai'], 'e' => $conf['tgl_akhir'], 'label' => date('d/m/y', strtotime($conf['tgl_akhir']))];

// Periode Komparatif
$comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
if(is_array($comp_json)) { 
    foreach($comp_json as $cj) { 
        $d_start = is_array($cj) ? ($cj['s'] ?? '') : '';
        $d_end = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj;
        if(!empty($d_end)) {
            $periods[] = [
                's' => (!empty($d_start)) ? $d_start : date('Y-01-01', strtotime($d_end)), 
                'e' => $d_end, 
                'label' => date('d/m/y', strtotime($d_end))
            ]; 
        }
    } 
}

// --- 2. ENGINE CALCULATION (SYNCED WITH SYSTEM CORE) ---

/**
 * getAccountCashActivity - Menghitung aktivitas kas per akun lawan
 */
function getAccountCashActivity($kode, $s_date, $e_date, $conn, $type = 'IN') {
    $cond = ($type == 'IN') ? "jd_kas.debit > 0" : "jd_kas.kredit > 0";
    $val_field = ($type == 'IN') ? "jd_target.kredit" : "jd_target.debit";
    $sql = "SELECT SUM($val_field) as total FROM syifa_jurnal j
            JOIN syifa_jurnal_detail jd_kas ON j.id = jd_kas.jurnal_id
            JOIN syifa_jurnal_detail jd_target ON j.id = jd_target.jurnal_id
            WHERE (jd_kas.kode_akun LIKE '1-11%' OR jd_kas.kode_akun LIKE '1.11%')
            AND $cond AND jd_target.kode_akun = '$kode'
            AND j.tgl_jurnal BETWEEN '$s_date' AND '$e_date'";
    $res = $conn->query($sql)->fetch_assoc();
    return (double)($res['total'] ?? 0);
}

/**
 * getInitialCashBalance - Kalkulasi Saldo Awal (COA + Journal Sync)
 */
function getInitialCashBalance($s_date, $conn) {
    $q_coa = $conn->query("SELECT SUM(opening_balance) as ob FROM syifa_akun WHERE (kode_akun LIKE '1-11%' OR kode_akun LIKE '1.11%') AND is_group=0")->fetch_assoc();
    $coa_ob = (double)($q_coa['ob'] ?? 0);
    $sql_mut = "SELECT SUM(debit - kredit) as bal FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE (jd.kode_akun LIKE '1-11%' OR jd.kode_akun LIKE '1.11%') AND j.tgl_jurnal < '$s_date'";
    $res_mut = $conn->query($sql_mut)->fetch_assoc();
    return $coa_ob + (double)($res_mut['bal'] ?? 0);
}

/**
 * fmt - Fungsi pemformat angka akuntansi dengan penjajaran presisi
 */
function fmt($n, $bold = false, $color = '') {
    if ($n == 0) return "-";
    $f = number_format(abs($n), 0, ',', '.');
    if ($n < 0) $f = "($f)";
    $weight = $bold ? "900" : "normal";
    $style = $color ? "color: $color;" : "";
    
    // Menggunakan flexbox di dalam tabel cetak untuk mengunci posisi Rp di kiri dan Angka di kanan
    return "<div style='float: right; width: 125px; font-weight: $weight; font-family: Arial, sans-serif; font-size: 9.5pt; $style display: flex; justify-content: space-between;'>
                <span style='text-align: left;'>Rp</span>
                <span style='text-align: right; white-space: nowrap;'>$f</span>
            </div><div style='clear: both;'></div>";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ringkasan_Kas_<?= date('Ymd') ?></title>
    <style>
        @page { size: A4 portrait; margin: 10mm 15mm; }
        body { font-family: 'Times New Roman', Times, serif; margin: 0; padding: 0; color: #000; background: #fff; line-height: 1.3; width: 100%; }
        .kop-table { width: 100%; border-collapse: collapse; border-bottom: 3.5px double #000; margin-bottom: 25px; table-layout: fixed; }
        .kop-logo { width: 15%; text-align: left; vertical-align: middle; }
        .kop-info { width: 70%; text-align: center; vertical-align: middle; }
        .report-title { font-family: Arial, sans-serif; font-size: 13pt; font-weight: 900; text-decoration: underline; margin-top: 10px; text-transform: uppercase; }
        
        .table-summary { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 10px; }
        .table-summary th { border-top: 1.5pt solid #000; border-bottom: 1.5pt solid #000; padding: 10px 5px; font-family: Arial, sans-serif; font-size: 8pt; font-weight: bold; background: #f2f2f2 !important; text-align: center; }
        .table-summary td { padding: 7px 5px; border-bottom: 0.5pt solid #eee; font-size: 10.5pt; vertical-align: middle; }
        
        .col-uraian { width: auto; text-align: left !important; }
        .col-val { width: 140px; text-align: right !important; }

        .row-section { font-weight: bold; background: #fafafa !important; text-transform: uppercase; border-bottom: 1pt solid #000 !important; }
        .row-subtotal { font-weight: bold; border-top: 1pt solid #000; }
        .row-total-final { background: #1e293b !important; color: #fff !important; font-weight: bold; -webkit-print-color-adjust: exact; }
        .row-total-final td { color: #fff !important; padding: 10px 8px !important; border: none !important; }
        .indent { padding-left: 20px !important; }
        .text-danger { color: #d32f2f !important; }
    </style>
</head>
<body onload="window.print()">
    <table class="kop-table">
        <tr>
            <td class="kop-logo"><?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height: 80px;"><?php endif; ?></td>
            <td class="kop-info">
                <h1 style="font-family: Arial; font-size: 16pt; margin:0;"><?= strtoupper($profile['institution_name']) ?></h1>
                <div style="font-size: 9pt; font-weight: bold;"><?= $profile['address'] ?>, <?= $profile['city'] ?> | Telp: <?= $profile['phone'] ?></div>
                <h2 class="report-title">RINGKASAN PENERIMAAN DAN PEMBAYARAN</h2>
                <div style="font-weight:bold; font-size:10pt;">Periode yang berakhir pada Tanggal <?= date('d F Y', strtotime($periods[0]['e'])) ?></div>
            </td>
            <td style="width:15%"></td>
        </tr>
    </table>

    <table class="table-summary">
        <thead>
            <tr>
                <th style="text-align:left; padding-left:10px;">URAIAN TRANSAKSI KAS</th>
                <?php foreach($periods as $p) echo "<th class='col-val'>PER ".strtoupper($p['label'])."</th>"; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $accounts = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY nama_akun ASC")->fetch_all(MYSQLI_ASSOC);
            $total_ins = array_fill(0, count($periods), 0);
            $total_outs = array_fill(0, count($periods), 0);
            ?>

            <!-- SEKSI PENERIMAAN -->
            <tr class="row-section"><td colspan="<?= count($periods)+1 ?>" style="padding-left:10px;">PENERIMAAN KAS</td></tr>
            <?php foreach($accounts as $acc): 
                $row_vals = []; $has_act = false;
                foreach($periods as $idx => $p) {
                    $v = getAccountCashActivity($acc['kode_akun'], $p['s'], $p['e'], $conn, 'IN');
                    $row_vals[] = $v; $total_ins[$idx] += $v;
                    if($v != 0) $has_act = true;
                }
                if($has_act):
            ?>
                <tr><td class="indent"><?= $acc['nama_akun'] ?></td>
                    <?php foreach($row_vals as $v) echo "<td>".fmt($v)."</td>"; ?></tr>
            <?php endif; endforeach; ?>
            <tr class="row-subtotal"><td style="padding-left:10px;">TOTAL PENERIMAAN</td><?php foreach($total_ins as $ti) echo "<td>".fmt($ti, true)."</td>"; ?></tr>

            <tr><td colspan="<?= count($periods)+1 ?>" style="height:15px;"></td></tr>

            <!-- SEKSI PEMBAYARAN -->
            <tr class="row-section"><td colspan="<?= count($periods)+1 ?>" style="padding-left:10px;">PEMBAYARAN KAS</td></tr>
            <?php foreach($accounts as $acc): 
                $row_vals = []; $has_act = false;
                foreach($periods as $idx => $p) {
                    $v = getAccountCashActivity($acc['kode_akun'], $p['s'], $p['e'], $conn, 'OUT');
                    $row_vals[] = $v; $total_outs[$idx] += $v;
                    if($v != 0) $has_act = true;
                }
                if($has_act):
            ?>
                <tr><td class="indent"><?= $acc['nama_akun'] ?></td>
                    <?php foreach($row_vals as $v) echo "<td>".fmt(-$v, false, '#d32f2f')."</td>"; ?></tr>
            <?php endif; endforeach; ?>
            <tr class="row-subtotal"><td style="padding-left:10px;">TOTAL PEMBAYARAN</td><?php foreach($total_outs as $to) echo "<td>".fmt(-$to, true)."</td>"; ?></tr>

            <tr><td colspan="<?= count($periods)+1 ?>" style="height:25px;"></td></tr>

            <!-- PERHITUNGAN AKHIR -->
            <tr style="font-weight:bold;"><td style="padding-left:10px;">Kenaikan (Penurunan) Kas Bersih Berjalan</td>
                <?php foreach($periods as $idx => $p) echo "<td>".fmt($total_ins[$idx] - $total_outs[$idx], true)."</td>"; ?></tr>
            <tr><td style="padding-left:10px;">Saldo Kas pada Awal Periode (COA Sync)</td>
                <?php foreach($periods as $p) echo "<td>".fmt(getInitialCashBalance($p['s'], $conn))."</td>"; ?></tr>
            
            <tr style="height:15px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
            <tr class="row-total-final">
                <td style="padding-left:15px;">SALDO KAS PADA AKHIR PERIODE</td>
                <?php foreach($periods as $idx => $p){ 
                    $sa = getInitialCashBalance($p['s'], $conn);
                    $ak = $sa + ($total_ins[$idx] - $total_outs[$idx]);
                    echo "<td>".fmt($ak, true)."</td>"; 
                } ?>
            </tr>
        </tbody>
    </table>
    </div>
</body>
</html>