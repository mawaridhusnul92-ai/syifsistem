<?php
/**
 * laporan_perubahan_aset.php - LAPORAN PERUBAHAN ASET TETAP (DETIL ITEM AUDIT)
 * Versi: 13.1 (Grand Master - Consistent UI & Self-Contained Edition)
 * Perbaikan: 
 * 1. UI FIX: Memindahkan tombol "Kembali" ke kiri sejajar dengan judul.
 * 2. SELF CONTAINED: Memutus ketergantungan dari financial_action.php agar user 
 * tidak terlempar keluar saat menekan tombol simpan laporan.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$view = $_GET['view'] ?? 'hub';
$report_id = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// ?? 1. LOCAL CRUD CONTROLLER (Mencegah Tendangan Keluar Menu)
// =========================================================================
if ($action == 'save_aset_local' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $uid = (int)($_SESSION['user_id'] ?? 1);
    
    // ??? ENUM BREAKER
    @$conn->query("ALTER TABLE laporan_keuangan_setting MODIFY COLUMN jenis_laporan VARCHAR(100)");
    
    $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $judul   = trim($_POST['judul'] ?? 'Laporan Perubahan Aset');
    $start   = $_POST['start_date'] ?? date('Y-01-01');
    $akhir   = $_POST['end_date'] ?? date('Y-m-d');
    
    try {
        if ($id) {
            $stmt = $conn->prepare("UPDATE laporan_keuangan_setting SET judul_laporan=?, tgl_mulai=?, tgl_akhir=? WHERE id=?");
            $stmt->bind_param("sssi", $judul, $start, $akhir, $id);
            $stmt->execute();
            $target_id = $id;
        } else {
            $stmt = $conn->prepare("INSERT INTO laporan_keuangan_setting (judul_laporan, jenis_laporan, tgl_mulai, tgl_akhir, created_by) VALUES (?, 'perubahan_aset', ?, ?, ?)");
            $stmt->bind_param("sssi", $judul, $start, $akhir, $uid);
            $stmt->execute();
            $target_id = $conn->insert_id;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Format laporan Aset berhasil disimpan dan dirender!'];
        header("Location: index.php?page=laporan_perubahan_aset&view=render&id=$target_id");
        exit;
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal Menyimpan ke Database: ' . $e->getMessage()];
        header("Location: index.php?page=laporan_perubahan_aset");
        exit;
    }
}

if ($action == 'delete_aset_local') {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM laporan_keuangan_setting WHERE id = $id");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Riwayat laporan berhasil dihapus secara permanen.'];
    header("Location: index.php?page=laporan_perubahan_aset");
    exit;
}

// --- 1. SYNC DATA MASTER & HISTORY ---
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$history = $conn->query("SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan = 'perubahan_aset' ORDER BY s.created_at DESC");

// --- 2. DATA PARSING ---
$periods = []; $conf = null;
if ($report_id > 0) {
    $res_conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id");
    if ($res_conf && $res_conf->num_rows > 0) {
        $conf = $res_conf->fetch_assoc();
        $periods[] = ['s' => $conf['tgl_mulai'], 'e' => $conf['tgl_akhir'], 'label' => date('d M Y', strtotime($conf['tgl_akhir']))];
    }
}

// --- 3. CALCULATION ENGINE (DETIL PER ITEM) ---

function getSingleAssetHistoricalBalance($asset_id, $date, $conn) {
    $sql_gross = "SELECT (purchase_value + COALESCE((SELECT SUM(nilai_penambahan) FROM asset_improvements WHERE asset_id=$asset_id AND tanggal <= '$date'), 0)) as total_bruto FROM assets WHERE id = $asset_id";
    $res_gross = $conn->query($sql_gross)->fetch_assoc();
    
    $sql_akum = "SELECT (COALESCE(residual_value, 0) + COALESCE((SELECT SUM(nilai_susut) FROM asset_depreciation ad WHERE ad.asset_id = $asset_id AND STR_TO_DATE(CONCAT(ad.periode_tahun, '-', LPAD(ad.periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') <= '$date'), 0)) as total_akum FROM assets WHERE id = $asset_id";
    $res_akum = $conn->query($sql_akum)->fetch_assoc();

    return ['bruto' => (double)($res_gross['total_bruto'] ?? 0), 'akum'  => (double)($res_akum['total_akum'] ?? 0)];
}

function getSingleAssetPeriodActivity($asset_id, $s, $e, $conn) {
    $sql_add = "SELECT SUM(nilai_penambahan) as capex FROM asset_improvements WHERE asset_id = $asset_id AND tanggal BETWEEN '$s' AND '$e'";
    $sql_depr = "SELECT SUM(nilai_susut) as depr FROM asset_depreciation WHERE asset_id = $asset_id AND STR_TO_DATE(CONCAT(periode_tahun, '-', LPAD(periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') BETWEEN '$s' AND '$e'";
    return ['add'  => (double)($conn->query($sql_add)->fetch_assoc()['capex'] ?? 0), 'depr' => (double)($conn->query($sql_depr)->fetch_assoc()['depr'] ?? 0)];
}

function fmtAudAsset($n, $isBold = false) {
    if ($n == 0) return "-";
    $f = number_format(abs($n), 0, ',', '.');
    if ($n < 0) $f = "($f)";
    $weight = $isBold ? "900" : "400";
    return "<div style='display: flex; justify-content: flex-end; width: 100%; font-weight: $weight; white-space: nowrap; color: inherit;'><div style='width: 25px; text-align: left;'>Rp</div><div style='text-align: right; min-width: 90px;'>$f</div></div>";
}
?>

<style>
    .table-audit-asset { border: none; border-collapse: collapse; width: 100%; table-layout: fixed; margin-bottom: 0; background: #fff; }
    .table-audit-asset thead th { background: #0f172a !important; color: #ffffff !important; padding: 12px 8px; font-weight: 800; text-transform: uppercase; font-size: 10px; border: 1px solid #334155; text-align: center; line-height: 1.4; }
    .table-audit-asset tbody td { padding: 10px 8px; font-size: 12px; color: #334155; border: 1px solid #f1f5f9; vertical-align: middle; }
    .row-cat-header { background: #f1f5f9; font-weight: 800; color: #1e293b; text-transform: uppercase; border-left: 5px solid #0d6efd; }
    .row-type-header { background: #f8fafc; font-weight: 700; color: #475569; font-style: italic; }
    .row-subtotal { font-weight: 800; border-top: 1.5px solid #1e293b; background: rgba(0,0,0,0.02); }
    .row-grand-total { background: #1e293b !important; font-weight: 900; }
    .row-grand-total td { color: #ffffff !important; border: 1px solid #334155; }
    .row-grand-total .text-white-force { color: #ffffff !important; }
    .indent-item { padding-left: 45px !important; }
    .btn-oval { border-radius: 50px !important; padding-left: 20px !important; padding-right: 20px !important; font-weight: 700; text-transform: uppercase; font-size: 11px; }
    @media print { .no-print { display: none !important; } .card { border: none !important; box-shadow: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">

    <?php if ($view == 'hub'): ?>
        <!-- ??? UI FIX: Tombol Kembali disejajarkan ke kiri -->
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan&tab=asset" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0 text-dark">Laporan Perubahan Aset</h4>
                    <small class="text-muted small fw-bold uppercase">Audit Inventaris & Penyusutan Berjalan</small>
                </div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="asset_openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</button>
        </div>
        
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0 text-center"><thead class="table-dark small text-uppercase"><tr><th width="120">Aksi</th><th>Hingga Tanggal</th><th class="text-start ps-5">Judul Laporan</th><th class="pe-4" width="160">Eksekusi</th></tr></thead><tbody>
                <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()) { ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden"><button class="btn btn-white text-warning border-end" data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $row['tgl_mulai'] ?>" data-end="<?= $row['tgl_akhir'] ?>" onclick='asset_editSetup(this)' title="Ubah"><i class="fas fa-edit"></i></button><button class="btn btn-white text-danger" onclick="asset_deleteReport(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button></div></td>
                        <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d M Y', strtotime($row['tgl_akhir'])) ?></span></td>
                        <td class="text-start ps-5 fw-bold text-primary"><?= $row['judul_laporan'] ?></td>
                        <td class="pe-4 text-center"><a href="index.php?page=laporan_perubahan_aset&view=render&id=<?= $row['id'] ?>" class="btn btn-primary btn-oval shadow-sm px-4">Tampilkan</a></td></tr>
                <?php } else: echo "<tr><td colspan='4' class='py-5 text-muted text-center'>Belum ada riwayat laporan aset.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <!-- ??? UI FIX: Tombol Kembali disejajarkan ke kiri -->
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-3 py-3 border text-dark">
            <div class="d-flex gap-2 align-items-center">
                <a href="index.php?page=laporan_perubahan_aset" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" onclick='asset_editSetup(this)' data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $conf['tgl_mulai'] ?>" data-end="<?= $conf['tgl_akhir'] ?>"><i class="fas fa-cog me-1"></i> UBAH SETTING</button>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2">
                <button class="btn btn-light border rounded-pill px-4 text-success fw-bold small shadow-sm" onclick="exportToExcelAsset('assetAuditTable', 'Lap_Perubahan_Aset')"><i class="fas fa-file-excel me-2"></i>EXCEL</button>
                <a href="print_perubahan_aset.php?id=<?= $report_id ?>" target="_blank" class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase"><i class="fas fa-print me-2"></i>CETAK PDF</a>
            </div>
        </div>

        <div class="card border-0 bg-white p-0 shadow-sm overflow-hidden rounded-4 text-dark">
            <div class="p-5 text-center bg-light border-bottom">
                <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                <h4 class="fw-bold text-primary mb-3 text-decoration-underline text-uppercase">Laporan Perubahan Aset Tetap</h4>
                <p class="text-muted mb-0 italic" id="reportPeriodText">Periode: <?= date('d M Y', strtotime($conf['tgl_mulai'])) ?> s.d <?= date('d M Y', strtotime($conf['tgl_akhir'])) ?></p>
            </div>

            <div class="table-responsive"><table class="table-audit-asset" id="assetAuditTable">
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 250px;">JENIS & RINCIAN ITEM ASET</th>
                        <th colspan="2">SALDO AWAL (<?= date('d/m/y', strtotime($conf['tgl_mulai'] . ' -1 day')) ?>)</th>
                        <th colspan="2">MUTASI PERIODE BERJALAN</th>
                        <th colspan="3">SALDO AKHIR (<?= date('d/m/y', strtotime($conf['tgl_akhir'])) ?>)</th>
                    </tr>
                    <tr>
                        <th width="140">HARGA PEROLEHAN</th>
                        <th width="140">AKUM. PENYUSUTAN</th>
                        <th width="125" class="text-success">PENAMBAHAN</th>
                        <th width="125" class="text-danger">PENYUSUTAN</th>
                        <th width="140">HARGA PEROLEHAN</th>
                        <th width="140">AKUM. PENYUSUTAN</th>
                        <th width="160" class="bg-light text-primary">NILAI BUKU NETO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $cats = [['label'=>'A. ASET TETAP BERWUJUD', 'type'=>'Tetap'], ['label'=>'B. ASET TETAP TIDAK BERWUJUD', 'type'=>'Tidak Terwujud']];
                    $grand = array_fill(0, 7, 0);

                    foreach($cats as $c):
                        $sub = array_fill(0, 7, 0);
                        echo "<tr class='row-cat-header'><td colspan='8' class='ps-3'>{$c['label']}</td></tr>";
                        
                        $sql_types = "SELECT t.id, t.type_name FROM asset_types t JOIN asset_categories ac ON t.category_id = ac.id WHERE ac.asset_type = '{$c['type']}' ORDER BY t.type_name ASC";
                        $res_types = $conn->query($sql_types);
                        
                        while($t = $res_types->fetch_assoc()):
                            echo "<tr class='row-type-header'><td colspan='8' class='ps-4'>[ Klasifikasi: {$t['type_name']} ]</td></tr>";
                            
                            $sql_items = "SELECT * FROM assets WHERE type_id = {$t['id']} AND status='Aktif' AND purchase_date <= '{$conf['tgl_akhir']}' ORDER BY asset_name ASC";
                            $res_items = $conn->query($sql_items);
                            
                            $type_total = array_fill(0, 7, 0);
                            while($item = $res_items->fetch_assoc()):
                                $prev_date = date('Y-m-d', strtotime($conf['tgl_mulai'] . ' -1 day'));
                                $awal = getSingleAssetHistoricalBalance($item['id'], $prev_date, $conn);
                                $act = getSingleAssetPeriodActivity($item['id'], $conf['tgl_mulai'], $conf['tgl_akhir'], $conn);
                                
                                $akhir_bruto = $awal['bruto'] + $act['add'];
                                $akhir_akum  = $awal['akum'] + $act['depr'];
                                $nbv = $akhir_bruto - $akhir_akum;

                                if($akhir_bruto == 0) continue;

                                $type_total[0]+=$awal['bruto']; $type_total[1]+=$awal['akum']; $type_total[2]+=$act['add']; $type_total[3]+=$act['depr'];
                                $type_total[4]+=$akhir_bruto; $type_total[5]+=$akhir_akum; $type_total[6]+=$nbv;
                    ?>
                        <tr>
                            <td class="indent-item"><?= $item['asset_name'] ?> <br><small class='text-muted' style='font-size:9px;'><?= $item['asset_code'] ?></small></td>
                            <td><?= fmtAudAsset($awal['bruto']) ?></td>
                            <td><?= fmtAudAsset($awal['akum']) ?></td>
                            <td class="text-success"><?= fmtAudAsset($act['add']) ?></td>
                            <td class="text-danger"><?= fmtAudAsset($act['depr']) ?></td>
                            <td><?= fmtAudAsset($akhir_bruto) ?></td>
                            <td><?= fmtAudAsset($akhir_akum) ?></td>
                            <td class="bg-light fw-bold text-primary"><?= fmtAudAsset($nbv, true) ?></td>
                        </tr>
                    <?php endwhile; 
                        for($i=0; $i<7; $i++) $sub[$i] += $type_total[$i];
                    endwhile; ?>
                        <tr class="row-subtotal">
                            <td class="ps-3 fw-bold">JUMLAH <?= str_replace(['A. ', 'B. '], '', $c['label']) ?></td>
                            <td><?= fmtAudAsset($sub[0], true) ?></td>
                            <td><?= fmtAudAsset($sub[1], true) ?></td>
                            <td class="text-success"><?= fmtAudAsset($sub[2], true) ?></td>
                            <td class="text-danger"><?= fmtAudAsset($sub[3], true) ?></td>
                            <td><?= fmtAudAsset($sub[4], true) ?></td>
                            <td><?= fmtAudAsset($sub[5], true) ?></td>
                            <td class="text-primary"><?= fmtAudAsset($sub[6], true) ?></td>
                        </tr>
                    <?php 
                        for($i=0; $i<7; $i++) $grand[$i] += $sub[$i];
                    endforeach; ?>

                    <tr style="height: 30px;"><td colspan="8" style="border:none;"></td></tr>
                    
                    <tr class="row-grand-total">
                        <td class="ps-3 py-3 text-white uppercase fw-bold text-white-force">TOTAL KEKAYAAN ASET INSTITUSI</td>
                        <td><?= fmtAudAsset($grand[0], true) ?></td>
                        <td><?= fmtAudAsset($grand[1], true) ?></td>
                        <td><?= fmtAudAsset($grand[2], true) ?></td>
                        <td><?= fmtAudAsset($grand[3], true) ?></td>
                        <td><?= fmtAudAsset($grand[4], true) ?></td>
                        <td><?= fmtAudAsset($grand[5], true) ?></td>
                        <td class="text-white-force fw-bold" style="font-size: 14px;"><?= fmtAudAsset($grand[6], true) ?></td>
                    </tr>
                </tbody>
            </table></div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL SETUP (MANDIRI) -->
<div class="modal fade" id="modalAssetSetup" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered text-dark">
    <!-- ??? FIX MUTLAK: Action diarahkan ke file ini sendiri -->
    <form action="index.php?page=laporan_perubahan_aset" method="POST" id="formAssetSetup" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
        <input type="hidden" name="action" value="save_aset_local">
        <input type="hidden" name="id" id="setup_id">
        <div class="modal-header bg-primary text-white border-0 p-4"><h5 class="modal-title fw-bold text-white">Konfigurasi Laporan Aset</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4 bg-light text-dark">
            <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label><input type="text" name="judul" id="setup_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div>
            <div class="row g-2">
                <div class="col-6"><label class="small fw-bold text-primary mb-1 uppercase">Dari Tanggal</label><input type="date" name="start_date" id="setup_start" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required></div>
                <div class="col-6"><label class="small fw-bold text-primary mb-1 uppercase">Hingga Tanggal</label><input type="date" name="end_date" id="setup_end" class="form-control border-0 bg-white rounded-pill px-4 shadow-sm" required></div>
            </div>
        </div>
        <div class="modal-footer border-0 p-4 pt-0 bg-light"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase">Generate Laporan</button></div>
    </form>
</div></div>

<script>
function asset_openSetupModal() { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAssetSetup')); document.getElementById('setup_id').value = ''; document.getElementById('setup_judul').value = 'Laporan Perubahan Aset ' + new Date().getFullYear(); document.getElementById('setup_start').value = '<?= date("Y-01-01") ?>'; document.getElementById('setup_end').value = '<?= date("Y-m-d") ?>'; m.show(); }
function asset_editSetup(el) { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAssetSetup')); const d = el.dataset; document.getElementById('setup_id').value = d.id; document.getElementById('setup_judul').value = d.judul; document.getElementById('setup_start').value = d.start; document.getElementById('setup_end').value = d.end; m.show(); }
function asset_deleteReport(id) { if(confirm('Hapus arsip laporan aset?')) window.location.href=`index.php?page=laporan_perubahan_aset&action=delete_aset_local&id=${id}`; }

function exportToExcelAsset(tableId, filename) {
    const table = document.getElementById(tableId);
    const form = document.createElement('form'); form.method = 'POST'; form.action = 'export_excel_engine.php'; form.target = '_blank';
    const inputs = [{ name: 'judul_laporan', value: document.getElementById('reportTitleHeader').innerText }, { name: 'nama_file', value: filename }, { name: 'periode_text', value: document.getElementById('reportPeriodText').innerText }, { name: 'html_content', value: table.outerHTML }];
    inputs.forEach(data => { const input = document.createElement('input'); input.type = 'hidden'; input.name = data.name; input.value = data.value; form.appendChild(input); });
    document.body.appendChild(form); form.submit(); document.body.removeChild(form);
}
</script>