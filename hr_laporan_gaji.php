<?php
/**
 * hr_laporan_gaji.php - LAPORAN KOMPREHENSIF GAJI PEGAWAI (REAL-TIME SYNC)
 * Versi: 2.1 (Sovereign Grand Master - Consistent UI Header)
 * Perbaikan: 
 * 1. UI FIX: Memindahkan tombol "Kembali" ke sisi kiri sejajar dengan judul laporan
 * agar 100% konsisten dengan modul Neraca, Aktivitas, dan Arus Kas.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$view = $_GET['view'] ?? 'hub';
$report_id = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// ?? 0. SELF-CONTAINED ACTION HANDLER (Mencegah Terlempar Keluar)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'delete_report_internal') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM laporan_keuangan_setting WHERE id = $id");
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Riwayat laporan berhasil dihapus.'];
        header("Location: index.php?page=hr_laporan_gaji"); 
        exit;
    }

    if ($_POST['action'] === 'save_gaji_local') {
        $uid = (int)($_SESSION['user_id'] ?? 1);
        
        // ??? ENUM BREAKER
        @$conn->query("ALTER TABLE laporan_keuangan_setting MODIFY COLUMN jenis_laporan VARCHAR(100)");
        
        $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $judul   = trim($_POST['judul'] ?? 'Laporan Gaji');
        $start   = $_POST['start_date'] ?? date('Y-m-01');
        $akhir   = $_POST['end_date'] ?? date('Y-m-t');
        $desc    = $_POST['deskripsi'] ?? '';
        
        try {
            if ($id) {
                $stmt = $conn->prepare("UPDATE laporan_keuangan_setting SET judul_laporan=?, tgl_mulai=?, tgl_akhir=?, deskripsi=? WHERE id=?");
                $stmt->bind_param("ssssi", $judul, $start, $akhir, $desc, $id);
                $stmt->execute();
                $target_id = $id;
            } else {
                $stmt = $conn->prepare("INSERT INTO laporan_keuangan_setting (judul_laporan, jenis_laporan, tgl_mulai, tgl_akhir, deskripsi, created_by) VALUES (?, 'gaji_pegawai', ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $judul, $start, $akhir, $desc, $uid);
                $stmt->execute();
                $target_id = $conn->insert_id;
            }
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Laporan Gaji berhasil disimpan dan dirender!'];
            header("Location: index.php?page=hr_laporan_gaji&view=render&id=$target_id");
            exit;
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()];
            header("Location: index.php?page=hr_laporan_gaji");
            exit;
        }
    }
}

// --- 1. DATA MASTER ---
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$units = $conn->query("SELECT DISTINCT unit_kerja FROM hr_pegawai WHERE unit_kerja IS NOT NULL ORDER BY unit_kerja ASC")->fetch_all(MYSQLI_ASSOC);
$pegawai_all = $conn->query("SELECT id, nip, nama_lengkap FROM hr_pegawai WHERE status_aktif=1 ORDER BY nama_lengkap ASC")->fetch_all(MYSQLI_ASSOC);
$history = $conn->query("SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan = 'gaji_pegawai' ORDER BY s.created_at DESC");

// --- 2. LOGIKA RENDER ---
$conf = null; $rows = []; $mode = 'summary'; $target_peg_id = 0;
if ($view == 'render' && $report_id > 0) {
    $conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
    if ($conf) {
        $s = $conf['tgl_mulai'];
        $e = $conf['tgl_akhir'];
        $params = !empty($conf['deskripsi']) ? json_decode($conf['deskripsi'], true) : [];
        $mode = $params['display_mode'] ?? 'summary';
        $target_peg_id = (int)($params['pegawai_id'] ?? 0);

        if($mode == 'rekap_individu' && $target_peg_id > 0) {
            $sql = "SELECT d.*, p.nip, p.nama_lengkap, p.jabatan, p.unit_kerja, 
                           h.tgl_slip, h.status as status_bayar, h.id as payroll_header_id,
                           h.periode_bulan, h.periode_tahun, h.pembayaran_jurnal_id
                    FROM hr_payroll_detail d
                    JOIN hr_pegawai p ON d.pegawai_id = p.id
                    JOIN hr_payroll_header h ON d.payroll_id = h.id
                    WHERE d.pegawai_id = $target_peg_id 
                    AND h.tgl_slip BETWEEN '$s' AND '$e'
                    AND UPPER(h.status) != 'DRAFT' 
                    ORDER BY h.tgl_slip ASC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
            
            $komponen_res = $conn->query("SELECT pk.nominal, k.nama_komponen, k.jenis 
                                          FROM hr_pegawai_komponen pk 
                                          JOIN hr_komponen k ON pk.komponen_id = k.id 
                                          WHERE pk.pegawai_id = $target_peg_id 
                                          ORDER BY k.jenis DESC, k.nama_komponen ASC")->fetch_all(MYSQLI_ASSOC);
        } else {
            $sql = "SELECT d.*, p.nip, p.nama_lengkap, p.jabatan, p.unit_kerja, 
                           h.tgl_slip, h.status as status_bayar, h.periode_bulan, h.periode_tahun,
                           h.pembayaran_jurnal_id
                    FROM hr_payroll_detail d
                    JOIN hr_pegawai p ON d.pegawai_id = p.id
                    JOIN hr_payroll_header h ON d.payroll_id = h.id
                    WHERE h.tgl_slip BETWEEN '$s' AND '$e'
                    AND UPPER(h.status) != 'DRAFT'";
            
            if(!empty($params['unit'])) $sql .= " AND p.unit_kerja = '".$conn->real_escape_string($params['unit'])."'";
            $sql .= " ORDER BY p.unit_kerja ASC, p.nama_lengkap ASC";
            $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
        }
    }
}

function fmtGaji($n) { 
    return number_format($n, 0, ',', '.'); 
}

$nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
?>

<style>
    .table-payroll thead th { background: #1e293b !important; color: #fff !important; font-size: 9px; text-transform: uppercase; text-align: center; border: 1px solid #334155; padding: 12px 5px; }
    .table-payroll tbody td { font-size: 12px; border-bottom: 1px solid #f1f5f9; padding: 10px; vertical-align: middle; }
    .bg-group { background: #e0f2fe; font-weight: 800; color: #0369a1; border-left: 5px solid #0284c7; }
    .text-gross { color: #059669; font-weight: 700; }
    .text-deduct { color: #dc2626; font-weight: 700; }
    .btn-oval { border-radius: 50px !important; font-weight: 700; text-transform: uppercase; font-size: 11px; }
    .card-rekap { border: 1px solid #e2e8f0; border-radius: 24px; background: #fff; overflow: hidden; }
    .rekap-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 30px; }
    .comp-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #f1f5f9; }
    .total-box { background: #0f172a; color: #fff; padding: 25px; border-radius: 18px; margin-top: 20px; }
    @media print { .no-print { display: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-info-circle me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if ($view == 'hub'): ?>
        <!-- ??? UI FIX: Memindahkan tombol Kembali ke kiri sejajar dengan judul -->
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan&tab=asset" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0 text-dark">Laporan Rincian Gaji Pegawai</h4>
                    <small class="text-muted small fw-bold uppercase">Data Real-time dari Modul Kepegawaian</small>
                </div>
            </div>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="payroll_openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan Baru</button>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0 text-center"><thead class="table-dark small text-uppercase"><tr><th width="120">Aksi</th><th>Periode Filter</th><th class="text-start ps-5">Judul Laporan</th><th class="pe-4" width="160">Eksekusi</th></tr></thead><tbody>
                <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()) { ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden">
                                <button class="btn btn-white text-warning border-end" onclick='payroll_openSetupModal(<?= json_encode($row) ?>)' title="Ubah"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-white text-danger" onclick="payroll_deleteReportInternal(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button>
                            </div></td>
                        <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d M y', strtotime($row['tgl_mulai'])) ?> - <?= date('d M y', strtotime($row['tgl_akhir'])) ?></span></td>
                        <td class="text-start ps-5 fw-bold text-primary"><?= $row['judul_laporan'] ?></td>
                        <td class="pe-4 text-center"><a href="index.php?page=hr_laporan_gaji&view=render&id=<?= $row['id'] ?>" class="btn btn-primary rounded-pill px-4 btn-sm fw-bold shadow-sm">Tampilkan</a></td></tr>
                <?php } else: echo "<tr><td colspan='4' class='py-5 text-muted text-center italic'>Belum ada riwayat laporan gaji.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-3 py-3 border text-dark">
            <div class="d-flex gap-2">
                <a href="index.php?page=hr_laporan_gaji" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" onclick='payroll_openSetupModal(<?= json_encode($conf) ?>)'><i class="fas fa-cog me-1"></i> UBAH SETTING</button>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2">
                <a href="hr_export_laporan.php?id=<?= $report_id ?>&mode=excel" target="_blank" class="btn btn-light border rounded-pill px-4 text-success fw-bold small shadow-sm"><i class="fas fa-file-excel me-2"></i>EXCEL</a>
                <a href="hr_export_laporan.php?id=<?= $report_id ?>&mode=print" target="_blank" class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase"><i class="fas fa-print me-2"></i>CETAK PDF</a>
            </div>
        </div>

        <div id="payrollExportArea">
            <?php if($mode == 'rekap_individu'): ?>
                <!-- MODE A: REKAP DETAIL PER PEGAWAI -->
                <?php 
                    $peg = $conn->query("SELECT * FROM hr_pegawai WHERE id = $target_peg_id")->fetch_assoc();
                    $total_p = 0; $total_m = 0; 
                    
                    $last_row = !empty($rows) ? end($rows) : null;
                    $st_up = $last_row ? strtoupper(trim($last_row['status_bayar'])) : '';
                    $has_jurnal = $last_row ? !empty($last_row['pembayaran_jurnal_id']) : false;
                    $is_paid = ($st_up == 'PAID' || $st_up == 'DIBAYAR' || $st_up == 'LUNAS' || $has_jurnal);
                ?>
                <div class="card card-rekap shadow-lg mb-5">
                    <div class="rekap-header text-center border-bottom">
                        <h2 class="fw-bold mb-1"><?= strtoupper($profile['institution_name']) ?></h2>
                        <h4 class="fw-bold text-primary text-decoration-underline mb-2">REKAPITULASI GAJI PEGAWAI</h4>
                        <p class="text-muted mb-4 small fw-bold">Periode: <?= date('d M Y', strtotime($conf['tgl_mulai'])) ?> s.d <?= date('d M Y', strtotime($conf['tgl_akhir'])) ?></p>
                        
                        <div class="row text-start mt-4 g-4">
                            <div class="col-md-4"><small class="text-muted fw-bold d-block uppercase">Nama Pegawai</small><h5 class="fw-bold text-dark"><?= strtoupper($peg['nama_lengkap']) ?></h5></div>
                            <div class="col-md-3"><small class="text-muted fw-bold d-block uppercase">NIP / ID</small><h5 class="fw-bold text-dark"><?= $peg['nip'] ?></h5></div>
                            <div class="col-md-3"><small class="text-muted fw-bold d-block uppercase">Jabatan / Unit</small><h5 class="fw-bold text-dark"><?= $peg['jabatan'] ?> (<?= $peg['unit_kerja'] ?>)</h5></div>
                            <div class="col-md-2 text-md-end"><small class="text-muted fw-bold d-block uppercase">Status Laporan</small><span class="badge <?= $is_paid?'bg-success':'bg-warning text-dark' ?> rounded-pill px-3 fw-bold"><?= $is_paid?'LUNAS / PAID':'PENDING' ?></span></div>
                        </div>
                    </div>
                    
                    <div class="card-body p-5">
                        <div class="row g-5">
                            <div class="col-md-6 border-end">
                                <h6 class="fw-bold text-success border-bottom pb-2 mb-3 uppercase"><i class="fas fa-hand-holding-usd me-2"></i>Pendapatan (Bruto)</h6>
                                <?php if(!empty($komponen_res)): foreach($komponen_res as $k): if($k['jenis'] == 'Pendapatan'): $total_p += $k['nominal']; ?>
                                    <div class="comp-item"><span><?= $k['nama_komponen'] ?></span><span class="fw-bold"><?= fmtGaji($k['nominal']) ?></span></div>
                                <?php endif; endforeach; endif; ?>
                                <div class="d-flex justify-content-between mt-3 p-3 bg-light rounded-3 fw-bold text-success"><span>TOTAL PENDAPATAN</span><span>Rp <?= fmtGaji($total_p) ?></span></div>
                            </div>
                            
                            <div class="col-md-6">
                                <h6 class="fw-bold text-danger border-bottom pb-2 mb-3 uppercase"><i class="fas fa-file-invoice-dollar me-2"></i>Potongan Wajib</h6>
                                <?php if(!empty($komponen_res)): foreach($komponen_res as $k): if($k['jenis'] == 'Potongan'): $total_m += $k['nominal']; ?>
                                    <div class="comp-item"><span><?= $k['nama_komponen'] ?></span><span class="text-danger fw-bold"><?= fmtGaji($k['nominal']) ?></span></div>
                                <?php endif; endforeach; endif; ?>
                                <div class="d-flex justify-content-between mt-3 p-3 bg-light rounded-3 fw-bold text-danger"><span>TOTAL POTONGAN</span><span>Rp <?= fmtGaji($total_m) ?></span></div>
                            </div>
                        </div>

                        <div class="total-box d-flex justify-content-between align-items-center shadow-lg animate__animated animate__pulse">
                            <div><h5 class="fw-bold mb-0 text-white">PENGHASILAN BERSIH (TAKE HOME PAY)</h5></div>
                            <h2 class="fw-bold mb-0 text-warning">Rp <?= fmtGaji($total_p - $total_m) ?></h2>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- MODE B: SUMMARY LIST -->
                <div class="card border-0 bg-white p-0 shadow-sm overflow-hidden rounded-4 text-dark mb-5">
                    <div class="p-5 text-center bg-light border-bottom">
                        <h2 class="fw-bold mb-1"><?= strtoupper($profile['institution_name']) ?></h2>
                        <h4 class="fw-bold text-primary mb-3 text-decoration-underline text-uppercase">LAPORAN REKAPITULASI GAJI PEGAWAI</h4>
                        <p class="text-muted mb-0 italic">Audit Periode: <?= date('d M Y', strtotime($conf['tgl_mulai'])) ?> s.d <?= date('d M Y', strtotime($conf['tgl_akhir'])) ?></p>
                    </div>
                    <div class="table-responsive">
                        <table class="table-payroll w-100" id="payrollTable">
                            <thead>
                                <tr>
                                    <th width="50">NO</th>
                                    <th width="110">PERIODE</th>
                                    <th width="110">NIP / ID</th>
                                    <!-- FIX: Mengatur Lebar Kolom Nama (30%) agar lebih proporsional -->
                                    <th class="text-start ps-4" width="30%">NAMA PEGAWAI / JABATAN</th>
                                    <th width="150" class="text-end">PENDAPATAN</th>
                                    <th width="150" class="text-end">POTONGAN</th>
                                    <th width="180" class="text-end pe-5">GAJI BERSIH (THP)</th>
                                    <th width="80" class="no-print">STATUS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no=1; $cur_u=''; $gt=['p'=>0, 'm'=>0, 'net'=>0];
                                foreach($rows as $r): 
                                    $st_up = strtoupper(trim($r['status_bayar']));
                                    $has_jurnal = !empty($r['pembayaran_jurnal_id']);
                                    $is_paid = ($st_up == 'PAID' || $st_up == 'DIBAYAR' || $st_up == 'LUNAS' || $has_jurnal);
                                    
                                    if($cur_u != $r['unit_kerja']) {
                                        $cur_u = $r['unit_kerja'];
                                        echo "<tr class='bg-group'><td colspan='8' class='ps-4'>UNIT KERJA: ".strtoupper($cur_u)."</td></tr>";
                                    }
                                    
                                    $bruto = $r['gapok'] + $r['tunjangan'];
                                    
                                    $gt['p'] += $bruto; 
                                    $gt['m'] += $r['potongan']; 
                                    $gt['net'] += $r['gaji_bersih']; 
                                ?>
                                <tr>
                                    <td class="text-center"><?= $no++ ?></td>
                                    <td class="text-center"><span class="badge bg-light text-dark border"><?= $nama_bulan[$r['periode_bulan']] ?> <?= $r['periode_tahun'] ?></span></td>
                                    <td class="text-center"><code><?= $r['nip'] ?></code></td>
                                    <td class="text-start ps-4 fw-bold"><?= strtoupper($r['nama_lengkap']) ?><br><small class="text-muted"><?= $r['jabatan'] ?></small></td>
                                    <td class="text-end text-gross"><?= fmtGaji($bruto) ?></td>
                                    <td class="text-end text-danger"><?= fmtGaji($r['potongan']) ?></td>
                                    <td class="text-end fw-bold text-dark pe-5">Rp <?= number_format($r['gaji_bersih'], 0, ',', '.') ?></td>
                                    <td class="text-center no-print">
                                        <?= $is_paid ? '<span class="badge bg-success rounded-pill px-3">PAID</span>' : '<span class="badge bg-warning text-dark rounded-pill px-3">PENDING</span>' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-dark text-white fw-bold">
                                <tr>
                                    <td colspan="4" class="ps-4 py-4 text-white align-middle">TOTAL REALISASI PENGGAJIAN INSTITUSI</td>
                                    <td class="text-end text-white align-middle"><?= number_format($gt['p']) ?></td>
                                    <td class="text-end text-white align-middle"><?= number_format($gt['m']) ?></td>
                                    <td class="text-end pe-5 text-warning align-middle">
                                        <div style="font-size: 10px; line-height: 1; opacity:0.8;">Rp</div>
                                        <div style="font-size: 14px; font-weight:800; line-height: 1.2;"><?= number_format($gt['net']) ?></div>
                                    </td>
                                    <td class="no-print"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL SETUP DUAL MODE -->
<div class="modal fade" id="modalPayrollSetup" tabindex="-1" data-bs-backdrop="static" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered">
        <form action="index.php?page=hr_laporan_gaji" method="POST" id="formPayrollSetup" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_gaji_local">
            <input type="hidden" name="jenis_laporan" value="gaji_pegawai">
            <input type="hidden" name="id" id="py_id">
            <div class="modal-header bg-primary text-white border-0 p-4"><h5 class="modal-title fw-bold text-white"><i class="fas fa-sliders-h me-2"></i>Konfigurasi Laporan Gaji</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label><input type="text" name="judul" id="py_judul" class="form-control rounded-pill border-0 shadow-sm px-4" required></div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="small fw-bold text-primary mb-1 uppercase">Mulai Tanggal</label><input type="date" name="start_date" id="py_start" class="form-control border-0 rounded-pill px-3 shadow-sm" required></div>
                    <div class="col-6"><label class="small fw-bold text-primary mb-1 uppercase">Hingga Tanggal</label><input type="date" name="end_date" id="py_end" class="form-control border-0 rounded-pill px-3 shadow-sm" required></div>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Metode Tampilan Laporan</label>
                    <select name="display_mode" id="py_mode" class="form-select border-0 rounded-pill px-3 shadow-sm fw-bold" onchange="togglePegSelection(this.value)">
                        <option value="summary">Laporan Gaji (Ringkasan Seluruh Pegawai)</option>
                        <option value="rekap_individu">Rekap Gaji Pegawai (Detil Komponen Per Pegawai)</option>
                    </select>
                </div>
                <div class="mb-3 d-none" id="divPegawaiSelection">
                    <label class="small fw-bold text-primary mb-1 uppercase">Pilih Pegawai (Searchable)</label>
                    <select name="pegawai_id" id="py_peg_id" class="form-select border-0 rounded-pill px-3 shadow-sm">
                        <option value="">-- Pilih Pegawai --</option>
                        <?php foreach($pegawai_all as $p) echo "<option value='{$p['id']}'>{$p['nama_lengkap']} ({$p['nip']})</option>"; ?>
                    </select>
                </div>
                <div class="mb-0" id="divUnitFilter">
                    <label class="small fw-bold text-muted mb-1 uppercase">Filter Unit (Opsional)</label>
                    <select name="unit" id="py_unit" class="form-select border-0 rounded-pill px-3 shadow-sm">
                        <option value="">Semua Unit</option>
                        <?php foreach($units as $u) echo "<option value='{$u['unit_kerja']}'>{$u['unit_kerja']}</option>"; ?>
                    </select>
                </div>
            </div>
            <input type="hidden" name="deskripsi" id="py_desc_hidden">
            <div class="modal-footer border-0 p-4 pt-0 bg-light text-center d-block">
                <button type="button" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase" onclick="submitPayrollConfig()">GENERASI LAPORAN GAJI</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePegSelection(val) {
    const divPeg = document.getElementById('divPegawaiSelection');
    const divUnit = document.getElementById('divUnitFilter');
    if(val === 'rekap_individu') { divPeg.classList.remove('d-none'); divUnit.classList.add('d-none'); } 
    else { divPeg.classList.add('d-none'); divUnit.classList.remove('d-none'); }
}

function submitPayrollConfig() {
    const mode = document.getElementById('py_mode').value;
    const unit = document.getElementById('py_unit').value;
    const peg_id = document.getElementById('py_peg_id').value;
    const params = { display_mode: mode, unit: unit, pegawai_id: peg_id };
    document.getElementById('py_desc_hidden').value = JSON.stringify(params);
    document.getElementById('formPayrollSetup').submit();
}

function payroll_openSetupModal(d = null) {
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPayrollSetup'));
    document.getElementById('py_id').value = d ? d.id : '';
    document.getElementById('py_judul').value = d ? d.judul_laporan : 'Laporan Gaji ' + new Date().getFullYear();
    document.getElementById('py_start').value = d ? d.tgl_mulai : '<?= date("Y-m-01") ?>';
    document.getElementById('py_end').value = d ? d.tgl_akhir : '<?= date("Y-m-d") ?>';
    if(d && d.deskripsi) {
        try {
            const p = JSON.parse(d.deskripsi);
            document.getElementById('py_mode').value = p.display_mode || 'summary';
            document.getElementById('py_unit').value = p.unit || '';
            document.getElementById('py_peg_id').value = p.pegawai_id || '';
            togglePegSelection(p.display_mode);
        } catch(e) {}
    } else { togglePegSelection('summary'); }
    m.show();
}

function payroll_deleteReportInternal(id) {
    if(confirm('Hapus riwayat laporan ini secara permanen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php?page=hr_laporan_gaji';
        form.innerHTML = `<input type="hidden" name="action" value="delete_report_internal"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>