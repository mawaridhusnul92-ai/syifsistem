<?php
/**
 * transaksi_kas.php - HANDLER TRANSAKSI KAS & BANK
 * Versi: 26.0 (Sovereign Grand Master - Fix Wrapping Amount)
 * STATUS: FULL CODE - NO TRUNCATION
 * Perbaikan: Memperbaiki antarmuka dan mempersiapkan fondasi untuk modal transaksi.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

if (!function_exists('formatRp')) { function formatRp($n) { return "Rp " . number_format($n ?? 0, 0, ',', '.'); } }

$view = $_GET['view'] ?? 'receipt';
$search = $conn->real_escape_string($_GET['q'] ?? '');
$trigger_new = $_GET['trigger_new'] ?? 0;
$last_id = $_GET['last_id'] ?? 0;

// Query akun kas komprehensif
$kas_bank_list = $conn->query("SELECT * FROM syifa_akun WHERE (kategori IN ('Kas', 'Bank') OR kode_akun LIKE '1-11%' OR kode_akun LIKE '1.11%' OR sub_kategori LIKE '%Kas%' OR is_cash_account = 1) AND is_group = 0 AND is_active = 1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

// 🚀 FILTER MUTLAK: Menggunakan jenis_transaksi ATAU variasi prefix no_jurnal 
$filter_jenis = "";
if ($view == 'transfer') {
    $filter_jenis = "(j.jenis_transaksi = 'transfer_kas' OR j.no_jurnal LIKE 'TRF%' OR j.no_jurnal LIKE 'TRA%')";
} elseif ($view == 'receipt') {
    $filter_jenis = "(j.jenis_transaksi = 'kas_masuk' OR j.no_jurnal LIKE 'BKM%')";
} else {
    $filter_jenis = "(j.jenis_transaksi = 'kas_keluar' OR j.no_jurnal LIKE 'BKK%')";
}

$sql_hist = "SELECT j.*, a1.nama_akun as nama_akun_utama, a2.nama_akun as nama_akun_tujuan 
             FROM syifa_jurnal j 
             LEFT JOIN syifa_akun a1 ON j.akun_utama_kode = a1.kode_akun
             LEFT JOIN syifa_akun a2 ON j.akun_tujuan_kode = a2.kode_akun
             WHERE $filter_jenis AND (j.no_jurnal LIKE '%$search%' OR j.pihak_nama LIKE '%$search%' OR j.keterangan LIKE '%$search%')
             ORDER BY j.tgl_jurnal DESC, j.id DESC LIMIT 50";
$history = $conn->query($sql_hist)->fetch_all(MYSQLI_ASSOC);
?>

<div class="container-fluid p-0 animate__animated animate__fadeIn text-dark">
    <!-- HEADER NAVIGASI -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border no-print text-dark">
        <div class="nav nav-pills gap-2">
            <a href="?page=transaksi_kas&view=receipt" class="nav-link <?= $view=='receipt'?'active':'' ?> rounded-pill px-4 fw-bold small">Penerimaan</a>
            <a href="?page=transaksi_kas&view=disbursement" class="nav-link <?= $view=='disbursement'?'active':'' ?> rounded-pill px-4 fw-bold small">Pengeluaran</a>
            <a href="?page=transaksi_kas&view=transfer" class="nav-link <?= $view=='transfer'?'active':'' ?> rounded-pill px-4 fw-bold small">Antar Kas</a>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="input-group input-group-sm" style="width: 250px;">
                <input type="hidden" name="page" value="transaksi_kas"><input type="hidden" name="view" value="<?= $view ?>">
                <input type="text" name="q" class="form-control border-0 bg-light px-3 shadow-none text-dark" placeholder="Cari data..." value="<?= htmlspecialchars($search) ?>" style="border-radius: 20px 0 0 20px;">
                <button type="submit" class="btn btn-primary border-0" style="border-radius: 0 20px 20px 0;"><i class="fas fa-search"></i></button>
            </form>

            <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="triggerAddTrx()">
                <i class="fas fa-plus me-2"></i>Tambah Baru
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if(isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-center text-dark">
                <i class="fas fa-info-circle me-2"></i>
                <div><?= $_SESSION['flash']['msg'] ?></div>
            </div>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- TABEL TRANSAKSI -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark">
        <div class="table-responsive" id="trxTableContainer">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-dark text-uppercase small text-center">
                    <tr>
                        <th class="ps-4 text-start" width="140">Aksi</th>
                        <th width="120">Tanggal</th>
                        <?php if($view == 'receipt'): ?>
                            <th class="text-center">Diterima Dari</th>
                            <th class="text-center">Masuk Ke Kas</th>
                        <?php elseif($view == 'disbursement'): ?>
                            <th class="text-center">Dibayar Kepada</th>
                            <th class="text-center">Sumber Kas</th>
                        <?php else: // transfer ?>
                            <th class="text-center">Dari Kas</th>
                            <th class="text-center">Ke Kas</th>
                        <?php endif; ?>
                        <th>Deskripsi</th>
                        <!-- 🚀 FIX LEBAR & ALIGNMENT: Menambah lebar kolom dan memastikan teks rata kanan -->
                        <th class="text-end pe-4" width="160">Jumlah</th>
                        <th width="50" class="text-center pe-4"><i class="fas fa-print"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($history)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted italic">Data transaksi tidak ditemukan.</td></tr>
                    <?php else: foreach($history as $row): 
                        $type = ($view == 'receipt') ? 'income' : (($view == 'disbursement') ? 'expense' : 'transfer');
                    ?>
                    <tr>
                        <td class="ps-4 text-start">
                            <div class="btn-group btn-group-sm rounded-pill border bg-white overflow-hidden shadow-sm">
                                <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                <button class="btn btn-white text-warning border-end" onclick="editTrxPopUp(<?= $row['id'] ?>, '<?= $type ?>')" title="Ubah Transaksi"><i class="fas fa-edit"></i></button>
                                <?php endif; ?>
                                
                                <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                                <button class="btn btn-white text-primary border-end" onclick="duplicateTrxAction(<?= $row['id'] ?>, '<?= $type ?>')" title="Duplikasi Transaksi"><i class="fas fa-clone"></i></button>
                                <?php endif; ?>
                                
                                <button class="btn btn-white text-info border-end" onclick="openJournalModal(<?= $row['id'] ?>)" title="Lihat Detail Jurnal"><i class="fas fa-eye"></i></button>

                                <?php if(defined('RBAC_DEL') && RBAC_DEL): ?>
                                <button class="btn btn-white text-danger" onclick="deleteTrxDirect(<?= $row['id'] ?>)" title="Hapus Permanen Jurnal Ini"><i class="fas fa-trash-alt"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="fw-bold text-center"><?= date('d/m/Y', strtotime($row['tgl_jurnal'])) ?></td>
                        
                        <?php if($view == 'receipt'): ?>
                            <td class="fw-bold text-dark text-center"><?= htmlspecialchars($row['pihak_nama']) ?: 'Umum' ?></td>
                            <td class="text-center"><span class="badge bg-light text-success border fw-bold"><?= htmlspecialchars($row['nama_akun_utama']) ?></span></td>
                        <?php elseif($view == 'disbursement'): ?>
                            <td class="fw-bold text-dark text-center"><?= htmlspecialchars($row['pihak_nama']) ?: 'Umum' ?></td>
                            <td class="text-center"><span class="badge bg-light text-danger border fw-bold"><?= htmlspecialchars($row['nama_akun_utama']) ?></span></td>
                        <?php else: // transfer ?>
                            <td class="text-center"><span class="badge bg-light text-primary border fw-bold"><?= htmlspecialchars($row['nama_akun_utama']) ?></span></td>
                            <td class="text-center"><span class="badge bg-light text-success border fw-bold"><?= htmlspecialchars($row['nama_akun_tujuan']) ?></span></td>
                        <?php endif; ?>
                        
                        <td><div class="text-muted small"><?= htmlspecialchars($row['keterangan']) ?></div><small class="text-muted" style="font-size: 9px;">Ref: <?= $row['no_jurnal'] ?></small></td>
                        
                        <!-- 🚀 FIX WRAPPING MUTLAK: Menggunakan white-space: nowrap untuk memaksa Rp dan Angka berdampingan -->
                        <td class="text-end fw-bold text-dark pe-4" style="white-space: nowrap;">
                            Rp <?= number_format($row['total_debet'], 0, ',', '.') ?>
                        </td>
                        
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-white text-success border rounded-circle shadow-sm p-2" onclick="window.open('print_voucher.php?id=<?= $row['id'] ?>', '_blank')" title="Cetak Bukti"><i class="fas fa-print"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'cash_modals_shared.php'; ?>

<script>
const kasBankListMaster = <?= json_encode($kas_bank_list) ?>;
const currentReturnPath = "transaksi_kas&view=<?= $view ?>";

window.onload = function() {
    const trigger = '<?= $trigger_new ?>';
    const lastId = '<?= $last_id ?>';
    if(trigger == '1') { setTimeout(() => { triggerAddTrx(); }, 500); } 
}

function triggerAddTrx() {
    const view = '<?= $view ?>';
    const type = (view === 'disbursement') ? 'expense' : ((view === 'transfer') ? 'transfer' : 'income');
    if(typeof openTrxModal === 'function') { 
        openTrxModal(type); 
        setTimeout(() => { document.getElementById('inpReturnPage').value = currentReturnPath; }, 200);
    } else { alert("Gagal memuat form transaksi. Pastikan cache browser sudah dibersihkan."); }
}

function editTrxPopUp(id, type) {
    if(typeof openTrxModal === 'function') { 
        openTrxModal(type, id); 
        setTimeout(() => { document.getElementById('inpReturnPage').value = currentReturnPath; }, 200);
    }
}

function duplicateTrxAction(id, type) {
    if (!type) {
        const view = '<?= $view ?>';
        type = (view === 'disbursement') ? 'expense' : ((view === 'transfer') ? 'transfer' : 'income');
    }
    if(typeof openTrxModal === 'function') { 
        openTrxModal(type, id, true); 
        setTimeout(() => { document.getElementById('inpReturnPage').value = currentReturnPath; }, 200);
    }
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
<style>
    .nav-pills .nav-link { color: #64748b; background: #f1f5f9; transition: 0.3s; }
    .nav-pills .nav-link:hover { background: #e2e8f0; color: #0d6efd; }
    .nav-pills .nav-link.active { background: #0d6efd; color: white; box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3); }
    .btn-white { background: #fff; border: none; transition: 0.2s; }
    .btn-white:hover { background: #f8f9fa; color: #0d6efd !important;}
</style>