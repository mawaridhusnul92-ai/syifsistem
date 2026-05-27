<?php
/**
 * print_honor.php - THE SUPREME DYNAMIC PDF PRINTER (PENGAJUAN)
 * Perbaikan: Menyesuaikan Kop dengan Profile Institusi dan TTD dengan Signature.
 * Urutan kolom (Bruto, Pajak, Netto) ditaruh di paling kanan secara presisi.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak: Silakan login terlebih dahulu."); }

$mode = $_GET['mode'] ?? 'pengajuan'; 
$gen_id = (int)($_GET['gen_id'] ?? 0);

if ($gen_id <= 0) die("<h3 style='padding: 50px;'>Parameter tidak valid.</h3>");

$sql = "SELECT d.*, g.nama_generate, g.periode_bulan, g.periode_tahun, g.nama_honorarium, g.periode_honor_teks,
        ds.nama as dosen_nama, ds.jabatan_fungsional as jabatan, ds.program_studi as default_prodi,
        t.custom_layout, t.nama_template
        FROM honor_generate_detail d
        JOIN honor_generate g ON d.generate_id = g.id
        JOIN dosen ds ON d.dosen_id = ds.id
        LEFT JOIN honor_template t ON g.template_id = t.id
        WHERE d.generate_id = $gen_id ORDER BY d.dosen_id ASC, d.id ASC";
$res = $conn->query($sql);

$matrix = [];
$dosen_order = []; // simpan urutan dosen agar nomor tetap konsisten
$dosen = [];
$t_bruto = 0; $t_pajak = 0;
$nama_honorarium_doc = '';
$periode_honor_teks_doc = '';

$nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
$layout_json = '';
$nama_template_doc = '';

while($r = $res->fetch_assoc()) {
    $nama_gen_clean = strtoupper($r['nama_generate']);
    // Kunci utama = dosen_id (bukan + md5 mata kuliah), agar semua sub-row 1 dosen = 1 nomor
    $dosen_key = $r['dosen_id'];
    // Sub-key per baris mata kuliah/prodi
    $sub_key = md5($r['mata_kuliah'].$r['prodi']);
    
    if(!isset($matrix[$nama_gen_clean][$dosen_key])) {
        $matrix[$nama_gen_clean][$dosen_key] = [
            'dosen_nama' => $r['dosen_nama'],
            'jabatan'    => $r['jabatan'],
            'periode'    => $nm_bln[$r['periode_bulan']] . ' ' . $r['periode_tahun'],
            'tot_bruto'  => 0, 'tot_pajak' => 0, 'tot_netto' => 0,
            'sub_rows'   => [] // array sub-baris per mata kuliah
        ];
    }
    
    if(!isset($matrix[$nama_gen_clean][$dosen_key]['sub_rows'][$sub_key])) {
        $matrix[$nama_gen_clean][$dosen_key]['sub_rows'][$sub_key] = [
            'dosen_nama'  => $r['dosen_nama'],
            'jabatan'     => $r['jabatan'],
            'prodi'       => $r['prodi'] ?: $r['default_prodi'],
            'mata_kuliah' => $r['mata_kuliah'],
            'komponen'    => [],
            'tot_bruto'   => 0, 'tot_pajak' => 0, 'tot_netto' => 0, 'pajak_pct' => $r['persen_pajak']
        ];
    }
    
    $matrix[$nama_gen_clean][$dosen_key]['sub_rows'][$sub_key]['komponen'][$r['rincian_komponen_id']] = [
        'qty' => $r['qty'], 'tarif' => $r['tarif'], 'jml' => $r['qty'] * $r['tarif']
    ];
    
    $matrix[$nama_gen_clean][$dosen_key]['sub_rows'][$sub_key]['tot_bruto'] += ($r['qty'] * $r['tarif']);
    $matrix[$nama_gen_clean][$dosen_key]['sub_rows'][$sub_key]['tot_pajak'] += $r['potongan_pajak'];
    $matrix[$nama_gen_clean][$dosen_key]['sub_rows'][$sub_key]['tot_netto'] += $r['honor_diterima'];
    
    // Akumulasi total per dosen
    $matrix[$nama_gen_clean][$dosen_key]['tot_bruto'] += ($r['qty'] * $r['tarif']);
    $matrix[$nama_gen_clean][$dosen_key]['tot_pajak'] += $r['potongan_pajak'];
    $matrix[$nama_gen_clean][$dosen_key]['tot_netto'] += $r['honor_diterima'];
    
    if (empty($dosen)) { $dosen = [ 'nama' => 'PENGELOLA KEUANGAN', 'periode' => $nm_bln[$r['periode_bulan']] . ' ' . $r['periode_tahun'] ]; }
    $layout_json = $r['custom_layout'] ?? '';
    $nama_template_doc = $r['nama_template'] ?? '';
    if (empty($nama_honorarium_doc)) $nama_honorarium_doc = $r['nama_honorarium'] ?? '';
    if (empty($periode_honor_teks_doc)) $periode_honor_teks_doc = $r['periode_honor_teks'] ?? '';
    
    $t_bruto += (double)$r['total_honor']; $t_pajak += (double)$r['potongan_pajak'];
}
$t_netto = $t_bruto - $t_pajak;

$master_tarif = [];
$res_tarif = $conn->query("SELECT id, besaran, rincian, satuan FROM honor_komponen_detail");
if ($res_tarif) { while ($row_t = $res_tarif->fetch_assoc()) { $master_tarif[$row_t['id']] = $row_t; } }

// PARSER LAYOUT CERDAS
$layout_cols = json_decode($layout_json, true) ?: [];
$teks_cols = []; $horiz_groups = []; $vert_group_info = ['name' => '', 'header' => '', 'items' => []];

foreach($layout_cols as $c) {
    if ($c['type'] == 'teks') { $teks_cols[] = $c; } 
    elseif (isset($c['group_type']) && $c['group_type'] == 'group_vertical') {
        $vert_group_info['name'] = $c['group'];
        $vert_group_info['header'] = isset($c['group_header']) ? trim($c['group_header']) : 'URAIAN';
        $vert_group_info['items'][] = $c;
    } 
    else {
        $grpName = $c['group'] ?? 'KOMPONEN HONOR';
        $horiz_groups[$grpName][] = $c;
    }
}

// MEMBANGUN HEADER THEAD
// Kolom NO selalu ada
$th_row1 = "<th rowspan='2' width='3%'>No</th>";
// Kolom TENAGA PENGAJAR selalu ada (merge cell utama per dosen)
$th_row1 .= "<th rowspan='2' style='min-width:160px; text-align:left;'>TENAGA PENGAJAR</th>";
$th_row2 = "";
$col_count = 2; // No + TENAGA PENGAJAR

// Kolom teks lainnya (selain dosen_nama, karena dosen_nama sudah dirender khusus)
foreach ($teks_cols as $t) {
    if ($t['source'] === 'dosen_nama') continue; // sudah ada di kolom TENAGA PENGAJAR
    $th_row1 .= "<th rowspan='2'>".strtoupper($t['label'])."</th>"; $col_count++;
}

foreach ($horiz_groups as $gName => $items) {
    $cs = count($items) * 3;
    $th_row1 .= "<th colspan='$cs' style='background-color:#ffc107 !important; color:#000;'>".strtoupper($gName)."</th>";
    foreach($items as $it) {
        $rid_it  = (int)($it['id_rincian'] ?? 0);
        $sat_it  = !empty($master_tarif[$rid_it]['satuan']) ? $master_tarif[$rid_it]['satuan'] : 'Satuan';
        $th_row2 .= "<th>".strtoupper($it['label'])."<br><small style='font-weight:normal;font-size:8px;'>($sat_it)</small></th>";
        $th_row2 .= "<th>Rp/$sat_it</th><th>JUMLAH</th>";
        $col_count += 3;
    }
}

if (count($vert_group_info['items']) > 0) {
    $vHeader = strtoupper($vert_group_info['header']);
    if ($vHeader !== '') {
        $th_row1 .= "<th rowspan='2'>{$vHeader}</th>";
    } else {
        $th_row1 .= "<th rowspan='2'></th>";
    }
    
    $th_row1 .= "<th colspan='3' style='background-color:#ffc107 !important; color:#000;'>".strtoupper($vert_group_info['name'])."</th>";
    // Ambil satuan dari item pertama grup vertikal
    $first_vr_id = (int)($vert_group_info['items'][0]['id_rincian'] ?? 0);
    $first_vr_sat = !empty($master_tarif[$first_vr_id]['satuan']) ? $master_tarif[$first_vr_id]['satuan'] : 'Satuan';
    $th_row2 .= "<th>Jml ($first_vr_sat)</th><th>Rp/$first_vr_sat</th><th>JUMLAH</th>";
    $col_count += 4;
}

$th_row1 .= "<th rowspan='2' width='10%'>TOTAL BRUTO</th><th rowspan='2' width='6%'>POT. PAJAK</th><th rowspan='2' width='10%' style='background-color:#00ff00 !important; color:#000;'>HONOR DITERIMA</th>";

// KOP SURAT PROFILE
$profile   = @$conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$inst_name = $profile['institution_name'] ?? 'STIKES YARSI PONTIANAK';
$inst_addr = $profile['address'] ?? 'Jl. Panglima A\'im No. 2, Pontianak, Kalimantan Barat';
$logo_img = '';
if (!empty($profile['logo']) && file_exists('assets/img/' . $profile['logo'])) {
    $logo_img = "<img src='assets/img/" . $profile['logo'] . "' style='width:60px; height:auto;'>";
}

// TANDA TANGAN DINAMIS DARI DB
$res_sig = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'LAPORAN_HONOR' ORDER BY id ASC");
$signatures = [];
if ($res_sig) {
    while($r = $res_sig->fetch_assoc()) $signatures[] = $r;
}
if (empty($signatures)) {
    $signatures = [
        ['sign_role' => 'Menyetujui/Mengetahui,', 'sign_position' => 'Ketua / Direktur', 'sign_name' => '_______________________'],
        ['sign_role' => 'Pontianak, '.date('d F Y').'<br>Yang Mengajukan,', 'sign_position' => 'Bendahara', 'sign_name' => $dosen['nama'] ?? '____________________']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Laporan Rekap</title>
    <style>
        @page { size: A4 landscape; margin: 7mm; }
        body { font-family: 'Arial', sans-serif; font-size: 8pt; color: #000; margin: 0; background: #525659; }
        .a4-landscape { background: #fff; width: 281mm; min-height: 190mm; margin: 0 auto; padding: 6mm 8mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); box-sizing: border-box; overflow: hidden; }
        
        .kop { display:flex; align-items:center; border-bottom:3px solid #000; padding-bottom:4px; margin-bottom:5px; }
        .kop-logo { width:52px; margin-right:10px; flex-shrink:0; text-align:center; }
        .kop-text { flex:1; text-align:center; }
        .kop-text .kop-nama { font-size:13pt; font-weight:900; letter-spacing:0.5px; margin-bottom:2px; text-transform: uppercase; }
        .kop-text .kop-judul-honor { font-size:11pt; font-weight:700; margin-top:3px; }
        .kop-text .kop-periode { font-size:9pt; margin-top:2px; }

        .tbl-data { width: 100%; border-collapse: collapse; margin-bottom: 4mm; font-size: 7pt; table-layout: auto; }
        .tbl-data th, .tbl-data td { border: 0.5px solid #555; padding: 2px 3px; vertical-align: middle; word-break: break-word; }
        .tbl-data th { background-color: #f1f5f9 !important; text-transform: uppercase; font-size: 6.5pt; text-align: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold; }
        .group-title { font-size: 9pt; font-weight: bold; text-transform: uppercase; margin-bottom: 1mm; margin-top: 3mm; color: #000; }
        .text-center { text-align: center; } .text-end { text-align: right; } .text-start { text-align: left; } .fw-bold { font-weight: bold; }
        
        /* ─── TANDA TANGAN ─── */
        .ttd-section { display:flex; justify-content:space-around; margin-top:8px; }
        .ttd-box { text-align:center; width: 30%; }
        .ttd-box .ttd-lbl { font-weight:700; font-size:8pt; margin-bottom:2px; line-height:1.4; height: 22px; }
        .ttd-box .ttd-name { font-weight:900; font-size:8pt; margin-top: 50px; text-decoration: underline; }

        @media print { 
            body { background: #fff; padding: 0; margin: 0; font-size: 7pt; } 
            .a4-landscape { box-shadow: none; margin: 0; width: 100%; height: auto; padding: 0; } 
            .no-print { display: none !important; } 
            .tbl-data { font-size: 6.5pt; }
            .tbl-data th { font-size: 6pt; }
            .tbl-data th, .tbl-data td { padding: 1.5px 2px; }
        }
    </style>
</head>
<body onload="setTimeout(window.print, 500)">
    <div style="text-align: center; margin: 20px 0;" class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #0d6efd; color: #fff; border: none; border-radius: 5px; font-weight: bold;">🖨️ CETAK DOKUMEN REKAP</button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #dc3545; color: #fff; border: none; border-radius: 5px; font-weight: bold; margin-left: 10px;">TUTUP</button>
    </div>

    <div class="a4-landscape">
        <!-- KOP -->
        <div class="kop">
            <div class="kop-logo"><?= $logo_img ?></div>
            <div class="kop-text">
                <div class="kop-nama"><?= htmlspecialchars($inst_name) ?></div>
                <?php if (!empty($nama_honorarium_doc)): ?>
                <div class="kop-judul-honor"><?= htmlspecialchars($nama_honorarium_doc) ?></div>
                <?php endif; ?>
                <?php if (!empty($periode_honor_teks_doc)): ?>
                <div class="kop-periode"><?= htmlspecialchars($periode_honor_teks_doc) ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php foreach($matrix as $nama_gen => $items): ?>
            <table class="tbl-data">
                <thead>
                    <tr><?= $th_row1 ?></tr>
                    <?php if(!empty($th_row2)) echo "<tr>$th_row2</tr>"; ?>
                </thead>
                <tbody>
                    <?php 
                    $sub_bruto = 0; $sub_pajak = 0; $sub_netto = 0; $n = 1;
                    foreach($items as $dosen_key => $dosen_item):
                        $sub_bruto += (double)$dosen_item['tot_bruto']; 
                        $sub_pajak += (double)$dosen_item['tot_pajak']; 
                        $sub_netto += (double)$dosen_item['tot_netto'];
                        
                        $sub_rows     = array_values($dosen_item['sub_rows']);
                        $sub_row_cnt  = count($sub_rows);
                        if ($sub_row_cnt < 1) $sub_row_cnt = 1;
                        
                        $vItems  = $vert_group_info['items'];
                        $vCount  = count($vItems);
                        $has_vert = $vCount > 0;

                        // Hitung total rowspan untuk kolom NO & TENAGA PENGAJAR
                        // Setiap sub_row punya: $vCount baris vertikal (+1 baris TOTAL jika vertikal)
                        // atau 1 baris jika non-vertikal
                        $total_rowspan_dosen = 0;
                        foreach ($sub_rows as $sr_tmp) {
                            if ($has_vert) {
                                $total_rowspan_dosen += $vCount + 1; // baris vertikal + baris TOTAL
                            } else {
                                $total_rowspan_dosen += 1;
                            }
                        }
                        if ($total_rowspan_dosen < 1) $total_rowspan_dosen = 1;

                        $first_sub_row = true;
                        foreach ($sub_rows as $sr_idx => $i):

                            $rs = $has_vert ? $vCount : 1;

                            for ($vi = 0; $vi < $rs; $vi++) {
                                echo "<tr>";
                                
                                // Kolom NO — hanya di baris pertama sub_row pertama, rowspan semua baris dosen ini
                                if ($first_sub_row && $vi === 0) {
                                    $rowspan_no = $total_rowspan_dosen;
                                    echo "<td class='text-center fw-bold' rowspan='{$rowspan_no}' style='vertical-align:top; padding-top:8px;'>" . $n++ . "</td>";
                                }
                                
                                // CETAK BARIS PERTAMA dari setiap sub_row (kolom Teks & Horizontal)
                                if ($vi === 0) {
                                    $rs_sub = $has_vert ? ($vCount + 1) : 1; // rowspan dalam 1 sub_row

                                    // Kolom TENAGA PENGAJAR — hanya di baris pertama sub_row pertama, rowspan semua baris dosen
                                    if ($first_sub_row) {
                                        // Selalu render kolom TENAGA PENGAJAR (merge cell per dosen)
                                        echo "<td rowspan='{$total_rowspan_dosen}' class='text-start fw-bold' style='vertical-align:top; padding-top:8px;'>" . htmlspecialchars($dosen_item['dosen_nama']) . "</td>";
                                    }
                                    
                                    // Kolom teks lainnya (selain dosen_nama) — rowspan dalam sub_row ini saja
                                    foreach ($teks_cols as $t) {
                                        if ($t['source'] === 'dosen_nama') continue; // sudah dirender di atas
                                        $src = $t['source'];
                                        if ($src === 'prodi')        $val = htmlspecialchars($i['prodi'] ?? '-');
                                        elseif ($src === 'mata_kuliah') $val = htmlspecialchars($i['mata_kuliah'] ?? '-');
                                        elseif ($src === 'jabatan')     $val = htmlspecialchars($i['jabatan'] ?? '-');
                                        else $val = htmlspecialchars($i[$src] ?? '-');
                                        echo "<td rowspan='{$rs_sub}' class='text-center'>$val</td>";
                                    }
                                    
                                    // Kolom horizontal
                                    foreach ($horiz_groups as $gName => $h_items) {
                                        foreach($h_items as $h) {
                                            $rid = $h['id_rincian'];
                                            $q   = $i['komponen'][$rid]['qty']   ?? 0;
                                            $trf = $i['komponen'][$rid]['tarif'] ?? 0;
                                            if ($trf == 0 && isset($master_tarif[$rid])) $trf = $master_tarif[$rid]['besaran'];
                                            $jml = $q * $trf;
                                            // Tampilkan qty apa adanya tanpa tambah desimal otomatis, tanpa teks satuan
                                            if ($q > 0) {
                                                $qty_disp = (floor($q) == $q) ? number_format($q, 0, ',', '.') : rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',');
                                            } else {
                                                $qty_disp = '-';
                                            }
                                            
                                            echo "<td rowspan='{$rs_sub}' class='text-center fw-bold'>$qty_disp</td>";
                                            echo "<td rowspan='{$rs_sub}' class='text-center'>".($trf>0?number_format($trf,0,',','.'):'-')."</td>";
                                            echo "<td rowspan='{$rs_sub}' class='text-center fw-bold'>".($jml>0?'Rp '.number_format($jml,0,',','.'):'-')."</td>";
                                        }
                                    }
                                }

                                // CETAK ITEM VERTIKAL
                                if ($vCount > 0 && isset($vItems[$vi])) {
                                    $v   = $vItems[$vi];
                                    $rid = $v['id_rincian'];
                                    $q   = $i['komponen'][$rid]['qty']   ?? 0;
                                    $trf = $i['komponen'][$rid]['tarif'] ?? 0;
                                    if ($trf == 0 && isset($master_tarif[$rid])) $trf = $master_tarif[$rid]['besaran'];
                                    $jml    = $q * $trf;
                                    // Tampilkan qty apa adanya tanpa tambah desimal otomatis, tanpa teks satuan
                                    if ($q > 0) {
                                        $qty_disp_v = (floor($q) == $q) ? number_format($q, 0, ',', '.') : rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',');
                                    } else {
                                        $qty_disp_v = '-';
                                    }
                                    
                                    echo "<td>".htmlspecialchars($v['label'])."</td>";
                                    echo "<td class='text-center fw-bold'>$qty_disp_v</td>";
                                    echo "<td class='text-center'>".($trf>0?number_format($trf,0,',','.'):'-')."</td>";
                                    echo "<td class='text-center fw-bold'>".($jml>0?'Rp '.number_format($jml,0,',','.'):'-')."</td>";

                                    $pajak_pct_row = (float)($i['pajak_pct'] ?? 0);
                                    $item_bruto    = $jml;
                                    $item_pajak    = round($item_bruto * $pajak_pct_row / 100);
                                    $item_netto    = $item_bruto - $item_pajak;
                                    echo "<td class='text-center fw-bold'>".($item_bruto>0?'Rp '.number_format($item_bruto,0,',','.'):'-')."</td>";
                                    echo "<td class='text-center text-danger fw-bold'>".($item_pajak>0?'Rp '.number_format($item_pajak,0,',','.'):'-')."</td>";
                                    echo "<td class='text-center fw-bold' style='background-color: #ccffcc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>".($item_netto>0?'Rp '.number_format($item_netto,0,',','.'):'-')."</td>";

                                } else if ($vCount === 0) {
                                    // tidak ada grup vertikal — skip
                                } else {
                                    echo "<td></td><td></td><td></td><td></td>";
                                    echo "<td></td><td></td><td></td>";
                                }

                                // CETAK TOTAL (Bruto, Pajak, Netto) — hanya untuk layout NON-vertikal, baris pertama sub_row
                                if (!$has_vert && $vi === 0) {
                                    echo "<td rowspan='1' class='text-center fw-bold'>Rp ".number_format($i['tot_bruto'],0,',','.')."</td>";
                                    echo "<td rowspan='1' class='text-center text-danger fw-bold'>Rp ".number_format($i['tot_pajak'],0,',','.')."</td>";
                                    echo "<td rowspan='1' class='text-center fw-bold' style='background-color: #ccffcc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>Rp ".number_format($i['tot_netto'],0,',','.')."</td>";
                                }
                                
                                echo "</tr>";
                            }

                            // Baris TOTAL per sub_row untuk layout vertikal
                            if ($has_vert) {
                                echo "<tr style='background-color: #e3f2fd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>";
                                echo "<td class='fw-bold text-center'>TOTAL</td>";
                                echo "<td class='text-center fw-bold'>-</td>";
                                echo "<td class='text-center fw-bold'>-</td>";
                                echo "<td class='text-center fw-bold'>-</td>";
                                echo "<td class='text-center fw-bold'>Rp ".number_format($i['tot_bruto'],0,',','.')."</td>";
                                echo "<td class='text-center text-danger fw-bold'>Rp ".number_format($i['tot_pajak'],0,',','.')."</td>";
                                echo "<td class='text-center fw-bold' style='background-color: #00ff00 !important; color:#000; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>Rp ".number_format($i['tot_netto'],0,',','.')."</td>";
                                echo "</tr>";
                            }

                            $first_sub_row = false;
                        endforeach; // end sub_rows
                    endforeach; // end dosen ?>
                </tbody>
                <tfoot class="fw-bold" style="background-color: #f8fafc; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                    <tr>
                        <td colspan="<?= $col_count ?>" class="text-end">SUBTOTAL <?= htmlspecialchars($nama_gen) ?></td>
                        <td class="text-center">Rp <?= number_format($sub_bruto, 0, ',', '.') ?></td>
                        <td class="text-center text-danger">Rp <?= number_format($sub_pajak, 0, ',', '.') ?></td>
                        <td class="text-center" style="background-color: #00ff00 !important; color:#000; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Rp <?= number_format($sub_netto, 0, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endforeach; ?>

        <table class="tbl-data" style="margin-top: 10mm; border: 2px solid #000;">
            <tr>
                <td class="text-end fw-bold" style="font-size: 13pt; padding: 10px;">TOTAL KESELURUHAN PENGAJUAN HONOR (NETTO)</td>
                <td class="text-end fw-bold" style="font-size: 15pt; width: 250px; background-color: #00ff00 !important; color: #000; padding: 10px; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Rp <?= number_format($t_netto, 0, ',', '.') ?></td>
            </tr>
        </table>
        
        <div class="ttd-section">
            <?php foreach($signatures as $sig): 
                $nam = $sig['sign_name'];
                if(stripos($sig['sign_role'], 'Mengajukan') !== false || stripos($sig['sign_position'], 'Bendahara') !== false || strpos($nam, '[NAMA_DOSEN]') !== false) {
                    $nam = $dosen['nama'] ?? '____________________';
                }
            ?>
            <div class="ttd-box">
                <div class="ttd-lbl"><?= htmlspecialchars_decode($sig['sign_role']) ?><br><?= htmlspecialchars($sig['sign_position']) ?></div>
                <div class="ttd-name"><?= htmlspecialchars($nam) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>