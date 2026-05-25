<?php
/**
 * cash_modals_shared.php - MODAL TRANSAKSI KAS & BANK TERPADU
 * Versi: 85.0 (Sovereign Grand Master - Anti HTML Alert Shield)
 * Perbaikan Mutlak: 
 * 1. Memperbaiki bug Javascript saat tombol "Simpan & Baru" ditekan, 
 * yang sebelumnya memunculkan popup berisi kode HTML secara keseluruhan.
 * 2. Logika validasi response AJAX telah diperketat.
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// 🚀 IDEMPOTENT KEY GENERATOR (ANTI-DOUBLE SUBMIT SHIELD)
// Membuat token acak unik untuk memastikan form tidak bisa dikirim dua kali
$idempotent_key = bin2hex(random_bytes(16)) . '_' . time();

$coa_beban_pend = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
$kas_bank_shared = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE (kategori IN ('Kas', 'Bank') OR kode_akun LIKE '1-11%' OR is_cash_account=1) AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

$mhs_list = [];
try {
    $res_mhs = $conn->query("SELECT id, nim, nama FROM syifa_mahasiswa ORDER BY nama ASC");
    if($res_mhs) $mhs_list = $res_mhs->fetch_all(MYSQLI_ASSOC);
} catch(Exception $e){}

$asset_list = [];
try {
    $res_ast = $conn->query("SELECT id, asset_code as kode, asset_name as nama FROM assets WHERE status='Aktif' ORDER BY asset_name ASC");
    if($res_ast) $asset_list = $res_ast->fetch_all(MYSQLI_ASSOC);
} catch(Exception $e){}

$pgw_list = [];
try {
    $res_pgw = $conn->query("SELECT id, nip, nama_lengkap as nama FROM hr_pegawai ORDER BY nama_lengkap ASC");
    if($res_pgw) $pgw_list = $res_pgw->fetch_all(MYSQLI_ASSOC);
} catch(Exception $e){}
?>

<style>
    .modal { z-index: 9999 !important; }
    .modal-backdrop { z-index: 9998 !important; }
    @media (min-width: 992px) { .custom-modal-width { max-width: 780px !important; } }

    /* 🚀 REKOMENDASI TATA GAYA: SOLID BLACK COLOR AT KAS/BANK AUTOCOMPLETE SEARCH */
    #inpMainAccSearch {
        color: #000000 !important;
        font-weight: 700 !important;
    }
    #inpMainAccSearch::placeholder {
        color: #94a3b8 !important;
        font-weight: normal !important;
    }

    .coa-suggest-container { position: relative !important; }
    .omni-suggest-box, .coa-suggestions {
        position: absolute !important; top: 100% !important; left: 0 !important; right: 0 !important;
        z-index: 10500 !important; background: #fff; border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.25);
        max-height: 200px; overflow-y: auto; border: 1px solid #0d6efd;
        display: none; margin-top: 5px;
    }
    .coa-item, .omni-suggest-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px dashed #f1f5f9; transition: 0.2s; font-size: 0.85rem; color: #334155; }
    .coa-item:hover, .omni-suggest-item:hover { background-color: #0d6efd !important; color: #ffffff !important; }
    .coa-item code, .omni-suggest-item code { background: #f1f5f9; padding: 2px 5px; border-radius: 4px; color: #0d6efd; margin-right: 8px; font-weight: bold; }
    .coa-item small, .omni-suggest-item small { display: block; font-size: 10px; color: #94a3b8; margin-top: 2px; }
    
    .modal-body { max-height: 65vh !important; overflow-y: auto !important; overflow-x: hidden !important; padding-bottom: 80px !important; }
    
    .bg-smart-ar { background-color: #f0fdf4; border-radius: 12px; padding: 8px; border: 1px dashed #22c55e; }
    .bg-smart-asset { background-color: #fffbeb; border-radius: 12px; padding: 8px; border: 1px dashed #f59e0b; }
    .form-control:read-only, .form-select:disabled { background-color: #f8fafc !important; opacity: 1; cursor: not-allowed; }
    
    .dropdown-chevron { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 12px; z-index: 10; }
    .remove-row-btn { transition: 0.2s; }
    .remove-row-btn:hover { transform: scale(1.1); color: #dc2626 !important; }
</style>

<!-- MODAL TRANSAKSI KAS -->
<div class="modal fade" id="modalTrx" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg custom-modal-width modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <div class="modal-header text-white p-3 border-0" id="modalHeaderBg">
                <h5 class="modal-title fw-bold text-white text-center" id="modalTitle">Form Transaksi Kas</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <form action="accounting_action.php" method="POST" id="formTrx" onsubmit="return validateBeforeSubmit(event)">
                <!-- 🚀 INJEKSI TOKEN ANTI-DOUBLE SUBMIT -->
                <input type="hidden" name="idempotency_key" id="inpIdempotentKey" value="<?= $idempotent_key ?>">

                <input type="hidden" name="action" id="inpAction" value="save_cash_trx">
                <input type="hidden" name="id" id="inpId" value="">
                <input type="hidden" name="type" id="inpType" value="">
                <input type="hidden" name="is_duplicate" id="inpIsDuplicate" value="0">
                <input type="hidden" name="trigger_new" id="inpTriggerNew" value="0"> 
                <input type="hidden" name="return_page" id="inpReturnPage" value="">

                <div class="modal-body p-4 bg-light text-dark">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1 uppercase text-dark" style="font-size: 10px;">Tanggal Transaksi</label>
                            <input type="date" name="tgl_jurnal" id="inpDate" class="form-control form-control-sm rounded-pill border shadow-sm px-3 text-dark fw-bold" required>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1 uppercase text-dark" style="font-size: 10px;">No. Referensi</label>
                            <input type="text" name="no_jurnal" id="inpRef" class="form-control form-control-sm rounded-pill border-0 shadow-sm px-3 bg-white fw-bold text-dark" readonly placeholder="Auto Generated">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1 uppercase text-dark" id="lblPihakUtama" style="font-size: 10px;">Pihak / Relasi</label>
                            <div class="position-relative coa-suggest-container">
                                <div class="input-group input-group-sm shadow-sm rounded-pill overflow-hidden border bg-white position-relative">
                                    <select id="jenisPihak" class="form-select border-0 bg-transparent fw-bold text-dark" style="max-width: 115px; font-size: 11px; padding-left: 15px; z-index: 2;" onchange="togglePihakInput()">
                                        <option value="lain">Pihak Lain</option>
                                        <option value="mahasiswa">Mahasiswa</option>
                                        <option value="pegawai">Pegawai</option>
                                    </select>
                                    <input type="text" name="pihak_nama" id="inpPihak" class="form-control border-0 px-2 text-dark bg-transparent fw-bold" style="font-size: 12px; z-index: 1;" 
                                           placeholder="Ketik manual..." autocomplete="off"
                                           onfocus="triggerPihakCombo()" onclick="triggerPihakCombo()" onkeyup="filterPihakCombo()" onblur="hideSuggestions('pihakSugBox')">
                                    <i class="fas fa-chevron-down dropdown-chevron" id="pihakChevron" style="display:none;"></i>
                                </div>
                                <div id="pihakSugBox" class="omni-suggest-box text-dark"></div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm rounded-4 mb-4 text-dark">
                        <div class="card-body p-3 coa-suggest-container text-dark bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-4">
                            <label class="small fw-bold text-primary mb-2 uppercase d-block" id="lblMainAccount" style="font-size: 10px;"><i class="fas fa-university me-1"></i> Pilih Akun Kas/Bank Utama</label>
                            <div class="input-group shadow-sm rounded-pill overflow-hidden border position-relative bg-white">
                                <span class="input-group-text border-0 bg-light px-3"><i class="fas fa-university text-muted"></i></span>
                                <input type="text" id="inpMainAccSearch" class="form-control border-0 bg-white fw-bold text-dark px-2 pe-4" 
                                       placeholder="Pilih atau ketik nama akun..." autocomplete="off"
                                       onclick="openKasDropdown(this)" onkeyup="filterKasDropdown(this)" onblur="hideSuggestions('res_main_acc')">
                                <i class="fas fa-chevron-down dropdown-chevron"></i>
                            </div>
                            <input type="hidden" name="akun_utama" id="inpMainAcc" required>
                            <div id="res_main_acc" class="coa-suggestions text-dark"></div>
                        </div>
                    </div>

                    <div id="areaTransfer" class="d-none mb-4 text-dark">
                        <div class="card border-0 shadow-sm rounded-4 border-start border-primary border-4 text-dark bg-light">
                            <div class="card-body p-3 text-dark">
                                <label class="small fw-bold text-primary mb-2 uppercase d-block" style="font-size: 10px;">Tujuan Transfer</label>
                                <select name="akun_tujuan" id="inpDestAcc" class="form-select form-select-sm border shadow-sm rounded-3 fw-bold px-3 text-dark"></select>
                                <div class="mt-3">
                                    <label class="small fw-bold text-muted mb-1" style="font-size: 10px;">Nominal Transfer</label>
                                    <div class="input-group shadow-sm rounded-3 overflow-hidden border">
                                        <span class="input-group-text border-0 bg-white ps-3 fw-bold text-dark">Rp</span>
                                        <input type="text" name="nominal_transfer" id="inpAmountSingle" class="form-control border-0 bg-white fw-bold text-primary fs-5 text-end pe-4" onkeyup="fmtRp(this); calcTotalTrx();">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="areaDetail" class="text-dark">
                        <div class="d-flex justify-content-between align-items-center mb-2 text-dark">
                            <label class="small fw-bold text-muted uppercase text-dark" id="lblDetail" style="font-size: 10px;">Rincian Akun Lawan</label>
                            <?php if((defined('RBAC_ADD') && RBAC_ADD) || (defined('RBAC_EDIT') && RBAC_EDIT)): ?>
                            <button type="button" id="btnAddItemRow" class="btn btn-sm btn-primary rounded-pill fw-bold px-3 shadow-sm" style="font-size: 10px;" onclick="addRow()"><i class="fas fa-plus me-1"></i> Tambah Item</button>
                            <?php endif; ?>
                        </div>
                        <div class="border rounded-4 bg-light p-2 mb-2">
                            <div id="containerRows"></div>
                        </div>
                    </div>

                    <div class="row mt-1 text-dark">
                        <div class="col-md-12">
                            <label class="small fw-bold text-muted mb-1 uppercase text-dark" style="font-size: 10px;">Deskripsi / Keterangan Transaksi</label>
                            <textarea name="keterangan" id="inpDesc" class="form-control form-control-sm border shadow-sm rounded-3 px-3 py-2 text-dark fw-bold" rows="2" placeholder="Memo transaksi..." required></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 p-3 bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="bg-primary rounded-pill px-4 py-2 d-flex align-items-center gap-3 shadow-sm">
                        <span class="fw-bold uppercase small text-white" style="font-size:10px;">Total Nominal</span>
                        <h5 class="fw-bold mb-0 text-white" id="vTotalDisplay">Rp 0</h5>
                        <input type="hidden" name="grand_total" id="inpGrandTotal" value="0">
                    </div>
                    
                    <div id="actionButtons" class="d-flex gap-2 ms-auto">
                        <button type="button" class="btn btn-white text-muted fw-bold rounded-pill px-4 border shadow-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="button" id="btnDeleteModal" class="btn btn-outline-danger rounded-pill px-3 fw-bold d-none shadow-sm" onclick="handleDeleteFromModal()" title="Hapus Jurnal"><i class="fas fa-trash"></i></button>
                        
                        <?php if((defined('RBAC_ADD') && RBAC_ADD) || (defined('RBAC_EDIT') && RBAC_EDIT)): ?>
                        <button type="button" id="btnSaveNew" class="btn btn-info text-white rounded-pill px-3 fw-bold shadow-sm" onclick="processDuplicateAndNew()">Simpan & Baru</button>
                        <button type="submit" id="btnSaveModal" class="btn btn-primary rounded-pill px-4 fw-bold shadow">SIMPAN TRANSAKSI</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDetailJurnal" tabindex="-1" style="z-index: 10005;" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h6 class="modal-title fw-bold text-white"><i class="fas fa-eye me-2"></i>Rincian Jurnal Transaksi</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 text-dark bg-white" style="max-height: 65vh; overflow-y: auto;">
                <div id="loadingDetail" class="text-center p-5"><div class="spinner-border text-primary"></div><div class="mt-2 small text-muted fw-bold">Memuat Data...</div></div>
                <div id="contentDetail" class="d-none">
                    <div class="p-4 bg-light border-bottom text-dark">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="fw-bold text-primary mb-1" id="viewRef">-</h5>
                                <div class="badge bg-white text-dark border shadow-sm px-3 py-2 fw-bold"><i class="fas fa-calendar-alt me-2 text-muted"></i><span id="viewDate">-</span></div>
                            </div>
                            <div class="text-end">
                                <div class="small fw-bold text-muted uppercase" style="font-size:9px;">Pihak Terkait</div>
                                <div class="fw-bold text-dark" id="viewPihak">-</div>
                            </div>
                        </div>
                        <div class="small text-dark fw-bold mb-3 p-3 bg-white border rounded-3" id="viewDesc">-</div>
                        
                        <div class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2 w-100 text-start" id="viewCreator">
                            <i class="fas fa-user-edit me-2"></i> Dientri oleh: -
                        </div>
                    </div>
                    
                    <div class="table-responsive p-4">
                        <table class="table table-bordered align-middle mb-0 text-dark text-center" style="font-size:0.85rem;">
                            <thead class="table-light text-muted uppercase small fw-bold">
                                <tr>
                                    <th class="text-start ps-3">Akun COA</th>
                                    <th class="text-end" width="150">Debit (Rp)</th>
                                    <th class="text-end pe-3" width="150">Kredit (Rp)</th>
                                </tr>
                            </thead>
                            <tbody id="viewTableBody"></tbody>
                            <tfoot class="fw-bold bg-dark text-white">
                                <tr>
                                    <td class="text-end pe-3 py-3 uppercase">TOTAL BALANCE</td>
                                    <td class="text-end text-success py-3" id="viewTotalD">0</td>
                                    <td class="text-end text-danger pe-3 py-3" id="viewTotalK">0</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-3 border-0 bg-light flex-nowrap" id="detailModalFooter">
                <button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold w-100 shadow-sm" data-bs-dismiss="modal">Tutup Detail</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL RIWAYAT AKTIVITAS & UNDO -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalRiwayat" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-light border-bottom p-4 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-dark mb-0">Riwayat Aktivitas: <span id="riwayatModule" class="text-primary"></span></h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="bg-white border-bottom p-3">
                <div class="row g-2 align-items-center">
                    <div class="col-md-auto">
                        <span class="small fw-bold text-muted text-uppercase">Filter Tanggal:</span>
                    </div>
                    <div class="col-md-3">
                        <input type="date" id="riwayatStartDate" class="form-control form-control-sm border shadow-sm rounded-pill px-3 text-dark fw-bold">
                    </div>
                    <div class="col-md-auto text-center"><span class="small fw-bold text-muted">s/d</span></div>
                    <div class="col-md-3">
                        <input type="date" id="riwayatEndDate" class="form-control form-control-sm border shadow-sm rounded-pill px-3 text-dark fw-bold">
                    </div>
                    <div class="col-md-auto">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="filterRiwayatData()"><i class="fas fa-filter me-1"></i>Terapkan</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold ms-1" onclick="resetRiwayatFilter()"><i class="fas fa-sync-alt"></i></button>
                    </div>
                </div>
            </div>

            <div class="modal-body p-0 bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                        <thead class="table-light text-muted small fw-bold text-center">
                            <tr>
                                <th width="90" class="text-center"><i class="fas fa-search"></i></th>
                                <th class="ps-3 text-center" width="160">Stempel Waktu</th>
                                <th width="150" class="text-start">Pengguna</th>
                                <th class="text-start">Deskripsi</th>
                                <th class="text-center" width="120">Keterangan</th>
                                <th class="text-center pe-4" width="120">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody id="riwayatBody">
                            <tr><td colspan="6" class="text-center py-5"><i class="fas fa-spinner fa-spin me-2"></i> Memuat...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-top p-3 text-center d-block">
                <button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold shadow-sm" data-bs-dismiss="modal">TUTUP RIWAYAT</button>
            </div>
        </div>
    </div>
</div>

<script>
const coaListMasterShared = <?= json_encode(is_array($coa_beban_pend) ? $coa_beban_pend : []) ?> || [];
const kasBankListShared = <?= json_encode(is_array($kas_bank_shared) ? $kas_bank_shared : []) ?> || [];
const cacheMhs = <?= json_encode(is_array($mhs_list) ? $mhs_list : []) ?> || [];
const cacheAst = <?= json_encode(is_array($asset_list) ? $asset_list : []) ?> || [];
const cachePgw = <?= json_encode(is_array($pgw_list) ? $pgw_list : []) ?> || [];

let trxCtr = 0;
let pendingAjaxCalls = 0;
let isSubmittingTrx = false; 

function formatRpJS(val) {
    if(!val) return '0';
    let clean = val.toString().replace(/[^0-9]/g, '');
    return clean ? new Intl.NumberFormat('id-ID').format(clean) : '0';
}
function cleanRpJS(val) { return parseFloat(val.toString().replace(/[^0-9]/g, '')) || 0; }
function fmtRp(el) { el.value = formatRpJS(el.value); }
function prs(s) { return parseFloat(s.toString().replace(/\./g, '')) || 0; }

function calcTotalTrx() { 
    let t = 0; 
    if(document.getElementById('inpType').value === 'transfer') { 
        t = prs(document.getElementById('inpAmountSingle').value); 
    } else { 
        document.querySelectorAll('.inp-amt').forEach(i => t += prs(i.value)); 
    } 
    document.getElementById('inpGrandTotal').value = t;
    if(document.getElementById('vTotalDisplay')) document.getElementById('vTotalDisplay').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(t); 
}

function checkPendingAjax() {
    const btn = document.getElementById('btnSaveModal');
    const btnNew = document.getElementById('btnSaveNew');
    if (pendingAjaxCalls > 0) {
        if(btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memuat...'; }
        if(btnNew) { btnNew.disabled = true; }
    } else {
        if(btn) { btn.disabled = false; btn.innerHTML = 'SIMPAN TRANSAKSI'; }
        if(btnNew) { btnNew.disabled = false; }
    }
}

function hideSuggestions(id) {
    setTimeout(() => { const el = document.getElementById(id); if(el) el.style.display = 'none'; }, 250); 
}

function togglePihakInput() {
    const jp = document.getElementById('jenisPihak').value;
    const inp = document.getElementById('inpPihak');
    const chevron = document.getElementById('pihakChevron');
    const box = document.getElementById('pihakSugBox');
    
    if (jp === 'lain') {
        inp.placeholder = 'Ketik manual...'; inp.readOnly = false;
        if(chevron) chevron.style.display = 'none';
    } else if (jp === 'mahasiswa') {
        inp.placeholder = 'Pilih/Ketik Mhs...'; inp.readOnly = false;
        if(chevron) chevron.style.display = 'block';
    } else if (jp === 'pegawai') {
        inp.placeholder = 'Pilih/Ketik Pgw...'; inp.readOnly = false;
        if(chevron) chevron.style.display = 'block';
    }
    inp.value = '';
    if(box) box.style.display = 'none';
}

function toggleComboPihakLocal(jp, inp, chevron, box) {
    if(jp === 'lain') return;
    inp.select();
    if(jp === 'mahasiswa') renderPihakBox(cacheMhs.slice(0, 50), 'mahasiswa');
    else if(jp === 'pegawai') renderPihakBox(cachePgw.slice(0, 50), 'pegawai');
}

function triggerPihakCombo() {
    const jp = document.getElementById('jenisPihak').value;
    const inp = document.getElementById('inpPihak');
    const chevron = document.getElementById('pihakChevron');
    const box = document.getElementById('pihakSugBox');
    toggleComboPihakLocal(jp, inp, chevron, box);
}

function filterPihakCombo() {
    const jp = document.getElementById('jenisPihak').value;
    const val = document.getElementById('inpPihak').value.toLowerCase();
    if(jp === 'lain') return;

    if(jp === 'mahasiswa') {
        const matches = cacheMhs.filter(m => m.nama.toLowerCase().includes(val) || (m.nim && m.nim.toLowerCase().includes(val)));
        renderPihakBox(matches.slice(0, 50), 'mahasiswa');
    } else if(jp === 'pegawai') {
        const matches = cachePgw.filter(p => p.nama.toLowerCase().includes(val) || (p.nip && p.nip.toLowerCase().includes(val)));
        renderPihakBox(matches.slice(0, 50), 'pegawai');
    }
}

function renderPihakBox(list, type) {
    const box = document.getElementById('pihakSugBox');
    const inp = document.getElementById('inpPihak');
    box.innerHTML = '';
    if(list.length > 0) {
        list.forEach(item => {
            const div = document.createElement('div'); div.className = 'omni-suggest-item';
            if(type === 'mahasiswa') {
                div.innerHTML = `<strong>${item.nama}</strong><br><small>${item.nim || '-'}</small>`;
                div.onmousedown = (e) => { e.preventDefault(); inp.value = item.nama; box.style.display='none'; };
            } else {
                div.innerHTML = `<strong>${item.nama}</strong><br><small>${item.nip || '-'}</small>`;
                div.onmousedown = (e) => { e.preventDefault(); inp.value = item.nama; box.style.display='none'; };
            }
            box.appendChild(div);
        });
    } else { box.innerHTML = '<div class="p-2 text-center text-muted small">Tidak ditemukan</div>'; }
    box.style.display = 'block';
}

function selectMainAcc(kode, nama) {
    document.getElementById('inpMainAcc').value = kode || '';
    document.getElementById('inpMainAccSearch').value = kode ? `${kode} - ${nama}` : '';
    document.getElementById('res_main_acc').style.display = 'none';
}

function openKasDropdown(el) { el.select(); renderKasBox(kasBankListShared); }
function filterKasDropdown(el) {
    const val = el.value.toLowerCase(); const matches = kasBankListShared.filter(c => c.kode_akun.includes(val) || c.nama_akun.toLowerCase().includes(val));
    renderKasBox(matches);
}

function renderKasBox(list) {
    const resBox = document.getElementById('res_main_acc'); resBox.innerHTML = '';
    if(list.length > 0) {
        list.forEach(c => {
            const div = document.createElement('div'); div.className = 'omni-suggest-item'; div.innerHTML = `<code>${c.kode_akun}</code> ${c.nama_akun}`;
            div.onmousedown = function(e) { e.preventDefault(); selectMainAcc(c.kode_akun, c.nama_akun); }; resBox.appendChild(div);
        });
    } else { resBox.innerHTML = '<div class="p-3 text-center text-muted small">Akun tidak ditemukan</div>'; }
    resBox.style.display = 'block';
}

function openTrxModal(type, id = null, isDuplicate = false) {
    isSubmittingTrx = false;
    const btnSm = document.getElementById('btnSaveModal');
    if(btnSm) { btnSm.disabled = false; btnSm.innerHTML = 'SIMPAN TRANSAKSI'; }
    const btnSn = document.getElementById('btnSaveNew');
    if(btnSn) { btnSn.disabled = false; }

    const modalEl = document.getElementById('modalTrx');
    if (modalEl.parentNode !== document.body) { document.body.appendChild(modalEl); }
    
    document.getElementById('formTrx').reset();
    document.getElementById('containerRows').innerHTML = '';
    document.getElementById('vTotalDisplay').innerText = 'Rp 0';
    document.getElementById('inpId').value = id || '';
    document.getElementById('inpType').value = type;
    document.getElementById('inpIsDuplicate').value = isDuplicate ? '1' : '0';
    document.getElementById('inpTriggerNew').value = '0'; 
    
    document.getElementById('jenisPihak').value = 'lain';
    togglePihakInput();
    
    const urlParams = new URLSearchParams(window.location.search);
    let retPage = urlParams.get('page') || 'transaksi_kas';
    ['tab', 'view', 'kode', 'bulan', 'tahun', 'status'].forEach(param => {
        if(urlParams.has(param)) retPage += `&${param}=${urlParams.get(param)}`;
    });
    document.getElementById('inpReturnPage').value = retPage;
    
    const header = document.getElementById('modalHeaderBg');
    const title = document.getElementById('modalTitle');
    const areaDetail = document.getElementById('areaDetail');
    const areaTransfer = document.getElementById('areaTransfer');
    const btnDel = document.getElementById('btnDeleteModal');

    areaTransfer.classList.add('d-none');
    areaDetail.classList.remove('d-none');

    let titlePrefix = isDuplicate ? 'Duplikasi ' : (id ? 'Ubah ' : 'Entri ');

    if(type === 'income') {
        header.className = 'modal-header bg-success text-white p-3 border-0';
        title.innerHTML = '<i class="fas fa-hand-holding-usd me-2"></i>' + titlePrefix + 'Penerimaan Kas';
        document.getElementById('lblMainAccount').innerHTML = '<i class="fas fa-university me-1"></i> Masuk Ke Akun Kas/Bank (Debet)';
        document.getElementById('lblPihakUtama').innerText = 'Diterima Dari (Penyetor)';
    } else if(type === 'expense') {
        header.className = 'modal-header bg-danger text-white p-3 border-0';
        title.innerHTML = '<i class="fas fa-file-invoice-dollar me-2"></i>' + titlePrefix + 'Pengeluaran Kas';
        document.getElementById('lblMainAccount').innerHTML = '<i class="fas fa-university me-1"></i> Keluar Dari Akun Kas/Bank (Kredit)';
        document.getElementById('lblPihakUtama').innerText = 'Dibayarkan Kepada (Penerima)';
    } else {
        header.className = 'modal-header bg-primary text-white p-3 border-0';
        title.innerHTML = '<i class="fas fa-exchange-alt me-2"></i>' + titlePrefix + 'Transfer Kas';
        document.getElementById('lblPihakUtama').innerText = 'Pelaksana Transfer';
        areaDetail.classList.add('d-none');
        areaTransfer.classList.remove('d-none');
    }

    populateDestKasBank(); 

    if(id && !isDuplicate) btnDel.classList.remove('d-none'); else btnDel.classList.add('d-none');

    if(id) {
        document.getElementById('inpRef').placeholder = 'Memuat data...';
        pendingAjaxCalls++; checkPendingAjax();
        fetch('accounting_action.php?action=get_trx_detail_full&id=' + id).then(r => r.json()).then(d => {
            if(d.error) { pendingAjaxCalls--; checkPendingAjax(); return; }
            const h = d.header;
            document.getElementById('inpDate').value = isDuplicate ? '<?= date('Y-m-d') ?>' : h.tgl_jurnal;
            document.getElementById('inpRef').value = isDuplicate ? 'Auto Generated' : h.no_jurnal;
            document.getElementById('inpDesc').value = h.keterangan || '';
            
            if(h.pihak_nama) {
                document.getElementById('inpPihak').value = h.pihak_nama;
                if(cacheMhs.find(m => m.nama === h.pihak_nama)) { document.getElementById('jenisPihak').value = 'mahasiswa'; togglePihakInput(); }
                else if(cachePgw.find(p => p.nama === h.pihak_nama)) { document.getElementById('jenisPihak').value = 'pegawai'; togglePihakInput(); }
            }
            
            selectMainAcc(h.akun_utama_kode, h.nama_akun_utama);

            if(type === 'transfer') {
                document.getElementById('inpDestAcc').value = h.akun_tujuan_kode || '';
                document.getElementById('inpAmountSingle').value = new Intl.NumberFormat('id-ID').format(Math.floor(parseFloat(h.total_debet || 0)));
            } else {
                d.details.forEach(item => {
                    const nominal = Math.max(parseFloat(item.debit || 0), parseFloat(item.kredit || 0));
                    addItemRow(item.kode_akun, item.nama_akun, Math.floor(nominal), item.mahasiswa_id, item.aset_id, item.tagihan_id_ref, item.keterangan); 
                });
            }
            calcTotalTrx();
            pendingAjaxCalls--; checkPendingAjax();
        }).catch(err => { pendingAjaxCalls--; checkPendingAjax(); });
    } else {
        document.getElementById('inpDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('inpRef').placeholder = 'Auto Generated';
        if(type !== 'transfer') addRow();
    }
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function openCoaDropdown(el, id) { el.select(); renderCoaBox(coaListMasterShared, id); }
function filterCoaDropdown(el, id) {
    const val = el.value.toLowerCase(); const matches = coaListMasterShared.filter(c => c.kode_akun.includes(val) || c.nama_akun.toLowerCase().includes(val));
    renderCoaBox(matches, id);
}

function renderCoaBox(list, id) {
    const resBox = document.getElementById('res_' + id); resBox.innerHTML = '';
    if(list.length > 0) {
        list.forEach(c => {
            const div = document.createElement('div'); div.className = 'omni-suggest-item'; div.innerHTML = `<code>${c.kode_akun}</code> ${c.nama_akun}`;
            div.onmousedown = function(e) { e.preventDefault(); selectCoaLawan(id, c.kode_akun, c.nama_akun); }; resBox.appendChild(div);
        });
    } else { resBox.innerHTML = '<div class="p-3 text-center text-muted small">Akun tidak ditemukan</div>'; }
    resBox.style.display = 'block';
}

function addItemRow(kode, nama, nominal, mhs_id = null, aset_id = null, tagihan_id = null, item_desc = '') {
    trxCtr++; const rowId = trxCtr;
    const safeKode = kode || ''; const safeNama = nama || '';
    const searchVal = safeKode ? `${safeKode} - ${safeNama}` : '';
    const nomVal = nominal ? new Intl.NumberFormat('id-ID').format(nominal) : '0';
    let removeBtnHtml = '';
    <?php if((defined('RBAC_ADD') && RBAC_ADD) || (defined('RBAC_EDIT') && RBAC_EDIT)): ?>
    removeBtnHtml = `<button type="button" class="btn btn-link text-danger p-0 mb-1 remove-row-btn" onclick="removeTrxRow(${rowId})"><i class="fas fa-times-circle fa-lg"></i></button>`;
    <?php endif; ?>

    const defaultSmartLink = `
        <label class="extra-small fw-bold text-muted" style="font-size:9px;">Memo Item</label>
        <input type="text" name="item_desc[]" class="form-control form-control-sm border shadow-sm text-dark" placeholder="Rincian..." value="${item_desc || ''}">
        <input type="hidden" name="mahasiswa_id[]" value="${mhs_id || ''}">
        <input type="hidden" name="tagihan_id[]" value="${tagihan_id || ''}">
        <input type="hidden" name="aset_id[]" value="${aset_id || ''}">
        <input type="hidden" name="asset_id[]" value="${aset_id || ''}">
    `;

    const html = `
    <div class="row g-2 mb-3 align-items-end border-bottom pb-3 text-dark text-start" id="row_${rowId}">
        <div class="col-md-4 coa-suggest-container">
            <label class="extra-small fw-bold text-muted uppercase text-start d-block mb-1" style="font-size:9px;">Akun Lawan</label>
            <div class="position-relative coa-suggest-container">
                <div class="input-group shadow-sm rounded-3 overflow-hidden border bg-white position-relative">
                    <input type="text" id="inpCoaSearch_${rowId}" class="form-control border-0 bg-white fw-bold text-dark px-2 pe-4" 
                           value="${searchVal}" placeholder="Pilih/ketik akun..." autocomplete="off"
                           onclick="openCoaDropdown(this, '${rowId}')" onkeyup="filterCoaDropdown(this, '${rowId}')" onblur="hideSuggestions('res_${rowId}')">
                    <i class="fas fa-chevron-down dropdown-chevron"></i>
                </div>
                <input type="hidden" name="lawan_akun[]" value="${safeKode}" id="coa_val_${rowId}" required>
                <div id="res_${rowId}" class="omni-suggest-box text-dark"></div>
            </div>
        </div>
        <div class="col-md-5" id="smart_link_${rowId}">${defaultSmartLink}</div>
        <div class="col-md-2 text-center">
            <label class="extra-small fw-bold text-muted uppercase text-center d-block mb-1" style="font-size:9px;">Nominal (Rp)</label>
            <input type="text" name="nominal[]" class="form-control form-control-sm text-end fw-bold shadow-sm border rounded-3 text-primary inp-amt" 
                   onkeyup="fmtRp(this); calcTotalTrx();" value="${nomVal}" required>
        </div>
        <div class="col-md-1 text-center">${removeBtnHtml}</div>
    </div>`;
    document.getElementById('containerRows').insertAdjacentHTML('beforeend', html);
    
    if(kode) selectCoaLawan(rowId, safeKode, safeNama, mhs_id, aset_id, tagihan_id, item_desc);
}

function addRow() { addItemRow('', '', 0); }
function removeTrxRow(id) { document.getElementById(`row_${id}`).remove(); calcTotalTrx(); }

function selectCoaLawan(rowId, kode, nama, mhs_id = null, aset_id = null, tagihan_id = null, item_desc = '') {
    const safeKode = kode || ''; const safeNama = nama || '';
    document.getElementById(`coa_val_${rowId}`).value = safeKode;
    const searchInput = document.getElementById(`inpCoaSearch_${rowId}`);
    if(searchInput) searchInput.value = safeKode ? `${safeKode} - ${safeNama}` : '';
    document.getElementById(`res_${rowId}`).style.display = 'none';
    
    const smartContainer = document.getElementById(`smart_link_${rowId}`); if(!smartContainer) return;
    smartContainer.innerHTML = ''; smartContainer.className = 'col-md-5';
    const lowerNama = safeNama.toLowerCase();

    if(safeKode.startsWith('1-12') || safeKode.startsWith('1.12') || lowerNama.includes('piutang') || lowerNama.includes('pendidikan') || lowerNama.includes('spp') || lowerNama.includes('biaya kuliah')) {
        smartContainer.className = 'col-md-5 bg-smart-ar rounded-3';
        let defMhsName = ''; if(mhs_id) { const m = cacheMhs.find(x => x.id == mhs_id); if(m) defMhsName = m.nama; }

        smartContainer.innerHTML = `
            <div class="row g-1 text-dark">
                <div class="col-6 position-relative coa-suggest-container">
                    <label class="extra-small fw-bold text-success" style="font-size:9px;">Mahasiswa</label>
                    <div class="input-group shadow-sm rounded-3 overflow-hidden border bg-white position-relative">
                        <input type="text" id="inpMhs_${rowId}" class="form-control border-0 bg-white fw-bold text-dark px-2 pe-4" 
                               placeholder="Pilih/ketik mhs..." autocomplete="off" value="${defMhsName}"
                               onclick="triggerCombo('Mhs', '${rowId}')" onkeyup="filterCombo('Mhs', '${rowId}')" onblur="hideSuggestions('sugMhs_${rowId}')">
                        <i class="fas fa-chevron-down dropdown-chevron"></i>
                    </div>
                    <div id="sugMhs_${rowId}" class="omni-suggest-box text-dark"></div>
                </div>
                <div class="col-6">
                    <label class="extra-small fw-bold text-success" style="font-size:9px;">Tagihan (Invoice)</label>
                    <select name="tagihan_id[]" id="tagihan_sel_${rowId}" class="form-select form-select-sm border shadow-sm text-dark" required>
                        <option value="">-- Pilih Mhs Dulu --</option>
                    </select>
                </div>
            </div>
            <input type="hidden" name="mahasiswa_id[]" id="hidMhs_${rowId}" value="${mhs_id || ''}">
            <input type="hidden" name="tagihan_id[]" value="">
            <input type="hidden" name="aset_id[]" value="">
            <input type="hidden" name="asset_id[]" value="">
            <input type="hidden" name="item_desc[]" value="">
        `;
        if(mhs_id) loadTagihanMhs(mhs_id, rowId, tagihan_id);

    } else if(safeKode.startsWith('1-21') || safeKode.startsWith('1-3') || lowerNama.includes('aset') || lowerNama.includes('inventaris')) {
        smartContainer.className = 'col-md-5 bg-smart-asset rounded-3';
        let defAssetName = ''; if(aset_id) { const a = cacheAst.find(x => x.id == aset_id); if(a) defAssetName = a.nama; }

        smartContainer.innerHTML = `
            <div class="position-relative coa-suggest-container">
                <label class="extra-small fw-bold text-warning" style="font-size:9px;">Aset / Inventaris</label>
                <div class="input-group shadow-sm rounded-3 overflow-hidden border bg-white position-relative">
                    <input type="text" id="inpAst_${rowId}" class="form-control border-0 bg-white fw-bold text-dark px-2 pe-4" 
                           placeholder="Pilih/ketik aset..." autocomplete="off" value="${defAssetName}"
                           onclick="triggerCombo('Ast', '${rowId}')" onkeyup="filterCombo('Ast', '${rowId}')" onblur="hideSuggestions('sugAst_${rowId}')">
                    <i class="fas fa-chevron-down dropdown-chevron"></i>
                </div>
                <div id="sugAst_${rowId}" class="omni-suggest-box text-dark"></div>
            </div>
            <input type="hidden" name="mahasiswa_id[]" value="">
            <input type="hidden" name="tagihan_id[]" value="">
            <input type="hidden" name="aset_id[]" id="hidAst_${rowId}" value="${aset_id || ''}">
            <input type="hidden" name="asset_id[]" id="hidAstEn_${rowId}" value="${aset_id || ''}">
            <input type="hidden" name="item_desc[]" value="">
        `;
    } else {
        smartContainer.innerHTML = `
            <label class="extra-small fw-bold text-muted" style="font-size:9px;">Memo Item</label>
            <input type="text" name="item_desc[]" class="form-control form-control-sm border shadow-sm text-dark" placeholder="Rincian..." value="${item_desc || ''}">
            <input type="hidden" name="mahasiswa_id[]" value="">
            <input type="hidden" name="tagihan_id[]" value="">
            <input type="hidden" name="aset_id[]" value="">
            <input type="hidden" name="asset_id[]" value="">
        `;
    }
}

function triggerCombo(type, rowId) {
    document.getElementById(`inp${type}_${rowId}`).select();
    if(type === 'Mhs') renderComboMhs(cacheMhs.slice(0, 50), rowId);
    else if(type === 'Ast') renderComboAst(cacheAst.slice(0, 50), rowId);
}

function filterCombo(type, rowId) {
    const val = document.getElementById(`inp${type}_${rowId}`).value.toLowerCase();
    if(type === 'Mhs') {
        const matches = cacheMhs.filter(m => m.nama.toLowerCase().includes(val) || (m.nim && m.nim.toLowerCase().includes(val)));
        renderComboMhs(matches.slice(0, 50), rowId);
    } else if(type === 'Ast') {
        const matches = cacheAst.filter(a => a.nama.toLowerCase().includes(val) || (a.kode && a.kode.toLowerCase().includes(val)));
        renderComboAst(matches.slice(0, 50), rowId);
    }
}

function renderComboMhs(list, rowId) {
    const box = document.getElementById(`sugMhs_${rowId}`);
    const hid = document.getElementById(`hidMhs_${rowId}`);
    const inp = document.getElementById(`inpMhs_${rowId}`);
    box.innerHTML = '';
    if(list.length > 0) {
        list.forEach(m => {
            const div = document.createElement('div'); div.className = 'omni-suggest-item';
            div.innerHTML = `<strong>${m.nama}</strong><br><small>${m.nim}</small>`;
            div.onmousedown = (e) => { 
                e.preventDefault(); inp.value = m.nama; hid.value = m.id; box.style.display='none'; 
                loadTagihanMhs(m.id, rowId, null); 
            };
            box.appendChild(div);
        });
    } else { box.innerHTML = '<div class="p-2 text-center text-muted small">Tidak ditemukan</div>'; }
    box.style.display = 'block';
}

function renderComboAst(list, rowId) {
    const box = document.getElementById(`sugAst_${rowId}`);
    const hid = document.getElementById(`hidAst_${rowId}`);
    const hidEn = document.getElementById(`hidAstEn_${rowId}`);
    const inp = document.getElementById(`inpAst_${rowId}`);
    box.innerHTML = '';
    if(list.length > 0) {
        list.forEach(a => {
            const div = document.createElement('div'); div.className = 'omni-suggest-item';
            div.innerHTML = `<strong>${a.nama}</strong><br><small>${a.kode}</small>`;
            div.onmousedown = (e) => { 
                e.preventDefault(); inp.value = a.nama; hid.value = a.id; 
                if(hidEn) hidEn.value = a.id; 
                box.style.display='none'; 
            };
            box.appendChild(div);
        });
    } else { box.innerHTML = '<div class="p-2 text-center text-muted small">Tidak ditemukan</div>'; }
    box.style.display = 'block';
}

function loadTagihanMhs(mhsId, rowId, selectedTag = null) {
    const tagSel = document.getElementById('tagihan_sel_' + rowId); if(!tagSel) return;
    tagSel.innerHTML = '<option value="">-- Memuat... --</option>';
    if(!mhsId) { tagSel.innerHTML = '<option value="">-- Pilih Mhs Dulu --</option>'; return; }
    
    pendingAjaxCalls++; checkPendingAjax();
    fetch('ajax_student_bills.php?action=get_bills&mhs_id=' + mhsId).then(r => r.json()).then(data => {
        tagSel.innerHTML = '<option value="">-- Pilih --</option>'; let hasTag = false;
        data.forEach(t => {
            const sisa = parseFloat(t.nominal) - parseFloat(t.terbayar);
            if(sisa > 0 || selectedTag == t.id) {
                const opt = new Option(`${t.nama_tagihan} (Rp ${formatRpJS(sisa)})`, t.id);
                if(selectedTag == t.id) { opt.selected = true; hasTag = true; }
                tagSel.add(opt);
            }
        });
        if(selectedTag && !hasTag) { const opt = new Option('Tagihan Selesai (ID:'+selectedTag+')', selectedTag); opt.selected = true; tagSel.add(opt); }
        pendingAjaxCalls--; checkPendingAjax();
    }).catch(e => {
        fetch('ajax_cash.php?action=get_mhs_tagihan&mhs_id=' + mhsId).then(r => r.json()).then(data => {
            tagSel.innerHTML = '<option value="">-- Pilih --</option>'; let hasTag2 = false;
            data.forEach(t => {
                const opt = new Option(`${t.nama_tagihan} (Rp ${formatRpJS(t.sisa || 0)})`, t.id);
                if(selectedTag == t.id) { opt.selected = true; hasTag2 = true; } tagSel.add(opt);
            });
            if (selectedTag && !hasTag2) { const opt = new Option('Tagihan Selesai (ID:'+selectedTag+')', selectedTag); opt.selected = true; tagSel.add(opt); }
            pendingAjaxCalls--; checkPendingAjax();
        }).catch(ex => {
            tagSel.innerHTML = '<option value="">-- Bebas Tagihan --</option>';
            pendingAjaxCalls--; checkPendingAjax();
        });
    });
}

function validateBeforeSubmit(e) {
    if (pendingAjaxCalls > 0) { alert("Sistem masih memuat data sinkronisasi!"); if(e) e.preventDefault(); return false; }
    
    if (isSubmittingTrx) { 
        if(e) e.preventDefault(); 
        return false; 
    }
    
    const type = document.getElementById('inpType').value;
    const mainAcc = document.getElementById('inpMainAcc').value;
    
    if(mainAcc.trim() === '') { 
        alert(type === 'transfer' ? "Akun Sumber Kas wajib dipilih." : "Akun Kas Utama wajib dipilih."); 
        if(e) e.preventDefault(); return false; 
    }
    
    if(type === 'transfer') {
        const destAcc = document.getElementById('inpDestAcc').value;
        if(destAcc.trim() === '') { alert("Akun Tujuan Transfer Kas wajib dipilih."); if(e) e.preventDefault(); return false; }
        if(mainAcc === destAcc) { alert("Akun Sumber dan Akun Tujuan tidak boleh sama."); if(e) e.preventDefault(); return false; }
        
        let nomTrf = cleanRpJS(document.getElementById('inpAmountSingle').value);
        if(nomTrf <= 0) { alert("Nominal transfer harus lebih besar dari 0!"); if(e) e.preventDefault(); return false; }
    } else {
        let t = cleanRpJS(document.getElementById('inpGrandTotal').value);
        if(t <= 0) { alert("Total transaksi harus lebih besar dari 0!"); if(e) e.preventDefault(); return false; }
        
        document.querySelectorAll('.inp-amt').forEach(i => i.value = cleanRpJS(i.value));
    }
    
    isSubmittingTrx = true;
    const btnSm = document.getElementById('btnSaveModal');
    const btnSn = document.getElementById('btnSaveNew');
    if (btnSm) {
        btnSm.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>MEMPROSES...';
        btnSm.disabled = true;
    }
    if (btnSn) btnSn.disabled = true;

    return true;
}

// 🚀 FUNGSI PENYIMPANAN YANG SUDAH DILINDUNGI DARI BUG ALERT HTML
function processDuplicateAndNew() {
    if(!validateBeforeSubmit(null)) return; 
    const form = document.getElementById('formTrx');
    const formData = new FormData(form);
    
    const btn = document.getElementById('btnSaveNew');
    const btnSimpan = document.getElementById('btnSaveModal');
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
    btn.disabled = true;

    fetch('accounting_action.php', { method: 'POST', body: formData })
    .then(response => response.text())
    .then(html => {
        // 🛡️ THE SHIELD: Mengecek apakah response adalah form/tabel (Sukses Redirect) 
        // Menggunakan ciri khas div container yang ada di halaman tujuan
        if(html.trim().startsWith("<") && (html.includes("trxTableContainer") || html.includes("<!DOCTYPE html>"))) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newTable = doc.getElementById('trxTableContainer');
            if (newTable && document.getElementById('trxTableContainer')) {
                document.getElementById('trxTableContainer').innerHTML = newTable.innerHTML;
            }

            document.getElementById('inpIdempotentKey').value = Math.random().toString(36).substring(2, 15) + '_' + Date.now();

            document.getElementById('inpId').value = '';
            document.getElementById('inpIsDuplicate').value = '0'; 
            document.getElementById('inpRef').value = 'Auto Generated';
            document.getElementById('inpDate').value = '<?= date('Y-m-d') ?>';
            document.getElementById('containerRows').innerHTML = ''; addRow();
            calcTotalTrx();
            
            const btnDel = document.getElementById('btnDeleteModal');
            if(btnDel) btnDel.classList.add('d-none');

            const modalTitle = document.getElementById('modalTitle');
            const oldTitle = modalTitle.innerHTML;
            modalTitle.innerHTML = `<span class="text-warning"><i class="fas fa-check-double me-1"></i> Tersimpan! Lanjut Entri...</span>`;
            setTimeout(() => { modalTitle.innerHTML = oldTitle; }, 2500);
            
            isSubmittingTrx = false;
            btn.innerHTML = originalText; btn.disabled = false;
            if(btnSimpan) { btnSimpan.innerHTML = 'SIMPAN TRANSAKSI'; btnSimpan.disabled = false; }
            
        } else {
            // Jika response MURNI merupakan pesan Error singkat 
            let cleanMsg = html.replace(/<\/?[^>]+(>|$)/g, "").trim();
            if(cleanMsg.length === 0 || cleanMsg.length > 150) cleanMsg = "Gagal memproses data. Periksa jaringan Anda.";
            alert(cleanMsg); 
            
            isSubmittingTrx = false;
            btn.innerHTML = originalText; btn.disabled = false;
            if(btnSimpan) { btnSimpan.innerHTML = 'SIMPAN TRANSAKSI'; btnSimpan.disabled = false; }
        }
        
        document.querySelectorAll('.inp-amt').forEach(i => { i.value = formatRpJS(i.value); });
        if(document.getElementById('inpAmountSingle')) document.getElementById('inpAmountSingle').value = formatRpJS(document.getElementById('inpAmountSingle').value);
    }).catch(error => {
        alert("Gagal menghubungi server untuk menyimpan data.");
        isSubmittingTrx = false;
        btn.innerHTML = originalText; btn.disabled = false;
        if(btnSimpan) { btnSimpan.innerHTML = 'SIMPAN TRANSAKSI'; btnSimpan.disabled = false; }

        document.querySelectorAll('.inp-amt').forEach(i => { i.value = formatRpJS(i.value); });
        if(document.getElementById('inpAmountSingle')) document.getElementById('inpAmountSingle').value = formatRpJS(document.getElementById('inpAmountSingle').value);
    });
}

function populateDestKasBank() { 
    const dSel = document.getElementById('inpDestAcc'); if(!dSel) return; 
    dSel.innerHTML = '<option value="">-- Pilih Akun Tujuan --</option>'; 
    kasBankListShared.forEach(k => { dSel.add(new Option(`${k.type_name ?? k.nama_akun} - ${k.kode_akun}`, k.kode_akun)); }); 
}

function handleDeleteFromModal() {
    const id = document.getElementById('inpId').value; const retPage = document.getElementById('inpReturnPage').value || 'transaksi_kas';
    if(confirm('HAPUS TRANSAKSI: Data akan hilang permanen dari buku besar. Lanjutkan?')) {
        const form = document.createElement('form'); form.method = 'POST'; form.action = 'accounting_action.php'; form.innerHTML = `<input type="hidden" name="action" value="delete_trx"><input type="hidden" name="id" value="${id}"><input type="hidden" name="return_page" value="${retPage}">`; document.body.appendChild(form); form.submit();
    }
}

function closeAndBackToRiwayat(moduleName) {
    const mDetail = bootstrap.Modal.getInstance(document.getElementById('modalDetailJurnal'));
    if(mDetail) mDetail.hide();
    setTimeout(() => { openRiwayatModal(moduleName, true); }, 400); 
}

let activeRiwayatModule = '';

function openRiwayatModal(module, keepFilter = false) {
    activeRiwayatModule = module;
    document.getElementById('riwayatModule').innerText = module;
    
    if (!keepFilter) {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('riwayatStartDate').value = today;
        document.getElementById('riwayatEndDate').value = today;
    }
    
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRiwayat'));
    m.show(); filterRiwayatData();
}

function filterRiwayatData() {
    const startDate = document.getElementById('riwayatStartDate').value;
    const endDate = document.getElementById('riwayatEndDate').value;
    if (startDate && endDate && startDate > endDate) { alert("Tanggal Awal tidak boleh lebih besar dari Tanggal Akhir."); return; }
    loadRiwayat(activeRiwayatModule, startDate, endDate);
}

function resetRiwayatFilter() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('riwayatStartDate').value = today;
    document.getElementById('riwayatEndDate').value = today;
    filterRiwayatData();
}

function loadRiwayat(module, startDate = '', endDate = '') {
    const tbody = document.getElementById('riwayatBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-primary mb-3 d-block"></i>Memuat riwayat...</td></tr>';
    
    let url = `history_action.php?action=get_history&module=${encodeURIComponent(module)}`;
    if (startDate) url += `&start_date=${startDate}`;
    if (endDate) url += `&end_date=${endDate}`;
    
    fetch(url).then(r => r.json()).then(data => {
        let h = '';
        if(data.length === 0) {
            h = '<tr><td colspan="6" class="text-center py-5 text-muted italic">Belum ada riwayat aktivitas pada rentang tanggal ini.</td></tr>';
        } else {
            data.forEach(d => {
                let actionHtml = '';
                let actRaw = d.action_type.toLowerCase();
                
                if (actRaw.includes('buat') || actRaw.includes('tambah') || actRaw.includes('generate') || actRaw.includes('import')) {
                    actionHtml = `<span class="badge bg-success text-white shadow-sm px-3 py-1 w-100 text-uppercase fw-bold" style="letter-spacing: 0.5px;">${d.action_type}</span>`;
                } else if (actRaw.includes('ubah') || actRaw.includes('edit') || actRaw.includes('perbarui')) {
                    actionHtml = `<span class="badge bg-primary text-white shadow-sm px-3 py-1 w-100 text-uppercase fw-bold" style="letter-spacing: 0.5px;">${d.action_type}</span>`;
                } else if (actRaw.includes('hapus') || actRaw.includes('delete') || actRaw.includes('dibatalkan')) {
                    actionHtml = `<span class="badge bg-danger text-white shadow-sm px-3 py-1 w-100 text-uppercase fw-bold" style="letter-spacing: 0.5px;">${d.action_type}</span>`;
                } else if (actRaw.includes('dipulihkan')) {
                    actionHtml = `<span class="badge bg-info text-white shadow-sm px-3 py-1 w-100 text-uppercase fw-bold" style="letter-spacing: 0.5px;">${d.action_type}</span>`;
                } else {
                    actionHtml = `<span class="badge bg-secondary text-white shadow-sm px-3 py-1 w-100 text-uppercase fw-bold" style="letter-spacing: 0.5px;">${d.action_type}</span>`;
                }

                let undoBtn = '';
                if(d.action_type === 'Dibatalkan') undoBtn = `<span class="text-muted italic small">-</span>`;
                else if(d.is_reverted == 1) undoBtn = `<span class="text-muted italic small d-block">Telah Dibatalkan</span>`;
                else undoBtn = `<button class="btn btn-sm btn-danger rounded-1 fw-bold" style="font-size:10px; padding:4px 10px;" onclick="undoActivity(${d.id}, '${module}')">Batalkan</button>`;
                
                let viewFunc = (d.action_type === 'Hapus' || d.is_reverted == 1 || d.action_type === 'Dibatalkan' || d.action_type === 'Dipulihkan') 
                                ? `openLogSnapshotModal(${d.id}, '${module}')` : `openJournalModal(${d.record_id}, '${module}')`;

                h += `<tr>
                    <td class="text-center"><button class="btn btn-sm btn-outline-primary fw-bold rounded-pill shadow-sm px-3 bg-light" style="font-size:10px;" onclick="${viewFunc}"><i class="fas fa-search-plus me-1"></i> Detail</button></td>
                    <td class="ps-3 text-muted text-center fw-bold small">${d.waktu}</td>
                    <td class="fw-bold text-dark text-start"><i class="fas fa-user-circle me-1 opacity-25"></i> ${d.user_name}</td>
                    <td class="text-start text-dark">${d.description}</td>
                    <td class="text-center">${actionHtml}</td>
                    <td class="text-center pe-4">${undoBtn}</td>
                </tr>`;
            });
        }
        tbody.innerHTML = h;
    }).catch(e => { tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-danger fw-bold">Gagal memuat riwayat.</td></tr>'; });
}

function openJournalModal(id, moduleName = null) {
    if(moduleName) {
        const mRiwayat = bootstrap.Modal.getInstance(document.getElementById('modalRiwayat'));
        if(mRiwayat) mRiwayat.hide();
    }
    const mEl = document.getElementById('modalDetailJurnal');
    if(!mEl) return;
    if (mEl.parentNode !== document.body) { document.body.appendChild(mEl); }
    const m = bootstrap.Modal.getOrCreateInstance(mEl);
    
    let footerHtml = `<button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold w-100 shadow-sm" data-bs-dismiss="modal">Tutup Detail</button>`;
    if (moduleName) footerHtml = `<button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold w-100 shadow-sm" onclick="closeAndBackToRiwayat('${moduleName}')">KEMBALI KE RIWAYAT</button>`;
    document.getElementById('detailModalFooter').innerHTML = footerHtml;
    
    document.getElementById('loadingDetail').classList.remove('d-none');
    document.getElementById('contentDetail').classList.add('d-none');
    m.show();
    
    fetch(`accounting_action.php?action=get_trx_detail_full&id=${id}`).then(r => r.json()).then(d => {
        if(d.error) { alert(d.error); m.hide(); return; }
        const h = d.header;
        document.getElementById('viewRef').innerText = h.no_jurnal;
        document.getElementById('viewDate').innerText = h.tgl_jurnal;
        document.getElementById('viewPihak').innerText = (h.pihak_nama ? h.pihak_nama : 'Pihak Umum');
        document.getElementById('viewDesc').innerText = h.keterangan || 'Tidak ada deskripsi.';
        document.getElementById('viewCreator').innerHTML = `<i class="fas fa-user-edit me-2"></i> Dientri oleh: -`;
        
        const tbody = document.getElementById('viewTableBody'); tbody.innerHTML = '';
        let tD = 0, tK = 0;
        
        d.full_journal.forEach(row => {
            const dV = parseFloat(row.debit) || 0; const kV = parseFloat(row.kredit) || 0; tD += dV; tK += kV;
            const strD = dV > 0 ? new Intl.NumberFormat('id-ID').format(dV) : '-'; const strK = kV > 0 ? new Intl.NumberFormat('id-ID').format(kV) : '-';
            tbody.innerHTML += `<tr><td class="ps-3 text-start"><div class="fw-bold text-dark">${row.nama_akun || 'Akun Terhapus'}</div><code style="font-size: 10px; background:#f1f5f9; padding:2px 5px; border-radius:4px; color:#0d6efd;">${row.kode_akun}</code></td><td class="text-end text-success fw-bold">${strD}</td><td class="text-end pe-3 text-danger fw-bold">${strK}</td></tr>`;
        });
        
        document.getElementById('viewTotalD').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(tD);
        document.getElementById('viewTotalK').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(tK);
        document.getElementById('loadingDetail').classList.add('d-none'); document.getElementById('contentDetail').classList.remove('d-none');
    }).catch(err => { alert("Gagal memuat detail jurnal."); m.hide(); });
}

function formatNiceDataShared(jsonStr) {
    if (!jsonStr || jsonStr === "null") return "<div class='text-center text-muted fst-italic py-4 bg-white border rounded-4 shadow-sm'>Tidak ada data spesifik (rincian JSON) yang terekam.</div>";
    try {
        let parsed = JSON.parse(jsonStr);
        if (parsed.header) parsed = parsed.header; 

        if (Array.isArray(parsed)) {
            return `<div class='text-center py-4 bg-white border rounded-4 shadow-sm text-muted small fw-bold'>
                        <i class="fas fa-list-ul fa-2x mb-2 d-block text-primary"></i> Berisi multi-baris data operasional.
                    </div>`;
        }

        let html = '<ul class="list-group list-group-flush mb-0 border rounded-4 shadow-sm overflow-hidden">';
        const hiddenKeys = ['id', 'created_at', 'updated_at', 'link_jurnal_id', 'no_jurnal', 'created_by', 'user_id', 'mahasiswa_id', 'aset_id', 'tagihan_id_ref', 'pegawai_id', 'password', 'reset_token', 'reset_expires', 'is_deleted', 'is_migration', 'status_bayar', 'terbayar', 'pembayaran_jurnal_id', 'payroll_id', 'komponen_id', 'jenis_tagihan_id', 'tarif_id', 'prodi_id', 'pihak_nama'];
        
        const mapKata = {
            'nama_mahasiswa': 'Nama Mahasiswa', 'nama_pegawai': 'Nama Pegawai', 'nip_pegawai': 'NIP / NIK',
            'pihak_terkait_jurnal': 'Penyetor / Penerima', 'nama_tagihan': 'Nama Tagihan',
            'nominal': 'Nominal Tagihan', 'nominal_bayar': 'Nom. Pembayaran', 'nim': 'NIM',
            'kode_tahun': 'Periode Akademik', 'tanggal_bayar': 'Tgl. Pembayaran', 'no_kuitansi': 'No. Kuitansi',
            'kode_akun_kas': 'Akun Kas', 'keterangan': 'Keterangan', 'tgl_jurnal': 'Tgl. Transaksi',
            'total_debet': 'Total Debit', 'total_kredit': 'Total Kredit', 'nama_lengkap': 'Nama Pegawai',
            'jabatan': 'Jabatan', 'unit_kerja': 'Unit Bertugas', 'periode_bulan': 'Bulan',
            'periode_tahun': 'Tahun', 'total_gross': 'Gaji Kotor', 'total_potongan': 'Potongan',
            'total_netto': 'Gaji Bersih (THP)', 'gapok': 'Gaji Pokok', 'tunjangan': 'Tunjangan',
            'jenis_transaksi': 'Jenis Transaksi', 'akun_utama_kode': 'Kode Akun Utama'
        };

        let hasData = false;
        for (let key in parsed) {
            if (hiddenKeys.includes(key)) continue;
            let val = parsed[key];
            if (val === null || val === '') continue;

            let cleanKey = mapKata[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            
            if (!isNaN(val) && val > 100 && (key.includes('nominal') || key.includes('debet') || key.includes('debit') || key.includes('kredit') || key.includes('saldo') || key.includes('harga') || key.includes('nilai') || key.includes('gross') || key.includes('potongan') || key.includes('netto') || key.includes('gapok') || key.includes('tunjangan'))) {
                val = "Rp " + new Intl.NumberFormat('id-ID').format(val);
            }

            if((key.includes('tanggal') || key.includes('tgl') || key.includes('date')) && typeof val === 'string' && val.includes('-')) {
                const pDate = val.split(' ')[0].split('-');
                if(pDate.length === 3) val = `${pDate[2]}/${pDate[1]}/${pDate[0]}`;
            }

            let valStyle = "font-size: 13px; max-width:60%; word-wrap:break-word;";
            if (key === 'nama_mahasiswa' || key === 'nama_pegawai' || key === 'pihak_terkait_jurnal') {
                valStyle += " color: #0d6efd; font-weight: 900 !important;";
            }

            html += `<li class="list-group-item px-4 py-3 bg-white d-flex justify-content-between align-items-center border-bottom">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 10px;">${cleanKey}</span>
                        <span class="fw-bold text-dark text-end" style="${valStyle}">${val}</span>
                     </li>`;
            hasData = true;
        }
        html += '</ul>';
        return hasData ? html : "<div class='text-center text-muted fst-italic py-4 bg-white border rounded-4 shadow-sm'>Hanya pembaruan status sistem (Logikal).</div>";
    } catch (e) {
        return "<div class='text-center text-muted fst-italic py-4 bg-white border rounded-4 shadow-sm'>Rincian format JSON tidak didukung.</div>";
    }
}

function openLogSnapshotModal(logId, moduleName) {
    const mRiwayat = bootstrap.Modal.getInstance(document.getElementById('modalRiwayat'));
    if(mRiwayat) mRiwayat.hide();
    const mEl = document.getElementById('modalDetailJurnal');
    if(!mEl) return;
    const m = bootstrap.Modal.getOrCreateInstance(mEl);
    
    document.getElementById('detailModalFooter').innerHTML = `<button type="button" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm w-100" onclick="undoActivity(${logId}, '${moduleName}')"><i class="fas fa-undo-alt me-2"></i>PULIHKAN KEMBALI JURNAL INI</button><button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold shadow-sm w-100" onclick="closeAndBackToRiwayat('${moduleName}')">TUTUP & KEMBALI</button>`;

    document.getElementById('loadingDetail').classList.remove('d-none'); document.getElementById('contentDetail').classList.add('d-none'); m.show();
    
    fetch(`history_action.php?action=get_log_detail&id=${logId}`).then(r => r.json()).then(d => {
        if(d.error) { alert(d.error); m.hide(); return; }
        
        const container = document.getElementById('contentDetail');
        container.innerHTML = `
            <div class="p-4 bg-light border-bottom">
                <div class="alert bg-white border shadow-sm rounded-4 text-center p-3 mb-0">
                    <div class="fw-bold text-danger text-uppercase" style="font-size: 11px;"><i class="fas fa-history me-1"></i> DATA ARSIP (SNAPSHOT)</div>
                    <h6 class="fw-bold text-dark mt-1 mb-0">${d.header ? d.header.keterangan : 'Data Terdahulu'}</h6>
                </div>
            </div>
            <div class="p-4 bg-white">
                ${formatNiceDataShared(JSON.stringify(d.header))}
            </div>
        `;
        
        document.getElementById('loadingDetail').classList.add('d-none'); 
        container.classList.remove('d-none');
    }).catch(err => { alert("Gagal memuat snapshot riwayat dari server."); m.hide(); });
}

function undoActivity(id, module) {
    if(!confirm("Anda yakin ingin membatalkan/memulihkan aktivitas ini? Data transaksi di Buku Besar akan berubah secara otomatis.")) return;
    const mDetail = bootstrap.Modal.getInstance(document.getElementById('modalDetailJurnal'));
    if(mDetail) mDetail.hide();

    const formData = new FormData(); formData.append('action', 'undo'); formData.append('id', id); formData.append('module', module);

    fetch('history_action.php', { method: 'POST', body: formData }).then(r => r.json()).then(res => {
        if(res.status === 'success') { alert(res.msg); setTimeout(() => { window.location.reload(); }, 500); } else { alert("Gagal: " + res.msg); }
    }).catch(e => alert("Gagal menghubungi server."));
}
</script>