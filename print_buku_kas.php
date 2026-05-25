<?php
/**
 * print_buku_kas.php - CETAK REKAP MUTASI KAS & BANK
 * Versi: 2.0 (Sovereign Print Engine - Dynamic Signatures)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak. Silakan login terlebih dahulu."); }

$kode = $conn->real_escape_string($_GET['kode'] ?? '');
$bulan = str_pad((int)($_GET['bulan'] ?? date('m')), 2, '0', STR_PAD_LEFT);
$tahun = (int)($_GET['tahun'] ?? date('Y'));

// --- 1. TARIK DATA MASTER & IDENTITAS INSTITUSI ---
$acc = $conn->query("SELECT * FROM syifa_akun WHERE kode_akun = '$kode'")->fetch_assoc();
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();

$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'BUKU_KAS' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

if (!$acc) { die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h4>Ralat: Akun Kas/Bank tidak ditemukan!</h4></div>"); }

$nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
$bulan_teks = $nama_bulan[(int)$bulan];

$start_date = "$tahun-$bulan-01 00:00:00";
$end_date = date('Y-m-t 23:59:59', strtotime($start_date));

$q_awal = $conn->query("SELECT SUM(debit - kredit) as net FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$kode' AND j.tgl_jurnal < '$start_date' AND j.is_deleted=0")->fetch_assoc();
$saldo_awal = (double)($acc['opening_balance'] ?? 0) + (double)($q_awal['net'] ?? 0);

$mutasi = $conn->query("SELECT j.tgl_jurnal, j.no_jurnal, j.keterangan, j.pihak_nama, jd.debit, jd.kredit FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$kode' AND j.tgl_jurnal BETWEEN '$start_date' AND '$end_date' AND j.is_deleted=0 ORDER BY j.tgl_jurnal ASC, j.id ASC");

function fmtRp($angka) { return number_format($angka ?? 0, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Buku_Kas_<?= $kode ?>_<?= $bulan ?><?= $tahun ?></title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color: #333; margin: 20px; }
        .kop-table { width: 100%; border-bottom: 3px double #000; margin-bottom: 20px; }
        .inst-name { font-size: 16pt; font-weight: 800; text-transform: uppercase; margin: 0; }
        .report-title { font-size: 16px; font-weight: bold; margin: 0 0 5px 0; text-transform: uppercase; text-align: center; }
        .info-table { width: 100%; margin-bottom: 15px; font-weight: bold; font-size: 12px; }
        .data-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .data-table th, .data-table td { border: 1px solid #000; padding: 6px 8px; }
        .data-table th { background-color: #f2f2f2; text-align: center; text-transform: uppercase; }
        .text-center { text-align: center; } .text-right { text-align: right; } .text-bold { font-weight: bold; }
        
        .signature-container { margin-top: 40px; width: 100%; page-break-inside: avoid; }
        .sign-table { width: 100%; text-align: center; font-family: Arial, sans-serif; font-size: 10pt; border: none; }
        .sign-table td { border: none; padding: 5px; }
        .sign-line { border-bottom: 1px solid #000; margin: 60px auto 5px auto; width: 80%; }
        
        @media print { .no-print { display: none; } body { margin: 0; } }
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
        <h3 class="report-title">REKAPITULASI BUKU KAS & BANK</h3>
        <p style="margin-top:0;">Periode: <?= $bulan_teks ?> <?= $tahun ?></p>
    </div>

    <table class="info-table">
        <tr>
            <td width="15%">REKENING / AKUN</td><td width="2%">:</td>
            <td><?= $acc['kode_akun'] ?> - <?= strtoupper($acc['nama_akun']) ?></td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th width="40">NO</th><th width="90">TANGGAL</th><th width="120">NO. BUKTI</th>
                <th>PIHAK & URAIAN TRANSAKSI</th><th width="110">MASUK (DEBIT)</th><th width="110">KELUAR (KREDIT)</th><th width="120">SALDO (RP)</th>
            </tr>
        </thead>
        <tbody>
            <tr style="background-color: #f9f9f9;">
                <td colspan="4" class="text-bold text-center">SALDO AWAL KAS PER 01 <?= strtoupper($bulan_teks) ?> <?= $tahun ?></td>
                <td></td><td></td><td class="text-right text-bold"><?= fmtRp($saldo_awal) ?></td>
            </tr>
            <?php 
            $no = 1; $run_bal = $saldo_awal; $tot_debit = 0; $tot_kredit = 0;
            if($mutasi && $mutasi->num_rows > 0): 
                while($r = $mutasi->fetch_assoc()): 
                    $d = (double)$r['debit']; 
                    $k = (double)$r['kredit'];
                    $tot_debit += $d; $tot_kredit += $k;
                    $run_bal += ($d - $k);
            ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td><td class="text-center"><?= date('d/m/Y', strtotime($r['tgl_jurnal'])) ?></td>
                <td class="text-center"><?= $r['no_jurnal'] ?></td>
                <td><b><?= htmlspecialchars($r['pihak_nama']) ?: 'Umum' ?></b><br><span style="color:#555;"><?= htmlspecialchars($r['keterangan']) ?></span></td>
                <td class="text-right"><?= $d > 0 ? fmtRp($d) : '-' ?></td>
                <td class="text-right"><?= $k > 0 ? fmtRp($k) : '-' ?></td>
                <td class="text-right text-bold"><?= fmtRp($run_bal) ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="7" class="text-center" style="padding: 20px; font-style: italic;">Tidak ada transaksi pada periode ini.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #f2f2f2;">
                <th colspan="4" class="text-right">TOTAL MUTASI PERIODE BERJALAN</th>
                <th class="text-right"><?= fmtRp($tot_debit) ?></th>
                <th class="text-right"><?= fmtRp($tot_kredit) ?></th>
                <th class="text-right"></th>
            </tr>
            <tr style="background-color: #e0e0e0;">
                <th colspan="6" class="text-right" style="font-size: 13px;">SALDO AKHIR KUMULATIF</th>
                <th class="text-right" style="font-size: 13px;">Rp <?= fmtRp($run_bal) ?></th>
            </tr>
        </tfoot>
    </table>

    <!-- ?? DYNAMIC SIGNATURE -->
    <div class="signature-container">
        <table class="sign-table">
            <tr>
                <td width="33%"><?= !empty($ttd['pembuat']['sign_name']) ? 'Disusun Oleh,' : '' ?></td>
                <td width="33%"><?= !empty($ttd['pemeriksa']['sign_name']) ? 'Diperiksa Oleh,' : '' ?></td>
                <td width="33%">Pontianak, <?= date('d M Y') ?><br><?= !empty($ttd['penyetuju']['sign_name']) ? 'Disetujui Oleh,' : 'Pimpinan,' ?></td>
            </tr>
            <tr>
                <td><?php if(!empty($ttd['pembuat']['sign_name'])): ?><div class="sign-line"></div><b><?= $ttd['pembuat']['sign_name'] ?></b><br><span><?= $ttd['pembuat']['sign_position'] ?></span><?php endif; ?></td>
                <td><?php if(!empty($ttd['pemeriksa']['sign_name'])): ?><div class="sign-line"></div><b><?= $ttd['pemeriksa']['sign_name'] ?></b><br><span><?= $ttd['pemeriksa']['sign_position'] ?></span><?php endif; ?></td>
                <td><?php if(!empty($ttd['penyetuju']['sign_name'])): ?><div class="sign-line"></div><b><?= $ttd['penyetuju']['sign_name'] ?></b><br><span><?= $ttd['penyetuju']['sign_position'] ?></span><?php else: ?><div class="sign-line"></div><b>( ____________________ )</b><?php endif; ?></td>
            </tr>
        </table>

        <!-- TANDA TANGAN -->
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