<?php
/**
 * honorarium_komponen.php - TAB 2: MANAJEMEN KOMPONEN HONORARIUM
 * Perbaikan Mutlak: 
 * Mengganti logika Regex Format Rupiah pada JS. Hanya menghapus ".00"
 * di bagian EKOR (End of String) yang berasal murni dari Database MySQL.
 * Angka ketikan manusia (seperti 10.000) aman tanpa terpotong!
 */
$komponen_data = [];
$res_komp = $conn->query("SELECT * FROM honor_komponen ORDER BY id DESC");
if ($res_komp) { 
    while($r = $res_komp->fetch_assoc()) {
        $details = $conn->query("SELECT * FROM honor_komponen_detail WHERE komponen_id={$r['id']} ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
        $r['details'] = $details;
        $komponen_data[] = $r;
    }
}

$coa_beban_list = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE (kategori='Beban' OR kode_akun LIKE '5-%') AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
?>
<style>
    .comp-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; height: 100%; display: flex; flex-direction: column; transition: 0.3s; }
    .comp-card:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(13, 110, 253, 0.1); border-color: var(--bs-primary); }
    .comp-header { padding: 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start; }
    .comp-code { background-color: #fef9c3; color: #854d0e; font-weight: 800; font-size: 11px; padding: 5px 12px; border-radius: 20px; border: 1px solid #fef08a; letter-spacing: 0.5px;}
    .comp-body { padding: 20px; flex-grow: 1; }
    .comp-title { font-size: 16px; font-weight: 800; color: var(--bs-primary); margin-bottom: 10px; line-height: 1.3; }
    .comp-desc { font-size: 13px; color: #64748b; margin-bottom: 15px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .comp-meta { display: flex; align-items: center; gap: 15px; font-size: 12px; font-weight: 700; }
    .comp-footer { padding: 15px 20px; background: #f8fafc; border-top: 1px solid #f1f5f9; border-radius: 0 0 16px 16px; display: flex; gap: 8px; justify-content: center; }
    .table-rincian th { background-color: #1e293b !important; color: #ffffff !important; font-size: 11px; font-weight: 800; text-transform: uppercase; border: none; }
    .table-rincian td { vertical-align: middle; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
</style>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-1 text-dark">Komponen Standar Biaya Honor</h5>
            <p class="text-muted small mb-0">Kelola template tarif honor dan mapping COA Akuntansi.</p>
        </div>
        <button class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold" onclick="openModalKomp()"><i class="fas fa-plus me-2"></i>Tambah Komponen</button>
    </div>

    <div class="row g-4">
        <?php if(empty($komponen_data)): ?>
            <div class="col-12 text-center py-5 text-muted fst-italic">
                <i class="fas fa-layer-group fa-3x opacity-25 mb-3 d-block"></i>Belum ada komponen tarif yang dibuat.
            </div>
        <?php else: foreach($komponen_data as $k): ?>
        <div class="col-md-6 col-lg-4">
            <div class="comp-card">
                <div class="comp-header">
                    <?php if($k['is_active'] == 1): ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary rounded-pill px-3 py-1 fw-bold"><i class="fas fa-check-circle me-1"></i> Aktif</span>
                    <?php else: ?>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary rounded-pill px-3 py-1 fw-bold"><i class="fas fa-times-circle me-1"></i> Non Aktif</span>
                    <?php endif; ?>
                    <div class="comp-code"><i class="fas fa-tag me-1"></i> <?= $k['kode_honor'] ?></div>
                </div>
                <div class="comp-body">
                    <div class="comp-title"><?= $k['nama_honor'] ?></div>
                    <div class="comp-desc"><?= $k['deskripsi'] ?></div>
                    <div class="comp-meta">
                        <span class="text-primary"><i class="fas fa-list-ul me-1"></i> <?= count($k['details']) ?> Rincian Tarif</span>
                        <?php if($k['is_jafung'] == 1): ?>
                        <span class="text-warning ms-2" title="Berdasarkan Jabatan Fungsional"><i class="fas fa-user-tie"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="comp-footer">
                    <button type="button" class="btn btn-sm btn-light border fw-bold text-info px-4 w-100" onclick='viewDetail(<?= json_encode($k, JSON_HEX_APOS) ?>)'><i class="fas fa-eye"></i></button>
                    <button type="button" class="btn btn-sm btn-light border fw-bold text-warning px-4 w-100" onclick='editKomponen(<?= json_encode($k, JSON_HEX_APOS) ?>)'><i class="fas fa-edit"></i></button>
                    <button type="button" class="btn btn-sm btn-light border fw-bold text-danger px-4 w-100" onclick="deleteKomponen(<?= $k['id'] ?>, '<?= addslashes($k['nama_honor']) ?>')"><i class="fas fa-trash"></i></button>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="modal fade" id="modalKomponen" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form action="javascript:void(0);" id="formKomponen" onsubmit="handleSaveKomp(event)" class="modal-content text-dark border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" id="formActionKomp" value="save_komp">
            <input type="hidden" name="id" id="compId">
            <div class="modal-header p-4 bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="modalTitleKomp"><i class="fas fa-layer-group me-2 text-warning"></i>Tambah Komponen Honor Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="bg-white p-4 rounded-4 border shadow-sm mb-4">
                    <h6 class="fw-bold border-bottom pb-2 mb-3 text-primary">A. Informasi Umum Komponen</h6>
                    <div class="row g-3 align-items-center">
                        <div class="col-md-3"><label class="form-label small fw-bold text-muted">Kode Honor <span class="text-danger">*</span></label><input type="text" name="kode_honor" id="inpKode" class="form-control rounded-3 fw-bold text-primary bg-light" required readonly></div>
                        <div class="col-md-5"><label class="form-label small fw-bold text-muted">Nama Honor <span class="text-danger">*</span></label><input type="text" name="nama_honor" id="inpNamaKomp" class="form-control rounded-3 fw-bold" required placeholder="Contoh: Honor Dosen Pengampu..."></div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted d-block text-center">Beda Jabatan?</label>
                            <div class="form-check form-switch d-flex justify-content-center mt-2">
                                <input class="form-check-input border-secondary shadow-sm" type="checkbox" name="is_jafung" id="inpIsJafung" value="1" style="transform: scale(1.5);" onchange="toggleJabfungCol()">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted d-block text-center">Status Aktif</label>
                            <div class="form-check form-switch d-flex justify-content-center mt-2">
                                <input class="form-check-input border-secondary shadow-sm" type="checkbox" name="is_active" id="inpStatusKomp" value="1" checked style="transform: scale(1.5);">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-danger mb-1"><i class="fas fa-link me-1"></i> Sinkronisasi ke Buku Besar (Akun Beban) <span class="text-danger">*</span></label>
                            <select name="kode_akun_beban" id="inpCoaBeban" class="form-select rounded-3 border-danger shadow-sm fw-bold" required>
                                <option value="">-- Pilih Akun Beban (Wajib) --</option>
                                <?php foreach($coa_beban_list as $c) echo "<option value='{$c['kode_akun']}'>{$c['kode_akun']} - {$c['nama_akun']}</option>"; ?>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label small fw-bold text-muted">Deskripsi Kegunaan</label><textarea name="deskripsi" id="inpDescKomp" class="form-control rounded-3" rows="2" placeholder="Jelaskan peruntukan honor ini..."></textarea></div>
                    </div>
                </div>

                <div class="bg-white p-4 rounded-4 border shadow-sm">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                        <h6 class="fw-bold mb-0 text-primary">B. Rincian Tarif Dasar (Template)</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-3" onclick="addRowKomp()"><i class="fas fa-plus me-1"></i> Tambah Baris</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-rincian text-center mb-0" id="tblRincian">
                            <thead><tr>
                                <th width="5%" class="text-center">No</th>
                                <th width="25%" class="text-start">Nama Rincian Pekerjaan</th>
                                <th width="15%" class="col-jabfung" style="display:none;">Jabatan Fungsional</th>
                                <th width="15%">Satuan Pengali</th>
                                <th width="12%">Pajak (%)</th>
                                <th width="18%" class="text-end pe-3">Besaran Tarif (Rp)</th>
                                <th width="10%" class="text-center">Aksi</th>
                            </tr></thead>
                            <tbody id="rincianBody"></tbody>
                        </table>
                    </div>
                    <div class="mt-3 p-3 bg-light rounded-3 border d-flex justify-content-between align-items-center">
                        <span class="small fw-bold text-muted"><i class="fas fa-calculator me-1"></i> Kalkulator Preview (Asumsi Qty = 1):</span>
                        <h5 class="fw-bold text-success mb-0" id="previewTotal">Rp 0</h5>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-4 bg-white border-0 d-block text-center">
                <div class="row g-2">
                    <div class="col-6"><button type="button" class="btn btn-light w-100 rounded-pill py-3 fw-bold border shadow-sm" data-bs-dismiss="modal">BATALKAN</button></div>
                    <div class="col-6"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow" id="btnSubmitKomp"><i class="fas fa-save me-2"></i>SIMPAN STANDAR BIAYA</button></div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalView" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-eye me-2 text-warning"></i>Detail Komponen Honor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 bg-light"><div id="viewContainer" class="p-4 text-dark"></div></div>
            <div class="modal-footer p-3 bg-white border-0"><button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold w-100 shadow-sm" data-bs-dismiss="modal">TUTUP DETAIL</button></div>
        </div>
    </div>
</div>

<script>
    const satOptions = ['Per SKS','Per Pertemuan','Per Mahasiswa','Per Kegiatan','Per Jam','Per Soal','Lump Sum'];
    let rowCountKomp = 0;

    document.addEventListener("DOMContentLoaded", () => {
        document.body.appendChild(document.getElementById('modalKomponen'));
        document.body.appendChild(document.getElementById('modalView'));
    });

    // 🚀 FIX BUG > 10.000 JADI 0 
    function formatRpJS(val) { 
        if(!val) return ''; 
        let strVal = val.toString();
        // HANYA hapus desimal .00 dari MYSQL, jangan pangkas titik dari ketikan user (Ribuan)
        if (strVal.match(/\.00$/)) { strVal = strVal.replace(/\.00$/, ''); }
        
        let clean = strVal.replace(/[^0-9]/g, ''); 
        return clean ? new Intl.NumberFormat('id-ID').format(clean) : ''; 
    }
    function cleanRp(val) { return parseFloat(val.toString().replace(/[^0-9]/g, '')) || 0; }
    function maskRp(input) { input.value = formatRpJS(input.value); calcPreview(); }

    function addRowKomp(data = null) {
        rowCountKomp++;
        let rincian = data ? data.rincian : ''; let satuan = data ? data.satuan : 'Per SKS'; let pajak = data ? data.potongan_pajak : '0'; let besaran = data ? formatRpJS(data.besaran) : '';
        let jafung = data ? (data.jabatan_fungsional || '') : '';
        
        let optHtml = ''; satOptions.forEach(opt => { optHtml += `<option value="${opt}" ${opt === satuan ? 'selected' : ''}>${opt}</option>`; });
        let jfOpts = '<option value="">Semua Jabatan</option>';
        ['Tenaga Pengajar', 'Asisten Ahli', 'Lektor', 'Lektor Kepala', 'Profesor'].forEach(opt => { jfOpts += `<option value="${opt}" ${opt===jafung?'selected':''}>${opt}</option>`; });

        let isJafungChecked = document.getElementById('inpIsJafung').checked;
        let displayJafung = isJafungChecked ? 'table-cell' : 'none';

        let html = `
        <tr id="rowk_${rowCountKomp}" class="row-rincian">
            <td class="fw-bold align-middle text-center row-numk">${rowCountKomp}</td>
            <td class="text-start"><input type="text" name="rincian[]" class="form-control form-control-sm rounded-3 fw-bold text-dark" value="${rincian}" required placeholder="Nama rincian..."></td>
            <td class="col-jabfung" style="display:${displayJafung};"><select name="jafung[]" class="form-select form-select-sm fw-bold text-dark rounded-3">${jfOpts}</select></td>
            <td><select name="satuan[]" class="form-select form-select-sm rounded-3 fw-bold text-dark">${optHtml}</select></td>
            <td><input type="number" name="pajak[]" class="form-control form-control-sm rounded-3 text-center fw-bold text-danger inp-pjk" value="${pajak}" min="0" max="100" step="0.01" required onchange="calcPreview()"></td>
            <td><div class="input-group input-group-sm"><span class="input-group-text bg-light border fw-bold text-muted rounded-start">Rp</span><input type="text" name="besaran[]" class="form-control border rounded-end fw-bold text-end text-success inp-besaran" value="${besaran}" required onkeyup="maskRp(this)"></div></td>
            <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm rounded-circle shadow-sm" onclick="delRowKomp(${rowCountKomp})" title="Hapus Baris"><i class="fas fa-times"></i></button></td>
        </tr>`;
        document.getElementById('rincianBody').insertAdjacentHTML('beforeend', html);
        updateRowNumbersKomp(); calcPreview();
    }

    function toggleJabfungCol() {
        let isJafung = document.getElementById('inpIsJafung').checked;
        document.querySelectorAll('.col-jabfung').forEach(el => { el.style.display = isJafung ? 'table-cell' : 'none'; });
    }

    function delRowKomp(id) { document.getElementById(`rowk_${id}`).remove(); updateRowNumbersKomp(); calcPreview(); }
    function updateRowNumbersKomp() { let idx = 1; document.querySelectorAll('#rincianBody .row-numk').forEach(td => { td.innerText = idx++; }); }

    function calcPreview() {
        let tBruto = 0; let tPajak = 0;
        document.querySelectorAll('#rincianBody .row-rincian').forEach(row => {
            let bsr = cleanRp(row.querySelector('.inp-besaran').value); 
            let pjkPct = parseFloat(row.querySelector('.inp-pjk').value) || 0;
            tBruto += bsr; tPajak += (bsr * (pjkPct / 100));
        });
        document.getElementById('previewTotal').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(tBruto - tPajak);
    }

    function openModalKomp() {
        document.getElementById('formKomponen').reset(); document.getElementById('compId').value = ''; 
        document.getElementById('rincianBody').innerHTML = ''; rowCountKomp = 0;
        document.getElementById('inpIsJafung').checked = false; toggleJabfungCol();
        document.getElementById('inpKode').value = 'HON-' + Math.floor(Math.random() * 9000 + 1000);
        document.getElementById('modalTitleKomp').innerHTML = '<i class="fas fa-layer-group me-2 text-warning"></i>Tambah Komponen Honor Baru';
        addRowKomp(); bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKomponen')).show();
    }

    function editKomponen(d) {
        document.getElementById('compId').value = d.id; document.getElementById('inpKode').value = d.kode_honor; 
        document.getElementById('inpNamaKomp').value = d.nama_honor; document.getElementById('inpDescKomp').value = d.deskripsi; 
        document.getElementById('inpStatusKomp').checked = (d.is_active == 1);
        document.getElementById('inpIsJafung').checked = (d.is_jafung == 1);
        document.getElementById('inpCoaBeban').value = d.kode_akun_beban || '';
        
        document.getElementById('rincianBody').innerHTML = ''; rowCountKomp = 0; toggleJabfungCol();
        d.details.forEach(det => { addRowKomp(det); });
        
        document.getElementById('modalTitleKomp').innerHTML = '<i class="fas fa-edit me-2 text-warning"></i>Ubah Komponen Honor';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKomponen')).show();
    }

    function viewDetail(d) {
        let statBadge = d.is_active == 1 ? '<span class="badge bg-primary px-3 py-1 rounded-pill">AKTIF</span>' : '<span class="badge bg-secondary px-3 py-1 rounded-pill">NON AKTIF</span>';
        let jafungHdr = d.is_jafung == 1 ? '<th>Jabatan</th>' : '';
        
        let html = `<div class="text-center mb-4 border-bottom pb-3"><code class="fs-5 bg-warning bg-opacity-10 text-warning px-3 py-1 rounded-pill mb-2 d-inline-block border border-warning border-opacity-50">${d.kode_honor}</code><h4 class="fw-bold text-dark mt-2">${d.nama_honor}</h4><div class="text-muted small">${d.deskripsi}</div><div class="mt-2">${statBadge}</div></div><h6 class="fw-bold mb-3"><i class="fas fa-list text-primary me-2"></i>Tabel Rincian Tarif Baku</h6><table class="table table-bordered table-striped text-center small"><thead class="table-dark"><tr><th>No</th><th class="text-start">Uraian Pekerjaan</th>${jafungHdr}<th>Satuan</th><th>Pajak</th><th class="text-end pe-3">Besaran Tarif (Rp)</th></tr></thead><tbody>`;
        let no = 1; let tBruto = 0; let tPajak = 0;
        d.details.forEach(item => { 
            let pjk = (item.besaran * (item.potongan_pajak / 100)); tBruto += parseFloat(item.besaran); tPajak += parseFloat(pjk); 
            let jfTd = d.is_jafung == 1 ? `<td><span class="badge bg-info text-dark">${item.jabatan_fungsional||'Semua'}</span></td>` : '';
            html += `<tr><td>${no++}</td><td class="text-start fw-bold">${item.rincian}</td>${jfTd}<td><span class="badge bg-light text-dark border">${item.satuan}</span></td><td class="text-danger">${item.potongan_pajak}%</td><td class="text-end text-success fw-bold pe-3">${new Intl.NumberFormat('id-ID').format(item.besaran)}</td></tr>`; 
        });
        
        let colspanSpan = d.is_jafung == 1 ? 5 : 4;
        html += `</tbody><tfoot class="table-light fw-bold"><tr><td colspan="${colspanSpan}" class="text-end">Asumsi 1x Pekerjaan (Bruto)</td><td class="text-end pe-3 text-primary">Rp ${new Intl.NumberFormat('id-ID').format(tBruto)}</td></tr><tr><td colspan="${colspanSpan}" class="text-end">Estimasi Potongan Pajak</td><td class="text-end pe-3 text-danger">- Rp ${new Intl.NumberFormat('id-ID').format(tPajak)}</td></tr><tr class="table-dark"><td colspan="${colspanSpan}" class="text-end text-white">ESTIMASI DITERIMA (NETTO)</td><td class="text-end pe-3 text-success fs-6">Rp ${new Intl.NumberFormat('id-ID').format(tBruto - tPajak)}</td></tr></tfoot></table>`;
        document.getElementById('viewContainer').innerHTML = html; 
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalView')).show();
    }

    function deleteKomponen(id, nama) {
        Swal.fire({ title: 'Hapus Komponen?', html: `Yakin ingin menghapus <b>${nama}</b>?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#6c757d', confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData(); fd.append('action', 'delete_komp'); fd.append('id', id);
                fetch('honorarium_action.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res=>{
                    if (res.status == 'success') Swal.fire({ icon: 'success', title: 'Terhapus!', text: res.message, timer: 1500, showConfirmButton: false }).then(() => { window.location.href = '?page=honorarium&tab=komponen'; });
                });
            }
        });
    }

    // 🚀 VANILLA JS FETCH MENGATASI REDIRECT HANG
    function handleSaveKomp(e) {
        e.preventDefault();
        if (document.querySelectorAll('#rincianBody .row-rincian').length === 0) { Swal.fire({ icon: 'error', title: 'Ditolak', text: 'Minimal harus ada 1 rincian tarif!', confirmButtonColor: '#0d6efd' }); return; }
        
        const form = e.target;
        let btn = document.getElementById('btnSubmitKomp'); let ori = btn.innerHTML; 
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...'; btn.disabled = true;

        fetch('honorarium_action.php', { method: 'POST', body: new FormData(form) })
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } 
            catch(err) { throw new Error("JSON Rusak/Server Error. Periksa logs."); }
        })
        .then(res => {
            if(res.status === 'success') { 
                Swal.fire({ icon: 'success', title: 'Berhasil!', text: res.message, timer: 1500, showConfirmButton: false }).then(() => { window.location.href = '?page=honorarium&tab=komponen'; });
            } else { 
                Swal.fire('Gagal', res.message, 'error'); btn.innerHTML = ori; btn.disabled = false; 
            }
        }).catch(err => {
            Swal.fire('Gagal', err.message, 'error'); btn.innerHTML = ori; btn.disabled = false;
        });
    }
</script>