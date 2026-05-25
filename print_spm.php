<?php
/**
 * print_spm.php - SPM DOCUMENT GENERATOR
 * Versi: 4.1 (Sovereign Grand Master - Pure Landscape & Isolated Signature)
 * Perbaikan Mutlak: Menghapus include signature UI agar tidak bocor, 
 * mengunci ke A4 Landscape mutlak, dan Auto-Print.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

// HAPUS REQUIRE SIGNATURE.PHP DI SINI AGAR UI TIDAK BOCOR KE HALAMAN CETAK

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak: Silakan login terlebih dahulu."); }

$spm_id = (int)($_GET['id'] ?? 0);
if ($spm_id <= 0) die("ID SPM tidak valid.");

// 1. Tarik Data SPM
$spm = $conn->query("SELECT * FROM keuangan_spm_header WHERE id = $spm_id")->fetch_assoc();
if (!$spm) { die("<h1>Dokumen SPM tidak ditemukan!</h1>"); }
$details = $conn->query("SELECT d.*, a.nama_akun as nama_coa FROM keuangan_spm_detail d LEFT JOIN syifa_akun a ON d.kode_akun = a.kode_akun WHERE d.spm_id = $spm_id")->fetch_all(MYSQLI_ASSOC);

// 2. Tarik Profil Institusi
$q_app = $conn->query("SELECT * FROM system_profile WHERE id=1");
$app = $q_app ? $q_app->fetch_assoc() : null;

$logo_path = (!empty($app['logo']) && file_exists("assets/img/" . $app['logo'])) ? "assets/img/" . $app['logo'] : "";
$inst_name = $app['institution_name'] ?? 'STIKes YARSI PONTIANAK';
$alamat = $app['address'] ?? 'Jl. Letjen Sutoyo, Kota Pontianak, Kalimantan Barat';
$telp = $app['phone'] ?? '(0561) 123456';
$email = $app['email'] ?? 'info@stikesyarsi.ac.id';
$kota = $app['city'] ?? 'Pontianak';

// Fungsi Render Tanda Tangan Independen (Bypass Form UI)
function renderSignatureSpmPrint($kota, $tgl_dokumen) {
    global $conn;
    $html = "<table width='100%' style='margin-top: 15mm; font-size: 10pt; page-break-inside: avoid; text-align: center; border:none;'><tr>";
    
    $q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'SPM' ORDER BY id ASC");
    $signatures = []; 
    if($q_ttd && $q_ttd->num_rows > 0) {
        while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;
    } else {
        $signatures = [
            ['sign_role' => 'Dibuat Oleh', 'sign_position' => 'Bendahara Pengeluaran', 'sign_name' => ''],
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
    <title>Cetak SPM - <?= htmlspecialchars($spm['nama_spm']) ?></title>
    <style>
        @page { size: A4 landscape; margin: 10mm 15mm; }
        body { font-family: 'Arial', sans-serif; font-size: 9pt; color: #000; line-height: 1.4; margin: 0; padding: 20px; background: #525659; }
        .a4-paper { background: #fff; width: 297mm; min-height: 210mm; margin: 0 auto; padding: 15mm 20mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); color: #000; box-sizing: border-box; }
        
        .kop-table { width: 100%; border-bottom: 2.5pt solid #000; margin-bottom: 15px; border-collapse: collapse; }
        .kop-table td { border: none; padding: 0 0 5px 0; vertical-align: middle; }
        .kop-logo { max-height: 75px; width: auto; }
        .inst-name { font-size: 14pt; font-weight: 900; text-transform: uppercase; margin: 0; letter-spacing: 1px; }
        .inst-info { font-size: 8.5pt; color: #333; margin: 0; margin-top: 2px; }
        
        .report-title { font-size: 13pt; font-weight: bold; text-decoration: underline; margin: 10px 0 3px; text-align: center; text-transform: uppercase; }
        .report-period { font-size: 10pt; text-align: center; margin-bottom: 20px; font-weight: bold; }
        
        table.table-data { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
        table.table-data th { background: #f2f2f2 !important; border: 1px solid #000; padding: 10px 6px; font-size: 9pt; text-transform: uppercase; text-align: center; vertical-align: middle; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.table-data td { border: 1px solid #000; padding: 8px 10px; vertical-align: middle; word-wrap: break-word; font-size: 9.5pt; }
        
        .row-grand-total { background: #eef2ff !important; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .row-grand-total td { padding: 12px 10px; font-size: 10.5pt; border: 1px solid #000; }

        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .text-bold { font-weight: bold; }
        .badge-tambahan { display: inline-block; padding: 3px 8px; border: 1px solid #000; font-size: 8.5pt; font-weight: bold; border-radius: 3px; margin-top: 5px; }

        /* MURNI CETAK - MENGHILANGKAN SEMUA UI BACKGROUND */
        @media print {
            body { background: #fff; padding: 0; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .a4-paper { box-shadow: none; margin: 0; width: 100%; height: auto; min-height: auto; padding: 0; border: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body onload="setTimeout(function(){ window.print(); }, 500);">
    
    <div style="text-align: center; margin-bottom: 20px;" class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #0d6efd; color: #fff; border: none; border-radius: 5px; font-weight: bold;">🖨️ CETAK DOKUMEN SPM</button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #dc3545; color: #fff; border: none; border-radius: 5px; font-weight: bold; margin-left: 10px;">TUTUP</button>
    </div>

    <div class="a4-paper">
        <table class="kop-table">
            <tr>
                <td width="12%" style="text-align:left;">
                    <?php if($logo_path): ?>
                        <img src="<?= $logo_path ?>" class="kop-logo">
                    <?php else: ?>
                        <div style="width:70px;height:70px;border:1px solid #000;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;margin:auto;">LOGO</div>
                    <?php endif; ?>
                </td>
                <td width="88%" style="text-align:center;">
                    <h1 class="inst-name"><?= $inst_name ?></h1>
                    <p class="inst-info"><?= $alamat ?> | Telp: <?= $telp ?> | Email: <?= $email ?></p>
                </td>
            </tr>
        </table>

        <div class="report-title">SURAT PERINTAH MEMBAYAR (SPM)</div>
        <div class="report-period">
            Periode: <?= date('d F Y', strtotime($spm['tgl_mulai'])) ?> s.d <?= date('d F Y', strtotime($spm['tgl_akhir'])) ?><br>
            <?php if($spm['is_tambahan'] == 1): ?><span class="badge-tambahan">DOKUMEN SPM TAMBAHAN (SUSULAN)</span><?php endif; ?>
        </div>

        <div style="font-size: 10pt; margin-bottom: 4mm; text-align: justify; line-height: 1.5;">
            Berdasarkan Rencana Anggaran Pendapatan dan Belanja (RAPB) serta pengajuan yang telah diverifikasi, dengan ini memerintahkan kepada Bendahara Pengeluaran untuk melakukan pembayaran atas rincian mata anggaran di bawah ini:
        </div>

        <table class="table-data">
            <thead>
                <tr>
                    <th width="5%">No.</th>
                    <th width="45%">Rincian / Uraian Pembayaran</th>
                    <th width="30%">Kode & Nama Akun COA</th>
                    <th width="20%">Jumlah Nominal (Rp)</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; if(!empty($details)): foreach($details as $d): ?>
                <tr>
                    <td class="text-center"><?= $no++ ?>.</td>
                    <td><?= htmlspecialchars($d['rincian']) ?></td>
                    <td>
                        <strong style="font-family:'Courier New', monospace; font-size:10pt;"><?= htmlspecialchars($d['kode_akun']) ?></strong><br>
                        <span style="color: #555; font-size: 8.5pt; display: block; margin-top: 3px;"><?= htmlspecialchars($d['nama_coa']) ?></span>
                    </td>
                    <td class="text-end text-bold"><?= number_format($d['nominal'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center" style="font-style: italic;">Tidak ada rincian anggaran.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="row-grand-total">
                    <td colspan="3" class="text-end">TOTAL PERINTAH BAYAR</td>
                    <td class="text-end">Rp <?= number_format($spm['total_nominal'], 0, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>

        <?= renderSignatureSpmPrint($kota, $spm['created_at']) ?>
    </div>
</body>
</html>