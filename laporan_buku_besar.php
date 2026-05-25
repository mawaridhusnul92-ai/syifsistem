<?php
/**
 * laporan_buku_besar.php - PUSAT KENDALI BUKU BESAR DETAIL (ENTERPRISE CORE)
 * Versi: 14.1 (Sovereign Grand Master - Self-Contained Controller & UI Refined)
 * Perbaikan: 
 * 1. SELF-CONTAINED: Memutus ketergantungan dari financial_action.php. Proses simpan dan 
 * hapus laporan kini dieksekusi mandiri agar user tidak terlempar ke menu lain.
 * 2. ENUM BREAKER: Melonggarkan batas tabel jenis_laporan secara otomatis.
 * 3. UI FIX: Memindahkan tombol "Kembali" ke sisi kiri agar sejajar dan konsisten dengan modul lain.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$view = $_GET['view'] ?? 'hub'; 
$report_id = (int)($_GET['id'] ?? 0);
$source = $_GET['source'] ?? ''; 
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// ?? 1. LOCAL CRUD CONTROLLER (Mencegah Tendangan Keluar Menu)
// =========================================================================
if ($action == 'save_bb_local' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $uid = (int)($_SESSION['user_id'] ?? 1);
    
    // ??? ENUM BREAKER: Bebaskan kolom jenis_laporan dari jeratan ENUM yang kaku!
    @$conn->query("ALTER TABLE laporan_keuangan_setting MODIFY COLUMN jenis_laporan VARCHAR(100)");
    
    $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $judul   = trim($_POST['judul'] ?? 'Buku Besar');
    $start   = $_POST['start_date'] ?? date('Y-01-01');
    $akhir   = $_POST['end_date'] ?? date('Y-m-d');
    $akun    = $conn->real_escape_string($_POST['deskripsi'] ?? ''); // Kode akun disimpan di kolom deskripsi

    try {
        if ($id) {
            $stmt = $conn->prepare("UPDATE laporan_keuangan_setting SET judul_laporan=?, tgl_mulai=?, tgl_akhir=?, deskripsi=? WHERE id=?");
            $stmt->bind_param("ssssi", $judul, $start, $akhir, $akun, $id);
            $stmt->execute();
            $target_id = $id;
        } else {
            $stmt = $conn->prepare("INSERT INTO laporan_keuangan_setting (judul_laporan, jenis_laporan, tgl_mulai, tgl_akhir, deskripsi, created_by) VALUES (?, 'buku_besar', ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $judul, $start, $akhir, $akun, $uid);
            $stmt->execute();
            $target_id = $conn->insert_id;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Parameter Laporan Buku Besar berhasil disimpan dan dirender!'];
        header("Location: index.php?page=laporan_buku_besar&view=render&id=$target_id");
        exit;
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal Menyimpan ke Database: ' . $e->getMessage()];
        header("Location: index.php?page=laporan_buku_besar");
        exit;
    }
}

if ($action == 'delete_bb_local') {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM laporan_keuangan_setting WHERE id = $id");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Riwayat laporan berhasil dihapus secara permanen.'];
    header("Location: index.php?page=laporan_buku_besar");
    exit;
}

// =========================================================================
// ?? 2. RENDER LOGIC
// =========================================================================
$start_date  = $conn->real_escape_string($_GET['start'] ?? $_GET['tgl_awal'] ?? date('Y-01-01'));
$end_date    = $conn->real_escape_string($_GET['end'] ?? $_GET['tgl_akhir'] ?? date('Y-m-d'));
$target_akun = $conn->real_escape_string($_GET['deskripsi'] ?? $_GET['akun'] ?? '');

$acc_master  = ['nama_akun' => 'Akun Belum Dipilih', 'saldo_normal' => 'D', 'kode_akun' => ''];
$conf        = null;

if ($report_id > 0) {
    $res_conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id");
    if ($res_conf && $res_conf->num_rows > 0) {
        $conf = $res_conf->fetch_assoc();
        $start_date  = $conn->real_escape_string($conf['tgl_mulai']);
        $end_date    = $conn->real_escape_string($conf['tgl_akhir']);
        $target_akun = $conn->real_escape_string(trim($conf['deskripsi']));
    }
} elseif (!empty($target_akun)) {
    $view = 'render'; 
}

if (!empty($target_akun)) {
    $q_acc = $conn->query("SELECT * FROM syifa_akun WHERE kode_akun='$target_akun' LIMIT 1");
    if ($q_acc && $q_acc->num_rows > 0) { $acc_master = $q_acc->fetch_assoc(); }
}

$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$all_coa = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

/**
 * GET SALDO AWAL (EOM SNAPSHOT ANTI-DRIFT DENGAN VALIDITY FLAG)
 */
if (!function_exists('getSaldoAwal')) {
    function getSaldoAwal($kode, $date, $conn) {
        if(empty($kode)) return 0;
        
        $thn = (int)date('Y', strtotime($date));
        $bln = (int)date('m', strtotime($date));
        $prev_thn = $thn; $prev_bln = $bln - 1;
        if($prev_bln == 0) { $prev_bln = 12; $prev_thn--; }

        $q_acc = $conn->query("SELECT opening_balance, saldo_normal FROM syifa_akun WHERE kode_akun='$kode'");
        $acc = ($q_acc) ? $q_acc->fetch_assoc() : null;
        if (!$acc) return 0;

        // ??? REBUILD AWARENESS: Cek apakah ada Snapshot yang masih VALID
        $cek_snap = $conn->query("
            SELECT id 
            FROM syifa_saldo_akun_eom 
            WHERE tahun=$prev_thn 
            AND bulan=$prev_bln
            AND is_valid = 1
            LIMIT 1
        ");

        // Jika tidak ada atau basi, Auto Rebuild!
        if (!$cek_snap || $cek_snap->num_rows == 0) {
            if (function_exists('runEOMSnapshot')) runEOMSnapshot($conn, $prev_thn, $prev_bln);
        }

        // Tumpuk dengan saldo Snapshot (Sangat Cepat! O(1))
        $q_snap = $conn->query("SELECT saldo FROM syifa_saldo_akun_eom WHERE kode_akun='$kode' AND tahun=$prev_thn AND bulan=$prev_bln AND is_valid=1 LIMIT 1");
        $saldo_snap = ($q_snap && $q_snap->num_rows > 0) ? (double)$q_snap->fetch_assoc()['saldo'] : (double)$acc['opening_balance'];

        $start_of_month = "$thn-" . sprintf("%02d", $bln) . "-01 00:00:00";
        $cutoff = "$date 00:00:00";
        $net_mutasi = 0;

        if ($start_of_month < $cutoff) {
            $sql_prev = "SELECT SUM(jd.debit) as d, SUM(jd.kredit) as k 
                         FROM syifa_jurnal_detail jd 
                         JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                         WHERE jd.kode_akun = '$kode' 
                         AND j.tgl_jurnal >= '$start_of_month' 
                         AND j.tgl_jurnal < '$cutoff' 
                         AND j.is_deleted = 0"; 
            $mut = $conn->query($sql_prev)->fetch_assoc();
            $net_mutasi = ($acc['saldo_normal'] == 'D') ? (($mut['d']??0) - ($mut['k']??0)) : (($mut['k']??0) - ($mut['d']??0));
        }

        return $saldo_snap + $net_mutasi;
    }
}

$back_link = ($source == 'ringkasan') ? "index.php?page=ringkasan" : "index.php?page=laporan_keuangan&tab=transaksi";
$back_text = ($source == 'ringkasan') ? "Kembali ke Ringkasan" : "Kembali";
?>

<style>
    .search-results-list { position: absolute; width: 100%; max-height: 250px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; z-index: 2050; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.15); display: none; }
    .search-item { padding: 12px 15px; cursor: pointer; border-bottom: 1px solid #f8fafc; font-size: 13px; }
    .search-item:hover { background: #0d6efd; color: #fff; font-weight: 700; }
    .id-badge { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 6px; font-family: monospace; font-size: 11px; margin-right: 5px; border: 1px solid #e2e8f0; }
    .badge-system { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: bold; margin-left: 5px; }
    .num { text-align: right !important; }
    @media print { .no-print { display: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2 fa-lg"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if ($view == 'hub'): ?>
        <!-- ??? UI FIX: Tombol Kembali dipindah ke kiri, sejajar dengan judul -->
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan&tab=transaksi" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0 text-dark">Buku Besar Detail</h4>
                    <small class="text-muted small text-uppercase fw-bold">Audit Transaksi Berdasarkan Akun COA.</small>
                </div>
            </div>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</button>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm text-dark">
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 text-center"><thead class="table-dark small text-uppercase"><tr><th>Aksi</th><th>Periode</th><th>Akun Terpilih</th><th class="pe-4">Eksekusi</th></tr></thead><tbody>
                <?php 
                $sql_h = "SELECT s.*, a.nama_akun FROM laporan_keuangan_setting s LEFT JOIN syifa_akun a ON TRIM(s.deskripsi) = TRIM(a.kode_akun) WHERE s.jenis_laporan = 'buku_besar' ORDER BY s.created_at DESC";
                $history = $conn->query($sql_h);
                if($history && $history->num_rows > 0): while($row = $history->fetch_assoc()): ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white overflow-hidden shadow-sm">
                                <button class="btn btn-white text-warning border-end" 
                                        data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" 
                                        data-start="<?= $row['tgl_mulai'] ?>" data-end="<?= $row['tgl_akhir'] ?>" 
                                        data-akun="<?= $row['deskripsi'] ?>" data-nama="<?= htmlspecialchars($row['nama_akun'] ?? '', ENT_QUOTES) ?>"
                                        onclick='editSetup(this)' title="Ubah"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-white text-danger" onclick="deleteSetting(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button>
                            </div></td>
                        <td><span class="badge bg-light text-dark border px-3"><?= date('d/m/y', strtotime($row['tgl_mulai'])) ?> - <?= date('d/m/y', strtotime($row['tgl_akhir'])) ?></span></td>
                        <td class="fw-bold text-primary text-start ps-5"><?= $row['deskripsi'] ?> - <?= $row['nama_akun'] ?></td>
                        <td class="pe-4 text-center"><a href="index.php?page=laporan_buku_besar&view=render&id=<?= $row['id'] ?>" class="btn btn-primary rounded-pill px-4 btn-sm fw-bold shadow-sm">Tampilkan</a></td></tr>
                <?php endwhile; else: echo "<tr><td colspan='4' class='py-5 text-muted small italic'>Belum ada riwayat laporan buku besar.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $acc_master['kode_akun']): ?>
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-4 py-3 border text-dark">
            <div class="d-flex gap-2 align-items-center">
                <a href="<?= $back_link ?>" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-2"></i><?= $back_text ?></a>
                
                <?php if($conf): ?>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm text-dark text-uppercase small" 
                        data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" 
                        data-start="<?= $conf['tgl_mulai'] ?>" data-end="<?= $conf['tgl_akhir'] ?>" 
                        data-akun="<?= $conf['deskripsi'] ?>" data-nama="<?= htmlspecialchars($acc_master['nama_akun'], ENT_QUOTES) ?>"
                        onclick='editSetup(this)'><i class="fas fa-cog me-1"></i> Ubah Setting</button>
                <?php endif; ?>
            </div>

            <h5 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= $acc_master['nama_akun'] ?> <small class="text-muted">(<?= $target_akun ?>)</small></h5>

            <div class="d-flex gap-2">
                <button class="btn btn-light border rounded-pill px-4 fw-bold shadow-sm small text-success text-uppercase" onclick="exportExcelServerSide('<?= $target_akun ?>', '<?= $start_date ?>', '<?= $end_date ?>')"><i class="fas fa-file-excel me-2"></i>EXCEL</button>
                
                <?php if ($report_id > 0): ?>
                <a href="print_buku_besar.php?id=<?= $report_id ?>" target="_blank" class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase small"><i class="fas fa-print me-2"></i>Cetak</a>
                <?php else: ?>
                <a href="print_buku_besar.php?akun=<?= $target_akun ?>&start=<?= $start_date ?>&end=<?= $end_date ?>" target="_blank" class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase small"><i class="fas fa-print me-2"></i>Cetak</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-lg rounded-4 overflow-hidden bg-white mb-5 text-dark">
            <div class="p-5 text-center bg-light border-bottom no-print">
                <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                <h4 class="fw-bold text-primary mb-3 text-decoration-underline">KARTU BUKU BESAR DETAIL</h4>
                <p class="text-muted mb-0 italic" id="reportPeriodText">Periode Laporan: <?= date('d M Y', strtotime($start_date)) ?> s.d <?= date('d M Y', strtotime($end_date)) ?></p>
            </div>
            
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="ledgerTable">
                    <thead class="table-primary small text-uppercase text-center">
                        <tr>
                            <th class="ps-4 py-3" width="100">Tanggal</th>
                            <th class="py-3" width="150">No. Bukti</th>
                            <th class="text-start py-3">Uraian Transaksi / Pihak Terkait</th>
                            <th class="text-end py-3" width="150">Debit (+)</th>
                            <th class="text-end py-3" width="150">Kredit (-)</th>
                            <th class="text-end pe-4 py-3" width="180">Saldo Berjalan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sa = getSaldoAwal($target_akun, $start_date, $conn); $cur_bal = $sa;
                        echo '<tr style="background-color: #f8fafc !important; font-weight: 700; color: #0369a1; border-top: 2px solid #0d6efd !important;"><td colspan="5" class="ps-4 py-3 text-uppercase">Saldo Awal per '.date('d/m/Y', strtotime($start_date)).'</td><td class="text-end pe-4 fw-bold num">Rp '.number_format($sa, 0, ',', '.').'</td></tr>';
                        
                        $sql_m = "SELECT j.tgl_jurnal, j.no_jurnal, j.keterangan as ket_header, jd.debit, jd.kredit, jd.id as jid, 
                                  m.nama as nama_mhs, m.nim, 
                                  p.nama_lengkap as nama_pegawai, p.nip,
                                  ast.asset_name as nama_aset, ast.asset_code
                                  FROM syifa_jurnal_detail jd 
                                  JOIN syifa_jurnal j ON jd.jurnal_id = j.id
                                  LEFT JOIN syifa_mahasiswa m ON jd.mahasiswa_id = m.id 
                                  LEFT JOIN hr_pegawai p ON jd.pegawai_id = p.id
                                  LEFT JOIN assets ast ON jd.aset_id = ast.id
                                  WHERE jd.kode_akun = '$target_akun' 
                                  AND j.tgl_jurnal >= '$start_date 00:00:00' AND j.tgl_jurnal <= '$end_date 23:59:59' 
                                  AND j.is_deleted = 0 
                                  ORDER BY j.tgl_jurnal ASC, jd.id ASC";
                        
                        $res = $conn->query($sql_m);
                        if ($res && $res->num_rows > 0): while($r = $res->fetch_assoc()):
                            $diff = $r['debit'] - $r['kredit'];
                            if($acc_master['saldo_normal'] == 'K') $diff = -$diff;
                            $cur_bal += $diff;
                            
                            if (!empty($r['nama_mhs'])) { $pihak = "<span class='id-badge'>{$r['nim']}</span> ".strtoupper($r['nama_mhs']); } 
                            elseif (!empty($r['nama_pegawai'])) { $pihak = "<span class='id-badge'>{$r['nip']}</span> ".strtoupper($r['nama_pegawai']); } 
                            elseif (!empty($r['nama_aset'])) { $pihak = "<span class='id-badge bg-warning text-dark border-warning'>ASET</span> ".strtoupper($r['nama_aset']); } 
                            else { $pihak = 'UMUM'; }

                            $system_badge = "";
                            if (strpos($r['no_jurnal'], 'DEP') === 0 || strpos(strtolower($r['ket_header']), 'penyusutan') !== false) { $system_badge = "<span class='badge-system'><i class='fas fa-cogs'></i> NON-CASH (PENYUSUTAN)</span>"; } 
                            elseif (strpos($r['no_jurnal'], 'CAP') === 0) { $system_badge = "<span class='badge-system'><i class='fas fa-wrench'></i> KAPITALISASI</span>"; } 
                            elseif (strpos(strtolower($r['ket_header']), 'reklasifikasi') !== false) { $system_badge = "<span class='badge-system'><i class='fas fa-exchange-alt'></i> REKLASIFIKASI</span>"; }
                        ?>
                            <tr><td class="ps-4 text-muted small text-center"><?= date('d/m/y', strtotime($r['tgl_jurnal'])) ?></td>
                                <td class="fw-bold text-center"><code><?= $r['no_jurnal'] ?></code></td>
                                <td><div class="fw-bold text-dark" style="font-size:0.8rem;"><?= $pihak ?></div><div class="text-muted"><?= $r['ket_header'] ?> <?= $system_badge ?></div></td>
                                <td class="text-end fw-bold text-success num"><?= $r['debit']>0?number_format($r['debit'], 0, ',', '.'):'-' ?></td>
                                <td class="text-end fw-bold text-danger num"><?= $r['kredit']>0?number_format($r['kredit'], 0, ',', '.'):'-' ?></td>
                                <td class="text-end pe-4 fw-bold text-primary num fs-6"><?= number_format($cur_bal, 0, ',', '.') ?></td></tr>
                        <?php endwhile; else: echo "<tr><td colspan='6' class='py-5 text-center text-muted italic'>Tidak ada mutasi dalam periode audit ini.</td></tr>"; endif; ?>
                        <tr class="table-dark text-white fw-bold"><td colspan="5" class="ps-4 py-4 uppercase">Saldo Akhir Kumulatif per <?= date('d/m/Y', strtotime($end_date)) ?></td><td class="text-end pe-4 fs-5 num">Rp <?= number_format($cur_bal, 0, ',', '.') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL SETUP DENGAN LOCAL CONTROLLER ACTION -->
<div class="modal fade" id="modalSetup" tabindex="-1" data-bs-backdrop="static" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered">
        <form action="index.php?page=laporan_buku_besar" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="action" value="save_bb_local">
            <input type="hidden" name="id" id="setup_id">
            <div class="modal-header bg-primary text-white border-0 p-4"><h5 class="modal-title fw-bold text-white">Konfigurasi Buku Besar</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label><input type="text" name="judul" id="setup_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div>
                <div class="mb-3 position-relative"><label class="small fw-bold text-primary mb-1 uppercase">Pilih Akun (Audit Target)</label><input type="text" id="coaSearchInput" class="form-control border-0 shadow-sm px-4 py-2 rounded-pill" placeholder="Ketik kode atau nama akun..." autocomplete="off"><input type="hidden" name="deskripsi" id="setup_akun_val" required><div id="coaResults" class="search-results-list mt-1"></div><div class="badge bg-white text-primary border mt-2 w-100 p-2 text-start rounded-3 shadow-sm small" id="selectedAccLabel">Belum ada akun terpilih.</div></div>
                <div class="row g-2"><div class="col-6"><label class="small fw-bold text-muted mb-1 uppercase">Dari Tanggal</label><input type="date" name="start_date" id="setup_start" class="form-control border-0 shadow-sm rounded-pill px-3" required></div><div class="col-6"><label class="small fw-bold text-muted mb-1 uppercase">Sampai Tanggal</label><input type="date" name="end_date" id="setup_end" class="form-control border-0 shadow-sm rounded-pill px-3" required></div></div>
            </div>
            <div class="modal-footer border-0 p-4 pt-0 bg-light text-center d-block"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase">Hasilkan Buku Besar</button></div>
        </form>
    </div>
</div>

<script>
const coaData = <?= json_encode($all_coa) ?>;
const coaInput = document.getElementById('coaSearchInput');
const coaResults = document.getElementById('coaResults');
const coaHidden = document.getElementById('setup_akun_val');
const coaLabel = document.getElementById('selectedAccLabel');

if(coaInput) {
    const showAll = () => { coaResults.innerHTML = ''; coaData.forEach(item => createItem(item)); coaResults.style.display = 'block'; coaResults.style.width = coaInput.offsetWidth + 'px'; };
    const filterData = (val) => { coaResults.innerHTML = ''; const filtered = coaData.filter(i => i.nama_akun.toLowerCase().includes(val.toLowerCase()) || i.kode_akun.includes(val)); if(filtered.length>0){ coaResults.style.display='block'; filtered.forEach(i=>createItem(i)); } else { coaResults.innerHTML='<div class="p-3 text-center small">Tidak ditemukan</div>'; } };
    const createItem = (item) => { const div = document.createElement('div'); div.className = 'search-item'; div.innerHTML = `<code>${item.kode_akun}</code> ${item.nama_akun}`; div.onclick = () => { selectAccount(item.kode_akun, item.nama_akun); }; coaResults.appendChild(div); };
    coaInput.addEventListener('click', showAll); 
    coaInput.addEventListener('input', function() { if(this.value.length > 0) filterData(this.value); else showAll(); });
    document.addEventListener('click', (e) => { if(coaResults && !coaResults.contains(e.target) && e.target !== coaInput) coaResults.style.display = 'none'; });
}

function selectAccount(kode, nama) { coaHidden.value = kode; coaInput.value = nama; coaLabel.innerHTML = `Terpilih: <b class='text-primary'>${kode} - ${nama}</b>`; coaResults.style.display = 'none'; }
function openSetupModal() { const mEl = document.getElementById('modalSetup'); const m = bootstrap.Modal.getOrCreateInstance(mEl); document.getElementById('setup_id').value = ''; document.getElementById('setup_judul').value = 'Buku Besar ' + new Date().getFullYear(); document.getElementById('setup_start').value = '<?= date("Y-01-01") ?>'; document.getElementById('setup_end').value = '<?= date("Y-m-d") ?>'; coaHidden.value = ''; coaInput.value = ''; coaLabel.innerHTML = 'Belum ada akun terpilih.'; m.show(); }
function editSetup(el) { const mEl = document.getElementById('modalSetup'); const m = bootstrap.Modal.getOrCreateInstance(mEl); const d = el.dataset; document.getElementById('setup_id').value = d.id; document.getElementById('setup_judul').value = d.judul; document.getElementById('setup_start').value = d.start; document.getElementById('setup_end').value = d.end; selectAccount(d.akun, d.nama); m.show(); }
function deleteSetting(id) { if(confirm('Hapus riwayat laporan ini secara permanen?')) window.location.href = `index.php?page=laporan_buku_besar&action=delete_bb_local&id=${id}`; }
function exportExcelServerSide(kode_akun, start_date, end_date) { const url = `print_buku_besar.php?akun=${kode_akun}&start=${start_date}&end=${end_date}&mode=excel`; window.open(url, '_blank'); }
</script>