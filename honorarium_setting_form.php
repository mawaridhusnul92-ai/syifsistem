<?php
/**
 * honorarium_setting_form.php - TAB SETTING FORM / TEMPLATE TABEL (VISUAL BUILDER)
 */
$templates = $conn->query("SELECT * FROM honor_template ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

$master_komponen = [];
$res_mk = $conn->query("SELECT * FROM honor_komponen WHERE is_active=1 ORDER BY nama_honor ASC");
if($res_mk) {
    while($mk = $res_mk->fetch_assoc()) {
        $details = $conn->query("SELECT id, rincian, jabatan_fungsional, besaran, potongan_pajak FROM honor_komponen_detail WHERE komponen_id={$mk['id']} ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
        $mk['details'] = $details;
        $master_komponen[$mk['id']] = $mk;
    }
}

/**
 * Fungsi PHP: Render preview tabel dari custom_layout JSON
 */
function renderPreviewTable($layout_json) {
    $layout = json_decode($layout_json, true) ?: [];
    if (empty($layout)) {
        return '<div class="text-muted fst-italic small py-2 px-3">Belum ada kolom terdaftar.</div>';
    }

    // Pisahkan kolom teks dan grup
    $teks_cols   = [];
    $horiz_groups = []; // [groupName => [header, items[]]]
    $vert_groups  = []; // [groupName => [header, items[]]]

    foreach ($layout as $l) {
        if ($l['type'] === 'teks') {
            $teks_cols[] = $l;
        } elseif (($l['group_type'] ?? '') === 'group_vertical') {
            $g = $l['group'];
            if (!isset($vert_groups[$g])) $vert_groups[$g] = ['header' => ($l['group_header'] ?? ''), 'is_jafung' => ($l['is_jafung'] ?? false), 'items' => []];
            $vert_groups[$g]['items'][] = $l;
        } else {
            $g = $l['group'] ?? 'KOMPONEN';
            if (!isset($horiz_groups[$g])) $horiz_groups[$g] = ['header' => ($l['group_header'] ?? ''), 'is_jafung' => ($l['is_jafung'] ?? false), 'items' => []];
            $horiz_groups[$g]['items'][] = $l;
        }
    }


    // Hitung total kolom untuk NO
    // Kolom: NO + teks_cols + per grup horizontal (header+items) + per grup vertikal (header_uraian + items) + TOTAL BRUTO + POT PAJAK + HONOR DITERIMA
    ob_start();
    ?>
    <div class="table-responsive" style="font-size:11px;">
    <table class="table table-bordered mb-0" style="min-width:600px; border-color:#dee2e6;">
    <thead>
    <?php
    // ---- BARIS 1: Header Utama ----
    echo '<tr>';
    // NO — rowspan 2
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;white-space:nowrap;vertical-align:middle;">NO</th>';
    // Kolom teks — rowspan 2
    foreach ($teks_cols as $tc) {
        echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;white-space:nowrap;vertical-align:middle;">'.strtoupper(htmlspecialchars($tc['label'])).'</th>';
    }
    // Grup horizontal — colspan = (has_header ? 1 : 0) + count(items)*3 (label+tarif+jml)
    foreach ($horiz_groups as $gName => $g) {
        $span = count($g['items']) * 3 + (!empty($g['header']) ? 1 : 0);
        if ($span < 1) $span = 1;
        echo '<th colspan="'.$span.'" class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($gName)).'</th>';
    }
    // Grup vertikal — colspan = (has_header ? 1 : 0) + count(items) (hanya JML/QTY+TARIF+JML)
    foreach ($vert_groups as $gName => $g) {
        $span = count($g['items']) * 3 + (!empty($g['header']) ? 1 : 0);
        if ($span < 1) $span = 1;
        echo '<th colspan="'.$span.'" class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($gName)).'</th>';
    }
    // TOTAL BRUTO, POT PAJAK — rowspan 2
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;white-space:nowrap;vertical-align:middle;">TOTAL BRUTO</th>';
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;white-space:nowrap;vertical-align:middle;">POT. PAJAK</th>';
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#198754;white-space:nowrap;vertical-align:middle;">HONOR DITERIMA</th>';
    echo '</tr>';


    // ---- BARIS 2: Sub-header ----
    echo '<tr>';
    foreach ($horiz_groups as $gName => $g) {
        if (!empty($g['header'])) {
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">'.strtoupper(htmlspecialchars($g['header'])).'</th>';
        }
        foreach ($g['items'] as $item) {
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">'.strtoupper(htmlspecialchars($item['label'])).'</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">TARIF</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">JML</th>';
        }
    }
    foreach ($vert_groups as $gName => $g) {
        if (!empty($g['header'])) {
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">'.strtoupper(htmlspecialchars($g['header'])).'</th>';
        }
        foreach ($g['items'] as $item) {
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">'.strtoupper(htmlspecialchars($item['label'])).'</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">TARIF</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;white-space:nowrap;">JML</th>';
        }
    }
    echo '</tr>';
    echo '</thead>';

    // ---- BARIS DATA SAMPLE ----
    echo '<tbody>';

    // Untuk grup vertikal: setiap item = 1 baris
    $has_vert = !empty($vert_groups);
    if ($has_vert) {
        // Ambil max items dari semua grup vertikal
        $max_rows = 1;
        foreach ($vert_groups as $g) { $max_rows = max($max_rows, count($g['items'])); }

        for ($r = 0; $r < $max_rows; $r++) {
            echo '<tr>';
            if ($r === 0) {
                // NO — rowspan max_rows
                echo '<td rowspan="'.$max_rows.'" class="text-center align-middle text-muted" style="background:#f8f9fa;">1</td>';
                // Teks cols — rowspan max_rows
                foreach ($teks_cols as $tc) {
                    $placeholder = $tc['source'] === 'dosen_nama' ? '<em class="text-muted">nama dosen...</em>' : '<em class="text-muted">'.strtolower($tc['label']).'...</em>';
                    echo '<td rowspan="'.$max_rows.'" class="text-center align-middle" style="background:#f8f9fa;">'.$placeholder.'</td>';
                }
                // Grup horizontal — rowspan max_rows
                foreach ($horiz_groups as $gName => $g) {
                    if (!empty($g['header'])) echo '<td rowspan="'.$max_rows.'" class="text-center align-middle text-muted" style="background:#f8f9fa;">-</td>';
                    foreach ($g['items'] as $item) {
                        echo '<td rowspan="'.$max_rows.'" class="text-center align-middle text-muted" style="background:#f8f9fa;">-</td>';
                        echo '<td rowspan="'.$max_rows.'" class="text-center align-middle text-muted" style="background:#f8f9fa;">0</td>';
                        echo '<td rowspan="'.$max_rows.'" class="text-center align-middle text-muted" style="background:#f8f9fa;">-</td>';
                    }
                }
            }
            // Grup vertikal — 1 item per baris
            foreach ($vert_groups as $gName => $g) {
                if (!empty($g['header'])) {
                    $item_label = isset($g['items'][$r]) ? htmlspecialchars($g['items'][$r]['label']) : '';
                    echo '<td class="text-center text-muted" style="background:#f8f9fa;"><em>'.($item_label ?: '-').'</em></td>';
                }
                // JML/QTY, TARIF, JML
                echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
                echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
                echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
            }
            if ($r === 0) {
                echo '<td rowspan="'.$max_rows.'" class="text-center align-middle text-muted" style="background:#f8f9fa;">0</td>';
                echo '<td rowspan="'.$max_rows.'" class="text-center align-middle text-muted" style="background:#f8f9fa;">0</td>';
                echo '<td rowspan="'.$max_rows.'" class="text-center align-middle fw-bold" style="background:#d1fae5;color:#065f46;">0</td>';
            }
            echo '</tr>';
        }
    } else {
        // Layout horizontal / hanya teks
        echo '<tr>';
        echo '<td class="text-center text-muted" style="background:#f8f9fa;">1</td>';
        foreach ($teks_cols as $tc) {
            $placeholder = $tc['source'] === 'dosen_nama' ? '<em class="text-muted">nama dosen...</em>' : '<em class="text-muted">'.strtolower($tc['label']).'...</em>';
            echo '<td class="text-center" style="background:#f8f9fa;">'.$placeholder.'</td>';
        }
        foreach ($horiz_groups as $gName => $g) {
            if (!empty($g['header'])) echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
            foreach ($g['items'] as $item) {
                echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
                echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
                echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
            }
        }
        echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
        echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
        echo '<td class="text-center fw-bold" style="background:#d1fae5;color:#065f46;">0</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    return ob_get_clean();
}
?>


<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

<style>
    .b-card { transition: 0.2s; border-left: 4px solid #cbd5e1; }
    .b-card[data-type="teks"] { border-left-color: #64748b; }
    .b-card[data-type="group_horizontal"] { border-left-color: #0d6efd; }
    .b-card[data-type="group_vertical"] { border-left-color: #198754; }
    .b-item { border-left: 3px solid #e2e8f0; }
    .tpl-preview-wrap { overflow-x:auto; border-top: 1px solid #e2e8f0; margin-top:0; }
    .tpl-preview-wrap table thead th { font-size:10px; padding:5px 6px; }
    .tpl-preview-wrap table tbody td { font-size:10px; padding:4px 6px; }
    .step-guide { background: linear-gradient(135deg,#f0fdf4 0%,#eff6ff 100%); border:1px solid #bbf7d0; border-left:5px solid #22c55e; border-radius:12px; }
    .step-badge { width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0; }
</style>

<div class="animate__animated animate__fadeIn">

    <!-- PANDUAN STEP BY STEP -->
    <div class="step-guide p-3 mb-4 shadow-sm">
        <div class="d-flex align-items-center mb-2 gap-2">
            <i class="fas fa-lightbulb text-success fs-5"></i>
            <span class="fw-bold text-success small">Panduan: Membuat Layout Vertikal (Seperti Gambar Excel)</span>
        </div>
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="step-badge bg-primary text-white">1</span>
                <span class="small text-dark">Buat Template &rarr; pilih jenis <strong>PENGAJUAN</strong></span>
            </div>
            <i class="fas fa-arrow-right text-muted small d-none d-md-block"></i>
            <div class="d-flex align-items-center gap-2">
                <span class="step-badge bg-secondary text-white">2</span>
                <span class="small text-dark">Tambah kolom <strong>Teks</strong>: PRODI, MATA KULIAH</span>
            </div>
            <i class="fas fa-arrow-right text-muted small d-none d-md-block"></i>
            <div class="d-flex align-items-center gap-2">
                <span class="step-badge bg-success text-white">3</span>
                <span class="small text-dark">Tambah <strong>Grup Vertikal</strong> &rarr; isi semua item komponen soal</span>
            </div>
            <i class="fas fa-arrow-right text-muted small d-none d-md-block"></i>
            <div class="d-flex align-items-center gap-2">
                <span class="step-badge bg-warning text-dark">4</span>
                <span class="small text-dark">Simpan &rarr; pakai di tab <strong>Susun Honor</strong></span>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-1 text-dark">Template Layout Cetak Kuitansi & Pengajuan</h5>
            <p class="text-muted small mb-0">Rancang struktur tabel Anda dengan sistem Grup Header Horizontal & Vertikal.</p>
        </div>
        <button class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold" onclick="openModalTemplate()">
            <i class="fas fa-plus me-2"></i>Buat Template Baru
        </button>
    </div>

    <div class="row g-4">
    <?php foreach($templates as $t):
        $layout     = json_decode($t['custom_layout'], true) ?: [];
        $cnt_teks   = 0; $cnt_horiz = 0; $cnt_vert = 0;
        foreach($layout as $l) {
            if ($l['type'] === 'teks') $cnt_teks++;
            elseif (($l['group_type'] ?? '') === 'group_vertical') $cnt_vert++;
            else $cnt_horiz++;
        }
        $total_cols = count($layout);
    ?>
    <div class="col-md-12">
        <div class="card border-0 rounded-4 shadow-sm bg-white overflow-hidden" style="border-top:4px solid #1a3c7a !important;">
            <div class="card-body p-3 d-flex justify-content-between align-items-start gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($t['nama_template']) ?></h6>
                        <span class="badge <?= $t['jenis_tujuan']=='KUITANSI'?'bg-warning text-dark':'bg-success text-white' ?> rounded-pill px-3"><?= $t['jenis_tujuan'] ?></span>
                    </div>
                    <div class="text-muted small mb-2">
                        <?= $total_cols ?> kolom terdaftar &nbsp;&bull;&nbsp;
                        <?= $cnt_teks ?> teks &nbsp;&bull;&nbsp;
                        <?= $cnt_horiz ?> komponen horizontal &nbsp;&bull;&nbsp;
                        <?= $cnt_vert ?> komponen vertikal
                    </div>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                    <button class="btn btn-sm btn-light border fw-bold text-warning px-3 rounded-pill shadow-sm"
                        onclick='editTemplate(<?= json_encode($t, JSON_HEX_APOS) ?>)'>
                        <i class="fas fa-edit me-1"></i> Edit Template
                    </button>
                    <button class="btn btn-sm btn-light border fw-bold text-danger px-3 rounded-pill shadow-sm"
                        onclick="deleteTemplate(<?= $t['id'] ?>)">
                        <i class="fas fa-trash me-1"></i> Hapus
                    </button>
                </div>
            </div>
            <!-- PREVIEW TABEL -->
            <div class="tpl-preview-wrap px-0">
                <?= renderPreviewTable($t['custom_layout']) ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div><!-- /row -->
</div>


<!-- MODAL BUILDER -->
<div class="modal fade" id="modalTemplate" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form action="javascript:void(0);" id="formTemplate" onsubmit="handleSaveTemplate(event)" class="modal-content text-dark border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="id" id="tplId">
            <input type="hidden" name="custom_layout" id="inpCustomLayout">
            <div class="modal-header p-4 bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="modalTitleTpl"><i class="fas fa-table me-2 text-warning"></i>Pembuat Layout Tabel Dinamis</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Nama Template <span class="text-danger">*</span></label>
                        <input type="text" name="nama_template" id="inpNamaTpl" class="form-control rounded-3 border fw-bold px-3 py-2" required placeholder="Contoh: Honor Pengampu">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Jenis Output Cetak <span class="text-danger">*</span></label>
                        <select name="jenis_tujuan" id="inpJenisTpl" class="form-select rounded-3 border fw-bold text-primary px-3 py-2" required>
                            <option value="PENGAJUAN">Cetak Rekap Gabungan (Pengajuan Laporan)</option>
                            <option value="KUITANSI">Cetak Per Dosen Individu (Kuitansi)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Komponen Honor Acuan <span class="text-danger">*</span></label>
                        <select id="inpMasterKomp" class="form-select rounded-3 border fw-bold text-success shadow-sm px-3 py-2" required onchange="handleMasterKompChange()">
                            <option value="">-- Pilih Master Komponen --</option>
                            <?php foreach($master_komponen as $id => $mk) echo "<option value='$id'>{$mk['nama_honor']}</option>"; ?>
                        </select>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                    <h6 class="fw-bold text-dark mb-0"><i class="fas fa-layer-group me-2 text-primary"></i>Struktur Header & Kolom</h6>
                    <div class="dropdown">
                        <button class="btn btn-primary fw-bold rounded-pill shadow-sm px-4" type="button" data-bs-toggle="dropdown"><i class="fas fa-plus me-2"></i> TAMBAH ELEMEN</button>
                        <ul class="dropdown-menu border-0 shadow-lg rounded-4 overflow-hidden">
                            <li><a class="dropdown-item fw-bold text-dark py-3 border-bottom" href="javascript:void(0);" onclick="addBlock('teks')"><i class="fas fa-font me-2 text-secondary"></i>Tambah Kolom Teks Standar</a></li>
                            <li><a class="dropdown-item fw-bold text-primary py-3 border-bottom" href="javascript:void(0);" onclick="addBlock('group_horizontal')"><i class="fas fa-arrows-alt-h me-2"></i>Tambah Grup Header Horizontal</a></li>
                            <li><a class="dropdown-item fw-bold text-success py-3" href="javascript:void(0);" onclick="addBlock('group_vertical')"><i class="fas fa-list me-2"></i>Tambah Grup Header Vertikal</a></li>
                        </ul>
                    </div>
                </div>
                <div id="builderContainer" style="min-height:200px;">
                    <div class="text-center py-5 text-muted fst-italic" id="emptyStateMsg">Pilih <b>Komponen Honor Acuan</b> di atas untuk mulai menyusun form.</div>
                </div>
            </div>
            <div class="modal-footer p-4 bg-white border-0 d-flex justify-content-end">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold border shadow-sm me-2" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow" id="btnSubmitTpl"><i class="fas fa-save me-2"></i>SIMPAN TEMPLATE</button>
            </div>
        </form>
    </div>
</div>


<script>
    let bCount = 0;
    const dbMasterKomp = <?= json_encode($master_komponen) ?>;
    const rincianToMaster = {};
    for(let mkId in dbMasterKomp) {
        dbMasterKomp[mkId].details.forEach(d => { rincianToMaster[d.id] = mkId; });
    }

    document.addEventListener("DOMContentLoaded", () => {
        document.body.appendChild(document.getElementById('modalTemplate'));
        new Sortable(document.getElementById('builderContainer'), {
            handle: '.drag-handle-block', animation: 150, ghostClass: 'bg-light'
        });
    });

    function handleMasterKompChange() {
        document.getElementById('builderContainer').innerHTML = '<div class="text-center py-5 text-muted fst-italic" id="emptyStateMsg">Klik tombol <b>TAMBAH ELEMEN</b> di atas untuk menyusun kerangka Form.</div>';
        bCount = 0;
    }

    function getSourceDropdown(type, val) {
        if (type === 'teks') {
            return `<select class="form-select fw-bold border shadow-sm b-source text-dark">
                <option value="mata_kuliah" ${val=='mata_kuliah'?'selected':''}>Input Teks Bebas</option>
                <option value="dosen_nama" ${val=='dosen_nama'?'selected':''}>Nama Dosen (Otomatis)</option>
                <option value="prodi" ${val=='prodi'?'selected':''}>Program Studi (Otomatis)</option>
                <option value="jabatan" ${val=='jabatan'?'selected':''}>Jabatan Fungsional (Otomatis)</option>
            </select>`;
        } else {
            let mkId = document.getElementById('inpMasterKomp').value;
            let opts = '<option value="">-- Pilih Rincian Komponen --</option>';
            if(mkId && dbMasterKomp[mkId]) {
                dbMasterKomp[mkId].details.forEach(r => {
                    let sel = (val == r.id) ? 'selected' : '';
                    opts += `<option value="${r.id}" data-nama="${r.rincian}" ${sel}>${r.rincian} (Tarif: Rp ${new Intl.NumberFormat('id-ID').format(r.besaran)})</option>`;
                });
            } else {
                opts = '<option value="">Silakan Pilih Master Komponen di Atas Terlebih Dahulu!</option>';
            }
            return `<select class="form-select fw-bold border-success shadow-sm b-rincian" required onchange="syncLabelKomponen(this)">${opts}</select>`;
        }
    }

    function syncLabelKomponen(sel) {
        let opt = sel.options[sel.selectedIndex];
        let row = sel.closest('.b-item');
        let inpLabel = row.querySelector('.b-label');
        if(opt.value !== '' && inpLabel.value === '') { inpLabel.value = opt.getAttribute('data-nama'); }
    }

    function toggleGroupHeader(chk) {
        let container = chk.closest('.col-md-6').querySelector('.b-group-header-container');
        let inp = container.querySelector('.b-group-header');
        if(chk.checked) {
            container.style.display = 'block'; inp.required = true;
            if(inp.value === '') inp.value = 'URAIAN';
            chk.nextElementSibling.innerText = 'On';
            chk.nextElementSibling.className = 'small fw-bold text-success';
        } else {
            container.style.display = 'none'; inp.required = false; inp.value = '';
            chk.nextElementSibling.innerText = 'Off';
            chk.nextElementSibling.className = 'small fw-bold text-muted';
        }
    }

    function toggleJafungLabel(chk) {
        chk.nextElementSibling.innerText = chk.checked ? 'On' : 'Off';
        chk.nextElementSibling.className = chk.checked ? 'small fw-bold text-warning' : 'small fw-bold text-muted';
    }
</script>


<script>
    function addBlock(type, data = null) {
        let msg = document.getElementById('emptyStateMsg'); if(msg) msg.remove();
        let mkId = document.getElementById('inpMasterKomp').value;
        if (!mkId && type !== 'teks') {
            Swal.fire('Peringatan', 'Silakan pilih <b>Komponen Honor Acuan</b> di atas terlebih dahulu!', 'warning');
            return;
        }
        bCount++;
        let html = '';
        if (type === 'teks') {
            let lbl = data ? data.label : ''; let src = data ? data.source : 'dosen_nama';
            html = `<div class="b-card bg-white p-3 rounded-4 border shadow-sm mb-3 b-row d-flex align-items-center gap-3" data-type="teks">
                <i class="fas fa-grip-vertical text-muted drag-handle-block fs-5" style="cursor:grab"></i>
                <div class="badge bg-secondary">TEKS</div>
                <input type="text" class="form-control fw-bold b-label" placeholder="Nama Header Kolom (Cth: MATA KULIAH)" value="${lbl}" required>
                <div class="flex-grow-1">${getSourceDropdown('teks', src)}</div>
                <button type="button" class="btn btn-light text-danger border rounded-circle shadow-sm" onclick="this.closest('.b-card').remove()"><i class="fas fa-times"></i></button>
            </div>`;
        } else if (type === 'group_horizontal' || type === 'group_vertical') {
            let isVert = type === 'group_vertical';
            let colorClass = isVert ? 'success' : 'primary';
            let title = isVert ? 'GRUP HEADER VERTIKAL (Susun Ke Bawah)' : 'GRUP HEADER HORIZONTAL (Susun Menyamping)';
            let gName = data ? data.name : '';
            let gHead = data ? (data.header || '') : (isVert ? 'URAIAN' : '');
            let gJafung = data ? (data.is_jafung || false) : false;
            let isChecked  = gHead !== '' ? 'checked' : '';
            let isDisplay  = gHead !== '' ? 'block' : 'none';
            let isRequired = gHead !== '' ? 'required' : '';
            let lblText    = gHead !== '' ? 'On' : 'Off';
            let lblColor   = gHead !== '' ? 'text-success' : 'text-muted';
            let jafungChecked = gJafung ? 'checked' : '';
            let jafungLbl     = gJafung ? 'On' : 'Off';
            let jafungColor   = gJafung ? 'text-warning' : 'text-muted';
            let headerInputHtml = `
            <div class="col-md-6">
                <div class="d-flex align-items-center mb-2">
                    <label class="small fw-bold text-dark me-3">Kolom Nama Uraian/Jenis?</label>
                    <div class="form-check form-switch m-0 d-flex align-items-center">
                        <input class="form-check-input cursor-pointer shadow-sm me-2" type="checkbox" onchange="toggleGroupHeader(this)" ${isChecked} style="transform:scale(1.3);">
                        <span class="small fw-bold ${lblColor}">${lblText}</span>
                    </div>
                </div>
                <div class="b-group-header-container" style="display:${isDisplay};">
                    <input type="text" class="form-control fw-bold b-group-header text-${colorClass}"
                        placeholder="${isVert ? 'Cth: JENIS SOAL / TINDAKAN' : 'Cth: NAMA DOSEN / TENAGA PENGAJAR'}"
                        value="${gHead}" ${isRequired}>
                </div>
            </div>
            <div class="col-md-6 d-flex align-items-center pt-3">
                <div class="p-2 border rounded-3 bg-white d-flex align-items-center gap-3 w-100">
                    <div>
                        <div class="small fw-bold text-dark">Tarif berdasarkan Jabatan Fungsional?</div>
                        <div class="small text-muted">Jika On, tarif setiap dosen menyesuaikan jabatan fungsionalnya</div>
                    </div>
                    <div class="form-check form-switch m-0 d-flex align-items-center ms-auto flex-shrink-0">
                        <input class="form-check-input cursor-pointer shadow-sm me-2 b-is-jafung" type="checkbox" onchange="toggleJafungLabel(this)" ${jafungChecked} style="transform:scale(1.3);">
                        <span class="small fw-bold ${jafungColor}">${jafungLbl}</span>
                    </div>
                </div>
            </div>`;
            html = `<div class="b-card bg-white p-4 rounded-4 border shadow-sm mb-4 b-row" data-type="${type}" id="block_${bCount}">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div class="fw-bold text-${colorClass} d-flex align-items-center">
                        <i class="fas fa-grip-vertical text-muted drag-handle-block me-3 fs-5" style="cursor:grab"></i>
                        <i class="fas fa-layer-group me-2"></i> ${title}
                    </div>
                    <button type="button" class="btn btn-sm btn-light text-danger border rounded-circle shadow-sm" onclick="this.closest('.b-card').remove()"><i class="fas fa-times"></i></button>
                </div>
                <div class="row g-3 mb-3 bg-light p-3 rounded-3 border">
                    <div class="col-md-12 mb-1">
                        <label class="small fw-bold text-dark mb-2">Nama Payung Grup (Merge Cell Header) <span class="text-danger">*</span></label>
                        <input type="text" class="form-control fw-bold b-group-name text-${colorClass}" placeholder="Cth: RINCIAN HONOR" value="${gName}" required>
                    </div>
                    ${headerInputHtml}
                </div>
                <div class="b-items-container" id="items_${bCount}"></div>
                <button type="button" class="btn btn-sm btn-${colorClass} mt-3 fw-bold rounded-pill px-4" onclick="addItemToGroup(${bCount}, '${colorClass}')">
                    <i class="fas fa-plus me-2"></i>Tambah Rincian Honor di Grup Ini
                </button>
            </div>`;
        }
        document.getElementById('builderContainer').insertAdjacentHTML('beforeend', html);
        if (data && data.items) {
            data.items.forEach(it => { addItemToGroup(bCount, (type==='group_vertical'?'success':'primary'), it); });
        } else if (type.includes('group')) {
            addItemToGroup(bCount, (type==='group_vertical'?'success':'primary'));
        }
    }

    function addItemToGroup(blockId, colorClass, data = null) {
        let container = document.getElementById(`items_${blockId}`);
        let lbl = data ? data.label : ''; let rid = data ? data.id_rincian : '';
        let itemHtml = `<div class="d-flex gap-3 mb-2 b-item border p-2 bg-white rounded-3 shadow-sm align-items-center animate__animated animate__fadeIn">
            <i class="fas fa-arrows-alt text-muted drag-handle-item ms-2" style="cursor:grab"></i>
            <input type="text" class="form-control fw-bold b-label text-${colorClass}" placeholder="Label Rincian (Otomatis)" value="${lbl}" required style="width:250px;">
            <div class="flex-grow-1">${getSourceDropdown('komponen', rid)}</div>
            <button type="button" class="btn btn-sm btn-light text-danger border rounded-circle" onclick="this.closest('.b-item').remove()"><i class="fas fa-trash"></i></button>
        </div>`;
        container.insertAdjacentHTML('beforeend', itemHtml);
        new Sortable(container, { handle: '.drag-handle-item', animation: 150 });
    }

    function openModalTemplate() {
        document.getElementById('formTemplate').reset();
        document.getElementById('tplId').value = '';
        document.getElementById('builderContainer').innerHTML = '<div class="text-center py-5 text-muted fst-italic" id="emptyStateMsg">Pilih <b>Komponen Honor Acuan</b> di atas untuk mulai menyusun form.</div>';
        bCount = 0;
        document.getElementById('modalTitleTpl').innerHTML = '<i class="fas fa-table me-2 text-warning"></i>Buat Template Tabel Baru';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTemplate')).show();
    }

    function editTemplate(d) {
        document.getElementById('tplId').value = d.id;
        document.getElementById('inpNamaTpl').value = d.nama_template;
        document.getElementById('inpJenisTpl').value = d.jenis_tujuan;
        let layout = JSON.parse(d.custom_layout) || [];
        let mkId = '';
        for(let l of layout) {
            if(l.type === 'komponen' && l.id_rincian) { mkId = rincianToMaster[l.id_rincian] || ''; if(mkId) break; }
        }
        document.getElementById('inpMasterKomp').value = mkId;
        document.getElementById('builderContainer').innerHTML = ''; bCount = 0;
        let groups = { horiz: {}, vert: {} };
        let standaloneTeks = [];
        layout.forEach(l => {
            if (l.type === 'teks') { standaloneTeks.push(l); }
            else if (l.group_type === 'group_vertical') {
                if(!groups.vert[l.group]) groups.vert[l.group] = {name: l.group, header: (l.group_header !== undefined ? l.group_header : 'URAIAN'), is_jafung: (l.is_jafung||false), items: []};
                groups.vert[l.group].items.push(l);
            } else {
                let g = l.group || 'KOMPONEN DEFAULT';
                if(!groups.horiz[g]) groups.horiz[g] = {name: g, header: (l.group_header||''), is_jafung: (l.is_jafung||false), items: []};
                groups.horiz[g].items.push(l);
            }
        });
        standaloneTeks.forEach(l => addBlock('teks', l));
        for(let k in groups.vert) { addBlock('group_vertical', groups.vert[k]); }
        for(let k in groups.horiz) { addBlock('group_horizontal', groups.horiz[k]); }
        document.getElementById('modalTitleTpl').innerHTML = '<i class="fas fa-edit me-2 text-warning"></i>Ubah Template';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTemplate')).show();
    }

    function handleSaveTemplate(e) {
        e.preventDefault();
        let layoutData = [];
        document.querySelectorAll('#builderContainer .b-row').forEach(block => {
            let type = block.getAttribute('data-type');
            if (type === 'teks') {
                layoutData.push({ type: 'teks', label: block.querySelector('.b-label').value, source: block.querySelector('.b-source').value });
            } else if (type === 'group_horizontal' || type === 'group_vertical') {
                let groupName = block.querySelector('.b-group-name').value;
                let groupHeader = ''; let isJafung = false;
                let ghContainer = block.querySelector('.b-group-header-container');
                let ghInput = block.querySelector('.b-group-header');
                if (ghContainer && ghContainer.style.display !== 'none' && ghInput) groupHeader = ghInput.value;
                let jafungChk = block.querySelector('.b-is-jafung');
                if (jafungChk && jafungChk.checked) isJafung = true;
                block.querySelectorAll('.b-item').forEach(item => {
                    layoutData.push({ type: 'komponen', label: item.querySelector('.b-label').value, id_rincian: item.querySelector('.b-rincian').value, group: groupName, group_type: type, group_header: groupHeader, is_jafung: isJafung });
                });
            }
        });
        if(layoutData.length === 0) { Swal.fire('Ditolak', 'Minimal harus ada 1 komponen pembentuk!', 'warning'); return; }
        document.getElementById('inpCustomLayout').value = JSON.stringify(layoutData);
        const btn = document.getElementById('btnSubmitTpl'); const ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...'; btn.disabled = true;
        fetch('honorarium_action.php', { method: 'POST', body: new FormData(e.target) }).then(r=>r.json()).then(res=>{
            if(res.status === 'success') { Swal.fire({ icon:'success', title:'Berhasil!', text:res.message, timer:1500, showConfirmButton:false }).then(()=>{ window.location.reload(); }); }
            else { Swal.fire('Gagal', res.message, 'error'); btn.innerHTML = ori; btn.disabled = false; }
        }).catch(()=>{ Swal.fire('Error', 'Koneksi terputus', 'error'); btn.innerHTML = ori; btn.disabled = false; });
    }

    function deleteTemplate(id) {
        Swal.fire({ title:'Hapus Template?', text:"Yakin menghapus template ini?", icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus!' }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData(); fd.append('action','delete_template'); fd.append('id',id);
                fetch('honorarium_action.php', { method:'POST', body:fd }).then(r=>r.json()).then(res => {
                    if (res.status == 'success') Swal.fire({ icon:'success', title:'Terhapus!', text:res.message, timer:1500, showConfirmButton:false }).then(()=>{ window.location.reload(); });
                });
            }
        });
    }
</script>
