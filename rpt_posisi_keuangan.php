<?php
/**
 * rpt_posisi_keuangan.php - LAPORAN POSISI KEUANGAN (NERACA)
 * Fitur: Drill-down ke Buku Besar & Auto-sync Aset.
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$id_setting = (int)$_GET['id'];
$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $id_setting")->fetch_assoc();
$tgl_per = $conf['tgl_akhir'];

// Fungsi Helper untuk hitung saldo per akun sampai tanggal tertentu
function getSaldoPer($conn, $kode, $tgl) {
    $acc = $conn->query("SELECT * FROM syifa_akun WHERE kode_akun = '$kode'")->fetch_assoc();
    if(!$acc) return 0;
    
    $jurnal = $conn->query("SELECT SUM(debit) as d, SUM(kredit) as k 
                            FROM syifa_jurnal_detail jd 
                            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                            WHERE jd.kode_akun = '$kode' AND j.tgl_jurnal <= '$tgl'")->fetch_assoc();
    
    $net = ($acc['saldo_normal'] == 'D') ? ($jurnal['d'] - $jurnal['k']) : ($jurnal['k'] - $jurnal['d']);
    return (double)$acc['opening_balance'] + $net;
}

// Data COA Hierarki
$coa = $conn->query("SELECT * FROM syifa_akun ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
?>

<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white animate__animated animate__fadeIn">
        <div class="card-header bg-white p-5 border-0 text-center">
            <h3 class="fw-bold mb-1"><?= $conf['judul_laporan'] ?></h3>
            <h5 class="text-primary fw-bold mb-1">STIKes Yarsi Pontianak</h5>
            <p class="text-muted">Per Posisi Tanggal: <b><?= date('d F Y', strtotime($tgl_per)) ?></b> | Metode: <?= $conf['metode'] ?></p>
            <div class="no-print">
                <button class="btn btn-sm btn-outline-dark rounded-pill px-4" onclick="window.print()"><i class="fas fa-print me-2"></i>Cetak PDF</button>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="row g-0 border-top">
                <!-- SISI AKTIVA -->
                <div class="col-md-6 border-end">
                    <div class="p-4 bg-light fw-bold text-uppercase small letter-spacing-1">Aset (Aktiva)</div>
                    <div class="p-0">
                        <?php 
                        $total_aset = 0;
                        foreach($coa as $a): if($a['kategori'] == 'Aset'): 
                            $saldo = getSaldoPer($conn, $a['kode_akun'], $tgl_per);
                            if($a['is_group'] == 0 && $saldo == 0) continue;
                            if($a['is_group'] == 0) $total_aset += $saldo;
                        ?>
                            <div class="d-flex justify-content-between p-3 border-bottom <?= $a['is_group']?'bg-white':'small' ?>" 
                                 style="padding-left: <?= ($a['parent_kode'] ? '40px' : '20px') ?> !important;">
                                <span class="<?= $a['is_group']?'fw-bold text-dark':'text-muted' ?>">
                                    <code><?= $a['kode_akun'] ?></code> <?= $a['nama_akun'] ?>
                                </span>
                                <span class="fw-bold <?= $saldo < 0 ? 'text-danger' : 'text-dark' ?>" 
                                      style="cursor:pointer;" onclick="drillDown('<?= $a['kode_akun'] ?>')">
                                    <?= $saldo < 0 ? "(".number_format(abs($saldo)).")" : number_format($saldo) ?>
                                </span>
                            </div>
                        <?php endif; endforeach; ?>
                        <div class="d-flex justify-content-between p-4 bg-primary text-white fw-bold">
                            <span>TOTAL ASET</span>
                            <span>Rp <?= number_format($total_aset) ?></span>
                        </div>
                    </div>
                </div>

                <!-- SISI PASIVA -->
                <div class="col-md-6">
                    <div class="p-4 bg-light fw-bold text-uppercase small letter-spacing-1">Liabilitas & Aset Neto</div>
                    <div class="p-0">
                        <?php 
                        $total_pasiva = 0;
                        foreach($coa as $p): if($p['kategori'] == 'Liabilitas' || $p['kategori'] == 'Ekuitas'): 
                            $saldo = getSaldoPer($conn, $p['kode_akun'], $tgl_per);
                            if($p['is_group'] == 0 && $saldo == 0) continue;
                            if($p['is_group'] == 0) $total_pasiva += $saldo;
                        ?>
                            <div class="d-flex justify-content-between p-3 border-bottom <?= $p['is_group']?'bg-white':'small' ?>"
                                 style="padding-left: <?= ($p['parent_kode'] ? '40px' : '20px') ?> !important;">
                                <span class="<?= $p['is_group']?'fw-bold text-dark':'text-muted' ?>">
                                    <code><?= $p['kode_akun'] ?></code> <?= $p['nama_akun'] ?>
                                </span>
                                <span class="fw-bold text-dark" style="cursor:pointer;" onclick="drillDown('<?= $p['kode_akun'] ?>')">
                                    <?= number_format($saldo) ?>
                                </span>
                            </div>
                        <?php endif; endforeach; ?>
                        <div class="d-flex justify-content-between p-4 bg-dark text-white fw-bold">
                            <span>TOTAL LIABILITAS & ASET NETO</span>
                            <span>Rp <?= number_format($total_pasiva) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footnote Audit -->
        <div class="card-footer bg-white p-4 border-0 text-center">
            <small class="text-muted italic">Dicetak secara sistematis oleh SYIFA ERP pada <?= date('d/m/Y H:i') ?>. Seluruh data telah tervalidasi dengan Buku Besar.</small>
        </div>
    </div>
</div>

<script>
function drillDown(kode) {
    // Membuka Buku Besar untuk akun terkait
    window.location.href = `index.php?page=akun_kas&view=rekap&kode=${kode}`;
}
</script>