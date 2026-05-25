<?php
/**
 * unit_report_view.php - HALAMAN DETAIL & KIRIM LAPORAN
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$id = (int)$_GET['id'];
$rep = $conn->query("SELECT * FROM unit_report_archives WHERE id = $id")->fetch_assoc();
if (!$rep) die("Laporan tidak ditemukan.");

$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$unit = $conn->query("SELECT * FROM m_unit WHERE id = {$rep['unit_id']}")->fetch_assoc();

// Re-query Mutasi untuk Tampilan Tabel
$akun_kas = $unit['kas_bank_akun'];
$sql_trx = "SELECT j.tgl_jurnal, j.keterangan, jd.debit, jd.kredit 
            FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            WHERE jd.kode_akun = '$akun_kas' AND j.tgl_jurnal BETWEEN '{$rep['periode_awal']}' AND '{$rep['periode_akhir']}' 
            ORDER BY j.tgl_jurnal ASC";
$trx = $conn->query($sql_trx);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= $rep['report_title'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f5f9; padding: 20px; font-family: 'Times New Roman', serif; }
        .sheet { background: white; width: 210mm; min-height: 297mm; margin: 0 auto; padding: 20mm; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .header { border-bottom: 3px double #000; padding-bottom: 15px; margin-bottom: 20px; text-align: center; }
        @media print { .no-print { display: none; } body { background: white; } .sheet { box-shadow: none; margin: 0; } }
    </style>
</head>
<body>

    <div class="container text-center no-print mb-4">
        <button onclick="window.close()" class="btn btn-light border rounded-pill px-4">Tutup</button>
        <button onclick="window.print()" class="btn btn-dark rounded-pill px-4">Cetak PDF</button>
        
        <!-- TOMBOL KIRIM LAPORAN (Hanya jika status DRAFT) -->
        <?php if($rep['status'] == 'DRAFT'): ?>
        <button class="btn btn-success rounded-pill px-4 fw-bold shadow" onclick="document.getElementById('uploadArea').classList.remove('d-none')">Kirim Laporan</button>
        <?php else: ?>
        <span class="badge bg-info p-2 rounded-pill">Status: <?= $rep['status'] ?></span>
        <?php endif; ?>
    </div>

    <!-- AREA UPLOAD (Muncul saat klik Kirim) -->
    <div id="uploadArea" class="container mb-4 no-print d-none">
        <div class="card p-4 shadow-sm border-success">
            <h5 class="fw-bold text-success">Upload Bukti & Kirim Laporan</h5>
            <form action="budget_unit_action.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit_final_report">
                <input type="hidden" name="report_id" value="<?= $id ?>">
                <div class="mb-3">
                    <label>File Bukti Transaksi (PDF/ZIP)</label>
                    <input type="file" name="bukti" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-success w-100 fw-bold">KIRIM & ARSIPKAN SEKARANG</button>
            </form>
        </div>
    </div>

    <div class="sheet">
        <div class="header">
            <h2 class="fw-bold m-0"><?= strtoupper($profile['institution_name']) ?></h2>
            <p class="m-0 small"><?= $profile['address'] ?></p>
        </div>
        
        <div class="text-center mb-4">
            <h4 class="text-decoration-underline fw-bold">LAPORAN MUTASI KAS</h4>
            <p class="mb-0 fw-bold"><?= $unit['nama_unit'] ?></p>
            <p class="small">Periode: <?= date('d-m-Y', strtotime($rep['periode_awal'])) ?> s/d <?= date('d-m-Y', strtotime($rep['periode_akhir'])) ?></p>
        </div>

        <table class="table table-bordered border-dark table-sm">
            <thead class="table-light text-center">
                <tr><th>TGL</th><th>URAIAN</th><th>DEBET</th><th>KREDIT</th><th>SALDO</th></tr>
            </thead>
            <tbody>
                <tr class="fw-bold bg-light">
                    <td colspan="2">SALDO AWAL</td><td></td><td></td><td class="text-end"><?= number_format($rep['saldo_awal']) ?></td>
                </tr>
                <?php $bal = $rep['saldo_awal']; while($r=$trx->fetch_assoc()): $bal+=($r['debit']-$r['kredit']); ?>
                <tr>
                    <td class="text-center"><?= date('d/m/y', strtotime($r['tgl_jurnal'])) ?></td>
                    <td><?= $r['keterangan'] ?></td>
                    <td class="text-end"><?= number_format($r['debit']) ?></td>
                    <td class="text-end"><?= number_format($r['kredit']) ?></td>
                    <td class="text-end fw-bold"><?= number_format($bal) ?></td>
                </tr>
                <?php endwhile; ?>
                <tr class="fw-bold bg-secondary text-white">
                    <td colspan="2" class="text-center">SALDO AKHIR</td>
                    <td class="text-end"><?= number_format($rep['total_masuk']) ?></td>
                    <td class="text-end"><?= number_format($rep['total_keluar']) ?></td>
                    <td class="text-end"><?= number_format($rep['saldo_akhir']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>