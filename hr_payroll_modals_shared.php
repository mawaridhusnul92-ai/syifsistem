<?php
/**
 * hr_payroll_modals_shared.php - MODAL MASTER HRIS TERINTEGRASI
 * Versi: 2.4 (Sovereign Grand Master - Accounting Attribute Edition)
 * Deskripsi: Menampung Modal Komponen & Jabatan agar terhubung dengan hr_action.php.
 * Perbaikan: Penambahan kolom Akun Tipe dan Status THR pada modal komponen.
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// Ambil data COA Detail aktif
$coa_list = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!-- 1. MODAL KONFIGURASI KOMPONEN -->
<div class="modal fade" id="modalKomponen" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white p-4">
                <h6 class="modal-title fw-bold"><i class="fas fa-layer-group me-2"></i>Setup Komponen Gaji</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form action="hr_action.php" method="POST">
                <input type="hidden" name="action" value="save_komponen">
                <input type="hidden" name="id" id="komp_id">
                
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1 uppercase">Nama Komponen</label>
                        <input type="text" name="nama_komponen" id="komp_nama" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="Misal: Tunjangan Transport" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold text-muted mb-1 uppercase">Kategori</label>
                            <select name="jenis" id="komp_jenis" class="form-select rounded-pill border-0 shadow-sm px-3" required>
                                <option value="Pendapatan">Pendapatan (+)</option>
                                <option value="Potongan">Potongan (-)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-muted mb-1 uppercase">Sifat</label>
                            <select name="sifat" id="komp_sifat" class="form-select rounded-pill border-0 shadow-sm px-3" required>
                                <option value="Tetap">Tetap (Rutin)</option>
                                <option value="Variabel">Variabel (Tidak Tetap)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1 uppercase">Akun COA (Mapping)</label>
                        <select name="kode_akun" id="komp_coa" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold" required>
                            <option value="">-- Pilih Akun COA --</option>
                            <?php foreach($coa_list as $c) echo "<option value='{$c['kode_akun']}'>{$c['kode_akun']} - {$c['nama_akun']}</option>"; ?>
                        </select>
                    </div>

                    <!-- ?? NEW UI: PERLAKUAN AKUNTANSI & THR GUARD -->
                    <div class="mt-3">
                        <label class="form-label small fw-bold text-muted uppercase mb-1">Perlakuan Akuntansi</label>
                        <select name="akun_tipe" id="komp_akun_tipe" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold text-dark">
                            <option value="Beban">Beban Operasional</option>
                            <option value="Kewajiban">Titipan / Hutang (BPJS, Pajak)</option>
                            <option value="Kontra Beban">Pengurang Beban</option>
                        </select>
                    </div>
                    
                    <div class="form-check mt-3 mb-2 px-4 py-2 bg-white rounded-pill shadow-sm border d-inline-block">
                        <input class="form-check-input ms-1" type="checkbox" name="is_thr_component" value="1" id="komp_is_thr">
                        <label class="form-check-label small fw-bold text-dark ms-2" for="komp_is_thr">Digunakan dalam THR / Bonus</label>
                    </div>

                </div>
                <div class="modal-footer p-4 border-0 bg-white">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">SIMPAN KOMPONEN</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2. MODAL KONFIGURASI JABATAN -->
<div class="modal fade" id="modalJabatan" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-danger text-white p-4">
                <h6 class="modal-title fw-bold"><i class="fas fa-sitemap me-2"></i>Setup Jabatan & Golongan</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form action="hr_action.php" method="POST">
                <input type="hidden" name="action" value="save_jabatan">
                <input type="hidden" name="id" id="jab_id">
                
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted mb-1 uppercase">Nama Jabatan</label>
                        <input type="text" name="nama_jabatan" id="jab_nama" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="Misal: Kepala Biro Keuangan" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold text-muted mb-1 uppercase">Golongan</label>
                            <input type="text" name="golongan" id="jab_gol" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="Misal: III/a" required>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-muted mb-1 uppercase">Level Hirarki</label>
                            <input type="number" name="level_jabatan" id="jab_level" class="form-control rounded-pill border-0 shadow-sm px-3 text-center" min="1" max="10" value="5" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-4 border-0 bg-white">
                    <button type="submit" class="btn btn-danger w-100 rounded-pill py-3 fw-bold shadow">SIMPAN JABATAN</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 3. MODAL SETUP GAJI INDIVIDU (MASTER) -->
<div class="modal fade" id="modalSetupGaji" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden bg-light">
            <div class="modal-header bg-dark text-white p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-money-check-alt me-2 text-success"></i>Konfigurasi Gaji Individu</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form action="hr_action.php" method="POST" id="formSetupGaji">
                <input type="hidden" name="action" value="save_pegawai_setup">
                <input type="hidden" name="pegawai_id" id="setup_peg_id">
                
                <div class="row g-0">
                    <div class="col-lg-6 border-end">
                        <div class="p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold text-success mb-0"><i class="fas fa-plus-circle me-2"></i>Komponen Pendapatan</h6>
                                <button type="button" class="btn btn-sm btn-success rounded-pill fw-bold px-3 shadow-sm" onclick="addItem('income')"><i class="fas fa-plus"></i></button>
                            </div>
                            <div id="container_income" class="min-vh-50"></div>
                            <div class="alert bg-success bg-opacity-10 text-success border-0 rounded-3 mt-3 fw-bold text-end" id="total_income_disp">Total Bruto: Rp 0</div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold text-danger mb-0"><i class="fas fa-minus-circle me-2"></i>Komponen Potongan</h6>
                                <button type="button" class="btn btn-sm btn-danger rounded-pill fw-bold px-3 shadow-sm" onclick="addItem('deduct')"><i class="fas fa-plus"></i></button>
                            </div>
                            <div id="container_deduct" class="min-vh-50"></div>
                            <div class="alert bg-danger bg-opacity-10 text-danger border-0 rounded-3 mt-3 fw-bold text-end" id="total_deduct_disp">Total Potongan: Rp 0</div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-white border-top p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="small fw-bold text-muted uppercase d-block">Take Home Pay (THP)</span>
                        <h3 class="fw-bold text-primary mb-0" id="thp_disp">Rp 0</h3>
                    </div>
                    <div>
                        <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm me-2" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" class="btn btn-dark rounded-pill px-5 py-3 fw-bold shadow">SIMPAN KONFIGURASI</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/** * LOGIKA JS MODAL KOMPONEN & JABATAN DENGAN ATRIBUT BARU */
function showModalKomponen(data = null) {
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKomponen'));
    document.getElementById('komp_id').value = data ? data.id : '';
    document.getElementById('komp_nama').value = data ? data.nama_komponen : '';
    document.getElementById('komp_jenis').value = data ? data.jenis : 'Pendapatan';
    document.getElementById('komp_sifat').value = data ? data.sifat : 'Tetap';
    document.getElementById('komp_coa').value = data ? data.kode_akun : '';
    
    // Injeksi Atribut Akuntansi Baru
    document.getElementById('komp_akun_tipe').value = (data && data.akun_tipe) ? data.akun_tipe : 'Beban';
    document.getElementById('komp_is_thr').checked = data ? (data.is_thr_component == 1) : false;
    
    m.show();
}

function showModalJabatan(data = null) {
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalJabatan'));
    document.getElementById('jab_id').value = data ? data.id : '';
    document.getElementById('jab_nama').value = data ? data.nama_jabatan : '';
    document.getElementById('jab_gol').value = data ? data.golongan : '';
    document.getElementById('jab_level').value = data ? data.level_jabatan : '5';
    m.show();
}

function editKomponen(data) { showModalKomponen(data); }
function confirmDeleteKom(id) { if(confirm('Hapus komponen gaji?')) window.location.href = `hr_action.php?action=delete_komponen&id=${id}`; }

/** * PERSISTENCE JS: MENGAMBIL DATA SETUP PEGAWAI */
function editSetup(pegId) {
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSetupGaji'));
    document.getElementById('setup_peg_id').value = pegId;
    fetch(`hr_action.php?action=get_setup&id=${pegId}`)
        .then(r => r.json())
        .then(d => {
            document.getElementById('container_income').innerHTML = '';
            document.getElementById('container_deduct').innerHTML = '';
            d.income.forEach(i => addItem('income', i.komponen_id, i.nominal));
            d.deduct.forEach(i => addItem('deduct', i.komponen_id, i.nominal));
            calcTotal();
            m.show();
        });
}

const listInc = <?= json_encode($conn->query("SELECT id, nama_komponen FROM hr_komponen WHERE jenis='Pendapatan' ORDER BY nama_komponen ASC")->fetch_all(MYSQLI_ASSOC)) ?>;
const listDed = <?= json_encode($conn->query("SELECT id, nama_komponen FROM hr_komponen WHERE jenis='Potongan' ORDER BY nama_komponen ASC")->fetch_all(MYSQLI_ASSOC)) ?>;

function addItem(type, selId = '', nominal = 0) {
    const container = document.getElementById('container_' + type);
    const list = type === 'income' ? listInc : listDed;
    const namePrefix = type === 'income' ? 'inc' : 'ded';
    const bgCls = type === 'income' ? 'bg-white border' : 'bg-white border';
    const nomStr = nominal > 0 ? new Intl.NumberFormat('id-ID').format(nominal) : '';
    
    let opt = '<option value="">-- Pilih --</option>';
    list.forEach(k => { opt += `<option value="${k.id}" ${selId==k.id?'selected':''}>${k.nama_komponen}</option>`; });

    const div = document.createElement('div');
    div.className = `row g-2 mb-2 align-items-center item-row ${bgCls} p-2 rounded-3 shadow-sm`;
    div.innerHTML = `
        <div class="col-7"><select name="${namePrefix}_id[]" class="form-select form-select-sm border-0 bg-transparent fw-bold" required>${opt}</select></div>
        <div class="col-4"><input type="text" name="${namePrefix}_nominal[]" class="form-control form-control-sm border-0 bg-light text-end fw-bold" placeholder="Rp 0" value="${nomStr}" onkeyup="this.value=this.value.replace(/[^0-9]/g,''); this.value=new Intl.NumberFormat('id-ID').format(this.value); calcTotal();" required></div>
        <div class="col-1"><button type="button" class="btn btn-link text-danger p-1" onclick="this.closest('.item-row').remove(); calcTotal();"><i class="fas fa-times-circle"></i></button></div>
    `;
    container.appendChild(div);
}

function calcTotal() {
    let tI = 0, tD = 0;
    document.getElementsByName('inc_nominal[]').forEach(el => tI += parseFloat(el.value.replace(/\./g, '')) || 0);
    document.getElementsByName('ded_nominal[]').forEach(el => tD += parseFloat(el.value.replace(/\./g, '')) || 0);
    document.getElementById('total_income_disp').innerText = 'Total Bruto: Rp ' + new Intl.NumberFormat('id-ID').format(tI);
    document.getElementById('total_deduct_disp').innerText = 'Total Potongan: Rp ' + new Intl.NumberFormat('id-ID').format(tD);
    document.getElementById('thp_disp').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(tI - tD);
}

function deleteSetup(id, nama) {
    if(confirm(`Kosongkan konfigurasi gaji [${nama}]?`)) window.location.href = `hr_action.php?action=delete_pegawai_setup&id=${id}`;
}
</script>