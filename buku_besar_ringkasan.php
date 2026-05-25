<?php
/**
 * buku_besar_ringkasan.php - DEDICATED DRILL-DOWN LEDGER (ENTERPRISE EDITION)
 * Versi: 2.0 (Sovereign Grand Master - PSAK/IFRS Opening Balance Compliance)
 * Perbaikan: Injeksi Mesin "Saldo Awal (Beginning Balance)" absolut sebelum transaksi berjalan.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$target_akun = $_GET['akun'] ?? '';
$start_date  = $_GET['tgl_awal'] ?? date('Y-01-01');
$end_date    = $_GET['tgl_akhir'] ?? date('Y-m-t');
$source      = $_GET['source'] ?? 'ringkasan';

if (empty($target_akun)) {
    die("<div class='alert alert-danger m-4 shadow-sm border-0 rounded-4 fw-bold'>Ralat: Parameter Akun tidak valid.</div>");
}

$q_acc = $conn->query("SELECT * FROM syifa_akun WHERE kode_akun='$target_akun' LIMIT 1");
$acc_master = ($q_acc) ? $q_acc->fetch_assoc() : null;

if (!$acc_master) {
    die("<div class='alert alert-danger m-4 shadow-sm border-0 rounded-4 fw-bold'>Ralat: Akun tidak ditemukan di database.</div>");
}

// =====================================================================================
// 1. ENGINE SALDO AWAL (MENGHITUNG HISTORI MASA LALU + MIGRASI)
// =====================================================================================
$sql_ob = "SELECT SUM(jd.debit) as d, SUM(jd.kredit) as k 
           FROM syifa_jurnal_detail jd 
           JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
           WHERE jd.kode_akun = '$target_akun' AND j.tgl_jurnal < '$start_date'";
$res_ob = $conn->query($sql_ob)->fetch_assoc();

$ob_master = (double)$acc_master['opening_balance'];
$mut_past_d = (double)($res_ob['d'] ?? 0);
$mut_past_k = (double)($res_ob['k'] ?? 0);

if ($acc_master['saldo_normal'] == 'D') {
    $saldo_awal = $ob_master + $mut_past_d - $mut_past_k;
} else {
    $saldo_awal = $ob_master + $mut_past_k - $mut_past_d;
}

// Set Starting Point Running Balance
$cur_bal = $saldo_awal;

// 2. QUERY MUTASI PERIODE BERJALAN
$sql_mutasi = "SELECT j.tgl_jurnal, j.no_jurnal, j.keterangan as ket_header, jd.debit, jd.kredit, jd.keterangan as ket_item 
               FROM syifa_jurnal_detail jd 
               JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
               WHERE jd.kode_akun = '$target_akun' AND j.tgl_jurnal BETWEEN '$start_date' AND '$end_date' 
               ORDER BY j.tgl_jurnal ASC, j.id ASC";
$mutasi = $conn->query($sql_mutasi);

$link_back = "index.php?page=" . ($source == 'ringkasan' ? 'ringkasan' : 'laporan_keuangan');
?>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <!-- HEADER -->
        <div class="card-header bg-dark text-white p-4 d-flex justify-content-between align-items-center border-0">
            <div>
                <h5 class="fw-bold mb-1"><i class="fas fa-book-open me-2 text-warning"></i>Buku Besar Rincian</h5>
                <div class="opacity-75 small">
                    Kode Akun: <b><?= $acc_master['kode_akun'] ?></b> | 
                    Nama: <b><?= strtoupper($acc_master['nama_akun']) ?></b> | 
                    Periode: <b><?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?></b>
                </div>
            </div>
            <div>
                <a href="<?= $link_back ?>" class="btn btn-outline-light rounded-pill px-4 fw-bold shadow-sm me-2"><i class="fas fa-arrow-left me-2"></i>KEMBALI</a>
                <a href="print_buku_besar.php?akun=<?= $target_akun ?>&start=<?= $start_date ?>&end=<?= $end_date ?>" target="_blank" class="btn btn-warning text-dark rounded-pill px-4 fw-bold shadow-sm"><i class="fas fa-print me-2"></i>CETAK / PDF</a>
            </div>
        </div>

        <!-- TABEL BUKU BESAR -->
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                <thead class="table-light text-muted small text-uppercase fw-bold">
                    <tr>
                        <th class="ps-5 text-center" width="120">Tanggal</th>
                        <th class="text-center" width="160">No. Referensi</th>
                        <th>Keterangan Transaksi</th>
                        <th class="text-end" width="150">Debet (+)</th>
                        <th class="text-end" width="150">Kredit (-)</th>
                        <th class="text-end pe-5" width="180">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- BARIS SALDO AWAL (MUTLAK PSAK) -->
                    <tr class="bg-light">
                        <td colspan="3" class="ps-5 fw-bold text-dark text-uppercase">
                            <i class="fas fa-flag-checkered text-success me-2"></i> SALDO AWAL PER <?= date('d M Y', strtotime($start_date)) ?>
                        </td>
                        <td class="text-end"></td>
                        <td class="text-end"></td>
                        <td class="text-end pe-5 fw-bold text-primary fs-6"><?= number_format($saldo_awal, 0, ',', '.') ?></td>
                    </tr>

                    <!-- BARIS TRANSAKSI BERJALAN -->
                    <?php 
                    $tot_d = 0; $tot_k = 0;
                    if($mutasi && $mutasi->num_rows > 0): while($r = $mutasi->fetch_assoc()): 
                        $d = (double)$r['debit']; 
                        $k = (double)$r['kredit'];
                        $tot_d += $d; 
                        $tot_k += $k;
                        
                        if ($acc_master['saldo_normal'] == 'D') {
                            $cur_bal += ($d - $k);
                        } else {
                            $cur_bal += ($k - $d);
                        }
                    ?>
                        <tr>
                            <td class="ps-5 text-muted text-center"><?= date('d/m/y', strtotime($r['tgl_jurnal'])) ?></td>
                            <td class="text-center fw-bold"><code><?= $r['no_jurnal'] ?></code></td>
                            <td class="text-start"><?= htmlspecialchars($r['ket_header']) ?> <?= !empty($r['ket_item']) ? " - ".$r['ket_item'] : "" ?></td>
                            <td class="text-end fw-bold text-success"><?= $d > 0 ? number_format($d, 0, ',', '.') : '-' ?></td>
                            <td class="text-end fw-bold text-danger"><?= $k > 0 ? number_format($k, 0, ',', '.') : '-' ?></td>
                            <td class="text-end pe-5 fw-bold text-dark fs-6"><?= number_format($cur_bal, 0, ',', '.') ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan='6' class='py-5 text-center text-muted italic'>Tidak ada mutasi transaksi dalam rentang periode ini.</td></tr>
                    <?php endif; ?>
                    
                    <!-- BARIS SALDO AKHIR -->
                    <tr class="bg-dark text-white fw-bold">
                        <td colspan="3" class="ps-5 py-3 text-uppercase text-start">TOTAL MUTASI & SALDO AKHIR PER <?= date('d M Y', strtotime($end_date)) ?></td>
                        <td class="text-end text-success"><?= number_format($tot_d, 0, ',', '.') ?></td>
                        <td class="text-end text-danger"><?= number_format($tot_k, 0, ',', '.') ?></td>
                        <td class="text-end pe-5 fs-5 text-warning">Rp <?= number_format($cur_bal, 0, ',', '.') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>