<?php
/**
 * diagnosa_coa.php - SOVEREIGN COA X-RAY SCANNER
 * Alat pemindai anomali database Chart of Accounts.
 * Versi: 2.0 (Builder & Clearing Account Tolerance Edition)
 * Perbaikan: Menghormati opsi 'NONE'. Akun pembangun/perantara yang sengaja 
 * tidak dimasukkan ke laporan tidak lagi dianggap sebagai Error/Danger.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php'; 

// 1. CEK AKUN TANPA REPORT GROUP (MURNI KOSONG / LUPA DIISI) -> INI ERROR
$sql_missing_rg = "SELECT kode_akun, nama_akun, kategori FROM syifa_akun WHERE is_group = 0 AND is_active = 1 AND (report_group IS NULL OR report_group = '')";
$res_missing_rg = $conn->query($sql_missing_rg);

// 2. CEK AKUN 'NONE' (SENGAJA TIDAK DIMASUKKAN KE LAPORAN / AKUN PEMBANGUN) -> INI AMAN (INFO)
$sql_ignored_rg = "SELECT kode_akun, nama_akun, kategori FROM syifa_akun WHERE is_group = 0 AND is_active = 1 AND report_group = 'NONE'";
$res_ignored_rg = $conn->query($sql_ignored_rg);

// 3. CEK INDUK SILANG (Penyebab Tampilan Akordion Kacau)
$sql_cross_parent = "
    SELECT a.kode_akun as anak_kode, a.nama_akun as anak_nama, a.kategori as anak_kat, 
           p.kode_akun as induk_kode, p.nama_akun as induk_nama, p.kategori as induk_kat
    FROM syifa_akun a
    JOIN syifa_akun p ON a.parent_kode = p.kode_akun
    WHERE a.kategori != p.kategori AND a.is_active = 1
";
$res_cross_parent = $conn->query($sql_cross_parent);

// 4. CEK ANOMALI SALDO NORMAL
$sql_anomali_saldo = "
    SELECT kode_akun, nama_akun, kategori, normal_balance 
    FROM syifa_akun 
    WHERE is_active = 1 AND is_group = 0 AND (
        (kategori IN ('Aset', 'Beban') AND UPPER(normal_balance) != 'DEBIT') OR
        (kategori IN ('Liabilitas', 'Aset Neto', 'Pendapatan') AND UPPER(normal_balance) != 'KREDIT')
    )
";
$res_anomali_saldo = $conn->query($sql_anomali_saldo);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>COA X-Ray Diagnostics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .font-monospace { font-size: 0.9em; }
    </style>
</head>
<body class="p-4 text-dark">
    <div class="container-fluid">
        <div class="d-flex align-items-center mb-4">
            <i class="fas fa-stethoscope fa-3x text-primary me-3"></i>
            <div>
                <h3 class="fw-bold mb-0 text-dark">Sovereign COA Diagnostics X-Ray</h3>
                <p class="text-muted mb-0">Pemindai Anomali Database untuk Integritas Laporan ISAK 35</p>
            </div>
        </div>

        <div class="row g-4">
            <!-- PANEL 1: KEKOSONGAN REPORT GROUP (ERROR) -->
            <div class="col-md-6">
                <div class="card border-top border-danger border-4 h-100">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-danger mb-3"><i class="fas fa-exclamation-triangle me-2"></i>1. Akun Kehilangan Arah (Lupa Set Laporan)</h5>
                        <p class="small text-muted">Akun di bawah ini berstatus Bisa Dijurnal namun <b>Kosong/Belum diset</b> akan masuk ke laporan mana. Jika tidak sengaja, segera edit dan set ke "NONE".</p>
                        
                        <?php if($res_missing_rg && $res_missing_rg->num_rows > 0): ?>
                            <div class="table-responsive" style="max-height: 250px;">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light sticky-top"><tr><th>Kode</th><th>Nama Akun</th><th>Kategori</th></tr></thead>
                                    <tbody>
                                        <?php while($r = $res_missing_rg->fetch_assoc()): ?>
                                            <tr>
                                                <td class="font-monospace text-danger fw-bold"><?= $r['kode_akun'] ?></td>
                                                <td class="fw-bold"><?= $r['nama_akun'] ?></td>
                                                <td><?= $r['kategori'] ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success fw-bold"><i class="fas fa-check-circle me-2"></i>Sempurna! Tidak ada akun yang lupa di-mapping.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PANEL 1.5: AKUN NONE (INFO AMAN) -->
            <div class="col-md-6">
                <div class="card border-top border-primary border-4 h-100">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-primary mb-3"><i class="fas fa-shield-alt me-2"></i>Akun Builder / Clearing (Aman)</h5>
                        <p class="small text-muted">Sistem mendeteksi bahwa Bapak sengaja mengatur akun-akun ini sebagai <b>"NONE" (Tidak Masuk Laporan)</b>. Sistem mematuhi perintah ini.</p>
                        
                        <?php if($res_ignored_rg && $res_ignored_rg->num_rows > 0): ?>
                            <div class="table-responsive" style="max-height: 250px;">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light sticky-top"><tr><th>Kode</th><th>Nama Akun</th><th>Kategori</th></tr></thead>
                                    <tbody>
                                        <?php while($r = $res_ignored_rg->fetch_assoc()): ?>
                                            <tr>
                                                <td class="font-monospace text-primary fw-bold"><?= $r['kode_akun'] ?></td>
                                                <td class="fw-bold"><?= $r['nama_akun'] ?></td>
                                                <td><?= $r['kategori'] ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-light border border-secondary fw-bold text-muted"><i class="fas fa-info-circle me-2"></i>Tidak ada akun yang di-set "NONE".</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PANEL 2: INDUK SILANG DIMENSI -->
            <div class="col-md-6">
                <div class="card border-top border-warning border-4 h-100">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-warning mb-3"><i class="fas fa-random me-2 text-warning"></i>2. Persilangan Induk (Cross-Parenting)</h5>
                        <p class="small text-muted">Akun anak memiliki Kategori yang berbeda dengan Induknya. Ini merusak struktur Akordion COA. (Contoh: Akun Liabilitas nyasar di dalam Folder Pendapatan).</p>
                        
                        <?php if($res_cross_parent && $res_cross_parent->num_rows > 0): ?>
                            <div class="table-responsive" style="max-height: 250px;">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light sticky-top"><tr><th>Akun Anak (Bermasalah)</th><th>Induk Saat Ini (Salah Tempat)</th></tr></thead>
                                    <tbody>
                                        <?php while($r = $res_cross_parent->fetch_assoc()): ?>
                                            <tr>
                                                <td><span class="badge bg-danger mb-1"><?= $r['anak_kat'] ?></span><br><code class="text-dark fw-bold"><?= $r['anak_kode'] ?></code> <?= $r['anak_nama'] ?></td>
                                                <td><span class="badge bg-secondary mb-1"><?= $r['induk_kat'] ?></span><br><code><?= $r['induk_kode'] ?></code> <?= $r['induk_nama'] ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success fw-bold"><i class="fas fa-check-circle me-2"></i>Aman! Tidak ada persilangan dimensi induk-anak.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PANEL 3: ANOMALI SALDO NORMAL -->
            <div class="col-md-6">
                <div class="card border-top border-info border-4 h-100">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-info mb-3"><i class="fas fa-balance-scale-right me-2"></i>3. Anomali Saldo Normal</h5>
                        <p class="small text-muted">Kategori Aset & Beban wajib bersaldo Debit. Sisanya (Liabilitas, Ekuitas, Pendapatan) wajib Kredit. Jika terbalik, nilai laporan akan minus/kacau.</p>
                        
                        <?php if($res_anomali_saldo && $res_anomali_saldo->num_rows > 0): ?>
                            <div class="table-responsive" style="max-height: 250px;">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead class="table-light sticky-top"><tr><th>Kode</th><th>Nama Akun</th><th>Kategori</th><th>Saldo Normal (Salah)</th></tr></thead>
                                    <tbody>
                                        <?php while($r = $res_anomali_saldo->fetch_assoc()): ?>
                                            <tr>
                                                <td class="font-monospace fw-bold"><?= $r['kode_akun'] ?></td>
                                                <td><?= $r['nama_akun'] ?></td>
                                                <td><?= $r['kategori'] ?></td>
                                                <td class="text-danger fw-bold"><?= strtoupper($r['normal_balance']) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success fw-bold"><i class="fas fa-check-circle me-2"></i>Tepat! Seluruh saldo normal sudah mematuhi kaidah akuntansi.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-12 text-center mt-4 pb-5">
                <a href="index.php?page=kode_akun" class="btn btn-dark rounded-pill px-5 py-3 fw-bold shadow"><i class="fas fa-cogs me-2"></i>KEMBALI KE MASTER COA</a>
            </div>
        </div>
    </div>
</body>
</html>