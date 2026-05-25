<?php
/**
 * print_rekap_tagihan.php - CETAK REKAPITULASI TAGIHAN MAHASISWA
 * Versi: 2.1 (Sovereign Grand Master - Dynamic Signatures Edition)
 */
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses ditolak. Silakan login terlebih dahulu."); }

$nim = $conn->real_escape_string($_GET['nim'] ?? '');
if (empty($nim)) { die("NIM Mahasiswa tidak valid atau kosong."); }

// 1. Ambil Profil & TTD Dinamis
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$inst_name = strtoupper($profile['institution_name'] ?? 'STIKES YARSI PONTIANAK');
$inst_addr = $profile['address'] ?? 'Jl. Panglima Aim, Pontianak Timur';
$inst_phone = $profile['phone'] ?? '';
$inst_email = $profile['email'] ?? '';

$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'AKADEMIK'");
$ttd = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $ttd[strtolower($r['sign_role'])] = $r;

// 2. Ambil Data Mahasiswa Terintegrasi
$sql_mhs = "SELECT m.*, p.nama_prodi, s.nama_sistem 
            FROM syifa_mahasiswa m 
            LEFT JOIN mhs_prodi p ON m.prodi_id = p.id 
            LEFT JOIN mhs_sistem_kuliah s ON m.sistem_kuliah = s.kode_sistem 
            WHERE m.nim = '$nim' LIMIT 1";
$mhs = $conn->query($sql_mhs)->fetch_assoc();

if (!$mhs) { die("Data Mahasiswa dengan NIM '$nim' tidak ditemukan di database."); }

// 3. Ambil Rincian Seluruh Tagihan (Status Apapun)
$sql_tagihan = "SELECT * FROM keuangan_tagihan WHERE nim = '$nim' ORDER BY kode_tahun DESC, id ASC";
$res_tagihan = $conn->query($sql_tagihan);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap_Tagihan_<?= $mhs['nim'] ?></title>
    <style>
        body { font-family: 'Arial', sans-serif; font-size: 12px; padding: 40px; color: #333; line-height: 1.5; }
        .header-table { width: 100%; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 25px; }
        .info-table { width: 100%; margin-bottom: 30px; }
        .info-table td { padding: 4px 0; }
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .item-table th { background: #f1f5f9; padding: 10px; border: 1px solid #cbd5e1; text-align: center; font-size: 11px; }
        .item-table td { padding: 10px; border: 1px solid #cbd5e1; vertical-align: middle; }
        .total-row td { font-weight: bold; background: #f8fafc; font-size: 13px; border-top: 2px solid #333; }
        
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 10px; text-transform: uppercase; text-align: center; min-width: 70px; border: 1px solid transparent; }
        .st-lunas { background-color: #ecfdf5; color: #059669; border-color: #a7f3d0; }
        .st-sebagian { background-color: #fefce8; color: #b45309; border-color: #fde047; }
        .st-belum { background-color: #fef2f2; color: #dc2626; border-color: #fecaca; }
        
        .signature-container { margin-top: 40px; width: 100%; page-break-inside: avoid; }
        .sign-table { width: 100%; text-align: center; font-family: Arial, sans-serif; font-size: 10pt; border: none; }
        .sign-table td { border: none; padding: 5px; }
        .sign-line { border-bottom: 1px solid #000; margin: 60px auto 5px auto; width: 80%; }
        
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 100px; opacity: 0.03; font-weight: 900; pointer-events: none; text-transform: uppercase; z-index: -1; white-space: nowrap; }

        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #0d6efd; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 14px; box-shadow: 0 4px 6px rgba(13,110,253,0.2);">CETAK REKAP SEKARANG</button>
    </div>

    <!-- WATERMARK -->
    <div class="watermark">STATEMENT OF ACCOUNT</div>

    <table class="header-table">
        <tr>
            <td width="15%">
                <?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height:70px;"><?php endif; ?>
            </td>
            <td width="55%">
                <h2 style="margin:0; font-size: 20px; color: #0f172a;"><?= $inst_name ?></h2>
                <p style="margin:5px 0 0 0; color: #64748b; font-size: 11px;"><?= $inst_addr ?></p>
                <?php if($inst_phone || $inst_email): ?>
                <p style="margin:2px 0 0 0; color: #64748b; font-size: 11px;">Telp: <?= $inst_phone ?> | Email: <?= $inst_email ?></p>
                <?php endif; ?>
            </td>
            <td width="30%" align="right">
                <h1 style="margin:0; color: #0f172a; letter-spacing: 1px; font-size: 22px;">REKAP TAGIHAN</h1>
                <p style="margin:5px 0 0 0; font-size:12px; color: #475569;">Dicetak Tanggal: <b><?= date('d M Y') ?></b></p>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td width="15%" style="color:#64748b; font-weight:bold;">NAMA MAHASISWA</td><td width="35%">: <b style="font-size: 14px; color: #000;"><?= strtoupper($mhs['nama']) ?></b></td>
            <td width="15%" style="color:#64748b; font-weight:bold;">PROGRAM STUDI</td><td width="35%">: <b><?= $mhs['nama_prodi'] ?? '-' ?></b></td>
        </tr>
        <tr>
            <td style="color:#64748b; font-weight:bold;">NIM / ID</td><td>: <b><?= $mhs['nim'] ?></b></td>
            <td style="color:#64748b; font-weight:bold;">SISTEM KULIAH</td><td>: <b><?= $mhs['nama_sistem'] ?? '-' ?></b></td>
        </tr>
        <tr>
            <td style="color:#64748b; font-weight:bold;">TAHUN ANGKATAN</td><td>: <b><?= $mhs['angkatan'] ?? '-' ?></b></td>
            <td style="color:#64748b; font-weight:bold;">STATUS AKADEMIK</td>
            <td>: <b><?= isset($mhs['status_aktif']) ? ($mhs['status_aktif'] == 1 ? 'AKTIF' : 'TIDAK AKTIF') : (isset($mhs['status']) ? strtoupper($mhs['status']) : 'AKTIF') ?></b></td>
        </tr>
    </table>

    <table class="item-table">
        <thead>
            <tr>
                <th width="40">NO</th>
                <th width="80">PERIODE</th>
                <th style="text-align: left; padding-left: 15px;">JENIS TAGIHAN / KOMPONEN</th>
                <th width="120" style="text-align: right;">NOMINAL (RP)</th>
                <th width="120" style="text-align: right;">TERBAYAR (RP)</th>
                <th width="120" style="text-align: right;">SISA/TUNGGAKAN (RP)</th>
                <th width="100">STATUS</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no=1; 
            $t_nominal = 0; $t_terbayar = 0; $t_sisa = 0;
            if($res_tagihan && $res_tagihan->num_rows > 0):
                while($row = $res_tagihan->fetch_assoc()): 
                    $nominal = (double)$row['nominal'];
                    $terbayar = (double)$row['terbayar'];
                    $sisa = $nominal - $terbayar;
                    
                    $t_nominal += $nominal;
                    $t_terbayar += $terbayar;
                    $t_sisa += $sisa;

                    $st_lbl = strtoupper($row['status_bayar']);
                    $st_cls = match($st_lbl) {
                        'LUNAS' => 'st-lunas',
                        'SEBAGIAN' => 'st-sebagian',
                        default => 'st-belum'
                    };
            ?>
            <tr>
                <td align="center"><?= $no++ ?></td>
                <td align="center"><b><?= $row['kode_tahun'] ?></b></td>
                <td style="font-weight: bold; padding-left: 15px; color: #1e293b;"><?= htmlspecialchars($row['nama_tagihan']) ?></td>
                <td align="right"><?= number_format($nominal, 0, ',', '.') ?></td>
                <td align="right"><?= number_format($terbayar, 0, ',', '.') ?></td>
                <td align="right" style="color: <?= $sisa > 0 ? '#dc2626' : 'inherit' ?>; font-weight: <?= $sisa > 0 ? 'bold' : 'normal' ?>;"><?= number_format($sisa, 0, ',', '.') ?></td>
                <td align="center"><span class="status-badge <?= $st_cls ?>"><?= $st_lbl ?></span></td>
            </tr>
            <?php endwhile; else: ?>
            <tr>
                <td colspan="7" align="center" style="padding: 30px; color: #64748b; font-style: italic;">Belum ada riwayat tagihan terdaftar untuk mahasiswa ini.</td>
            </tr>
            <?php endif; ?>
            
            <tr class="total-row">
                <td colspan="3" align="right" style="padding-right: 15px; text-transform: uppercase;">Total Keseluruhan</td>
                <td align="right">Rp <?= number_format($t_nominal, 0, ',', '.') ?></td>
                <td align="right">Rp <?= number_format($t_terbayar, 0, ',', '.') ?></td>
                <td align="right" style="color: <?= $t_sisa > 0 ? '#dc2626' : '#059669' ?>;">Rp <?= number_format($t_sisa, 0, ',', '.') ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="signature-container">
        <table class="sign-table">
            <tr>
                <td width="33%"><?= !empty($ttd['pembuat']['sign_name']) ? 'Disusun Oleh,' : '' ?></td>
                <td width="33%"><?= !empty($ttd['pemeriksa']['sign_name']) ? 'Diperiksa Oleh,' : '' ?></td>
                <td width="33%">Pontianak, <?= date('d M Y') ?><br><?= !empty($ttd['penyetuju']['sign_name']) ? 'Disetujui Oleh,' : 'Bagian Keuangan / Kasir,' ?></td>
            </tr>
            <tr>
                <td><?php if(!empty($ttd['pembuat']['sign_name'])): ?><div class="sign-line"></div><b><?= $ttd['pembuat']['sign_name'] ?></b><br><span><?= $ttd['pembuat']['sign_position'] ?></span><?php endif; ?></td>
                <td><?php if(!empty($ttd['pemeriksa']['sign_name'])): ?><div class="sign-line"></div><b><?= $ttd['pemeriksa']['sign_name'] ?></b><br><span><?= $ttd['pemeriksa']['sign_position'] ?></span><?php endif; ?></td>
                <td><?php if(!empty($ttd['penyetuju']['sign_name'])): ?><div class="sign-line"></div><b><?= $ttd['penyetuju']['sign_name'] ?></b><br><span><?= $ttd['penyetuju']['sign_position'] ?></span><?php else: ?><div class="sign-line"></div><b>( ____________________ )</b><?php endif; ?></td>
            </tr>
        </table>
    </div>

    <div style="margin-top: 40px; font-size: 10px; color: #94a3b8; text-align: center; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
        Dokumen ini dicetak otomatis oleh Sistem ERP Terpadu pada <?= date('d/m/Y H:i') ?>.<br>
        Rincian ini adalah hasil rekonsiliasi database mutlak dan sah sebagai informasi riwayat tagihan mahasiswa (Statement of Account).
    </div>

</body>
</html>