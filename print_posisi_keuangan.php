<?php
/**
 * print_posisi_keuangan.php - OFFICIAL PRINT ENGINE
 * Versi: 9.9 (Sovereign Grand Master - Asset Subtotal Print Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Deskripsi: Mesin cetak 100% Identik dengan Sistem, menampilkan 
 * subtotal perolehan aset dan header abu-abu elegan.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';
require_once 'engine/LedgerAggregationEngine.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak."); }

$id = (int)($_GET['id'] ?? 0);
if (!$id) die("Dokumen tidak ditemukan.");

$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=$id")->fetch_assoc();
if (!$conf) die("Konfigurasi laporan tidak valid.");

$periods = [['e' => $conf['tgl_akhir'], 'label' => date('d M Y', strtotime($conf['tgl_akhir']))]];
$comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
if(is_array($comp_json)) { 
    foreach($comp_json as $cj) { 
        $d_end = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj; 
        if(!empty($d_end)) $periods[] = ['e' => $d_end, 'label' => date('d M Y', strtotime($d_end))]; 
    } 
}

function sumNetoPrint($conn, $kat, $tgl_start, $tgl_end, $is_kredit, $extra_cond = "") {
    $sql = "SELECT SUM(jd.kredit) as k, SUM(jd.debit) as d FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun WHERE a.kategori IN ($kat) AND j.tgl_jurnal BETWEEN '$tgl_start 00:00:00' AND '$tgl_end 23:59:59' AND j.is_deleted = 0 $extra_cond";
    $res = $conn->query($sql);
    $r = $res ? $res->fetch_assoc() : ['k'=>0, 'd'=>0];
    return $is_kredit ? ((double)$r['k'] - (double)$r['d']) : ((double)$r['d'] - (double)$r['k']);
}

$report_data = [];
foreach ($periods as $idx => $p) { 
    $rd = LedgerAggregationEngine::getNeracaData($conn, $p['e']); 
    $tgl_akhir = $p['e'];
    
    $q_at = $conn->query("SELECT t.type_name, SUM(a.purchase_value + COALESCE((SELECT SUM(nilai_penambahan) FROM asset_improvements ai WHERE ai.asset_id = a.id AND ai.tanggal <= '$tgl_akhir' AND ai.jenis_penambahan != 'Perolehan Awal'), 0)) as total_cost FROM assets a JOIN asset_types t ON a.type_id = t.id JOIN asset_categories c ON a.category_id = c.id WHERE c.category_name NOT LIKE '%Tidak Berwujud%' AND c.category_name NOT LIKE '%Amortisasi%' AND a.purchase_date <= '$tgl_akhir' AND a.status='Aktif' GROUP BY t.type_name");
    $at_b = []; $at_t = 0; if($q_at) { while($r = $q_at->fetch_assoc()){ $at_b[] = $r; $at_t += (double)$r['total_cost']; } }
    
    $q_atb = $conn->query("SELECT t.type_name, SUM(a.purchase_value + COALESCE((SELECT SUM(nilai_penambahan) FROM asset_improvements ai WHERE ai.asset_id = a.id AND ai.tanggal <= '$tgl_akhir' AND ai.jenis_penambahan != 'Perolehan Awal'), 0)) as total_cost FROM assets a JOIN asset_types t ON a.type_id = t.id JOIN asset_categories c ON a.category_id = c.id WHERE (c.category_name LIKE '%Tidak Berwujud%' OR c.category_name LIKE '%Amortisasi%') AND a.purchase_date <= '$tgl_akhir' AND a.status='Aktif' GROUP BY t.type_name");
    $atb_b = []; $atb_t = 0; if($q_atb) { while($r = $q_atb->fetch_assoc()){ $atb_b[] = $r; $atb_t += (double)$r['total_cost']; } }

    $rd['aset_tetap_berwujud_cost'] = $at_t;
    $rd['aset_tetap_tak_berwujud_cost'] = $atb_t;
    $rd['at_breakdown'] = $at_b;
    $rd['atb_breakdown'] = $atb_b;

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

    $pend_lalu = sumNetoPrint($conn, "'Pendapatan'", '1970-01-01', $tgl_akhir_lalu, true);
    $beb_lalu = sumNetoPrint($conn, "'Beban'", '1970-01-01', $tgl_akhir_lalu, false);
    $surplus_ditahan = $pend_lalu - $beb_lalu;

    $pend_ini = sumNetoPrint($conn, "'Pendapatan'", $tgl_awal_tahun, $tgl_akhir, true);
    $beb_ini = sumNetoPrint($conn, "'Beban'", $tgl_awal_tahun, $tgl_akhir, false);
    $surplus_berjalan = $pend_ini - $beb_ini;

    $q_ob_rest = $conn->query("SELECT SUM(opening_balance) as ob FROM syifa_akun WHERE kategori IN ('Aset Neto', 'Ekuitas') AND is_restricted = 1 AND is_group = 0")->fetch_assoc();
    $ob_rest = (double)($q_ob_rest['ob'] ?? 0);
    $mut_rest = sumNetoPrint($conn, "'Aset Neto', 'Ekuitas'", '1970-01-01', $tgl_akhir, true, "AND a.is_restricted = 1");
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
    $rd['t_lancar'] = $total_lancar;
    $rd['t_tdk_lancar'] = $total_tidak_lancar;

    $report_data[$idx] = $rd;
} 

function fmtP($n, $b=false) {
    if (round($n, 2) == 0) return "-";
    $f = number_format(abs($n), 0, ',', '.');
    if ($n < 0) $f = "($f)"; 
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
    <title>Cetak Neraca - <?= htmlspecialchars($conf['judul_laporan']) ?></title>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 9.5pt; color: #000; margin: 0; }
        table.kop { width: 100%; border-bottom: 3px solid #000; margin-bottom: 2mm; }
        .judul { font-size: 11pt; font-weight: bold; text-align: center; margin-bottom: 3mm; text-transform: uppercase; }
        
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        table.data th, table.data td { border: 1px solid #000; padding: 3px 5px; }
        
        table.data th { 
            text-align: center; font-weight: bold; background-color: #cbd5e1 !important; color: #000 !important; 
            text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        
        .row-cat td { font-weight: bold; background-color: #f8fafc !important; text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .row-sub td { font-weight: bold; background-color: rgba(0,0,0,0.03) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .row-grand td { font-weight: bold; background-color: #e2e8f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;}
        .ind-1 { padding-left: 15px; } .ind-2 { padding-left: 30px; }
        table.sign { width: 100%; font-size: 9.5pt; text-align: center; page-break-inside: avoid; margin-top: 5mm; border: none !important;}
        table.sign td { border: none !important; padding: 0; }
    </style>
</head>
<body onload="window.print()">

<table class="kop">
    <tr>
        <td width="15%"><?php if($logo) echo "<img src='$logo' style='max-height:60px;'>"; ?></td>
        <td width="70%" style="text-align:center;">
            <div style="font-size:14pt; font-weight:bold; text-transform:uppercase;"><?= $inst ?></div>
            <div style="font-size:9pt;"><?= $app['address'] ?? '' ?></div>
        </td>
        <td width="15%"></td>
    </tr>
</table>
<div style="border-top:1px solid #000; margin-bottom:4mm;"></div>

<div class="judul">LAPORAN POSISI KEUANGAN<br><span style="font-size:9.5pt; font-weight:normal;">Per Tanggal <?= date('d M Y', strtotime($conf['tgl_akhir'])) ?></span></div>

<table class="data">
    <thead>
        <tr><th style="text-align: left; padding-left: 10px;">URAIAN ASET, LIABILITAS, DAN ASET NETO</th><?php foreach($periods as $p) echo "<th width='150'>".$p['label']."</th>"; ?></tr>
    </thead>
    <tbody>
        <!-- ASET -->
        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">I. ASET</td></tr>
        <tr><td colspan="<?= count($periods)+1 ?>"><b>A. ASET LANCAR</b></td></tr>
        <tr><td class="ind-1">Kas dan Setara Kas</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['kas'])."</td>"; ?></tr>
        <tr><td class="ind-1">Piutang Mahasiswa & Usaha</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['piutang'])."</td>"; ?></tr>
        <tr><td class="ind-1">Beban Dibayar Dimuka</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['dimuka'])."</td>"; ?></tr>
        <tr><td class="ind-1">Persediaan</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['persediaan'])."</td>"; ?></tr>
        <tr><td class="ind-1">Aset Lancar Lainnya</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['aset_lancar_lain'])."</td>"; ?></tr>
        <tr class="row-sub"><td>Jumlah Aset Lancar</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['t_lancar'], true)."</td>"; ?></tr>
        
        <tr><td colspan="<?= count($periods)+1 ?>"><b>B. ASET TIDAK LANCAR</b></td></tr>
        <tr><td class="ind-1" colspan="<?= count($periods)+1 ?>">1. Aset Tetap Berwujud</td></tr>
        <?php
        $all_at = []; foreach($report_data as $rd) { foreach($rd['at_breakdown'] as $b) { $all_at[$b['type_name']] = 1; } } ksort($all_at);
        foreach($all_at as $type_name => $val): ?>
            <tr><td class="ind-2"><?= htmlspecialchars($type_name) ?></td>
            <?php foreach($report_data as $d) { $c = 0; foreach($d['at_breakdown'] as $b) { if($b['type_name'] == $type_name) { $c = $b['total_cost']; break; } } echo "<td>".fmtP($c)."</td>"; } ?>
            </tr>
        <?php endforeach; ?>
        <tr class="row-sub"><td>Jumlah Perolehan Aset Tetap Berwujud</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['aset_tetap_berwujud_cost'], true)."</td>"; ?></tr>
        <tr><td class="ind-2" style="font-style:italic;">Total Akumulasi Penyusutan</td><?php foreach($report_data as $d) echo "<td style='color:red;'>".fmtP($d['aset_tetap_berwujud_akum'])."</td>"; ?></tr>
        
        <tr><td class="ind-1" colspan="<?= count($periods)+1 ?>">2. Aset Tidak Berwujud</td></tr>
        <?php
        $all_atb = []; foreach($report_data as $rd) { foreach($rd['atb_breakdown'] as $b) { $all_atb[$b['type_name']] = 1; } } ksort($all_atb);
        foreach($all_atb as $type_name => $val): ?>
            <tr><td class="ind-2"><?= htmlspecialchars($type_name) ?></td>
            <?php foreach($report_data as $d) { $c = 0; foreach($d['atb_breakdown'] as $b) { if($b['type_name'] == $type_name) { $c = $b['total_cost']; break; } } echo "<td>".fmtP($c)."</td>"; } ?>
            </tr>
        <?php endforeach; ?>
        <tr class="row-sub"><td>Jumlah Perolehan Aset Tidak Berwujud</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['aset_tetap_tak_berwujud_cost'], true)."</td>"; ?></tr>
        <tr><td class="ind-2" style="font-style:italic;">Total Akumulasi Amortisasi</td><?php foreach($report_data as $d) echo "<td style='color:red;'>".fmtP($d['aset_tetap_tak_berwujud_akum'])."</td>"; ?></tr>
        <tr class="row-sub"><td>Jumlah Aset Tidak Lancar</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['t_tdk_lancar'], true)."</td>"; ?></tr>
        <tr class="row-grand"><td>TOTAL ASET</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['grand_aset'], true)."</td>"; ?></tr>
        
        <!-- LIABILITAS -->
        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">II. LIABILITAS</td></tr>
        <tr><td class="ind-1">Liabilitas Jangka Pendek</td><?php foreach($report_data as $d) echo "<td>".fmtP(-1 * $d['liab_pendek'])."</td>"; ?></tr>
        <tr><td class="ind-1">Liabilitas Jangka Panjang</td><?php foreach($report_data as $d) echo "<td>".fmtP(-1 * $d['liab_panjang'])."</td>"; ?></tr>
        <tr><td class="ind-1">Liabilitas Lainnya</td><?php foreach($report_data as $d) echo "<td>".fmtP(-1 * $d['liab_lain'])."</td>"; ?></tr>
        <tr class="row-sub"><td>TOTAL LIABILITAS</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['t_liab'], true)."</td>"; ?></tr>

        <!-- ASET NETO -->
        <tr class="row-cat"><td colspan="<?= count($periods)+1 ?>">III. ASET NETO (EKUITAS)</td></tr>
        <tr><td colspan="<?= count($periods)+1 ?>"><b>A. TANPA PEMBATASAN DARI PEMBERI SUMBER DAYA</b></td></tr>
        <tr><td class="ind-1">Saldo Aset Neto</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['modal_unrest'])."</td>"; ?></tr>
        <tr><td class="ind-1">Saldo Awal / Surplus Ditahan</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['surplus_ditahan'])."</td>"; ?></tr>
        <tr><td class="ind-1">Surplus (Defisit) Tahun Berjalan</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['surplus_berjalan'])."</td>"; ?></tr>
        <tr class="row-sub"><td>Total Aset Neto Tanpa Pembatasan</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['t_unrest'], true)."</td>"; ?></tr>
        
        <tr><td colspan="<?= count($periods)+1 ?>"><b>B. DENGAN PEMBATASAN DARI PEMBERI SUMBER DAYA</b></td></tr>
        <tr><td class="ind-1">Dana Terikat / Aset Neto Dengan Pembatasan</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['modal_rest'])."</td>"; ?></tr>
        <tr class="row-sub"><td>TOTAL ASET NETO</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['t_eq'], true)."</td>"; ?></tr>
        <tr class="row-grand"><td>TOTAL LIABILITAS DAN ASET NETO</td><?php foreach($report_data as $d) echo "<td>".fmtP($d['grand_pasiva'], true)."</td>"; ?></tr>
    </tbody>
</table>

<?php
$kota = $conf['ttd_kota'] ?? $app['city'] ?? 'Pontianak';
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'KEUANGAN' ORDER BY id ASC");
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
        echo "<td><div style='margin-top: 15mm;'><b>$name</b><br><span>{$sig['sign_position']}</span></div></td>";
    }
} else {
    echo "<td width='60%'></td><td width='40%'>$kota, ".date('d M Y', strtotime($conf['tgl_akhir']))."<br>Mengetahui/Menyetujui<br><b>Pimpinan Institusi</b><div style='margin-top: 15mm;'><b><u>....................................</u></b></div></td>";
}
echo "</tr></table>";
?>

</body>
</html>