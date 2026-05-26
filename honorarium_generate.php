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
    .txt-total, .txt-potongan, .txt-netto { white-space: nowrap; min-width: 130px; }
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
                        <label class="form-label small fw-bold text-muted">Judul Honor (untuk Header PDF)</label>
                        <input type="text" name="judul_honor" id="inpJudulHonor" class="form-control rounded-3 border px-3 py-2" placeholder="Contoh: Honor Pembuat Soal UTS Ganjil 2025/2026">
                        <div class="form-text text-muted">Ditampilkan sebagai judul di tengah dokumen PDF (bawah nama institusi).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Periode Semester (untuk Header PDF)</label>
                        <input type="text" name="periode_semester" id="inpPeriodeSmt" class="form-control rounded-3 border px-3 py-2" placeholder="Contoh: Semester Ganjil 2025/2026">
                        <div class="form-text text-muted">Ditampilkan sebagai keterangan periode di header dokumen PDF.</div>
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
    function editHeaderGen(g) { document.getElementById('actionGen').value = 'edit_generate_header'; document.getElementById('editGenId').value = g.id; document.getElementById('inpNamaGen').value = g.nama_generate; document.getElementById('inpJudulHonor').value = g.judul_honor || ''; document.getElementById('inpPeriodeSmt').value = g.periode_semester || ''; document.getElementById('inpTemplateGen').value = g.template_id; document.getElementById('inpBlnGen').value = g.periode_bulan; document.getElementById('inpThnGen').value = g.periode_tahun; document.getElementById('titleGen').innerHTML = '<i class="fas fa-edit me-2 text-warning"></i>Edit Batch Generate'; bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNewGen')).show(); }
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
            // Mode 1 Kolom: QTY (dengan satuan dari komponen) | TARIF | JUMLAH
            // Ambil satuan dari rincian komponen pertama
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
                $cs += 1; // tambah 1 untuk kolom uraian
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

    $hdr1 .= "<th rowspan='2' style='min-width:130px; width:130px;'>TOTAL BRUTO</th><th rowspan='2' style='min-width:80px; width:80px;'>PAJAK (%)</th><th rowspan='2' style='min-width:130px; width:130px;'>POTONGAN</th><th rowspan='2' style='min-width:150px; width:150px;' class='text-end pe-4'>HONOR DITERIMA</th>";
    if(!$is_locked) $hdr1 .= "<th rowspan='2' style='min-width:90px; width:90px; text-align:center;'>Aksi</th>";
?>
    <div class="card border border-primary border-4 border-start-0 border-end-0 border-bottom-0 rounded-4 shadow-sm bg-white mb-3">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light">
            <div>
                <span class="badge <?= $is_locked?'bg-success':'bg-secondary' ?> px-3 py-1 rounded-pill mb-1 fw-bold"><?= strtoupper($gen_head['status']) ?></span>
                <h5 class="fw-bold mb-0 text-dark"><?= $gen_head['nama_generate'] ?></h5>
                <?php if(!empty($gen_head['judul_honor'])): ?>
                <div class="small text-primary fw-bold mt-1"><i class="fas fa-file-alt me-1"></i><?= htmlspecialchars($gen_head['judul_honor']) ?></div>
                <?php endif; ?>
                <?php if(!empty($gen_head['periode_semester'])): ?>
                <div class="small text-muted mt-1"><i class="fas fa-calendar me-1"></i><?= htmlspecialchars($gen_head['periode_semester']) ?></div>
                <?php endif; ?>
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
            <table class="table table-gen mb-0" id="tblHonorDetail" style="min-width: 1800px; table-layout: auto;">
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
    //  HONOR GENERATE — JAVASCRIPT ENGINE (FINAL STABLE v3)
    //  Metode insert baris: createElement + appendChild
    //  Ini satu-satunya cara aman memasukkan <tr> ke <tbody> di semua
    //  browser. insertAdjacentHTML/innerHTML di-strip oleh HTML parser
    //  saat target adalah <table> atau <tbody>.
    // ================================================================

    const isLocked      = <?= $is_locked ? 'true' : 'false' ?>;
    const dosenData     = <?= json_encode($dosen_list) ?>;
    const prodiList     = <?= json_encode($prodi_list ?? []) ?>;
    const matrixDetails = <?= json_encode(array_values($matrix_details)) ?>;

    const teksCols    = <?= json_encode($teks_cols) ?>;
    const horizGroups = <?= json_encode($horiz_groups) ?>;
    const vertGroup   = <?= json_encode($vert_group_info) ?>;
    const masterTarif = <?= json_encode($rincian_master) ?>;
    // Lookup tarif berdasarkan jabatan fungsional: jafungTarif[komp_id][jabatan] = {id, besaran, potongan_pajak}
    const jafungTarif = <?= json_encode($jafung_tarif_map ?? []) ?>;

    let rCount = 0;

    // ── Helper format & clean angka ──────────────────────────────────
    function fmtRp(val) {
        return new Intl.NumberFormat('id-ID').format(Math.round(parseFloat(val) || 0));
    }
    function cleanNum(str) {
        // Hapus semua karakter non-digit (titik ribuan, "Rp", spasi, dll)
        return parseFloat(String(str).replace(/[^0-9]/g, '')) || 0;
    }
    function cleanPct(str) {
        // Untuk persen: izinkan titik desimal, ganti koma jadi titik
        return parseFloat(String(str).replace(',', '.').replace(/[^0-9.]/g, '')) || 0;
    }

    // ── Opsi dropdown dosen & prodi ──────────────────────────────────
    let dosenOpts = [{ val: '', lbl: '-- Pilih Dosen --', prodi: '', jabatan: '' }];
    dosenData.forEach(d => dosenOpts.push({
        val: d.id, lbl: d.nama,
        prodi: d.program_studi,
        jabatan: d.jabatan_fungsional || ''
    }));

    function syncProdi(selDosen, rowId) {
        const opt = selDosen.options[selDosen.selectedIndex];
        // Isi kolom Prodi
        const pInp = document.querySelector(`#hr_${rowId} .inp-prodi`);
        if (pInp) pInp.value = (opt && opt.value) ? (opt.dataset.prodi || '') : '';
        // FIX: Isi dropdown Jabatan Fungsional otomatis dari data dosen
        const jSel = document.querySelector(`#hr_${rowId} .inp-jabatan`);
        const jabatan = (opt && opt.value) ? (opt.dataset.jabatan || '') : '';
        if (jSel) {
            jSel.value = jabatan;
        }
        // Update tarif per jabatan fungsional & filter komponen
        updateJafungTarif(rowId, jabatan);
        filterKomponenByJabatan(rowId, jabatan);
    }

    /**
     * updateJafungTarif — update nilai tarif pada sel-sel yang is_jafung=true
     * berdasarkan jabatan fungsional dosen yang dipilih.
     * FIX: Mencakup horizGroups DAN vertGroup, plus fallback is_jafung dari masterTarif.
     */
    function updateJafungTarif(rowId, jabatan) {
        const tbody = document.getElementById(`hr_${rowId}`);
        if (!tbody) return;

        let pajakBaru = null;

        // ── Helper: proses satu item komponen ────────────────────────
        function processItem(c) {
            const rid   = c.id_rincian;
            const mData = masterTarif[rid] || null;
            if (!mData) return;

            // Cek is_jafung: dari template JSON atau dari masterTarif (fallback)
            const isJafung = c.is_jafung || (String(mData.is_jafung) === '1');
            if (!isJafung) return; // skip jika bukan jafung

            const kompId = String(mData.komp_id);

            // Cari tarif yang cocok dengan jabatan dosen
            let tarifBaru = mData.besaran;
            let pajak     = mData.potongan_pajak;
            let ridBaru   = String(rid);

            if (jabatan && jafungTarif[kompId] && jafungTarif[kompId][jabatan]) {
                const jt  = jafungTarif[kompId][jabatan];
                tarifBaru = jt.besaran;
                pajak     = jt.potongan_pajak;
                ridBaru   = String(jt.id);
            }

            // Update hidden rincian_ids di td-qty dengan rid baru
            const tdQty = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-qty"]`);
            if (tdQty) {
                const hidRid = tdQty.querySelector('input[name="rincian_ids[]"]');
                if (hidRid) {
                    // Ganti data-rid di semua td yang masih pakai rid lama
                    if (ridBaru !== String(rid)) {
                        tbody.querySelectorAll(`td[data-rid="${rid}"]`).forEach(td => {
                            td.dataset.rid = ridBaru;
                        });
                    }
                    hidRid.value = ridBaru;
                }
                // BUGFIX: update qty/kompId input names to match new rid
                if (ridBaru !== String(rid)) {
                    const qtyInp = tdQty.querySelector('.inp-qty');
                    if (qtyInp) qtyInp.name = `komp_qty_${ridBaru}[]`;
                }
            }

            // Update tarif di td-tarif (pakai ridBaru karena data-rid sudah diupdate)
            const tdTarif = tbody.querySelector(`td[data-rid="${ridBaru}"][data-role="td-tarif"]`);
            if (tdTarif) {
                const trfInp = tdTarif.querySelector('.inp-tarif');
                if (trfInp) {
                    trfInp.value = fmtRp(tarifBaru);
                    // BUGFIX: update tarif/kompId input names to match new rid
                    if (ridBaru !== String(rid)) {
                        trfInp.name = `komp_tarif_${ridBaru}[]`;
                        const kidInp = tdTarif.querySelector(`input[name="komp_kompId_${rid}[]"]`);
                        if (kidInp) kidInp.name = `komp_kompId_${ridBaru}[]`;
                    }
                    // Highlight visual singkat agar user tahu tarif berubah
                    trfInp.style.background = '#fef9c3';
                    trfInp.style.color = '#b45309';
                    setTimeout(() => {
                        trfInp.style.background = '';
                        trfInp.style.color = '';
                    }, 1500);
                }
            }

            if (pajakBaru === null) pajakBaru = pajak;
        }

        // ── Proses semua grup horizontal ─────────────────────────────
        for (const g in horizGroups) {
            const firstItem = horizGroups[g][0] || {};
            const gSingleCol = firstItem.single_jafung_col || false;
            
            if (gSingleCol && jabatan) {
                // MODE SINGLE COL: update rid dan tarif berdasarkan jabatan
                const items = horizGroups[g];
                const mDataFirst = masterTarif[items[0].id_rincian] || null;
                if (mDataFirst) {
                    const kompId = String(mDataFirst.komp_id);
                    if (jafungTarif[kompId] && jafungTarif[kompId][jabatan]) {
                        const jt = jafungTarif[kompId][jabatan];
                        const ridBaru = String(jt.id);
                        const tarifBaru = jt.besaran;
                        if (pajakBaru === null) pajakBaru = jt.potongan_pajak;
                        
                        // Find the single-col td by group name
                        const tdQty = tbody.querySelector(`td[data-single-col="true"][data-group-name="${g}"][data-role="td-qty"]`);
                        if (tdQty) {
                            const hidRid = tdQty.querySelector('input[name="rincian_ids[]"]');
                            const oldRid = tdQty.dataset.rid;
                            if (hidRid) hidRid.value = ridBaru;
                            // Update data-rid on all related tds
                            tbody.querySelectorAll(`td[data-single-col="true"][data-group-name="${g}"]`).forEach(td => {
                                td.dataset.rid = ridBaru;
                            });
                            // Update input names to match new rid
                            const qtyInp = tdQty.querySelector('.inp-qty');
                            if (qtyInp) qtyInp.name = `komp_qty_${ridBaru}[]`;
                        }
                        const tdTarif = tbody.querySelector(`td[data-single-col="true"][data-group-name="${g}"][data-role="td-tarif"]`);
                        if (tdTarif) {
                            const trfInp = tdTarif.querySelector('.inp-tarif');
                            if (trfInp) {
                                trfInp.value = fmtRp(tarifBaru);
                                trfInp.name = `komp_tarif_${ridBaru}[]`;
                                trfInp.style.background = '#fef9c3';
                                trfInp.style.color = '#b45309';
                                setTimeout(() => { trfInp.style.background = ''; trfInp.style.color = ''; }, 1500);
                            }
                            const kidInp = tdTarif.querySelector('input[type="hidden"]');
                            if (kidInp) { kidInp.value = mDataFirst.komp_id; kidInp.name = `komp_kompId_${ridBaru}[]`; }
                        }
                    }
                }
            } else {
                horizGroups[g].forEach(c => processItem(c));
            }
        }

        // ── Proses grup vertikal ──────────────────────────────────────
        const vItems = vertGroup.items || [];
        vItems.forEach(c => processItem(c));

        // Update pajak jika ada nilai baru
        if (pajakBaru !== null) {
            const pajakInp = tbody.querySelector('.inp-pajak-pct');
            if (pajakInp) pajakInp.value = pajakBaru;
        }

        calcRow(rowId);
    }

    /**
     * filterKomponenByJabatan — Menyembunyikan/menampilkan komponen honor
     * berdasarkan jabatan fungsional yang dipilih.
     * Komponen yang is_jafung=true dan memiliki jabatan_fungsional berbeda
     * dari jabatan yang dipilih akan di-hide (qty di-set 0 & visual hidden).
     * Hanya komponen yang sesuai jabatan yang ditampilkan.
     */
    function filterKomponenByJabatan(rowId, jabatan) {
        const tbody = document.getElementById(`hr_${rowId}`);
        if (!tbody) return;

        // Kumpulkan semua item komponen yang is_jafung dari horizGroups dan vertGroup
        const allItems = [...Object.values(horizGroups).flat(), ...(vertGroup.items || [])];

        allItems.forEach(c => {
            const rid   = String(c.id_rincian);
            const mData = masterTarif[rid] || null;
            if (!mData) return;

            const isJafung = c.is_jafung || (String(mData.is_jafung) === '1');
            if (!isJafung) return; // Non-jafung selalu tampil
            
            // Skip items dari single_jafung_col groups (ditangani oleh updateJafungTarif)
            if (c.single_jafung_col) return;

            const kompId = String(mData.komp_id);

            // Cek apakah rincian ini cocok dengan jabatan yang dipilih
            let shouldShow = true;
            if (jabatan && jafungTarif[kompId]) {
                // Jika komponen ini punya mapping per jabatan, cek kecocokan
                const jabatanRincian = mData.jabatan_fungsional || '';
                if (jabatanRincian && jabatanRincian !== jabatan) {
                    shouldShow = false;
                }
            }

            // Cari semua td yang terkait rid ini (bisa jadi sudah berubah ridnya via updateJafungTarif)
            // Kita cari berdasarkan rid asli terlebih dahulu
            let tdQty  = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-qty"]`);
            let tdTarif = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-tarif"]`);
            let tdJml  = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-jml"]`);

            // Untuk vertical items, cari juga row label-nya
            const vertItems = vertGroup.items || [];
            const isVertItem = vertItems.some(vi => String(vi.id_rincian) === rid);

            if (shouldShow) {
                if (tdQty)   tdQty.style.display = '';
                if (tdTarif) tdTarif.style.display = '';
                if (tdJml)   tdJml.style.display = '';
                // Untuk vertikal, tampilkan juga tr-nya
                if (isVertItem && tdQty) {
                    const tr = tdQty.closest('tr');
                    if (tr) tr.style.display = '';
                }
            } else {
                if (tdQty) {
                    tdQty.style.display = 'none';
                    // Set qty ke 0 agar tidak dihitung
                    const qtyInp = tdQty.querySelector('input[type="number"]');
                    if (qtyInp) qtyInp.value = 0;
                }
                if (tdTarif) tdTarif.style.display = 'none';
                if (tdJml)   tdJml.style.display = 'none';
                // Untuk vertikal, sembunyikan juga tr-nya
                if (isVertItem && tdQty) {
                    const tr = tdQty.closest('tr');
                    if (tr) tr.style.display = 'none';
                }
            }
        });

        // Recalculate setelah filter
        calcRow(rowId);
    }

    // ================================================================
    //  createCell — Buat satu <td> dengan innerHTML aman
    // ================================================================
    function createCell(innerHtml, opts = {}) {
        const td = document.createElement('td');
        if (opts.rowspan > 1) td.rowSpan = opts.rowspan;
        if (opts.cls)  td.className = opts.cls;
        if (opts.style) td.style.cssText = opts.style;
        if (opts.dataRid)  td.dataset.rid  = opts.dataRid;
        if (opts.dataRole) td.dataset.role = opts.dataRole;
        td.innerHTML = innerHtml;
        return td;
    }

    // ================================================================
    //  addHonorMatrixRow — INSERT BARIS BARU KE TABEL
    //  Menggunakan createElement + appendChild agar tidak di-strip browser
    // ================================================================
    function addHonorMatrixRow(d = null) {
        rCount++;
        const id = rCount;

        const vItems   = vertGroup.items || [];
        const rs       = vItems.length > 0 ? vItems.length : 1;
        const readOnly = isLocked;

        // Ambil pajak default dari komponen pertama
        let pajak_base = 0;
        const allItems = [...Object.values(horizGroups).flat(), ...vItems];
        for (const c of allItems) {
            const rid = c.id_rincian;
            if (d?.komponen?.[rid]) { pajak_base = d.komponen[rid].pajak || 0; break; }
            else if (masterTarif[rid]) { pajak_base = masterTarif[rid].potongan_pajak || 0; break; }
        }

        // ── Buat <tbody> baru ────────────────────────────────────────
        const tbody = document.createElement('tbody');
        tbody.id        = `hr_${id}`;
        tbody.className = 'honor-row bg-white';

        // ── Helper: buat <tr> ────────────────────────────────────────
        function mkTr() {
            const tr = document.createElement('tr');
            tbody.appendChild(tr);
            return tr;
        }

        // ── BARIS PERTAMA ────────────────────────────────────────────
        const tr1 = mkTr();

        // No
        tr1.appendChild(createCell(id, { cls: 'text-center align-middle fw-bold row-no', rowspan: rs }));

        // Dropdown Dosen
        const selDosen = document.createElement('select');
        selDosen.name      = 'dosen_id[]';
        selDosen.className = 'inp-gen text-dark inp-dosen-w';
        selDosen.required  = true;
        if (readOnly) selDosen.disabled = true;
        selDosen.onchange  = () => syncProdi(selDosen, id);
        dosenOpts.forEach(o => {
            const opt    = document.createElement('option');
            opt.value    = o.val;
            opt.text     = o.lbl;
            opt.dataset.prodi   = o.prodi;
            opt.dataset.jabatan = o.jabatan;
            if (d && String(o.val) === String(d.dosen_id)) opt.selected = true;
            selDosen.appendChild(opt);
        });
        const tdDosen = createCell('', { cls: 'text-start align-middle', rowspan: rs });
        tdDosen.appendChild(selDosen);
        tr1.appendChild(tdDosen);

        // Kolom teks (prodi, mata kuliah, jabatan, dll)
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

            const tdT = createCell('', { cls: 'align-middle', rowspan: rs });

            // FIX: Jabatan Fungsional menggunakan dropdown <select> bukan input teks
            if (c.source === 'jabatan') {
                const selJabatan = document.createElement('select');
                selJabatan.name      = `teks_${c.source}[]`;
                selJabatan.className = 'inp-gen text-dark inp-teks-w inp-jabatan';
                if (readOnly) selJabatan.disabled = true;

                const jabatanOptions = ['', 'Tenaga Pengajar', 'Asisten Ahli', 'Lektor', 'Lektor Kepala', 'Profesor'];
                jabatanOptions.forEach(jOpt => {
                    const opt = document.createElement('option');
                    opt.value = jOpt;
                    opt.text  = jOpt === '' ? '-- Pilih Jabatan --' : jOpt;
                    if (jOpt === val) opt.selected = true;
                    selJabatan.appendChild(opt);
                });

                // Saat user mengubah jabatan fungsional, update tarif & filter komponen
                selJabatan.onchange = function() {
                    updateJafungTarif(id, this.value);
                    filterKomponenByJabatan(id, this.value);
                };

                tdT.appendChild(selJabatan);
            } else {
                const inp = document.createElement('input');
                inp.type  = 'text';
                inp.name  = `teks_${c.source}[]`;
                inp.value = val;
                let extraClass = '';
                if (c.source === 'prodi') extraClass = ' inp-prodi';
                inp.className = 'inp-gen text-dark inp-teks-w' + extraClass;
                if (readOnly || c.source === 'prodi') inp.readOnly = true;
                if (readOnly) inp.disabled = true;
                if (c.source !== 'prodi') inp.required = true;

                // Datalist untuk prodi
                if (c.source === 'prodi') {
                    const dl = document.createElement('datalist');
                    dl.id = `dlProdi_${id}`;
                    prodiList.forEach(p => {
                        const op = document.createElement('option'); op.value = p; dl.appendChild(op);
                    });
                    inp.setAttribute('list', `dlProdi_${id}`);
                    tdT.appendChild(dl);
                }

                tdT.appendChild(inp);
            }

            tr1.appendChild(tdT);
        });

        // ── Kolom grup Horizontal ────────────────────────────────────
        for (const g in horizGroups) {
            const firstItem  = horizGroups[g][0] || {};
            const gHeader    = firstItem.group_header || '';
            const gIsJafung  = firstItem.is_jafung || false;
            const gSingleCol = firstItem.single_jafung_col || false;

            if (gSingleCol) {
                // ═══ MODE SINGLE COLUMN: 1 set QTY | TARIF | JUMLAH ═══
                // Tarif otomatis menyesuaikan jabatan fungsional dosen
                const items = horizGroups[g];
                // Tentukan rid yang cocok berdasarkan jabatan dosen (atau pakai item pertama sebagai default)
                let activeRid = items[0].id_rincian;
                let activeData = masterTarif[activeRid] || null;
                let q = 0, t = activeData ? activeData.besaran : 0;
                
                // Jika ada data existing, cari rid yang punya data
                if (d) {
                    for (const c of items) {
                        if (d.komponen?.[c.id_rincian] && d.komponen[c.id_rincian].qty > 0) {
                            activeRid = c.id_rincian;
                            activeData = masterTarif[activeRid] || null;
                            q = d.komponen[activeRid].qty;
                            t = d.komponen[activeRid].tarif;
                            break;
                        }
                    }
                }
                
                // Jika dosen sudah dipilih, cari tarif sesuai jabatan
                if (d && d.dosen_jabatan) {
                    const kompId = activeData ? String(activeData.komp_id) : '';
                    if (kompId && jafungTarif[kompId] && jafungTarif[kompId][d.dosen_jabatan]) {
                        const jt = jafungTarif[kompId][d.dosen_jabatan];
                        activeRid = String(jt.id);
                        t = jt.besaran;
                    }
                }

                // Hidden input untuk menyimpan semua rids dari grup ini (untuk referensi)
                const inpHid = document.createElement('input');
                inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = activeRid;
                // Data attribute untuk menyimpan info grup single col
                const inpGrpInfo = document.createElement('input');
                inpGrpInfo.type = 'hidden'; inpGrpInfo.name = `single_col_group[]`; inpGrpInfo.value = g;
                
                // td QTY
                const inpQty = document.createElement('input');
                inpQty.type = 'number'; inpQty.name = `komp_qty_${activeRid}[]`;
                inpQty.className = 'inp-gen text-center inp-qty';
                inpQty.value = q; inpQty.step = '0.01'; inpQty.min = '0';
                if (readOnly) inpQty.disabled = true;
                inpQty.oninput = inpQty.onchange = () => calcRow(id);
                const tdQty = createCell('', { cls: 'cell-qty align-middle', rowspan: rs, dataRid: activeRid, dataRole: 'td-qty' });
                tdQty.dataset.singleCol = 'true';
                tdQty.dataset.groupName = g;
                tdQty.appendChild(inpHid); tdQty.appendChild(inpGrpInfo); tdQty.appendChild(inpQty);
                tr1.appendChild(tdQty);

                // td Tarif
                const inpTrf = document.createElement('input');
                inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${activeRid}[]`;
                inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t);
                inpTrf.readOnly = true; inpTrf.tabIndex = -1;
                const inpKid = document.createElement('input');
                inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${activeRid}[]`;
                inpKid.value = activeData ? activeData.komp_id : 0;
                const tdTrf = createCell('', { cls: 'cell-nom align-middle', rowspan: rs, dataRid: activeRid, dataRole: 'td-tarif' });
                tdTrf.dataset.singleCol = 'true';
                tdTrf.dataset.groupName = g;
                tdTrf.appendChild(inpTrf); tdTrf.appendChild(inpKid);
                tr1.appendChild(tdTrf);

                // td Jumlah
                const inpJml = document.createElement('input');
                inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display';
                inpJml.value = q > 0 ? fmtRp(q * t) : '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
                const tdJml = createCell('', { cls: 'cell-tot align-middle', rowspan: rs, dataRid: activeRid, dataRole: 'td-jml' });
                tdJml.dataset.singleCol = 'true';
                tdJml.dataset.groupName = g;
                tdJml.appendChild(inpJml);
                tr1.appendChild(tdJml);

            } else {
                // ═══ MODE NORMAL: Per-item columns ═══
                // Jika ada group_header → render 1 kolom input teks uraian (rowspan)
                if (gHeader) {
                    const uraianVal = d?.uraian_horiz?.[g] || '';
                    const inpUraian = document.createElement('input');
                    inpUraian.type      = 'text';
                    inpUraian.name      = `uraian_horiz_${encodeURIComponent(g)}[]`;
                    inpUraian.className = 'inp-gen text-dark';
                    inpUraian.value     = uraianVal;
                    inpUraian.placeholder = gHeader;
                    if (readOnly) inpUraian.disabled = true;
                    const tdUraian = createCell('', { cls: 'align-middle', rowspan: rs, dataRid: `uraian_${g}`, dataRole: 'td-uraian' });
                    tdUraian.appendChild(inpUraian);
                    tr1.appendChild(tdUraian);
                }

                horizGroups[g].forEach(c => {
                    const rid   = c.id_rincian;
                    const mData = masterTarif[rid] || null;
                    let q = 0, t = mData ? mData.besaran : 0;
                    if (d?.komponen?.[rid]) { q = d.komponen[rid].qty; t = d.komponen[rid].tarif; }

                    // td QTY
                    const inpHid = document.createElement('input');
                    inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = rid;
                    const inpQty = document.createElement('input');
                    inpQty.type = 'number'; inpQty.name = `komp_qty_${rid}[]`;
                    inpQty.className = 'inp-gen text-center inp-qty';
                    inpQty.value = q; inpQty.step = '0.01'; inpQty.min = '0';
                    if (readOnly) inpQty.disabled = true;
                    inpQty.oninput = inpQty.onchange = () => calcRow(id);
                    const tdQty = createCell('', { cls: 'cell-qty align-middle', rowspan: rs, dataRid: rid, dataRole: 'td-qty' });
                    tdQty.appendChild(inpHid); tdQty.appendChild(inpQty);
                    tr1.appendChild(tdQty);

                    // td Tarif
                    const inpTrf = document.createElement('input');
                    inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${rid}[]`;
                    inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t);
                    inpTrf.readOnly = true; inpTrf.tabIndex = -1;
                    const inpKid = document.createElement('input');
                    inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${rid}[]`;
                    inpKid.value = mData ? mData.komp_id : 0;
                    const tdTrf = createCell('', { cls: 'cell-nom align-middle', rowspan: rs, dataRid: rid, dataRole: 'td-tarif' });
                    tdTrf.appendChild(inpTrf); tdTrf.appendChild(inpKid);
                    tr1.appendChild(tdTrf);

                    // td Jumlah
                    const inpJml = document.createElement('input');
                    inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display';
                    inpJml.value = q > 0 ? fmtRp(q * t) : '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
                    const tdJml = createCell('', { cls: 'cell-tot align-middle', rowspan: rs, dataRid: rid, dataRole: 'td-jml' });
                    tdJml.appendChild(inpJml);
                    tr1.appendChild(tdJml);
                });
            }
        }

        // ── Kolom grup Vertikal baris-1 ──────────────────────────────
        if (vItems.length > 0) {
            appendVertRow(tr1, vItems[0], d, id, rs);
        }

        // ── Total Bruto, Pajak, Potongan, Netto ──────────────────────
        const tdBruto = createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-dark txt-total', rowspan: rs, style: 'white-space:nowrap; min-width:130px;' });
        tr1.appendChild(tdBruto);

        const inpPajak = document.createElement('input');
        inpPajak.type = 'text'; inpPajak.name = 'pajak_pct[]';
        inpPajak.className = 'inp-gen text-center text-danger inp-pajak-pct';
        inpPajak.value = pajak_base; inpPajak.placeholder = '0';
        inpPajak.setAttribute('inputmode', 'decimal');
        if (readOnly) inpPajak.disabled = true;
        inpPajak.oninput = inpPajak.onchange = function() {
            this.value = this.value.replace(/[^0-9.]/g, '');
            calcRow(id);
        };
        const tdPajak = createCell('', { cls: 'align-middle', rowspan: rs });
        tdPajak.appendChild(inpPajak);
        tr1.appendChild(tdPajak);

        tr1.appendChild(createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-danger txt-potongan', rowspan: rs, style: 'white-space:nowrap; min-width:130px; width:130px;' }));
        tr1.appendChild(createCell('Rp 0', { cls: 'text-end pe-4 fw-bold align-middle fs-6 text-success txt-netto', rowspan: rs, style: 'white-space:nowrap; min-width:150px; width:150px;' }));

        if (!readOnly) {
            const btnDel = document.createElement('button');
            btnDel.type = 'button'; btnDel.title = 'Hapus Baris Ini';
            btnDel.className = 'btn-action bg-light border text-danger shadow-sm';
            btnDel.innerHTML = '<i class="fas fa-trash"></i>';
            btnDel.onclick = () => delHonorRow(id);

            const btnAdd = document.createElement('button');
            btnAdd.type = 'button'; btnAdd.title = 'Tambah Baris Mata Kuliah (Dosen Sama)';
            btnAdd.className = 'btn-action bg-light border text-success shadow-sm';
            btnAdd.innerHTML = '<i class="fas fa-plus"></i>';
            btnAdd.onclick = () => addSubRowSameDosen(id);

            const tdAksi = createCell('', { cls: 'text-center align-middle', rowspan: rs });
            tdAksi.style.cssText = 'min-width:70px;';

            const wrapDiv = document.createElement('div');
            wrapDiv.className = 'd-flex justify-content-center gap-1';
            wrapDiv.appendChild(btnDel);
            wrapDiv.appendChild(btnAdd);
            tdAksi.appendChild(wrapDiv);
            tr1.appendChild(tdAksi);
        }

        // ── Baris ke-2 s/d N untuk grup vertikal ─────────────────────
        for (let i = 1; i < vItems.length; i++) {
            const trN = mkTr();
            appendVertRow(trN, vItems[i], d, id, 1);
        }

        // ── Masukkan tbody ke dalam table ─────────────────────────────
        document.getElementById('tblHonorDetail').appendChild(tbody);

        // Hitung setelah DOM terupdate
        setTimeout(() => calcRow(id), 0);
    }

    // ── Helper: tambah sel vertikal ke dalam <tr> ────────────────────
    function appendVertRow(tr, v, d, rowId, rs) {
        const rid   = v.id_rincian;
        const mData = masterTarif[rid] || null;
        let q = 0, t = mData ? mData.besaran : 0;
        if (d?.komponen?.[rid]) { q = d.komponen[rid].qty; t = d.komponen[rid].tarif; }

        tr.appendChild(createCell(v.label, { cls: 'align-middle bg-light fw-bold text-dark' }));

        const inpHid = document.createElement('input');
        inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = rid;
        const inpQty = document.createElement('input');
        inpQty.type = 'number'; inpQty.name = `komp_qty_${rid}[]`;
        inpQty.className = 'inp-gen text-center inp-qty bg-white';
        inpQty.value = q; inpQty.step = '0.01'; inpQty.min = '0';
        if (isLocked) inpQty.disabled = true;
        inpQty.oninput = inpQty.onchange = () => calcRow(rowId);
        const tdQty = createCell('', { cls: 'cell-qty align-middle', dataRid: rid, dataRole: 'td-qty' });
        tdQty.appendChild(inpHid); tdQty.appendChild(inpQty);
        tr.appendChild(tdQty);

        const inpTrf = document.createElement('input');
        inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${rid}[]`;
        inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t);
        inpTrf.readOnly = true; inpTrf.tabIndex = -1;
        const inpKid = document.createElement('input');
        inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${rid}[]`;
        inpKid.value = mData ? mData.komp_id : 0;
        const tdTrf = createCell('', { cls: 'cell-nom align-middle', dataRid: rid, dataRole: 'td-tarif' });
        tdTrf.appendChild(inpTrf); tdTrf.appendChild(inpKid);
        tr.appendChild(tdTrf);

        const inpJml = document.createElement('input');
        inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display';
        inpJml.value = q > 0 ? fmtRp(q * t) : '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
        const tdJml = createCell('', { cls: 'cell-tot align-middle', dataRid: rid, dataRole: 'td-jml' });
        tdJml.appendChild(inpJml);
        tr.appendChild(tdJml);
    }

    // ================================================================
    //  delHonorRow & reindexRows
    // ================================================================
    function delHonorRow(id) {
        const el = document.getElementById('hr_' + id);
        if (el) el.remove();
        reindexRows();
        calcSummary();
    }

    function reindexRows() {
        let idx = 1;
        document.querySelectorAll('#tblHonorDetail tbody.honor-row .row-no').forEach(td => {
            td.innerText = idx++;
        });
    }

    // ================================================================
    //  addSubRowSameDosen — Tambah baris baru di bawah dengan dosen SAMA
    //  Dosen di-rowspan: baris induk tidak berubah, baris baru = baris
    //  mandiri (entry terpisah) dengan dosen yang sama. Ini menjaga
    //  kompatibilitas dengan sistem submit yang sudah ada.
    // ================================================================
    function addSubRowSameDosen(parentId) {
        const parentTbody = document.getElementById(`hr_${parentId}`);
        if (!parentTbody) return;

        // Ambil data dosen dari baris induk
        const selDosenParent = parentTbody.querySelector('select[name="dosen_id[]"]');
        const dosenId = selDosenParent ? selDosenParent.value : '';
        const dosenNama = selDosenParent ? selDosenParent.options[selDosenParent.selectedIndex]?.text : '';

        if (!dosenId) {
            Swal.fire('Peringatan', 'Pilih dosen terlebih dahulu sebelum menambah baris.', 'warning');
            return;
        }

        // Ambil nilai jabatan dari baris induk
        const selJabatanParent = parentTbody.querySelector('select.inp-jabatan');
        const jabatan = selJabatanParent ? selJabatanParent.value : '';

        // Ambil nilai pajak dari baris induk
        const pajakParent = parentTbody.querySelector('.inp-pajak-pct');
        const pajakVal = pajakParent ? pajakParent.value : '0';

        // Buat data "d" seperti row kosong tapi dosen sama
        const dSub = {
            dosen_id: dosenId,
            prodi: '',
            mata_kuliah: '',
            dosen_jabatan: jabatan,
            komponen: {}
        };

        // Ambil prodi dari baris induk
        const prodiInp = parentTbody.querySelector('input.inp-prodi');
        if (prodiInp) dSub.prodi = prodiInp.value;

        // Buat row baru menggunakan addHonorMatrixRow dengan data dosen yang sama
        rCount++;
        const newId = rCount;

        const vItems   = vertGroup.items || [];
        const rs       = vItems.length > 0 ? vItems.length : 1;

        // Buat tbody baru
        const tbody = document.createElement('tbody');
        tbody.id        = `hr_${newId}`;
        tbody.className = 'honor-row bg-white border-top border-2 border-info border-opacity-25';
        // Tandai sebagai sub-row dari parent agar mudah diidentifikasi
        tbody.dataset.parentId = parentId;

        function mkTr() {
            const tr = document.createElement('tr');
            tbody.appendChild(tr);
            return tr;
        }

        const tr1 = mkTr();

        // No — tampilkan nomor induk dengan suffix (contoh: 1a)
        const parentNo = parentTbody.querySelector('.row-no')?.innerText || '';
        tr1.appendChild(createCell(`${parentNo}+`, { cls: 'text-center align-middle fw-bold row-no text-info', rowspan: rs, style: 'font-size:11px;' }));

        // Dropdown Dosen — sudah dipilih (dosen sama), merge visual dengan baris induk
        const selDosen = document.createElement('select');
        selDosen.name      = 'dosen_id[]';
        selDosen.className = 'inp-gen text-dark inp-dosen-w';
        selDosen.required  = true;
        selDosen.style.cssText = 'background: #f0fff4; border-color: #22c55e;';
        selDosen.onchange  = () => syncProdi(selDosen, newId);
        dosenOpts.forEach(o => {
            const opt    = document.createElement('option');
            opt.value    = o.val;
            opt.text     = o.lbl;
            opt.dataset.prodi   = o.prodi;
            opt.dataset.jabatan = o.jabatan;
            if (String(o.val) === String(dosenId)) opt.selected = true;
            selDosen.appendChild(opt);
        });
        const tdDosen = createCell('', { cls: 'text-start align-middle', rowspan: rs, style: 'background:#f0fff4; border-left:3px solid #22c55e;' });
        tdDosen.appendChild(selDosen);
        tr1.appendChild(tdDosen);

        // Kolom teks (kosong, siap diisi — mata kuliah baru)
        teksCols.forEach(c => {
            let val = '';
            if (c.source === 'prodi')   val = dSub.prodi;
            if (c.source === 'jabatan') val = jabatan;

            const tdT = createCell('', { cls: 'align-middle', rowspan: rs });

            if (c.source === 'jabatan') {
                const selJabatan = document.createElement('select');
                selJabatan.name      = `teks_${c.source}[]`;
                selJabatan.className = 'inp-gen text-dark inp-teks-w inp-jabatan';
                const jabatanOptions = ['', 'Tenaga Pengajar', 'Asisten Ahli', 'Lektor', 'Lektor Kepala', 'Profesor'];
                jabatanOptions.forEach(jOpt => {
                    const opt = document.createElement('option');
                    opt.value = jOpt; opt.text = jOpt === '' ? '-- Pilih Jabatan --' : jOpt;
                    if (jOpt === val) opt.selected = true;
                    selJabatan.appendChild(opt);
                });
                selJabatan.onchange = function() {
                    updateJafungTarif(newId, this.value);
                    filterKomponenByJabatan(newId, this.value);
                };
                tdT.appendChild(selJabatan);
            } else {
                const inp = document.createElement('input');
                inp.type  = 'text';
                inp.name  = `teks_${c.source}[]`;
                inp.value = val;
                let extraClass = '';
                if (c.source === 'prodi') extraClass = ' inp-prodi';
                inp.className = 'inp-gen text-dark inp-teks-w' + extraClass;
                if (c.source === 'prodi') inp.readOnly = true;
                if (c.source !== 'prodi') inp.required = true;
                if (c.source === 'prodi') {
                    const dl = document.createElement('datalist');
                    dl.id = `dlProdi_${newId}`;
                    prodiList.forEach(p => { const op = document.createElement('option'); op.value = p; dl.appendChild(op); });
                    inp.setAttribute('list', `dlProdi_${newId}`);
                    tdT.appendChild(dl);
                }
                tdT.appendChild(inp);
            }
            tr1.appendChild(tdT);
        });

        // Kolom komponen horizontal — semua kosong (qty=0)
        for (const g in horizGroups) {
            const firstItem  = horizGroups[g][0] || {};
            const gHeader    = firstItem.group_header || '';
            const gSingleCol = firstItem.single_jafung_col || false;

            if (gSingleCol) {
                const items   = horizGroups[g];
                let activeRid = items[0].id_rincian;
                let activeData = masterTarif[activeRid] || null;
                let t = activeData ? activeData.besaran : 0;
                if (jabatan) {
                    const kompId = activeData ? String(activeData.komp_id) : '';
                    if (kompId && jafungTarif[kompId] && jafungTarif[kompId][jabatan]) {
                        const jt = jafungTarif[kompId][jabatan];
                        activeRid = String(jt.id); t = jt.besaran;
                    }
                }
                const inpHid = document.createElement('input');
                inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = activeRid;
                const inpGrpInfo = document.createElement('input');
                inpGrpInfo.type = 'hidden'; inpGrpInfo.name = `single_col_group[]`; inpGrpInfo.value = g;
                const inpQty = document.createElement('input');
                inpQty.type = 'number'; inpQty.name = `komp_qty_${activeRid}[]`;
                inpQty.className = 'inp-gen text-center inp-qty'; inpQty.value = 0; inpQty.step = '0.01'; inpQty.min = '0';
                inpQty.oninput = inpQty.onchange = () => calcRow(newId);
                const tdQ = createCell('', { cls: 'cell-qty align-middle', rowspan: rs, dataRid: activeRid, dataRole: 'td-qty' });
                tdQ.dataset.singleCol = 'true'; tdQ.dataset.groupName = g;
                tdQ.appendChild(inpHid); tdQ.appendChild(inpGrpInfo); tdQ.appendChild(inpQty);
                tr1.appendChild(tdQ);
                const inpTrf = document.createElement('input');
                inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${activeRid}[]`;
                inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t);
                inpTrf.readOnly = true; inpTrf.tabIndex = -1;
                const inpKid = document.createElement('input');
                inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${activeRid}[]`; inpKid.value = activeData ? activeData.komp_id : 0;
                const tdT2 = createCell('', { cls: 'cell-nom align-middle', rowspan: rs, dataRid: activeRid, dataRole: 'td-tarif' });
                tdT2.dataset.singleCol = 'true'; tdT2.dataset.groupName = g;
                tdT2.appendChild(inpTrf); tdT2.appendChild(inpKid); tr1.appendChild(tdT2);
                const inpJml = document.createElement('input');
                inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display'; inpJml.value = '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
                const tdJ = createCell('', { cls: 'cell-tot align-middle', rowspan: rs, dataRid: activeRid, dataRole: 'td-jml' });
                tdJ.dataset.singleCol = 'true'; tdJ.dataset.groupName = g; tdJ.appendChild(inpJml); tr1.appendChild(tdJ);
            } else {
                if (gHeader) {
                    const inpUraian = document.createElement('input');
                    inpUraian.type = 'text'; inpUraian.name = `uraian_horiz_${encodeURIComponent(g)}[]`;
                    inpUraian.className = 'inp-gen text-dark'; inpUraian.placeholder = gHeader;
                    const tdU = createCell('', { cls: 'align-middle', rowspan: rs, dataRid: `uraian_${g}`, dataRole: 'td-uraian' });
                    tdU.appendChild(inpUraian); tr1.appendChild(tdU);
                }
                horizGroups[g].forEach(c => {
                    const rid   = c.id_rincian;
                    const mData = masterTarif[rid] || null;
                    const t     = mData ? mData.besaran : 0;
                    const inpHid = document.createElement('input');
                    inpHid.type = 'hidden'; inpHid.name = 'rincian_ids[]'; inpHid.value = rid;
                    const inpQty = document.createElement('input');
                    inpQty.type = 'number'; inpQty.name = `komp_qty_${rid}[]`;
                    inpQty.className = 'inp-gen text-center inp-qty'; inpQty.value = 0; inpQty.step = '0.01'; inpQty.min = '0';
                    inpQty.oninput = inpQty.onchange = () => calcRow(newId);
                    const tdQ = createCell('', { cls: 'cell-qty align-middle', rowspan: rs, dataRid: rid, dataRole: 'td-qty' });
                    tdQ.appendChild(inpHid); tdQ.appendChild(inpQty); tr1.appendChild(tdQ);
                    const inpTrf = document.createElement('input');
                    inpTrf.type = 'text'; inpTrf.name = `komp_tarif_${rid}[]`;
                    inpTrf.className = 'inp-gen inp-nom inp-tarif'; inpTrf.value = fmtRp(t);
                    inpTrf.readOnly = true; inpTrf.tabIndex = -1;
                    const inpKid = document.createElement('input');
                    inpKid.type = 'hidden'; inpKid.name = `komp_kompId_${rid}[]`; inpKid.value = mData ? mData.komp_id : 0;
                    const tdT2 = createCell('', { cls: 'cell-nom align-middle', rowspan: rs, dataRid: rid, dataRole: 'td-tarif' });
                    tdT2.appendChild(inpTrf); tdT2.appendChild(inpKid); tr1.appendChild(tdT2);
                    const inpJml = document.createElement('input');
                    inpJml.type = 'text'; inpJml.className = 'inp-gen inp-nom inp-jml-display'; inpJml.value = '0'; inpJml.readOnly = true; inpJml.tabIndex = -1;
                    const tdJ = createCell('', { cls: 'cell-tot align-middle', rowspan: rs, dataRid: rid, dataRole: 'td-jml' });
                    tdJ.appendChild(inpJml); tr1.appendChild(tdJ);
                });
            }
        }

        // Kolom vertikal baris-1
        if (vItems.length > 0) appendVertRow(tr1, vItems[0], null, newId, rs);

        // Total, Pajak, Potongan, Netto
        const tdBruto = createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-dark txt-total', rowspan: rs, style: 'white-space:nowrap; min-width:130px;' });
        tr1.appendChild(tdBruto);
        const inpPajak = document.createElement('input');
        inpPajak.type = 'text'; inpPajak.name = 'pajak_pct[]';
        inpPajak.className = 'inp-gen text-center text-danger inp-pajak-pct';
        inpPajak.value = pajakVal; inpPajak.placeholder = '0';
        inpPajak.setAttribute('inputmode', 'decimal');
        inpPajak.oninput = inpPajak.onchange = function() {
            this.value = this.value.replace(/[^0-9.]/g, '');
            calcRow(newId);
        };
        const tdPajak = createCell('', { cls: 'align-middle', rowspan: rs });
        tdPajak.appendChild(inpPajak); tr1.appendChild(tdPajak);
        tr1.appendChild(createCell('Rp 0', { cls: 'text-end fw-bold align-middle text-danger txt-potongan', rowspan: rs, style: 'white-space:nowrap; min-width:130px; width:130px;' }));
        tr1.appendChild(createCell('Rp 0', { cls: 'text-end pe-4 fw-bold align-middle fs-6 text-success txt-netto', rowspan: rs, style: 'white-space:nowrap; min-width:150px; width:150px;' }));

        // Tombol aksi
        const btnDelSub = document.createElement('button');
        btnDelSub.type = 'button'; btnDelSub.title = 'Hapus Baris Ini';
        btnDelSub.className = 'btn-action bg-light border text-danger shadow-sm';
        btnDelSub.innerHTML = '<i class="fas fa-trash"></i>';
        btnDelSub.onclick = () => { tbody.remove(); reindexRows(); calcSummary(); };
        const btnAddSub = document.createElement('button');
        btnAddSub.type = 'button'; btnAddSub.title = 'Tambah Baris Mata Kuliah (Dosen Sama)';
        btnAddSub.className = 'btn-action bg-light border text-success shadow-sm';
        btnAddSub.innerHTML = '<i class="fas fa-plus"></i>';
        btnAddSub.onclick = () => addSubRowSameDosen(newId);
        const tdAksi = createCell('', { cls: 'text-center align-middle', rowspan: rs, style: 'min-width:70px;' });
        const wrapDiv = document.createElement('div');
        wrapDiv.className = 'd-flex justify-content-center gap-1';
        wrapDiv.appendChild(btnDelSub); wrapDiv.appendChild(btnAddSub);
        tdAksi.appendChild(wrapDiv); tr1.appendChild(tdAksi);

        // Baris vertikal ke-2 dst
        for (let i = 1; i < vItems.length; i++) {
            const trN = mkTr();
            appendVertRow(trN, vItems[i], null, newId, 1);
        }

        // Sisipkan tbody baru SETELAH tbody induk
        parentTbody.insertAdjacentElement('afterend', tbody);

        // Sinkronkan jabatan & tarif jika jabatan sudah terisi
        if (jabatan) {
            setTimeout(() => {
                updateJafungTarif(newId, jabatan);
                filterKomponenByJabatan(newId, jabatan);
            }, 50);
        }

        setTimeout(() => calcRow(newId), 0);
    }

    // ================================================================
    //  calcRow — Hitung QTY × Tarif, Pajak, Netto per baris
    // ================================================================
    function calcRow(id) {
        const tbody = document.getElementById('hr_' + id);
        if (!tbody) return;

        let total_bruto = 0;

        tbody.querySelectorAll('input[name="rincian_ids[]"]').forEach(ridInp => {
            const rid    = ridInp.value;
            const tdQty  = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-qty"]`);
            const tdTarf = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-tarif"]`);
            const tdJml  = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-jml"]`);
            if (!tdQty || !tdTarf || !tdJml) return;

            const qtyInp = tdQty.querySelector('input[type="number"]');
            const trfInp = tdTarf.querySelector('.inp-tarif');
            const jmlInp = tdJml.querySelector('.inp-jml-display');
            if (!qtyInp || !trfInp || !jmlInp) return;

            const qty   = parseFloat(qtyInp.value) || 0;
            const tarif = cleanNum(trfInp.value);   // "4.000" → 4000
            const jml   = qty * tarif;
            total_bruto += jml;

            jmlInp.value = fmtRp(jml);
            jmlInp.style.color = jml > 0 ? '#0d6efd' : '#94a3b8';
        });

        // Hitung pajak & netto
        const pajakInp = tbody.querySelector('.inp-pajak-pct');
        const pct      = pajakInp ? cleanPct(pajakInp.value) : 0;
        const potongan = Math.round(total_bruto * pct / 100);
        const netto    = total_bruto - potongan;

        const txtBruto = tbody.querySelector('.txt-total');
        const txtPot   = tbody.querySelector('.txt-potongan');
        const txtNet   = tbody.querySelector('.txt-netto');

        if (txtBruto) txtBruto.innerText = 'Rp ' + fmtRp(total_bruto);
        if (txtPot)   txtPot.innerText   = 'Rp ' + fmtRp(potongan);
        if (txtNet)   txtNet.innerText   = 'Rp ' + fmtRp(netto);

        calcSummary();
    }

    // ================================================================
    //  calcSummary — Update ringkasan bawah halaman
    // ================================================================
    function calcSummary() {
        let sumB = 0, sumP = 0, count = 0;
        document.querySelectorAll('#tblHonorDetail tbody.honor-row').forEach(r => {
            count++;
            const b = r.querySelector('.txt-total');
            const p = r.querySelector('.txt-potongan');
            if (b) sumB += cleanNum(b.innerText);
            if (p) sumP += cleanNum(p.innerText);
        });
        document.getElementById('sumDosen').innerText = count + ' Dosen';
        document.getElementById('sumBruto').innerText = 'Rp ' + fmtRp(sumB);
        document.getElementById('sumPajak').innerText = 'Rp ' + fmtRp(sumP);
        document.getElementById('sumNetto').innerText = 'Rp ' + fmtRp(sumB - sumP);
    }

    // ================================================================
    //  submitHonorDetail & executeSubmit
    // ================================================================
    function submitHonorDetail(isFinal) {
        if (document.querySelectorAll('#tblHonorDetail tbody.honor-row').length === 0) {
            Swal.fire('Ditolak', 'Minimal harus ada 1 entri dosen!', 'error'); return;
        }
        document.getElementById('inpFinalize').value = isFinal;
        if (isFinal) {
            Swal.fire({
                title: 'Finalisasi Honor?',
                text: 'Data yang difinalisasi tidak bisa diedit kembali. Lanjutkan?',
                icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#0d6efd', confirmButtonText: 'Ya, Finalisasi!'
            }).then(r => { if (r.isConfirmed) executeSubmit(); });
        } else {
            executeSubmit();
        }
    }

    function executeSubmit() {
        // ── Snapshot state aktif ke hidden inputs sebelum submit ──────
        // Masalah: saat single_jafung_col, rid di DOM bisa berganti-ganti
        // sehingga komp_qty_{rid}[] tidak konsisten. Solusi: buat snapshot
        // flat (satu per dosen per grup) yang pasti konsisten.
        const form = document.getElementById('formDetailGen');

        // Hapus snapshot lama jika ada
        form.querySelectorAll('input.snap-submit').forEach(el => el.remove());

        document.querySelectorAll('#tblHonorDetail tbody.honor-row').forEach((tbody, rowIdx) => {
            // Kumpulkan semua td[data-role="td-qty"] dalam baris ini
            tbody.querySelectorAll('td[data-role="td-qty"]').forEach(tdQty => {
                const rid    = tdQty.dataset.rid;
                const qtyInp = tdQty.querySelector('input[type="number"]');
                if (!rid || !qtyInp) return;
                const qty = parseFloat(qtyInp.value) || 0;
                if (qty <= 0) return; // skip kosong

                // Ambil tarif dari td-tarif
                const tdTarif = tbody.querySelector(`td[data-rid="${rid}"][data-role="td-tarif"]`);
                const trfInp  = tdTarif ? tdTarif.querySelector('.inp-tarif') : null;
                const tarif   = trfInp ? cleanNum(trfInp.value) : 0;

                // Ambil kompId
                const kidInp = tdTarif ? tdTarif.querySelector(`input[name^="komp_kompId_"]`) : null;
                const kompId = kidInp ? kidInp.value : 0;

                // Pastikan hidden rincian_ids[] dan komp_qty/tarif/kompId input
                // di dalam form sudah benar. Jika belum ada atau rid-nya beda,
                // tambahkan sebagai snapshot baru.
                const hidRid = tdQty.querySelector('input[name="rincian_ids[]"]');
                if (hidRid) hidRid.value = rid; // update ke rid aktif

                // Pastikan komp_qty dan komp_tarif input namanya sesuai rid aktif
                if (qtyInp) qtyInp.name = `komp_qty_${rid}[]`;
                if (trfInp) trfInp.name = `komp_tarif_${rid}[]`;
                if (kidInp) kidInp.name = `komp_kompId_${rid}[]`;
            });
        });

        const fd  = new FormData(form);
        const btn = document.querySelector('[onclick="submitHonorDetail(0)"]');
        if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...'; btn.disabled = true; }
        fetch('honorarium_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Berhasil', text: res.message, timer: 1500, showConfirmButton: false })
                        .then(() => {
                            if (document.getElementById('inpFinalize').value == 1)
                                window.location.href = '?page=honorarium&tab=laporan';
                            else window.location.reload();
                        });
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                    if (btn) { btn.innerHTML = '<i class="fas fa-save me-2"></i>Simpan Draft'; btn.disabled = false; }
                }
            });
    }

    // ── Inisialisasi ─────────────────────────────────────────────────
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
