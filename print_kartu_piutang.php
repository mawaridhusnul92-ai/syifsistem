<?php
/**
 * print_kartu_piutang.php - PRINT ENGINE KARTU PIUTANG MAHASISWA
 * Versi: 2.0 (Grand Master Edition - Dynamic Profile Sync)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$nim = $conn->real_escape_string($_GET['nim'] ?? '');
$f_end = $_GET['f_end'] ?? date('Y-m-d');
$CODE_PIUTANG = getAccountCode($conn, 'PIUTANG_MHS') ?: '1-1201';

if(empty($nim)) die("NIM tidak valid.");

// ?? TARIK PROFIL & TANDA TANGAN DINAMIS
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$sql_mhs = "SELECT m.*, p.nama_prodi FROM syifa_mahasiswa m JOIN mhs_prodi p ON m.prodi_id = p.id WHERE m.nim = '$nim'";
$m = $conn->query($sql_mhs)->fetch_assoc();

if(!$m) die("Mahasiswa tidak ditemukan.");

$tagihans = $conn->query("SELECT * FROM keuangan_tagihan WHERE nim='$nim' AND created_at <= '$f_end 23:59:59' ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$bayars = $conn->query("SELECT j.tgl_jurnal, j.no_jurnal, jd.kredit FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.mahasiswa_id = {$m['id']} AND jd.kode_akun = '$CODE_PIUTANG' AND jd.kredit > 0 AND j.tgl_jurnal <= '$f_end' ORDER BY j.tgl_jurnal ASC")->fetch_all(MYSQLI_ASSOC);

$total_tagihan = 0; $total_bayar = 0;

$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'KARTU_PIUTANG' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Kartu_Piutang_<?= $nim ?></title>
    <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: 'Times New Roman', serif; font-size: 10pt; color: #000; line-height: 1.4; }
        .kop-table { width: 100%; border-bottom: 3px double #000; margin-bottom: 20px; }
        .inst-name { font-family: Arial, sans-serif; font-size: 14pt; font-weight: 800; text-transform: uppercase; margin: 0; }
        .report-title { font-family: Arial, sans-serif; font-size: 12pt; font-weight: bold; text-decoration: underline; margin-top: 5px; text-transform: uppercase; text-align: center; }
        .info-box { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .info-box td { padding: 3px 0; } .info-box .label { width: 150px; font-weight: bold; font-size: 9pt; color: #555; }
        .table-data { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .table-data th { background: #f2f2f2 !important; border: 1px solid #000; padding: 8px; font-size: 8pt; text-transform: uppercase; }
        .table-data td { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; }
        .summary-card { background: #000; color: #fff; padding: 15px; border-radius: 5px; margin-top: 10px; }
        .summary-card table { width: 100%; color: #fff; } .summary-card h2 { margin: 0; font-size: 16pt; text-align: right; }
        .footer-sign { margin-top: 40px; float: right; width: 250px; text-align: center; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 100px; opacity: 0.03; font-weight: 900; pointer-events: none; text-transform: uppercase; z-index: -1; white-space: nowrap; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body onload="window.print()">
    <div class="watermark">STATEMENT OF ACCOUNT</div>

    <table class="kop-table">
        <tr>
            <td width="15%"><?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height:70px;"><?php endif; ?></td>
            <td width="85%" style="text-align:center; padding-right: 15%;">
                <h1 class="inst-name"><?= strtoupper($profile['institution_name']) ?></h1>
                <div style="font-size: 8pt;"><?= $profile['address'] ?> | Telp: <?= $profile['phone'] ?></div>
            </td>
        </tr>
    </table>

    <h2 class="report-title">Kartu Audit Piutang Mahasiswa</h2>
    <div style="text-align:center; font-size: 9pt; margin-bottom: 20px;">Posisi Data per Tanggal: <?= date('d F Y', strtotime($f_end)) ?></div>

    <table class="info-box">
        <tr><td class="label">NIM</td><td>: <?= $m['nim'] ?></td><td class="label" style="text-align:right;">PRODI :</td><td style="text-align:right; font-weight:bold;"><?= $m['nama_prodi'] ?></td></tr>
        <tr><td class="label">NAMA LENGKAP</td><td>: <b style="font-size:11pt;"><?= strtoupper($m['nama']) ?></b></td><td class="label" style="text-align:right;">ANGKATAN :</td><td style="text-align:right; font-weight:bold;"><?= $m['angkatan'] ?></td></tr>
    </table>

    <div style="display: flex; justify-content: space-between; gap: 30px;">
        <div style="flex: 1;">
            <div style="font-weight:bold; font-size: 9pt; margin-bottom: 5px;">I. RINCIAN KEWAJIBAN (TAGIHAN)</div>
            <table class="table-data">
                <thead><tr><th width="80">Tanggal</th><th>Uraian Tagihan</th><th style="text-align:right;">Nominal</th></tr></thead>
                <tbody>
                    <?php foreach($tagihans as $t): $total_tagihan += $t['nominal']; ?>
                    <tr><td align="center"><?= date('d/m/y', strtotime($t['created_at'])) ?></td><td><?= $t['nama_tagihan'] ?></td><td align="right"><?= number_format($t['nominal']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr style="font-weight:bold; background:#eee;"><td colspan="2" align="right">TOTAL TAGIHAN</td><td align="right"><?= number_format($total_tagihan) ?></td></tr></tfoot>
            </table>
        </div>
        <div style="flex: 1;">
            <div style="font-weight:bold; font-size: 9pt; margin-bottom: 5px;">II. REALISASI PEMBAYARAN (KAS)</div>
            <table class="table-data">
                <thead><tr><th width="80">Tanggal</th><th>No. Kuitansi</th><th style="text-align:right;">Jumlah</th></tr></thead>
                <tbody>
                    <?php if(empty($bayars)): ?><tr><td colspan="3" align="center" style="padding: 20px; color: #999;">Belum ada pembayaran.</td></tr>
                    <?php else: foreach($bayars as $b): $total_bayar += $b['kredit']; ?>
                    <tr><td align="center"><?= date('d/m/y', strtotime($b['tgl_jurnal'])) ?></td><td align="center"><?= $b['no_jurnal'] ?></td><td align="right"><?= number_format($b['kredit']) ?></td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot><tr style="font-weight:bold; background:#eee;"><td colspan="2" align="right">TOTAL DIBAYAR</td><td align="right"><?= number_format($total_bayar) ?></td></tr></tfoot>
            </table>
        </div>
    </div>

    <div class="summary-card">
        <table border="0">
            <tr>
                <td><div style="font-weight:bold; font-size: 10pt;">SALDO AKHIR PIUTANG</div><div style="font-size: 8pt; opacity: 0.8;">Status audit sistem per <?= date('d/m/Y') ?></div></td>
                <td align="right"><h2>Rp <?= number_format($total_tagihan - $total_bayar, 0, ',', '.') ?></h2></td>
            </tr>
        </table>
    </div>

    <!-- Signatures -->
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