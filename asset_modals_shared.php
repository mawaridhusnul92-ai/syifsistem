<?php
/**
 * asset_modals_shared.php - KOMPONEN UI MODAL ASET TERINTEGRASI
 * Versi: 4.2 (Grand Master - Sovereign Integrity Edition)
 * Perbaikan: Restore Migration Selector (Saldo Awal vs Pembelian), Fix JS editAsset.
 * Deskripsi: NBV = (Initial Purchase + CAPEX) - Accumulated.
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }
$categories = $conn->query("SELECT * FROM asset_categories ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$asset_types = $conn->query("SELECT * FROM asset_types ORDER BY type_code ASC, type_name ASC")->fetch_all(MYSQLI_ASSOC);
$kas_list = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE (kategori IN ('Kas', 'Bank') OR is_cash_account=1) AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!-- 1. MODAL REGISTRASI & EDIT ASSET -->
<div class="modal fade" id="modalAsset" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form action="asset_action.php" method="POST" id="formAsset" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_asset">
            <input type="hidden" name="id" id="ast_id">
            <div class="modal-header border-0 p-4 text-white" style="background: #1e293b;">
                <div class="d-flex align-items-center">
                    <div class="bg-primary p-3 rounded-4 me-3 shadow-sm text-white"><i class="fas fa-boxes fa-lg"></i></div>
                    <div>
                        <h5 class="fw-bold mb-0 text-white" id="ast_title">Registrasi Asset Baru</h5>
                        <small class="opacity-75 text-white">Manajemen Aset Tetap & Tak Berwujud</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <!-- RESTORED: Mode Selector (Saldo Awal vs Pembelian) -->
                <div id="modeSelectorRow" class="mb-4 p-3 rounded-4 border border-primary border-opacity-25 bg-white shadow-sm">
                    <label class="form-label small fw-bold text-primary"><i class="fas fa-exchange-alt me-2"></i>Tentukan Metode Pencatatan:</label>
                    <select name="input_mode" id="ast_mode" class="form-select border-0 fw-bold text-dark shadow-none" onchange="toggleMode(this.value)">
                        <option value="saldo_awal">Migrasi Saldo Awal (Aset Lama - Tanpa Potong Kas)</option>
                        <option value="pembelian">Pembelian Baru (Otomatis Buat Jurnal Kas Keluar)</option>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-12 text-dark"><label class="form-label small fw-bold text-muted uppercase">Nama Asset / Deskripsi Barang</label><input type="text" name="asset_name" id="ast_name" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div>
                    <div class="col-md-6 text-dark"><label class="form-label small fw-bold text-muted uppercase">Kategori (PSAK Mapping)</label><select name="category_id" id="ast_cat" class="form-select rounded-pill border-0 shadow-sm px-4 py-2"><?php foreach($categories as $c) echo "<option value='{$c['id']}'>{$c['category_name']}</option>"; ?></select></div>
                    <div class="col-md-6 text-dark"><label class="form-label small fw-bold text-muted uppercase">Jenis Klasifikasi</label><select name="type_id" id="ast_type" class="form-select rounded-pill border-0 shadow-sm px-4 py-2"><option value="">-- Pilih Jenis --</option><?php foreach($asset_types as $t) echo "<option value='{$t['id']}'>[{$t['type_code']}] {$t['type_name']}</option>"; ?></select></div>
                    <div class="col-md-6 text-dark"><label class="form-label small fw-bold text-muted uppercase">Tgl Perolehan</label><input type="date" name="purchase_date" id="ast_date" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div>
                    <div class="col-md-6" id="divSourceAcc" style="display:none;"><label class="form-label small fw-bold text-danger uppercase">Dibayar Melalui (Sumber Kas)</label><select name="source_account" id="ast_source" class="form-select rounded-pill border-0 shadow-sm px-4 bg-danger bg-opacity-10 fw-bold"><?php foreach($kas_list as $k) echo "<option value='{$k['kode_akun']}'>{$k['kode_akun']} - {$k['nama_akun']}</option>"; ?></select></div>
                    <div class="col-md-4 text-dark"><label class="form-label small fw-bold text-muted uppercase">Harga Perolehan (Bruto)</label><input type="text" name="purchase_value" id="ast_val" class="form-control rounded-pill border-0 shadow-sm px-4 text-end fw-bold text-primary" onkeyup="fmt(this)" required></div>
                    <div class="col-md-4 text-dark"><label class="form-label small fw-bold text-muted uppercase" id="labelCurrentValue">Nilai Buku Saat Ini</label><input type="text" name="current_book_value" id="ast_book" class="form-control rounded-pill border-0 shadow-sm px-4 text-end fw-bold text-success" onkeyup="fmt(this)"></div>
                    <div class="col-md-4 text-dark"><label class="form-label small fw-bold text-primary uppercase">Manfaat (Tahun)</label><input type="number" name="useful_life" id="ast_life" class="form-control rounded-pill border-0 shadow-sm px-4 text-center fw-bold text-primary" value="4" required></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 bg-light"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg" id="btnSubmitAst">SIMPAN DATA ASSET</button></div>
        </form>
    </div>
</div>

<!-- 2. MODAL SETUP JENIS ASSET -->
<div class="modal fade" id="modalSetupType" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <div class="modal-header border-0 p-4 bg-dark text-white">
                <h6 class="modal-title fw-bold text-white">Setup Klasifikasi Jenis Asset</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <form action="asset_action.php" method="POST" class="mb-4 bg-light p-3 rounded-4 border">
                    <input type="hidden" name="action" value="save_asset_type">
                    <div class="row g-2 text-dark">
                        <div class="col-4"><label class="small fw-bold opacity-50 uppercase">Kode</label><input type="text" name="type_code" class="form-control form-control-sm rounded-pill px-3 border-0 shadow-sm" placeholder="01" required></div>
                        <div class="col-8"><label class="small fw-bold opacity-50 uppercase">Klasifikasi</label><input type="text" name="type_name" class="form-control form-control-sm rounded-pill px-3 border-0 shadow-sm" placeholder="Misal: Kendaraan" required></div>
                        <div class="col-12 mt-2"><button class="btn btn-dark w-100 rounded-pill btn-sm fw-bold text-white shadow" type="submit">TAMBAH</button></div>
                    </div>
                </form>
                <div class="list-group list-group-flush rounded-4 border overflow-hidden text-dark"><?php foreach($asset_types as $at): ?><div class="list-group-item d-flex justify-content-between py-2 small text-dark"><div><span class="badge bg-primary me-2"><?= $at['type_code'] ?></span><b><?= $at['type_name'] ?></b></div><a href="asset_action.php?action=delete_asset_type&id=<?= $at['id'] ?>" class="text-danger" onclick="return confirm('Hapus?')"><i class="fas fa-trash-alt"></i></a></div><?php endforeach; ?></div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleMode(m) {
    const divSource = document.getElementById('divSourceAcc');
    const labelBook = document.getElementById('labelCurrentValue');
    const btnSubmit = document.getElementById('btnSubmitAst');
    if(m === 'pembelian') {
        divSource.style.display = 'block'; labelBook.innerText = "ESTIMASI NILAI RESIDU";
        btnSubmit.innerText = "DAFTARKAN & POSTING JURNAL"; btnSubmit.className = "btn btn-danger w-100 rounded-pill py-3 fw-bold shadow-lg";
    } else {
        divSource.style.display = 'none'; labelBook.innerText = "NILAI BUKU SAAT INI";
        btnSubmit.innerText = "SIMPAN SEBAGAI SALDO AWAL"; btnSubmit.className = "btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg";
    }
}
function showModalAsset(d = null) {
    const m = new bootstrap.Modal(document.getElementById('modalAsset'));
    const form = document.getElementById('formAsset');
    form.reset();
    if(d) {
        document.getElementById('ast_id').value = d.id;
        document.getElementById('ast_name').value = d.asset_name;
        document.getElementById('ast_cat').value = d.category_id;
        document.getElementById('ast_type').value = d.type_id;
        document.getElementById('ast_date').value = d.purchase_date;
        document.getElementById('ast_val').value = new Intl.NumberFormat('id-ID').format(d.purchase_value);
        document.getElementById('ast_book').value = new Intl.NumberFormat('id-ID').format(d.current_book_value);
        document.getElementById('ast_life').value = Math.round(d.useful_life / 12);
        document.getElementById('modeSelectorRow').style.display = 'none';
        document.getElementById('divSourceAcc').style.display = 'none';
        document.getElementById('ast_title').innerText = 'Ubah Informasi Aset';
        document.getElementById('btnSubmitAst').innerText = 'SIMPAN PERUBAHAN ASET';
    } else {
        document.getElementById('modeSelectorRow').style.display = 'block';
        document.getElementById('ast_mode').value = 'saldo_awal';
        document.getElementById('ast_title').innerText = 'Registrasi Aset Baru';
        toggleMode('saldo_awal');
    }
    m.show();
}
function editAsset(data) { showModalAsset(data); }
function showModalSetupType() { new bootstrap.Modal(document.getElementById('modalSetupType')).show(); }
function fmt(el){ el.value = new Intl.NumberFormat('id-ID').format(el.value.replace(/\D/g, "")); }
function fmtRp(el){ el.value = new Intl.NumberFormat('id-ID').format(el.value.replace(/\D/g, "")); }
</script>