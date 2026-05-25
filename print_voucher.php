<?php
/**
 * print_voucher.php - CETAK BUKTI TRANSAKSI (RECEIPT/VOUCHER)
 * Versi: 4.0 (Enterprise Integrated - Unified Single Door Printer)
 * Perbaikan Mutlak: 
 * Menambahkan deteksi Invoice "INV-" secara otomatis, sehingga mesin 
 * cetak ini bertransformasi menjadi "Satu Pintu" untuk mencetak KWITANSI 
 * PENERIMAAN KAS, BUKTI PENGELUARAN, maupun INVOICE TAGIHAN MAHASISWA.
 */
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) { die("Akses ditolak."); }
$id = (int)$_GET['id'];

// 1. Ambil Profil Institusi & TTD
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'VOUCHER' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

// 2. Ambil Header Jurnal
$h = $conn->query("SELECT * FROM syifa_jurnal WHERE id = $id")->fetch_assoc();
if (!$h) die("Data transaksi tidak ditemukan.");

// 3. Deteksi Tipe (Penerimaan, Pengeluaran, atau Tagihan Piutang)
$main_acc = $h['akun_utama_kode'] ?? '';
$is_income = true; 

if (!empty($main_acc)) {
    $check_d = $conn->query("SELECT debit FROM syifa_jurnal_detail WHERE jurnal_id = $id AND kode_akun = '$main_acc' LIMIT 1")->fetch_assoc();
    if ($check_d) { $is_income = ($check_d['debit'] > 0); }
} else {
    if (strpos($h['no_jurnal'], 'BKK') === 0 || strpos(strtolower($h['no_jurnal']), 'keluar') !== false) {
        $is_income = false;
    }
}

// THE UNIFIED SINGLE DOOR ENGINE
// Jika nomor jurnal berawalan 'INV', cetak sebagai Invoice Piutang
if (strpos($h['no_jurnal'], 'INV') === 0) {
    $title = "INVOICE / BUKTI TAGIHAN";
    $party_label = "Ditagihkan Kepada";
} else {
    // Jika KAS MASUK (BKM) akan otomatis menjadi KWITANSI
    $title = $is_income ? "KWITANSI / BUKTI PENERIMAAN KAS" : "BUKTI PENGELUARAN KAS";
    $party_label = $is_income ? "Diterima Dari" : "Dibayarkan Kepada";
}

$person_name = !empty($h['pihak_nama']) ? $h['pihak_nama'] : "Umum";

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Voucher_<?= $h['no_jurnal'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #fff; font-family: 'Segoe UI', sans-serif; padding: 20px; color: #000; }
        .voucher-container { width: 210mm; min-height: 140mm; padding: 30px; margin: auto; border: 2px solid #000; position: relative; }
        .header-v { display: flex; align-items: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px; }
        .logo-v { height: 65px; margin-right: 20px; }
        .inst-name { font-size: 18px; font-weight: 900; margin: 0; text-transform: uppercase; }
        .inst-addr { font-size: 11px; margin: 0; line-height: 1.2; }
        .title-doc { font-size: 20px; text-decoration: underline; font-weight: 900; margin-bottom: 0; }
        
        .sig-wrapper { display: flex; justify-content: space-between; margin-top: 40px; page-break-inside: avoid; gap: 15px; }
        .sig-item { flex: 1; text-align: center; display: flex; flex-direction: column; justify-content: space-between; min-height: 140px; }
        .sig-header { margin-bottom: 40px; } 
        .sig-role { font-weight: bold; font-size: 12px; margin-bottom: 2px; }
        .sig-pos { font-size: 12px; color: #333; font-weight: normal; }
        .sig-name-box { margin-top: auto; }
        .sig-line { border-bottom: 1px solid #000; width: 85%; margin: 0 auto 5px auto; }
        .sig-name { font-weight: bold; font-size: 12px; }
        
        @media print { .no-print { display: none; } .voucher-container { border: 2px solid #000; } }
    </style>
</head>
<body onload="window.print()">
    <div class="no-print text-center mb-4"><button onclick="window.print()" class="btn btn-dark px-5 fw-bold shadow">CETAK BUKTI TRANSAKSI</button></div>

    <div class="voucher-container">
        <div class="header-v">
            <?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" class="logo-v"><?php endif; ?>
            <div style="flex:1">
                <h2 class="inst-name"><?= $profile['institution_name'] ?? 'INSTITUSI' ?></h2>
                <p class="inst-addr"><?= $profile['address'] ?? '' ?>, <?= $profile['city'] ?? '' ?></p>
                <p class="inst-addr">Telp: <?= $profile['phone'] ?? '-' ?> | Email: <?= $profile['email'] ?? '-' ?></p>
            </div>
            <div class="text-end">
                <h3 class="title-doc" style="<?= strpos($title, 'TAGIHAN') !== false ? 'color:#dc3545;' : 'color:#198754;' ?>"><?= $title ?></h3>
                <div class="fw-bold mt-1 fs-5">No: <?= $h['no_jurnal'] ?></div>
            </div>
        </div>

        <table class="table table-borderless mb-4">
            <tr>
                <td width="20%" class="fw-bold"><?= strtoupper($party_label) ?></td><td width="2%">:</td>
                <td class="border-bottom border-dark fw-bold"><?= strtoupper($person_name) ?></td>
                <td width="15%" class="text-end fw-bold">TANGGAL:</td><td width="15%" class="text-end border-bottom border-dark"><?= date('d/m/Y', strtotime($h['tgl_jurnal'])) ?></td>
            </tr>
        </table>

        <table class="table table-bordered border-dark align-middle">
            <thead class="bg-light text-center fw-bold"><tr><th>URAIAN TRANSAKSI / KETERANGAN</th><th width="240">JUMLAH (RP)</th></tr></thead>
            <tbody>
                <tr style="height: 120px;">
                    <td class="p-3 fs-5" valign="top"><?= htmlspecialchars($h['keterangan']) ?></td>
                    <td class="text-end p-3 fs-3 fw-bold">Rp <?= number_format($h['total_debet'], 0, ',', '.') ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="fw-bold bg-light">
                    <td class="text-end px-3">TOTAL TERBILANG</td>
                    <td class="text-end px-3">Rp <?= number_format($h['total_debet'], 0, ',', '.') ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="sig-wrapper">
            <?php foreach($signatures as $sig): ?>
            <div class="sig-item">
                <div class="sig-header">
                    <div class="sig-role"><?= htmlspecialchars($sig['sign_role']) ?></div>
                    <div class="sig-pos"><?= htmlspecialchars($sig['sign_position']) ?></div>
                </div>
                <div class="sig-name-box">
                    <div class="sig-line"></div>
                    <div class="sig-name"><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="sig-item">
                <div class="sig-header">
                    <div class="sig-role">Pihak Terkait,</div>
                    <div class="sig-pos">&nbsp;</div> 
                </div>
                <div class="sig-name-box">
                    <div class="sig-line"></div>
                    <div class="sig-name">( <?= htmlspecialchars($person_name) ?> )</div>
                </div>
            </div>
        </div>

        <div class="mt-4 small fst-italic text-muted text-center border-top pt-2" style="font-size: 9px;">
            Dicetak otomatis via Sistem ERP pada <?= date('d/m/Y H:i') ?> | Rekonsiliasi Tersinkronisasi.
        </div>
    </div>
</body>
</html>