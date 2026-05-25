<?php
/**
 * akun_default.php - MODUL MAPPING AKUN OTOMATIS (DECOUPLED)
 * Versi: 23.0 (Grand Master Modular Edition)
 * Deskripsi: Menangani UI Pemetaan COA, Searchable Input, dan Metadata Management.
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// 1. Ambil data master untuk pencarian COA
$all_coa = $conn->query("SELECT id, kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

// 2. Ambil data pemetaan kategori akun default saat ini
$defaults = $conn->query("SELECT sd.*, a.kode_akun, a.nama_akun 
                         FROM setting_akun_default sd 
                         LEFT JOIN syifa_akun a ON sd.coa_id = a.id 
                         ORDER BY sd.kategori ASC, sd.id ASC");
$categories = [];
while($d = $defaults->fetch_assoc()) { $categories[$d['kategori']][] = $d; }
?>

<div class="animate__animated animate__fadeIn">
    <!-- INFORMASI HEADER MODUL -->
    <div class="alert alert-info rounded-4 border-0 shadow-sm mb-4 d-flex align-items-center">
        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
            <i class="fas fa-magic fa-lg"></i>
        </div>
        <div>
            <h6 class="fw-bold mb-0">Mapping Akun Otomatis (Smart Mapping)</h6>
            <p class="mb-0 small text-dark">Ketik nama atau kode akun untuk menghubungkan aktivitas sistem ke Buku Besar secara otomatis.</p>
        </div>
    </div>

    <!-- FORM BATCH UPDATE MAPPING -->
    <form method="POST" action="settings.php">
        <input type="hidden" name="action" value="save_default_accounts">
        
        <?php if(empty($categories)): ?>
            <div class="text-center py-5 text-muted bg-light rounded-4 border border-dashed">
                <i class="fas fa-folder-open fa-3x mb-3 opacity-25"></i>
                <h5>Belum ada kategori pengaturan.</h5>
                <p class="small">Klik tombol "+ TAMBAH JENIS" di atas untuk memulai.</p>
            </div>
        <?php else: foreach($categories as $cat => $items): ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden bg-white">
                <div class="card-header bg-light fw-bold text-dark py-3 px-4 border-0 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-tags me-2 text-primary"></i><?= strtoupper($cat) ?></span>
                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3" style="font-size: 10px;"><?= count($items) ?> Item Konfigurasi</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-white small text-uppercase fw-bold text-muted">
                                <tr>
                                    <th class="ps-4" width="85">Opsi</th>
                                    <th width="240">Jenis Pengaturan</th>
                                    <th>Pilih Akun COA Target</th>
                                    <th class="pe-4">Keterangan Fungsi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($items as $item): 
                                    $display_val = $item['coa_id'] ? "{$item['kode_akun']} - {$item['nama_akun']}" : "";
                                    $json_item = htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex gap-1">
                                            <button type="button" class="btn btn-sm btn-light text-warning rounded-pill border" onclick='editDefaultSetting(<?= $json_item ?>)' title="Ubah Nama/Key"><i class="fas fa-edit"></i></button>
                                            <button type="button" class="btn btn-sm btn-light text-danger rounded-pill border" onclick="deleteDefaultSetting(<?= $item['id'] ?>)" title="Hapus Pengaturan"><i class="fas fa-trash-alt"></i></button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= $item['nama_setting'] ?></div>
                                        <code class="small" style="font-size:9px; color:#999;">KEY: <?= $item['kode_setting'] ?></code>
                                    </td>
                                    <td>
                                        <div class="coa-search-container">
                                            <input type="hidden" name="def_coa[<?= $item['kode_setting'] ?>]" id="val_<?= $item['id'] ?>" value="<?= $item['coa_id'] ?>">
                                            <div class="input-group input-group-sm rounded-pill overflow-hidden border shadow-none bg-white">
                                                <span class="input-group-text border-0 bg-transparent ps-3"><i class="fas fa-search text-muted small"></i></span>
                                                <input type="text" 
                                                       class="form-control border-0 shadow-none fw-bold text-primary coa-search-input" 
                                                       placeholder="Ketik nama akun..." 
                                                       value="<?= htmlspecialchars($display_val) ?>"
                                                       autocomplete="off"
                                                       data-id="<?= $item['id'] ?>"
                                                       oninput="searchCOA(this)"
                                                       onfocus="searchCOA(this)">
                                            </div>
                                            <div id="results_<?= $item['id'] ?>" class="coa-results d-none"></div>
                                        </div>
                                    </td>
                                    <td class="pe-4 small text-muted italic" style="font-size: 0.78rem;"><?= $item['keterangan'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>

        <?php if(!empty($categories)): ?>
            <div class="d-flex justify-content-end gap-2 mt-4 pb-5">
                <button type="reset" class="btn btn-light rounded-pill px-5 fw-bold border">BATALKAN</button>
                <button type="submit" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg">
                    <i class="fas fa-check-double me-2"></i>SIMPAN & TERAPKAN PEMETAAN
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- MODAL TAMBAH/UBAH METADATA -->
<div class="modal fade" id="modalAddDefault" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form action="settings.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_default_setting_meta">
            <input type="hidden" name="id" id="mdl_def_id">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold" id="mdl_def_title">Registrasi Akun Default Baru</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3">
                    <label class="small fw-bold mb-1">KODE SYSTEM (KEY)</label>
                    <input type="text" name="kode_setting" id="mdl_def_kode" class="form-control border-0 shadow-sm fw-bold text-primary" placeholder="E.g: PEND_DENDA" required>
                    <small class="text-muted" style="font-size:9px;">Hanya huruf besar dan underscore. Digunakan oleh sistem backend.</small>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold mb-1">NAMA PENGATURAN</label>
                    <input type="text" name="nama_setting" id="mdl_def_nama" class="form-control border-0 shadow-sm" placeholder="E.g: Pendapatan Denda Mahasiswa" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold mb-1">KATEGORI</label>
                    <select name="kategori" id="mdl_def_kat" class="form-select border-0 shadow-sm" required>
                        <option value="Kas & Bank">Kas & Bank</option>
                        <option value="Piutang & Pendapatan">Piutang & Pendapatan</option>
                        <option value="Beban">Beban</option>
                        <option value="Kewajiban">Kewajiban</option>
                        <option value="Ekuitas">Ekuitas</option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="small fw-bold mb-1">KETERANGAN PENGGUNAAN</label>
                    <textarea name="keterangan" id="mdl_def_desc" class="form-control border-0 shadow-sm" rows="2" placeholder="Fungsi akun ini di jurnal otomatis..."></textarea>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-light">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">SIMPAN IDENTITAS PENGATURAN</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL TUTORIAL -->
<div class="modal fade" id="modalTutorial" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-info text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-graduation-cap me-2"></i>Tutorial: Cara Kerja Akun Default</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white text-dark">
                <h6 class="fw-bold text-primary mb-3">1. Apa Itu Akun Default?</h6>
                <p class="small">Akun Default adalah instruksi permanen bagi sistem untuk membuat jurnal otomatis. Tanpa mapping, sistem tidak tahu ke mana angka nominal transaksi harus dicatat di Buku Besar.</p>
                <h6 class="fw-bold text-primary mb-3 mt-4">2. Contoh Penerapan</h6>
                <div class="bg-light p-3 rounded-4 border mb-2">
                    <p class="mb-1 small fw-bold text-success">Skenario: Bayar Listrik via Kasir</p>
                    <ul class="small mb-0">
                        <li>User pilih kategori: <b>"Beban Operasional"</b>.</li>
                        <li>Sistem menarik akun <code>5xxx</code> yang sudah dipasangkan di sini.</li>
                        <li><b>Jurnal:</b> (D) Beban Operasional | (K) Kas Utama.</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold" data-bs-dismiss="modal">SAYA MENGERTI</button>
            </div>
        </div>
    </div>
</div>

<script>
// Data Master COA untuk Autocomplete
const coaMaster = <?= json_encode($all_coa ?? []) ?>;

function showModalAddDefault() { 
    document.getElementById('mdl_def_id').value = '';
    document.getElementById('mdl_def_title').innerText = 'Registrasi Akun Default Baru';
    document.getElementById('mdl_def_kode').value = '';
    document.getElementById('mdl_def_nama').value = '';
    document.getElementById('mdl_def_desc').value = '';
    new bootstrap.Modal(document.getElementById('modalAddDefault')).show(); 
}

function editDefaultSetting(data) {
    document.getElementById('mdl_def_id').value = data.id;
    document.getElementById('mdl_def_title').innerText = 'Ubah Identitas Pengaturan';
    document.getElementById('mdl_def_kode').value = data.kode_setting;
    document.getElementById('mdl_def_nama').value = data.nama_setting;
    document.getElementById('mdl_def_kat').value = data.kategori;
    document.getElementById('mdl_def_desc').value = data.keterangan;
    new bootstrap.Modal(document.getElementById('modalAddDefault')).show();
}

function deleteDefaultSetting(id) {
    if(confirm('HAPUS PENGATURAN: Menghapus baris ini akan menghilangkan kunci otomatisasi terkait. Lanjutkan?')) {
        const f = document.createElement('form'); f.method='POST'; f.action='settings.php';
        f.innerHTML = `<input type="hidden" name="action" value="delete_default_setting"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f); f.submit();
    }
}

function showTutorial() { new bootstrap.Modal(document.getElementById('modalTutorial')).show(); }

function searchCOA(el) {
    const query = el.value.toLowerCase();
    const settingId = el.getAttribute('data-id');
    const resultBox = document.getElementById('results_' + settingId);
    const filtered = coaMaster.filter(c => c.kode_akun.toLowerCase().includes(query) || c.nama_akun.toLowerCase().includes(query)).slice(0, 12);

    if (filtered.length > 0) {
        let html = '';
        filtered.forEach(item => {
            html += `<div class="coa-res-item" onclick="selectCOA(${settingId}, ${item.id}, '${item.kode_akun}', '${item.nama_akun}')">
                        <code>${item.kode_akun}</code> ${item.nama_akun}
                     </div>`;
        });
        resultBox.innerHTML = html;
        resultBox.classList.remove('d-none');
    } else {
        resultBox.innerHTML = '<div class="p-3 text-center small text-muted">Akun tidak ditemukan.</div>';
        resultBox.classList.remove('d-none');
    }
}

function selectCOA(settingId, coaId, code, name) {
    document.getElementById('val_' + settingId).value = coaId;
    document.querySelector(`input[data-id="${settingId}"]`).value = code + ' - ' + name;
    document.getElementById('results_' + settingId).classList.add('d-none');
}

document.addEventListener('click', (e) => { if (!e.target.classList.contains('coa-search-input')) { document.querySelectorAll('.coa-results').forEach(box => box.classList.add('d-none')); } });
</script>

<style>
    .coa-search-container { position: relative; }
    .coa-results { position: absolute; top: 100%; left: 0; right: 0; z-index: 1060; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; margin-top: 5px; }
    .coa-res-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: 0.2s; font-size: 0.82rem; color: #333; }
    .coa-res-item:hover { background: #f0f9ff; color: #0d6efd; }
    .coa-res-item code { color: #64748b; font-weight: bold; margin-right: 8px; }
</style>