<?php
/**
 * honorarium_setting_form.php — SETTING FORM LAYOUT
 * Update: Sub-menu Pengajuan & Kuitansi.
 * - Sub-menu Pengajuan : tanpa dropdown "Jenis Output Cetak" (otomatis PENGAJUAN)
 * - Sub-menu Kuitansi  : ada pilihan "Slip untuk Pengajuan mana?" → sinkronisasi 1x input
 *   Komponen rincian dikunci dari template pengajuan acuan (tidak bisa beda komponen).
 * $active_subtab diteruskan dari honorarium.php ('pengajuan' | 'kuitansi')
 */

// ── AUTO-CREATE tabel honor_template jika belum ada ─────────────────
$conn->query("CREATE TABLE IF NOT EXISTS honor_template (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_template VARCHAR(150) NOT NULL,
    jenis_tujuan ENUM('KUITANSI','PENGAJUAN') DEFAULT 'PENGAJUAN',
    custom_layout MEDIUMTEXT NULL,
    linked_pengajuan_template_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Pastikan kolom linked_pengajuan_template_id ada (ALTER jika belum)
$_cols_tpl = [];
$_res_tpl_cols = $conn->query("SHOW COLUMNS FROM honor_template");
if ($_res_tpl_cols) { while ($_rc = $_res_tpl_cols->fetch_assoc()) $_cols_tpl[] = $_rc['Field']; }
if (!in_array('linked_pengajuan_template_id', $_cols_tpl))
    $conn->query("ALTER TABLE honor_template ADD COLUMN linked_pengajuan_template_id INT NULL DEFAULT NULL AFTER custom_layout");
if (!in_array('jenis_tujuan', $_cols_tpl))
    $conn->query("ALTER TABLE honor_template ADD COLUMN jenis_tujuan ENUM('KUITANSI','PENGAJUAN') DEFAULT 'PENGAJUAN' AFTER nama_template");

// ── Ambil semua template dipisah per jenis ──────────────────────────
$tpl_pengajuan = [];
$_res_tpl_p = $conn->query("SELECT * FROM honor_template WHERE jenis_tujuan='PENGAJUAN' ORDER BY id ASC");
if ($_res_tpl_p) $tpl_pengajuan = $_res_tpl_p->fetch_all(MYSQLI_ASSOC);

$tpl_kuitansi = [];
$_res_tpl_k = $conn->query(
    "SELECT t.*, p.nama_template AS nama_pengajuan_acuan
     FROM honor_template t
     LEFT JOIN honor_template p ON t.linked_pengajuan_template_id = p.id
     WHERE t.jenis_tujuan='KUITANSI'
     ORDER BY t.id ASC"
);
if ($_res_tpl_k) $tpl_kuitansi = $_res_tpl_k->fetch_all(MYSQLI_ASSOC);

// ── Daftar komponen aktif untuk builder ────────────────────────────
$master_komponen = [];
$res_mk = $conn->query("SELECT * FROM honor_komponen WHERE is_active=1 ORDER BY nama_honor ASC");
if ($res_mk) {
    while ($mk = $res_mk->fetch_assoc()) {
        $_res_det = $conn->query(
            "SELECT id,rincian,jabatan_fungsional,besaran,potongan_pajak
             FROM honor_komponen_detail WHERE komponen_id={$mk['id']} ORDER BY id ASC"
        );
        $details = $_res_det ? $_res_det->fetch_all(MYSQLI_ASSOC) : [];
        $mk['details'] = $details;
        $master_komponen[$mk['id']] = $mk;
    }
}

/* ─────────────────────────────────────────────────────────────────
   FUNGSI RENDER PREVIEW TABEL (sama seperti sebelumnya)
───────────────────────────────────────────────────────────────── */
function renderPreviewTable($layout_json) {
    $layout = json_decode($layout_json, true) ?: [];
    if (empty($layout)) {
        return '<div class="text-muted fst-italic small py-2 px-3">Belum ada kolom terdaftar.</div>';
    }
    $teks_cols = []; $horiz_groups = []; $vert_groups = [];
    foreach ($layout as $l) {
        if ($l['type'] === 'teks') { $teks_cols[] = $l; }
        elseif (($l['group_type'] ?? '') === 'group_vertical') {
            $g = $l['group'];
            if (!isset($vert_groups[$g])) $vert_groups[$g] = ['header'=>($l['group_header']??''),'items'=>[]];
            $vert_groups[$g]['items'][] = $l;
        } else {
            $g = $l['group'] ?? 'KOMPONEN';
            if (!isset($horiz_groups[$g])) $horiz_groups[$g] = ['header'=>($l['group_header']??''),'items'=>[]];
            $horiz_groups[$g]['items'][] = $l;
        }
    }
    ob_start();

    ?>
    <div class="table-responsive" style="font-size:11px;">
    <table class="table table-bordered mb-0" style="min-width:500px;border-color:#dee2e6;">
    <thead>
    <?php
    echo '<tr>';
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;">NO</th>';
    foreach ($teks_cols as $tc) {
        echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;">'.strtoupper(htmlspecialchars($tc['label'])).'</th>';
    }
    foreach ($horiz_groups as $gName => $g) {
        $span = count($g['items'])*3 + (!empty($g['header'])?1:0);
        echo '<th colspan="'.max(1,$span).'" class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($gName)).'</th>';
    }
    foreach ($vert_groups as $gName => $g) {
        $span = count($g['items'])*3 + (!empty($g['header'])?1:0);
        echo '<th colspan="'.max(1,$span).'" class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($gName)).'</th>';
    }
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;">TOTAL BRUTO</th>';
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#1a3c7a;">POT. PAJAK</th>';
    echo '<th rowspan="2" class="text-center fw-bold text-white align-middle" style="background:#198754;">HONOR DITERIMA</th>';
    echo '</tr><tr>';
    foreach ($horiz_groups as $g) {
        if (!empty($g['header'])) echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($g['header'])).'</th>';
        foreach ($g['items'] as $item) {
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($item['label'])).'</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">TARIF</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">JML</th>';
        }
    }
    foreach ($vert_groups as $g) {
        if (!empty($g['header'])) echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($g['header'])).'</th>';
        foreach ($g['items'] as $item) {
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">'.strtoupper(htmlspecialchars($item['label'])).'</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">TARIF</th>';
            echo '<th class="text-center fw-bold text-white" style="background:#e07b00;">JML</th>';
        }
    }
    echo '</tr></thead><tbody><tr>';
    echo '<td class="text-center text-muted" style="background:#f8f9fa;">1</td>';
    foreach ($teks_cols as $tc) {
        $ph = $tc['source']==='dosen_nama'?'<em class="text-muted">nama dosen...</em>':'<em class="text-muted">'.strtolower($tc['label']).'...</em>';
        echo '<td class="text-center" style="background:#f8f9fa;">'.$ph.'</td>';
    }
    foreach ($horiz_groups as $g) {
        if (!empty($g['header'])) echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
        foreach ($g['items'] as $it) {
            echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
            echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
            echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
        }
    }
    foreach ($vert_groups as $g) {
        if (!empty($g['header'])) echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
        foreach ($g['items'] as $it) {
            echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
            echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
            echo '<td class="text-center text-muted" style="background:#f8f9fa;">-</td>';
        }
    }
    echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
    echo '<td class="text-center text-muted" style="background:#f8f9fa;">0</td>';
    echo '<td class="text-center fw-bold" style="background:#d1fae5;color:#065f46;">0</td>';
    echo '</tr></tbody></table></div>';
    <?php
    return ob_get_clean();
}

?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<style>
    .b-card { transition:0.2s; border-left:4px solid #cbd5e1; }
    .b-card[data-type="teks"] { border-left-color:#64748b; }
    .b-card[data-type="group_horizontal"] { border-left-color:#0d6efd; }
    .b-card[data-type="group_vertical"]   { border-left-color:#198754; }
    .tpl-preview-wrap { overflow-x:auto; border-top:1px solid #e2e8f0; }
    .tpl-preview-wrap table thead th { font-size:10px; padding:5px 6px; }
    .tpl-preview-wrap table tbody td  { font-size:10px; padding:4px 6px; }
    .step-guide { background:linear-gradient(135deg,#f0fdf4 0%,#eff6ff 100%); border:1px solid #bbf7d0; border-left:5px solid #22c55e; border-radius:12px; }
    .step-badge { width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0; }
    /* Info-box kuitansi */
    .sync-info-box { background:linear-gradient(135deg,#eff6ff 0%,#f0fdf4 100%); border:1px solid #bfdbfe; border-left:5px solid #0d6efd; border-radius:12px; }
    /* Komponen locked (readonly di kuitansi) */
    .locked-komponen .b-rincian { pointer-events:none; opacity:0.75; background:#f1f5f9; }
    .locked-komponen .btn-del-item { display:none !important; }
    .locked-komponen .btn-add-item { display:none !important; }
</style>

<div class="animate__animated animate__fadeIn">

<?php if ($active_subtab === 'pengajuan'): ?>
<!-- ═══════════════════════════════════════════════════════════════
     SUB-MENU: FORM PENGAJUAN
═══════════════════════════════════════════════════════════════ -->

<div class="step-guide p-3 mb-4 shadow-sm">
    <div class="d-flex align-items-center mb-2 gap-2">
        <i class="fas fa-lightbulb text-success fs-5"></i>
        <span class="fw-bold text-success small">Panduan: Form Pengajuan (Rekap Gabungan)</span>
    </div>
    <div class="d-flex flex-wrap gap-3 align-items-center">
        <div class="d-flex align-items-center gap-2"><span class="step-badge bg-primary text-white">1</span><span class="small text-dark">Buat Template Pengajuan di sini</span></div>
        <i class="fas fa-arrow-right text-muted small d-none d-md-block"></i>
        <div class="d-flex align-items-center gap-2"><span class="step-badge bg-secondary text-white">2</span><span class="small text-dark">Tambah kolom <strong>Teks</strong> &amp; Komponen Honor</span></div>
        <i class="fas fa-arrow-right text-muted small d-none d-md-block"></i>
        <div class="d-flex align-items-center gap-2"><span class="step-badge bg-success text-white">3</span><span class="small text-dark">Buat <strong>Form Kuitansi</strong> → pilih template ini sebagai acuan</span></div>
        <i class="fas fa-arrow-right text-muted small d-none d-md-block"></i>
        <div class="d-flex align-items-center gap-2"><span class="step-badge bg-warning text-dark">4</span><span class="small text-dark">Pakai di tab <strong>Susun Honor</strong> → data 1x input, dua output</span></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-1 text-dark">Template Form Pengajuan (Rekap Gabungan)</h5>
        <p class="text-muted small mb-0">Layout tabel untuk cetak Rekap Laporan Pengajuan Honor.</p>
    </div>
    <button class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold"
            onclick="openModalTemplate('PENGAJUAN')">
        <i class="fas fa-plus me-2"></i>Buat Template Pengajuan
    </button>
</div>

<div class="row g-4">
<?php foreach ($tpl_pengajuan as $t):
    $layout = json_decode($t['custom_layout'], true) ?: [];
    $cnt_teks=0; $cnt_h=0; $cnt_v=0;
    foreach($layout as $l){ if($l['type']==='teks') $cnt_teks++; elseif(($l['group_type']??'')==='group_vertical') $cnt_v++; else $cnt_h++; }
?>
<div class="col-md-12">
    <div class="card border-0 rounded-4 shadow-sm bg-white overflow-hidden" style="border-top:4px solid #1a3c7a !important;">
        <div class="card-body p-3 d-flex justify-content-between align-items-start gap-3">
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($t['nama_template']) ?></h6>
                    <span class="badge bg-success text-white rounded-pill px-3">PENGAJUAN</span>
                    <?php
                    // Tampilkan berapa kuitansi yang sudah terhubung
                    $cnt_linked = $conn->query("SELECT COUNT(id) FROM honor_template WHERE linked_pengajuan_template_id={$t['id']}")->fetch_row()[0] ?? 0;
                    if ($cnt_linked > 0):
                    ?><span class="badge bg-info text-white rounded-pill px-2" title="Jumlah form kuitansi yang menggunakan template pengajuan ini"><i class="fas fa-link me-1"></i><?= $cnt_linked ?> Kuitansi</span><?php endif; ?>
                </div>
                <div class="text-muted small"><?= count($layout) ?> kolom &bull; <?= $cnt_teks ?> teks &bull; <?= $cnt_h ?> horiz &bull; <?= $cnt_v ?> vertikal</div>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button class="btn btn-sm btn-light border fw-bold text-warning px-3 rounded-pill shadow-sm"
                        onclick='editTemplate(<?= json_encode($t, JSON_HEX_APOS) ?>, "PENGAJUAN")'>
                    <i class="fas fa-edit me-1"></i>Edit
                </button>
                <button class="btn btn-sm btn-light border fw-bold text-danger px-3 rounded-pill shadow-sm"
                        onclick="deleteTemplate(<?= $t['id'] ?>, <?= $cnt_linked ?>)">
                    <i class="fas fa-trash me-1"></i>Hapus
                </button>
            </div>
        </div>
        <div class="tpl-preview-wrap px-0"><?= renderPreviewTable($t['custom_layout']) ?></div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($tpl_pengajuan)): ?>
<div class="col-12 text-center py-5 text-muted fst-italic">
    <i class="fas fa-file-invoice fa-3x opacity-25 mb-3 d-block"></i>
    Belum ada template pengajuan. Klik tombol <strong>Buat Template Pengajuan</strong> di atas.
</div>
<?php endif; ?>
</div><!-- /row pengajuan -->

<?php elseif ($active_subtab === 'kuitansi'): ?>
<!-- ═══════════════════════════════════════════════════════════════
     SUB-MENU: FORM KUITANSI
═══════════════════════════════════════════════════════════════ -->

<div class="sync-info-box p-3 mb-4 shadow-sm">
    <div class="d-flex align-items-center gap-2 mb-2">
        <i class="fas fa-sync-alt text-primary fs-5"></i>
        <span class="fw-bold text-primary small">Cara Kerja Sinkronisasi Kuitansi ↔ Pengajuan</span>
    </div>
    <div class="row g-2 small text-dark">
        <div class="col-md-4 d-flex align-items-start gap-2"><span class="step-badge bg-primary text-white" style="min-width:28px;">1</span><span>Pilih <strong>Template Pengajuan Acuan</strong> saat buat kuitansi — komponen rincian otomatis terkunci dari sana</span></div>
        <div class="col-md-4 d-flex align-items-start gap-2"><span class="step-badge bg-info text-white" style="min-width:28px;">2</span><span>User hanya input data <strong>1x di Susun Honor</strong> menggunakan template pengajuan</span></div>
        <div class="col-md-4 d-flex align-items-start gap-2"><span class="step-badge bg-success text-white" style="min-width:28px;">3</span><span>Kuitansi ter-generate otomatis dengan data yang sama — cetak langsung dari tab <strong>Slip &amp; Pembayaran</strong></span></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-1 text-dark">Template Form Kuitansi (Slip Per Dosen)</h5>
        <p class="text-muted small mb-0">Layout tabel kuitansi individual — komponen honor dikunci sesuai pengajuan acuan.</p>
    </div>
    <button class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold"
            onclick="openModalTemplate('KUITANSI')"
            <?= empty($tpl_pengajuan)?'disabled title="Buat Template Pengajuan terlebih dahulu"':'' ?>>
        <i class="fas fa-plus me-2"></i>Buat Template Kuitansi
    </button>
</div>
<?php if (empty($tpl_pengajuan)): ?>
<div class="alert alert-warning border-warning border rounded-4 shadow-sm mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <strong>Belum ada Template Pengajuan.</strong>
    Buat Template Pengajuan terlebih dahulu di sub-menu <a href="?page=honorarium&tab=setting_form&subtab=pengajuan" class="fw-bold">Form Pengajuan</a>,
    baru kembali ke sini untuk membuat template kuitansinya.
</div>
<?php endif; ?>

<div class="row g-4">
<?php foreach ($tpl_kuitansi as $t):
    $layout = json_decode($t['custom_layout'], true) ?: [];
    $cnt_teks=0; $cnt_h=0; $cnt_v=0;
    foreach($layout as $l){ if($l['type']==='teks') $cnt_teks++; elseif(($l['group_type']??'')==='group_vertical') $cnt_v++; else $cnt_h++; }
?>
<div class="col-md-12">
    <div class="card border-0 rounded-4 shadow-sm bg-white overflow-hidden" style="border-top:4px solid #e07b00 !important;">
        <div class="card-body p-3 d-flex justify-content-between align-items-start gap-3">
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h6 class="fw-bold text-dark mb-0"><?= htmlspecialchars($t['nama_template']) ?></h6>
                    <span class="badge bg-warning text-dark rounded-pill px-3">KUITANSI</span>
                    <?php if (!empty($t['nama_pengajuan_acuan'])): ?>
                    <span class="badge bg-light text-primary border border-primary rounded-pill px-2" style="font-size:11px;">
                        <i class="fas fa-link me-1"></i>Acuan: <?= htmlspecialchars($t['nama_pengajuan_acuan']) ?>
                    </span>
                    <?php else: ?>
                    <span class="badge bg-danger text-white rounded-pill px-2" style="font-size:11px;"><i class="fas fa-unlink me-1"></i>Belum ditautkan</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted small"><?= count($layout) ?> kolom &bull; <?= $cnt_teks ?> teks &bull; <?= $cnt_h ?> horiz &bull; <?= $cnt_v ?> vertikal</div>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button class="btn btn-sm btn-light border fw-bold text-warning px-3 rounded-pill shadow-sm"
                        onclick='editTemplate(<?= json_encode($t, JSON_HEX_APOS) ?>, "KUITANSI")'>
                    <i class="fas fa-edit me-1"></i>Edit
                </button>
                <button class="btn btn-sm btn-light border fw-bold text-danger px-3 rounded-pill shadow-sm"
                        onclick="deleteTemplate(<?= $t['id'] ?>, 0)">
                    <i class="fas fa-trash me-1"></i>Hapus
                </button>
            </div>
        </div>
        <div class="tpl-preview-wrap px-0"><?= renderPreviewTable($t['custom_layout']) ?></div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($tpl_kuitansi)): ?>
<div class="col-12 text-center py-5 text-muted fst-italic">
    <i class="fas fa-receipt fa-3x opacity-25 mb-3 d-block"></i>
    Belum ada template kuitansi. Klik <strong>Buat Template Kuitansi</strong> di atas.
</div>
<?php endif; ?>
</div><!-- /row kuitansi -->

<?php endif; // end subtab ?>

</div><!-- /animate wrapper -->



<!-- ═══════════════════════════════════════════════════════════════
     MODAL BUILDER (dipakai bersama oleh Pengajuan & Kuitansi)
═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalTemplate" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <form action="javascript:void(0);" id="formTemplate" onsubmit="handleSaveTemplate(event)"
          class="modal-content text-dark border-0 shadow-lg rounded-4 overflow-hidden">
      <input type="hidden" name="action"       value="save_template">
      <input type="hidden" name="id"           id="tplId">
      <input type="hidden" name="jenis_tujuan" id="tplJenis" value="PENGAJUAN">
      <input type="hidden" name="linked_pengajuan_template_id" id="tplLinkedId" value="0">
      <input type="hidden" name="custom_layout" id="inpCustomLayout">

      <!-- HEADER MODAL -->
      <div class="modal-header p-4 bg-primary text-white border-0">
        <h5 class="modal-title fw-bold" id="modalTitleTpl">
            <i class="fas fa-table me-2 text-warning"></i>Buat Template Baru
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-4 bg-light">

        <!-- BARIS 1: Nama Template + (kondisional) Link Pengajuan -->
        <div class="row g-3 mb-3" id="formTopFields">
          <div class="col-md-5" id="colNamaTpl">
            <label class="form-label small fw-bold">Nama Template <span class="text-danger">*</span></label>
            <input type="text" name="nama_template" id="inpNamaTpl"
                   class="form-control rounded-3 border fw-bold px-3 py-2"
                   required placeholder="Contoh: Honor Pengampu">
          </div>
          <!-- Kolom "Acuan Pengajuan" — hanya tampil saat KUITANSI -->
          <div class="col-md-4 d-none" id="colLinkedPengajuan">
            <label class="form-label small fw-bold text-primary">
                <i class="fas fa-link me-1"></i>Template Pengajuan Acuan <span class="text-danger">*</span>
            </label>
            <select id="selLinkedPengajuan" class="form-select rounded-3 border-primary fw-bold text-primary px-3 py-2 shadow-sm"
                    onchange="handleLinkedPengajuanChange(this)">
              <option value="">-- Pilih Template Pengajuan --</option>
              <?php foreach ($tpl_pengajuan as $tp): ?>
              <option value="<?= $tp['id'] ?>"
                      data-layout="<?= htmlspecialchars($tp['custom_layout'], ENT_QUOTES) ?>"
                      data-komponen="<?= htmlspecialchars($tp['custom_layout'], ENT_QUOTES) ?>">
                <?= htmlspecialchars($tp['nama_template']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text text-primary fw-bold mt-1">
                <i class="fas fa-info-circle me-1"></i>
                Komponen rincian akan dikunci dari template ini agar data sinkron.
            </div>
          </div>
          <!-- Komponen Acuan — untuk Pengajuan tetap tampil manual -->
          <div class="col-md-4" id="colMasterKomp">
            <label class="form-label small fw-bold">Komponen Honor Acuan <span class="text-danger">*</span></label>
            <select id="inpMasterKomp" class="form-select rounded-3 border fw-bold text-success shadow-sm px-3 py-2"
                    onchange="handleMasterKompChange()">
              <option value="">-- Pilih Master Komponen --</option>
              <?php foreach ($master_komponen as $id => $mk): ?>
              <option value="<?= $id ?>"><?= htmlspecialchars($mk['nama_honor']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Badge info jenis template (readonly) -->
          <div class="col-md-3 d-flex align-items-end">
            <div class="w-100 p-3 rounded-3 border text-center fw-bold" id="badgeJenisTpl"
                 style="background:#dcfce7;color:#166534;border-color:#bbf7d0 !important;">
                <i class="fas fa-file-invoice me-1"></i> Template PENGAJUAN
            </div>
          </div>
        </div>

        <!-- BUILDER AREA -->
        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
          <h6 class="fw-bold text-dark mb-0">
              <i class="fas fa-layer-group me-2 text-primary"></i>Struktur Header &amp; Kolom
          </h6>
          <div class="dropdown" id="btnTambahElemen">
            <button class="btn btn-primary fw-bold rounded-pill shadow-sm px-4"
                    type="button" data-bs-toggle="dropdown">
                <i class="fas fa-plus me-2"></i>TAMBAH ELEMEN
            </button>
            <ul class="dropdown-menu border-0 shadow-lg rounded-4 overflow-hidden">
              <li><a class="dropdown-item fw-bold text-dark py-3 border-bottom" href="javascript:void(0);"
                     onclick="addBlock('teks')">
                     <i class="fas fa-font me-2 text-secondary"></i>Tambah Kolom Teks Standar</a></li>
              <li><a class="dropdown-item fw-bold text-primary py-3 border-bottom" href="javascript:void(0);"
                     onclick="addBlock('group_horizontal')">
                     <i class="fas fa-arrows-alt-h me-2"></i>Tambah Grup Header Horizontal</a></li>
              <li><a class="dropdown-item fw-bold text-success py-3" href="javascript:void(0);"
                     onclick="addBlock('group_vertical')">
                     <i class="fas fa-list me-2"></i>Tambah Grup Header Vertikal</a></li>
            </ul>
          </div>
        </div>
        <div id="builderContainer" style="min-height:200px;">
          <div class="text-center py-5 text-muted fst-italic" id="emptyStateMsg">
              Pilih <b>Komponen Honor Acuan</b> di atas untuk mulai menyusun form.
          </div>
        </div>
      </div><!-- /modal-body -->

      <div class="modal-footer p-4 bg-white border-0 d-flex justify-content-end">
        <button type="button" class="btn btn-light rounded-pill px-4 fw-bold border shadow-sm me-2"
                data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow" id="btnSubmitTpl">
            <i class="fas fa-save me-2"></i>SIMPAN TEMPLATE
        </button>
      </div>
    </form>
  </div>
</div>


<script>
/* ============================================================
   JAVASCRIPT ENGINE — SETTING FORM LAYOUT
   Mendukung dua mode: PENGAJUAN (builder bebas) dan
   KUITANSI (komponen dikunci dari template pengajuan acuan)
============================================================ */
let bCount = 0;
const dbMasterKomp = <?= json_encode($master_komponen) ?>;
const rincianToMaster = {};
for (let mkId in dbMasterKomp) {
    dbMasterKomp[mkId].details.forEach(d => { rincianToMaster[d.id] = mkId; });
}
// Data template pengajuan tersedia (untuk mode kuitansi)
const tplPengajuanData = <?= json_encode(array_values($tpl_pengajuan)) ?>;

// Apakah mode kuitansi & komponen dikunci?
let isKuitansiMode   = false;
let lockedComponents = []; // array rincian dari template pengajuan acuan

document.addEventListener("DOMContentLoaded", () => {
    document.body.appendChild(document.getElementById('modalTemplate'));
    new Sortable(document.getElementById('builderContainer'), {
        handle: '.drag-handle-block', animation: 150, ghostClass: 'bg-light'
    });
});

/* ── Mode switch saat buka modal ───────────────────────────── */
function setModalMode(jenis) {
    isKuitansiMode = (jenis === 'KUITANSI');
    document.getElementById('tplJenis').value = jenis;

    const colLinked  = document.getElementById('colLinkedPengajuan');
    const colKomp    = document.getElementById('colMasterKomp');
    const badge      = document.getElementById('badgeJenisTpl');

    if (isKuitansiMode) {
        colLinked.classList.remove('d-none');
        colKomp.classList.add('d-none');
        badge.style.cssText = 'background:#fff3cd;color:#854d0e;border-color:#fef08a !important;';
        badge.innerHTML = '<i class="fas fa-receipt me-1"></i> Template KUITANSI';
    } else {
        colLinked.classList.add('d-none');
        colKomp.classList.remove('d-none');
        badge.style.cssText = 'background:#dcfce7;color:#166534;border-color:#bbf7d0 !important;';
        badge.innerHTML = '<i class="fas fa-file-invoice me-1"></i> Template PENGAJUAN';
    }
}

/* ── Saat user pilih template pengajuan acuan (mode kuitansi) ─ */
function handleLinkedPengajuanChange(sel) {
    const val = sel.value;
    document.getElementById('tplLinkedId').value = val;
    if (!val) {
        lockedComponents = [];
        resetBuilder();
        return;
    }
    // Ambil layout dari data-layout attribute
    const opt = sel.options[sel.selectedIndex];
    const layoutJson = opt.getAttribute('data-layout') || '[]';
    try {
        const layout = JSON.parse(layoutJson);
        // Ekstrak semua komponen & komponen_id dari layout pengajuan
        lockedComponents = layout.filter(l => l.type === 'komponen');
        // Otomatis set master komp dari rincian pertama
        if (lockedComponents.length > 0) {
            const firstRid = lockedComponents[0].id_rincian;
            const mkId     = rincianToMaster[firstRid] || '';
            document.getElementById('inpMasterKomp').value = mkId;
        }
        // Rebuild builder dengan layout pengajuan acuan
        autoFillBuilderFromPengajuan(layout);
    } catch(e) {
        console.error('Gagal parse layout pengajuan acuan:', e);
    }
}

/* ── Auto-isi builder dari layout template pengajuan acuan ─── */
function autoFillBuilderFromPengajuan(layout) {
    resetBuilder();
    // Re-group layout
    const standaloneTeks = [];
    const groups_vert    = {};
    const groups_horiz   = {};
    layout.forEach(l => {
        if (l.type === 'teks') { standaloneTeks.push(l); }
        else if (l.group_type === 'group_vertical') {
            if (!groups_vert[l.group]) groups_vert[l.group] = { name: l.group, header: l.group_header || '', is_jafung: l.is_jafung || false, items: [] };
            groups_vert[l.group].items.push(l);
        } else {
            if (!groups_horiz[l.group]) groups_horiz[l.group] = { name: l.group, header: l.group_header || '', is_jafung: l.is_jafung || false, items: [] };
            groups_horiz[l.group].items.push(l);
        }
    });
    standaloneTeks.forEach(l => addBlock('teks', l));
    for (const k in groups_vert)  addBlock('group_vertical',   groups_vert[k]);
    for (const k in groups_horiz) addBlock('group_horizontal', groups_horiz[k]);

    // Kunci semua item komponen (readonly di mode kuitansi)
    if (isKuitansiMode) lockKomponenInBuilder();
}

/* ── Kunci semua item komponen di builder ──────────────────── */
function lockKomponenInBuilder() {
    document.querySelectorAll('#builderContainer .b-card').forEach(card => {
        if (card.getAttribute('data-type') !== 'teks') {
            card.classList.add('locked-komponen');
            // Tambahkan overlay info
            if (!card.querySelector('.lock-notice')) {
                const notice = document.createElement('div');
                notice.className = 'lock-notice alert alert-info py-1 px-3 mb-2 small fw-bold';
                notice.innerHTML = '<i class="fas fa-lock me-1"></i> Komponen dikunci — mengikuti Template Pengajuan acuan';
                card.querySelector('.b-items-container')?.before(notice);
            }
        }
    });
}

/* ── Reset builder ─────────────────────────────────────────── */
function resetBuilder() {
    document.getElementById('builderContainer').innerHTML =
        '<div class="text-center py-5 text-muted fst-italic" id="emptyStateMsg">' +
        (isKuitansiMode
            ? 'Pilih <b>Template Pengajuan Acuan</b> di atas untuk mengisi komponen secara otomatis.'
            : 'Pilih <b>Komponen Honor Acuan</b> di atas untuk mulai menyusun form.')
        + '</div>';
    bCount = 0;
}

function handleMasterKompChange() {
    resetBuilder();
}
</script>


<script>
/* ============================================================
   BUILDER BLOCKS — addBlock, addItemToGroup, helpers
============================================================ */
function getSourceDropdown(type, val) {
    if (type === 'teks') {
        return `<select class="form-select fw-bold border shadow-sm b-source text-dark">
            <option value="mata_kuliah" ${val=='mata_kuliah'?'selected':''}>Input Teks Bebas</option>
            <option value="dosen_nama"  ${val=='dosen_nama' ?'selected':''}>Nama Dosen (Otomatis)</option>
            <option value="prodi"       ${val=='prodi'      ?'selected':''}>Program Studi (Otomatis)</option>
            <option value="jabatan"     ${val=='jabatan'    ?'selected':''}>Jabatan Fungsional (Otomatis)</option>
        </select>`;
    } else {
        const mkId = document.getElementById('inpMasterKomp').value;
        let opts = '<option value="">-- Pilih Rincian Komponen --</option>';
        if (mkId && dbMasterKomp[mkId]) {
            dbMasterKomp[mkId].details.forEach(r => {
                const sel = (String(val) === String(r.id)) ? 'selected' : '';
                opts += `<option value="${r.id}" data-nama="${r.rincian}" ${sel}>${r.rincian} (Rp ${new Intl.NumberFormat('id-ID').format(r.besaran)})</option>`;
            });
        } else if (isKuitansiMode) {
            // Mode kuitansi: tampilkan semua rincian dari semua komponen
            for (const mk of Object.values(dbMasterKomp)) {
                mk.details.forEach(r => {
                    const sel = (String(val) === String(r.id)) ? 'selected' : '';
                    opts += `<option value="${r.id}" data-nama="${r.rincian}" ${sel}>${mk.nama_honor} → ${r.rincian}</option>`;
                });
            }
        } else {
            opts = '<option value="">Pilih Master Komponen di atas terlebih dahulu</option>';
        }
        const lockedAttr = (isKuitansiMode && lockedComponents.length > 0) ? 'disabled' : '';
        return `<select class="form-select fw-bold border-success shadow-sm b-rincian" required onchange="syncLabelKomponen(this)" ${lockedAttr}>${opts}</select>`;
    }
}

function syncLabelKomponen(sel) {
    const opt = sel.options[sel.selectedIndex];
    const row = sel.closest('.b-item');
    const inpLabel = row?.querySelector('.b-label');
    if (opt?.value && inpLabel && !inpLabel.value) {
        inpLabel.value = opt.getAttribute('data-nama');
    }
}

function toggleGroupHeader(chk) {
    const container = chk.closest('.col-md-6')?.querySelector('.b-group-header-container');
    const inp = container?.querySelector('.b-group-header');
    const isOn = chk.checked;
    if (container) container.style.display = isOn ? 'block' : 'none';
    if (inp) { inp.required = isOn; if (isOn && !inp.value) inp.value = 'URAIAN'; }
    chk.nextElementSibling.innerText = isOn ? 'On' : 'Off';
    chk.nextElementSibling.className = isOn ? 'small fw-bold text-success' : 'small fw-bold text-muted';
}

function toggleJafungLabel(chk) {
    chk.nextElementSibling.innerText = chk.checked ? 'On' : 'Off';
    chk.nextElementSibling.className = chk.checked ? 'small fw-bold text-warning' : 'small fw-bold text-muted';
}

function addBlock(type, data = null) {
    const msg = document.getElementById('emptyStateMsg');
    if (msg) msg.remove();

    const mkId = document.getElementById('inpMasterKomp').value;
    if (!mkId && type !== 'teks' && !isKuitansiMode) {
        Swal.fire('Peringatan', 'Silakan pilih <b>Komponen Honor Acuan</b> terlebih dahulu!', 'warning');
        return;
    }
    bCount++;
    let html = '';

    if (type === 'teks') {
        const lbl = data?.label || '';
        const src = data?.source || 'dosen_nama';
        html = `<div class="b-card bg-white p-3 rounded-4 border shadow-sm mb-3 b-row d-flex align-items-center gap-3" data-type="teks">
            <i class="fas fa-grip-vertical text-muted drag-handle-block fs-5" style="cursor:grab"></i>
            <div class="badge bg-secondary">TEKS</div>
            <input type="text" class="form-control fw-bold b-label" placeholder="Nama Header Kolom" value="${lbl}" required>
            <div class="flex-grow-1">${getSourceDropdown('teks', src)}</div>
            <button type="button" class="btn btn-light text-danger border rounded-circle shadow-sm" onclick="this.closest('.b-card').remove()">
                <i class="fas fa-times"></i></button>
        </div>`;
    } else {
        const isVert   = (type === 'group_vertical');
        const colorCls = isVert ? 'success' : 'primary';
        const title    = isVert ? 'GRUP HEADER VERTIKAL (Susun Ke Bawah)' : 'GRUP HEADER HORIZONTAL (Susun Menyamping)';
        const gName    = data?.name   || '';
        const gHead    = data?.header || (isVert ? 'URAIAN' : '');
        const gJafung  = data?.is_jafung || false;
        const isChecked  = gHead ? 'checked' : '';
        const isDisplay  = gHead ? 'block' : 'none';
        const isReq      = gHead ? 'required' : '';
        const lblText    = gHead ? 'On' : 'Off';
        const lblColor   = gHead ? 'text-success' : 'text-muted';
        const jfChecked  = gJafung ? 'checked' : '';
        const jfLbl      = gJafung ? 'On' : 'Off';
        const jfColor    = gJafung ? 'text-warning' : 'text-muted';

        html = `<div class="b-card bg-white p-4 rounded-4 border shadow-sm mb-4 b-row" data-type="${type}" id="block_${bCount}">
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div class="fw-bold text-${colorCls} d-flex align-items-center">
                    <i class="fas fa-grip-vertical text-muted drag-handle-block me-3 fs-5" style="cursor:grab"></i>
                    <i class="fas fa-layer-group me-2"></i> ${title}
                </div>
                <button type="button" class="btn btn-sm btn-light text-danger border rounded-circle shadow-sm"
                        onclick="this.closest('.b-card').remove()"><i class="fas fa-times"></i></button>
            </div>
            <div class="row g-3 mb-3 bg-light p-3 rounded-3 border">
                <div class="col-md-12 mb-1">
                    <label class="small fw-bold text-dark mb-2">Nama Payung Grup <span class="text-danger">*</span></label>
                    <input type="text" class="form-control fw-bold b-group-name text-${colorCls}"
                           placeholder="Cth: RINCIAN HONOR" value="${gName}" required>
                </div>
                <div class="col-md-6">
                    <div class="d-flex align-items-center mb-2">
                        <label class="small fw-bold text-dark me-3">Kolom Nama Uraian/Jenis?</label>
                        <div class="form-check form-switch m-0 d-flex align-items-center">
                            <input class="form-check-input cursor-pointer shadow-sm me-2" type="checkbox"
                                   onchange="toggleGroupHeader(this)" ${isChecked} style="transform:scale(1.3);">
                            <span class="small fw-bold ${lblColor}">${lblText}</span>
                        </div>
                    </div>
                    <div class="b-group-header-container" style="display:${isDisplay};">
                        <input type="text" class="form-control fw-bold b-group-header text-${colorCls}"
                               placeholder="${isVert ? 'Cth: JENIS SOAL' : 'Cth: NAMA DOSEN'}"
                               value="${gHead}" ${isReq}>
                    </div>
                </div>
                <div class="col-md-6 d-flex align-items-center pt-3">
                    <div class="p-2 border rounded-3 bg-white d-flex align-items-center gap-3 w-100">
                        <div>
                            <div class="small fw-bold text-dark">Tarif berdasarkan Jabatan Fungsional?</div>
                            <div class="small text-muted">Jika On, tarif per dosen mengikuti jabatan fungsionalnya</div>
                        </div>
                        <div class="form-check form-switch m-0 d-flex align-items-center ms-auto flex-shrink-0">
                            <input class="form-check-input cursor-pointer shadow-sm me-2 b-is-jafung" type="checkbox"
                                   onchange="toggleJafungLabel(this)" ${jfChecked} style="transform:scale(1.3);">
                            <span class="small fw-bold ${jfColor}">${jfLbl}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="b-items-container" id="items_${bCount}"></div>
            <button type="button"
                    class="btn btn-sm btn-${colorCls} mt-3 fw-bold rounded-pill px-4 btn-add-item"
                    onclick="addItemToGroup(${bCount}, '${colorCls}')">
                <i class="fas fa-plus me-2"></i>Tambah Rincian Honor di Grup Ini
            </button>
        </div>`;
    }

    document.getElementById('builderContainer').insertAdjacentHTML('beforeend', html);

    if (data?.items) {
        data.items.forEach(it => addItemToGroup(bCount, (type==='group_vertical'?'success':'primary'), it));
    } else if (type.includes('group')) {
        addItemToGroup(bCount, (type==='group_vertical'?'success':'primary'));
    }
    if (isKuitansiMode) lockKomponenInBuilder();
}

function addItemToGroup(blockId, colorClass, data = null) {
    const container = document.getElementById(`items_${blockId}`);
    const lbl = data?.label || '';
    const rid = data?.id_rincian || '';
    const itemHtml = `
    <div class="d-flex gap-3 mb-2 b-item border p-2 bg-white rounded-3 shadow-sm align-items-center animate__animated animate__fadeIn">
        <i class="fas fa-arrows-alt text-muted drag-handle-item ms-2" style="cursor:grab"></i>
        <input type="text" class="form-control fw-bold b-label text-${colorClass}"
               placeholder="Label Rincian (Otomatis)" value="${lbl}" required style="width:250px;">
        <div class="flex-grow-1">${getSourceDropdown('komponen', rid)}</div>
        <button type="button" class="btn btn-sm btn-light text-danger border rounded-circle btn-del-item"
                onclick="this.closest('.b-item').remove()"><i class="fas fa-trash"></i></button>
    </div>`;
    container.insertAdjacentHTML('beforeend', itemHtml);
    new Sortable(container, { handle: '.drag-handle-item', animation: 150 });
}
</script>


<script>
/* ============================================================
   OPEN / EDIT / SAVE / DELETE TEMPLATE
============================================================ */
function openModalTemplate(jenis) {
    document.getElementById('formTemplate').reset();
    document.getElementById('tplId').value = '';
    document.getElementById('tplLinkedId').value = '0';
    document.getElementById('selLinkedPengajuan').value = '';
    document.getElementById('inpMasterKomp').value = '';
    lockedComponents = [];
    setModalMode(jenis);
    resetBuilder();
    const title = jenis === 'KUITANSI'
        ? '<i class="fas fa-receipt me-2 text-warning"></i>Buat Template Kuitansi Baru'
        : '<i class="fas fa-file-invoice me-2 text-warning"></i>Buat Template Pengajuan Baru';
    document.getElementById('modalTitleTpl').innerHTML = title;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTemplate')).show();
}

function editTemplate(d, jenis) {
    document.getElementById('tplId').value  = d.id;
    document.getElementById('inpNamaTpl').value = d.nama_template;
    setModalMode(jenis);

    const layout = JSON.parse(d.custom_layout || '[]');

    if (jenis === 'KUITANSI') {
        const linkedId = d.linked_pengajuan_template_id || '';
        document.getElementById('tplLinkedId').value       = linkedId;
        document.getElementById('selLinkedPengajuan').value = linkedId;
        // Set komponen acuan
        if (linkedId) {
            const selLinked = document.getElementById('selLinkedPengajuan');
            handleLinkedPengajuanChange(selLinked);
        } else {
            resetBuilder();
        }
    } else {
        // Mode PENGAJUAN: isi master komp dari layout
        let mkId = '';
        for (const l of layout) {
            if (l.type === 'komponen' && l.id_rincian) {
                mkId = rincianToMaster[l.id_rincian] || ''; if (mkId) break;
            }
        }
        document.getElementById('inpMasterKomp').value = mkId;
        // Rebuild manual
        document.getElementById('builderContainer').innerHTML = '';
        bCount = 0;
        const gVert = {}; const gHoriz = {}; const teks = [];
        layout.forEach(l => {
            if (l.type === 'teks') { teks.push(l); }
            else if (l.group_type === 'group_vertical') {
                if (!gVert[l.group]) gVert[l.group] = { name: l.group, header: l.group_header||'URAIAN', is_jafung: l.is_jafung||false, items: [] };
                gVert[l.group].items.push(l);
            } else {
                if (!gHoriz[l.group]) gHoriz[l.group] = { name: l.group, header: l.group_header||'', is_jafung: l.is_jafung||false, items: [] };
                gHoriz[l.group].items.push(l);
            }
        });
        teks.forEach(l => addBlock('teks', l));
        for (const k in gVert)  addBlock('group_vertical',   gVert[k]);
        for (const k in gHoriz) addBlock('group_horizontal', gHoriz[k]);
    }

    const title = jenis === 'KUITANSI'
        ? '<i class="fas fa-edit me-2 text-warning"></i>Ubah Template Kuitansi'
        : '<i class="fas fa-edit me-2 text-warning"></i>Ubah Template Pengajuan';
    document.getElementById('modalTitleTpl').innerHTML = title;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTemplate')).show();
}

function handleSaveTemplate(e) {
    e.preventDefault();
    // Validasi linked pengajuan saat kuitansi
    if (isKuitansiMode && !document.getElementById('tplLinkedId').value) {
        Swal.fire('Ditolak', 'Pilih <b>Template Pengajuan Acuan</b> terlebih dahulu!', 'warning');
        return;
    }
    // Kumpulkan layout
    const layoutData = [];
    document.querySelectorAll('#builderContainer .b-row').forEach(block => {
        const type = block.getAttribute('data-type');
        if (type === 'teks') {
            layoutData.push({
                type: 'teks',
                label: block.querySelector('.b-label').value,
                source: block.querySelector('.b-source').value
            });
        } else {
            const groupName   = block.querySelector('.b-group-name').value;
            let groupHeader   = '';
            let isJafung      = false;
            const ghContainer = block.querySelector('.b-group-header-container');
            const ghInput     = block.querySelector('.b-group-header');
            if (ghContainer?.style.display !== 'none' && ghInput) groupHeader = ghInput.value;
            const jafungChk = block.querySelector('.b-is-jafung');
            if (jafungChk?.checked) isJafung = true;
            block.querySelectorAll('.b-item').forEach(item => {
                layoutData.push({
                    type: 'komponen',
                    label: item.querySelector('.b-label').value,
                    id_rincian: item.querySelector('.b-rincian').value,
                    group: groupName, group_type: type,
                    group_header: groupHeader, is_jafung: isJafung
                });
            });
        }
    });
    if (layoutData.length === 0) {
        Swal.fire('Ditolak', 'Minimal harus ada 1 komponen!', 'warning'); return;
    }
    document.getElementById('inpCustomLayout').value = JSON.stringify(layoutData);

    const btn = document.getElementById('btnSubmitTpl');
    const ori = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
    btn.disabled  = true;

    fetch('honorarium_action.php', { method: 'POST', body: new FormData(e.target) })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            Swal.fire({ icon:'success', title:'Berhasil!', text:res.message, timer:1500, showConfirmButton:false })
            .then(() => window.location.reload());
        } else {
            Swal.fire('Gagal', res.message, 'error');
            btn.innerHTML = ori; btn.disabled = false;
        }
    }).catch(() => {
        Swal.fire('Error', 'Koneksi terputus', 'error');
        btn.innerHTML = ori; btn.disabled = false;
    });
}

function deleteTemplate(id, cntLinked) {
    let extraMsg = cntLinked > 0
        ? `<br><span class="text-danger small"><i class="fas fa-exclamation-triangle me-1"></i>${cntLinked} template kuitansi terhubung ke template ini. Hapus kuitansinya terlebih dahulu!</span>`
        : '';
    if (cntLinked > 0) {
        Swal.fire({ icon:'error', title:'Tidak Bisa Dihapus', html: 'Template ini masih digunakan oleh <b>'+cntLinked+'</b> template kuitansi.<br>Hapus template kuitansi yang terhubung terlebih dahulu.', confirmButtonColor:'#0d6efd' });
        return;
    }
    Swal.fire({
        title:'Hapus Template?', text:'Yakin menghapus template ini?',
        icon:'warning', showCancelButton:true, confirmButtonColor:'#d33', confirmButtonText:'Ya, Hapus!'
    }).then(result => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('action','delete_template'); fd.append('id', id);
            fetch('honorarium_action.php', { method:'POST', body:fd })
            .then(r=>r.json()).then(res => {
                if (res.status === 'success') {
                    Swal.fire({ icon:'success', title:'Terhapus!', text:res.message, timer:1500, showConfirmButton:false })
                    .then(() => window.location.reload());
                } else {
                    Swal.fire('Gagal', res.message, 'error');
                }
            });
        }
    });
}
</script>
