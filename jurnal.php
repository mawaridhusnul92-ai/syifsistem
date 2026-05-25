<?php
/**
 * jurnal.php - MODAL JURNAL PENYESUAIAN (AJP) - COMPACT CONTEXT INTEGRATED
 * Versi: 18.1 (Optimized Layout & Sidebar Harmony)
 * Deskripsi: Menangani AJP dengan deteksi Piutang & Aset dalam layout yang lebih ramping.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$tgl_awal = $_GET['start'] ?? date('Y-m-01');
$tgl_akhir = $_GET['end'] ?? date('Y-m-d');

// 1. DYNAMIC CONFIGURATION
$CODE_PIUTANG = getAccountCode($conn, 'PIUTANG_MHS') ?: '1-1201';

// 2. DATA MASTER UNTUK MODAL
$all_coa = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
$asset_list = $conn->query("SELECT id, asset_code, asset_name FROM assets WHERE status='Aktif' ORDER BY asset_name ASC")->fetch_all(MYSQLI_ASSOC);

// 3. QUERY LISTING AJP
$sql = "SELECT j.* FROM syifa_jurnal j 
        WHERE j.jenis_jurnal = 'penyesuaian' 
        AND j.tgl_jurnal BETWEEN '$tgl_awal' AND '$tgl_akhir' 
        ORDER BY j.tgl_jurnal DESC, j.id DESC";
$res = $conn->query($sql);
?>

<style>
    /* UI Suggestion Box COA */
    .coa-suggest-container { position: relative; }
    .coa-suggestions {
        position: absolute; top: 100%; left: 0; right: 0;
        z-index: 1080; background: #fff; border-radius: 10px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        max-height: 200px; overflow-y: auto; border: 1px solid #e2e8f0;
    }
    .coa-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
    .coa-item:hover { background: #f0f9ff; color: #0d6efd; }
    .coa-item code { font-weight: bold; color: #64748b; margin-right: 5px; }
    
    /* Penyesuaian Kolom Konteks agar Ramping */
    .col-context { min-width: 220px; max-width: 280px; transition: all 0.3s ease; }
    .inp-jurnal { font-size: 0.8rem !important; height: 32px; border: none !important; box-shadow: none !important; }
    
    /* Perbaikan Dimensi Modal agar Tidak Tertutup Sidebar */
    .modal-dialog-centered { margin-left: auto; margin-right: auto; }
    @media (min-width: 992px) {
        body:not(.sidebar-toggled) .modal-ajp-custom { max-width: 850px; }
    }
    
    .table-jurnal thead th { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; padding: 10px; background: #334155; color: #fff; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    <!-- HEADER & FILTER -->
    <div class="card shadow-sm border-0 rounded-4 bg-white overflow-hidden mb-4">
        <div class="card-header pt-3 px-4 border-0 d-flex justify-content-between align-items-center" style="background: #f8fafc;">
            <div>
                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-balance-scale me-2 text-primary"></i>Jurnal Penyesuaian (AJP)</h5>
                <small class="text-muted" style="font-size: 11px;">Pencatatan akun akrual, penyusutan, dan penyesuaian saldo akhir.</small>
            </div>
            <div class="d-flex gap-2">
                <form method="GET" class="d-flex gap-2 no-print">
                    <input type="hidden" name="page" value="jurnal">
                    <input type="date" name="start" class="form-control form-control-sm rounded-pill px-3 border shadow-none" value="<?= $tgl_awal ?>">
                    <input type="date" name="end" class="form-control form-control-sm rounded-pill px-3 border shadow-none" value="<?= $tgl_akhir ?>">
                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">FILTER</button>
                </form>
                <button class="btn btn-dark btn-sm rounded-pill px-4 fw-bold shadow-sm" onclick="openAjpModal()">
                    <i class="fas fa-plus-circle me-1"></i>BUAT JURNAL
                </button>
            </div>
        </div>

        <div class="card-body p-4">
            <div class="table-responsive rounded-4 border overflow-hidden">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small fw-bold">
                        <tr>
                            <th class="ps-4" width="100">Opsi</th>
                            <th>Referensi</th>
                            <th>Tanggal</th>
                            <th>Keterangan</th>
                            <th class="text-end pe-4">Nominal (D)</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 13px;">
                        <?php if($res && $res->num_rows > 0): while($row = $res->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="btn-group btn-group-sm rounded-pill border bg-white overflow-hidden">
                                    <button class="btn btn-white text-warning border-end" onclick="editAjp(<?= $row['id'] ?>)"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-white text-danger" onclick="confirmDelete(<?= $row['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                            <td class="fw-bold text-primary"><?= $row['no_jurnal'] ?></td>
                            <td class="text-muted"><?= date('d/m/Y', strtotime($row['tgl_jurnal'])) ?></td>
                            <td class="fw-semibold text-dark"><?= htmlspecialchars($row['keterangan']) ?></td>
                            <td class="text-end pe-4 fw-bold text-dark">Rp <?= number_format($row['total_debet']) ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted small italic">Belum ada transaksi penyesuaian.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL COMPACT JURNAL -->
<div class="modal fade" id="modalJurnal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-ajp-custom modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" action="adjustment_action.php" id="formJournal" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_ajp">
            <input type="hidden" name="jenis_jurnal_input" value="penyesuaian">
            <input type="hidden" name="id" id="inp_id">
            
            <div class="modal-header border-0 p-3 text-white" id="modalHeaderAjp" style="background: #1e293b;">
                <h6 class="modal-title fw-bold" id="title_modal"><i class="fas fa-edit me-2"></i>Entri Jurnal Penyesuaian</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-3 bg-light">
                <!-- Header Fields (Compact Row) -->
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="small fw-bold text-muted mb-1" style="font-size: 10px;">TANGGAL</label>
                        <input type="date" name="date" id="inp_date" class="form-control form-control-sm border-0 shadow-sm rounded-3" required>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-muted mb-1" style="font-size: 10px;">NO. REFERENSI</label>
                        <input type="text" name="no_ref" id="inp_ref" class="form-control form-control-sm border-0 shadow-sm fw-bold text-primary rounded-3" readonly placeholder="Auto">
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1" style="font-size: 10px;">KETERANGAN / MEMO</label>
                        <input type="text" name="desc" id="inp_ket" class="form-control form-control-sm border-0 shadow-sm rounded-3" placeholder="Deskripsi penyesuaian..." required>
                    </div>
                </div>

                <!-- Matrix Table -->
                <div class="table-responsive rounded-3 border bg-white shadow-sm overflow-visible">
                    <table class="table table-sm table-hover mb-0 align-middle table-jurnal">
                        <thead>
                            <tr>
                                <th class="ps-3" width="40%">Akun</th>
                                <th class="col-context d-none">Identitas / Link Aset</th>
                                <th width="140" class="text-end">Debet</th>
                                <th width="140" class="text-end">Kredit</th>
                                <th width="40" class="text-center">#</th>
                            </tr>
                        </thead>
                        <tbody id="journalBody" style="font-size: 11px;"></tbody>
                    </table>
                </div>
                
                <div class="mt-2 px-1">
                    <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-3 fw-bold" style="font-size: 10px;" onclick="addRow()">
                        <i class="fas fa-plus me-1"></i> TAMBAH BARIS
                    </button>
                </div>
                
                <!-- Status Bar -->
                <div class="mt-3 p-3 bg-white rounded-3 border border-dashed d-flex justify-content-between align-items-center shadow-sm">
                    <div>
                        <div class="small fw-bold text-muted text-uppercase" style="font-size: 9px; letter-spacing: 0.5px;">Status Balance</div>
                        <div id="balanceLabel" class="fw-bold" style="font-size: 13px;">Rp 0</div>
                    </div>
                    <div class="text-end">
                        <div class="small fw-bold text-muted text-uppercase" style="font-size: 9px; letter-spacing: 0.5px;">Total Debet</div>
                        <div id="totalLabel" class="fw-bold text-primary h5 mb-0">Rp 0</div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer border-0 p-3 bg-light">
                <button type="submit" id="btnSubmit" class="btn btn-sm btn-success w-100 py-2 rounded-pill fw-bold shadow-lg" disabled>
                    POSTING JURNAL KE BUKU BESAR
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const coaData = <?= json_encode($all_coa) ?>;
const assetData = <?= json_encode($asset_list) ?>;
const CODE_PIUTANG = '<?= $CODE_PIUTANG ?>';
let rowIdx = 0;

function openAjpModal(id = null) {
    const modalEl = document.getElementById('modalJurnal');
    const form = document.getElementById('formJournal');
    form.reset();
    $('#journalBody').empty();
    $('#inp_id').val(id || '');
    
    $('#btnSubmit').text(id ? 'PERBARUI JURNAL' : 'POSTING JURNAL SEKARANG')
                  .removeClass('btn-success btn-warning')
                  .addClass(id ? 'btn-warning' : 'btn-success');
    
    toggleIdentitas(false);

    if(!id) {
        $('#inp_ref').val('AJP-' + Math.floor(Date.now() / 1000));
        $('#inp_date').val(new Date().toISOString().split('T')[0]);
        addRow(); addRow();
    } else {
        $.getJSON('ajax_cash.php?action=get_trx_detail&id=' + id, function(data) {
            $('#inp_date').val(data.header.tgl_jurnal);
            $('#inp_ref').val(data.header.no_jurnal);
            $('#inp_ket').val(data.header.keterangan);
            data.details.forEach(d => {
                const acc = coaData.find(c => c.kode_akun == d.kode_akun);
                addRow(d.kode_akun, (acc ? acc.nama_akun : ''), d.debit, d.kredit, {
                    m_id: d.mahasiswa_id,
                    t_ref: d.tagihan_id_ref,
                    ast_id: d.aset_id
                });
            });
            calcJurnal();
        });
    }
    new bootstrap.Modal(modalEl).show();
}

function addRow(accCode = '', accName = '', d = 0, k = 0, context = null) {
    const id = ++rowIdx;
    const initialLabel = accCode ? `${accCode} - ${accName}` : '';
    
    const html = `<tr id="j_row_${id}" class="animate__animated animate__fadeIn">
        <td class="ps-3 p-1 coa-suggest-container">
            <input type="hidden" name="kode_akun[]" id="hid_j_acc_${id}" value="${accCode}">
            <input type="text" class="form-control inp-jurnal search-coa-j" id="search_j_acc_${id}" 
                   placeholder="Cari..." autocomplete="off" value="${initialLabel}" 
                   oninput="filterCOAJurnal(this, ${id})" onfocus="filterCOAJurnal(this, ${id})" required>
            <div id="suggest_j_${id}" class="coa-suggestions d-none"></div>
        </td>
        <td class="col-context d-none p-1 bg-light"><div id="logic_box_j_${id}"></div></td>
        <td class="p-1"><input type="text" name="debit[]" class="form-control inp-jurnal text-end inp-d fw-bold text-primary" value="${fmt(d)}" onkeyup="calcJurnal(this)" onclick="this.select()"></td>
        <td class="p-1"><input type="text" name="kredit[]" class="form-control inp-jurnal text-end inp-k fw-bold text-danger" value="${fmt(k)}" onkeyup="calcJurnal(this)" onclick="this.select()"></td>
        <td class="text-center p-1"><button type="button" class="btn btn-link text-danger p-0" onclick="removeRow(${id})"><i class="fas fa-times-circle"></i></button></td>
    </tr>`;
    $('#journalBody').append(html);
    if(accCode) checkContextJournal(id, accCode, context);
    calcJurnal();
}

function filterCOAJurnal(el, id) {
    const query = el.value.toLowerCase();
    const suggestBox = document.getElementById(`suggest_j_${id}`);
    const filtered = coaData.filter(c => c.kode_akun.includes(query) || c.nama_akun.toLowerCase().includes(query)).slice(0, 10);
    if (filtered.length > 0) {
        suggestBox.innerHTML = filtered.map(c => `<div class="coa-item" onclick="selectCOAJurnal(${id}, '${c.kode_akun}', '${c.nama_akun}')"><code>${c.kode_akun}</code> ${c.nama_akun}</div>`).join('');
        suggestBox.classList.remove('d-none');
    } else { suggestBox.classList.add('d-none'); }
}

function selectCOAJurnal(rowId, code, name) {
    document.getElementById(`hid_j_acc_${rowId}`).value = code;
    document.getElementById(`search_j_acc_${rowId}`).value = `${code} - ${name}`;
    document.getElementById(`suggest_j_${rowId}`).classList.add('d-none');
    checkContextJournal(rowId, code);
}

function checkContextJournal(rId, code, context = null) {
    const box = document.getElementById(`logic_box_j_${rId}`);
    if(code === CODE_PIUTANG) {
        box.innerHTML = `
            <select name="mhs_id[]" class="form-select form-select-sm mb-1 border-primary" style="height:28px; font-size:10px;" onchange="loadBillsJournal(this, ${rId})" required><option value="">-- Mhs --</option></select>
            <select name="tagihan_ref[]" id="tag_j_${rId}" class="form-select form-select-sm border-primary" style="height:28px; font-size:10px;" required><option value="">-- Tagihan --</option></select>
            <input type="hidden" name="asset_id[]" value="">
        `;
        fetch('ajax_student_bills.php?action=get_mhs_list').then(r=>r.json()).then(data => {
            const s = box.querySelector('select');
            data.forEach(m => {
                const opt = new Option(`${m.nim} - ${m.nama}`, m.id);
                if(context && context.m_id == m.id) opt.selected = true;
                s.add(opt);
            });
            if(context && context.m_id) loadBillsJournal(s, rId, context.t_ref);
        });
    } else if(code.startsWith('1-21') || code.startsWith('1-22')) {
        box.innerHTML = `
            <select name="asset_id[]" class="form-select form-select-sm border-success" style="height:28px; font-size:10px;" required>
                <option value="">-- Pilih Aset --</option>
                ${assetData.map(a => `<option value="${a.id}" ${(context && context.ast_id == a.id) ? 'selected' : ''}>${a.asset_name}</option>`).join('')}
            </select>
            <input type="hidden" name="mhs_id[]" value=""><input type="hidden" name="tagihan_ref[]" value="">
        `;
    } else {
        box.innerHTML = '<input type="hidden" name="mhs_id[]" value=""><input type="hidden" name="tagihan_ref[]" value=""><input type="hidden" name="asset_id[]" value="">';
    }
    checkGlobalContextJournal();
}

function loadBillsJournal(el, rId, selectedId = null) {
    const bSel = document.getElementById(`tag_j_${rId}`);
    fetch(`ajax_student_bills.php?action=get_bills&mhs_id=${el.value}`).then(r=>r.json()).then(data => {
        bSel.innerHTML = '<option value="">-- Tagihan --</option>';
        data.forEach(b => { 
            const opt = new Option(b.display_label, b.id);
            if(b.id == selectedId) opt.selected = true;
            bSel.add(opt); 
        });
    });
}

function checkGlobalContextJournal() {
    let show = false;
    document.querySelectorAll('input[name="kode_akun[]"]').forEach(input => {
        if(input.value === CODE_PIUTANG || input.value.startsWith('1-21') || input.value.startsWith('1-22')) show = true;
    });
    toggleIdentitas(show);
}

function toggleIdentitas(show) { document.querySelectorAll('.col-context').forEach(c => c.classList.toggle('d-none', !show)); }
function removeRow(id) { $(`#j_row_${id}`).remove(); calcJurnal(); checkGlobalContextJournal(); }
function fmt(n) { return new Intl.NumberFormat('id-ID').format(n); }
function prs(s) { return parseFloat(s.toString().replace(/\./g, '').replace(/,/g, '.')) || 0; }

function calcJurnal(el = null) {
    if(el) el.value = fmt(prs(el.value));
    let td = 0, tk = 0;
    $('.inp-d').each(function() { td += prs($(this).val()); });
    $('.inp-k').each(function() { tk += prs($(this).val()); });
    $('#totalLabel').text('Rp ' + fmt(td));
    const diff = Math.abs(td - tk);
    $('#balanceLabel').html(diff === 0 ? '<span class="text-success"><i class="fas fa-check-circle me-1"></i>BALANCE</span>' : '<span class="text-danger">SELISIH Rp ' + fmt(diff) + '</span>');
    $('#btnSubmit').prop('disabled', !(td > 0 && diff === 0));
}

function editAjp(id) { openAjpModal(id); }
function confirmDelete(id) {
    if(confirm('HAPUS JURNAL INI PERMANEN?')) {
        const f = document.createElement('form'); f.method='POST'; f.action='accounting_action.php';
        f.innerHTML = `<input type="hidden" name="action" value="delete_trx"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f); f.submit();
    }
}

document.addEventListener('click', (e) => { if (!e.target.classList.contains('search-coa-j')) { document.querySelectorAll('.coa-suggestions').forEach(box => box.classList.add('d-none')); } });
</script>

<style>
    .btn-white { background: #fff; border: none; transition: 0.2s; }
    .btn-white:hover { background: #f8fafc; color: #0d6efd !important; }
    .btn-xs { padding: 4px 12px; font-size: 10px; }
</style>