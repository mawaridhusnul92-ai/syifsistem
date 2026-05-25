<?php
/**
 * print_invoice_mhs.php - FORMAT INVOICE & KUITANSI (DYNAMIC SIGNATURE)
 * Versi: 4.0 (Sovereign Grand Master - Global Sync Edition)
 */
require_once 'config/koneksi.php';

$no_inv = $_GET['no_inv'] ?? '';
$log_id = (int)($_GET['log_id'] ?? 0);

if(empty($no_inv) && $log_id == 0) { die("Parameter dokumen tidak valid."); }

$doc_type = ''; $h = null; $items = []; $total = 0;

if (!empty($no_inv)) {
    $doc_type = 'INVOICE';
    $sql_h = "SELECT j.no_jurnal as no_doc, j.tgl_jurnal as tgl_doc, j.keterangan as ket_doc, m.nama, m.nim, p.nama_prodi, m.angkatan FROM syifa_jurnal j JOIN syifa_jurnal_detail jd ON j.id = jd.jurnal_id JOIN syifa_mahasiswa m ON jd.mahasiswa_id = m.id LEFT JOIN mhs_prodi p ON m.prodi_id = p.id WHERE j.no_jurnal = '$no_inv' LIMIT 1";
    $h = $conn->query($sql_h)->fetch_assoc();
    if (!$h) die("Data Invoice tidak ditemukan.");
    
    $res_items = $conn->query("SELECT nama_tagihan, nominal FROM keuangan_tagihan WHERE no_jurnal = '$no_inv'");
    if($res_items) { while($row = $res_items->fetch_assoc()){ $items[] = ['nama' => $row['nama_tagihan'], 'nominal' => $row['nominal']]; $total += $row['nominal']; } }
    $title_doc = "INVOICE TAGIHAN"; $total_label = "TOTAL TAGIHAN PIUTANG";

} elseif ($log_id > 0) {
    $doc_type = 'RECEIPT';
    $sql_h = "SELECT l.no_kuitansi as no_doc, l.tanggal_bayar as tgl_doc, j.keterangan as ket_doc, m.nama, m.nim, p.nama_prodi, m.angkatan, l.link_jurnal_id FROM keuangan_pembayaran_log l JOIN syifa_jurnal j ON l.link_jurnal_id = j.id JOIN syifa_mahasiswa m ON l.nim = m.nim LEFT JOIN mhs_prodi p ON m.prodi_id = p.id WHERE l.id = $log_id LIMIT 1";
    $h = $conn->query($sql_h)->fetch_assoc();
    if (!$h) die("Data Kuitansi Pembayaran tidak ditemukan.");

    $jid = $h['link_jurnal_id'];
    $res_items = $conn->query("SELECT t.nama_tagihan, l.nominal_bayar as nominal FROM keuangan_pembayaran_log l JOIN keuangan_tagihan t ON l.tagihan_id = t.id WHERE l.link_jurnal_id = $jid");
    if($res_items) { while($row = $res_items->fetch_assoc()){ $items[] = ['nama' => $row['nama_tagihan'], 'nominal' => $row['nominal']]; $total += $row['nominal']; } }
    $title_doc = "KUITANSI PEMBAYARAN"; $total_label = "TOTAL TELAH DIBAYAR";
}

// ?? TARIK PROFIL & TANDA TANGAN DINAMIS
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'INVOICE_MHS' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title><?= $doc_type == 'INVOICE' ? 'Invoice_' : 'Kuitansi_' ?><?= $h['no_doc'] ?></title>
    <style>
        body { font-family: 'Arial', sans-serif; font-size: 12px; padding: 40px; color: #333; line-height: 1.5; }
        .header-table { width: 100%; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 25px; }
        .info-table { width: 100%; margin-bottom: 30px; }
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .item-table th { background: #f1f5f9; padding: 12px; border: 1px solid #cbd5e1; text-align: left; }
        .item-table td { padding: 12px; border: 1px solid #cbd5e1; }
        .total-row td { font-weight: bold; background: #f8fafc; font-size: 14px; }
        .status-badge { display: inline-block; padding: 6px 15px; border-radius: 6px; color: white; font-weight: bold; margin-top: 10px; font-size: 14px; letter-spacing: 1px; }
        .bg-paid { background-color: #10b981; border: 2px solid #059669; }
        .bg-unpaid { background-color: #ef4444; border: 2px solid #b91c1c; }
        .signature-box { margin-top: 50px; width: 100%; display: flex; justify-content: flex-end; text-align: center; }
        .signature-area { width: 250px; }
        .signature-line { border-bottom: 1px solid #000; height: 70px; margin-bottom: 5px; }
        .watermark { position: absolute; top: 35%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 120px; opacity: 0.04; font-weight: 900; pointer-events: none; text-transform: uppercase; z-index: -1; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="watermark"><?= $doc_type == 'RECEIPT' ? 'L U N A S' : 'T A G I H A N' ?></div>
    <table class="header-table">
        <tr>
            <td width="12%">
                <?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height: 70px;"><?php endif; ?>
            </td>
            <td width="53%">
                <h2 style="margin:0; font-size: 18px; color: #0f172a;"><?= strtoupper($profile['institution_name'] ?? 'INSTITUSI') ?></h2>
                <p style="margin:5px 0 0 0; color: #64748b; font-size: 11px;"><?= $profile['address'] ?? '' ?>, <?= $profile['city'] ?? '' ?></p>
            </td>
            <td width="35%" align="right">
                <h1 style="margin:0; color: <?= $doc_type == 'RECEIPT' ? '#10b981' : '#0d6efd' ?>; letter-spacing: 1px; font-size: 24px;"><?= $title_doc ?></h1>
                <p style="margin:5px 0 0 0; font-size:12px; color: #475569;">No Bukti: <b><?= $h['no_doc'] ?></b></p>
                <?php if($doc_type == 'RECEIPT'): ?><div class="status-badge bg-paid">LUNAS DIBAYAR</div>
                <?php else: ?><div class="status-badge bg-unpaid">MENUNGGU PEMBAYARAN</div><?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr><td width="15%" style="color:#64748b;">NAMA MHS</td><td width="40%">: <b style="font-size: 14px;"><?= strtoupper($h['nama']) ?></b></td><td width="15%" style="color:#64748b;">TANGGAL</td><td width="30%">: <b><?= date('d M Y', strtotime($h['tgl_doc'])) ?></b></td></tr>
        <tr><td style="color:#64748b;">NIM MAHASISWA</td><td>: <b><?= $h['nim'] ?></b></td><td style="color:#64748b;">KETERANGAN</td><td>: <?= $h['ket_doc'] ?></td></tr>
        <tr><td style="color:#64748b;">PROGRAM STUDI</td><td>: <?= $h['nama_prodi'] ?> (Akt. <?= $h['angkatan'] ?>)</td><td colspan="2"></td></tr>
    </table>

    <table class="item-table">
        <thead><tr><th width="50" style="text-align: center;">NO</th><th>JENIS TAGIHAN / KOMPONEN BIAYA</th><th style="text-align: right;" width="200">SUBTOTAL (RP)</th></tr></thead>
        <tbody>
            <?php $no=1; foreach($items as $row): ?>
            <tr><td align="center"><?= $no++ ?></td><td style="font-weight: bold;"><?= strtoupper($row['nama']) ?></td><td align="right"><?= number_format($row['nominal'], 0, ',', '.') ?></td></tr>
            <?php endforeach; ?>
            <tr class="total-row"><td colspan="2" align="right" style="padding-right: 20px;"><?= $total_label ?></td><td align="right" style="color: <?= $doc_type == 'RECEIPT' ? '#10b981' : '#0f172a' ?>;">Rp <?= number_format($total, 0, ',', '.') ?></td></tr>
        </tbody>
    </table>

    <div class="signature-box">
        <?php if(!empty($signatures)): 
            $width = floor(100 / count($signatures)) . '%';
        ?>
        <table class="sign-table" style="margin-top: 20px;">
            <tr>
                <?php foreach($signatures as $sig): ?>
                    <td width="<?= $width ?>"><?= htmlspecialchars($sig['sign_role']) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach($signatures as $sig): ?>
                    <td>
                        <div style="height: 50px;"></div>
                        <b><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></b><br>
                        <span style="color:#64748b; font-size:10px;"><?= htmlspecialchars($sig['sign_position']) ?></span>
                    </td>
                <?php endforeach; ?>
            </tr>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>