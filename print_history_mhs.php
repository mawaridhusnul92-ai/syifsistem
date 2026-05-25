<?php
/**
 * print_history_mhs.php - EXPORT KARTU AUDIT PIUTANG MAHASISWA
 * Versi: 2.0 (Sovereign Dynamic Signatures)
 */
session_start();
require_once 'config/koneksi.php';

$nim = $_GET['nim'] ?? die("NIM tidak valid.");
$mhs = $conn->query("SELECT m.*, p.nama_prodi FROM syifa_mahasiswa m LEFT JOIN mhs_prodi p ON m.prodi_id = p.id WHERE m.nim = '$nim'")->fetch_assoc();

if(!$mhs) die("Data Mahasiswa tidak ditemukan.");

// ?? TARIK PROFIL & TANDA TANGAN DINAMIS
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'HISTORY_MHS' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;
$CODE_PIUTANG = function_exists('getAccountCode') ? getAccountCode($conn, 'PIUTANG_MHS') : '1-1201';

// SQL UNION: Menampilkan Referensi Nomor Dokumen Resmi (no_jurnal)
$sql_hist = "
    (SELECT created_at as tgl, 'TAGIHAN' as tipe, nama_tagihan as item, no_jurnal as ref, nominal as debit, 0 as kredit 
     FROM keuangan_tagihan WHERE nim = '$nim' AND deleted_at IS NULL)
    UNION ALL
    (SELECT j.tgl_jurnal as tgl, 'PEMBAYARAN' as tipe, t.nama_tagihan as item, j.no_jurnal as ref, 0 as debit, jd.kredit as kredit 
     FROM syifa_jurnal_detail jd 
     JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
     JOIN keuangan_tagihan t ON jd.tagihan_id_ref = t.id
     WHERE t.nim = '$nim' AND t.deleted_at IS NULL AND jd.kode_akun = '$CODE_PIUTANG' AND jd.kredit > 0)
    ORDER BY tgl ASC
";
$res = $conn->query($sql_hist);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Audit_History_<?= $nim ?></title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; margin: 30px; }
        .kop-table { width: 100%; border-bottom: 3px double #000; margin-bottom: 20px; }
        .inst-name { font-size: 16pt; font-weight: 800; text-transform: uppercase; margin: 0; text-align: center; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .data-table th, .data-table td { border: 1px solid #000; padding: 6px; }
        .data-table th { background: #f2f2f2; text-align: center; font-size: 10px; }
        .text-end { text-align: right; }
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

    <div style="text-align: center;">
        <h2 style="margin-bottom: 5px; text-decoration: underline;">KARTU KENDALI PIUTANG MAHASISWA</h2>
        <span>Ledger Transaction Audit Trail</span>
    </div>

    <table style="width:100%; margin-top:15px; font-weight:bold;">
        <tr><td width="15%">NAMA</td><td>: <?= strtoupper($mhs['nama']) ?></td><td width="15%">PRODI</td><td>: <?= $mhs['nama_prodi'] ?></td></tr>
        <tr><td>NIM</td><td>: <?= $mhs['nim'] ?></td><td>TGL CETAK</td><td>: <?= date('d/m/Y H:i') ?></td></tr>
    </table>

    <table class="data-table">
        <thead>
            <tr><th>TANGGAL</th><th>NO. REFERENSI</th><th>TIPE</th><th>KETERANGAN</th><th class="text-end">DEBET (+)</th><th class="text-end">KREDIT (-)</th><th class="text-end">SALDO</th></tr>
        </thead>
        <tbody>
            <?php $bal = 0; while($row = $res->fetch_assoc()): $bal += ($row['debit'] - $row['kredit']); ?>
            <tr>
                <td style="text-align:center;"><?= date('d/m/Y', strtotime($row['tgl'])) ?></td>
                <td style="text-align:center;"><code><?= $row['ref'] ?></code></td>
                <td style="text-align:center; font-weight:bold;"><?= $row['tipe'] ?></td>
                <td><?= $row['item'] ?></td>
                <td style="text-align:right; color: #10b981; font-weight:bold;"><?= $row['debit']>0?number_format($row['debit']):'-' ?></td>
                <td style="text-align:right; color: #ef4444; font-weight:bold;"><?= $row['kredit']>0?number_format($row['kredit']):'-' ?></td>
                <td style="text-align:right; font-weight:bold;">Rp <?= number_format($bal) ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
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