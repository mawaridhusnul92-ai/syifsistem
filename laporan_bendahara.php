<?php
/**
 * laporan_bendahara.php - LAPORAN BENDAHARA & REALISASI SPM
 * Versi: 22.0 (Sovereign Grand Master - Clean UI & Enhanced Cashflow Header)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan:
 * 1. Menghapus kode akun dan kode voucher dari tabel realisasi.
 * 2. Memperbaiki kontras warna header tabel Rekapitulasi Kas & Bank.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// 🚀 INTERCEPTOR TARIK RAPB LANGSUNG
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] == 'tarik_rapb_local') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $bulan = (int)$_GET['bulan'];
        $tahun = (int)$_GET['tahun'];
        
        $q_head = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran = '$tahun' AND kategori = 'Belanja' AND status IN ('Approved', 'Generated') ORDER BY id DESC LIMIT 1");
        
        if ($q_head && $q_head->num_rows > 0) {
            $header_id = $q_head->fetch_assoc()['id'];
            $sql = "SELECT b.kode_akun, a.nama_akun, p.nominal_rencana as pagu_bulan 
                    FROM syifa_budgets b 
                    LEFT JOIN syifa_akun a ON b.kode_akun = a.kode_akun 
                    JOIN syifa_budget_monthly_plan p ON p.budget_id = b.id 
                    WHERE b.header_id = $header_id AND b.is_category = 0 AND p.bulan = $bulan AND p.nominal_rencana > 0";
            
            $res = $conn->query($sql);
            $data = [];
            $nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
            $nama_bln_pilih = $nama_bulan[$bulan] ?? "Bulan Ini";
            
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $nama_ak = $r['nama_akun'] ? $r['nama_akun'] : "Akun ".$r['kode_akun'];
                    $data[] = [
                        'kode_akun' => $r['kode_akun'],
                        'rincian' => "Anggaran " . $nama_ak . " - " . $nama_bln_pilih . " " . $tahun,
                        'nominal' => $r['pagu_bulan']
                    ];
                }
            }
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => "Tidak ada Anggaran Belanja (RAPB) yang disetujui (Approved) untuk tahun $tahun."]);
        }
    } catch(Exception $e) { echo json_encode(['status' => 'error', 'msg' => 'Server Error: ' . $e->getMessage()]); }
    exit;
}

$role_id = (int)($_SESSION['role_id'] ?? 0);
$is_superadmin = ($role_id === 1);
if(function_exists('guardPage')) { guardPage('laporan_bendahara'); }

$idempotent_key = bin2hex(random_bytes(16)) . '_' . time();
$view = $_GET['view'] ?? 'hub';
$active_tab = $_GET['tab'] ?? 'spm';
$uid = $_SESSION['user_id'] ?? 0;

if (!function_exists('formatRp')) { function formatRp($n) { return number_format($n ?? 0, 0, ',', '.'); } }

$coa_beban = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE kategori='Beban' AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
?>

<style>
    .spm-coa-container { position: relative !important; display: block; width: 100%; }
    #globalCoaDropdown { background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 12px !important; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.15) !important; max-height: 240px !important; overflow-y: auto !important; display: none; padding: 6px 0 !important; z-index: 105000 !important; }
    #globalCoaDropdown::-webkit-scrollbar { width: 6px; }
    #globalCoaDropdown::-webkit-scrollbar-track { background: transparent; }
    #globalCoaDropdown::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    #globalCoaDropdown::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    .spm-coa-item { padding: 8px 14px !important; cursor: pointer !important; border-bottom: 1px solid #f1f5f9 !important; font-size: 12.5px !important; font-weight: 600 !important; text-align: left !important; color: #334155 !important; display: flex !important; align-items: center !important; }
    .spm-coa-item:last-child { border-bottom: none !important; }
    .spm-coa-item:hover { background-color: #f1f5f9 !important; color: #0d6efd !important; }
    .spm-coa-item code { background-color: #e2e8f0 !important; padding: 3px 6px !important; border-radius: 6px !important; color: #0f172a !important; margin-right: 12px !important; font-weight: bold !important; font-size: 11px !important; }
    .spm-coa-item:hover code { background-color: #0d6efd !important; color: #ffffff !important; }
    .table-responsive { overflow: visible !important; }
    
    /* FIX Warni Teks Header Kas & Bank */
    .excel-table thead.bg-dark th { background-color: #212529 !important; color: #ffffff !important; border-color: #373b3e !important; }
</style>

<?php
if ($view == 'builder') {
    $spm_id = (int)$_GET['id'];
    $spm = $conn->query("SELECT * FROM keuangan_spm_header WHERE id = $spm_id")->fetch_assoc();
    if (!$spm) { die("Data SPM tidak ditemukan."); }
    
    $is_locked = ($spm['status'] == 'GENERATED');
    $details = $conn->query("SELECT * FROM keuangan_spm_detail WHERE spm_id = $spm_id")->fetch_all(MYSQLI_ASSOC);
    $tahun_spm = date('Y', strtotime($spm['tgl_mulai']));
?>
    <div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-warning border-4">
            <div class="d-flex align-items-center gap-3">
                <a href="index.php?page=laporan_bendahara&tab=spm" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h5 class="fw-bold mb-0 text-dark">Penyusunan: <?= htmlspecialchars($spm['nama_spm']) ?></h5>
                    <small class="text-muted fw-bold">Periode: <?= date('d M Y', strtotime($spm['tgl_mulai'])) ?> s/d <?= date('d M Y', strtotime($spm['tgl_akhir'])) ?></small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if($spm['is_tambahan'] == 1): ?>
                    <span class="badge bg-danger px-3 py-2 rounded-pill shadow-sm"><i class="fas fa-plus-circle me-1"></i> SPM Tambahan</span>
                <?php endif; ?>
                <span class="badge bg-<?= $spm['status'] == 'GENERATED' ? 'success' : 'secondary' ?> px-3 py-2 rounded-pill shadow-sm"><?= $spm['status'] ?></span>
                <a href="print_spm.php?id=<?= $spm_id ?>" target="_blank" class="btn btn-dark btn-sm rounded-pill px-3 shadow-sm fw-bold"><i class="fas fa-print me-1"></i> Cetak</a>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 bg-white overflow-visible">
            <div class="card-header bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-list-ol me-2"></i>Rincian Mata Anggaran</h6>
                <?php if(!$is_locked): ?>
                <button type="button" class="btn btn-sm btn-primary rounded-pill fw-bold px-4 shadow-sm" onclick="bukaModalTarik()">
                    <i class="fas fa-cloud-download-alt me-2"></i>Tarik Data Anggaran (RAPB)
                </button>
                <?php endif; ?>
            </div>
            
            <form action="spm_action.php" method="POST" id="formBuilderSpm" onsubmit="return validateSpm(event)">
                <input type="hidden" name="action" value="save_spm">
                <input type="hidden" name="spm_id" value="<?= $spm_id ?>">
                <input type="hidden" name="status_target" id="statusTarget" value="DRAFT">
                
                <div class="card-body p-4 overflow-visible">
                    <div class="table-responsive rounded-3 border overflow-visible">
                        <table class="table table-hover align-middle mb-0 text-center overflow-visible">
                            <thead class="table-dark small text-uppercase text-white">
                                <tr>
                                    <th class="text-start ps-3" width="35%">Rincian / Deskripsi</th>
                                    <th class="text-start" width="35%">Kode Akun COA (Beban)</th>
                                    <th class="text-end" width="20%">Jumlah (Rp)</th>
                                    <th class="text-center pe-3" width="10%">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="spmRows" class="overflow-visible"></tbody>
                            <tfoot class="bg-light fw-bold text-dark border-top">
                                <tr>
                                    <td colspan="2" class="text-end py-3 text-uppercase">Total Pengajuan SPM</td>
                                    <td class="text-end fs-5 text-primary" id="txtTotalSpm">Rp 0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php if(!$is_locked): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-4 mt-3" onclick="addSpmRow()">
                        <i class="fas fa-plus me-2"></i>Tambah Baris Kosong
                    </button>
                    <?php endif; ?>
                </div>
                
                <?php if(!$is_locked): ?>
                <div class="card-footer bg-white border-top p-4 text-center">
                    <button type="submit" class="btn btn-secondary rounded-pill px-5 py-3 fw-bold shadow-sm me-2" onclick="document.getElementById('statusTarget').value='DRAFT'">
                        <i class="fas fa-save me-2"></i>SIMPAN DRAFT
                    </button>
                    <button type="submit" class="btn btn-success rounded-pill px-5 py-3 fw-bold shadow-sm ms-2" onclick="document.getElementById('statusTarget').value='GENERATED'">
                        <i class="fas fa-check-double me-2"></i>GENERATE SPM (FINAL)
                    </button>
                </div>
                <?php else: ?>
                <div class="card-footer bg-light border-top p-4 text-center">
                    <div class="alert alert-success border-0 mb-3 fw-bold d-inline-block px-5 rounded-pill shadow-sm">
                        <i class="fas fa-lock me-2"></i>SPM INI TELAH DIGENERATE DAN TERKUNCI
                    </div>
                    <?php if($is_superadmin || (defined('RBAC_EDIT') && RBAC_EDIT)): ?>
                        <div class="d-block mt-2">
                            <a href="spm_action.php?action=cancel_generate&id=<?= $spm_id ?>" class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold shadow-sm" onclick="return confirm('Peringatan: Membatalkan Generate akan mengubah nilai Realisasi Anggaran secara instan. Lanjutkan?')">
                                <i class="fas fa-unlock-alt me-1"></i> Batalkan Generate (Mode Edit)
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- 🚀 MODAL PILIH BULAN & TAHUN UNTUK TARIK ANGGARAN -->
    <div class="modal fade" id="modalTarikAnggaran" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered text-dark">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-primary text-white p-3 border-0">
                    <h6 class="modal-title fw-bold"><i class="fas fa-calendar-alt me-2"></i>Pilih Periode Anggaran Belanja (RAPB)</h6>
                    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light text-center">
                    <label class="small fw-bold text-muted mb-2">Tarik porsi anggaran untuk periode:</label>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <select id="pilihBulanTarik" class="form-select border shadow-sm rounded-pill fw-bold text-center">
                                <?php $nmb=["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"]; $curMonth = date('n', strtotime($spm['tgl_mulai'])); for($m=1;$m<=12;$m++) echo "<option value='$m' ".($curMonth==$m?'selected':'').">".$nmb[$m-1]."</option>"; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <select id="pilihTahunTarik" class="form-select border shadow-sm rounded-pill fw-bold text-center">
                                <?php for($y=date('Y')+1;$y>=2020;$y--) echo "<option value='$y' ".($tahun_spm==$y?'selected':'').">$y</option>"; ?>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm py-2" id="btnEksekusiTarik" onclick="eksekusiTarikAnggaran(<?= $spm_id ?>)">
                        <i class="fas fa-download me-2"></i>Tarik Data RAPB Sekarang
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const coaBeban = <?= json_encode($coa_beban) ?>;
        const existingData = <?= json_encode($details) ?>;
        const isLocked = <?= $is_locked ? 'true' : 'false' ?>;
        let isSubmittingSPM = false;
        let spmRowId = 0; 
        
        let activeRowId = null;
        let activeInputEl = null;

        function formatRpJS(val) {
            if(!val) return '0';
            let clean = val.toString().replace(/[^0-9]/g, '');
            return clean ? new Intl.NumberFormat('id-ID').format(clean) : '0';
        }
        function fmtRp(el) { el.value = formatRpJS(el.value); calcSpmTotal(); }
        function parseRp(str) { return parseFloat(str.replace(/[^0-9]/g, '')) || 0; }

        function calcSpmTotal() {
            let t = 0;
            document.querySelectorAll('.spm-amt').forEach(el => { t += parseRp(el.value); });
            document.getElementById('txtTotalSpm').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(t);
        }

        function addSpmRow(rincian = '', kode_akun = '', nominal = 0) {
            spmRowId++;
            const rowId = spmRowId;
            const tr = document.createElement('tr');
            tr.id = `spm_row_tr_${rowId}`;
            tr.className = "overflow-visible";
            
            let initialSearchVal = '';
            if (kode_akun) {
                const found = coaBeban.find(c => c.kode_akun === kode_akun);
                initialSearchVal = found ? `${found.kode_akun} - ${found.nama_akun}` : kode_akun;
            }

            tr.innerHTML = `
                <td class="ps-3"><input type="text" name="rincian[]" class="form-control form-control-sm border shadow-sm rounded-3 fw-bold" value="${rincian}" placeholder="Deskripsi pengeluaran..." required ${isLocked?'readonly':''}></td>
                <td class="text-start overflow-visible">
                    <div class="position-relative spm-coa-container w-100">
                        <div class="input-group input-group-sm shadow-sm rounded-3 overflow-hidden border bg-white position-relative">
                            <input type="text" id="spmCoaSearch_${rowId}" class="form-control border-0 bg-white fw-bold text-dark px-2 pe-4" 
                                   value="${initialSearchVal}" placeholder="Pilih atau ketik akun..." autocomplete="off"
                                   onclick="openSpmCoaDropdown(this, '${rowId}')" onkeyup="filterSpmCoaDropdown(this, '${rowId}')" onblur="hideSpmSuggestions()" ${isLocked?'readonly':''}>
                            <i class="fas fa-search" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #94a3b8; font-size: 11px; z-index: 5;"></i>
                        </div>
                        <input type="hidden" name="kode_akun[]" value="${kode_akun}" id="spmCoaValue_${rowId}" required>
                    </div>
                </td>
                <td><input type="text" name="nominal[]" class="form-control form-control-sm border shadow-sm rounded-3 text-end text-primary fw-bold spm-amt" value="${formatRpJS(nominal)}" onkeyup="fmtRp(this)" required ${isLocked?'readonly':''}></td>
                <td class="pe-3 text-center">
                    ${!isLocked ? `<button type="button" class="btn btn-link text-danger p-0" onclick="document.getElementById('spm_row_tr_${rowId}').remove(); calcSpmTotal();"><i class="fas fa-times-circle fa-lg"></i></button>` : `<i class="fas fa-lock text-muted"></i>`}
                </td>
            `;
            document.getElementById('spmRows').appendChild(tr);
            calcSpmTotal();
        }

        function openSpmCoaDropdown(el, rowId) {
            if (isLocked) return;
            el.select();
            activeRowId = rowId;
            activeInputEl = el;
            renderGlobalCoaBox(coaBeban, rowId);
        }

        function filterSpmCoaDropdown(el, rowId) {
            if (isLocked) return;
            const val = el.value.toLowerCase();
            const matches = coaBeban.filter(c => c.kode_akun.includes(val) || c.nama_akun.toLowerCase().includes(val));
            renderGlobalCoaBox(matches, rowId);
        }

        function renderGlobalCoaBox(list, rowId) {
            const resBox = document.getElementById('globalCoaDropdown');
            if(!resBox) return;
            resBox.innerHTML = '';
            const rect = activeInputEl.getBoundingClientRect();
            resBox.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            resBox.style.left = (rect.left + window.scrollX) + 'px';
            resBox.style.width = rect.width + 'px';
            
            if (list.length > 0) {
                list.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'spm-coa-item';
                    div.innerHTML = `<code>${c.kode_akun}</code> ${c.nama_akun}`;
                    div.onmousedown = function(e) { e.preventDefault(); selectSpmCoa(rowId, c.kode_akun, c.nama_akun); };
                    resBox.appendChild(div);
                });
            } else {
                resBox.innerHTML = '<div class="p-2 text-center text-muted small">Akun tidak ditemukan</div>';
            }
            resBox.style.display = 'block';
        }

        function selectSpmCoa(rowId, kode, nama) {
            document.getElementById('spmCoaValue_' + rowId).value = kode;
            document.getElementById('spmCoaSearch_' + rowId).value = `${kode} - ${nama}`;
            document.getElementById('globalCoaDropdown').style.display = 'none';
            calcSpmTotal();
        }

        function hideSpmSuggestions() { setTimeout(() => { const el = document.getElementById('globalCoaDropdown'); if (el) el.style.display = 'none'; }, 200); }
        function repositionGlobalCoaBox() {
            const resBox = document.getElementById('globalCoaDropdown');
            if (resBox && resBox.style.display === 'block' && activeInputEl) {
                const rect = activeInputEl.getBoundingClientRect();
                resBox.style.top = (rect.bottom + window.scrollY + 4) + 'px';
                resBox.style.left = (rect.left + window.scrollX) + 'px';
                resBox.style.width = rect.width + 'px';
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.spm-coa-container') && !e.target.closest('#globalCoaDropdown')) {
                const el = document.getElementById('globalCoaDropdown');
                if(el) el.style.display = 'none';
            }
        });
        window.addEventListener('scroll', function() { repositionGlobalCoaBox(); }, true);

        function bukaModalTarik() { new bootstrap.Modal(document.getElementById('modalTarikAnggaran')).show(); }

        function eksekusiTarikAnggaran(id) {
            const bulan = document.getElementById('pilihBulanTarik').value;
            const tahun = document.getElementById('pilihTahunTarik').value;
            const btn = document.getElementById('btnEksekusiTarik');
            const ori = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menarik Data...';
            btn.disabled = true;

            fetch(`index.php?page=laporan_bendahara&ajax_action=tarik_rapb_local&bulan=${bulan}&tahun=${tahun}`)
            .then(async r => {
                const text = await r.text();
                if(!text || text.trim() === '') throw new Error("Server mengembalikan respons kosong.");
                try { return JSON.parse(text); } catch(e) { throw new Error("Format respon tidak valid: " + text.substring(0,100)); }
            })
            .then(res => {
                if(res.status === 'success') {
                    if(res.data.length === 0) { alert("Tidak ada rincian anggaran yang disahkan untuk bulan " + bulan + " tahun " + tahun + "."); } 
                    else {
                        document.getElementById('spmRows').innerHTML = ''; 
                        res.data.forEach(d => { addSpmRow(d.rincian, d.kode_akun, d.nominal); });
                        bootstrap.Modal.getInstance(document.getElementById('modalTarikAnggaran')).hide();
                    }
                } else { alert(res.msg); }
                btn.innerHTML = ori; btn.disabled = false;
            })
            .catch(e => { alert("Gagal menarik data. Error: " + e.message); btn.innerHTML = ori; btn.disabled = false; });
        }

        function validateSpm(e) {
            if(isSubmittingSPM || isLocked) { e.preventDefault(); return false; }
            const rows = document.querySelectorAll('.spm-amt');
            if(rows.length === 0) { alert("Minimal harus ada 1 baris rincian SPM."); e.preventDefault(); return false; }
            isSubmittingSPM = true; document.body.style.cursor = 'wait';
            return true;
        }

        document.addEventListener("DOMContentLoaded", function() {
            if(existingData.length > 0) { existingData.forEach(d => { addSpmRow(d.rincian, d.kode_akun, d.nominal); }); } 
            else if(!isLocked) { addSpmRow(); }
        });
    </script>
<?php 
} else { 
// ==========================================================
// RENDER: TAMPILAN HUB UTAMA (TABS)
// ==========================================================

$list_spm = [];
try {
    $q_list = $conn->query("SELECT * FROM keuangan_spm_header ORDER BY id DESC");
    if ($q_list) { $list_spm = $q_list->fetch_all(MYSQLI_ASSOC); }
} catch (Exception $e) {}
?>

<style>
    .excel-table { width: 100%; border-collapse: collapse; font-size: 13px; color: #000; }
    .excel-table th, .excel-table td { border: 1px solid #1e293b; padding: 8px 10px; vertical-align: middle; }
    .excel-table thead th { background: #f1f5f9; text-align: center; font-weight: bold; text-transform: uppercase; }
    .excel-table thead.bg-dark th { background-color: #212529 !important; color: #ffffff !important; border-color: #373b3e !important; }
    .row-group { background: #f8fafc; font-weight: 800; text-transform: uppercase; }
    .bg-summary { background: #e2e8f0; font-weight: bold; }
    .kop-excel { text-align: center; font-weight: bold; color: #000; line-height: 1.5; margin-bottom: 20px; }
    .table-responsive { border: 1px solid #1e293b; }
    .row-child-akun td { background-color: #ffffff; font-weight: bold; border-top: 1px dashed #cbd5e1; }
    .row-trx td { background-color: #ffffff; border-top: none; }
    .bullet-point { display: inline-block; width: 6px; height: 6px; background-color: #64748b; border-radius: 50%; margin-right: 8px; vertical-align: middle; }
    .no-break { page-break-inside: avoid; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4">
        <div>
            <h4 class="fw-bold text-dark mb-1"><i class="fas fa-file-signature text-primary me-2"></i>Laporan Bendahara</h4>
            <p class="text-muted small mb-0 fw-bold">Pusat Kendali Surat Perintah Membayar (SPM) dan Realisasi Anggaran Kas.</p>
        </div>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-info-circle me-2 fa-lg"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark">
        <div class="card-header bg-white border-bottom p-0">
            <ul class="nav nav-tabs nav-fill" style="border-bottom: 0;">
                <li class="nav-item">
                    <a class="nav-link py-3 fw-bold <?= $active_tab=='spm'?'active bg-light border-bottom border-primary border-3 text-primary':'text-muted' ?>" href="?page=laporan_bendahara&tab=spm"><i class="fas fa-file-invoice-dollar me-2"></i> Tab Menu SPM</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-3 fw-bold <?= $active_tab=='realisasi'?'active bg-light border-bottom border-success border-3 text-success':'text-muted' ?>" href="?page=laporan_bendahara&tab=realisasi"><i class="fas fa-chart-bar me-2"></i> Tab Realisasi SPM</a>
                </li>
            </ul>
        </div>

        <div class="card-body p-4">
            <?php if ($active_tab == 'spm'): ?>
                <!-- ================== KONTEN TAB SPM ================== -->
                <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                    <h6 class="fw-bold text-dark mb-0"><i class="fas fa-history text-muted me-2"></i>Riwayat Pembuatan SPM</h6>
                    <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                    <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="showModalSpm()">
                        <i class="fas fa-plus-circle me-2"></i>BUAT SPM
                    </button>
                    <?php endif; ?>
                </div>

                <div class="table-responsive" style="border: none;">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th class="text-start ps-3" width="220">Periode SPM</th>
                                <th class="text-start">Nama Dokumen SPM</th>
                                <th class="text-end">Total Nominal</th>
                                <th>Status</th>
                                <th class="pe-3" width="160">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($list_spm)): foreach($list_spm as $s): ?>
                                <tr>
                                    <td class="text-start ps-3 fw-bold text-dark">
                                        <div class="badge bg-light text-dark border px-3 py-2 shadow-sm">
                                            <?= date('d/m/Y', strtotime($s['tgl_mulai'])) ?> - <?= date('d/m/Y', strtotime($s['tgl_akhir'])) ?>
                                        </div>
                                    </td>
                                    <td class="text-start text-dark">
                                        <span class="fw-bold"><?= htmlspecialchars($s['nama_spm']) ?></span>
                                        <?php if($s['is_tambahan'] == 1): ?> <span class="badge bg-danger ms-2" style="font-size: 8px;">TAMBAHAN</span> <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold text-primary">Rp <?= formatRp($s['total_nominal']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $s['status']=='GENERATED'?'success':'secondary' ?> rounded-pill px-3 py-1 shadow-sm"><?= $s['status'] ?></span>
                                    </td>
                                    <td class="pe-3">
                                        <div class="btn-group btn-group-sm rounded-pill border shadow-sm bg-white overflow-hidden">
                                            <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                            <a href="index.php?page=laporan_bendahara&view=builder&id=<?= $s['id'] ?>&tab=spm" class="btn btn-white text-warning border-end" title="<?= $s['status']=='GENERATED'?'Lihat Detail':'Ubah Draft' ?>"><i class="fas <?= $s['status']=='GENERATED'?'fa-search':'fa-edit' ?>"></i></a>
                                            <?php endif; ?>
                                            <a href="print_spm.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-white text-dark border-end" title="Cetak SPM"><i class="fas fa-print"></i></a>
                                            <?php if((defined('RBAC_DEL') && RBAC_DEL && $s['status']=='DRAFT') || $is_superadmin): ?>
                                            <a href="spm_action.php?action=delete_spm&id=<?= $s['id'] ?>" onclick="return confirm('Hapus permanen dokumen SPM ini?')" class="btn btn-white text-danger" title="Hapus"><i class="fas fa-trash"></i></a>
                                            <?php else: ?>
                                            <button class="btn btn-white text-muted opacity-50" disabled><i class="fas fa-lock"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5" class="py-5 text-muted fst-italic text-center">Belum ada riwayat pembuatan SPM.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($active_tab == 'realisasi'): ?>
                <!-- ================== KONTEN TAB REALISASI SPM ================== -->
                <?php 
                    $f_bulan = $_GET['bulan'] ?? date('m');
                    $f_tahun = $_GET['tahun'] ?? date('Y');
                    $nama_bulan = ["", "JANUARI", "FEBRUARI", "MARET", "APRIL", "MEI", "JUNI", "JULI", "AGUSTUS", "SEPTEMBER", "OKTOBER", "NOVEMBER", "DESEMBER"];
                    
                    $start_d = sprintf("%04d-%02d-01 00:00:00", $f_tahun, $f_bulan);
                    $end_d = date("Y-m-t 23:59:59", strtotime($start_d));

                    $all_accs = [];
                    $q_acc = $conn->query("SELECT kode_akun, nama_akun, is_group, parent_kode FROM syifa_akun");
                    if ($q_acc) {
                        while($r = $q_acc->fetch_assoc()) { $all_accs[$r['kode_akun']] = $r; }
                    }

                    if(!function_exists('getLogicalGroup')) {
                        function getLogicalGroup($kode, $all_accs) {
                            $curr = $all_accs[$kode] ?? null;
                            if (!$curr) return ['kode' => 'LAINNYA', 'nama' => 'PENGELUARAN LAINNYA'];
                            
                            $path = [$curr];
                            $top = $curr;
                            $visited = [];
                            while (!empty($top['parent_kode']) && isset($all_accs[$top['parent_kode']])) {
                                if (in_array($top['kode_akun'], $visited)) break;
                                $visited[] = $top['kode_akun'];
                                $top = $all_accs[$top['parent_kode']];
                                array_unshift($path, $top);
                            }
                            
                            $grp = $path[0];
                            foreach($path as $node) {
                                if ($node['is_group'] == 1 && strlen($node['kode_akun']) >= 6 && substr($node['kode_akun'], -2) === '00') {
                                    $grp = $node;
                                }
                            }
                            if ($grp['kode_akun'] == $path[0]['kode_akun'] && isset($path[1])) $grp = $path[1];
                            if (isset($path[2]) && $path[2]['is_group'] == 1) $grp = $path[2];
                            
                            return ['kode' => $grp['kode_akun'], 'nama' => $grp['nama_akun']];
                        }
                    }

                    $report_data_spm = [];
                    $report_data_non_spm = [];

                    $sql_spm = "
                        SELECT d.kode_akun, SUM(d.nominal) as nominal, a.nama_akun
                        FROM keuangan_spm_detail d 
                        JOIN keuangan_spm_header h ON d.spm_id = h.id 
                        LEFT JOIN syifa_akun a ON d.kode_akun = a.kode_akun
                        WHERE h.status = 'GENERATED' AND MONTH(h.tgl_mulai) = ".(int)$f_bulan." AND YEAR(h.tgl_mulai) = ".(int)$f_tahun."
                        GROUP BY d.kode_akun, a.nama_akun
                    ";
                    $spm_items = $conn->query($sql_spm);
                    
                    $mapped_spm_coas = [];

                    if ($spm_items && $spm_items->num_rows > 0) {
                        while ($spm = $spm_items->fetch_assoc()) {
                            $child_kode = $spm['kode_akun'];
                            $child_nama = $spm['nama_akun'] ?? 'Akun '.$child_kode;
                            $nom_anggaran = (double)$spm['nominal'];
                            
                            $mapped_spm_coas[] = $child_kode;
                            
                            $grp = getLogicalGroup($child_kode, $all_accs);
                            $top_kode = $grp['kode'];
                            $top_nama = strtoupper($grp['nama']);
                            
                            if (!isset($report_data_spm[$top_kode])) {
                                $report_data_spm[$top_kode] = ['nama' => $top_nama, 'anggaran' => 0, 'realisasi' => 0, 'children' => []];
                            }
                            if (!isset($report_data_spm[$top_kode]['children'][$child_kode])) {
                                $report_data_spm[$top_kode]['children'][$child_kode] = ['nama' => $child_nama, 'anggaran' => 0, 'realisasi' => 0, 'trx' => []];
                            }
                            $report_data_spm[$top_kode]['children'][$child_kode]['anggaran'] += $nom_anggaran;
                            $report_data_spm[$top_kode]['anggaran'] += $nom_anggaran;
                        }
                    }

                    $sql_jurnal = "
                        SELECT jd.kode_akun, a.nama_akun, j.keterangan as uraian_manual, j.tgl_jurnal, DAY(j.tgl_jurnal) as tgl_hari, SUM(jd.debit - jd.kredit) as real_val, j.no_jurnal 
                        FROM syifa_jurnal_detail jd 
                        JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                        JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
                        WHERE j.tgl_jurnal BETWEEN '$start_d' AND '$end_d'
                        AND j.is_deleted = 0
                        AND a.kategori IN ('Beban', 'Pengeluaran')
                        AND a.nama_akun NOT LIKE '%Penyusutan%' AND a.nama_akun NOT LIKE '%Amortisasi%' AND a.nama_akun NOT LIKE '%Depresiasi%'
                        AND EXISTS (
                            SELECT 1 FROM syifa_jurnal_detail jdx JOIN syifa_akun ax ON jdx.kode_akun = ax.kode_akun
                            WHERE jdx.jurnal_id = j.id AND jdx.kredit > 0 AND (ax.kategori IN ('Kas', 'Bank') OR ax.kode_akun LIKE '1-11%' OR ax.is_cash_account = 1)
                        )
                        GROUP BY j.id, jd.kode_akun
                        HAVING real_val > 0
                    ";
                    $jurnals = $conn->query($sql_jurnal);
                    
                    if ($jurnals && $jurnals->num_rows > 0) {
                        while ($j = $jurnals->fetch_assoc()) {
                            $child_kode = $j['kode_akun'];
                            $child_nama = $j['nama_akun'] ?? 'Akun '.$child_kode;
                            $nom_real = (double)$j['real_val'];
                            $j['tgl_hari'] = $j['tgl_hari'] . " " . substr($nama_bulan[(int)$f_bulan], 0, 3);
                            
                            $grp = getLogicalGroup($child_kode, $all_accs);
                            $top_kode = $grp['kode'];
                            $top_nama = strtoupper($grp['nama']);
                            
                            if (in_array($child_kode, $mapped_spm_coas)) {
                                $report_data_spm[$top_kode]['children'][$child_kode]['realisasi'] += $nom_real;
                                $report_data_spm[$top_kode]['children'][$child_kode]['trx'][] = $j;
                                $report_data_spm[$top_kode]['realisasi'] += $nom_real;
                            } else {
                                if (!isset($report_data_non_spm[$top_kode])) {
                                    $report_data_non_spm[$top_kode] = ['nama' => $top_nama, 'anggaran' => 0, 'realisasi' => 0, 'children' => []];
                                }
                                if (!isset($report_data_non_spm[$top_kode]['children'][$child_kode])) {
                                    $report_data_non_spm[$top_kode]['children'][$child_kode] = ['nama' => $child_nama, 'anggaran' => 0, 'realisasi' => 0, 'trx' => []];
                                }
                                $report_data_non_spm[$top_kode]['children'][$child_kode]['realisasi'] += $nom_real;
                                $report_data_non_spm[$top_kode]['children'][$child_kode]['trx'][] = $j;
                                $report_data_non_spm[$top_kode]['realisasi'] += $nom_real;
                            }
                        }
                    }

                    ksort($report_data_spm);
                    ksort($report_data_non_spm);

                    $sql_kas = "SELECT a.kode_akun, a.nama_akun, 
                                (a.opening_balance + COALESCE((SELECT SUM(jd.debit - jd.kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = a.kode_akun AND j.tgl_jurnal < '$start_d' AND j.is_deleted = 0), 0)) as saldo_awal,
                                COALESCE((SELECT SUM(jd.debit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = a.kode_akun AND j.tgl_jurnal BETWEEN '$start_d' AND '$end_d' AND j.is_deleted = 0), 0) as mutasi_in,
                                COALESCE((SELECT SUM(jd.kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = a.kode_akun AND j.tgl_jurnal BETWEEN '$start_d' AND '$end_d' AND j.is_deleted = 0), 0) as mutasi_out
                                FROM syifa_akun a
                                WHERE (a.kategori IN ('Kas', 'Bank') OR a.is_cash_account = 1) 
                                AND a.is_group = 0 AND a.is_active = 1
                                AND a.kode_akun NOT IN (SELECT kas_bank_akun FROM m_unit WHERE kas_bank_akun IS NOT NULL AND kas_bank_akun != '')
                                ORDER BY a.kode_akun ASC";
                    
                    $res_kas = $conn->query($sql_kas);
                    $kas_balances = [];
                    $grand_saldo_awal = 0;
                    $grand_terima = 0;
                    $grand_keluar = 0;
                    $grand_saldo_akhir = 0;

                    if($res_kas) {
                        while($rk = $res_kas->fetch_assoc()) {
                            $sa = (double)$rk['saldo_awal'];
                            $in = (double)$rk['mutasi_in'];
                            $out = (double)$rk['mutasi_out'];
                            $sak = $sa + $in - $out;

                            $kas_balances[] = [
                                'nama_akun' => $rk['nama_akun'],
                                'saldo_awal' => $sa,
                                'terima_dana' => $in,
                                'pengeluaran' => $out,
                                'saldo_akhir' => $sak
                            ];

                            $grand_saldo_awal += $sa;
                            $grand_terima += $in;
                            $grand_keluar += $out;
                            $grand_saldo_akhir += $sak;
                        }
                    }

                    $tot_spm_anggaran = 0; $tot_spm_realisasi = 0;
                    foreach($report_data_spm as $td) { $tot_spm_anggaran += $td['anggaran']; $tot_spm_realisasi += $td['realisasi']; }
                    
                    $tot_non_spm_realisasi = 0;
                    foreach($report_data_non_spm as $td) { $tot_non_spm_realisasi += $td['realisasi']; }
                    $tot_pengeluaran_all = $tot_spm_realisasi + $tot_non_spm_realisasi;
                ?>

                <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3 no-print">
                    <h6 class="fw-bold text-success mb-0"><i class="fas fa-file-excel text-success me-2"></i>LPJ Realisasi Anggaran Operasional</h6>
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="page" value="laporan_bendahara"><input type="hidden" name="tab" value="realisasi">
                        <select name="bulan" class="form-select form-select-sm border-0 bg-light fw-bold shadow-sm rounded-pill px-3" onchange="this.form.submit()">
                            <?php for($m=1;$m<=12;$m++) echo "<option value='".sprintf("%02d",$m)."' ".($f_bulan==$m?'selected':'').">".$nama_bulan[$m]."</option>"; ?>
                        </select>
                        <select name="tahun" class="form-select form-select-sm border-0 bg-light fw-bold shadow-sm rounded-pill px-3" onchange="this.form.submit()">
                            <?php for($y=date('Y')+1;$y>=2020;$y--) echo "<option value='$y' ".($f_tahun==$y?'selected':'').">$y</option>"; ?>
                        </select>
                        <a href="print_realisasi_spm.php?bulan=<?= sprintf("%02d",$f_bulan) ?>&tahun=<?= $f_tahun ?>" target="_blank" class="btn btn-dark btn-sm rounded-pill px-4 fw-bold shadow-sm ms-2"><i class="fas fa-print me-1"></i> Cetak</a>
                    </form>
                </div>

                <div class="kop-excel">
                    <div style="font-size: 16px;">LAPORAN PERTANGGUNGJAWABAN REALISASI ANGGARAN BELANJA OPERASIONAL</div>
                    <div style="font-size: 16px;">BULAN <?= $nama_bulan[(int)$f_bulan] ?> TAHUN <?= $f_tahun ?></div>
                    <div style="font-size: 16px;">INSTITUSI STIKES YARSI PONTIANAK</div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="excel-table">
                        <thead>
                            <tr>
                                <th width="12%">TANGGAL BAYAR</th>
                                <th colspan="2" class="text-start ps-3">RINCIAN KODE INDUK SPM & KETERANGAN MUTASI</th>
                                <th width="15%" class="text-end pe-3">JUMLAH ANGGARAN</th>
                                <th width="15%" class="text-end pe-3">JUMLAH PEMBAYARAN</th>
                                <th width="15%" class="text-end pe-3">SISA ANGGARAN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="6" class="text-center fw-bold bg-dark text-white py-2">REALISASI ANGGARAN (DALAM SPM)</td></tr>
                            <?php 
                            $alpha = 'A';
                            if(empty($report_data_spm)): ?>
                                <tr><td colspan="6" class="text-center py-4 italic text-muted">Belum ada Anggaran Belanja SPM pada bulan ini.</td></tr>
                            <?php else: 
                                foreach($report_data_spm as $root_kode => $top_data): 
                                    $grup_anggaran = $top_data['anggaran']; 
                                    $grup_realisasi = $top_data['realisasi']; 
                            ?>
                                <tr class="row-group">
                                    <td></td>
                                    <td width="3%" class="text-center"><?= $alpha ?>.</td>
                                    <td class="text-start text-primary"><?= $top_data['nama'] ?></td>
                                    <td></td><td></td><td></td>
                                </tr>
                                
                                <?php 
                                    $num = 1;
                                    foreach($top_data['children'] as $child_kode => $child_data): 
                                        $child_anggaran = $child_data['anggaran'];
                                        $child_realisasi = $child_data['realisasi'];
                                        $child_sisa = $child_anggaran - $child_realisasi;
                                ?>
                                        <tr class="row-child-akun">
                                            <td></td>
                                            <td class="text-center"><?= $num ?>.</td>
                                            <td class="text-start text-dark"><?= $child_data['nama'] ?></td>
                                            <td class="text-end pe-3 text-primary"><?= $child_anggaran > 0 ? formatRp($child_anggaran) : '-' ?></td>
                                            <td class="text-end pe-3 text-muted">-</td>
                                            <td class="text-end pe-3 text-dark"><?= formatRp($child_sisa) ?></td>
                                        </tr>
                                        
                                        <?php if(!empty($child_data['trx'])): foreach($child_data['trx'] as $trx): ?>
                                            <tr class="row-trx">
                                                <td class="text-center text-muted"><?= $trx['tgl_hari'] ?></td>
                                                <td></td>
                                                <td class="text-start text-muted ps-4"><span class="bullet-point"></span><?= htmlspecialchars($trx['uraian_manual']) ?></td>
                                                <td class="text-end pe-3 text-muted">-</td>
                                                <td class="text-end pe-3 text-danger fw-bold"><?= formatRp($trx['real_val']) ?></td>
                                                <td class="text-end pe-3 text-muted">-</td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                <?php $num++; endforeach; ?>
                                
                                <tr class="bg-summary">
                                    <td colspan="3" class="text-end pe-3">SUBTOTAL <?= $alpha ?></td>
                                    <td class="text-end pe-3 text-primary"><?= formatRp($grup_anggaran) ?></td>
                                    <td class="text-end pe-3 text-danger"><?= formatRp($grup_realisasi) ?></td>
                                    <td class="text-end pe-3"><?= formatRp($grup_anggaran - $grup_realisasi) ?></td>
                                </tr>
                            <?php $alpha++; endforeach; endif; ?>

                            <?php if(!empty($report_data_non_spm)): ?>
                                <tr><td colspan="6" style="border:none; height:20px;"></td></tr>
                                <tr><td colspan="6" class="text-center fw-bold bg-secondary text-white py-2">PENGELUARAN DILUAR SPM</td></tr>
                                <?php foreach($report_data_non_spm as $root_kode => $top_data): 
                                        $grup_realisasi = $top_data['realisasi']; 
                                ?>
                                    <tr class="row-group">
                                        <td></td>
                                        <td width="3%" class="text-center"><?= $alpha ?>.</td>
                                        <td class="text-start text-primary"><?= $top_data['nama'] ?></td>
                                        <td></td><td></td><td></td>
                                    </tr>
                                    
                                    <?php 
                                        $num = 1;
                                        foreach($top_data['children'] as $child_kode => $child_data): 
                                            $child_realisasi = $child_data['realisasi'];
                                    ?>
                                            <tr class="row-child-akun">
                                                <td></td>
                                                <td class="text-center"><?= $num ?>.</td>
                                                <td class="text-start text-dark"><?= $child_data['nama'] ?></td>
                                                <td class="text-end pe-3 text-muted">-</td>
                                                <td class="text-end pe-3 text-muted">-</td>
                                                <td class="text-end pe-3 text-danger">- <?= formatRp($child_realisasi) ?></td>
                                            </tr>
                                            <?php foreach($child_data['trx'] as $trx): ?>
                                                <tr class="row-trx">
                                                    <td class="text-center text-muted"><?= $trx['tgl_hari'] ?></td>
                                                    <td></td>
                                                    <td class="text-start text-muted ps-4"><span class="bullet-point"></span><?= htmlspecialchars($trx['uraian_manual']) ?></td>
                                                    <td class="text-end pe-3 text-muted">-</td>
                                                    <td class="text-end pe-3 text-danger fw-bold"><?= formatRp($trx['real_val']) ?></td>
                                                    <td class="text-end pe-3 text-muted">-</td>
                                                </tr>
                                            <?php endforeach; ?>
                                    <?php $num++; endforeach; ?>
                                    
                                    <tr class="bg-summary">
                                        <td colspan="3" class="text-end pe-3">SUBTOTAL <?= $alpha ?></td>
                                        <td class="text-end pe-3 text-muted">-</td>
                                        <td class="text-end pe-3 text-danger"><?= formatRp($grup_realisasi) ?></td>
                                        <td class="text-end pe-3 text-danger">- <?= formatRp($grup_realisasi) ?></td>
                                    </tr>
                                <?php $alpha++; endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        
                        <tfoot class="bg-white">
                            <tr><td colspan="6" style="border:none; height:15px;"></td></tr>
                            <tr class="bg-summary">
                                <td colspan="3" class="text-end pe-3">TOTAL KESELURUHAN (ANGGARAN VS REALISASI) :</td>
                                <td class="text-end pe-3 text-primary fs-6">Rp <?= formatRp($tot_spm_anggaran) ?></td>
                                <td class="text-end pe-3 text-danger fs-6">Rp <?= formatRp($tot_pengeluaran_all) ?></td>
                                <td class="text-end pe-3 text-dark fs-6">Rp <?= formatRp($tot_spm_anggaran - $tot_pengeluaran_all) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 🚀 TABEL BARU: REKAPITULASI KAS & BANK PUSAT -->
                <div class="mt-5 mb-4 no-break">
                    <h6 class="fw-bold text-dark mb-3 text-uppercase"><i class="fas fa-university text-primary me-2"></i>Rekapitulasi Saldo Kas & Bank Pusat</h6>
                    <div class="table-responsive">
                        <table class="excel-table">
                            <thead class="bg-dark text-white text-center">
                                <tr>
                                    <th class="text-start ps-3" width="40%" style="background-color: #212529 !important; color: #ffffff !important;">NAMA AKUN KAS DAN BANK (DILUAR KAS UNIT)</th>
                                    <th class="text-end pe-3" width="15%" style="background-color: #212529 !important; color: #ffffff !important;">SALDO AWAL</th>
                                    <th class="text-end pe-3" width="15%" style="background-color: #212529 !important; color: #ffffff !important;">TERIMA DANA</th>
                                    <th class="text-end pe-3" width="15%" style="background-color: #212529 !important; color: #ffffff !important;">PENGELUARAN</th>
                                    <th class="text-end pe-3" width="15%" style="background-color: #212529 !important; color: #ffffff !important;">SALDO AKHIR</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($kas_balances)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted italic">Tidak ada data rekening Kas/Bank pusat.</td></tr>
                                <?php else: foreach($kas_balances as $kb): ?>
                                    <tr>
                                        <td class="text-start ps-3 fw-bold text-dark"><?= htmlspecialchars($kb['nama_akun']) ?></td>
                                        <td class="text-end pe-3 text-primary"><?= formatRp($kb['saldo_awal']) ?></td>
                                        <td class="text-end pe-3 text-success"><?= formatRp($kb['terima_dana']) ?></td>
                                        <td class="text-end pe-3 text-danger"><?= formatRp($kb['pengeluaran']) ?></td>
                                        <td class="text-end pe-3 fw-bold text-dark"><?= formatRp($kb['saldo_akhir']) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                            <tfoot class="bg-summary">
                                <tr>
                                    <td class="text-end pe-3 fw-bold">TOTAL KESELURUHAN KAS & BANK</td>
                                    <td class="text-end pe-3 text-primary fw-bold fs-6">Rp <?= formatRp($grand_saldo_awal) ?></td>
                                    <td class="text-end pe-3 text-success fw-bold fs-6">Rp <?= formatRp($grand_terima) ?></td>
                                    <td class="text-end pe-3 text-danger fw-bold fs-6">Rp <?= formatRp($grand_keluar) ?></td>
                                    <td class="text-end pe-3 text-dark fw-bold fs-6">Rp <?= formatRp($grand_saldo_akhir) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<!-- MODAL BUAT SPM BARU -->
<div class="modal fade" id="modalBuatSpm" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="spm_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" onsubmit="preventDoubleSubmit(this)">
            <input type="hidden" name="action" value="init_spm">
            <input type="hidden" name="idempotency_key" value="<?= $idempotent_key ?>">
            
            <div class="modal-header bg-primary text-white p-4 border-0">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-file-signature me-2"></i>Inisialisasi Dokumen SPM</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Nama / Judul SPM</label>
                    <input type="text" name="nama_spm" class="form-control rounded-pill border-0 shadow-sm px-4 fw-bold text-dark" placeholder="Contoh: SPM Rutin Bulan Mei 2026" required>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Dari Tanggal</label>
                        <input type="date" name="tgl_mulai" class="form-control rounded-pill border-0 shadow-sm px-4 text-muted" value="<?= date('Y-m-01') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Sampai Tanggal</label>
                        <input type="date" name="tgl_akhir" class="form-control rounded-pill border-0 shadow-sm px-4 text-muted" value="<?= date('Y-m-t') ?>" required>
                    </div>
                </div>
                <div class="p-3 bg-white border border-danger border-opacity-25 rounded-4 shadow-sm text-center">
                    <div class="form-check form-switch d-inline-block m-0">
                        <input class="form-check-input border-danger" type="checkbox" name="is_tambahan" id="chkTambahan" value="1">
                        <label class="form-check-label small fw-bold text-danger ms-2" for="chkTambahan">Tandai Sebagai SPM Tambahan</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-white d-flex justify-content-between">
                <button type="button" class="btn-light btn rounded-pill px-4 fw-bold shadow-sm" data-bs-dismiss="modal">Batalkan</button>
                <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow text-uppercase btn-submit-shield">Susun SPM <i class="fas fa-arrow-right ms-2"></i></button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const savedTab = localStorage.getItem('activeSpmTab');
    const urlParams = new URLSearchParams(window.location.search);
    if (!urlParams.has('tab') && savedTab) {
        const tabLink = document.querySelector(`.nav-link[href="?page=laporan_bendahara&tab=${savedTab}"]`);
        if (tabLink) window.location.href = tabLink.href; 
    }

    document.querySelectorAll('.nav-link[href^="?page=laporan_bendahara&tab="]').forEach(link => {
        link.addEventListener('click', function(e) {
            const tabName = new URL(this.href).searchParams.get('tab');
            localStorage.setItem('activeSpmTab', tabName);
        });
    });
});

function showModalSpm() { new bootstrap.Modal(document.getElementById('modalBuatSpm')).show(); }

let isSubmitting = false;
function preventDoubleSubmit(form) {
    if(isSubmitting) return false;
    isSubmitting = true;
    const btn = form.querySelector('.btn-submit-shield');
    if(btn) {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...';
        btn.classList.add('disabled');
    }
    return true;
}
</script>

<style>
    .btn-white { background: #fff; border: none; transition: 0.2s; } 
    .btn-white:hover { background: #f8fafc; color: #0d6efd !important; }
    .table-hover tbody tr:hover { background-color: #f8fafc !important; }
    .no-break { page-break-inside: avoid; }
    @media print {
        body { background: #fff; margin: 0; padding: 0; }
        .no-print { display: none !important; }
        .card, .container-fluid { padding: 0 !important; margin: 0 !important; box-shadow: none !important; border: none !important; }
        .excel-table { font-size: 11pt !important; }
        .excel-table th, .excel-table td { border: 1px solid #000 !important; color: #000 !important; }
        .kop-excel { margin-top: 15px; }
        .no-break { page-break-inside: avoid; }
    }
</style>
<?php } ?>
<!-- Global Floating Dropdown Container at Body Level -->
<div id="globalCoaDropdown" style="display: none; position: absolute; z-index: 999999;"></div>