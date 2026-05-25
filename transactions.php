<?php
/**
 * transactions.php - MANAJEMEN KAS & REKAP MUTASI
 * Versi: 33.0 (Sovereign Grand Master - True Transfer Sync)
 * Perbaikan Mutlak: 
 * Menyempurnakan kueri histori transaksi agar transaksi Pindah Buku (Transfer Antar Kas) 
 * terbaca dengan sempurna, baik pada sisi akun pengirim maupun sisi akun penerima.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// 🛡️ THE DATABASE HEALER & GHOST SWEEPER
try {
    @$conn->query("ALTER TABLE keuangan_tagihan ADD COLUMN IF NOT EXISTS jenis_tagihan_id INT NULL");
    @$conn->query("ALTER TABLE mhs_billing ADD COLUMN IF NOT EXISTS jenis_tagihan_id INT NULL");
    @$conn->query("DROP TRIGGER IF EXISTS trg_pembayaran_insert");
    @$conn->query("DROP TRIGGER IF EXISTS after_payment_insert");
    @$conn->query("UPDATE syifa_akun SET is_cash_account = 0 WHERE kode_akun REGEXP '^[456789]' OR kategori IN ('Beban', 'Pendapatan')");
} catch (Exception $e) {}

// =========================================================================
// 🚀 HANDLER QUICK EDIT KAS (STICKY ROUTING EDITION)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_edit_kas') {
    $qe_id = (int)$_POST['id'];
    $qe_nama = $conn->real_escape_string($_POST['nama_akun']);
    
    $conn->query("UPDATE syifa_akun SET nama_akun='$qe_nama' WHERE id=$qe_id");
    
    $r_kode = $_POST['return_kode'] ?? '';
    $r_bulan = $_POST['return_bulan'] ?? '';
    $r_tahun = $_POST['return_tahun'] ?? '';
    
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Nama akun Kas/Bank berhasil diperbarui.'];
    header("Location: index.php?page=akun_kas&view=rekap&kode=$r_kode&bulan=$r_bulan&tahun=$r_tahun");
    exit;
}

$view = $_GET['view'] ?? 'rekap';
$kode = $_GET['kode'] ?? '';
$tahun = $_GET['tahun'] ?? date('Y');
$bulan = $_GET['bulan'] ?? date('m');

if (!function_exists('formatRp')) {
    function formatRp($angka) { return "Rp " . number_format($angka ?? 0, 0, ',', '.'); }
}

// 🛡️ STRICT QUERY + LIVE BALANCE CALCULATION
$sql_kas_bank = "SELECT a.*, 
                 COALESCE((SELECT SUM(debit - kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = a.kode_akun AND j.is_deleted = 0), 0) as mutasi_netto 
                 FROM syifa_akun a 
                 WHERE (a.kategori IN ('Kas', 'Bank') OR a.kode_akun LIKE '1-11%' OR (a.is_cash_account=1 AND a.kategori NOT IN ('Beban', 'Pendapatan'))) 
                 AND a.is_group=0 
                 AND a.is_active=1 
                 AND a.kode_akun NOT REGEXP '^[456789]' 
                 ORDER BY a.kode_akun ASC";
$kas_bank_list = $conn->query($sql_kas_bank)->fetch_all(MYSQLI_ASSOC);

$grand_total_kas = 0;
foreach($kas_bank_list as &$kb) {
    $mutasi = (double)$kb['mutasi_netto'];
    $ob = (double)$kb['opening_balance'];
    $saldo_sekarang = ($kb['saldo_normal'] == 'K') ? ($ob - $mutasi) : ($ob + $mutasi); 
    $kb['current_balance'] = $saldo_sekarang;
    $grand_total_kas += $saldo_sekarang;
}
unset($kb);

if ($view == 'rekap') {
    if(empty($kode) && !empty($kas_bank_list)) { $kode = $kas_bank_list[0]['kode_akun']; }
    
    $akun_aktif = null;
    foreach($kas_bank_list as $k) { if($k['kode_akun'] == $kode) { $akun_aktif = $k; break; } }
    
    $nm_bln = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    $bulan_nama = empty($bulan) ? "Semua Bulan" : $nm_bln[(int)$bulan];
    
    $start_date = empty($bulan) ? "$tahun-01-01 00:00:00" : "$tahun-" . sprintf("%02d", $bulan) . "-01 00:00:00";
    $end_date   = empty($bulan) ? "$tahun-12-31 23:59:59" : date('Y-m-t 23:59:59', strtotime($start_date));
    
    $q_awal = $conn->query("SELECT SUM(debit - kredit) as net FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$kode' AND j.tgl_jurnal < '$start_date' AND j.is_deleted=0")->fetch_assoc();
    $saldo_awal = (double)($akun_aktif['opening_balance'] ?? 0) + (double)($q_awal['net'] ?? 0);
    
    // 🚀 THE TRUE TRANSFER SYNC: Menarik semua jurnal di mana kode kas terlibat, lalu disajikan cerdas
    $sql_mutasi = "
        SELECT j.id, j.tgl_jurnal, j.no_jurnal, j.keterangan, j.pihak_nama, 
               j.akun_utama_kode, j.akun_tujuan_kode,
               a1.nama_akun as nama_utama, a2.nama_akun as nama_tujuan,
               jd.debit, jd.kredit, jd.keterangan as ket_detail
        FROM syifa_jurnal_detail jd 
        JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
        LEFT JOIN syifa_akun a1 ON j.akun_utama_kode = a1.kode_akun
        LEFT JOIN syifa_akun a2 ON j.akun_tujuan_kode = a2.kode_akun
        WHERE jd.kode_akun = '$kode' 
        AND j.tgl_jurnal BETWEEN '$start_date' AND '$end_date' 
        AND j.is_deleted=0 
        ORDER BY j.tgl_jurnal ASC, j.id ASC
    ";
    $mutasi = $conn->query($sql_mutasi);
}
?>

<style>
    .btn-oval { border-radius: 50px !important; padding: 10px 25px; font-weight: 700; text-transform: uppercase; font-size: 12px; }
    .kpi-card { border: none; border-radius: 20px; color: #fff; text-align: left; padding: 25px; position: relative; overflow: hidden; box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .kpi-icon { position: absolute; right: -15px; bottom: -20px; font-size: 100px; opacity: 0.15; transform: rotate(-15deg); }
    .kpi-label { font-size: 12px; font-weight: 800; text-transform: uppercase; opacity: 0.9; margin-bottom: 5px; letter-spacing: 1px; }
    .kpi-val { font-size: 28px; font-weight: 900; line-height: 1.2; }
    .table-mutasi thead th { background: #1e293b !important; color: #fff !important; font-size: 10px; text-transform: uppercase; padding: 15px 10px; border: none; }
    .table-mutasi tbody td { font-size: 13px; border-bottom: 1px solid #f1f5f9; padding: 12px 10px; vertical-align: middle; color: #334155; }
    .trx-in { color: #10b981; font-weight: 700; }
    .trx-out { color: #ef4444; font-weight: 700; }
    
    .btn-edit-quick { font-size: 10px; padding: 4px 10px; border-radius: 50px; border: 1px solid #cbd5e1; background: #fff; color: #64748b; font-weight: 700; margin-left: 10px; cursor: pointer; transition: 0.2s; }
    .btn-edit-quick:hover { background: #0d6efd; color: #fff; border-color: #0d6efd; }
    
    .btn-edit-sidebar { font-size: 11px; padding: 4px; border-radius: 6px; background: transparent; color: #cbd5e1; border: none; cursor: pointer; transition: 0.2s; }
    .list-group-item:hover .btn-edit-sidebar { color: #f59e0b; }
    .btn-edit-sidebar:hover { background: #fef3c7; color: #d97706 !important; transform: scale(1.1); }
    
    .btn-white { background: #fff; border: none; transition: 0.2s; }
    .btn-white:hover { background: #f8f9fa; color: #0d6efd !important;}
    
    .form-control[readonly] { background-color: #f8fafc; border-color: #e2e8f0; opacity: 1; cursor: not-allowed; }
    
    /* Transition untuk Tampilan Wide */
    #sidebarKas, #mainContentKas { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-info-circle me-2 fa-lg"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 text-dark text-center">
        <div class="d-flex align-items-center gap-3 text-start">
            <a href="index.php?page=ringkasan" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
            <div><h4 class="fw-bold mb-0">Manajemen Kas & Bank</h4><small class="text-muted fw-bold uppercase">Rekonsiliasi Mutasi Rekening</small></div>
        </div>
    </div>

    <!-- 🚀 GRAND TOTAL CARD -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white;">
        <div class="card-body p-4 d-flex justify-content-between align-items-center">
            <div>
                <h6 class="fw-bold mb-1 opacity-75 text-uppercase" style="font-size: 11px; letter-spacing: 1px;">Saldo Grand Total Kas & Bank Keseluruhan</h6>
                <h2 class="fw-bold mb-0">Rp <?= number_format($grand_total_kas, 0, ',', '.') ?></h2>
            </div>
            <i class="fas fa-vault fa-3x opacity-25"></i>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- 🛡️ SIDEBAR DAFTAR KAS -->
        <div class="col-md-3" id="sidebarKas">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 overflow-hidden">
                <div class="card-header bg-dark text-white p-4 border-0 text-center"><h6 class="fw-bold mb-0"><i class="fas fa-wallet me-2 text-warning"></i>Daftar Rekening</h6></div>
                
                <!-- 🚀 SEARCH BAR UNTUK REKENING KAS -->
                <div class="p-3 bg-light border-bottom">
                    <div class="input-group input-group-sm shadow-sm rounded-pill overflow-hidden border">
                        <span class="input-group-text bg-white border-0 text-muted px-3"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchAkunSidebar" class="form-control border-0 px-2 shadow-none bg-white fw-bold" placeholder="Cari rekening..." onkeyup="filterSidebarAkun()">
                    </div>
                </div>

                <div class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
                    <?php foreach($kas_bank_list as $k): ?>
                        <a href="?page=akun_kas&view=rekap&kode=<?= $k['kode_akun'] ?>&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="list-group-item list-group-item-action py-3 px-3 border-bottom kas-item <?= $kode==$k['kode_akun']?'bg-primary text-white fw-bold shadow-sm':'text-dark fw-bold' ?>" style="transition: 0.2s;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <i class="fas fa-money-check-alt me-2 <?= $kode==$k['kode_akun']?'text-white':'text-muted opacity-50' ?>"></i> 
                                    <span class="kas-nama"><?= $k['nama_akun'] ?></span>
                                    <small class="kas-kode <?= $kode==$k['kode_akun']?'text-white opacity-75':'text-muted' ?> d-block mt-1 ps-4" style="font-family: monospace; font-size: 11px;"><?= $k['kode_akun'] ?></small>
                                    
                                    <!-- 🚀 LIVE BALANCE PER ACCOUNT -->
                                    <small class="<?= $kode==$k['kode_akun']?'text-white':'text-success' ?> d-block mt-1 ps-4 fw-bold">Rp <?= number_format($k['current_balance'], 0, ',', '.') ?></small>
                                </div>
                                <div class="d-flex align-items-center gap-1 mt-1">
                                    <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                    <button type="button" class="btn-edit-sidebar <?= $kode==$k['kode_akun']?'text-white':'text-muted' ?>" onclick="event.preventDefault(); editAkunKasLocal('<?= $k['id'] ?>', '<?= addslashes($k['nama_akun']) ?>', '<?= $k['opening_balance'] ?>', '<?= $k['kode_akun'] ?>')" title="Ubah Nama Akun">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if($kode==$k['kode_akun']): ?><i class="fas fa-chevron-right ms-1 small"></i><?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- 🛡️ MAIN CONTENT KAS -->
        <div class="col-md-9" id="mainContentKas">
            <div class="card border-0 shadow-sm rounded-4 bg-white h-100 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <div>
                        <h5 class="fw-bold text-primary mb-1 d-flex align-items-center">
                            <?= $akun_aktif['nama_akun'] ?? 'Pilih Akun' ?> 
                            <?php if(defined('RBAC_EDIT') && RBAC_EDIT && $akun_aktif): ?>
                            <span class="btn-edit-quick" onclick="editAkunKasLocal('<?= $akun_aktif['id'] ?>', '<?= addslashes($akun_aktif['nama_akun']) ?>', '<?= $akun_aktif['opening_balance'] ?>', '<?= $akun_aktif['kode_akun'] ?>')"><i class="fas fa-pen me-1"></i> Edit</span>
                            <?php endif; ?>
                        </h5>
                        <small class="text-muted fw-bold" style="font-family: monospace;">KODE: <?= $akun_aktif['kode_akun'] ?? '-' ?></small>
                    </div>
                    <div class="d-flex align-items-center">
                        <!-- ?? TOMBOL HIDE/EXPAND SIDEBAR -->
                        <button class="btn btn-outline-primary rounded-pill px-3 fw-bold shadow-sm small text-uppercase me-3" onclick="toggleSidebarKas()" id="btnToggleSidebarKas" title="Sembunyikan/Tampilkan Daftar Rekening">
                            <i class="fas fa-expand-arrows-alt me-1"></i> Lebarkan
                        </button>
                        
                        <form method="GET" class="d-flex gap-2">
                            <input type="hidden" name="page" value="akun_kas"><input type="hidden" name="view" value="rekap"><input type="hidden" name="kode" value="<?= $kode ?>">
                            <select name="bulan" class="form-select border-0 shadow-sm rounded-pill fw-bold text-dark" onchange="this.form.submit()" style="width: 150px;">
                                <option value="">Semua Bulan</option>
                                <?php for($m=1; $m<=12; $m++) echo "<option value='".sprintf("%02d", $m)."' ".($bulan==$m?'selected':'').">{$nm_bln[$m]}</option>"; ?>
                            </select>
                            <select name="tahun" class="form-select border-0 shadow-sm rounded-pill fw-bold text-primary" onchange="this.form.submit()" style="width: 100px;">
                                <?php for($y=date('Y')+1; $y>=2020; $y--) echo "<option value='$y' ".($tahun==$y?'selected':'').">$y</option>"; ?>
                            </select>
                            
                            <button type="button" class="btn btn-primary rounded-pill px-3" onclick="window.open('print_buku_kas.php?kode=<?= $kode ?>&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>', '_blank')" title="Cetak / Unduh PDF">
                                <i class="fas fa-print"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="table-responsive" id="trxTableContainer">
                    <table class="table table-hover align-middle mb-0 table-mutasi text-center">
                        <thead><tr><th width="90">Tanggal</th><th width="130">No. Bukti</th><th class="text-start ps-3">Uraian Transaksi / Pihak</th><th class="text-end" width="130">Masuk (Debit)</th><th class="text-end" width="130">Keluar (Kredit)</th><th class="text-end pe-4" width="150">Saldo Akhir</th><th width="160" class="text-center pe-3">Aksi</th></tr></thead>
                        <tbody>
                            <tr class="bg-light fw-bold text-primary"><td colspan="3" class="text-start ps-4 text-uppercase"><i class="fas fa-flag me-2"></i>Saldo Awal per <?= date('d/m/Y', strtotime($start_date)) ?></td><td colspan="2"></td><td class="text-end pe-4 fs-6"><?= formatRp($saldo_awal) ?></td><td></td></tr>
                            <?php 
                            $run_bal = $saldo_awal; $t_in = 0; $t_out = 0;
                            if($mutasi && $mutasi->num_rows > 0): while($r = $mutasi->fetch_assoc()):
                                $run_bal += ($r['debit'] - $r['kredit']);
                                $t_in += $r['debit']; $t_out += $r['kredit'];
                                $is_in = ($r['debit'] > 0);
                                
                                // 🚀 PENYESUAIAN DESKRIPSI UNTUK TRANSFER KAS
                                $uraian_final = htmlspecialchars($r['keterangan']);
                                $pihak_final = htmlspecialchars($r['pihak_nama']) ?: 'Umum';
                                
                                if (strpos($r['no_jurnal'], 'TRF') === 0) {
                                    $type_edit = 'transfer';
                                    if (!empty($r['ket_detail'])) {
                                        $uraian_final = htmlspecialchars($r['ket_detail']);
                                    } elseif ($is_in) {
                                        $uraian_final = "Transfer Masuk dari " . htmlspecialchars($r['nama_utama']);
                                    } else {
                                        $uraian_final = "Transfer Keluar ke " . htmlspecialchars($r['nama_tujuan']);
                                    }
                                } else {
                                    $type_edit = $is_in ? 'income' : 'expense';
                                }
                            ?>
                            <tr><td class="text-muted text-center"><?= date('d/m/y', strtotime($r['tgl_jurnal'])) ?></td>
                                <td class="fw-bold text-center"><code><?= $r['no_jurnal'] ?></code></td>
                                <td class="text-start ps-3"><div class="fw-bold text-dark"><?= $pihak_final ?></div><div class="small text-muted text-truncate" style="max-width: 250px;" title="<?= $uraian_final ?>"><?= $uraian_final ?></div></td>
                                <td class="text-end trx-in"><?= $r['debit']>0 ? number_format($r['debit'], 0, ',', '.') : '-' ?></td>
                                <td class="text-end trx-out"><?= $r['kredit']>0 ? number_format($r['kredit'], 0, ',', '.') : '-' ?></td>
                                <td class="text-end pe-4 fw-bold text-dark"><?= number_format($run_bal, 0, ',', '.') ?></td>
                                <td class="text-center pe-3">
                                    <div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden">
                                        <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                        <button class="btn btn-white text-warning border-end" onclick="editTrxPopUp(<?= $r['id'] ?>, '<?= $type_edit ?>')" title="Ubah Transaksi"><i class="fas fa-edit"></i></button>
                                        <?php endif; ?>
                                        
                                        <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                                        <button class="btn btn-white text-primary border-end" onclick="duplicateTrxAction(<?= $r['id'] ?>, '<?= $type_edit ?>')" title="Duplikasi Transaksi"><i class="fas fa-clone"></i></button>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-white text-info border-end" onclick="openJournalModal(<?= $r['id'] ?>)" title="Lihat Detail Jurnal"><i class="fas fa-eye"></i></button>

                                        <?php if(defined('RBAC_DEL') && RBAC_DEL): ?>
                                        <button class="btn btn-white text-danger" onclick="deleteTrxDirect(<?= $r['id'] ?>)" title="Hapus Permanen Jurnal Ini"><i class="fas fa-trash-alt"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: echo "<tr><td colspan='7' class='py-5 text-center text-muted italic'>Tidak ada mutasi kas pada periode ini.</td></tr>"; endif; ?>
                        </tbody>
                        <tfoot class="bg-dark text-white fw-bold">
                            <!-- 🚀 FIX UKURAN SALDO AKHIR YANG KEBESARAN -->
                            <tr><td colspan="3" class="text-start ps-4 py-3 text-uppercase">Total Mutasi & Saldo Akhir</td><td class="text-end text-success"><?= number_format($t_in, 0, ',', '.') ?></td><td class="text-end text-danger"><?= number_format($t_out, 0, ',', '.') ?></td><td class="text-end pe-4 fw-bold text-warning fs-6">Rp <?= number_format($run_bal, 0, ',', '.') ?></td><td></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 🚀 MODAL QUICK EDIT KAS (ROUTING KHUSUS) -->
<div class="modal fade" id="mdlEditKas" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <!-- Action dialihkan murni ke index halaman yang sama -->
        <form action="index.php?page=akun_kas" method="POST" class="modal-content border-0 shadow-lg rounded-4 text-dark overflow-hidden">
            <input type="hidden" name="action" value="quick_edit_kas">
            <input type="hidden" name="id" id="qe_id">
            
            <input type="hidden" name="return_kode" id="qe_ret_kode">
            <input type="hidden" name="return_bulan" value="<?= $bulan ?>">
            <input type="hidden" name="return_tahun" value="<?= $tahun ?>">
            
            <div class="modal-header bg-primary text-white border-0 p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-pen-square me-2"></i>Ubah Nama Rekening</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Kode Akun</label>
                    <input type="text" id="qe_kode_show" class="form-control rounded-pill border-0 shadow-none px-4 py-2 fw-bold text-muted" readonly disabled>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-primary mb-1 uppercase">Nama Rekening Kas/Bank</label>
                    <input type="text" name="nama_akun" id="qe_nama" class="form-control rounded-pill border-primary shadow-sm px-4 py-2 fw-bold text-dark" required>
                </div>
                <div class="mb-0 d-none">
                    <label class="small fw-bold text-muted mb-1 uppercase">Saldo Awal Terkunci</label>
                    <input type="text" id="qe_ob" class="form-control rounded-pill border-0 shadow-sm px-4 py-2 fw-bold text-muted fs-5 text-end" readonly>
                </div>
                <div class="mt-3 p-3 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-4 small text-primary fw-bold">
                    <i class="fas fa-info-circle me-2"></i>Perubahan nama di sini akan tersinkronisasi di seluruh sistem (Jurnal, Buku Besar, Neraca, Laporan).
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white text-center d-block">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">SIMPAN PERUBAHAN NAMA</button>
            </div>
        </form>
    </div>
</div>

<?php include 'cash_modals_shared.php'; ?>

<script>
// 🚀 FUNGSI HIDE/EXPAND SIDEBAR KAS
function toggleSidebarKas() {
    const sidebar = document.getElementById('sidebarKas');
    const mainCol = document.getElementById('mainContentKas');
    const btn = document.getElementById('btnToggleSidebarKas');
    
    if (sidebar.classList.contains('d-none')) {
        sidebar.classList.remove('d-none');
        mainCol.classList.remove('col-md-12');
        mainCol.classList.add('col-md-9');
        btn.innerHTML = '<i class="fas fa-expand-arrows-alt me-1"></i> Lebarkan';
        btn.classList.replace('btn-primary', 'btn-outline-primary');
    } else {
        sidebar.classList.add('d-none');
        mainCol.classList.remove('col-md-9');
        mainCol.classList.add('col-md-12');
        btn.innerHTML = '<i class="fas fa-compress-arrows-alt me-1"></i> Tampilkan Rekening';
        btn.classList.replace('btn-outline-primary', 'btn-primary');
    }
}

// 🚀 FUNGSI FILTER SEARCH SIDEBAR AKUN KAS
function filterSidebarAkun() {
    let filter = document.getElementById('searchAkunSidebar').value.toLowerCase();
    let items = document.querySelectorAll('.kas-item');
    items.forEach(item => {
        let textKode = item.querySelector('.kas-kode').innerText.toLowerCase();
        let textNama = item.querySelector('.kas-nama').innerText.toLowerCase();
        if (textKode.includes(filter) || textNama.includes(filter)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// FUNGSI EDIT KAS ROUTING SPESIFIK
function editAkunKasLocal(id, nama, ob, kode) {
    document.getElementById('qe_id').value = id;
    document.getElementById('qe_nama').value = nama;
    document.getElementById('qe_ob').value = new Intl.NumberFormat('id-ID').format(ob);
    document.getElementById('qe_ret_kode').value = kode; 
    document.getElementById('qe_kode_show').value = kode; 
    new bootstrap.Modal(document.getElementById('mdlEditKas')).show();
}

const currentReturnPath = "akun_kas&view=rekap&kode=<?= $kode ?? '' ?>&bulan=<?= $bulan ?? '' ?>&tahun=<?= $tahun ?? '' ?>";

function editTrxPopUp(id, type) {
    if(typeof openTrxModal === 'function') { 
        openTrxModal(type, id); 
        document.getElementById('inpReturnPage').value = currentReturnPath;
    } else { alert("Sistem sedang memuat modul, silakan coba lagi."); }
}

function duplicateTrxAction(id, type) {
    if(typeof openTrxModal === 'function') { 
        openTrxModal(type, id, true); 
        document.getElementById('inpReturnPage').value = currentReturnPath;
    } else { alert("Sistem sedang memuat modul, silakan coba lagi."); }
}

function deleteTrxDirect(id) {
    if(confirm('PENTING: Hapus transaksi kas ini secara permanen? Saldo buku besar akan otomatis dikoreksi ke posisi awal.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'accounting_action.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete_trx"><input type="hidden" name="id" value="${id}"><input type="hidden" name="return_page" value="${currentReturnPath}">`;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>