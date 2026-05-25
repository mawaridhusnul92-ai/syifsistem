<?php
/**
 * print_perubahan_aset.php - SUPREME PRINT ENGINE (PERUBAHAN ASET)
 * Versi: 3.0 (Grand Master - Precise Layout Edition)
 * Perbaikan: Rincian Item Aset (Granular), Kop Resmi Institusi, & Sinkronisasi Saldo LTD.
 * Deskripsi: NBV = (Perolehan Awal + CAPEX) - (Akumulasi Awal + Penyusutan Periode).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("ID Laporan tidak valid.");

// 1. DATA MASTER & CONFIG
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $id")->fetch_assoc();
if (!$conf) die("Laporan tidak ditemukan.");

$main_start = $conf['tgl_mulai'];
$main_end   = $conf['tgl_akhir'];

// --- CALCULATION HELPERS (Consistent with v13.0 UI) ---

function getSingleAssetBalPrint($asset_id, $date, $conn) {
    $sql_gross = "SELECT (purchase_value + COALESCE((SELECT SUM(nilai_penambahan) FROM asset_improvements WHERE asset_id=$asset_id AND tanggal <= '$date'), 0)) as total_bruto FROM assets WHERE id = $asset_id";
    $res_gross = $conn->query($sql_gross)->fetch_assoc();
    $sql_akum = "SELECT (COALESCE(residual_value, 0) + COALESCE((SELECT SUM(nilai_susut) FROM asset_depreciation ad WHERE ad.asset_id = $asset_id AND STR_TO_DATE(CONCAT(ad.periode_tahun, '-', LPAD(ad.periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') <= '$date'), 0)) as total_akum FROM assets WHERE id = $asset_id";
    $res_akum = $conn->query($sql_akum)->fetch_assoc();
    return ['bruto' => (double)($res_gross['total_bruto'] ?? 0), 'akum'  => (double)($res_akum['total_akum'] ?? 0)];
}

function getSingleAssetActPrint($asset_id, $s, $e, $conn) {
    $sql_add = "SELECT SUM(nilai_penambahan) as capex FROM asset_improvements WHERE asset_id = $asset_id AND tanggal BETWEEN '$s' AND '$e'";
    $sql_depr = "SELECT SUM(nilai_susut) as depr FROM asset_depreciation WHERE asset_id = $asset_id AND STR_TO_DATE(CONCAT(periode_tahun, '-', LPAD(periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') BETWEEN '$s' AND '$e'";
    return ['add' => (double)($conn->query($sql_add)->fetch_assoc()['capex'] ?? 0), 'depr' => (double)($conn->query($sql_depr)->fetch_assoc()['depr'] ?? 0)];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak_Perubahan_Aset_<?= $id ?></title>
    <style>
        @page { size: A4 landscape; margin: 10mm 15mm; }
        body { font-family: 'Arial', sans-serif; font-size: 8.5pt; color: #000; line-height: 1.4; margin: 0; padding: 0; }
        
        /* Layout Kop */
        .kop-table { width: 100%; border-bottom: 2.5pt solid #000; margin-bottom: 15px; }
        .inst-name { font-size: 14pt; font-weight: 900; text-transform: uppercase; margin: 0; }
        .inst-info { font-size: 8pt; color: #333; margin: 0; }
        .report-title { font-size: 12pt; font-weight: bold; text-decoration: underline; margin: 10px 0 3px; text-align: center; text-transform: uppercase; }
        .report-period { font-size: 9pt; text-align: center; margin-bottom: 20px; font-weight: bold; }

        /* Style Tabel Data */
        .table-data { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
        .table-data th { background: #f2f2f2 !important; border: 1px solid #000; padding: 8px 4px; font-size: 7.5pt; text-transform: uppercase; text-align: center; vertical-align: middle; }
        .table-data td { border: 1px solid #000; padding: 5px 6px; vertical-align: middle; word-wrap: break-word; }
        
        .section-header { background: #f9f9f9 !important; font-weight: bold; text-transform: uppercase; font-size: 8.5pt; }
        .type-header { background: #fafafa !important; font-weight: bold; color: #444; }
        .row-total { background: #eee !important; font-weight: bold; }
        .row-grand-total { background: #1e293b !important; color: #ffffff !important; font-weight: bold; border: 1px solid #000; }
        .row-grand-total td { color: #ffffff !important; padding: 10px 6px; font-size: 9.5pt; }

        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }
        .text-muted { color: #666; font-size: 7.5pt; }
    </style>
</head>
<body onload="window.print()">
    <!-- Bagian Kop Surat -->
    <table class="kop-table">
        <tr>
            <td width="12%"><?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height:75px;"><?php endif; ?></td>
            <td width="88%" style="text-align:center;">
                <h1 class="inst-name"><?= $profile['institution_name'] ?></h1>
                <p class="inst-info"><?= $profile['address'] ?> | Telp: <?= $profile['phone'] ?> | Email: <?= $profile['email'] ?></p>
            </td>
        </tr>
    </table>

    <h2 class="report-title">Laporan Perubahan Aset Tetap</h2>
    <div class="report-period">Periode: <?= date('d M Y', strtotime($main_start)) ?> s.d <?= date('d M Y', strtotime($main_end)) ?></div>

    <table class="table-data">
        <thead>
            <tr>
                <th rowspan="2" style="width: 200px;">RINCIAN ITEM ASET</th>
                <th colspan="2">SALDO AWAL (<?= date('d/m/y', strtotime($main_start . ' -1 day')) ?>)</th>
                <th colspan="2">MUTASI PERIODE BERJALAN</th>
                <th colspan="3">SALDO AKHIR (<?= date('d/m/y', strtotime($main_end)) ?>)</th>
            </tr>
            <tr>
                <th width="120">HARGA PEROLEHAN</th>
                <th width="120">AKUM. PENYUSUTAN</th>
                <th width="100">PENAMBAHAN</th>
                <th width="100">PENYUSUTAN</th>
                <th width="120">HARGA PEROLEHAN</th>
                <th width="120">AKUM. PENYUSUTAN</th>
                <th width="140" style="background:#eef2ff !important; color:#000 !important;">NILAI BUKU NETO</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $cats = [['label'=>'A. ASET TETAP BERWUJUD', 'type'=>'Tetap'], ['label'=>'B. ASET TETAP TIDAK BERWUJUD', 'type'=>'Tidak Terwujud']];
            $grand = array_fill(0, 7, 0);

            foreach($cats as $c):
                $sub = array_fill(0, 7, 0);
                echo "<tr class='section-header'><td colspan='8'>{$c['label']}</td></tr>";
                
                $sql_types = "SELECT t.id, t.type_name FROM asset_types t JOIN asset_categories ac ON t.category_id = ac.id WHERE ac.asset_type = '{$c['type']}' ORDER BY t.type_name ASC";
                $res_types = $conn->query($sql_types);
                
                while($t = $res_types->fetch_assoc()):
                    $res_items = $conn->query("SELECT * FROM assets WHERE type_id = {$t['id']} AND status='Aktif' AND purchase_date <= '$main_end' ORDER BY asset_name ASC");
                    
                    if($res_items->num_rows > 0) {
                        echo "<tr class='type-header'><td colspan='8'>&nbsp; [ Klasifikasi: {$t['type_name']} ]</td></tr>";
                        
                        while($item = $res_items->fetch_assoc()):
                            $awal = getSingleAssetBalPrint($item['id'], date('Y-m-d', strtotime($main_start . ' -1 day')), $conn);
                            $act = getSingleAssetActPrint($item['id'], $main_start, $main_end, $conn);
                            
                            $akhir_bruto = $awal['bruto'] + $act['add'];
                            $akhir_akum  = $awal['akum'] + $act['depr'];
                            $nbv = $akhir_bruto - $akhir_akum;

                            if($akhir_bruto == 0) continue;

                            $sub[0]+=$awal['bruto']; $sub[1]+=$awal['akum']; $sub[2]+=$act['add']; $sub[3]+=$act['depr'];
                            $sub[4]+=$akhir_bruto; $sub[5]+=$akhir_akum; $sub[6]+=$nbv;
            ?>
                <tr>
                    <td style="padding-left:15px;"><?= $item['asset_name'] ?> <br><span class="text-muted"><?= $item['asset_code'] ?></span></td>
                    <td class="text-end"><?= number_format($awal['bruto'], 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($awal['akum'], 0, ',', '.') ?></td>
                    <td class="text-end" style="color:green;"><?= $act['add'] > 0 ? '+'.number_format($act['add'], 0, ',', '.') : '-' ?></td>
                    <td class="text-end" style="color:red;"><?= $act['depr'] > 0 ? '('.number_format($act['depr'], 0, ',', '.').')' : '-' ?></td>
                    <td class="text-end"><?= number_format($akhir_bruto, 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($akhir_akum, 0, ',', '.') ?></td>
                    <td class="text-end text-bold" style="background:#f8fafc;"><?= number_format($nbv, 0, ',', '.') ?></td>
                </tr>
            <?php endwhile; } endwhile; ?>
                <tr class="row-total">
                    <td>TOTAL <?= str_replace(['A. ', 'B. '], '', $c['label']) ?></td>
                    <td class="text-end"><?= number_format($sub[0], 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($sub[1], 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($sub[2], 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($sub[3], 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($sub[4], 0, ',', '.') ?></td>
                    <td class="text-end"><?= number_format($sub[5], 0, ',', '.') ?></td>
                    <td class="text-end">Rp <?= number_format($sub[6], 0, ',', '.') ?></td>
                </tr>
            <?php for($i=0; $i<7; $i++) $grand[$i] += $sub[$i]; endforeach; ?>

            <tr style="height: 15px;"><td colspan="8" style="border:none;"></td></tr>
            <tr class="row-grand-total">
                <td class="text-center">TOTAL KEKAYAAN ASET INSTITUSI</td>
                <td class="text-end"><?= number_format($grand[0], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grand[1], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grand[2], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grand[3], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grand[4], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format($grand[5], 0, ',', '.') ?></td>
                <td class="text-end">Rp <?= number_format($grand[6], 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    
       </div>
</body>
</html>