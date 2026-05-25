<?php
/**
 * laporan_anggaran_unit.php - OFFICIAL UNIT CASH MUTATION REPORT
 * Versi: 1.6 (Grand Master - Full Identity Header)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$id = (int)$_GET['id'];
$rep = $conn->query("SELECT * FROM anggaran_unit_reports WHERE id = $id")->fetch_assoc();
if (!$rep) die("<div style='text-align:center; padding:50px;'><h4>Ralat: Laporan tidak dijumpai!</h4></div>");

// Ambil Profil Institusi & Unit
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$unit = $conn->query("SELECT * FROM m_unit WHERE id = {$rep['unit_id']}")->fetch_assoc();

// Ambil data transaksi riil dari Jurnal
$akun_kas = $unit['kas_bank_akun'];
$sql_mutasi = "SELECT j.tgl_jurnal, j.keterangan, jd.debit, jd.kredit 
               FROM syifa_jurnal_detail jd 
               JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
               WHERE jd.kode_akun = '$akun_kas' 
               AND j.tgl_jurnal BETWEEN '{$rep['tgl_mulai']}' AND '{$rep['tgl_selesai']}' 
               ORDER BY j.tgl_jurnal ASC, j.id ASC";
$res_mutasi = $conn->query($sql_mutasi);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Laporan - <?= $rep['nama_laporan'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Times New Roman', serif; background: #fff; color: #000; }
        .print-area { width: 210mm; min-height: 297mm; padding: 25mm; margin: auto; }
        .report-header { border-bottom: 5px double #000; padding-bottom: 10px; margin-bottom: 30px; text-align: center; }
        .institution-name { font-size: 24px; font-weight: bold; text-transform: uppercase; }
        .table-report { font-size: 13px; border: 1.5px solid #000 !important; }
        .table-report th { background: #f8fafc !important; border: 1.5px solid #000 !important; padding: 10px; font-weight: bold; }
        .table-report td { border: 1px solid #000 !important; padding: 8px; }
        .signature-box { margin-top: 50px; float: right; width: 250px; text-align: center; }
        @media print { .no-print { display: none; } .print-area { padding: 10mm; } }
    </style>
</head>
<body>
    <div class="container no-print py-3 text-center">
        <button onclick="window.print()" class="btn btn-dark shadow px-5 rounded-pill fw-bold">CETAK LAPORAN</button>
        <button onclick="window.close()" class="btn btn-outline-secondary px-4 rounded-pill ms-2">TUTUP</button>
    </div>

    <div class="print-area">
        <div class="report-header text-center text-dark">
            <div class="institution-name"><?= $profile['institution_name'] ?? 'STIKes Yarsi Pontianak' ?></div>
            <div class="small fw-bold"><?= $profile['address'] ?? '' ?>, <?= $profile['city'] ?? '' ?></div>
            <div class="small">Email: <?= $profile['email'] ?? '-' ?> | Web: <?= $profile['website'] ?? '-' ?></div>
        </div>

        <div class="text-center mb-5 text-dark">
            <h4 class="fw-bold text-decoration-underline mb-1">LAPORAN MUTASI KAS UNIT</h4>
            <div class="fw-bold fs-5"><?= strtoupper($unit['nama_unit']) ?></div>
            <div class="small fw-bold">PERIODE: <?= date('d F Y', strtotime($rep['tgl_mulai'])) ?> s/d <?= date('d F Y', strtotime($rep['tgl_selesai'])) ?></div>
        </div>

        <table class="table table-report align-middle text-dark">
            <thead class="text-center">
                <tr><th>TANGGAL</th><th>URAIAN TRANSAKSI</th><th width="120">DEBET</th><th width="120">KREDIT</th><th width="140">SALDO AKHIR</th></tr>
            </thead>
            <tbody>
                <tr class="fw-bold bg-light"><td class="text-center">-</td><td class="ps-3">SALDO AWAL PERIODE (SNAPSHOT)</td><td></td><td></td><td class="text-end pe-3">Rp <?= number_format($rep['saldo_awal']) ?></td></tr>
                <?php 
                $running_bal = $rep['saldo_awal'];
                while($m = $res_mutasi->fetch_assoc()): 
                    $running_bal += ($m['debit'] - $m['kredit']); ?>
                <tr>
                    <td class="text-center small"><?= date('d/m/Y', strtotime($m['tgl_jurnal'])) ?></td>
                    <td class="ps-3"><?= $m['keterangan'] ?></td>
                    <td class="text-end pe-3 text-success"><?= $m['debit'] > 0 ? number_format($m['debit']) : '-' ?></td>
                    <td class="text-end pe-3 text-danger"><?= $m['kredit'] > 0 ? number_format($m['kredit']) : '-' ?></td>
                    <td class="text-end pe-3 fw-bold"><?= number_format($running_bal) ?></td>
                </tr>
                <?php endwhile; ?>
                <tr class="fw-bold bg-dark text-white text-center">
                    <td colspan="2" class="text-center text-white py-3">TOTAL MUTASI & SALDO AKHIR PERIODE</td>
                    <td class="text-end pe-3 text-white"><?= number_format($rep['total_debet']) ?></td>
                    <td class="text-end pe-3 text-white"><?= number_format($rep['total_kredit']) ?></td>
                    <td class="text-end pe-3 text-white">Rp <?= number_format($rep['saldo_akhir']) ?></td>
                </tr>
            </tbody>
        </table>

        <div class="signature-box text-dark">
            <p>Dicetak pada: <?= date('d/m/Y H:i') ?></p>
            <p class="mb-5 pb-4">Pimpinan Unit/Lembaga,</p>
            <p class="fw-bold mb-0 text-decoration-underline"><?= $_SESSION['name'] ?></p>
            <p class="small text-muted">Aplikasi Keuangan Syifa ERP</p>
        </div>
        <div style="clear:both;"></div>
    </div>
</body>
</html>