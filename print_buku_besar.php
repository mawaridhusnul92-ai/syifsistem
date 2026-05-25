<?php
/**
 * print_buku_besar.php - ENGINE CETAK & EXPORT BUKU BESAR
 * Versi: 13.0 (Sovereign Export Engine - Dynamic Profile Sync)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak."); }

$report_id = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'print';

if ($report_id > 0) {
    $conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
    if (!$conf) die("Data laporan tidak ditemukan.");
    $target_akun = $conf['deskripsi'];
    $start_date = $conf['tgl_mulai'];
    $end_date = $conf['tgl_akhir'];
} else {
    $target_akun = $_GET['akun'] ?? $_GET['kode'] ?? '';
    $start_date = $_GET['start'] ?? date('Y-01-01');
    $end_date = $_GET['end'] ?? date('Y-m-d');
}

// 1. DATA MASTER & TANDA TANGAN
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'BUKU_BESAR' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

$acc = $conn->query("SELECT * FROM syifa_akun WHERE kode_akun='$target_akun'")->fetch_assoc();
if(!$acc) die("Akun tidak valid.");

// 2. KALKULASI SALDO AWAL
$sql_ob = "SELECT SUM(jd.debit) as d, SUM(jd.kredit) as k 
           FROM syifa_jurnal_detail jd 
           JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
           WHERE jd.kode_akun = '$target_akun' AND j.tgl_jurnal < '$start_date'";
$res_ob = $conn->query($sql_ob)->fetch_assoc();

$saldo_awal = (double)$acc['opening_balance'];
if ($acc['saldo_normal'] == 'D') {
    $saldo_awal += ((double)$res_ob['d'] - (double)$res_ob['k']);
} else {
    $saldo_awal += ((double)$res_ob['k'] - (double)$res_ob['d']);
}

// 3. AMBIL MUTASI PERIODE INI
$sql = "SELECT j.tgl_jurnal, j.no_jurnal, j.keterangan as ket_header, jd.debit, jd.kredit, jd.keterangan as ket_item
        FROM syifa_jurnal_detail jd 
        JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
        WHERE jd.kode_akun = '$target_akun' AND j.tgl_jurnal BETWEEN '$start_date' AND '$end_date'
        ORDER BY j.tgl_jurnal ASC, j.id ASC";
$mutasi = $conn->query($sql);

function fmtRp($angka) { return number_format($angka, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Buku_Besar_<?= $target_akun ?></title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .kop-table { width: 100%; border-bottom: 3px double #000; margin-bottom: 20px; }
        .inst-name { font-size: 16pt; font-weight: 800; text-transform: uppercase; margin: 0; }
        .report-title { font-size: 16px; font-weight: bold; margin: 0 0 5px 0; text-transform: uppercase; text-align: center; }
        .info-table { width: 100%; margin-bottom: 15px; font-weight: bold; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { border: 1px solid #000; padding: 6px 8px; }
        .data-table th { background-color: #f2f2f2; text-align: center; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
        
        .signature-container { margin-top: 40px; width: 100%; page-break-inside: avoid; }
        .sign-table { width: 100%; text-align: center; font-family: Arial, sans-serif; font-size: 10pt; border: none; }
        .sign-table td { border: none; padding: 5px; }
        .sign-line { border-bottom: 1px solid #000; margin: 60px auto 5px auto; width: 80%; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <table class="kop-table">
        <tr>
            <td width="15%"><?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height:70px;"><?php endif; ?></td>
            <td width="85%" style="text-align:center; padding-right: 15%;">
                <h1 class="inst-name"><?= strtoupper($profile['institution_name'] ?? 'INSTITUSI') ?></h1>
                <div style="font-size: 9pt;"><?= $profile['address'] ?? '' ?> | Telp: <?= $profile['phone'] ?? '' ?></div>
            </td>
        </tr>
    </table>

    <div class="text-center">
        <h3 class="report-title">LAPORAN BUKU BESAR RINCIAN</h3>
        <p>Periode: <?= date('d M Y', strtotime($start_date)) ?> s.d <?= date('d M Y', strtotime($end_date)) ?></p>
    </div>

    <table class="info-table">
        <tr>
            <td width="12%">KODE AKUN</td><td width="3%">:</td><td width="35%"><?= $acc['kode_akun'] ?></td>
            <td width="15%">SALDO NORMAL</td><td width="3%">:</td><td><?= $acc['saldo_normal'] == 'D' ? 'DEBIT' : 'KREDIT' ?></td>
        </tr>
        <tr>
            <td>NAMA AKUN</td><td>:</td><td><?= strtoupper($acc['nama_akun']) ?></td>
            <td>KATEGORI</td><td>:</td><td><?= strtoupper($acc['kategori']) ?></td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th width="10%">TANGGAL</th>
                <th width="15%">NO BUKTI</th>
                <th>KETERANGAN / URAIAN</th>
                <th width="13%">DEBET (RP)</th>
                <th width="13%">KREDIT (RP)</th>
                <th width="15%">SALDO (RP)</th>
            </tr>
        </thead>
        <tbody>
            <!-- BARIS SALDO AWAL -->
            <tr style="background:#f9f9f9; font-weight:bold;">
                <td colspan="3" class="text-center">SALDO AWAL PER <?= date('d/m/Y', strtotime($start_date)) ?></td>
                <td></td>
                <td></td>
                <td class="text-right"><?= fmtRp($saldo_awal) ?></td>
            </tr>

            <!-- BARIS MUTASI -->
            <?php 
                $run_bal = $saldo_awal; 
                $tot_d = 0; 
                $tot_k = 0;
                while($r = $mutasi->fetch_assoc()): 
                    $d = (double)$r['debit']; 
                    $k = (double)$r['kredit'];
                    $tot_d += $d; $tot_k += $k;
                    
                    if ($acc['saldo_normal'] == 'D') { $run_bal += ($d - $k); } 
                    else { $run_bal += ($k - $d); }
            ?>
            <tr>
                <td class="text-center"><?= date('d/m/Y', strtotime($r['tgl_jurnal'])) ?></td>
                <td class="text-center"><?= $r['no_jurnal'] ?></td>
                <td><?= htmlspecialchars($r['ket_header']) ?> <?= !empty($r['ket_item']) ? "- ".$r['ket_item'] : "" ?></td>
                <td class="text-right"><?= $d > 0 ? fmtRp($d) : '-' ?></td>
                <td class="text-right"><?= $k > 0 ? fmtRp($k) : '-' ?></td>
                <td class="text-right text-bold"><?= fmtRp($run_bal) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
        <tfoot>
            <tr style="background:#f2f2f2; font-weight:bold;">
                <td colspan="3" class="text-right">TOTAL MUTASI PERIODE BERJALAN</td>
                <td class="text-right"><?= fmtRp($tot_d) ?></td>
                <td class="text-right"><?= fmtRp($tot_k) ?></td>
                <td class="text-right"></td>
            </tr>
            <tr style="background:#e8e8e8; font-weight:bold;">
                <td colspan="5" class="text-right">SALDO AKHIR KUMULATIF</td>
                <td class="text-right">Rp <?= fmtRp($run_bal) ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="signature-container">
        <?php if(!empty($signatures)): 
            $width = floor(100 / count($signatures)) . '%';
        ?>
        <table class="sign-table">
            <tr>
                <?php foreach($signatures as $sig): ?>
                    <td width="<?= $width ?>"><?= htmlspecialchars($sig['sign_role']) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach($signatures as $sig): ?>
                    <td>
                        <div class="sign-line"></div>
                        <b><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></b><br>
                        <span><?= htmlspecialchars($sig['sign_position']) ?></span>
                    </td>
                <?php endforeach; ?>
            </tr>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>