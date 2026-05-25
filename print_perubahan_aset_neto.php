<?php
/**
 * print_perubahan_aset_neto.php - OFFICIAL PRINT ENGINE
 * Versi: 806.0 (Gray Header Edition)
 * Deskripsi: Cetak Laporan Perubahan Aset Neto 100% Identik UI
 * dengan penyelarasan Header Kolom berwarna Abu-abu estetik.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';
require_once 'engine/LedgerAggregationEngine.php';

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

function sumNetoPrint($conn, $kat, $tgl_start, $tgl_end, $is_kredit, $extra_cond = "") {
    $sql = "SELECT SUM(jd.kredit) as k, SUM(jd.debit) as d FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun WHERE a.kategori IN ($kat) AND j.tgl_jurnal BETWEEN '$tgl_start 00:00:00' AND '$tgl_end 23:59:59' AND j.is_deleted = 0 $extra_cond";
    $res = $conn->query($sql);
    $r = $res ? $res->fetch_assoc() : ['k'=>0, 'd'=>0];
    return $is_kredit ? ((double)$r['k'] - (double)$r['d']) : ((double)$r['d'] - (double)$r['k']);
}

$data0 = []; $data1 = [];
foreach($periods as $idx => $p) { 
    $tgl_akhir = $p['e']; $tahun = date('Y', strtotime($tgl_akhir)); $tgl_awal_tahun = "$tahun-01-01"; $tgl_akhir_lalu = date('Y-m-d', strtotime('-1 day', strtotime($tgl_awal_tahun)));

    $rd_akhir = LedgerAggregationEngine::getNeracaData($conn, $tgl_akhir);
    $ga = ($rd_akhir['kas'] + $rd_akhir['piutang'] + $rd_akhir['dimuka'] + $rd_akhir['persediaan'] + $rd_akhir['aset_lancar_lain']) + ($rd_akhir['aset_tetap_berwujud_cost'] + $rd_akhir['aset_tetap_berwujud_akum'] + $rd_akhir['aset_tetap_tak_berwujud_cost'] + $rd_akhir['aset_tetap_tak_berwujud_akum']);
    $la = -1 * ($rd_akhir['liab_pendek'] + $rd_akhir['liab_panjang'] + $rd_akhir['liab_lain']);
    $target_ekuitas_akhir = $ga - $la;

    $rd_awal = LedgerAggregationEngine::getNeracaData($conn, $tgl_akhir_lalu);
    $ga_aw = ($rd_awal['kas'] + $rd_awal['piutang'] + $rd_awal['dimuka'] + $rd_awal['persediaan'] + $rd_awal['aset_lancar_lain']) + ($rd_awal['aset_tetap_berwujud_cost'] + $rd_awal['aset_tetap_berwujud_akum'] + $rd_awal['aset_tetap_tak_berwujud_cost'] + $rd_awal['aset_tetap_tak_berwujud_akum']);
    $la_aw = -1 * ($rd_awal['liab_pendek'] + $rd_awal['liab_panjang'] + $rd_awal['liab_lain']);
    $target_ekuitas_awal = $ga_aw - $la_aw;

    $surplus_berjalan = sumNetoPrint($conn, "'Pendapatan'", $tgl_awal_tahun, $tgl_akhir, true) - sumNetoPrint($conn, "'Beban'", $tgl_awal_tahun, $tgl_akhir, false);

    $ob_rest = (double)($conn->query("SELECT SUM(opening_balance) as ob FROM syifa_akun WHERE kategori IN ('Aset Neto', 'Ekuitas') AND is_restricted = 1 AND is_group = 0")->fetch_assoc()['ob'] ?? 0);
    $saldo_awal_rest = $ob_rest + sumNetoPrint($conn, "'Aset Neto', 'Ekuitas'", '1970-01-01', $tgl_akhir_lalu, true, "AND a.is_restricted = 1");
    $saldo_akhir_rest = $saldo_awal_rest + sumNetoPrint($conn, "'Aset Neto', 'Ekuitas'", $tgl_awal_tahun, $tgl_akhir, true, "AND a.is_restricted = 1");

    $saldo_awal_unrest = $target_ekuitas_awal - $saldo_awal_rest;
    $saldo_akhir_unrest = $target_ekuitas_akhir - $saldo_akhir_rest;
    $mut_unrest_ini = $saldo_akhir_unrest - ($saldo_awal_unrest + $surplus_berjalan);

    $data0[$idx] = ['aw' => $saldo_awal_unrest, 'dir' => $mut_unrest_ini, 'sur' => $surplus_berjalan, 'ak' => $saldo_akhir_unrest];
    $data1[$idx] = ['aw' => $saldo_awal_rest, 'dir' => ($saldo_akhir_rest - $saldo_awal_rest), 'sur' => 0, 'ak' => $saldo_akhir_rest];
}

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
    <title>Cetak Perubahan Aset Neto</title>
    <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 10pt; color: #000; margin: 0; }
        table.kop { width: 100%; border-bottom: 3px solid #000; margin-bottom: 2mm; }
        .judul { font-size: 11pt; font-weight: bold; text-align: center; margin-bottom: 3mm; text-transform: uppercase; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        table.data th, table.data td { border: 1px solid #000; padding: 5px; }
        
        /* 🚀 INJEKSI WARNA ABU-ABU ESTETIK PADA HEADER */
        table.data th { 
            text-align: center; 
            font-weight: bold; 
            background-color: #cbd5e1 !important; 
            color: #000 !important; 
            text-transform: uppercase; 
            -webkit-print-color-adjust: exact; 
            print-color-adjust: exact;
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

<div class="judul">LAPORAN PERUBAHAN ASET NETO<br><span style="font-size:10pt; font-weight:normal;">Untuk Tahun Yang Berakhir Pada Tanggal <?= date('d M Y', strtotime($conf['tgl_akhir'])) ?></span></div>

<table class="data">
    <thead><tr><th style="text-align: left; padding-left: 10px;">URAIAN PERUBAHAN EKUITAS</th><?php foreach($periods as $p) echo "<th width='150'>".$p['label']."</th>"; ?></tr></thead>
    <tbody>
        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">A. TANPA PEMBATASAN DARI PEMBERI SUMBER DAYA</td></tr>
        <tr><td class="ind-1">Saldo Awal Aset Neto / Surplus Ditahan</td><?php foreach($data0 as $d) echo "<td>".fmtP($d['aw'])."</td>"; ?></tr>
        <?php $hk = false; foreach($data0 as $d) { if (round(abs($d['dir']), 2) > 0) { $hk = true; break; } }
        if ($hk): ?><tr><td class="ind-1">Koreksi/Penyesuaian Nilai Buku Modal</td><?php foreach($data0 as $d) echo "<td>".fmtP($d['dir'])."</td>"; ?></tr><?php endif; ?>
        <tr><td class="ind-1">Surplus (Defisit) Tahun Berjalan</td><?php foreach($data0 as $d) echo "<td>".fmtP($d['sur'])."</td>"; ?></tr>
        <tr class="row-sub"><td>Aset Neto Tanpa Pembatasan Akhir</td><?php foreach($data0 as $d) echo "<td>".fmtP($d['ak'], true)."</td>"; ?></tr>

        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">B. DENGAN PEMBATASAN DARI PEMBERI SUMBER DAYA</td></tr>
        <tr><td class="ind-1">Saldo Awal Dana Terikat</td><?php foreach($data1 as $d) echo "<td>".fmtP($d['aw'])."</td>"; ?></tr>
        <?php $hr = false; foreach($data1 as $d) { if (round(abs($d['dir']), 2) > 0) { $hr = true; break; } }
        if ($hr): ?><tr><td class="ind-1">Koreksi/Penyesuaian Nilai Buku Terikat</td><?php foreach($data1 as $d) echo "<td>".fmtP($d['dir'])."</td>"; ?></tr><?php endif; ?>
        <tr class="row-sub"><td>Aset Neto Dengan Pembatasan Akhir</td><?php foreach($data1 as $d) echo "<td>".fmtP($d['ak'], true)."</td>"; ?></tr>

        <tr class="row-grand"><td>TOTAL ASET NETO AKHIR (SINKRON NERACA)</td><?php foreach($periods as $idx => $p) echo "<td>".fmtP($data0[$idx]['ak'] + $data1[$idx]['ak'], true)."</td>"; ?></tr>
    </tbody>
</table>

<?php
$kota = $conf['ttd_kota'] ?? $app['city'] ?? 'Pontianak';
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'ASET_NETO' ORDER BY id ASC");
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