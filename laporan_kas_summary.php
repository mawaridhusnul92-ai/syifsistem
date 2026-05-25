<?php
/**
 * laporan_kas_summary.php - RINGKASAN PENERIMAAN DAN PEMBAYARAN (ISAK 35)
 * Versi: 55.1 (Sovereign Grand Master - Self-Contained Controller Edition)
 * Perbaikan: 
 * 1. SELF-CONTAINED: Memutus ketergantungan dari financial_action.php agar user tidak terlempar keluar.
 * 2. ENUM BREAKER: Melonggarkan batas tabel jenis_laporan secara otomatis.
 * 3. UI FIX: Merapikan tombol "Kembali" ke sisi kiri sejajar dengan judul di semua view.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$view = $_GET['view'] ?? 'hub';
$report_id = (int)($_GET['id'] ?? ($_GET['report_id'] ?? 0));
$drill_akun = $_GET['drill_akun'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// ?? 1. LOCAL CRUD CONTROLLER (Mencegah Tendangan Keluar Menu)
// =========================================================================
if ($action == 'save_summary_local' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $uid = (int)($_SESSION['user_id'] ?? 1);
    
    // ??? ENUM BREAKER: Bebaskan kolom jenis_laporan dari jeratan ENUM yang kaku!
    @$conn->query("ALTER TABLE laporan_keuangan_setting MODIFY COLUMN jenis_laporan VARCHAR(100)");
    
    $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $judul   = trim($_POST['judul'] ?? 'Ringkasan Kas');
    $start   = $_POST['start_date'] ?? date('Y-01-01');
    $akhir   = $_POST['end_date'] ?? date('Y-m-d');
    
    // Proses Kolom Komparatif
    $comp_dates = [];
    if (!empty($_POST['comp_end'])) {
        $comp_starts = $_POST['comp_start'] ?? [];
        foreach ($_POST['comp_end'] as $idx => $e_date) {
            if (!empty($e_date)) {
                $s_date = (!empty($comp_starts[$idx])) ? $comp_starts[$idx] : date('Y-01-01', strtotime($e_date));
                $comp_dates[] = ['s' => $s_date, 'e' => $e_date];
            }
        }
    }
    $json_comp = empty($comp_dates) ? NULL : json_encode($comp_dates);

    try {
        if ($id) {
            $stmt = $conn->prepare("UPDATE laporan_keuangan_setting SET judul_laporan=?, tgl_mulai=?, tgl_akhir=?, comp_dates=? WHERE id=?");
            $stmt->bind_param("ssssi", $judul, $start, $akhir, $json_comp, $id);
            $stmt->execute();
            $target_id = $id;
        } else {
            $stmt = $conn->prepare("INSERT INTO laporan_keuangan_setting (judul_laporan, jenis_laporan, tgl_mulai, tgl_akhir, comp_dates, created_by) VALUES (?, 'kas_summary', ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $judul, $start, $akhir, $json_comp, $uid);
            $stmt->execute();
            $target_id = $conn->insert_id;
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Format laporan berhasil disimpan dan dirender!'];
        header("Location: index.php?page=laporan_kas_summary&view=render&id=$target_id");
        exit;
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal Menyimpan ke Database: ' . $e->getMessage()];
        header("Location: index.php?page=laporan_kas_summary");
        exit;
    }
}

if ($action == 'delete_summary_local') {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM laporan_keuangan_setting WHERE id = $id");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Riwayat laporan berhasil dihapus secara permanen.'];
    header("Location: index.php?page=laporan_kas_summary");
    exit;
}

// --- 1. SYNC DATA MASTER & HISTORY ---
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
// ??? BROAD HISTORY RADAR: Tangkap laporan dengan tag kas_summary
$history = $conn->query("SELECT s.*, u.nama_lengkap as creator FROM laporan_keuangan_setting s LEFT JOIN users u ON s.created_by = u.id WHERE s.jenis_laporan IN ('kas_summary', 'laporan_kas_summary') ORDER BY s.created_at DESC");

// --- 2. DATA PARSING (MULTI-PERIOD COMPARATIVE) ---
$periods = []; $conf = null;
if ($report_id > 0) {
    $res_conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id");
    if ($res_conf && $res_conf->num_rows > 0) {
        $conf = $res_conf->fetch_assoc();
        $periods[] = ['s' => $conf['tgl_mulai'], 'e' => $conf['tgl_akhir'], 'label' => date('d M Y', strtotime($conf['tgl_akhir']))];
        $comp_json = !empty($conf['comp_dates']) ? json_decode($conf['comp_dates'], true) : [];
        if(is_array($comp_json)) { 
            foreach($comp_json as $cj) { 
                $d_start = is_array($cj) ? ($cj['s'] ?? '') : '';
                $d_end = is_array($cj) ? ($cj['e'] ?? $cj['s'] ?? '') : $cj;
                if(!empty($d_end)) {
                    $periods[] = [
                        's' => (!empty($d_start)) ? $d_start : date('Y-01-01', strtotime($d_end)), 
                        'e' => $d_end, 
                        'label' => date('d M Y', strtotime($d_end))
                    ]; 
                }
            } 
        }
    }
}

// --- 3. CORE ENGINE (SINKRONISASI SALDO) ---
if (!function_exists('getAccountCashActivity')) {
    function getAccountCashActivity($kode, $s_date, $e_date, $conn, $type = 'IN') {
        $cond = ($type == 'IN') ? "jd_kas.debit > 0" : "jd_kas.kredit > 0";
        $val_field = ($type == 'IN') ? "jd_target.kredit" : "jd_target.debit";
        $sql = "SELECT SUM($val_field) as total FROM syifa_jurnal j
                JOIN syifa_jurnal_detail jd_kas ON j.id = jd_kas.jurnal_id
                JOIN syifa_jurnal_detail jd_target ON j.id = jd_target.jurnal_id
                WHERE (jd_kas.kode_akun LIKE '1-11%' OR jd_kas.kode_akun LIKE '1.11%')
                AND $cond AND jd_target.kode_akun = '$kode'
                AND j.tgl_jurnal BETWEEN '$s_date' AND '$e_date'";
        $res = $conn->query($sql)->fetch_assoc();
        return (double)($res['total'] ?? 0);
    }
}

if (!function_exists('getInitialCashBalance')) {
    function getInitialCashBalance($s_date, $conn) {
        $q_coa = $conn->query("SELECT SUM(opening_balance) as ob FROM syifa_akun WHERE (kode_akun LIKE '1-11%' OR kode_akun LIKE '1.11%') AND is_group=0")->fetch_assoc();
        $sql_mut = "SELECT SUM(debit - kredit) as bal FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE (jd.kode_akun LIKE '1-11%' OR jd.kode_akun LIKE '1.11%') AND j.tgl_jurnal < '$s_date'";
        return (double)($q_coa['ob'] ?? 0) + (double)($conn->query($sql_mut)->fetch_assoc()['bal'] ?? 0);
    }
}

function fmtAudSummary($num, $isBold = false) {
    if ($num == 0) return "-";
    $formatted = number_format(abs($num), 0, ',', '.');
    if ($num < 0) $formatted = "($formatted)";
    $fontWeight = $isBold ? "900" : "normal";
    // Menghilangkan Flexbox di dalam TD untuk mencegah Excel Crash
    return "<span style='float: left; width: 25px;'>Rp</span><span style='float: right; font-weight: $fontWeight;'>$formatted</span><div style='clear:both;'></div>";
}
?>

<style>
    .table-summary { border: none; border-collapse: collapse; width: 100%; table-layout: fixed; }
    .table-summary thead th { background: #1e293b; color: #fff; font-size: 10px; text-transform: uppercase; padding: 15px 12px; border: 1px solid #334155; text-align: center; }
    .table-summary tbody td { font-size: 13.5px; border-bottom: 1px solid #f1f5f9; padding: 12px 15px; vertical-align: middle; }
    .row-group-header { background: #f8fafc; font-weight: 800; color: #1e293b; border-bottom: 1px solid #cbd5e1 !important; text-transform: uppercase; border-left: 5px solid #0d6efd; }
    .row-subtotal { font-weight: 800; border-top: 1.5px solid #1e293b; background: rgba(0,0,0,0.02); }
    .row-total-final { background: #1e293b !important; color: #fff !important; font-weight: 900; }
    .drill-link { color: #0d6efd; font-weight: 700; cursor: pointer; text-decoration: none; border-bottom: 1px dashed transparent; }
    .drill-link:hover { text-decoration: underline; background: rgba(13, 110, 253, 0.05); }
    .id-badge { background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 6px; font-family: monospace; font-size: 11px; margin-right: 5px; border: 1px solid #e2e8f0; }
    .type-badge { font-size: 9px; padding: 2px 8px; border-radius: 12px; font-weight: 800; text-transform: uppercase; margin-left: 5px; }
    .badge-payment { background: #fee2e2; color: #991b1b; }
    .badge-receipt { background: #dcfce7; color: #166534; }
    .btn-oval { border-radius: 50px !important; padding-left: 20px !important; padding-right: 20px !important; font-weight: 700; text-transform: uppercase; font-size: 11px; }
    @media print { .no-print { display: none !important; } }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">

    <?php if ($view == 'hub'): ?>
        <!-- VIEW RIWAYAT -->
        <!-- ??? UI FIX: Tombol Kembali dipindah ke kiri, sejajar dengan judul -->
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 no-print text-dark text-center">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_keuangan&tab=transaksi" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0">Ringkasan Penerimaan dan Pembayaran</h4>
                    <small class="text-muted small uppercase fw-bold">Executive Audit Hub v55.1</small>
                </div>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow text-uppercase" onclick="summary_openSetupModal()"><i class="fas fa-plus-circle me-2"></i>Buat Laporan</button>
        </div>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0 text-center"><thead class="table-dark small text-uppercase"><tr><th width="120">Aksi</th><th>Periode Laporan</th><th class="text-start ps-5">Judul Laporan</th><th class="pe-4" width="160">Eksekusi</th></tr></thead><tbody>
                <?php if($history && $history->num_rows > 0): while ($row = $history->fetch_assoc()) { ?>
                    <tr><td><div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden"><button class="btn btn-white text-warning border-end" data-id="<?= $row['id'] ?>" data-judul="<?= htmlspecialchars($row['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $row['tgl_mulai'] ?>" data-end="<?= $row['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($row['comp_dates'], ENT_QUOTES) ?>' onclick='summary_editSetup(this)' title="Ubah"><i class="fas fa-edit"></i></button><button class="btn btn-white text-danger" onclick="summary_deleteReport(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button></div></td>
                        <td><span class="badge bg-light text-dark border px-3 fw-bold"><?= date('d/m/y', strtotime($row['tgl_mulai'])) ?> - <?= date('d/m/y', strtotime($row['tgl_akhir'])) ?></span></td>
                        <td class="text-start ps-5 fw-bold text-primary"><?= $row['judul_laporan'] ?></td>
                        <td class="pe-4 text-center"><a href="index.php?page=laporan_kas_summary&view=render&id=<?= $row['id'] ?>" class="btn btn-primary btn-oval shadow-sm px-4">Tampilkan</a></td></tr>
                <?php } else: echo "<tr><td colspan='4' class='py-5 text-muted italic'>Belum ada riwayat laporan.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>

    <?php elseif ($view == 'render' && $conf): ?>
        <!-- VIEW LAPORAN -->
        <div class="no-print d-flex justify-content-between align-items-center shadow-sm rounded-4 mb-4 bg-white px-3 py-3 border text-dark">
            <div class="d-flex gap-2 align-items-center">
                <a href="index.php?page=laporan_kas_summary" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <button class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm small text-dark" data-id="<?= $conf['id'] ?>" data-judul="<?= htmlspecialchars($conf['judul_laporan'], ENT_QUOTES) ?>" data-start="<?= $conf['tgl_mulai'] ?>" data-end="<?= $conf['tgl_akhir'] ?>" data-comp='<?= htmlspecialchars($conf['comp_dates'], ENT_QUOTES) ?>' onclick='summary_editSetup(this)'><i class="fas fa-cog me-1"></i> UBAH SETTING</button>
            </div>
            <h6 class="fw-bold mb-0 text-dark text-center" id="reportTitleHeader"><?= strtoupper($conf['judul_laporan']) ?></h6>
            <div class="d-flex gap-2">
                <button class="btn btn-light border rounded-pill px-4 text-success fw-bold small shadow-sm" onclick="exportToExcelStability('summaryTable', 'Lap_Summary_Kas')"><i class="fas fa-file-excel me-2"></i>EXCEL</button>
                <a href="print_kas_summary.php?id=<?= $report_id ?>" target="_blank" class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase"><i class="fas fa-print me-2"></i>CETAK PDF</a>
            </div>
        </div>

        <div class="card border-0 bg-white p-0 shadow-sm overflow-hidden rounded-4 text-dark">
            <div class="p-5 text-center bg-light border-bottom">
                <h2 class="fw-bold mb-1 text-dark"><?= strtoupper($profile['institution_name'] ?? 'STIKes YARSI PONTIANAK') ?></h2>
                <h4 class="fw-bold text-primary mb-3 text-decoration-underline text-uppercase">Ringkasan Penerimaan dan Pembayaran</h4>
                <p class="text-muted mb-0 italic" id="reportPeriodText">Per Tanggal <?= date('d F Y', strtotime($conf['tgl_akhir'])) ?></p>
            </div>
            <div class="table-responsive"><table class="table-summary" id="summaryTable">
                <colgroup><col style="width: auto;"><?php foreach($periods as $p) echo '<col style="width: 220px;">'; ?></colgroup>
                <thead><tr><th class="ps-5 text-start">URAIAN TRANSAKSI KAS</th><?php foreach($periods as $p) echo "<th class='text-end pe-4'>PER ".strtoupper($p['label'])."</th>"; ?></tr></thead>
                <tbody>
                    <?php 
                    $accounts = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY nama_akun ASC")->fetch_all(MYSQLI_ASSOC);
                    $total_ins = array_fill(0, count($periods), 0); $total_outs = array_fill(0, count($periods), 0);
                    ?>

                    <tr class="row-group-header"><td class="ps-4" colspan="<?= count($periods)+1 ?>">PENERIMAAN KAS</td></tr>
                    <?php foreach($accounts as $acc): 
                        $row_vals = []; $has_activity = false;
                        foreach($periods as $idx => $p) {
                            $v = getAccountCashActivity($acc['kode_akun'], $p['s'], $p['e'], $conn, 'IN');
                            $row_vals[] = $v; $total_ins[$idx] += $v;
                            if($v != 0) $has_activity = true;
                        }
                        if(!$has_activity) continue;
                    ?>
                        <tr><td class="ps-5"><?= $acc['nama_akun'] ?></td>
                            <?php foreach($row_vals as $idx => $v): ?>
                                <td class="text-end pe-4"><a href="index.php?page=laporan_kas_summary&view=drill&report_id=<?= $report_id ?>&drill_akun=<?= $acc['kode_akun'] ?>&type=receipt&s=<?= $periods[$idx]['s'] ?>&e=<?= $periods[$idx]['e'] ?>" class="drill-link text-dark"><?= fmtAudSummary($v) ?></a></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="row-subtotal"><td class="ps-4">TOTAL PENERIMAAN</td><?php foreach($total_ins as $ti) echo "<td class='text-end pe-4'>".fmtAudSummary($ti, true)."</td>"; ?></tr>

                    <tr style="height:15px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                    <tr class="row-group-header"><td class="ps-4" colspan="<?= count($periods)+1 ?>">PEMBAYARAN KAS</td></tr>
                    <?php foreach($accounts as $acc): 
                        $row_vals = []; $has_activity = false;
                        foreach($periods as $idx => $p) {
                            $v = getAccountCashActivity($acc['kode_akun'], $p['s'], $p['e'], $conn, 'OUT');
                            $row_vals[] = $v; $total_outs[$idx] += $v;
                            if($v != 0) $has_activity = true;
                        }
                        if(!$has_activity) continue;
                    ?>
                        <tr><td class="ps-5"><?= $acc['nama_akun'] ?></td>
                            <?php foreach($row_vals as $idx => $v): ?>
                                <td class="text-end pe-4"><a href="index.php?page=laporan_kas_summary&view=drill&report_id=<?= $report_id ?>&drill_akun=<?= $acc['kode_akun'] ?>&type=disbursement&s=<?= $periods[$idx]['s'] ?>&e=<?= $periods[$idx]['e'] ?>" class="drill-link text-dark"><div class="text-danger"><?= fmtAudSummary($v) ?></div></a></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="row-subtotal"><td class="ps-4">TOTAL PEMBAYARAN</td><?php foreach($total_outs as $to) echo "<td class='text-end pe-4'>".fmtAudSummary($to, true)."</td>"; ?></tr>

                    <tr style="height:30px;"><td colspan="<?= count($periods)+1 ?>"></td></tr>
                    <tr class="row-group-header"><td class="ps-4" colspan="<?= count($periods)+1 ?>">PERHITUNGAN SALDO AKHIR</td></tr>
                    <tr class="fw-bold"><td class="ps-5">Kenaikan (Penurunan) Kas Bersih Berjalan</td><?php foreach($periods as $idx => $p) echo "<td class='text-end pe-4'>".fmtAudSummary($total_ins[$idx] - $total_outs[$idx])."</td>"; ?></tr>
                    <tr><td class="ps-5">Saldo Kas pada Awal Periode (COA Sync)</td><?php foreach($periods as $idx => $p){ $sa = getInitialCashBalance($p['s'], $conn); echo "<td class='text-end pe-4'>".fmtAudSummary($sa)."</td>"; } ?></tr>
                    <tr class="row-total-final"><td class="ps-4 py-3 text-white fw-bold">SALDO KAS PADA AKHIR PERIODE</td><?php foreach($periods as $idx => $p){ 
                        $sa = getInitialCashBalance($p['s'], $conn);
                        $ak = $sa + ($total_ins[$idx] - $total_outs[$idx]);
                        echo "<td class='text-end pe-4 fs-5 text-white fw-bold'>Rp ".number_format($ak, 0, ',', '.')."</td>"; 
                    } ?></tr>
                </tbody>
            </table></div>
        </div>

    <?php elseif ($view == 'drill'): ?>
        <!-- VIEW DRILLDOWN: UNIVERSAL BUKTI TRANSAKSI -->
        <?php 
            $s = $_GET['s']; $e = $_GET['e']; $type = $_GET['type'];
            $acc_info = $conn->query("SELECT * FROM syifa_akun WHERE kode_akun = '$drill_akun'")->fetch_assoc();
            $cond = ($type == 'receipt') ? "jd_kas.debit > 0" : "jd_kas.kredit > 0";
            
            // Query cerdas untuk menarik rincian individu dan link kuitansi/slip
            $sql_drill = "SELECT j.*, a1.nama_akun as nama_akun_utama, jd_target.debit as d_val, jd_target.kredit as k_val,
                          p.nama_lengkap as nama_pegawai, p.nip as nip_pegawai,
                          m.nama as nama_mhs, m.nim as nim_mhs,
                          (SELECT id FROM hr_payroll_detail WHERE link_jurnal_id = j.id OR link_jurnal_id IN (SELECT id FROM syifa_jurnal WHERE no_jurnal = j.no_jurnal) LIMIT 1) as payroll_detail_id,
                          (SELECT id FROM hr_payroll_detail WHERE pegawai_id = p.id AND payroll_id IN (SELECT id FROM hr_payroll_header WHERE pembayaran_jurnal_id = j.id)) as payment_slip_id
                          FROM syifa_jurnal j 
                          JOIN syifa_jurnal_detail jd_kas ON j.id = jd_kas.jurnal_id
                          JOIN syifa_jurnal_detail jd_target ON j.id = jd_target.jurnal_id
                          LEFT JOIN syifa_akun a1 ON j.akun_utama_kode = a1.kode_akun
                          LEFT JOIN hr_pegawai p ON jd_target.pegawai_id = p.id
                          LEFT JOIN syifa_mahasiswa m ON jd_target.mahasiswa_id = m.id
                          WHERE jd_target.kode_akun = '$drill_akun' 
                          AND (jd_kas.kode_akun LIKE '1-11%' OR jd_kas.kode_akun LIKE '1.11%')
                          AND $cond AND j.tgl_jurnal BETWEEN '$s' AND '$e'
                          ORDER BY j.tgl_jurnal ASC, j.id ASC";
            $drill_res = $conn->query($sql_drill);
        ?>
        <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-info border-4 no-print text-dark">
            <div class="d-flex align-items-center gap-3 text-start">
                <a href="index.php?page=laporan_kas_summary&view=render&id=<?= $report_id ?>&tab=transaksi" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
                <div>
                    <h4 class="fw-bold mb-0">Rincian Transaksi Detil: <?= $acc_info['nama_akun'] ?></h4>
                    <small class="text-muted uppercase fw-bold">Audit Bukti Transaksi Sah</small>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark">
            <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-dark small text-uppercase"><tr><th width="100">Tanggal</th><th width="120">No. Jurnal</th><th>Sub-Ledger / Pihak</th><th>Keterangan / Memo</th><th class="text-end pe-4">Nominal</th><th width="120" class="text-center">Voucher Bukti</th></tr></thead><tbody>
                <?php if($drill_res && $drill_res->num_rows > 0): while($r = $drill_res->fetch_assoc()): 
                    $val = ($type == 'receipt') ? $r['k_val'] : $r['d_val'];
                    
                    // FALLBACK LOGIC: Jika pembayaran gaji kolektif, pecah rinciannya
                    $is_payroll_payment = (strpos($r['keterangan'], 'Gaji') !== false);
                    if($is_payroll_payment && empty($r['nama_pegawai'])) {
                        $sub_res = $conn->query("SELECT d.*, p.nama_lengkap, p.nip FROM hr_payroll_detail d JOIN hr_pegawai p ON d.pegawai_id = p.id WHERE d.payroll_id IN (SELECT id FROM hr_payroll_header WHERE pembayaran_jurnal_id = {$r['id']})");
                        if($sub_res && $sub_res->num_rows > 0) {
                            while($sub = $sub_res->fetch_assoc()) { ?>
                                <tr><td class="text-muted"><?= date('d/m/y', strtotime($r['tgl_jurnal'])) ?></td>
                                    <td class="fw-bold"><code><?= $r['no_jurnal'] ?></code></td>
                                    <td><span class='id-badge'><?= $sub['nip'] ?></span> <?= strtoupper($sub['nama_lengkap']) ?> <span class='type-badge badge-payment'>PEGAWAI</span></td>
                                    <td><?= $r['keterangan'] ?> (Individu)</td>
                                    <td class="text-end pe-4 fw-bold text-danger">Rp <?= number_format($sub['gaji_bersih'], 0, ',', '.') ?></td>
                                    <td class="text-center"><a href="print_slip_gaji.php?id=<?= $sub['id'] ?>" target="_blank" class="btn btn-xs btn-outline-success rounded-pill px-3 fw-bold"><i class="fas fa-file-invoice-dollar me-1"></i>SLIP</a></td></tr>
                            <?php } continue; 
                        }
                    }

                    $identitas = "<span class='text-muted small italic'>Transaksi Umum</span>";
                    if(!empty($r['nama_pegawai'])) $identitas = "<span class='id-badge'>{$r['nip_pegawai']}</span> ".strtoupper($r['nama_pegawai']) . " <span class='type-badge badge-payment'>PEGAWAI</span>";
                    elseif(!empty($r['nama_mhs'])) $identitas = "<span class='id-badge'>{$r['nim_mhs']}</span> ".strtoupper($r['nama_mhs']) . " <span class='type-badge badge-receipt'>MAHASISWA</span>";
                    
                    // ROUTING VOUCHER: Slip Gaji vs Kuitansi (Voucher)
                    $slip_id = $r['payment_slip_id'] ?: $r['payroll_detail_id'];
                    $voucher_url = $slip_id ? "print_slip_gaji.php?id=$slip_id" : "print_voucher.php?id={$r['id']}";
                    $btn_label = $slip_id ? "SLIP" : "BUKTI";
                    $btn_color = $slip_id ? "btn-outline-success" : "btn-outline-primary";
                    $btn_icon = $slip_id ? "fa-file-invoice-dollar" : "fa-receipt";
                ?>
                    <tr><td><?= date('d/m/y', strtotime($r['tgl_jurnal'])) ?></td>
                        <td class="fw-bold"><code><?= $r['no_jurnal'] ?></code></td>
                        <td><?= $identitas ?></td>
                        <td><?= $r['keterangan'] ?></td>
                        <td class="text-end pe-4 fw-bold <?= $type=='receipt'?'text-success':'text-danger' ?>">Rp <?= number_format($val, 0, ',', '.') ?></td>
                        <td class="text-center">
                            <a href="<?= $voucher_url ?>" target="_blank" class="btn btn-xs <?= $btn_color ?> rounded-pill px-3 fw-bold shadow-sm"><i class="fas <?= $btn_icon ?> me-1"></i><?= $btn_label ?></a>
                        </td></tr>
                <?php endwhile; else: echo "<tr><td colspan='6' class='py-5 text-center text-muted italic'>Tidak ada rincian data ditemukan.</td></tr>"; endif; ?>
            </tbody></table></div>
        </div>
    <?php endif; ?>
</div>

<script>
/** MESIN EXPORT ATOMIC STABILITY v55.0 - ANTI CRASH & COMMA */
function exportToExcelStability(tableId, filename) {
    const table = document.getElementById(tableId);
    const clone = table.cloneNode(true);
    clone.querySelectorAll('td').forEach(td => {
        const valLabel = td.querySelector('.val-label');
        if (valLabel) { td.innerHTML = valLabel.innerText.trim().replace(/\./g, ''); } 
        else { td.innerHTML = td.innerText.trim().replace(/\./g, ''); }
    });
    clone.querySelectorAll('i, button, script, colgroup').forEach(el => el.remove());
    const form = document.createElement('form'); form.method = 'POST'; form.action = 'export_excel_engine.php'; form.target = '_blank';
    const inputs = [{ name: 'judul_laporan', value: document.getElementById('reportTitleHeader').innerText }, { name: 'nama_file', value: filename }, { name: 'periode_text', value: document.getElementById('reportPeriodText').innerText }, { name: 'html_content', value: clone.outerHTML }];
    inputs.forEach(data => { const input = document.createElement('input'); input.type = 'hidden'; input.name = data.name; input.value = data.value; form.appendChild(input); });
    document.body.appendChild(form); form.submit(); document.body.removeChild(form);
}

function summary_openSetupModal() { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSummarySetup')); document.getElementById('s_id').value = ''; document.getElementById('s_judul').value = 'Ringkasan Kas ' + new Date().getFullYear(); document.getElementById('s_start').value = '<?= date("Y-01-01") ?>'; document.getElementById('s_end').value = '<?= date("Y-m-d") ?>'; document.getElementById('compSummaryContainer').innerHTML = ''; m.show(); }
function summary_editSetup(el) { const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSummarySetup')); const d = el.dataset; document.getElementById('s_id').value = d.id; document.getElementById('s_judul').value = d.judul ?? ''; document.getElementById('s_start').value = d.start ?? ''; document.getElementById('s_end').value = d.end ?? ''; document.getElementById('compSummaryContainer').innerHTML = ''; if(d.comp && d.comp !== 'null' && d.comp !== '[]') { try { JSON.parse(d.comp).forEach(p => { addCompSummary(p.s, p.e); }); } catch(err) {} } m.show(); }
function addCompSummary(s = '', e = '') { const html = `<div class="row g-2 mb-2 comp-row animate__animated animate__fadeIn"><div class="col-5"><input type="date" name="comp_start[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-none small" value="${s}" required></div><div class="col-5"><input type="date" name="comp_end[]" class="form-control border-0 bg-light rounded-pill px-3 shadow-none small" value="${e}" required></div><div class="col-2 text-center"><button type="button" class="btn btn-light text-danger rounded-pill w-100 fw-bold" onclick="this.closest('.comp-row').remove()">&times;</button></div></div>`; document.getElementById('compSummaryContainer').insertAdjacentHTML('beforeend', html); }
function summary_deleteReport(id) { if(confirm('Hapus arsip?')) window.location.href=`index.php?page=laporan_kas_summary&action=delete_summary_local&id=${id}`; }
</script>

<!-- MODAL SETUP -->
<div class="modal fade" id="modalSummarySetup" tabindex="-1" data-bs-backdrop="static" aria-hidden="true" style="z-index: 9999;"><div class="modal-dialog modal-dialog-centered modal-lg"><form action="index.php?page=laporan_kas_summary" method="POST" id="formSummarySetup" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark"><input type="hidden" name="action" value="save_summary_local"><input type="hidden" name="id" id="s_id"><input type="hidden" name="metode" value="Akrual"><div class="modal-header bg-primary text-white border-0 p-4"><h5 class="modal-title fw-bold text-white">Konfigurasi Ringkasan Kas</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body p-4 bg-light text-dark"><div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Judul Laporan</label><input type="text" name="judul" id="s_judul" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div><div class="row g-2 mb-3"><div class="col-6"><label class="small fw-bold text-primary">Mulai Periode Utama</label><input type="date" name="start_date" id="s_start" class="form-control border-0 bg-white rounded-pill px-4 py-2 shadow-sm" required></div><div class="col-6"><label class="small fw-bold text-primary">Sampai Periode Utama</label><input type="date" name="end_date" id="s_end" class="form-control border-0 bg-white rounded-pill px-4 py-2 shadow-sm" required></div></div><div class="border p-3 rounded-4 bg-white mt-3 shadow-sm"><div class="d-flex justify-content-between align-items-center mb-3"><label class="small fw-bold text-secondary mb-0 uppercase">Kolom Komparatif</label><button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-3 fw-bold" onclick="addCompSummary()">+ Tambah</button></div><div id="compSummaryContainer"></div></div></div><div class="modal-footer border-0 p-4 pt-0 bg-light text-center d-block"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase">Simpan & Proses</button></div></form></div></div>