<?php
/**
 * transaksi_unit.php - PUSAT PENCATATAN TRANSAKSI KAS UNIT
 * Versi: 3.1 (Sovereign Grand Master - Direct Delete & Voucher Edition)
 * STATUS: FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak:
 * Menambahkan kolom dan tombol "Cetak Bukti (Voucher)" di sisi paling kanan 
 * tabel (setelah kolom Jumlah) agar unit bisa langsung mencetak bukti fisik.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

if (!function_exists('formatRp')) { function formatRp($n) { return "Rp " . number_format($n ?? 0, 0, ',', '.'); } }

$uid = (int)$_SESSION['user_id'];
$search = $conn->real_escape_string($_GET['q'] ?? '');

$sql_role = "SELECT r.unit_id, r.is_ka_unit, r.role_name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = '$uid'";
$res_role = $conn->query($sql_role);
$u_role = $res_role ? $res_role->fetch_assoc() : null;

$role_name_upper = strtoupper($u_role['role_name'] ?? '');

$is_global_admin = ($_SESSION['role_id'] == 1 || in_array($role_name_upper, ['PIMPINAN','BAUK','ADMIN','SUPERADMIN','CHECKER','SPI']));
$is_ka_unit = ($u_role && $u_role['is_ka_unit'] == 1);

// Logika Pemilihan Unit
$active_unit_id = 0;
if ($is_global_admin) {
    $active_unit_id = isset($_GET['unit_id']) && $_GET['unit_id'] !== '' ? (int)$_GET['unit_id'] : 0;
    if ($active_unit_id === 0) {
        $first_unit = $conn->query("SELECT id FROM m_unit ORDER BY nama_unit ASC LIMIT 1")->fetch_assoc();
        $active_unit_id = $first_unit ? (int)$first_unit['id'] : 0;
    }
} else {
    $active_unit_id = (int)($u_role['unit_id'] ?? 0);
}

$unit = $conn->query("SELECT * FROM m_unit WHERE id = '$active_unit_id'")->fetch_assoc();

if(!$unit) {
    echo "<div class='container-fluid py-4'><div class='alert alert-danger rounded-4 shadow-sm fw-bold p-4 text-center'><i class='fas fa-exclamation-triangle fa-2x mb-3 d-block'></i>Akses Ditolak: Data Unit Kerja tidak ditemukan atau Akun belum terikat. Hubungi Administrator.</div></div>";
    exit;
}

$kode_unit = $unit['kode_unit'];
$kas_akun = $unit['kas_bank_akun'];
$nama_kas = $conn->query("SELECT nama_akun FROM syifa_akun WHERE kode_akun = '$kas_akun'")->fetch_assoc()['nama_akun'] ?? 'Kas Unit Belum Diset';

// 2. AMBIL MAPPING COA KHUSUS UNIT INI
$coa_unit = $conn->query("SELECT a.kode_akun, a.nama_akun FROM unit_coa_map m JOIN syifa_akun a ON m.kode_akun = a.kode_akun WHERE m.unit_id = '$active_unit_id'")->fetch_all(MYSQLI_ASSOC);

// 3. AMBIL HISTORI TRANSAKSI
$prefix_desc = "[Unit " . $kode_unit . "]";
$sql_hist = "SELECT j.*, a1.nama_akun as nama_akun_utama
             FROM syifa_jurnal j 
             LEFT JOIN syifa_akun a1 ON j.akun_utama_kode = a1.kode_akun
             WHERE j.no_jurnal LIKE 'BKK%' 
             AND j.keterangan LIKE '$prefix_desc%' 
             AND (j.no_jurnal LIKE '%$search%' OR j.pihak_nama LIKE '%$search%' OR j.keterangan LIKE '%$search%')
             ORDER BY j.tgl_jurnal DESC, j.id DESC LIMIT 50";
$history = $conn->query($sql_hist)->fetch_all(MYSQLI_ASSOC);
?>

<div class="container-fluid p-0 animate__animated animate__fadeIn text-dark">
    <!-- HEADER NAVIGASI -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border no-print text-dark">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white p-2 rounded-3"><i class="fas fa-file-invoice-dollar fa-lg"></i></div>
            <div>
                <h5 class="fw-bold mb-0 text-dark">Transaksi Kas Unit</h5>
                <small class="text-muted fw-bold">Otoritas Pencatatan Pengeluaran <?= strtoupper($unit['nama_unit']) ?></small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <?php if($is_global_admin): ?>
            <!-- Filter Pilih Unit untuk Superadmin -->
            <form method="GET" class="d-flex bg-light rounded-pill px-3 border align-items-center shadow-sm text-dark me-2">
                <input type="hidden" name="page" value="transaksi_unit">
                <span class="small fw-bold text-muted me-2">FILTER UNIT:</span>
                <select name="unit_id" class="form-select border-0 bg-transparent fw-bold text-primary shadow-none" onchange="this.form.submit()" style="width:150px;">
                    <?php 
                    $all_u = $conn->query("SELECT id, kode_unit FROM m_unit ORDER BY kode_unit ASC");
                    while($uu = $all_u->fetch_assoc()) echo "<option value='{$uu['id']}' ".($active_unit_id==$uu['id']?'selected':'').">{$uu['kode_unit']}</option>"; 
                    ?>
                </select>
            </form>
            <?php endif; ?>

            <form method="GET" class="input-group input-group-sm text-dark" style="width: 200px;">
                <input type="hidden" name="page" value="transaksi_unit">
                <?php if($is_global_admin): ?><input type="hidden" name="unit_id" value="<?= $active_unit_id ?>"><?php endif; ?>
                <input type="text" name="q" class="form-control border-0 bg-light px-3 shadow-none text-dark" placeholder="Cari transaksi..." value="<?= htmlspecialchars($search) ?>" style="border-radius: 20px 0 0 20px;">
                <button type="submit" class="btn btn-primary border-0" style="border-radius: 0 20px 20px 0;"><i class="fas fa-search"></i></button>
            </form>
            
            <?php if(!empty($kas_akun)): ?>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="openUnitTrxModal()">
                <i class="fas fa-plus me-2"></i>Catat Baru
            </button>
            <?php else: ?>
            <button class="btn btn-secondary rounded-pill px-4 fw-bold shadow text-uppercase" disabled title="Kas belum di-setting">
                <i class="fas fa-lock me-2"></i>Kas Belum Diset
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if(isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-info-circle me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- TABEL TRANSAKSI -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-dark" style="font-size: 0.88rem;">
                <thead class="table-dark text-uppercase small text-white">
                    <tr>
                        <th class="ps-4 text-center" width="140">Aksi</th>
                        <th class="text-center" width="120">Tanggal</th>
                        <th class="text-start">Dibayar Kepada</th>
                        <th class="text-center">Sumber Dana (Kas)</th>
                        <th class="text-start">Deskripsi</th>
                        <th class="text-end">Jumlah</th>
                        <!-- ??? Header Kolom Voucher Dipersempit -->
                        <th class="text-center pe-4" width="80"><i class="fas fa-print"></i></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($history)): ?>
                        <!-- ??? Fix Colspan -->
                        <tr><td colspan="7" class="text-center py-5 text-muted italic">Tidak ada histori transaksi pengeluaran unit.</td></tr>
                    <?php else: foreach($history as $row): ?>
                    <tr>
                        <td class="ps-4 text-center">
                            <!-- FIX: Menambahkan Tombol Edit, Duplicate, View, & Delete -->
                            <div class="btn-group btn-group-sm rounded-pill border bg-white overflow-hidden shadow-sm">
                                <button class="btn btn-white text-warning border-end" onclick="editUnitTrx(<?= $row['id'] ?>)" title="Ubah Transaksi"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-white text-primary border-end" onclick="duplicateUnitTrx(<?= $row['id'] ?>)" title="Duplikasi Transaksi"><i class="fas fa-clone"></i></button>
                                <button class="btn btn-white text-info border-end" onclick="showJournalDetail(<?= $row['id'] ?>)" title="Detail Jurnal"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-white text-danger" onclick="deleteTrxDirect(<?= $row['id'] ?>)" title="Hapus Permanen"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </td>
                        <td class="fw-bold text-center"><?= date('d/m/Y', strtotime($row['tgl_jurnal'])) ?></td>
                        <td class="text-start"><span class="fw-bold text-dark"><?= htmlspecialchars($row['pihak_nama']) ?: 'Umum' ?></span></td>
                        <td class="text-center"><span class="badge bg-light text-danger border fw-bold"><?= htmlspecialchars($row['nama_akun_utama']) ?></span></td>
                        <td class="text-start"><div class="text-muted small"><?= htmlspecialchars($row['keterangan']) ?></div><small class="text-muted" style="font-size: 9px;">Ref: <?= $row['no_jurnal'] ?></small></td>
                        <td class="text-end fw-bold text-dark">Rp <?= number_format($row['total_kredit']) ?></td>
                        <!-- ??? Tombol Cetak Voucher / Bukti Transaksi Diperkecil (Ikon Lingkaran Hijau) -->
                        <td class="text-center pe-4">
                            <a href="print_voucher.php?id=<?= $row['id'] ?>" target="_blank" class="btn btn-outline-success rounded-circle shadow-sm d-inline-flex align-items-center justify-content-center p-0" style="width: 32px; height: 32px;" title="Cetak Bukti Transaksi"><i class="fas fa-print"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL INPUT TRANSAKSI KHUSUS UNIT (ISOLATED) -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalUnitTrx" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <div class="modal-header bg-danger text-white p-4">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-file-invoice-dollar me-2"></i>Pengeluaran Kas Unit (Expense)</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <!-- Mengarah ke accounting action -->
            <form action="accounting_action.php" method="POST" id="formUnitTrx" onsubmit="handleUnitSubmit(event)">
                <input type="hidden" name="action" value="save_cash_trx">
                <input type="hidden" name="type" value="expense">
                <input type="hidden" name="akun_utama" value="<?= $kas_akun ?>">
                <input type="hidden" name="return_page" value="transaksi_unit<?= $is_global_admin ? '&unit_id='.$active_unit_id : '' ?>">
                
                <!-- FIX: Hidden ID & Duplicate Flag untuk backend -->
                <input type="hidden" name="id" id="inpUnitId" value="">
                <input type="hidden" name="is_duplicate" id="inpUnitIsDup" value="0">

                <div class="modal-body p-4 bg-light text-dark">
                    <!-- INFO HEADER -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1 uppercase">Tanggal Transaksi</label>
                            <input type="date" name="tgl_jurnal" id="unitInpDate" class="form-control rounded-pill border shadow-sm px-3 fw-bold" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1 uppercase text-center">No. Referensi</label>
                            <input type="text" name="no_jurnal" id="unitInpRef" class="form-control rounded-pill border-0 shadow-sm px-3 bg-white fw-bold text-center" readonly placeholder="Auto Generated">
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1 uppercase">Dibayarkan Kepada</label>
                            <input type="text" name="pihak_nama" id="unitInpPihak" class="form-control rounded-pill border shadow-sm px-3" placeholder="Nama Penerima Uang" required>
                        </div>
                    </div>

                    <!-- AKUN KAS UTAMA (LOCKED) -->
                    <div class="mb-4">
                        <label class="small fw-bold text-primary mb-1 uppercase"><i class="fas fa-lock me-1"></i> Sumber Dana (Kas Terkunci)</label>
                        <div class="form-control rounded-pill border-0 shadow-sm px-4 bg-white fw-bold text-dark d-flex align-items-center">
                            <i class="fas fa-wallet text-muted me-3"></i> <?= $kas_akun ?> - <?= $nama_kas ?>
                        </div>
                    </div>

                    <!-- AREA DETAIL (RINCIAN AKUN LAWAN) -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="small fw-bold text-muted uppercase">Rincian Pos Biaya (Beban)</label>
                            <button type="button" class="btn btn-sm btn-primary rounded-pill fw-bold px-3 shadow-sm" onclick="addUnitRow()"><i class="fas fa-plus me-1"></i> Tambah Item</button>
                        </div>
                        <div id="unitContainerRows"></div>
                    </div>

                    <!-- KETERANGAN & TOTAL -->
                    <div class="row mt-4">
                        <div class="col-md-12 mb-3">
                            <label class="small fw-bold text-muted mb-1 uppercase">Deskripsi / Keterangan Transaksi</label>
                            <textarea name="keterangan" id="unitInpDesc" class="form-control border shadow-sm rounded-4 px-3 py-2 fw-bold" rows="2" placeholder="Tuliskan tujuan pengeluaran..." required></textarea>
                            <small class="text-muted" style="font-size: 10px;">*Sistem otomatis menambahkan "[Unit <?= $kode_unit ?>] - " di awal keterangan saat disimpan.</small>
                        </div>
                        <div class="col-md-12">
                            <div class="alert alert-danger border-0 rounded-4 d-flex justify-content-between align-items-center px-4 py-3 mb-0 shadow-sm">
                                <span class="fw-bold uppercase small text-dark">Total Pengeluaran</span>
                                <h4 class="fw-bold mb-0 text-dark" id="unitTotalDisplay">Rp 0</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 p-4 bg-white text-center">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-5 fw-bold shadow">SIMPAN PENGELUARAN</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Sertakan Modals Shared agar showJournalDetail berfungsi! -->
<?php include 'cash_modals_shared.php'; ?>

<script>
const coaUnitMaster = <?= json_encode($coa_unit) ?>;
const prefixDesc = "[Unit <?= $kode_unit ?>] - ";

function openUnitTrxModal() {
    document.getElementById('formUnitTrx').reset();
    document.getElementById('unitContainerRows').innerHTML = '';
    document.getElementById('unitTotalDisplay').innerText = 'Rp 0';
    document.getElementById('inpUnitId').value = '';
    document.getElementById('inpUnitIsDup').value = '0';
    document.getElementById('unitInpDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('unitInpRef').value = 'Auto Generated';
    
    addUnitRow(); // Otomatis buat 1 baris
    new bootstrap.Modal(document.getElementById('modalUnitTrx')).show();
}

function addUnitRow(kode = '', nominal = 0) {
    const id = Date.now() + Math.floor(Math.random() * 100);
    const nomVal = nominal > 0 ? new Intl.NumberFormat('id-ID').format(nominal) : '';
    
    let coaOptions = '<option value="">-- Pilih Akun Biaya --</option>';
    if(coaUnitMaster.length === 0) {
        coaOptions = '<option value="">(Belum Ada Mapping COA! Hubungi Admin)</option>';
    } else {
        coaUnitMaster.forEach(c => {
            const isSelected = (kode === c.kode_akun) ? 'selected' : '';
            coaOptions += `<option value="${c.kode_akun}" ${isSelected}>${c.kode_akun} - ${c.nama_akun}</option>`;
        });
    }

    const html = `
    <div class="row g-2 mb-2 align-items-center item-row border-bottom pb-2" id="urow_${id}">
        <div class="col-md-6">
            <select name="lawan_akun[]" class="form-select border shadow-sm rounded-pill fw-bold text-dark" required>
                ${coaOptions}
            </select>
        </div>
        <div class="col-md-5">
            <div class="input-group shadow-sm rounded-pill overflow-hidden border">
                <span class="input-group-text border-0 bg-light text-muted fw-bold">Rp</span>
                <input type="text" name="nominal[]" class="form-control border-0 fw-bold unit-inp-amt text-end pe-3" 
                       onkeyup="fmtRpUnit(this); calcUnitTotal();" value="${nomVal}" placeholder="0" required>
            </div>
        </div>
        <div class="col-md-1 text-center">
            <button type="button" class="btn btn-link text-danger p-0" onclick="document.getElementById('urow_${id}').remove(); calcUnitTotal();">
                <i class="fas fa-times-circle fa-lg"></i>
            </button>
        </div>
    </div>`;
    document.getElementById('unitContainerRows').insertAdjacentHTML('beforeend', html);
}

function fmtRpUnit(el) { 
    let rawStr = el.value.replace(/[^0-9]/g, ""); 
    if(rawStr === "") { el.value = ""; return; }
    let num = parseInt(rawStr, 10);
    el.value = new Intl.NumberFormat('id-ID').format(num); 
}

function calcUnitTotal() {
    let t = 0;
    document.querySelectorAll('.unit-inp-amt').forEach(i => {
        let rawStr = i.value.replace(/[^0-9]/g, '');
        if (rawStr) { t += parseFloat(rawStr); }
    });
    document.getElementById('unitTotalDisplay').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(t);
}

function editUnitTrx(id) {
    document.getElementById('formUnitTrx').reset();
    document.getElementById('unitContainerRows').innerHTML = '';
    document.getElementById('inpUnitId').value = id;
    document.getElementById('inpUnitIsDup').value = '0';
    
    fetch('ajax_cash.php?action=get_trx_detail&id=' + id).then(r=>r.json()).then(d=>{
        if(d.error) { alert("Data tidak ditemukan."); return; }
        
        document.getElementById('unitInpDate').value = d.header.tgl_jurnal;
        document.getElementById('unitInpRef').value = d.header.no_jurnal;
        document.getElementById('unitInpPihak').value = d.header.pihak_nama;
        
        let rawDesc = d.header.keterangan;
        if (rawDesc.startsWith(prefixDesc)) {
            rawDesc = rawDesc.replace(prefixDesc, '');
        }
        document.getElementById('unitInpDesc').value = rawDesc;
        
        d.details.forEach(item => {
            if(item.kode_akun == '<?= $kas_akun ?>') return;
            const nominal = Math.max(parseFloat(item.debit || 0), parseFloat(item.kredit || 0));
            addUnitRow(item.kode_akun, nominal);
        });
        
        calcUnitTotal();
        new bootstrap.Modal(document.getElementById('modalUnitTrx')).show();
    });
}

function duplicateUnitTrx(id) {
    document.getElementById('formUnitTrx').reset();
    document.getElementById('unitContainerRows').innerHTML = '';
    document.getElementById('inpUnitId').value = '';
    document.getElementById('inpUnitIsDup').value = '1'; 
    
    fetch('ajax_cash.php?action=get_trx_detail&id=' + id).then(r=>r.json()).then(d=>{
        if(d.error) { alert("Data tidak ditemukan."); return; }
        
        document.getElementById('unitInpDate').value = '<?= date('Y-m-d') ?>'; 
        document.getElementById('unitInpRef').value = 'Auto Generated';
        document.getElementById('unitInpPihak').value = d.header.pihak_nama;
        
        let rawDesc = d.header.keterangan;
        if (rawDesc.startsWith(prefixDesc)) {
            rawDesc = rawDesc.replace(prefixDesc, '');
        }
        document.getElementById('unitInpDesc').value = rawDesc;
        
        d.details.forEach(item => {
            if(item.kode_akun == '<?= $kas_akun ?>') return;
            const nominal = Math.max(parseFloat(item.debit || 0), parseFloat(item.kredit || 0));
            addUnitRow(item.kode_akun, nominal);
        });
        
        calcUnitTotal();
        new bootstrap.Modal(document.getElementById('modalUnitTrx')).show();
    });
}

function showJournalDetail(id) {
    if(typeof openJournalModal === 'function') { openJournalModal(id); } 
    else { alert("Modul detail belum termuat."); }
}

// ??? THE DIRECT DELETE ENGINE
function deleteTrxDirect(id) {
    if(confirm('PENTING: Hapus transaksi kas ini secara permanen? Saldo buku besar akan otomatis dikoreksi ke posisi awal.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'accounting_action.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete_trx"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}

function handleUnitSubmit(e) {
    const descEl = document.getElementById('unitInpDesc');
    if (!descEl.value.startsWith(prefixDesc)) {
        descEl.value = prefixDesc + descEl.value;
    }
}
</script>

<style>
    .btn-white { background: #fff; border: none; transition: 0.2s; } 
    .btn-white:hover { background: #f8f9fa; color: #0d6efd !important; }
</style>