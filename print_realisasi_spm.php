<?php
/**
 * print_realisasi_spm.php - SUPREME PRINT ENGINE (REALISASI SPM)
 * Versi: 2.1 (Grand Master - Pure Landscape & Isolated Signature)
 * Deskripsi: Mesin pencetak khusus LPJ Realisasi Anggaran Operasional 
 * berformat A4 Landscape murni (tanpa elemen UI Web yang membocorkan layout).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

// HAPUS REQUIRE SIGNATURE.PHP DI SINI AGAR UI TIDAK BOCOR KE HALAMAN CETAK

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak: Silakan login terlebih dahulu."); }
if (!function_exists('formatRp')) { function formatRp($n) { return number_format($n ?? 0, 0, ',', '.'); } }

$f_bulan = $_GET['bulan'] ?? date('m');
$f_tahun = $_GET['tahun'] ?? date('Y');
$nama_bulan = ["", "JANUARI", "FEBRUARI", "MARET", "APRIL", "MEI", "JUNI", "JULI", "AGUSTUS", "SEPTEMBER", "OKTOBER", "NOVEMBER", "DESEMBER"];

$start_d = sprintf("%04d-%02d-01 00:00:00", $f_tahun, $f_bulan);
$end_d = date("Y-m-t 23:59:59", strtotime($start_d));

// 1. DATA MASTER & CONFIG
$q_app = $conn->query("SELECT * FROM system_profile WHERE id=1");
$profile = $q_app ? $q_app->fetch_assoc() : null;

$logo_path = (!empty($profile['logo']) && file_exists("assets/img/" . $profile['logo'])) ? "assets/img/" . $profile['logo'] : "";
$inst_name = $profile['institution_name'] ?? 'STIKes YARSI PONTIANAK';
$alamat = $profile['address'] ?? 'Jl. Letjen Sutoyo, Kota Pontianak, Kalimantan Barat';
$telp = $profile['phone'] ?? '(0561) 123456';
$kota = $profile['city'] ?? 'Pontianak';

// --- DATA ENGINE TREE-CLIMBER ---
$all_accs = [];
$q_acc = $conn->query("SELECT kode_akun, nama_akun, is_group, parent_kode FROM syifa_akun");
if ($q_acc) { while($r = $q_acc->fetch_assoc()) { $all_accs[$r['kode_akun']] = $r; } }

function getLogicalGroup($kode, $all_accs) {
    $curr = $all_accs[$kode] ?? null;
    if (!$curr) return ['kode' => 'LAINNYA', 'nama' => 'PENGELUARAN LAINNYA'];
    
    $path = [$curr];
    $top = $curr;
    $visited = [];
    while (!empty($top['parent_kode']) && isset($all_accs[$top['parent_kode']])) {
        if (in_array($top['kode_akun'], $visited)) break;
        $visited[] = $top['kode_akun'];
        $top = $all_accs[$top['parent_kode']];
        array_unshift($path, $top);
    }
    
    $grp = $path[0];
    foreach($path as $node) {
        if ($node['is_group'] == 1 && strlen($node['kode_akun']) >= 6 && substr($node['kode_akun'], -2) === '00') {
            $grp = $node;
        }
    }
    if ($grp['kode_akun'] == $path[0]['kode_akun'] && isset($path[1])) $grp = $path[1];
    if (isset($path[2]) && $path[2]['is_group'] == 1) $grp = $path[2];
    
    return ['kode' => $grp['kode_akun'], 'nama' => $grp['nama_akun']];
}

$report_data_spm = [];
$report_data_non_spm = [];

// AMBIL ANGGARAN SPM
$sql_spm = "SELECT d.kode_akun, SUM(d.nominal) as nominal, a.nama_akun FROM keuangan_spm_detail d JOIN keuangan_spm_header h ON d.spm_id = h.id LEFT JOIN syifa_akun a ON d.kode_akun = a.kode_akun WHERE h.status = 'GENERATED' AND MONTH(h.tgl_mulai) = ".(int)$f_bulan." AND YEAR(h.tgl_mulai) = ".(int)$f_tahun." GROUP BY d.kode_akun, a.nama_akun";
$spm_items = $conn->query($sql_spm);
$mapped_spm_coas = [];

if ($spm_items && $spm_items->num_rows > 0) {
    while ($spm = $spm_items->fetch_assoc()) {
        $child_kode = $spm['kode_akun'];
        $child_nama = $spm['nama_akun'] ?? 'Akun '.$child_kode;
        $nom_anggaran = (double)$spm['nominal'];
        $mapped_spm_coas[] = $child_kode;
        
        $grp = getLogicalGroup($child_kode, $all_accs);
        $top_kode = $grp['kode'];
        $top_nama = strtoupper($grp['nama']);
        
        if (!isset($report_data_spm[$top_kode])) { $report_data_spm[$top_kode] = ['nama' => $top_nama, 'anggaran' => 0, 'realisasi' => 0, 'children' => []]; }
        if (!isset($report_data_spm[$top_kode]['children'][$child_kode])) { $report_data_spm[$top_kode]['children'][$child_kode] = ['nama' => $child_nama, 'anggaran' => 0, 'realisasi' => 0, 'trx' => []]; }
        $report_data_spm[$top_kode]['children'][$child_kode]['anggaran'] += $nom_anggaran;
        $report_data_spm[$top_kode]['anggaran'] += $nom_anggaran;
    }
}

// AMBIL REALISASI JURNAL
$sql_jurnal = "SELECT jd.kode_akun, a.nama_akun, j.keterangan as uraian_manual, j.tgl_jurnal, DAY(j.tgl_jurnal) as tgl_hari, SUM(jd.debit - jd.kredit) as real_val, j.no_jurnal FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun WHERE j.tgl_jurnal BETWEEN '$start_d' AND '$end_d' AND j.is_deleted = 0 AND a.kategori IN ('Beban', 'Pengeluaran') AND a.nama_akun NOT LIKE '%Penyusutan%' AND a.nama_akun NOT LIKE '%Amortisasi%' AND a.nama_akun NOT LIKE '%Depresiasi%' AND EXISTS (SELECT 1 FROM syifa_jurnal_detail jdx JOIN syifa_akun ax ON jdx.kode_akun = ax.kode_akun WHERE jdx.jurnal_id = j.id AND jdx.kredit > 0 AND (ax.kategori IN ('Kas', 'Bank') OR ax.kode_akun LIKE '1-11%' OR ax.is_cash_account = 1)) GROUP BY j.id, jd.kode_akun HAVING real_val > 0";
$jurnals = $conn->query($sql_jurnal);

if ($jurnals && $jurnals->num_rows > 0) {
    while ($j = $jurnals->fetch_assoc()) {
        $child_kode = $j['kode_akun'];
        $child_nama = $j['nama_akun'] ?? 'Akun '.$child_kode;
        $nom_real = (double)$j['real_val'];
        $j['tgl_hari'] = $j['tgl_hari'] . " " . substr($nama_bulan[(int)$f_bulan], 0, 3);
        
        $grp = getLogicalGroup($child_kode, $all_accs);
        $top_kode = $grp['kode'];
        $top_nama = strtoupper($grp['nama']);
        
        if (in_array($child_kode, $mapped_spm_coas)) {
            $report_data_spm[$top_kode]['children'][$child_kode]['realisasi'] += $nom_real;
            $report_data_spm[$top_kode]['children'][$child_kode]['trx'][] = $j;
            $report_data_spm[$top_kode]['realisasi'] += $nom_real;
        } else {
            if (!isset($report_data_non_spm[$top_kode])) { $report_data_non_spm[$top_kode] = ['nama' => $top_nama, 'anggaran' => 0, 'realisasi' => 0, 'children' => []]; }
            if (!isset($report_data_non_spm[$top_kode]['children'][$child_kode])) { $report_data_non_spm[$top_kode]['children'][$child_kode] = ['nama' => $child_nama, 'anggaran' => 0, 'realisasi' => 0, 'trx' => []]; }
            $report_data_non_spm[$top_kode]['children'][$child_kode]['realisasi'] += $nom_real;
            $report_data_non_spm[$top_kode]['children'][$child_kode]['trx'][] = $j;
            $report_data_non_spm[$top_kode]['realisasi'] += $nom_real;
        }
    }
}
ksort($report_data_spm); ksort($report_data_non_spm);

// AMBIL SALDO KAS PUSAT
$sql_kas = "SELECT a.kode_akun, a.nama_akun, 
            (a.opening_balance + COALESCE((SELECT SUM(jd.debit - jd.kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = a.kode_akun AND j.tgl_jurnal < '$start_d' AND j.is_deleted = 0), 0)) as saldo_awal,
            COALESCE((SELECT SUM(jd.debit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = a.kode_akun AND j.tgl_jurnal BETWEEN '$start_d' AND '$end_d' AND j.is_deleted = 0), 0) as mutasi_in,
            COALESCE((SELECT SUM(jd.kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = a.kode_akun AND j.tgl_jurnal BETWEEN '$start_d' AND '$end_d' AND j.is_deleted = 0), 0) as mutasi_out
            FROM syifa_akun a WHERE (a.kategori IN ('Kas', 'Bank') OR a.is_cash_account = 1) AND a.is_group = 0 AND a.is_active = 1 AND a.kode_akun NOT IN (SELECT kas_bank_akun FROM m_unit WHERE kas_bank_akun IS NOT NULL AND kas_bank_akun != '') ORDER BY a.kode_akun ASC";
$res_kas = $conn->query($sql_kas);
$kas_balances = []; $grand_saldo_awal = 0; $grand_terima = 0; $grand_keluar = 0; $grand_saldo_akhir = 0;
if($res_kas) {
    while($rk = $res_kas->fetch_assoc()) {
        $sa = (double)$rk['saldo_awal']; $in = (double)$rk['mutasi_in']; $out = (double)$rk['mutasi_out']; $sak = $sa + $in - $out;
        $kas_balances[] = ['nama_akun' => $rk['nama_akun'], 'saldo_awal' => $sa, 'terima_dana' => $in, 'pengeluaran' => $out, 'saldo_akhir' => $sak];
        $grand_saldo_awal += $sa; $grand_terima += $in; $grand_keluar += $out; $grand_saldo_akhir += $sak;
    }
}

$tot_spm_anggaran = 0; $tot_spm_realisasi = 0;
foreach($report_data_spm as $td) { $tot_spm_anggaran += $td['anggaran']; $tot_spm_realisasi += $td['realisasi']; }
$tot_non_spm_realisasi = 0;
foreach($report_data_non_spm as $td) { $tot_non_spm_realisasi += $td['realisasi']; }
$tot_pengeluaran_all = $tot_spm_realisasi + $tot_non_spm_realisasi;

// SIGNATURE GENERATOR (Bypass File Signature UI)
function renderSignatureRealisasiPrint($kota, $tgl_dokumen) {
    global $conn;
    $html = "<table width='100%' style='margin-top: 15mm; font-size: 10pt; page-break-inside: avoid; text-align: center; border:none;'><tr>";
    
    $q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'REALISASI_SPM' ORDER BY id ASC");
    $signatures = []; 
    if($q_ttd && $q_ttd->num_rows > 0) {
        while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;
    } else {
        $signatures = [
            ['sign_role' => 'Bendahara Pengeluaran', 'sign_position' => 'Bagian Keuangan', 'sign_name' => ''],
            ['sign_role' => 'Mengetahui/Menyetujui', 'sign_position' => 'Ketua/Direktur', 'sign_name' => '']
        ];
    }
    
    $width = floor(100 / count($signatures)) . '%';
    foreach($signatures as $idx => $sig) { 
        if($idx == count($signatures) - 1) {
            $html .= "<td width='$width' style='border:none; padding:0; vertical-align:top;'>$kota, ".date('d M Y', strtotime($tgl_dokumen))."<br>".htmlspecialchars($sig['sign_role'])."</td>"; 
        } else {
            $html .= "<td width='$width' style='border:none; padding:0; vertical-align:top;'><br>".htmlspecialchars($sig['sign_role'])."</td>"; 
        }
    }
    $html .= "</tr><tr>";
    foreach($signatures as $sig) {
        $name = htmlspecialchars($sig['sign_name']) ?: '( ____________________ )';
        $pos = htmlspecialchars($sig['sign_position']);
        $html .= "<td style='border:none; padding:0;'><div style='margin-top: 25mm;'><b style='text-decoration:underline;'>$name</b><br><span>$pos</span></div></td>";
    }
    $html .= "</tr></table>";
    return $html;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak LPJ Realisasi - <?= $nama_bulan[(int)$f_bulan] ?> <?= $f_tahun ?></title>
    <style>
        @page { size: A4 landscape; margin: 10mm 15mm; }
        
        body { font-family: 'Arial', sans-serif; font-size: 8.5pt; color: #000; line-height: 1.4; margin: 0; padding: 15px 20px; background: #525659; }
        .a4-paper { background: #fff; width: 297mm; min-height: 210mm; margin: 0 auto; padding: 15mm 20mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); color: #000; box-sizing: border-box; }
        
        .kop-table { width: 100%; border-bottom: 2.5pt solid #000; margin-bottom: 15px; border-collapse: collapse; }
        .kop-table td { border: none; padding: 0 0 5px 0; vertical-align: middle; }
        .kop-logo { max-height: 75px; width: auto; }
        .inst-name { font-size: 14pt; font-weight: 900; text-transform: uppercase; margin: 0; letter-spacing: 1px; }
        .inst-info { font-size: 8.5pt; color: #333; margin: 0; margin-top: 2px; }
        
        .report-title { font-size: 11pt; font-weight: bold; margin: 5px 0 2px; text-align: center; text-transform: uppercase; }
        .report-period { font-size: 10pt; text-align: center; margin-bottom: 15px; font-weight: bold; text-transform: uppercase; }
        
        table.table-data { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
        table.table-data th { background: #f2f2f2 !important; border: 1px solid #000; padding: 8px 4px; font-size: 8pt; text-transform: uppercase; text-align: center; vertical-align: middle; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.table-data td { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; word-wrap: break-word; font-size: 8.5pt; }
        
        .row-group { background: #f9f9f9 !important; font-weight: bold; font-size: 8.5pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; text-transform: uppercase; }
        .row-child-akun td { background-color: #ffffff; font-weight: bold; border-top: 1px dashed #000; }
        .row-trx td { background-color: #ffffff; border-top: none; }
        
        .bg-summary { background: #eaeaea !important; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .row-grand-total { background: #1e293b !important; color: #ffffff !important; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .row-grand-total td { color: #ffffff !important; padding: 10px 8px; font-size: 9.5pt; border: 1px solid #000; }
        .table-dark-header th { background-color: #212529 !important; color: #ffffff !important; border-color: #373b3e !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .text-start { text-align: left; }
        .text-bold { font-weight: bold; }
        .bullet-point { display: inline-block; width: 4px; height: 4px; background-color: #000; border-radius: 50%; margin-right: 6px; vertical-align: middle; }

        @media print {
            body { background: #fff; padding: 0; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .a4-paper { box-shadow: none; margin: 0; width: 100%; height: auto; min-height: auto; padding: 0; border: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body onload="setTimeout(function(){ window.print(); }, 500);">
    
    <div style="text-align: center; margin-bottom: 20px;" class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #0d6efd; color: #fff; border: none; border-radius: 5px; font-weight: bold;">🖨️ CETAK DOKUMEN LPJ</button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #dc3545; color: #fff; border: none; border-radius: 5px; font-weight: bold; margin-left: 10px;">TUTUP</button>
    </div>

    <div class="a4-paper">
        <table class="kop-table">
            <tr>
                <td width="12%" style="text-align:left;">
                    <?php if($logo_path): ?>
                        <img src="<?= $logo_path ?>" class="kop-logo">
                    <?php else: ?>
                        <div style="width:60px;height:60px;border:1px solid #000;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;margin:auto;">LOGO</div>
                    <?php endif; ?>
                </td>
                <td width="88%" style="text-align:center;">
                    <h1 class="inst-name"><?= $inst_name ?></h1>
                    <p class="inst-info"><?= $alamat ?> | Telp: <?= $telp ?> | Email: <?= $email ?></p>
                </td>
            </tr>
        </table>

        <div class="report-title">LAPORAN PERTANGGUNGJAWABAN REALISASI ANGGARAN BELANJA OPERASIONAL</div>
        <div class="report-period">BULAN <?= $nama_bulan[(int)$f_bulan] ?> TAHUN <?= $f_tahun ?></div>

        <table class="table-data">
            <thead>
                <tr>
                    <th width="12%">TANGGAL BAYAR</th>
                    <th colspan="2" width="43%" class="text-start ps-3">RINCIAN KODE INDUK SPM & KETERANGAN MUTASI</th>
                    <th width="15%" class="text-end pe-3">JUMLAH ANGGARAN</th>
                    <th width="15%" class="text-end pe-3">JUMLAH PEMBAYARAN</th>
                    <th width="15%" class="text-end pe-3">SISA ANGGARAN</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="6" class="text-center fw-bold" style="background:#ddd !important; -webkit-print-color-adjust:exact;">REALISASI ANGGARAN (DALAM SPM)</td></tr>
                <?php 
                $alpha = 'A';
                if(empty($report_data_spm)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">Belum ada Anggaran Belanja SPM pada bulan ini.</td></tr>
                <?php else: 
                    foreach($report_data_spm as $root_kode => $top_data): 
                        $grup_anggaran = $top_data['anggaran']; 
                        $grup_realisasi = $top_data['realisasi']; 
                ?>
                    <tr class="row-group">
                        <td></td>
                        <td width="4%" class="text-center"><?= $alpha ?>.</td>
                        <td class="text-start text-bold" colspan="4"><?= $top_data['nama'] ?> [<?= $root_kode ?>]</td>
                    </tr>
                    
                    <?php 
                        $num = 1;
                        foreach($top_data['children'] as $child_kode => $child_data): 
                            $child_anggaran = $child_data['anggaran'];
                            $child_realisasi = $child_data['realisasi'];
                            $child_sisa = $child_anggaran - $child_realisasi;
                    ?>
                            <tr class="row-child-akun">
                                <td></td>
                                <td class="text-center"><?= $num ?>.</td>
                                <td class="text-start"><?= $child_data['nama'] ?></td>
                                <td class="text-end"><?= $child_anggaran > 0 ? formatRp($child_anggaran) : '-' ?></td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?= formatRp($child_sisa) ?></td>
                            </tr>
                            
                            <?php if(!empty($child_data['trx'])): foreach($child_data['trx'] as $trx): ?>
                                <tr class="row-trx">
                                    <td class="text-center"><?= $trx['tgl_hari'] ?></td>
                                    <td></td>
                                    <td class="text-start"><span class="bullet-point"></span><?= htmlspecialchars($trx['uraian_manual']) ?></td>
                                    <td class="text-end">-</td>
                                    <td class="text-end"><?= formatRp($trx['real_val']) ?></td>
                                    <td class="text-end">-</td>
                                </tr>
                            <?php endforeach; endif; ?>
                    <?php $num++; endforeach; ?>
                    
                    <tr class="bg-summary">
                        <td colspan="3" class="text-end">SUBTOTAL <?= $alpha ?></td>
                        <td class="text-end"><?= formatRp($grup_anggaran) ?></td>
                        <td class="text-end"><?= formatRp($grup_realisasi) ?></td>
                        <td class="text-end"><?= formatRp($grup_anggaran - $grup_realisasi) ?></td>
                    </tr>
                <?php $alpha++; endforeach; endif; ?>

                <?php if(!empty($report_data_non_spm)): ?>
                    <tr><td colspan="6" style="border:none; height:15px;"></td></tr>
                    <tr><td colspan="6" class="text-center fw-bold" style="background:#e2e8f0 !important; -webkit-print-color-adjust:exact;">PENGELUARAN DILUAR SPM</td></tr>
                    <?php 
                    $alpha = 'A';
                    foreach($report_data_non_spm as $root_kode => $top_data): 
                            $grup_realisasi = $top_data['realisasi']; 
                    ?>
                        <tr class="row-group">
                            <td></td>
                            <td width="4%" class="text-center"><?= $alpha ?>.</td>
                            <td class="text-start text-bold" colspan="4"><?= $top_data['nama'] ?> [<?= $root_kode ?>]</td>
                        </tr>
                        
                        <?php 
                            $num = 1;
                            foreach($top_data['children'] as $child_kode => $child_data): 
                                $child_realisasi = $child_data['realisasi'];
                        ?>
                                <tr class="row-child-akun">
                                    <td></td>
                                    <td class="text-center"><?= $num ?>.</td>
                                    <td class="text-start"><?= $child_data['nama'] ?></td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">-</td>
                                    <td class="text-end">- <?= formatRp($child_realisasi) ?></td>
                                </tr>
                                <?php foreach($child_data['trx'] as $trx): ?>
                                    <tr class="row-trx">
                                        <td class="text-center"><?= $trx['tgl_hari'] ?></td>
                                        <td></td>
                                        <td class="text-start"><span class="bullet-point"></span><?= htmlspecialchars($trx['uraian_manual']) ?></td>
                                        <td class="text-end">-</td>
                                        <td class="text-end"><?= formatRp($trx['real_val']) ?></td>
                                        <td class="text-end">-</td>
                                    </tr>
                                <?php endforeach; ?>
                        <?php $num++; endforeach; ?>
                        
                        <tr class="bg-summary">
                            <td colspan="3" class="text-end">SUBTOTAL <?= $alpha ?></td>
                            <td class="text-end">-</td>
                            <td class="text-end"><?= formatRp($grup_realisasi) ?></td>
                            <td class="text-end">- <?= formatRp($grup_realisasi) ?></td>
                        </tr>
                    <?php $alpha++; endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="6" style="border:none; height:10px;"></td></tr>
                <tr class="row-grand-total">
                    <td colspan="3" class="text-end">TOTAL KESELURUHAN (ANGGARAN VS REALISASI) :</td>
                    <td class="text-end">Rp <?= formatRp($tot_spm_anggaran) ?></td>
                    <td class="text-end">Rp <?= formatRp($tot_pengeluaran_all) ?></td>
                    <td class="text-end">Rp <?= formatRp($tot_spm_anggaran - $tot_pengeluaran_all) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- REKAPITULASI KAS & BANK -->
        <h3 style="font-size: 10pt; font-weight: bold; margin-top: 30px; margin-bottom: 5px;">REKAPITULASI SALDO KAS & BANK PUSAT</h3>
        <table class="table-data" style="margin-top:0;">
            <thead class="table-dark-header">
                <tr>
                    <th width="40%" class="text-start ps-3">NAMA AKUN KAS DAN BANK (DILUAR KAS UNIT)</th>
                    <th width="15%">SALDO AWAL</th>
                    <th width="15%">TERIMA DANA</th>
                    <th width="15%">PENGELUARAN</th>
                    <th width="15%">SALDO AKHIR</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($kas_balances)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted italic">Tidak ada data rekening Kas/Bank pusat.</td></tr>
                <?php else: foreach($kas_balances as $kb): ?>
                    <tr>
                        <td class="text-start ps-3 fw-bold"><?= htmlspecialchars($kb['nama_akun']) ?></td>
                        <td class="text-end"><?= formatRp($kb['saldo_awal']) ?></td>
                        <td class="text-end"><?= formatRp($kb['terima_dana']) ?></td>
                        <td class="text-end"><?= formatRp($kb['pengeluaran']) ?></td>
                        <td class="text-end text-bold"><?= formatRp($kb['saldo_akhir']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot class="bg-summary">
                <tr>
                    <td class="text-end pe-3 fw-bold">TOTAL KESELURUHAN KAS & BANK</td>
                    <td class="text-end text-bold">Rp <?= formatRp($grand_saldo_awal) ?></td>
                    <td class="text-end text-bold">Rp <?= formatRp($grand_terima) ?></td>
                    <td class="text-end text-bold">Rp <?= formatRp($grand_keluar) ?></td>
                    <td class="text-end text-bold">Rp <?= formatRp($grand_saldo_akhir) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- TANDA TANGAN -->
        <?= renderSignatureRealisasiPrint($kota, date('Y-m-d')) ?>
    </div>
</body>
</html>