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

$sql = "SELECT d.*, g.nama_generate, g.periode_bulan, g.periode_tahun,
        ds.nama as dosen_nama, ds.jabatan_fungsional as jabatan, ds.program_studi as default_prodi,
        t.custom_layout, t.nama_template
        FROM honor_generate_detail d
        JOIN honor_generate g ON d.generate_id = g.id
        JOIN dosen ds ON d.dosen_id = ds.id
        LEFT JOIN honor_template t ON g.template_id = t.id
        WHERE d.generate_id = $gen_id ORDER BY d.dosen_id ASC, d.id ASC";
$res = $conn->query($sql);

$matrix = [];
$dosen = [];
$t_bruto = 0; $t_pajak = 0;

$nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
$layout_json = '';
$nama_template_doc = '';

while($r = $res->fetch_assoc()) {
    $nama_gen_clean = strtoupper($r['nama_generate']);
    $key = $r['dosen_id'] . '_' . md5($r['mata_kuliah'].$r['prodi']);
    
    if(!isset($matrix[$nama_gen_clean][$key])) {
        $matrix[$nama_gen_clean][$key] = [
            'dosen_nama' => $r['dosen_nama'],
            'jabatan' => $r['jabatan'],
            'prodi' => $r['prodi'] ?: $r['default_prodi'],
            'mata_kuliah' => $r['mata_kuliah'],
            'periode' => $nm_bln[$r['periode_bulan']] . ' ' . $r['periode_tahun'],
            'komponen' => [],
            'tot_bruto' => 0, 'tot_pajak' => 0, 'tot_netto' => 0, 'pajak_pct' => $r['persen_pajak']
        ];
    }
    
    $matrix[$nama_gen_clean][$key]['komponen'][$r['rincian_komponen_id']] = [
        'qty' => $r['qty'], 'tarif' => $r['tarif'], 'jml' => $r['qty'] * $r['tarif']
    ];
    
    $matrix[$nama_gen_clean][$key]['tot_bruto'] += ($r['qty'] * $r['tarif']);
    $matrix[$nama_gen_clean][$key]['tot_pajak'] += $r['potongan_pajak'];
    $matrix[$nama_gen_clean][$key]['tot_netto'] += $r['honor_diterima'];
    
    if (empty($dosen)) { $dosen = [ 'nama' => 'PENGELOLA KEUANGAN', 'periode' => $nm_bln[$r['periode_bulan']] . ' ' . $r['periode_tahun'] ]; }
    $layout_json = $r['custom_layout'] ?? '';
    $nama_template_doc = $r['nama_template'] ?? '';
    
    $t_bruto += (double)$r['total_honor']; $t_pajak += (double)$r['potongan_pajak'];
}
$t_netto = $t_bruto - $t_pajak;

// AMBIL nama_honor dan periode_semester langsung dari DB (aman jika kolom belum ada)
$nama_honor_doc      = '';
$periode_semester_doc = '';
$res_gen_info = @$conn->query("SELECT nama_honor, periode_semester FROM honor_generate WHERE id=$gen_id LIMIT 1");
if ($res_gen_info) {
    $rgi = $res_gen_info->fetch_assoc();
    $nama_honor_doc      = $rgi['nama_honor'] ?? '';
    $periode_semester_doc = $rgi['periode_semester'] ?? '';
}

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
$th_row1 = "<th rowspan='2' width='3%'>No</th>";
$th_row2 = "";
$col_count = 1;

foreach ($teks_cols as $t) { $th_row1 .= "<th rowspan='2'>".strtoupper($t['label'])."</th>"; $col_count++; }

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
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 10pt; color: #000; margin: 0; background: #525659; }
        .a4-landscape { background: #fff; width: 297mm; min-height: 210mm; margin: 0 auto; padding: 15mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); box-sizing: border-box; }
        
        .kop { display:flex; align-items:center; border-bottom:3px solid #000; padding-bottom:6px; margin-bottom:8px; }
        .kop-logo { width:65px; margin-right:12px; flex-shrink:0; text-align:center;}
        .kop-text { flex:1; text-align:center; }
        .kop-text .kop-nama { font-size:16pt; font-weight:900; letter-spacing:1px; margin-bottom:4px; text-transform: uppercase; }
        .kop-text .kop-alamat { font-size:11pt; line-height:1.5; }

        .doc-sub { text-align: left; font-size: 11pt; margin-bottom: 5mm; }
        .tbl-data { width: 100%; border-collapse: collapse; margin-bottom: 8mm; font-size: 9pt; }
        .tbl-data th, .tbl-data td { border: 1px solid #000; padding: 6px; vertical-align: middle; }
        .tbl-data th { background-color: #f1f5f9 !important; text-transform: uppercase; font-size: 8pt; text-align: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold; }
        .group-title { font-size: 11pt; font-weight: bold; text-transform: uppercase; margin-bottom: 2mm; margin-top: 5mm; color: #000;}
        .text-center { text-align: center; } .text-end { text-align: right; } .text-start { text-align: left; } .fw-bold { font-weight: bold; }
        
        /* ─── TANDA TANGAN ─── */
        .ttd-section { display:flex; justify-content:space-around; margin-top:15px; }
        .ttd-box { text-align:center; width: 30%; }
        .ttd-box .ttd-lbl { font-weight:700; font-size:11pt; margin-bottom:2px; line-height:1.4; height: 30px; }
        .ttd-box .ttd-name { font-weight:900; font-size:11pt; margin-top: 70px; text-decoration: underline;}

        @media print { body { background: #fff; padding: 0; margin: 0; } .a4-landscape { box-shadow: none; margin: 0; width: 100%; height: auto; padding: 0; } .no-print { display: none !important; } }
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
                <div class="kop-nama"><?= $inst_name ?></div>
                <?php if (!empty($nama_honor_doc)): ?>
                <div style="font-size:13pt; font-weight:900; margin-top:4px; text-transform:uppercase;"><?= htmlspecialchars($nama_honor_doc) ?></div>
                <?php endif; ?>
                <?php if (!empty($periode_semester_doc)): ?>
                <div style="font-size:11pt; font-weight:700; margin-top:2px;">Semester: <?= htmlspecialchars($periode_semester_doc) ?></div>
                <?php endif; ?>
                <div class="kop-alamat" style="font-size:10pt; margin-top:3px;"><?= htmlspecialchars($inst_addr) ?></div>
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
                    foreach($items as $i): 
                        $sub_bruto += (double)$i['tot_bruto']; $sub_pajak += (double)$i['tot_pajak']; $sub_netto += (double)$i['tot_netto'];
                        
                        // FIX: $vItems harus ARRAY bukan integer count()
                        $vItems = $vert_group_info['items'];
                        $vCount = count($vItems);
                        $rs = $vCount > 0 ? $vCount : 1;
                        $has_vert = $vCount > 0;

                        for ($vi = 0; $vi < $rs; $vi++) {
                            echo "<tr>";
                            
                            // CETAK BARIS PERTAMA (Teks & Horizontal Cols)
                            if ($vi === 0) {
                                echo "<td class='text-center' rowspan='".($has_vert ? ($rs + 1) : $rs)."'>" . $n++ . "</td>";
                                
                                foreach ($teks_cols as $t) {
                                    // FIX: map source ke field yang benar di matrix $i
                                    $src = $t['source'];
                                    if ($src === 'prodi')        $val = htmlspecialchars($i['prodi'] ?? '-');
                                    elseif ($src === 'mata_kuliah') $val = htmlspecialchars($i['mata_kuliah'] ?? '-');
                                    elseif ($src === 'dosen_nama')  $val = htmlspecialchars($i['dosen_nama'] ?? '-');
                                    elseif ($src === 'jabatan')     $val = htmlspecialchars($i['jabatan'] ?? '-');
                                    else $val = htmlspecialchars($i[$src] ?? '-');
                                    echo "<td rowspan='".($has_vert ? ($rs + 1) : $rs)."' class='text-center'>$val</td>";
                                }
                                
                                foreach ($horiz_groups as $gName => $h_items) {
                                    foreach($h_items as $h) {
                                        $rid = $h['id_rincian'];
                                        $q = $i['komponen'][$rid]['qty'] ?? 0;
                                        $trf = $i['komponen'][$rid]['tarif'] ?? 0;
                                        if ($trf == 0 && isset($master_tarif[$rid])) $trf = $master_tarif[$rid]['besaran'];
                                        $jml = $q * $trf;
                                        $sat = !empty($master_tarif[$rid]['satuan']) ? $master_tarif[$rid]['satuan'] : '';
                                        $qty_disp = ($q>0) ? $q . ($sat ? '<br><small style="font-weight:normal;font-size:8px;color:#666;">'.$sat.'</small>' : '') : '-';
                                        
                                        echo "<td rowspan='".($has_vert ? ($rs + 1) : $rs)."' class='text-center fw-bold'>$qty_disp</td>";
                                        echo "<td rowspan='".($has_vert ? ($rs + 1) : $rs)."' class='text-end'>".($trf>0?number_format($trf,0,',','.'):'-')."</td>";
                                        echo "<td rowspan='".($has_vert ? ($rs + 1) : $rs)."' class='text-end fw-bold'>".($jml>0?number_format($jml,0,',','.'):'-')."</td>";
                                    }
                                }
                            }

                            // CETAK ITEM VERTIKAL — FIX: cek array $vItems bukan integer
                            if ($vCount > 0 && isset($vItems[$vi])) {
                                $v = $vItems[$vi];
                                $rid = $v['id_rincian'];
                                $q = $i['komponen'][$rid]['qty'] ?? 0;
                                $trf = $i['komponen'][$rid]['tarif'] ?? 0;
                                if ($trf == 0 && isset($master_tarif[$rid])) $trf = $master_tarif[$rid]['besaran'];
                                $jml = $q * $trf;
                                $sat_v = !empty($master_tarif[$rid]['satuan']) ? $master_tarif[$rid]['satuan'] : '';
                                $qty_disp_v = ($q>0) ? $q . ($sat_v ? '<br><small style="font-weight:normal;font-size:8px;color:#666;">'.$sat_v.'</small>' : '') : '-';
                                
                                echo "<td>".htmlspecialchars($v['label'])."</td>";
                                echo "<td class='text-center fw-bold'>$qty_disp_v</td>";
                                echo "<td class='text-end'>".($trf>0?number_format($trf,0,',','.'):'-')."</td>";
                                echo "<td class='text-end fw-bold'>".($jml>0?number_format($jml,0,',','.'):'-')."</td>";

                                // Untuk layout vertikal: tampilkan bruto, pajak, netto per baris uraian
                                if ($has_vert) {
                                    $pajak_pct_row = (float)($i['pajak_pct'] ?? $i['persen_pajak'] ?? 0);
                                    $item_bruto = $jml;
                                    $item_pajak = round($item_bruto * $pajak_pct_row / 100);
                                    $item_netto = $item_bruto - $item_pajak;
                                    echo "<td class='text-end fw-bold'>".($item_bruto>0?number_format($item_bruto,0,',','.'):'-')."</td>";
                                    echo "<td class='text-center text-danger fw-bold'>".($item_pajak>0?'Rp '.number_format($item_pajak,0,',','.'):'-')."</td>";
                                    echo "<td class='text-end fw-bold' style='background-color: #ccffcc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>".($item_netto>0?number_format($item_netto,0,',','.'):'-')."</td>";
                                }
                            } else if ($vCount === 0) {
                                // tidak ada grup vertikal — skip
                            } else {
                                echo "<td></td><td></td><td></td><td></td>";
                                if ($has_vert) {
                                    echo "<td></td><td></td><td></td>";
                                }
                            }

                            // CETAK TOTAL (Bruto, Pajak, Netto) — hanya untuk layout NON-vertikal
                            if (!$has_vert && $vi === 0) {
                                echo "<td rowspan='$rs' class='text-end fw-bold'>".number_format($i['tot_bruto'],0,',','.')."</td>";
                                echo "<td rowspan='$rs' class='text-center text-danger fw-bold'>Rp ".number_format($i['tot_pajak'],0,',','.')."</td>";
                                echo "<td rowspan='$rs' class='text-end fw-bold' style='background-color: #ccffcc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>".number_format($i['tot_netto'],0,',','.')."</td>";
                            }
                            
                            echo "</tr>";
                        }

                        // Untuk layout vertikal: tambahkan baris TOTAL di bawah semua item vertikal
                        if ($has_vert) {
                            echo "<tr style='background-color: #e3f2fd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>";
                            echo "<td class='fw-bold text-center'>TOTAL</td>";
                            echo "<td class='text-center fw-bold'>-</td>";
                            echo "<td class='text-end fw-bold'>-</td>";
                            echo "<td class='text-end fw-bold'>-</td>";
                            echo "<td class='text-end fw-bold'>".number_format($i['tot_bruto'],0,',','.')."</td>";
                            echo "<td class='text-center text-danger fw-bold'>Rp ".number_format($i['tot_pajak'],0,',','.')."</td>";
                            echo "<td class='text-end fw-bold' style='background-color: #00ff00 !important; color:#000; -webkit-print-color-adjust: exact; print-color-adjust: exact;'>".number_format($i['tot_netto'],0,',','.')."</td>";
                            echo "</tr>";
                        }
                    endforeach; ?>
                </tbody>
                <tfoot class="fw-bold" style="background-color: #f8fafc; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                    <tr>
                        <td colspan="<?= $col_count ?>" class="text-end">SUBTOTAL <?= htmlspecialchars($nama_gen) ?></td>
                        <td class="text-end"><?= number_format($sub_bruto, 0, ',', '.') ?></td>
                        <td class="text-end text-danger">- <?= number_format($sub_pajak, 0, ',', '.') ?></td>
                        <td class="text-end" style="background-color: #00ff00 !important; color:#000; -webkit-print-color-adjust: exact; print-color-adjust: exact;">Rp <?= number_format($sub_netto, 0, ',', '.') ?></td>
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