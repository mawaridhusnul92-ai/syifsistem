<?php
/**
 * print_aktivitas_keuangan.php - OFFICIAL PRINT ENGINE
 * Versi: 130.5 (Sovereign Grand Master - Root Parent & Zero Balance Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Deskripsi: Cetak PDF HTML murni. Menampilkan akun Induk Besar (Root Parent) 
 * sesuai COA, dan tetap menampilkan Rp 0.
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

$raw_acc_info = $conn->query("SELECT kode_akun, kategori, is_restricted, parent_kode, is_group, nama_akun FROM syifa_akun WHERE is_active=1 AND (laporan_aktivitas = 1 OR akun_tipe_laporan IN ('OPERASIONAL','NON_OPERASIONAL')) ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

function getPointInTimeBalanceMap($conn, $date) {
    $map = []; $thn = (int)date('Y', strtotime($date)); $bln = (int)date('m', strtotime($date));
    $prev_thn = $thn; $prev_bln = $bln - 1; if($prev_bln == 0) { $prev_bln = 12; $prev_thn--; }
    $sql_acc = "SELECT kode_akun, saldo_normal, normal_balance, opening_balance FROM syifa_akun WHERE (laporan_aktivitas = 1 OR akun_tipe_laporan IN ('OPERASIONAL','NON_OPERASIONAL')) AND is_group = 0";
    $accounts = $conn->query($sql_acc); $acc_meta = [];
    while($a = $accounts->fetch_assoc()) {
        $map[$a['kode_akun']] = (double)$a['opening_balance']; 
        $sn_val = $a['saldo_normal'] ?? ''; $nb_val = $a['normal_balance'] ?? '';
        $is_kredit = ($sn_val == 'K' || strtoupper($nb_val) == 'KREDIT' || strtoupper($sn_val) == 'KREDIT');
        $acc_meta[$a['kode_akun']] = $is_kredit ? 'K' : 'D';
    }
    $res_snap = $conn->query("SELECT kode_akun, saldo FROM syifa_saldo_akun_eom WHERE tahun=$prev_thn AND bulan=$prev_bln AND is_valid=1");
    if ($res_snap) { while($r = $res_snap->fetch_assoc()) { if(isset($map[$r['kode_akun']])) $map[$r['kode_akun']] = (double)$r['saldo']; } }
    $start_of_month = "$thn-" . sprintf("%02d", $bln) . "-01 00:00:00"; $cutoff = "$date 23:59:59";
    if ($start_of_month <= $cutoff) {
        $sql_delta = "SELECT jd.kode_akun, SUM(jd.debit) as d, SUM(jd.kredit) as k FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE j.tgl_jurnal >= '$start_of_month' AND j.tgl_jurnal <= '$cutoff' AND j.is_deleted = 0 GROUP BY jd.kode_akun";
        $res_delta = $conn->query($sql_delta);
        if ($res_delta) { while($r = $res_delta->fetch_assoc()) { $kode = $r['kode_akun']; if(isset($acc_meta[$kode])) { $net = ($acc_meta[$kode] == 'D') ? ($r['d'] - $r['k']) : ($r['k'] - $r['d']); $map[$kode] += $net; } } }
    }
    return $map;
}

function buildBalanceMap($conn, $start, $end) {
    $date_prev = date('Y-m-d', strtotime("$start -1 day"));
    $map_end = getPointInTimeBalanceMap($conn, $end); $map_start = getPointInTimeBalanceMap($conn, $date_prev);
    $final_map = []; foreach($map_end as $kode => $val_end) { $val_start = $map_start[$kode] ?? 0; $final_map[$kode] = $val_end - $val_start; }
    return $final_map;
}

$rawCache = []; 
foreach($periods as $idx => $p){ $rawCache[$idx] = buildBalanceMap($conn, $p['s'], $p['e']); }

function fmtP($n, $b=false) {
    if ($n == 0) $f = "0"; else { $f = number_format(abs($n), 0, ',', '.'); if ($n < 0) $f = "($f)"; }
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
    <title>Cetak Aktivitas Laba Rugi</title>
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

<div class="judul">LAPORAN PENGHASILAN KOMPREHENSIF<br><span style="font-size:10pt; font-weight:normal;">Periode <?= date('d M Y', strtotime($conf['tgl_mulai'])) ?> s.d <?= date('d M Y', strtotime($conf['tgl_akhir'])) ?></span></div>

<table class="data">
    <thead><tr><th style="text-align: left; padding-left: 10px;">URAIAN PENDAPATAN & BEBAN</th><th width="100">CATATAN</th><?php foreach($periods as $p) echo "<th width='150'>".$p['label']."</th>"; ?></tr></thead>
    <tbody>
        <?php 
        $surplus_final = array_fill(0, count($periods), 0);
        $sects = [['label'=>'A. TANPA PEMBATASAN DARI PEMBERI SUMBER DAYA', 'res'=>0], ['label'=>'B. DENGAN PEMBATASAN DARI PEMBERI SUMBER DAYA', 'res'=>1]];

        // ROOT EXTRACTOR
        $root_parents = []; $child_map = [];
        foreach($raw_acc_info as $a) { if(empty($a['parent_kode'])) { $root_parents[$a['kode_akun']] = $a; $child_map[$a['kode_akun']] = $a['kode_akun']; } }
        for($i=0; $i<5; $i++) { foreach($raw_acc_info as $a) { if(!empty($a['parent_kode']) && isset($child_map[$a['parent_kode']])) { $child_map[$a['kode_akun']] = $child_map[$a['parent_kode']]; } } }
        foreach($raw_acc_info as $a) { if(!isset($child_map[$a['kode_akun']])) { $root_parents[$a['kode_akun']] = $a; $child_map[$a['kode_akun']] = $a['kode_akun']; } }

        foreach($sects as $s):
            $section_income = array_fill(0, count($periods), 0);
            $section_expense = array_fill(0, count($periods), 0);

            echo '<tr class="row-cat"><td colspan="'.(count($periods)+2).'">'.$s['label'].'</td></tr>';
            
            foreach(['Pendapatan', 'Beban'] as $type):
                echo '<tr><td colspan="'.(count($periods)+2).'"><b>'.$type.'</b></td></tr>';
                
                $roots_section = array_filter($root_parents, function($r) use ($type, $s) { return $r['kategori'] == $type && (int)$r['is_restricted'] == $s['res']; });
                
                foreach($roots_section as $rk => $rdata){
                    echo "<tr><td class='ind-1'>{$rdata['nama_akun']}</td><td style='text-align:center;'>{$rk}</td>";
                    foreach($periods as $idx => $p){
                        $sum = 0;
                        foreach($raw_acc_info as $child) { if(isset($child_map[$child['kode_akun']]) && $child_map[$child['kode_akun']] == $rk) { $sum += ($rawCache[$idx][$child['kode_akun']] ?? 0); } }
                        if ($type == 'Pendapatan') $section_income[$idx] += $sum; else $section_expense[$idx] += $sum;
                        echo "<td>".fmtP($sum)."</td>"; 
                    }
                    echo "</tr>";
                }
                
                echo '<tr class="row-sub"><td>Total '.$type.'</td><td></td>';
                foreach(($type=='Pendapatan'?$section_income:$section_expense) as $v) echo "<td>".fmtP($v, true)."</td>";
                echo '</tr>';
            endforeach;

            $res_0 = $section_income[0] - $section_expense[0];
            $label_dyn = ($res_0 < 0) ? "DEFISIT" : "SURPLUS";
            $label_clean = str_replace(['A. ', 'B. '], '', $s['label']);
            
            echo '<tr class="row-sub"><td>'.$label_dyn.' DARI '.$label_clean.'</td><td></td>';
            foreach($periods as $idx => $p) {
                $res_section = $section_income[$idx] - $section_expense[$idx];
                $surplus_final[$idx] += $res_section; 
                echo "<td>".fmtP($res_section, true)."</td>";
            }
            echo '</tr><tr><td colspan="'.(count($periods)+2).'" style="border:none; height:10px;"></td></tr>';
        endforeach;
        ?>
        
        <tr class="row-grand">
            <td>KENAIKAN / (PENURUNAN) ASET NETO</td><td></td>
            <?php foreach($surplus_final as $sf) { echo "<td>".fmtP($sf, true)."</td>"; } ?>
        </tr>
    </tbody>
</table>

<?php
$kota = $conf['ttd_kota'] ?? $app['city'] ?? 'Pontianak';
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'AKTIVITAS' ORDER BY id ASC");
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