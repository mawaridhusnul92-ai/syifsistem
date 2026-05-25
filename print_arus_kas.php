<?php
/**
 * print_arus_kas.php - OFFICIAL PRINT ENGINE
 * Versi: 9.6 (Sovereign Grand Master - True HTML Engine Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan: Merubah cetak arus kas dari format Screenshot menjadi HTML Murni 
 * lengkap dengan header estetik abu-abu dan struktur tanda tangan presisi.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak."); }
$id = (int)($_GET['id'] ?? 0);
if (!$id) die("Dokumen tidak ditemukan.");

$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=$id")->fetch_assoc();
$periods = [['s' => $conf['tgl_mulai'], 'e' => $conf['tgl_akhir'], 'label' => date('d M Y', strtotime($conf['tgl_akhir']))]];
$comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
if(is_array($comp_json)) { 
    foreach($comp_json as $cj) { 
        $d_s = is_array($cj) ? ($cj['s'] ?? '') : ''; $d_e = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj;
        if(!empty($d_e)) $periods[] = ['s' => (!empty($d_s))?$d_s:date('Y-01-01', strtotime($d_e)), 'e' => $d_e, 'label' => date('d M Y', strtotime($d_e))]; 
    } 
}

function getCashFlowDataPrint($conn, $start, $end) {
    $data = ['OPERATING' => [], 'INVESTING' => [], 'FINANCING' => [], 'net_op' => 0, 'net_inv' => 0, 'net_fin' => 0];
    
    $sql = "SELECT a.cash_flow_category, a.kode_akun, a.nama_akun, SUM(jd.kredit - jd.debit) as net_cf
            FROM syifa_jurnal_detail jd
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id
            JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
            WHERE a.kategori NOT IN ('Kas', 'Bank') AND a.is_cash_account = 0
            AND j.tgl_jurnal BETWEEN '$start 00:00:00' AND '$end 23:59:59' AND j.is_deleted = 0
            GROUP BY a.kode_akun, a.nama_akun, a.cash_flow_category HAVING net_cf != 0";
    
    $res = $conn->query($sql);
    if($res) {
        while($r = $res->fetch_assoc()) {
            $cat = $r['cash_flow_category'] ?? 'NONE';
            if(in_array($cat, ['OPERATING', 'INVESTING', 'FINANCING'])) {
                $data[$cat][] = ['kode' => $r['kode_akun'], 'nama' => $r['nama_akun'], 'net' => (double)$r['net_cf']];
                if($cat=='OPERATING') $data['net_op'] += (double)$r['net_cf'];
                if($cat=='INVESTING') $data['net_inv'] += (double)$r['net_cf'];
                if($cat=='FINANCING') $data['net_fin'] += (double)$r['net_cf'];
            }
        }
    }
    
    $data['net_total'] = $data['net_op'] + $data['net_inv'] + $data['net_fin'];
    
    $tgl_aw = date('Y-m-d', strtotime('-1 day', strtotime($start)));
    $q_kas = $conn->query("SELECT SUM(opening_balance) as ob FROM syifa_akun WHERE (kategori IN ('Kas', 'Bank') OR is_cash_account=1) AND is_group=0");
    $ob = (double)($q_kas->fetch_assoc()['ob'] ?? 0);
    
    $q_mut = $conn->query("SELECT SUM(jd.debit - jd.kredit) as m FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun=a.kode_akun WHERE (a.kategori IN ('Kas', 'Bank') OR a.is_cash_account=1) AND j.tgl_jurnal <= '$tgl_aw 23:59:59' AND j.is_deleted=0");
    $mut_aw = (double)($q_mut->fetch_assoc()['m'] ?? 0);
    
    $data['saldo_awal'] = $ob + $mut_aw;
    $data['saldo_akhir'] = $data['saldo_awal'] + $data['net_total'];
    return $data;
}

$report_data = [];
foreach ($periods as $idx => $p) { $report_data[$idx] = getCashFlowDataPrint($conn, $p['s'], $p['e']); }

function fmtP($n, $b=false) {
    if (round($n, 2) == 0) return "-";
    $f = number_format(abs($n), 0, ',', '.'); if ($n < 0) $f = "($f)"; 
    $w = $b ? 'font-weight: bold;' : '';
    return "<div style='display: flex; justify-content: space-between; width: 100%; $w'><div style='text-align: left; width: 30px;'>Rp</div><div style='text-align: right;'>$f</div></div>";
}
$app = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$logo = (!empty($app['logo']) && file_exists("assets/img/" . $app['logo'])) ? "assets/img/" . $app['logo'] : "";
$inst = $app['institution_name'] ?? 'STIKes YARSI PONTIANAK';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Arus Kas</title>
    <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 10pt; color: #000; margin: 0; }
        table.kop { width: 100%; border-bottom: 3px solid #000; margin-bottom: 2mm; }
        .judul { font-size: 11pt; font-weight: bold; text-align: center; margin-bottom: 3mm; text-transform: uppercase; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        table.data th, table.data td { border: 1px solid #000; padding: 5px; }
        
        /* 🚀 INJEKSI WARNA ABU-ABU ESTETIK PADA HEADER */
        table.data th { 
            text-align: center; font-weight: bold; background-color: #cbd5e1 !important; color: #000 !important; 
            text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        
        .row-cat td { font-weight: bold; background-color: #f8fafc !important; text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .row-sub td { font-weight: bold; background-color: rgba(0,0,0,0.03) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .row-grand td { font-weight: bold; background-color: #e2e8f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .ind-1 { padding-left: 20px; }
        table.sign { width: 100%; font-size: 10pt; text-align: center; page-break-inside: avoid; margin-top: 10mm; border: none !important;}
        table.sign td { border: none !important; padding: 0; }
    </style>
</head>
<body onload="window.print()">

<table class="kop">
    <tr>
        <td width="15%"><?php if($logo) echo "<img src='$logo' style='max-height:70px;'>"; ?></td>
        <td width="70%" style="text-align:center;"><div style="font-size:14pt; font-weight:bold; text-transform:uppercase;"><?= $inst ?></div><div style="font-size:10pt;"><?= $app['address'] ?? '' ?></div></td>
        <td width="15%"></td>
    </tr>
</table>
<div style="border-top:1px solid #000; margin-bottom:5mm;"></div>

<div class="judul">LAPORAN ARUS KAS<br><span style="font-size:10pt; font-weight:normal;">Periode <?= date('d M Y', strtotime($conf['tgl_mulai'])) ?> s.d <?= date('d M Y', strtotime($conf['tgl_akhir'])) ?></span></div>

<table class="data">
    <thead><tr><th style="text-align: left; padding-left: 10px;">URAIAN ARUS KAS</th><?php foreach($periods as $p) echo "<th width='150'>".$p['label']."</th>"; ?></tr></thead>
    <tbody>
        <!-- OPERASI -->
        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">A. ARUS KAS DARI AKTIVITAS OPERASI</td></tr>
        <?php 
        $all_op = []; foreach($report_data as $rd) { foreach($rd['OPERATING'] as $p) { $all_op[$p['kode']] = $p['nama']; } } ksort($all_op);
        foreach($all_op as $kode => $nama): ?>
            <tr><td class="ind-1"><?= $nama ?></td>
            <?php foreach($report_data as $rd) { $v=0; foreach($rd['OPERATING'] as $p) { if($p['kode']==$kode){ $v=$p['net']; break; } } echo "<td>".fmtP($v)."</td>"; } ?></tr>
        <?php endforeach; ?>
        <tr class="row-sub"><td>Arus Kas Bersih dari Aktivitas Operasi</td><?php foreach($report_data as $rd) echo "<td>".fmtP($rd['net_op'], true)."</td>"; ?></tr>
        
        <!-- INVESTASI -->
        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">B. ARUS KAS DARI AKTIVITAS INVESTASI</td></tr>
        <?php 
        $all_inv = []; foreach($report_data as $rd) { foreach($rd['INVESTING'] as $p) { $all_inv[$p['kode']] = $p['nama']; } } ksort($all_inv);
        foreach($all_inv as $kode => $nama): ?>
            <tr><td class="ind-1"><?= $nama ?></td>
            <?php foreach($report_data as $rd) { $v=0; foreach($rd['INVESTING'] as $p) { if($p['kode']==$kode){ $v=$p['net']; break; } } echo "<td>".fmtP($v)."</td>"; } ?></tr>
        <?php endforeach; ?>
        <tr class="row-sub"><td>Arus Kas Bersih dari Aktivitas Investasi</td><?php foreach($report_data as $rd) echo "<td>".fmtP($rd['net_inv'], true)."</td>"; ?></tr>

        <!-- PENDANAAN -->
        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">C. ARUS KAS DARI AKTIVITAS PENDANAAN</td></tr>
        <?php 
        $all_fin = []; foreach($report_data as $rd) { foreach($rd['FINANCING'] as $p) { $all_fin[$p['kode']] = $p['nama']; } } ksort($all_fin);
        foreach($all_fin as $kode => $nama): ?>
            <tr><td class="ind-1"><?= $nama ?></td>
            <?php foreach($report_data as $rd) { $v=0; foreach($rd['FINANCING'] as $p) { if($p['kode']==$kode){ $v=$p['net']; break; } } echo "<td>".fmtP($v)."</td>"; } ?></tr>
        <?php endforeach; ?>
        <tr class="row-sub"><td>Arus Kas Bersih dari Aktivitas Pendanaan</td><?php foreach($report_data as $rd) echo "<td>".fmtP($rd['net_fin'], true)."</td>"; ?></tr>

        <!-- REKONSILIASI KAS -->
        <tr class="row-grand"><td>KENAIKAN (PENURUNAN) BERSIH KAS DAN SETARA KAS</td><?php foreach($report_data as $rd) echo "<td>".fmtP($rd['net_total'], true)."</td>"; ?></tr>
        <tr><td>Kas dan Setara Kas Pada Awal Periode</td><?php foreach($report_data as $rd) echo "<td>".fmtP($rd['saldo_awal'])."</td>"; ?></tr>
        <tr class="row-grand"><td>KAS DAN SETARA KAS PADA AKHIR PERIODE (SINKRON NERACA)</td><?php foreach($report_data as $rd) echo "<td>".fmtP($rd['saldo_akhir'], true)."</td>"; ?></tr>
    </tbody>
</table>

<?php
$kota = $conf['ttd_kota'] ?? $app['city'] ?? 'Pontianak';
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'ARUS_KAS' ORDER BY id ASC");
$sigs = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $sigs[] = $r;
echo "<table class='sign'><tr>";
if(!empty($sigs)) {
    $width = floor(100 / count($sigs)) . '%';
    foreach($sigs as $idx => $sig) {
        if($idx == count($sigs)-1) echo "<td width='$width'>$kota, ".date('d M Y', strtotime($conf['tgl_akhir']))."<br>".htmlspecialchars($sig['sign_role'])."</td>";
        else echo "<td width='$width'><br>".htmlspecialchars($sig['sign_role'])."</td>";
    }
    echo "</tr><tr>";
    foreach($sigs as $sig) {
        $name = htmlspecialchars($sig['sign_name']) ?: '( ____________________ )';
        echo "<td><div style='margin-top: 20mm;'><b>$name</b><br><span>{$sig['sign_position']}</span></div></td>";
    }
} else {
    echo "<td width='60%'></td><td width='40%'>$kota, ".date('d M Y', strtotime($conf['tgl_akhir']))."<br>Mengetahui/Menyetujui<br><b>Pimpinan Institusi</b><div style='margin-top: 20mm;'><b><u>....................................</u></b></div></td>";
}
echo "</tr></table>";
?>

</body>
</html>