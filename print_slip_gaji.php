<?php
/**
 * print_slip_gaji.php - SLIP GAJI PREMIUM ENTERPRISE SYIFA ERP
 * Versi: 2.5 (Perfect Symmetry Alignment & True Color Print Edition)
 * Perbaikan Mutlak: 
 * 1. Menggunakan CSS Flexbox tingkat lanjut untuk menjamin kolom tanda tangan sejajar.
 * 2. Menyuntikkan perintah -webkit-print-color-adjust: exact; agar warna 
 * latar (Background) dan kotak (THP) tetap tercetak di PDF persis seperti di layar online.
 */
require_once 'config/koneksi.php';

$id = (int)($_GET['id'] ?? 0);

// 1. Ambil Profil & TTD Dinamis
$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'SLIP_GAJI' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

$sql = "SELECT d.*, p.id as peg_id, p.nama_lengkap, p.nip, p.jabatan, p.unit_kerja, 
               h.periode_bulan, h.periode_tahun, h.tgl_slip, j.no_jurnal as no_slip_resmi 
        FROM hr_payroll_detail d 
        JOIN hr_pegawai p ON d.pegawai_id = p.id 
        JOIN hr_payroll_header h ON d.payroll_id = h.id 
        LEFT JOIN syifa_jurnal j ON h.pembayaran_jurnal_id = j.id
        WHERE d.id = $id";
$data = $conn->query($sql)->fetch_assoc();

if(!$data) die("Data Slip tidak ditemukan.");

$peg_id = $data['peg_id'];
$komponen_res = $conn->query("SELECT pk.nominal, k.nama_komponen, k.jenis FROM hr_pegawai_komponen pk JOIN hr_komponen k ON pk.komponen_id = k.id WHERE pk.pegawai_id = $peg_id ORDER BY k.jenis DESC, k.nama_komponen ASC");
$arr_inc = []; $arr_ded = [];
if($komponen_res) {
    while($k = $komponen_res->fetch_assoc()) {
        if($k['jenis'] == 'Pendapatan') $arr_inc[] = $k; else $arr_ded[] = $k;
    }
}

$bulan_nama = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
$periode_str = $bulan_nama[(int)$data['periode_bulan']] . " " . $data['periode_tahun'];
$total_bruto = 0; $total_deduct = 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Slip_Gaji_<?= $data['nip'] ?>_<?= $data['periode_bulan'].$data['periode_tahun'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; padding: 20px; font-size: 11px; color: #1e293b; }
        .slip-wrapper { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-radius: 12px; border-top: 8px solid #059669; position: relative; }
        .header-top { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 20px; }
        .company-name { font-size: 20px; font-weight: 800; color: #0f172a; margin: 0; letter-spacing: -0.5px; text-transform: uppercase; }
        .company-address { color: #64748b; margin-top: 2px; font-size: 10px; }
        .slip-title { text-align: right; }
        .slip-title h2 { margin: 0; color: #059669; font-weight: 800; font-size: 22px; letter-spacing: 1px; }
        .slip-title p { margin: 2px 0 0 0; color: #64748b; font-size: 10px; font-weight: 600; }
        
        .info-grid { display: flex; flex-wrap: wrap; background: #f8fafc; border-radius: 8px; padding: 15px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .info-col { width: 50%; display: flex; margin-bottom: 6px; }
        .info-col span:first-child { font-weight: 600; color: #64748b; width: 100px; text-transform: uppercase; font-size: 9px; }
        .info-col span:last-child { font-weight: 700; color: #0f172a; flex: 1; }

        .detail-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .detail-table th { background: #0f172a; color: #fff; padding: 8px 12px; font-size: 9px; text-transform: uppercase; text-align: left; }
        .detail-table td { padding: 8px 12px; border-bottom: 1px dashed #e2e8f0; }
        .col-amount { text-align: right; font-weight: 700; font-family: 'Courier New', Courier, monospace; font-size: 12px; }
        
        .subtotal-box { display: flex; justify-content: space-between; padding: 10px 12px; background: #f8fafc; font-weight: 800; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px; font-size: 11px; }
        .thp-container { background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; padding: 15px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .thp-title { color: #065f46; font-weight: 800; font-size: 14px; letter-spacing: -0.5px; }
        .thp-amount { font-size: 24px; font-weight: 900; color: #059669; font-family: 'Courier New', Courier, monospace; }

        /* CSS Modern Flexbox untuk Signature (Perfect Symmetry Bottom Alignment) */
        .sig-wrapper { display: flex; justify-content: space-between; margin-top: 40px; padding: 0 20px; page-break-inside: avoid; gap: 15px; }
        .sig-item { flex: 1; text-align: center; display: flex; flex-direction: column; justify-content: space-between; min-height: 120px; }
        .sig-header { margin-bottom: 50px; }
        .sig-role { font-weight: 600; font-size: 11px; margin-bottom: 2px; color: #64748b; }
        .sig-pos { font-size: 10px; color: #64748b; font-weight: normal; margin-top: 3px; }
        .sig-name-box { margin-top: auto; /* KUNCI MUTLAK: Mendorong garis dan nama ke dasar (rata bawah) */ }
        .sig-name { font-weight: 800; font-size: 12px; text-decoration: underline; color: #0f172a; }

        .watermark { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 120px; opacity: 0.03; font-weight: 900; pointer-events: none; z-index: 0; }
        
        /* 🚀 THE TRUE COLOR PRINT OVERRIDE */
        @media print { 
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            body { background: white !important; padding: 0 !important; margin: 0 !important; } 
            .slip-wrapper { box-shadow: none !important; border-top: 8px solid #059669 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; border-radius: 0 !important;} 
            .no-print { display: none !important; } 
        }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print" style="text-align:center; margin-bottom:20px;">
        <button onclick="window.print()" style="padding:10px 25px; background:#059669; color:#fff; border:none; border-radius:5px; font-weight:bold; cursor:pointer;">CETAK SLIP</button>
    </div>

    <div class="slip-wrapper">
        <div class="watermark">PAID</div>
        
        <div class="header-top">
            <div style="display:flex; align-items:center;">
                <?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="height:50px; margin-right:15px;"><?php endif; ?>
                <div>
                    <h1 class="company-name"><?= strtoupper($profile['institution_name'] ?? 'INSTITUSI') ?></h1>
                    <p class="company-address"><?= $profile['address'] ?? '' ?> | Telp: <?= $profile['phone'] ?? '' ?></p>
                </div>
            </div>
            <div class="slip-title">
                <h2>SLIP GAJI</h2>
                <p>PERIODE: <?= strtoupper($periode_str) ?></p>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-col"><span>NIP / ID</span><span>: <?= $data['nip'] ?></span></div>
            <div class="info-col"><span>NAMA PEGAWAI</span><span>: <?= strtoupper($data['nama_lengkap']) ?></span></div>
            <div class="info-col"><span>JABATAN</span><span>: <?= $data['jabatan'] ?></span></div>
            <div class="info-col"><span>UNIT KERJA</span><span>: <?= $data['unit_kerja'] ?></span></div>
            <div class="info-col"><span>TANGGAL SLIP</span><span>: <?= date('d/m/Y', strtotime($data['tgl_slip'])) ?></span></div>
            <div class="info-col"><span>NO. REFERENSI</span><span>: <?= $data['no_slip_resmi'] ?? 'AUTO-GENERATE' ?></span></div>
        </div>

        <table class="detail-table">
            <tr><th colspan="2" style="background:#059669; color: #fff !important;">PENERIMAAN (INCOME)</th></tr>
            <?php if(empty($arr_inc)): ?>
                <tr><td>Gaji Pokok & Tunjangan Dasar</td><td class="col-amount"><?= number_format($data['gapok'] + $data['tunjangan']) ?></td></tr>
            <?php else: ?>
                <?php foreach($arr_inc as $in): $total_bruto += $in['nominal']; ?>
                <tr><td><?= $in['nama_komponen'] ?></td><td class="col-amount"><?= number_format($in['nominal']) ?></td></tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
        <div class="subtotal-box" style="color: #059669;">
            <span>TOTAL PENERIMAAN KOTOR</span>
            <span>Rp <?= number_format($total_bruto ?: ($data['gapok']+$data['tunjangan'])) ?></span>
        </div>

        <table class="detail-table">
            <tr><th colspan="2" style="background:#b91c1c; color: #fff !important;">POTONGAN (DEDUCTION)</th></tr>
            <?php if(empty($arr_ded)): ?>
                <tr><td>Total Potongan Komulatif</td><td class="col-amount"><?= number_format($data['potongan']) ?></td></tr>
            <?php else: ?>
                <?php foreach($arr_ded as $de): $total_deduct += $de['nominal']; ?>
                <tr><td><?= $de['nama_komponen'] ?></td><td class="col-amount"><?= number_format($de['nominal']) ?></td></tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
        <div class="subtotal-box" style="color: #b91c1c;">
            <span>TOTAL POTONGAN</span>
            <span>Rp <?= number_format($total_deduct ?: $data['potongan']) ?></span>
        </div>

        <div class="thp-container">
            <div class="thp-title">PENGHASILAN BERSIH (TAKE HOME PAY)</div>
            <div class="thp-amount">Rp <?= number_format($data['gaji_bersih']) ?></div>
        </div>

        <!-- DYNAMIC SIGNATURE DENGAN FLEXBOX (SEJAJAR RATA BAWAH SEMPURNA) -->
        <div class="sig-wrapper">
            <!-- Kolom Penerima (Tetap di Kiri) -->
            <div class="sig-item">
                <div class="sig-header">
                    <div class="sig-role">Penerima,</div>
                </div>
                <div class="sig-name-box">
                    <div class="sig-name"><?= strtoupper($data['nama_lengkap']) ?></div>
                    <div class="sig-pos">&nbsp;</div> <!-- Spasi kosong penyeimbang tinggi baris -->
                </div>
            </div>

            <!-- Tanda Tangan Dinamis (Otorisator) -->
            <?php foreach($signatures as $sig): ?>
            <div class="sig-item">
                <div class="sig-header">
                    <div class="sig-role"><?= htmlspecialchars($sig['sign_role']) ?></div>
                </div>
                <div class="sig-name-box">
                    <div class="sig-name"><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></div>
                    <div class="sig-pos"><?= htmlspecialchars($sig['sign_position']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div style="text-align:center; margin-top:30px; font-size:9px; color:#94a3b8;">
            Slip gaji ini di-*generate* secara elektronik dan sah tanpa stempel basah.
        </div>
    </div>
</body>
</html>