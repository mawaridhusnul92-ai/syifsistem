<?php
/**
 * honorarium_generate.php - TAB 3: GENERATE HONOR (JANTUNG SISTEM)
 * BUG FIX: Perhitungan QTY × Tarif = Jumlah kini berjalan 100%.
 *
 * ROOT CAUSE FIX (calcRow):
 * - Sebelumnya: mencari .inp-jml via nextElementSibling dari td tarif → SALAH
 * karena di dalam td tarif ada 2 input (tarif + hidden kompId), sehingga
 * nextElementSibling bisa menunjuk td yang salah.
 * - Sesudah: tiap set QTY/Tarif/Jumlah diberi data-rid="${rid}" pada td-nya,
 * sehingga selector langsung tepat sasaran tanpa mengandalkan posisi DOM.
 *
 * FIX TAMBAHAN:
 * - input qty diberi class khusus "inp-qty-{rid}" agar query lebih eksplisit.
 * - calcRow dipanggil juga saat baris dimuat dari matrixDetails (load existing data).
 * - formatRpJS menerima angka float dengan benar (tidak strip desimal sblm format).
 *
 * LAYOUT FIX:
 * - Kolom POTONGAN / HONOR DITERIMA / AKSI diberi border-left agar tidak tergeser secara visual.
 * - min-width HONOR DITERIMA dinaikkan agar tidak terpotong.
 * - Kolom TENAGA PENGAJAR menggunakan align-top + vertical-align:top agar
 *   dropdown dosen sejajar baris pertama, bukan tengah, saat ada sub-rows.
 */

$view_mode = $_GET['view'] ?? 'list';
$gen_id = (int)($_GET['id'] ?? 0);

$generate_list = [];
$res_gen = $conn->query("SELECT g.*, t.nama_template, t.jenis_tujuan FROM honor_generate g LEFT JOIN honor_template t ON g.template_id = t.id ORDER BY g.id DESC");
if($res_gen) while($r = $res_gen->fetch_assoc()) $generate_list[] = $r;


// Hanya template PENGAJUAN yang digunakan untuk generate honor (input data 1x)
$templates = $conn->query("SELECT * FROM honor_template WHERE jenis_tujuan='PENGAJUAN' ORDER BY nama_template ASC")->fetch_all(MYSQLI_ASSOC);

if ($view_mode == 'detail' && $gen_id > 0) {
    $gen_head = $conn->query("SELECT g.*, t.nama_template, t.custom_layout, t.jenis_tujuan FROM honor_generate g LEFT JOIN honor_template t ON g.template_id = t.id WHERE g.id = $gen_id")->fetch_assoc();
    
    $matrix_details = [];
    $res_det = $conn->query("SELECT d.*, ds.nama as dosen_nama, ds.nip, ds.jabatan_fungsional as dosen_jabatan FROM honor_generate_detail d LEFT JOIN dosen ds ON d.dosen_id = ds.id WHERE d.generate_id = $gen_id ORDER BY d.id ASC");
    if($res_det) {
        while($r = $res_det->fetch_assoc()) {
            $key = $r['dosen_id'] . '_' . md5($r['mata_kuliah'].$r['prodi']);
            if(!isset($matrix_details[$key])) {
                $matrix_details[$key] = [
                    'dosen_id'        => $r['dosen_id'],
                    'prodi'           => $r['prodi'],
                    'mata_kuliah'     => $r['mata_kuliah'],
                    'dosen_jabatan'   => $r['dosen_jabatan'] ?? '',
                    'komponen'        => []
                ];
            }
            $matrix_details[$key]['komponen'][$r['rincian_komponen_id']] = ['qty' => $r['qty'], 'tarif' => $r['tarif'], 'pajak' => $r['persen_pajak']];
        }
    }
    
    $dosen_list = $conn->query("SELECT id, nama, nip, program_studi, jabatan_fungsional FROM dosen WHERE status='Aktif' ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC);
    $prodi_list = [];
    $res_prodi = $conn->query("SELECT nama_prodi FROM mhs_prodi ORDER BY nama_prodi ASC");
    if($res_prodi) { while($r = $res_prodi->fetch_assoc()) $prodi_list[] = $r['nama_prodi']; }

    // Ambil rincian master DENGAN jabatan_fungsional untuk keperluan is_jafung
    $rincian_master = [];
    $res_rm = $conn->query("SELECT d.id, d.rincian, d.satuan, d.besaran, d.potongan_pajak, d.jabatan_fungsional, k.id as komp_id, k.is_jafung FROM honor_komponen_detail d JOIN honor_komponen k ON d.komponen_id = k.id");
    if($res_rm) while($rm = $res_rm->fetch_assoc()) $rincian_master[$rm['id']] = $rm;

    // Buat lookup: komp_id → array rincian per jabatan (untuk is_jafung)
    // Struktur: jafungTarif[komp_id][jabatan_fungsional] = {id, besaran, potongan_pajak}
    $jafung_tarif_map = [];
    foreach($rincian_master as $rm) {
        if($rm['is_jafung'] && !empty($rm['jabatan_fungsional'])) {
            $jafung_tarif_map[$rm['komp_id']][$rm['jabatan_fungsional']] = $rm;
        }
    }
}
?>


<style>
    .table-gen th { background-color: #f8fafc !important; color: #475569 !important; font-size: 11px; text-transform: uppercase; padding: 12px 8px; border: 1px solid #e2e8f0; text-align: center; vertical-align: middle;}
    .table-gen td { font-size: 13px; vertical-align: middle; padding: 6px; border: 1px solid #e2e8f0; color: #1e293b; }
    tbody.honor-row { border-bottom: 3px solid #94a3b8; }
    
    .col-dosen { min-width: 250px !important; }
    .col-teks { min-width: 200px !important; }
    .inp-gen { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; font-size: 12px; font-weight: 600; width: 100%; transition: 0.3s; }
    .inp-gen:focus { border-color: var(--bs-primary); box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1); outline: none; }
    .inp-nom[readonly], .inp-qty[readonly] { background-color: #f1f5f9; color: #64748b; cursor: not-allowed; border-style: dashed; }
    .inp-nom:not([readonly]) { text-align: right; color: var(--bs-primary); background: #f8fafc; }
    
    .th-group { background: #ffc107 !important; color: #000 !important; border-bottom: 2px solid #000 !important; }
    .cell-qty { min-width: 70px; text-align: center; } .cell-nom { min-width: 120px; text-align: right; } .cell-tot { min-width: 120px; text-align: right; font-weight: 800; background: #f8fafc; color: #0d6efd; white-space: nowrap; }
    .txt-total, .txt-potongan, .txt-netto { white-space: nowrap; min-width: 120px; display: block; }
    .btn-action { width: 28px; height: 28px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; font-size: 12px;}
    /* Highlight sel jumlah saat ada nilai */
    .inp-jml-display { text-align: right; font-weight: 700; color: #0d6efd; background: transparent; border: none; width: 100%; padding: 0; }
    /* ── Hilangkan spinner (tanda panah atas/bawah) pada input number ── */
    input.inp-qty::-webkit-outer-spin-button,
    input.inp-qty::-webkit-inner-spin-button,
    input.inp-pajak-pct::-webkit-outer-spin-button,
    input.inp-pajak-pct::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input.inp-qty, input.inp-pajak-pct { -moz-appearance: textfield; appearance: textfield; }
    /* Warna khusus input qty agar lebih jelas saat diisi */
    input.inp-qty { color: #0d6efd; font-weight: 700; }

    /* ── FIX #2: Tenaga Pengajar selalu rata atas (vertical-align:top pada td) ── */
    .table-gen td.td-dosen { vertical-align: top !important; padding-top: 10px !important; }

    /* ── FIX #1: Kolom TOTAL BRUTO, PAJAK, POTONGAN, HONOR DITERIMA, AKSI ── */
    /* Gunakan sticky + border-left agar tidak tergeser dan selalu terlihat di kanan */
    .th-separator-potongan { border-left: 3px solid #64748b !important; background-color: #f1f5f9 !important; }
    .th-separator-netto    { border-left: 3px solid #64748b !important; background-color: #dcfce7 !important; color: #166534 !important; }
    .th-separator-aksi     { border-left: 3px solid #64748b !important; background-color: #f1f5f9 !important; }

    .table-gen td.td-potongan { vertical-align: middle; border-left: 3px solid #94a3b8 !important; min-width: 140px; white-space: nowrap; background-color: #fef2f2; }
    .table-gen td.td-netto    { vertical-align: middle; border-left: 3px solid #94a3b8 !important; min-width: 160px; white-space: nowrap; background-color: #f0fdf4; }
    .table-gen td.td-aksi     { vertical-align: middle; border-left: 3px solid #94a3b8 !important; min-width: 80px; }
    /* pastikan txt-* tidak punya display:block yg bisa menyebabkan geser */
    .txt-total, .txt-potongan, .txt-netto { white-space: nowrap; font-weight: 700; }
    /* FIX KOLOM GESER: cell yang disembunyikan tetap menempati kolom tabel */
    .table-gen td.hidden-cell { visibility: hidden; pointer-events: none; padding: 0 !important; }
    .table-gen td.hidden-cell * { visibility: hidden; }
</style>

<div class="animate__animated animate__fadeIn">

<?php if ($view_mode == 'list'): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0 text-dark">Daftar Generate Honor</h5>
        <button class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold" onclick="showModalGenerate()"><i class="fas fa-plus me-2"></i>Buat Batch Generate Baru</button>
    </div>

    <div class="card border-0 bg-white rounded-4 shadow-sm border">
        <div class="table-responsive p-3">
            <table class="table table-hover table-gen mb-0 text-center">
                <thead><tr><th class="text-start ps-3">Kode Batch</th><th class="text-start">Nama Batch (Generate)</th><th>Layout Template Form</th><th>Periode</th><th>Status</th><th class="text-end pe-3">Total Honor</th><th width="140">Aksi</th></tr></thead>
                <tbody>
                    <?php if(empty($generate_list)): ?><tr><td colspan="7" class="text-center py-5 text-muted fst-italic">Belum ada data batch generate honor.</td></tr><?php endif; ?>
                    <?php foreach($generate_list as $g): 
                        $b_cls = match($g['status']) { 'Final'=>'bg-success', 'Dibayarkan'=>'bg-primary', default=>'bg-warning text-dark' };
                        $nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
                    ?>
                    <tr>
                        <td class="ps-3 text-start"><code class="text-dark bg-light px-2 py-1 rounded border fw-bold"><?= $g['kode_generate'] ?></code></td>
                        <td class="text-start fw-bold text-primary"><?= $g['nama_generate'] ?></td>
                        <td><span class="badge bg-light text-dark border px-2"><i class="fas fa-table text-primary me-1"></i><?= $g['nama_template'] ?: 'Default Form' ?></span></td>
                        <td class="fw-bold text-muted"><?= $nm_bln[$g['periode_bulan']] . ' ' . $g['periode_tahun'] ?></td>
                        <td><span class="badge <?= $b_cls ?> rounded-pill px-3 py-1"><?= $g['status'] ?></span></td>
                        <td class="text-end pe-3 fw-bold text-success">Rp <?= number_format($g['total_honor'], 0, ',', '.') ?></td>
                        <td>
                            <div class="d-flex justify-content-center gap-1">
                                <a href="?page=honorarium&tab=generate&view=detail&id=<?= $g['id'] ?>" class="btn-action btn btn-light border text-info shadow-sm" title="Susun Honor (Detail)"><i class="fas fa-list-ol"></i></a>
                                <?php if($g['status'] == 'Draft'): ?>
                                    <button type="button" class="btn-action btn btn-light border text-warning shadow-sm" title="Edit Batch" onclick='editHeaderGen(<?= htmlspecialchars(json_encode($g), JSON_HEX_APOS) ?>)'><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn-action btn btn-light border text-danger shadow-sm" title="Hapus Permanen" onclick="hapusGenerate(<?= $g['id'] ?>)"><i class="fas fa-trash"></i></button>
                                <?php else: ?>
                                    <button type="button" class="btn-action btn btn-light border text-warning shadow-sm" title="Batalkan Generate & Tarik Slip" onclick="batalGenerate(<?= $g['id'] ?>)"><i class="fas fa-undo"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>


    <script>
    const modalGenHTML = `
    <div class="modal fade" id="modalNewGen" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <form action="javascript:void(0);" id="formNewGen" onsubmit="handleInitGen(event)" class="modal-content border-0 shadow-lg rounded-4 text-dark overflow-hidden">
                <input type="hidden" name="action" id="actionGen" value="init_generate">
                <input type="hidden" name="id" id="editGenId" value="">
                <div class="modal-header p-4 bg-primary text-white border-0">
                    <h5 class="modal-title fw-bold text-white" id="titleGen"><i class="fas fa-cogs me-2 text-warning"></i>Buat Batch Generate Honor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Nama Batch (Generate) <span class="text-danger">*</span></label>
                        <input type="text" name="nama" id="inpNamaGen" class="form-control rounded-3 border fw-bold px-3 py-2" required placeholder="Contoh: Pembayaran Honor Smt Ganjil">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Nama Honorarium <span class="text-danger">*</span> <small class="text-muted fw-normal">(tampil di header print)</small></label>
                        <input type="text" name="nama_honorarium" id="inpNamaHonor" class="form-control rounded-3 border fw-bold px-3 py-2" required placeholder="Contoh: Honor Dosen Buat Soal">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Periode Honorarium (Teks) <span class="text-danger">*</span> <small class="text-muted fw-normal">(tampil di print)</small></label>
                        <input type="text" name="periode_honor_teks" id="inpPeriodeTeks" class="form-control rounded-3 border fw-bold px-3 py-2" required placeholder="Contoh: Semester Ganjil 2025/2026">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-primary">Pilih Layout Template Tabel <span class="text-danger">*</span></label>
                        <select name="template_id" id="inpTemplateGen" class="form-select rounded-3 border-primary shadow-sm fw-bold px-3 py-2 bg-white" required>
                            <option value="">-- Pilih Template --</option>
                            <?php foreach($templates as $t) echo "<option value='{$t['id']}'>{$t['nama_template']} (PENGAJUAN)</option>"; ?>
                        </select>
                        <div class="form-text text-primary fw-bold">
                            <i class="fas fa-info-circle me-1"></i>
                            Hanya Template <strong>PENGAJUAN</strong> yang tampil. Kuitansi ter-generate otomatis dari sinkronisasi.
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Bulan Periode <span class="text-danger">*</span></label>
                            <select name="bulan" id="inpBlnGen" class="form-select rounded-3 border fw-bold" required>
                                <?php $nb = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"]; foreach($nb as $idx => $b) { if($idx==0) continue; echo "<option value='$idx'>$b</option>"; } ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Tahun Periode <span class="text-danger">*</span></label>
                            <input type="number" name="tahun" id="inpThnGen" class="form-control rounded-3 border fw-bold text-center" value="<?= date('Y') ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-white">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm border" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow" id="btnSubmitInitGen">Simpan & Susun Honor <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
            </form>
        </div>
    </div>`;

    document.addEventListener("DOMContentLoaded", () => { if(!document.getElementById('modalNewGen')) { document.body.insertAdjacentHTML('beforeend', modalGenHTML); } });
    function handleInitGen(e) {
        e.preventDefault(); 
        let btn = document.getElementById('btnSubmitInitGen'); let ori = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...'; btn.disabled = true;
        fetch('honorarium_action.php', { method: 'POST', body: new FormData(e.target) }).then(r=>r.json()).then(res => {
            if(res.status === 'success') { if(document.getElementById('actionGen').value == 'edit_generate_header') { window.location.reload(); } else { window.location.href = '?page=honorarium&tab=generate&view=detail&id=' + res.id; } } else { Swal.fire('Gagal', res.message, 'error'); btn.innerHTML = ori; btn.disabled = false; }
        });
    }
    function showModalGenerate() { document.getElementById('formNewGen').reset(); document.getElementById('actionGen').value = 'init_generate'; document.getElementById('titleGen').innerHTML = '<i class="fas fa-cogs me-2 text-warning"></i>Buat Batch Generate Honor'; bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNewGen')).show(); }
    function editHeaderGen(g) { document.getElementById('actionGen').value = 'edit_generate_header'; document.getElementById('editGenId').value = g.id; document.getElementById('inpNamaGen').value = g.nama_generate; document.getElementById('inpNamaHonor').value = g.nama_honorarium || ''; document.getElementById('inpPeriodeTeks').value = g.periode_honor_teks || ''; document.getElementById('inpTemplateGen').value = g.template_id; document.getElementById('inpBlnGen').value = g.periode_bulan; document.getElementById('inpThnGen').value = g.periode_tahun; document.getElementById('titleGen').innerHTML = '<i class="fas fa-edit me-2 text-warning"></i>Edit Batch Generate'; bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNewGen')).show(); }
    function batalGenerate(id) { Swal.fire({ title: 'Batalkan Generate?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, Batalkan!' }).then((result) => { if (result.isConfirmed) { const fd = new FormData(); fd.append('action', 'batal_generate'); fd.append('id', id); fetch('honorarium_action.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => { if(res.status == 'success') window.location.reload(); }); } }); }
    function hapusGenerate(id) { Swal.fire({ title: 'Hapus Draf?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, Hapus!' }).then((result) => { if (result.isConfirmed) { const fd = new FormData(); fd.append('action', 'delete_generate'); fd.append('id', id); fetch('honorarium_action.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => { if(res.status == 'success') window.location.href='?page=honorarium&tab=generate'; }); } }); }
    </script>


<?php elseif ($view_mode == 'detail'): 
    $nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    $periode_str = $nm_bln[$gen_head['periode_bulan']] . ' ' . $gen_head['periode_tahun'];
    $is_locked = ($gen_head['status'] != 'Draft');
    $j_tujuan = $gen_head['jenis_tujuan'] ?? 'KUITANSI';
    $print_file_gen = ($j_tujuan == 'PENGAJUAN') ? 'print_honor.php' : 'print_slip_honor.php';
    
    $layout_json = $gen_head['custom_layout'] ?? '';
    $layout_cols = json_decode($layout_json, true) ?: [];
    
    $teks_cols = []; $horiz_groups = []; $vert_group_info = ['name' => '', 'header' => '', 'items' => []];

    foreach($layout_cols as $c) {
        if ($c['type'] == 'teks') { $teks_cols[] = $c; } 
        elseif (isset($c['group_type']) && $c['group_type'] == 'group_vertical') {
            $vert_group_info['name'] = $c['group'];
            $vert_group_info['header'] = $c['group_header'] ?? 'URAIAN';
            $vert_group_info['items'][] = $c;
        } 
        else {
            $grpName = $c['group'] ?? 'KOMPONEN HONOR';
            $horiz_groups[$grpName][] = $c;
        }
    }

    $hdr1 = "<th rowspan='2' width='40'>No</th><th rowspan='2' class='col-dosen text-start'>TENAGA PENGAJAR</th>";
    $hdr2 = "";
    
    foreach ($teks_cols as $t) { $hdr1 .= "<th rowspan='2' class='col-teks'>".strtoupper($t['label'])."</th>"; }
    
    foreach ($horiz_groups as $gName => $items) {
        $firstItem = $items[0] ?? null;
        $gHeader = $firstItem['group_header'] ?? '';
        $gIsJafung = !empty($firstItem['is_jafung']);
        $gSingleCol = !empty($firstItem['single_jafung_col']);
        
        if ($gSingleCol) {
            $first_rid   = (int)($items[0]['id_rincian'] ?? 0);
            $sat_map = ['Per Mahasiswa'=>'Mhs','Per SKS'=>'SKS','Per Pertemuan'=>'Pertemuan',
                        'Per Kegiatan'=>'Kegiatan','Per Jam'=>'Jam','Per Soal'=>'Soal','Lump Sum'=>'Ls'];
            $satuanLabel = 'QTY';
            $tarifLabel  = 'TARIF (Rp)';
            if ($first_rid > 0) {
                $res_sat = $conn->query("SELECT satuan FROM honor_komponen_detail WHERE id = $first_rid LIMIT 1");
                if ($res_sat && $row_sat = $res_sat->fetch_assoc()) {
                    $s = $row_sat['satuan'] ?: 'Qty';
                    $satuanLabel = strtoupper($sat_map[$s] ?? $s);
                    $tarifLabel  = "Rp/$satuanLabel";
                }
            }
            $cs = 3;
            $hdr1 .= "<th colspan='$cs' class='th-group'>".strtoupper($gName)."</th>";
            $hdr2 .= "<th class='cell-qty'>$satuanLabel</th><th class='cell-nom'>$tarifLabel</th><th class='cell-tot'>JUMLAH</th>";
        } else {
            $cs = count($items) * 3;
            if (!empty($gHeader)) {
                $cs += 1;
                $hdr1 .= "<th colspan='$cs' class='th-group'>".strtoupper($gName)."</th>";
                $hdr2 .= "<th class='cell-qty' style='min-width:120px;'>".strtoupper($gHeader)."</th>";
            } else {
                $hdr1 .= "<th colspan='$cs' class='th-group'>".strtoupper($gName)."</th>";
            }
            foreach($items as $it) {
                $it_rid = (int)($it['id_rincian'] ?? 0);
                $it_sat_lbl = 'QTY'; $it_trf_lbl = 'TARIF (Rp)';
                if ($it_rid > 0) {
                    $rs2 = $conn->query("SELECT satuan FROM honor_komponen_detail WHERE id=$it_rid LIMIT 1");
                    if ($rs2 && $rw2 = $rs2->fetch_assoc()) {
                        $s2 = $rw2['satuan'] ?: 'Qty';
                        $it_sat_lbl = strtoupper($sat_map[$s2] ?? $s2);
                        $it_trf_lbl = "Rp/$it_sat_lbl";
                    }
                }
                $hdr2 .= "<th class='cell-qty'>".strtoupper($it['label'])."<br><small>($it_sat_lbl)</small></th><th class='cell-nom'>$it_trf_lbl</th><th class='cell-tot'>JUMLAH</th>";
            }
        }
    }

    if (count($vert_group_info['items']) > 0) {
        $hdr1 .= "<th rowspan='2' class='col-teks'>".strtoupper($vert_group_info['header'])."</th>";
        $hdr1 .= "<th colspan='3' class='th-group'>".strtoupper($vert_group_info['name'])."</th>";
        $hdr2 .= "<th class='cell-qty'>JML/QTY</th><th class='cell-nom'>TARIF (Rp)</th><th class='cell-tot'>JUMLAH</th>";
    }

    // FIX: Tambah white-space:nowrap + min-width lebih besar + border-left pada kolom akhir
    $hdr1 .= "<th rowspan='2' style='min-width:130px; white-space:nowrap;'>TOTAL BRUTO</th>";
    $hdr1 .= "<th rowspan='2' style='min-width:80px; white-space:nowrap;'>PAJAK (%)</th>";
    $hdr1 .= "<th rowspan='2' class='th-separator-potongan' style='min-width:140px; white-space:nowrap;'>POTONGAN</th>";
    $hdr1 .= "<th rowspan='2' class='th-separator-netto text-end pe-3' style='min-width:160px; white-space:nowrap;'>HONOR DITERIMA</th>";
    if(!$is_locked) $hdr1 .= "<th rowspan='2' class='th-separator-aksi' style='min-width:80px; white-space:nowrap;'>Aksi</th>";

    // Hitung total kolom header secara eksplisit untuk sinkronisasi dengan JS
    $total_header_cols = 2; // No + TENAGA PENGAJAR
    $total_header_cols += count($teks_cols); // kolom teks
    foreach ($horiz_groups as $gName => $items) {
        $firstItem = $items[0] ?? null;
        $gSingleCol = !empty($firstItem['single_jafung_col']);
        $gHeader = $firstItem['group_header'] ?? '';
        if ($gSingleCol) {
            $total_header_cols += 3;
        } else {
            if (!empty($gHeader)) $total_header_cols += 1;
            $total_header_cols += count($items) * 3;
        }
    }
    if (count($vert_group_info['items']) > 0) {
        $total_header_cols += 4; // header label + 3 sub-cols
    }
    $total_header_cols += 4; // TOTAL BRUTO + PAJAK + POTONGAN + HONOR DITERIMA
    if (!$is_locked) $total_header_cols += 1; // Aksi
?>

    <div class="card border border-primary border-4 border-start-0 border-end-0 border-bottom-0 rounded-4 shadow-sm bg-white mb-3">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light">
            <div>
                <span class="badge <?= $is_locked?'bg-success':'bg-secondary' ?> px-3 py-1 rounded-pill mb-1 fw-bold"><?= strtoupper($gen_head['status']) ?></span>
                <h5 class="fw-bold mb-0 text-dark"><?= $gen_head['nama_generate'] ?></h5>
            </div>
            <div class="text-end">
                <div class="small text-muted fw-bold">Periode: <span class="text-dark"><?= $periode_str ?></span></div>
            </div>
        </div>
        <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex gap-2">
                <a href="?page=honorarium&tab=generate" class="btn btn-light border fw-bold rounded-pill shadow-sm"><i class="fas fa-arrow-left me-2"></i>Kembali</a>
                <?php if(!$is_locked): ?>
                <button type="button" class="btn btn-outline-primary fw-bold rounded-pill shadow-sm" onclick="addHonorMatrixRow()"><i class="fas fa-plus me-2"></i>Tambah Dosen</button>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-info fw-bold rounded-pill shadow-sm text-white" onclick="window.open('print_honor.php?mode=pengajuan&gen_id=<?= $gen_id ?>', '_blank')"><i class="fas fa-print me-2"></i>Cetak Preview PDF</button>
                <?php if(!$is_locked): ?>
                <button type="button" class="btn btn-warning fw-bold rounded-pill shadow-sm text-dark" onclick="submitHonorDetail(0)"><i class="fas fa-save me-2"></i>Simpan Draft</button>
                <button type="button" class="btn btn-primary fw-bold rounded-pill shadow-sm" onclick="submitHonorDetail(1)"><i class="fas fa-check-double me-2"></i>Finalisasi Honor</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <form id="formDetailGen" class="card border-0 rounded-4 shadow-sm bg-white overflow-hidden mb-4 border" action="javascript:void(0);" onsubmit="return false;">
        <input type="hidden" name="action" value="save_generate_detail">
        <input type="hidden" name="generate_id" value="<?= $gen_id ?>">
        <input type="hidden" name="finalize" id="inpFinalize" value="0">

        <div class="table-responsive" style="min-height: 400px; padding-bottom: 20px; overflow-x: auto;">
            <table class="table table-gen mb-0" id="tblHonorDetail" style="min-width: 1500px;">
                <thead class="table-light">
                    <tr><?= $hdr1 ?></tr>
                    <?php if(!empty($hdr2)) echo "<tr>$hdr2</tr>"; ?>
                </thead>
                <tbody id="honorContainer"></tbody>
            </table>
        </div>
    </form>

    <div class="row justify-content-end">
        <div class="col-md-5 col-lg-4">
            <div class="card border-2 rounded-4 shadow-sm bg-light border-primary border-opacity-25">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-calculator me-2 text-primary"></i>Ringkasan Generate</h6>
                    <div class="d-flex justify-content-between mb-2 small fw-bold"><span class="text-muted">Jumlah Dosen</span><span class="text-dark" id="sumDosen">0</span></div>
                    <div class="d-flex justify-content-between mb-2 small fw-bold"><span class="text-muted">Total Honor Bruto</span><span class="text-dark" id="sumBruto">Rp 0</span></div>
                    <div class="d-flex justify-content-between mb-2 small fw-bold"><span class="text-danger">Total Potongan Pajak</span><span class="text-danger" id="sumPajak">Rp 0</span></div>
                    <hr class="my-2 border-secondary opacity-25">
                    <div class="d-flex justify-content-between fs-5 fw-bold"><span class="text-primary">TOTAL BERSIH</span><span class="text-success" id="sumNetto">Rp 0</span></div>
                </div>
            </div>
        </div>
    </div>



    <script>
    // ================================================================
    //  HONOR GENERATE — JAVASCRIPT ENGINE
    // ================================================================

    const isLocked      = <?= $is_locked ? 'true' : 'false' ?>;
    const dosenData     = <?= json_encode($dosen_list) ?>;
    const prodiList     = <?= json_encode($prodi_list ?? []) ?>;
    const matrixDetails = <?= json_encode(array_values($matrix_details)) ?>;

    const teksCols    = <?= json_encode($teks_cols) ?>;
    const horizGroups = <?= json_encode($horiz_groups) ?>;
    const vertGroup   = <?= json_encode($vert_group_info) ?>;
    const masterTarif = <?= json_encode($rincian_master) ?>;
    const jafungTarif = <?= json_encode($jafung_tarif_map ?? []) ?>;
    const EXPECTED_COL_COUNT = <?= $total_header_cols ?>; // jumlah kolom yang harus ada di setiap tr

    let rCount = 0;

    function fmtRp(val) {
        return new Intl.NumberFormat('id-ID').format(Math.round(parseFloat(val) || 0));
    }
    function fmtQty(val) {
        // Jangan auto-tambah desimal. Tampilkan angka apa adanya sesuai input user.
        // misal: 15 → 15 | 15.5 → 15,5 | 15.55 → 15,55
        const num = parseFloat(val) || 0;
        if (num === 0) return '0';
        // Cek apakah bilangan bulat
        if (Number.isInteger(num)) return num.toString();
        // Jika ada desimal, tampilkan sesuai input (max 2 desimal, tanpa trailing zero)
        const str = num.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        return str;
    }
    function cleanNum(str) {
        return parseFloat(String(str).replace(/[^0-9]/g, '')) || 0;
    }
    function cleanPct(str) {
        return parseFloat(String(str).replace(',', '.').replace(/[^0-9.]/g, '')) || 0;
    }

    let dosenOpts = [{ val: '', lbl: '-- Pilih Dosen --', prodi: '', jabatan: '' }];
    dosenData.forEach(d => dosenOpts.push({
        val: d.id, lbl: d.nama,
        prodi: d.program_studi,
        jabatan: d.jabatan_fungsional || ''
    }));

    function syncProdi(selDosen, rowId) {
        const opt = selDosen.options[selDosen.selectedIndex];
        const pInp = document.querySelector(`#hr_${rowId} .inp-prodi`);
        if (pInp) pInp.value = (opt && opt.value) ? (opt.dataset.prodi || '') : '';
        const jSel = document.querySelector(`#hr_${rowId} .inp-jabatan`);
        const jabatan = (opt && opt.value) ? (opt.dataset.jabatan || '') : '';
        if (jSel) jSel.value = jabatan;
        updateJafungTarif(rowId, jabatan);
        filterKomponenByJabatan(rowId, jabatan);
    }

    function updateJafungTarif(rowId, jabatan) {
        const tbody = document.getElementById(`hr_${rowId}`);
        if (!tbody) return;
        let pajakBaru = null;
        function processItem(c) {
            const rid   = c.id_rincian;
            const mData = masterTarif[rid] || null;
            if (!mData) return;
            const isJafung = c.is_jafung || (String(mData.is_jafung) === '1');
            if (!isJafung) return;
            const kompId = String(mData.komp_id);
            let tarifBaru = mData.besaran, pajak = mData.potongan_pajak, ridBaru = String(rid);
            if (jabatan && jafungTarif[kompId] && jafungTarif[kompId][jabatan]) {
                const jt = jafungTarif[kompId][jabatan];
                tarifBaru = jt.besaran; pajak = jt.potongan_pajak; ridBaru = String(jt.id);
            }
            const tdQty = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-qty"]`);
            if (tdQty) {
                const hidRid = tdQty.querySelector('input[name="rincian_ids[]"]');
                if (hidRid) {
                    if (ridBaru !== String(rid)) tbody.querySelectorAll(`td[data-rid="${rid}"]`).forEach(td => td.dataset.rid = ridBaru);
                    hidRid.value = ridBaru;
                }
                if (ridBaru !== String(rid)) { const qi = tdQty.querySelector('.inp-qty'); if (qi) qi.name = `komp_qty_${ridBaru}[]`; }
            }
            const tdTarif = tbody.querySelector(`td[data-rid="${ridBaru}"][data-role="td-tarif"]`);
            if (tdTarif) {
                const trfInp = tdTarif.querySelector('.inp-tarif');
                if (trfInp) {
                    trfInp.value = fmtRp(tarifBaru);
                    if (ridBaru !== String(rid)) {
                        trfInp.name = `komp_tarif_${ridBaru}[]`;
                        const ki = tdTarif.querySelector(`input[name="komp_kompId_${rid}[]"]`);
                        if (ki) ki.name = `komp_kompId_${ridBaru}[]`;
                    }
                    trfInp.style.background = '#fef9c3'; trfInp.style.color = '#b45309';
                    setTimeout(() => { trfInp.style.background = ''; trfInp.style.color = ''; }, 1500);
                }
            }
            if (pajakBaru === null) pajakBaru = pajak;
        }
        for (const g in horizGroups) {
            const firstItem = horizGroups[g][0] || {};
            const gSingleCol = firstItem.single_jafung_col || false;
            if (gSingleCol && jabatan) {
                const items = horizGroups[g], mDataFirst = masterTarif[items[0].id_rincian] || null;
                if (mDataFirst) {
                    const kompId = String(mDataFirst.komp_id);
                    if (jafungTarif[kompId] && jafungTarif[kompId][jabatan]) {
                        const jt = jafungTarif[kompId][jabatan], ridBaru = String(jt.id), tarifBaru = jt.besaran;
                        if (pajakBaru === null) pajakBaru = jt.potongan_pajak;
                        const tdQty = tbody.querySelector(`td[data-single-col="true"][data-group-name="${g}"][data-role="td-qty"]`);
                        if (tdQty) {
                            const hidRid = tdQty.querySelector('input[name="rincian_ids[]"]');
                            if (hidRid) hidRid.value = ridBaru;
                            tbody.querySelectorAll(`td[data-single-col="true"][data-group-name="${g}"]`).forEach(td => td.dataset.rid = ridBaru);
                            const qi = tdQty.querySelector('.inp-qty'); if (qi) qi.name = `komp_qty_${ridBaru}[]`;
                        }
                        const tdTarif = tbody.querySelector(`td[data-single-col="true"][data-group-name="${g}"][data-role="td-tarif"]`);
                        if (tdTarif) {
                            const trfInp = tdTarif.querySelector('.inp-tarif');
                            if (trfInp) { trfInp.value = fmtRp(tarifBaru); trfInp.name = `komp_tarif_${ridBaru}[]`; trfInp.style.background = '#fef9c3'; trfInp.style.color = '#b45309'; setTimeout(() => { trfInp.style.background = ''; trfInp.style.color = ''; }, 1500); }
                            const ki = tdTarif.querySelector('input[type="hidden"]');
                            if (ki) { ki.value = mDataFirst.komp_id; ki.name = `komp_kompId_${ridBaru}[]`; }
                        }
                    }
                }
            } else { horizGroups[g].forEach(c => processItem(c)); }
        }
        const vItems = vertGroup.items || [];
        vItems.forEach(c => processItem(c));
        if (pajakBaru !== null) { const pi = tbody.querySelector('.inp-pajak-pct'); if (pi) pi.value = pajakBaru; }
        calcRow(rowId);
    }

    function filterKomponenByJabatan(rowId, jabatan) {
        const tbody = document.getElementById(`hr_${rowId}`);
        if (!tbody) return;
        const allItems = [...Object.values(horizGroups).flat(), ...(vertGroup.items || [])];
        allItems.forEach(c => {
            const rid = String(c.id_rincian), mData = masterTarif[rid] || null;
            if (!mData) return;
            const isJafung = c.is_jafung || (String(mData.is_jafung) === '1');
            if (!isJafung) return;
            if (c.single_jafung_col) return;
            const kompId = String(mData.komp_id);
            let shouldShow = true;
            if (jabatan && jafungTarif[kompId]) {
                const jabR = mData.jabatan_fungsional || '';
                if (jabR && jabR !== jabatan) shouldShow = false;
            }
            let tdQty = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-qty"]`);
            let tdTarif = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-tarif"]`);
            let tdJml = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-jml"]`);
            const vertItems = vertGroup.items || [], isVertItem = vertItems.some(vi => String(vi.id_rincian) === rid);
            if (shouldShow) {
                // Tampilkan kembali - hapus class hidden-cell
                if (tdQty) tdQty.classList.remove('hidden-cell');
                if (tdTarif) tdTarif.classList.remove('hidden-cell');
                if (tdJml) tdJml.classList.remove('hidden-cell');
                if (isVertItem && tdQty) { const tr = tdQty.closest('tr'); if (tr) tr.style.display = ''; }
            } else {
                // Sembunyikan KONTEN tapi td tetap ada & menempati kolom
                if (tdQty) { tdQty.classList.add('hidden-cell'); const qi = tdQty.querySelector('input[type="number"]'); if (qi) qi.value = 0; }
                if (tdTarif) tdTarif.classList.add('hidden-cell');
                if (tdJml) tdJml.classList.add('hidden-cell');
                if (isVertItem && tdQty) { const tr = tdQty.closest('tr'); if (tr) tr.style.display = 'none'; }
            }
        });
        calcRow(rowId);
    }

    function createCell(innerHtml, opts = {}) {
        const td = document.createElement('td');
        if (opts.rowspan && opts.rowspan > 1) td.rowSpan = opts.rowspan;
        if (opts.cls)      td.className      = opts.cls;
        if (opts.style)    td.style.cssText  = opts.style;
        if (opts.dataRid)  td.dataset.rid    = opts.dataRid;
        if (opts.dataRole) td.dataset.role   = opts.dataRole;
        td.innerHTML = innerHtml;
        return td;
    }

    function appendKomponenCols(tr, rowId, d) {
        for (const g in horizGroups) {
            const firstItem  = horizGroups[g][0] || {};
            const gHeader    = firstItem.group_header || '';
            const gSingleCol = firstItem.single_jafung_col || false;
            if (gSingleCol) {
                const items = horizGroups[g];
                let activeRid = items[0].id_rincian, activeData = masterTarif[activeRid] || null, q = 0, t = activeData ? activeData.besaran : 0;
                if (d) { for (const c of items) { if (d.komponen?.[c.id_rincian] && d.komponen[c.id_rincian].qty > 0) { activeRid = c.id_rincian; activeData = masterTarif[activeRid] || null; q = d.komponen[activeRid].qty; t = d.komponen[activeRid].tarif; break; } } }
                if (d && d.dosen_jabatan) { const ki = activeData ? String(activeData.komp_id) : ''; if (ki && jafungTarif[ki] && jafungTarif[ki][d.dosen_jabatan]) { const jt = jafungTarif[ki][d.dosen_jabatan]; activeRid = String(jt.id); t = jt.besaran; } }
                const inpHid = document.createElement('input'); inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = activeRid;
                const inpGI = document.createElement('input'); inpGI.type = 'hidden'; inpGI.name = 'single_col_group[]'; inpGI.value = g;
                const inpQty = document.createElement('input'); inpQty.type = 'number'; inpQty.name = `komp_qty_${activeRid}[]`; inpQty.className = 'inp-gen text-center inp-qty'; inpQty.value = q; inpQty.step = '0.01'; inpQty.min = '0'; if (isLocked) inpQty.disabled = true; inpQty.oninput = inpQty.onchange = () => calcRow(rowId);
                const tdQ = createCell('', { cls: 'cell-qty align-middle', dataRid: activeRid, dataRole: 'td-qty' }); tdQ.dataset.singleCol = 'true'; tdQ.dataset.groupName = g; tdQ.appendChild(inpHid); tdQ.appendChild(inpGI); tdQ.appendChild(inpQty); tr.appendChild(tdQ);
                const inpTrf = document.createElement('input'); inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${activeRid}[]`; inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t); inpTrf.readOnly = true; inpTrf.tabIndex = -1;
                const inpKid = document.createElement('input'); inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${activeRid}[]`; inpKid.value = activeData ? activeData.komp_id : 0;
                const tdT = createCell('', { cls: 'cell-nom align-middle', dataRid: activeRid, dataRole: 'td-tarif' }); tdT.dataset.singleCol = 'true'; tdT.dataset.groupName = g; tdT.appendChild(inpTrf); tdT.appendChild(inpKid); tr.appendChild(tdT);
                const inpJml = document.createElement('input'); inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display'; inpJml.value = q > 0 ? fmtRp(q * t) : '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
                const tdJ = createCell('', { cls: 'cell-tot align-middle', dataRid: activeRid, dataRole: 'td-jml' }); tdJ.dataset.singleCol = 'true'; tdJ.dataset.groupName = g; tdJ.appendChild(inpJml); tr.appendChild(tdJ);
            } else {
                if (gHeader) {
                    const uraianVal = d?.uraian_horiz?.[g] || '';
                    const inpU = document.createElement('input'); inpU.type = 'text'; inpU.name = `uraian_horiz_${encodeURIComponent(g)}[]`; inpU.className = 'inp-gen text-dark'; inpU.value = uraianVal; inpU.placeholder = gHeader; if (isLocked) inpU.disabled = true;
                    const tdU = createCell('', { cls: 'align-middle', dataRid: `uraian_${g}`, dataRole: 'td-uraian' }); tdU.appendChild(inpU); tr.appendChild(tdU);
                }
                horizGroups[g].forEach(c => {
                    const rid = c.id_rincian, mData = masterTarif[rid] || null;
                    let q = 0, t = mData ? mData.besaran : 0;
                    if (d?.komponen?.[rid]) { q = d.komponen[rid].qty; t = d.komponen[rid].tarif; }
                    const inpHid = document.createElement('input'); inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = rid;
                    const inpQty = document.createElement('input'); inpQty.type = 'number'; inpQty.name = `komp_qty_${rid}[]`; inpQty.className = 'inp-gen text-center inp-qty'; inpQty.value = q; inpQty.step = '0.01'; inpQty.min = '0'; if (isLocked) inpQty.disabled = true; inpQty.oninput = inpQty.onchange = () => calcRow(rowId);
                    const tdQ = createCell('', { cls: 'cell-qty align-middle', dataRid: rid, dataRole: 'td-qty' }); tdQ.appendChild(inpHid); tdQ.appendChild(inpQty); tr.appendChild(tdQ);
                    const inpTrf = document.createElement('input'); inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${rid}[]`; inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t); inpTrf.readOnly = true; inpTrf.tabIndex = -1;
                    const inpKid = document.createElement('input'); inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${rid}[]`; inpKid.value = mData ? mData.komp_id : 0;
                    const tdT = createCell('', { cls: 'cell-nom align-middle', dataRid: rid, dataRole: 'td-tarif' }); tdT.appendChild(inpTrf); tdT.appendChild(inpKid); tr.appendChild(tdT);
                    const inpJml = document.createElement('input'); inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display'; inpJml.value = q > 0 ? fmtRp(q * t) : '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
                    const tdJ = createCell('', { cls: 'cell-tot align-middle', dataRid: rid, dataRole: 'td-jml' }); tdJ.appendChild(inpJml); tr.appendChild(tdJ);
                });
            }
        }
        const vItems = vertGroup.items || [];
        if (vItems.length > 0) appendVertRow(tr, vItems[0], d, rowId);
    }



    // ================================================================
    //  addHonorMatrixRow — INSERT BARIS DOSEN BARU
    //  FIX: tdDosen pakai class td-dosen (vertical-align:top) agar
    //       dropdown selalu sejajar baris pertama, tidak di tengah.
    // ================================================================
    function addHonorMatrixRow(d = null) {
        rCount++;
        const id       = rCount;
        const readOnly = isLocked;

        let pajak_base = 0;
        const vItems   = vertGroup.items || [];
        const allItems = [...Object.values(horizGroups).flat(), ...vItems];
        for (const c of allItems) {
            const rid = c.id_rincian;
            if (d?.komponen?.[rid]) { pajak_base = d.komponen[rid].pajak || 0; break; }
            else if (masterTarif[rid]) { pajak_base = masterTarif[rid].potongan_pajak || 0; break; }
        }

        const tbody = document.createElement('tbody');
        tbody.id        = `hr_${id}`;
        tbody.className = 'honor-row bg-white';

        const tr1 = document.createElement('tr');
        tbody.appendChild(tr1);

        // No
        const tdNo = createCell(id, { cls: 'text-center align-middle fw-bold row-no' });
        tr1.appendChild(tdNo);

        // Dosen — FIX: class td-dosen memberi vertical-align:top agar sejajar baris pertama
        const selDosen = document.createElement('select');
        selDosen.name      = 'dosen_id[]';
        selDosen.className = 'inp-gen text-dark inp-dosen-w';
        selDosen.required  = true;
        if (readOnly) selDosen.disabled = true;
        selDosen.onchange  = () => syncProdi(selDosen, id);
        dosenOpts.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.val; opt.text = o.lbl;
            opt.dataset.prodi   = o.prodi;
            opt.dataset.jabatan = o.jabatan;
            if (d && String(o.val) === String(d.dosen_id)) opt.selected = true;
            selDosen.appendChild(opt);
        });
        const tdDosen = createCell('', { cls: 'text-start td-dosen col-dosen', style: 'vertical-align:top !important; padding-top:10px;' });
        tdDosen.appendChild(selDosen);
        tr1.appendChild(tdDosen);

        // teksCols
        _appendTeksCols(tr1, id, d, readOnly);

        // Komponen + vertikal
        appendKomponenCols(tr1, id, d);

        // Total / Pajak
        tr1.appendChild(createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-dark txt-total', style: 'white-space:nowrap; min-width:130px;' }));
        const inpPajak = document.createElement('input');
        inpPajak.type = 'text'; inpPajak.name = 'pajak_pct[]';
        inpPajak.className = 'inp-gen text-center text-danger inp-pajak-pct';
        inpPajak.value = pajak_base; inpPajak.placeholder = '0';
        inpPajak.setAttribute('inputmode', 'decimal');
        if (readOnly) inpPajak.disabled = true;
        inpPajak.oninput = inpPajak.onchange = function() {
            this.value = this.value.replace(/[^0-9.]/g, ''); calcRow(id);
        };
        const tdPajak = createCell('', { cls: 'align-middle' });
        tdPajak.appendChild(inpPajak); tr1.appendChild(tdPajak);

        // FIX: Potongan / Honor Diterima / Aksi pakai class td-potongan / td-netto / td-aksi
        // yang memberi border-left agar kolom tidak tergeser secara visual.
        tr1.appendChild(createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-danger txt-potongan td-potongan' }));
        tr1.appendChild(createCell('Rp 0', { cls: 'text-end pe-3 fw-bold align-middle fs-6 text-success txt-netto td-netto' }));

        if (!readOnly) {
            const btnDel = document.createElement('button');
            btnDel.type = 'button'; btnDel.title = 'Hapus Dosen Ini';
            btnDel.className = 'btn-action bg-light border text-danger shadow-sm';
            btnDel.innerHTML = '<i class="fas fa-trash"></i>';
            btnDel.onclick   = () => delHonorRow(id);
            const btnAdd = document.createElement('button');
            btnAdd.type = 'button'; btnAdd.title = 'Tambah Baris Komponen (Dosen Sama)';
            btnAdd.className = 'btn-action bg-light border text-success shadow-sm';
            btnAdd.innerHTML = '<i class="fas fa-plus"></i>';
            btnAdd.onclick   = () => addSubRowSameDosen(id);
            const tdAksi = createCell('', { cls: 'text-center align-middle td-aksi' });
            const wrap = document.createElement('div');
            wrap.className = 'd-flex justify-content-center gap-1';
            wrap.appendChild(btnDel); wrap.appendChild(btnAdd);
            tdAksi.appendChild(wrap); tr1.appendChild(tdAksi);
        }

        document.getElementById('tblHonorDetail').appendChild(tbody);
        // FIX KOLOM GESER: pastikan jumlah td di tr1 = EXPECTED_COL_COUNT
        const actualCols = tr1.querySelectorAll('td').length;
        if (actualCols < EXPECTED_COL_COUNT) {
            const diff = EXPECTED_COL_COUNT - actualCols;
            console.warn(`[HONOR DEBUG] Row has ${actualCols} cells, expected ${EXPECTED_COL_COUNT}. Adding ${diff} empty cells before summary cols.`);
            // Cari posisi TOTAL BRUTO cell (txt-total) dan insert empty cells sebelumnya
            const txtTotalTd = tr1.querySelector('.txt-total');
            for (let i = 0; i < diff; i++) {
                const emptyTd = document.createElement('td');
                emptyTd.className = 'align-middle';
                emptyTd.innerHTML = '&nbsp;';
                if (txtTotalTd) tr1.insertBefore(emptyTd, txtTotalTd);
                else tr1.appendChild(emptyTd);
            }
        }
        reindexRows();
        setTimeout(() => calcRow(id), 0);
    }

    function _appendTeksCols(tr, rowId, d, readOnly) {
        teksCols.forEach(c => {
            let val = '';
            if (d) {
                if (c.source === 'prodi')       val = d.prodi || '';
                if (c.source === 'mata_kuliah') val = d.mata_kuliah || '';
                if (c.source === 'jabatan') {
                    const dosenObj = dosenData.find(dd => String(dd.id) === String(d.dosen_id));
                    val = d.dosen_jabatan || (dosenObj ? (dosenObj.jabatan_fungsional || '') : '');
                }
            }
            const tdT = createCell('', { cls: 'align-middle' });
            if (c.source === 'jabatan') {
                const selJ = document.createElement('select');
                selJ.name = `teks_${c.source}[]`;
                selJ.className = 'inp-gen text-dark inp-teks-w inp-jabatan';
                if (readOnly) selJ.disabled = true;
                ['', 'Tenaga Pengajar', 'Asisten Ahli', 'Lektor', 'Lektor Kepala', 'Profesor'].forEach(jOpt => {
                    const opt = document.createElement('option');
                    opt.value = jOpt; opt.text = jOpt === '' ? '-- Pilih Jabatan --' : jOpt;
                    if (jOpt === val) opt.selected = true;
                    selJ.appendChild(opt);
                });
                selJ.onchange = function() { updateJafungTarif(rowId, this.value); filterKomponenByJabatan(rowId, this.value); };
                tdT.appendChild(selJ);
            } else {
                const inp = document.createElement('input');
                inp.type = 'text'; inp.name = `teks_${c.source}[]`; inp.value = val;
                inp.className = 'inp-gen text-dark inp-teks-w' + (c.source === 'prodi' ? ' inp-prodi' : '');
                if (readOnly || c.source === 'prodi') inp.readOnly = true;
                if (readOnly) inp.disabled = true;
                if (c.source !== 'prodi') inp.required = true;
                if (c.source === 'prodi') {
                    const dl = document.createElement('datalist');
                    dl.id = `dlProdi_${rowId}_${Math.random().toString(36).slice(2,7)}`;
                    prodiList.forEach(p => { const op = document.createElement('option'); op.value = p; dl.appendChild(op); });
                    inp.setAttribute('list', dl.id); tdT.appendChild(dl);
                }
                tdT.appendChild(inp);
            }
            tr.appendChild(tdT);
        });
    }

    function appendVertRow(tr, v, d, rowId) {
        const rid = v.id_rincian, mData = masterTarif[rid] || null;
        let q = 0, t = mData ? mData.besaran : 0;
        if (d?.komponen?.[rid]) { q = d.komponen[rid].qty; t = d.komponen[rid].tarif; }
        tr.appendChild(createCell(v.label, { cls: 'align-middle bg-light fw-bold text-dark' }));
        const inpHid = document.createElement('input'); inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = rid;
        const inpQty = document.createElement('input'); inpQty.type = 'number'; inpQty.name = `komp_qty_${rid}[]`; inpQty.className = 'inp-gen text-center inp-qty bg-white'; inpQty.value = q; inpQty.step = '0.01'; inpQty.min = '0'; if (isLocked) inpQty.disabled = true; inpQty.oninput = inpQty.onchange = () => calcRow(rowId);
        const tdQty = createCell('', { cls: 'cell-qty align-middle', dataRid: rid, dataRole: 'td-qty' }); tdQty.appendChild(inpHid); tdQty.appendChild(inpQty); tr.appendChild(tdQty);
        const inpTrf = document.createElement('input'); inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${rid}[]`; inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t); inpTrf.readOnly = true; inpTrf.tabIndex = -1;
        const inpKid = document.createElement('input'); inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${rid}[]`; inpKid.value = mData ? mData.komp_id : 0;
        const tdTrf = createCell('', { cls: 'cell-nom align-middle', dataRid: rid, dataRole: 'td-tarif' }); tdTrf.appendChild(inpTrf); tdTrf.appendChild(inpKid); tr.appendChild(tdTrf);
        const inpJml = document.createElement('input'); inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display'; inpJml.value = q > 0 ? fmtRp(q * t) : '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
        const tdJml = createCell('', { cls: 'cell-tot align-middle', dataRid: rid, dataRole: 'td-jml' }); tdJml.appendChild(inpJml); tr.appendChild(tdJml);
    }



    function delHonorRow(tbodyId) {
        const tbody = document.getElementById('hr_' + tbodyId);
        if (tbody) tbody.remove();
        reindexRows();
        calcSummary();
    }

    function delSubRow(tr, tbodyId) {
        const tbody = document.getElementById('hr_' + tbodyId);
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr');
        if (rows.length <= 1) { delHonorRow(tbodyId); return; }
        const trFirst = tbody.querySelector('tr');
        const tdNo    = trFirst.querySelector('.row-no');
        const tdDosen = trFirst.querySelector('.td-dosen');
        if (tdNo)    tdNo.rowSpan    = Math.max(1, (tdNo.rowSpan    || 1) - 1);
        if (tdDosen) tdDosen.rowSpan = Math.max(1, (tdDosen.rowSpan || 1) - 1);
        tr.remove();
        calcRow(tbodyId);
        calcSummary();
    }

    function reindexRows() {
        let idx = 1;
        document.querySelectorAll('#tblHonorDetail tbody.honor-row').forEach(tbody => {
            const tdNo = tbody.querySelector('tr:first-child .row-no');
            if (tdNo) tdNo.innerText = idx++;
        });
    }

    // ================================================================
    //  addSubRowSameDosen — tambah <tr> baru ke tbody yang SAMA
    //  FIX: sel potongan/netto/aksi juga pakai class td-potongan/td-netto/td-aksi
    //  FIX BUG1: tambahkan hidden input dosen_id agar sub-row ikut tersimpan
    // ================================================================
    function addSubRowSameDosen(tbodyId) {
        const tbody = document.getElementById(`hr_${tbodyId}`);
        if (!tbody) return;

        const selDosenParent = tbody.querySelector('select[name="dosen_id[]"]');
        const dosenId        = selDosenParent ? selDosenParent.value : '';
        if (!dosenId) {
            Swal.fire('Peringatan', 'Pilih dosen terlebih dahulu sebelum menambah baris.', 'warning');
            return;
        }

        const selJab   = tbody.querySelector('select.inp-jabatan');
        const jabatan  = selJab ? selJab.value : '';
        const prodiInp = tbody.querySelector('input.inp-prodi');
        const prodi    = prodiInp ? prodiInp.value : '';

        const trNew = document.createElement('tr');
        trNew.className = 'honor-subrow';

        // BUG FIX: Simpan dosen_id di data-attribute, akan di-inject via snap-submit saat submit
        trNew.dataset.dosenId = dosenId;

        // BUG FIX: Simpan pajak dari baris utama
        const pajakParentVal = tbody.querySelector('.inp-pajak-pct')?.value || '0';

        const dSub = { dosen_id: dosenId, prodi: prodi, mata_kuliah: '', dosen_jabatan: jabatan, komponen: {} };
        _appendTeksCols(trNew, tbodyId, dSub, isLocked);
        appendKomponenCols(trNew, tbodyId, null);

        // Total / Pajak
        trNew.appendChild(createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-dark txt-total', style: 'white-space:nowrap; min-width:130px;' }));
        const inpPajak = document.createElement('input');
        inpPajak.type = 'text'; inpPajak.name = 'pajak_pct[]';
        inpPajak.className = 'inp-gen text-center text-danger inp-pajak-pct';
        inpPajak.value = pajakParentVal;
        inpPajak.placeholder = '0';
        inpPajak.setAttribute('inputmode', 'decimal');
        if (isLocked) inpPajak.disabled = true;
        inpPajak.oninput = inpPajak.onchange = function() {
            this.value = this.value.replace(/[^0-9.]/g, ''); calcRow(tbodyId);
        };
        const tdPajak = createCell('', { cls: 'align-middle' });
        tdPajak.appendChild(inpPajak); trNew.appendChild(tdPajak);

        // FIX: sama seperti baris utama
        trNew.appendChild(createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-danger txt-potongan td-potongan' }));
        trNew.appendChild(createCell('Rp 0', { cls: 'text-end pe-3 fw-bold align-middle fs-6 text-success txt-netto td-netto' }));

        if (!isLocked) {
            const btnDel = document.createElement('button');
            btnDel.type = 'button'; btnDel.title = 'Hapus Baris Ini';
            btnDel.className = 'btn-action bg-light border text-danger shadow-sm';
            btnDel.innerHTML = '<i class="fas fa-trash"></i>';
            btnDel.onclick = () => delSubRow(trNew, tbodyId);
            const btnAdd = document.createElement('button');
            btnAdd.type = 'button'; btnAdd.title = 'Tambah Baris Komponen (Dosen Sama)';
            btnAdd.className = 'btn-action bg-light border text-success shadow-sm';
            btnAdd.innerHTML = '<i class="fas fa-plus"></i>';
            btnAdd.onclick = () => addSubRowSameDosen(tbodyId);
            const tdAksi = createCell('', { cls: 'text-center align-middle td-aksi' });
            const wrap = document.createElement('div');
            wrap.className = 'd-flex justify-content-center gap-1';
            wrap.appendChild(btnDel); wrap.appendChild(btnAdd);
            tdAksi.appendChild(wrap); trNew.appendChild(tdAksi);
        }

        tbody.appendChild(trNew);

        // FIX KOLOM GESER: pastikan jumlah td di trNew = EXPECTED_COL_COUNT
        const actualColsSub = trNew.querySelectorAll('td').length;
        if (actualColsSub < EXPECTED_COL_COUNT) {
            const diffSub = EXPECTED_COL_COUNT - actualColsSub;
            const txtTotalTdSub = trNew.querySelector('.txt-total');
            for (let i = 0; i < diffSub; i++) {
                const emptyTd = document.createElement('td');
                emptyTd.className = 'align-middle';
                emptyTd.innerHTML = '&nbsp;';
                if (txtTotalTdSub) trNew.insertBefore(emptyTd, txtTotalTdSub);
                else trNew.appendChild(emptyTd);
            }
        }

        // Update rowspan No dan Dosen di tr pertama
        const trFirst = tbody.querySelector('tr:first-child');
        const tdNo    = trFirst.querySelector('.row-no');
        const tdDosen = trFirst.querySelector('.td-dosen');
        const rowCount = tbody.querySelectorAll('tr').length;
        if (tdNo)    tdNo.rowSpan    = rowCount;
        if (tdDosen) tdDosen.rowSpan = rowCount;

        if (jabatan) {
            setTimeout(() => {
                updateJafungTarif(tbodyId, jabatan);
                filterKomponenByJabatan(tbodyId, jabatan);
            }, 50);
        }
        setTimeout(() => calcRow(tbodyId), 0);
    }



    function calcRow(id) {
        const tbody = document.getElementById('hr_' + id);
        if (!tbody) return;
        let total_bruto_tbody = 0, total_potongan_tbody = 0;
        tbody.querySelectorAll('tr').forEach(tr => {
            let bruto_tr = 0;
            tr.querySelectorAll('input[name="rincian_ids[]"]').forEach(ridInp => {
                const rid    = ridInp.value;
                const tdQty  = tr.querySelector(`td[data-rid="${rid}"][data-role="td-qty"]`);
                const tdTarf = tr.querySelector(`td[data-rid="${rid}"][data-role="td-tarif"]`);
                const tdJml  = tr.querySelector(`td[data-rid="${rid}"][data-role="td-jml"]`);
                if (!tdQty || !tdTarf || !tdJml) return;
                const qtyInp = tdQty.querySelector('input[type="number"]');
                const trfInp = tdTarf.querySelector('.inp-tarif');
                const jmlInp = tdJml.querySelector('.inp-jml-display');
                if (!qtyInp || !trfInp || !jmlInp) return;
                const qty = parseFloat(qtyInp.value) || 0, tarif = cleanNum(trfInp.value), jml = qty * tarif;
                bruto_tr += jml;
                jmlInp.value = fmtRp(jml);
                jmlInp.style.color = jml > 0 ? '#0d6efd' : '#94a3b8';
            });
            const pajakInp = tr.querySelector('.inp-pajak-pct');
            const pct      = pajakInp ? cleanPct(pajakInp.value) : 0;
            const potongan = Math.round(bruto_tr * pct / 100);
            const netto    = bruto_tr - potongan;
            const txtBruto = tr.querySelector('.txt-total');
            const txtPot   = tr.querySelector('.txt-potongan');
            const txtNet   = tr.querySelector('.txt-netto');
            if (txtBruto) txtBruto.innerText = 'Rp ' + fmtRp(bruto_tr);
            if (txtPot)   txtPot.innerText   = 'Rp ' + fmtRp(potongan);
            if (txtNet)   txtNet.innerText   = 'Rp ' + fmtRp(netto);
            total_bruto_tbody    += bruto_tr;
            total_potongan_tbody += potongan;
        });
        calcSummary();
    }

    function calcSummary() {
        let sumB = 0, sumP = 0, count = 0;
        document.querySelectorAll('#tblHonorDetail tbody.honor-row').forEach(tbody => {
            count++;
            tbody.querySelectorAll('.txt-total').forEach(b    => { sumB += cleanNum(b.innerText); });
            tbody.querySelectorAll('.txt-potongan').forEach(p  => { sumP += cleanNum(p.innerText); });
        });
        document.getElementById('sumDosen').innerText = count + ' Dosen';
        document.getElementById('sumBruto').innerText = 'Rp ' + fmtRp(sumB);
        document.getElementById('sumPajak').innerText = 'Rp ' + fmtRp(sumP);
        document.getElementById('sumNetto').innerText = 'Rp ' + fmtRp(sumB - sumP);
    }

    function submitHonorDetail(isFinal) {
        if (document.querySelectorAll('#tblHonorDetail tbody.honor-row').length === 0) {
            Swal.fire('Ditolak', 'Minimal harus ada 1 entri dosen!', 'error'); return;
        }
        document.getElementById('inpFinalize').value = isFinal;
        if (isFinal) {
            Swal.fire({ title: 'Finalisasi Honor?', text: 'Data yang difinalisasi tidak bisa diedit kembali. Lanjutkan?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Ya, Finalisasi!' }).then(r => { if (r.isConfirmed) executeSubmit(); });
        } else { executeSubmit(); }
    }

    function executeSubmit() {
        const form = document.getElementById('formDetailGen');
        // Hapus semua snap-submit lama
        form.querySelectorAll('input.snap-submit').forEach(el => el.remove());

        // Iterasi setiap tbody (1 dosen), lalu setiap tr (sub-row)
        document.querySelectorAll('#tblHonorDetail tbody.honor-row').forEach((tbody) => {
            // Ambil dosen_id dari select di tbody
            const selDosen = tbody.querySelector('select[name="dosen_id[]"]');
            const dosenId  = selDosen ? selDosen.value : '';

            tbody.querySelectorAll('tr').forEach(tr => {
                // Pastikan setiap tr punya dosen_id[] tersendiri
                // Cek apakah tr ini sudah punya dosen_id input/select (di dalam td)
                const hasDosenInput = tr.querySelector('td select[name="dosen_id[]"], td input[name="dosen_id[]"]');
                if (!hasDosenInput && dosenId) {
                    // inject hidden dosen_id di dalam td pertama yang ada di tr ini
                    const firstTd = tr.querySelector('td');
                    if (firstTd) {
                        const hid = document.createElement('input');
                        hid.type  = 'hidden';
                        hid.name  = 'dosen_id[]';
                        hid.value = tr.dataset.dosenId || dosenId;
                        hid.className = 'snap-submit';
                        firstTd.appendChild(hid);
                    }
                }

                // Pastikan pajak_pct[] ada di setiap tr (di dalam td)
                const hasPajak = tr.querySelector('td input[name="pajak_pct[]"]');
                if (!hasPajak) {
                    const pajakParent = tbody.querySelector('.inp-pajak-pct');
                    const targetTd = tr.querySelector('td');
                    if (targetTd) {
                        const hidPajak = document.createElement('input');
                        hidPajak.type  = 'hidden';
                        hidPajak.name  = 'pajak_pct[]';
                        hidPajak.value = pajakParent ? pajakParent.value : '0';
                        hidPajak.className = 'snap-submit';
                        targetTd.appendChild(hidPajak);
                    }
                }

                // Sinkronkan nama input qty/tarif/kompId berdasarkan data-rid
                tr.querySelectorAll('td[data-role="td-qty"]').forEach(tdQty => {
                    const rid    = tdQty.dataset.rid;
                    const qtyInp = tdQty.querySelector('input[type="number"]');
                    if (!rid || !qtyInp) return;
                    // Cari tdTarif di tr yang sama
                    const tdTarif = tr.querySelector(`td[data-rid="${rid}"][data-role="td-tarif"]`);
                    const trfInp  = tdTarif ? tdTarif.querySelector('.inp-tarif') : null;
                    const kidInp  = tdTarif ? tdTarif.querySelector('input[type="hidden"]') : null;
                    const hidRid  = tdQty.querySelector('input[name="rincian_ids[]"]');
                    if (hidRid) hidRid.value = rid;
                    qtyInp.name = `komp_qty_${rid}[]`;
                    if (trfInp) trfInp.name = `komp_tarif_${rid}[]`;
                    if (kidInp) kidInp.name = `komp_kompId_${rid}[]`;
                });
            });
        });

        const fd  = new FormData(form);
        const btn = document.querySelector('[onclick="submitHonorDetail(0)"]');
        if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...'; btn.disabled = true; }
        fetch('honorarium_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Berhasil', text: res.message, timer: 1500, showConfirmButton: false }).then(() => {
                        if (document.getElementById('inpFinalize').value == 1) window.location.href = '?page=honorarium&tab=laporan';
                        else window.location.reload();
                    });
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                    if (btn) { btn.innerHTML = '<i class="fas fa-save me-2"></i>Simpan Draft'; btn.disabled = false; }
                }
            });
    }

    function initHonorRows() {
        if (matrixDetails.length > 0) {
            matrixDetails.forEach(d => {
                addHonorMatrixRow(d);
                const capturedRowId = rCount;
                setTimeout(() => {
                    const dosenObj = dosenData.find(dd => String(dd.id) === String(d.dosen_id));
                    if (dosenObj && dosenObj.jabatan_fungsional) {
                        updateJafungTarif(capturedRowId, dosenObj.jabatan_fungsional);
                        filterKomponenByJabatan(capturedRowId, dosenObj.jabatan_fungsional);
                    }
                }, 50);
            });
        } else if (!isLocked) {
            addHonorMatrixRow();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHonorRows);
    } else {
        initHonorRows();
    }
    </script>
<?php endif; ?>
</div>
