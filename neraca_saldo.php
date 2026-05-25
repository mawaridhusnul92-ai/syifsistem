<?php
/**
 * neraca_saldo.php - NERACA SALDO & INVESTIGASI SUPREME (ISAK 35)
 * Versi: 2.3 (Grand Master - Consistent UI & Self-Contained Edition)
 * Perbaikan: 
 * 1. UI FIX: Memindahkan tombol "Kembali" ke sisi kiri sejajar dengan judul.
 * 2. SELF-CONTAINED: Memutus ketergantungan dari financial_action.php agar tidak 
 * ada lagi error "terlempar keluar" saat membuat laporan baru.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$view = $_GET['view'] ?? 'hub';
$report_id = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// ?? 1. LOCAL CRUD CONTROLLER (Mencegah Tendangan Keluar Menu)
// =========================================================================
if ($action == 'save_ns_local' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $uid = (int)($_SESSION['user_id'] ?? 1);
    
    // ??? ENUM BREAKER
    @$conn->query("ALTER TABLE laporan_keuangan_setting MODIFY COLUMN jenis_laporan VARCHAR(100)");
    
    $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $judul   = trim($_POST['judul'] ?? 'Neraca Saldo');
    $start   = $_POST['start_date'] ?? date('Y-01-01');
    $akhir   = $_POST['end_date'] ?? date('Y-m-d');
    
    try {
        if ($id) {
            $stmt = $conn->prepare("UPDATE laporan_keuangan_setting SET judul_laporan=?, tgl_mulai=?, tgl_akhir=? WHERE id=?");
            $stmt->bind_param("sssi", $judul, $start, $akhir, $id);
            $stmt->execute();
            $target_id = $id;
        } else {
            $stmt = $conn->prepare("INSERT INTO laporan_keuangan_setting (judul_laporan, jenis_laporan, tgl_mulai, tgl_akhir, created_by) VALUES (?, 'neraca_saldo', ?, ?, ?)");
            $stmt->bind_param("sssi", $judul, $start, $akhir, $uid);
            $stmt->execute();
            $target_id = $conn->insert_id;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Format laporan berhasil disimpan dan dirender!'];
        header("Location: index.php?page=neraca_saldo&view=render&id=$target_id");
        exit;
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal Menyimpan ke Database: ' . $e->getMessage()];
        header("Location: index.php?page=neraca_saldo");
        exit;
    }
}

if ($action == 'delete_ns_local') {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM laporan_keuangan_setting WHERE id = $id");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Riwayat laporan berhasil dihapus secara permanen.'];
    header("Location: index.php?page=neraca_saldo");
    exit;
}

// --- 1. DATA MASTER & HISTORY ---
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$history = $conn->query("SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan = 'neraca_saldo' ORDER BY s.created_at DESC");

// --- 2. LOGIKA RENDER DATA ---
if (($view == 'render' || $view == 'drill') && $report_id > 0) {
    $conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
    if (!$conf) die("<div class='alert alert-danger rounded-4 m-4 text-center'>Laporan Tidak Ditemukan.</div>");
    $start_date = $conf['tgl_mulai']; 
    $end_date = $conf['tgl_akhir'];
    $all_accounts = $conn->query("SELECT * FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
}

/**
 * getAccountSummary - Kalkulasi Saldo Terintegrasi
 */
if (!function_exists('getAccountSummary')) {
    function getAccountSummary($kode, $s_date, $e_date, $conn) {
        $acc = $conn->query("SELECT opening_balance, saldo_normal FROM syifa_akun WHERE kode_akun='$kode'")->fetch_assoc();
        // Mutasi s/d H-1
        $q_awal = $conn->query("SELECT SUM(debit - kredit) as net FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id=j.id WHERE jd.kode_akun='$kode' AND j.tgl_jurnal < '$s_date'")->fetch_assoc();
        $mut_awal = (double)($q_awal['net'] ?? 0);
        $saldo_awal = (double)$acc['opening_balance'] + (($acc['saldo_normal'] == 'D') ? $mut_awal : -$mut_awal);
        
        // Mutasi Periode Berjalan
        $q_mutasi = $conn->query("SELECT SUM(debit) as d, SUM(kredit) as k FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun='$kode' AND j.tgl_jurnal BETWEEN '$s_date' AND '$e_date'")->fetch_assoc();
        $debet = (double)($q_mutasi['d'] ?? 0); 
        $kredit = (double)($q_mutasi['k'] ?? 0);
        
        $saldo_akhir = $saldo_awal + (($acc['saldo_normal'] == 'D') ? ($debet - $kredit) : ($kredit - $debet));
        return ['awal' => $saldo_awal, 'debet' => $debet, 'kredit' => $kredit, 'akhir' => $saldo_akhir];
    }
}
?>

<style>
    .table-ns thead th { background: #1e293b !important; color: #fff !important; font-size: 9px; text-transform: uppercase; text-align: center !important; vertical-align: middle !important; padding: 12px 5px; border: 1px solid #334155; }
    .table-ns tbody td { font-size: 12.5px; border-bottom: 1px solid #f1f5f9; padding: 10px; vertical-align: middle; color: #334155; }
    .row-group { background: #f8fafc; font-weight: 800; color: #1e293b; border-left: 4px solid #0d6efd; }
    .drill-link { text-decoration: none; color: #0d6efd; font-weight: 700; border-bottom: 1px dashed #0d6efd; cursor: pointer; transition: 0.2s; }
    .drill-link:hover { text-decoration: underline; background: rgba(13, 110, 253, 0.05); color: #000; }
    .id-badge { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 6px; font-family: monospace; font-size: 11px; margin-right: 5px; border: 1px solid #e2e8f0; }
    .btn-oval { border-radius: 50px !important; padding-left: 20px !important; padding-right: 20px !important; font-weight: 700; text-transform: uppercase; font-size: 11px; }
    .val-label { font-weight: bold; text-align: right; }
    @media print { .no-print { display: none !important; } .card { border: none !important; box-shadow: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">

    <?php if ($view == 'hub'): ?>
        <!-- VIEW 1: HUB (RIWAYAT) -->
        <!-- ??? UI FIX: Tombol Kembali dipindah ke kiri, sejajar dengan judul -->
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan&tab=asset" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0 text-dark">Neraca Saldo & Rekonsiliasi</h4>
                    <small class="text-muted small fw-bold">Audit posisi keuangan komprehensif institusi.</small>
                </div>
            </div>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="ns_openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</button>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0 text-center"><thead class="table-dark small text-uppercase"><tr><th width="120">Aksi</th><th>Hingga Tanggal</th><th>Judul Laporan</th><th class="pe-4" width="160">Eksekusi</th></tr></thead><tbody>
                <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()) { ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden"><button class="btn btn-white text-warning border-end" data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $row['tgl_mulai'] ?>" data-end="<?= $row['tgl_akhir'] ?>" onclick='ns_editSetup(this)' title="Ubah"><i class="fas fa-edit"></i></button><button class="btn btn-white text-danger" onclick="ns_deleteReport(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button></div></td>
                        <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d M Y', strtotime($row['tgl_akhir'])) ?></span></td>
                        <td class="text-start ps-5 fw-bold text-primary"><?= $row['judul_laporan'] ?></td>
                        <td class="pe-4 text-center"><a href="index.php?page=neraca_saldo&view=render&id=<?= $row['id'] ?>" class="btn btn-primary btn-oval shadow-sm px-4">Tampilkan</a></td></tr>
                <?php } else: echo "<tr><td colspan='4' class='py-5 text-muted small italic text-center'>Belum ada arsip laporan neraca saldo.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <!-- VIEW 2: RENDER (TABEL NERACA SALDO) -->
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-3 py-3 border text-dark">
            <div class="d-flex gap-2 align-items-center">
                <a href="index.php?page=neraca_saldo" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $conf['tgl_mulai'] ?>" data-end="<?= $conf['tgl_akhir'] ?>" onclick='ns_editSetup(this)'><i class="fas fa-cog me-1"></i> UBAH SETTING</button>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2">
                <button class="btn btn-light border rounded-pill px-4 text-success fw-bold small shadow-sm" onclick="exportToExcelStability('nsTable', 'Neraca_Saldo')"><i class="fas fa-file-excel me-2"></i>EXCEL</button>
                <a href="print_neraca_saldo.php?id=<?= $report_id ?>" target="_blank" class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase"><i class="fas fa-print me-2"></i>CETAK PDF</a>
            </div>
        </div>

        <div class="card border-0 shadow-lg rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="p-5 text-center bg-light border-bottom">
                <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                <h4 class="fw-bold text-primary mb-3">NERACA SALDO & PERCOBAAN (TRIAL BALANCE)</h4>
                <p class="text-muted mb-0 italic" id="reportPeriodText">Audit Periode <?= date('d M Y', strtotime($start_date)) ?> s/d <?= date('d M Y', strtotime($end_date)) ?></p>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-ns" id="nsTable">
                    <thead><tr><th width="50">NO</th><th width="120">KODE AKUN</th><th class="text-start ps-4">NAMA AKUN PERKIRAAN</th><th class="text-end" width="150">SALDO AWAL</th><th class="text-end" width="140">DEBET (+)</th><th class="text-end" width="140">KREDIT (-)</th><th class="text-end pe-5" width="160">SALDO AKHIR</th></tr></thead>
                    <tbody>
                        <?php 
                        $no=1; $gt = ['aw'=>0, 'd'=>0, 'k'=>0, 'ak'=>0];
                        foreach(['Aset', 'Liabilitas', 'Aset Neto', 'Pendapatan', 'Beban'] as $kat):
                            echo "<tr class='row-group'><td colspan='7' class='ps-4'>KELOMPOK: ".strtoupper($kat)."</td></tr>";
                            foreach($all_accounts as $acc):
                                if($acc['kategori'] == $kat):
                                    $res = getAccountSummary($acc['kode_akun'], $start_date, $end_date, $conn);
                                    if(abs($res['awal']) < 0.01 && abs($res['debet']) < 0.01 && abs($res['kredit']) < 0.01) continue;
                                    $gt['aw']+=$res['awal']; $gt['d']+=$res['debet']; $gt['k']+=$res['kredit']; $gt['ak']+=$res['akhir'];
                        ?>
                                    <tr><td class="text-center"><?= $no++ ?></td>
                                        <td class="text-center"><code><?= $acc['kode_akun'] ?></code></td>
                                        <td class="text-start fw-bold ps-4"><?= $acc['nama_akun'] ?></td>
                                        <td class="text-end val-label"><?= number_format($res['awal'], 0, ',', '.') ?></td>
                                        <td class="text-end"><a href="?page=neraca_saldo&view=drill&id=<?= $report_id ?>&kode=<?= $acc['kode_akun'] ?>&type=debit" class="drill-link val-label"><?= number_format($res['debet'], 0, ',', '.') ?></a></td>
                                        <td class="text-end"><a href="?page=neraca_saldo&view=drill&id=<?= $report_id ?>&kode=<?= $acc['kode_akun'] ?>&type=kredit" class="drill-link val-label"><?= number_format($res['kredit'], 0, ',', '.') ?></a></td>
                                        <td class="text-end pe-5 fw-bold text-dark val-label">Rp <?= number_format($res['akhir'], 0, ',', '.') ?></td></tr>
                        <?php endif; endforeach; endforeach; ?>
                    </tbody>
                    <tfoot class="fw-bold bg-dark text-white">
                        <tr><td colspan="3" class="ps-4 py-4 text-white uppercase">Total Balance Check</td><td class="text-end text-white"><?= number_format($gt['aw'], 0, ',', '.') ?></td><td class="text-end text-white"><?= number_format($gt['d'], 0, ',', '.') ?></td><td class="text-end text-white"><?= number_format($gt['k'], 0, ',', '.') ?></td><td class="text-end pe-5 fs-5 text-white">Rp <?= number_format($gt['ak'], 0, ',', '.') ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

    <?php elseif ($view == 'drill'): ?>
        <!-- VIEW 3: DRILL DOWN (AUDIT INVESTIGASI SALDO) -->
        <?php 
            $kode_drill = $_GET['kode'] ?? '';
            $type_drill = $_GET['type'] ?? 'debit';
            $acc_info = $conn->query("SELECT * FROM syifa_akun WHERE kode_akun = '$kode_drill'")->fetch_assoc();
            $cond_type = ($type_drill == 'debit') ? "jd.debit > 0" : "jd.kredit > 0";
            
            $sql_drill = "SELECT j.*, jd.debit as d_val, jd.kredit as k_val, p.nama_lengkap as nama_pegawai, p.nip as nip_pegawai, m.nama as nama_mhs, m.nim as nim_mhs
                          FROM syifa_jurnal j JOIN syifa_jurnal_detail jd ON j.id = jd.jurnal_id
                          LEFT JOIN hr_pegawai p ON jd.pegawai_id = p.id LEFT JOIN syifa_mahasiswa m ON jd.mahasiswa_id = m.id
                          WHERE jd.kode_akun = '$kode_drill' AND $cond_type AND j.tgl_jurnal BETWEEN '$start_date' AND '$end_date'
                          ORDER BY j.tgl_jurnal ASC, j.id ASC";
            $drill_res = $conn->query($sql_drill);
        ?>
        
        <!-- ??? UI FIX: Tombol Kembali dipindah ke kiri di halaman Drill Down -->
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-info border-4 no-print text-dark text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=neraca_saldo&view=render&id=<?= $report_id ?>" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0">Audit Investigasi Saldo: <?= $acc_info['nama_akun'] ?></h4>
                    <small class="text-muted uppercase fw-bold">Tipe: <b><?= strtoupper($type_drill) ?></b> | Periode: <?= date('d/m/y', strtotime($start_date)) ?> - <?= date('d/m/y', strtotime($end_date)) ?></small>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-dark small text-uppercase"><tr><th width="100">Tanggal</th><th width="120">No. Jurnal</th><th>Pihak / Sub-Ledger</th><th>Keterangan / Memo</th><th class="text-end pe-4">Nominal</th><th width="100" class="text-center">Voucher</th></tr></thead><tbody>
                <?php if($drill_res && $drill_res->num_rows > 0): while($r = $drill_res->fetch_assoc()): 
                    $val_final = ($type_drill == 'debit') ? $r['d_val'] : $r['k_val'];
                    $identitas = "<span class='text-muted small italic'>Transaksi Umum</span>";
                    if(!empty($r['nama_pegawai'])) $identitas = "<span class='id-badge'>{$r['nip_pegawai']}</span> ".strtoupper($r['nama_pegawai']);
                    elseif(!empty($r['nama_mhs'])) $identitas = "<span class='id-badge'>{$r['nim_mhs']}</span> ".strtoupper($r['nama_mhs']);
                ?>
                    <tr><td><?= date('d/m/y', strtotime($r['tgl_jurnal'])) ?></td>
                        <td class="fw-bold"><code><?= $r['no_jurnal'] ?></code></td>
                        <td><?= $identitas ?></td>
                        <td><?= htmlspecialchars($r['keterangan']) ?></td>
                        <td class="text-end pe-4 fw-bold <?= $type_drill=='debit'?'text-success':'text-danger' ?>">Rp <?= number_format($val_final, 0, ',', '.') ?></td>
                        <td class="text-center"><a href="print_voucher.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-xs btn-outline-primary rounded-pill px-3 fw-bold shadow-sm small"><i class="fas fa-print me-1"></i>BUKTI</a></td></tr>
                <?php endwhile; else: echo "<tr><td colspan='6' class='py-5 text-center text-muted italic'>Tidak ada rincian transaksi ditemukan untuk kategori ini.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL SETUP -->
<div class="modal fade" id="modalSetupNS" tabindex="-1" data-bs-backdrop="static" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered">
        <form action="index.php?page=neraca_saldo" method="POST" id="formSetupNS" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_ns_local">
            <input type="hidden" name="id" id="ns_setup_id">
            <div class="modal-header bg-primary text-white border-0 p-4"><h5 class="modal-title fw-bold text-white" id="ns_modal_title">Konfigurasi Audit Neraca</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 bg-light text-dark">
                <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label><input type="text" name="judul" id="ns_setup_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div>
                <div class="row g-2">
                    <div class="col-6"><label class="small fw-bold text-muted mb-1 uppercase">Mulai Tanggal</label><input type="date" name="start_date" id="ns_setup_start" class="form-control border-0 rounded-pill px-4 shadow-sm" required></div>
                    <div class="col-6"><label class="small fw-bold text-muted mb-1 uppercase">Hingga Tanggal</label><input type="date" name="end_date" id="ns_setup_end" class="form-control border-0 rounded-pill px-4 shadow-sm" required></div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 bg-light text-center d-block"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase">Hasilkan Neraca Saldo</button></div>
        </form>
    </div>
</div>

<script>
function ns_openSetupModal() { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSetupNS')); document.getElementById('ns_setup_id').value = ''; document.getElementById('ns_setup_judul').value = 'Neraca Saldo ' + new Date().getFullYear(); document.getElementById('ns_setup_start').value = '<?= date("Y-m-01") ?>'; document.getElementById('ns_setup_end').value = '<?= date("Y-m-d") ?>'; document.getElementById('ns_modal_title').innerText = 'Buat Laporan Baru'; m.show(); }
function ns_editSetup(el) { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSetupNS')); const d = el.dataset; document.getElementById('ns_setup_id').value = d.id; document.getElementById('ns_setup_judul').value = d.judul; document.getElementById('ns_setup_start').value = d.start; document.getElementById('ns_setup_end').value = d.end; document.getElementById('ns_modal_title').innerText = 'Ubah Parameter Laporan'; m.show(); }
function ns_deleteReport(id) { if(confirm('Hapus arsip laporan neraca saldo ini secara permanen?')) window.location.href=`index.php?page=neraca_saldo&action=delete_ns_local&id=${id}`; }

/** MESIN EXPORT ATOMIC STABILITY v2.3 */
function exportToExcelStability(tableId, filename) {
    const table = document.getElementById(tableId);
    const clone = table.cloneNode(true);
    clone.querySelectorAll('td').forEach(td => {
        const val = td.querySelector('.val-label');
        if (val) { td.innerHTML = val.innerText.trim().replace(/\./g, ''); } 
        else { td.innerHTML = td.innerText.trim(); }
    });
    clone.querySelectorAll('i, button, script, a').forEach(el => el.remove());
    const form = document.createElement('form'); form.method = 'POST'; form.action = 'export_excel_engine.php'; form.target = '_blank';
    const inputs = [{ name: 'judul_laporan', value: document.getElementById('reportTitleHeader').innerText }, { name: 'nama_file', value: filename }, { name: 'periode_text', value: document.getElementById('reportPeriodText').innerText }, { name: 'html_content', value: clone.outerHTML }];
    inputs.forEach(data => { const input = document.createElement('input'); input.type = 'hidden'; input.name = data.name; input.value = data.value; form.appendChild(input); });
    document.body.appendChild(form); form.submit(); document.body.removeChild(form);
}
</script>