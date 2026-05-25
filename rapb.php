<?php
/**
 * rapb.php - MANAJEMEN RAPB & KONFIGURASI UNIT (EXECUTIVE MONITORING)
 * Versi: 5.5 (Grand Master - Unmapped Catcher & Total Realisasi Edition)
 * STATUS: FULL CODE - NO TRUNCATION
 * Perbaikan:
 * 1. MENGINJEKSI UNMAPPED CATCHER: Jika ada transaksi beban yang belum di-mapping 
 * ke dalam RAPB, sistem otomatis menyedotnya ke kolom Operasional agar tabel tidak 0.
 * 2. Menambahkan kolom Total Realisasi Belanja di samping Pengembangan (30%).
 * 3. Memaksa warna text-white pada baris tfoot (Total).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }
if(function_exists('guardPage')) { guardPage('rapb'); }

$tahun = $_GET['tahun'] ?? date('Y');
$active_tab = $_GET['tab'] ?? 'dashboard';
$nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

// =========================================================================
// 1. DATA MASTER UNTUK MANAJEMEN UNIT
// =========================================================================
$unit_list = $conn->query("SELECT * FROM m_unit ORDER BY nama_unit ASC")->fetch_all(MYSQLI_ASSOC);
$coa_beban = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE kategori='Beban' AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
$coa_kas = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE (kategori IN ('Kas','Bank') OR kode_akun LIKE '1-11%') AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

if(!function_exists('safeQuerySum')){
    function safeQuerySum($conn, $sql) {
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) { $r = $res->fetch_row(); return (double)($r[0] ?? 0); }
        return 0;
    }
}

// =========================================================================
// 🚀 THE OMNI-PREFIX EXPANSION ENGINE
// =========================================================================
if(!function_exists('getExpandedCoasSQL')) {
    function getExpandedCoasSQL($conn, $base_coas) {
        $valid_coas = array_filter($base_coas, function($c) { return trim($c) !== ''; });
        if(empty($valid_coas)) return "''";
        
        $likes = [];
        foreach($valid_coas as $c) {
            $p = rtrim($c, '0');
            if(substr($p, -1) == '-' || substr($p, -1) == '.') $p = substr($p, 0, -1);
            if(strlen($p) < 4) $p = substr($c, 0, 4);
            $likes[] = "kode_akun LIKE '$p%'";
        }
        $cond = implode(" OR ", $likes);
        $res = [];
        $q = $conn->query("SELECT kode_akun FROM syifa_akun WHERE ($cond) AND is_active=1");
        if($q) while($r = $q->fetch_assoc()) $res[] = "'" . $r['kode_akun'] . "'";
        return empty($res) ? "''" : implode(",", array_unique($res));
    }
}

// =========================================================================
// 2. DATA AGGREGATION ENGINE (SINKRONISASI KAS & BANK UNTUK DASHBOARD RAPB)
// =========================================================================

// A. Saldo Awal Kas (Opening Balance 1-11xx)
$sql_sa = "SELECT SUM(opening_balance) as total FROM syifa_akun WHERE kode_akun LIKE '1-11%' AND is_group = 0 AND is_active = 1";
$saldo_awal_kas = (double)($conn->query($sql_sa)->fetch_assoc()['total'] ?? 0);

// B. Inisialisasi Data 12 Bulan
$monthly_data = [];
for($i=1; $i<=12; $i++) {
    $monthly_data[$i] = ['in' => 0, 'out_cash' => 0, 'ops_70' => 0, 'dev_30' => 0];
}

// C. REALISASI KAS (Debit = Masuk, Kredit = Keluar)
$sql_cash_flow = "SELECT MONTH(j.tgl_jurnal) as bln, SUM(jd.debit) as total_debit, SUM(jd.kredit) as total_kredit
                  FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id
                  WHERE jd.kode_akun LIKE '1-11%' AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted = 0 GROUP BY bln";
$res_cash = $conn->query($sql_cash_flow);
while($r = $res_cash->fetch_assoc()){
    $m = (int)$r['bln'];
    $monthly_data[$m]['in'] = (double)$r['total_debit'];
    $monthly_data[$m]['out_cash'] = (double)$r['total_kredit'];
}

// D. REALISASI ANGGARAN (Beban 70/30) - 🚀 OMNI-PREFIX & UNMAPPED CATCHER
$q_active = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$tahun' AND kategori='Belanja' AND status IN ('Approved', 'Generated') ORDER BY id DESC LIMIT 1");
$active_header_id = ($q_active && $q_active->num_rows > 0) ? (int)$q_active->fetch_assoc()['id'] : 0;

$b_ops = []; $b_dev = [];
$q_b = $conn->query("SELECT kode_akun, jenis_belanja FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND status='Disetujui' AND is_category=0 AND kategori='Pengeluaran' AND (header_id=$active_header_id OR sumber_data='UNIT_APPROVED')");
if($q_b) {
    while($r = $q_b->fetch_assoc()) {
        if(!empty(trim($r['kode_akun']))) {
            if($r['jenis_belanja'] == 'Operasional') $b_ops[] = $r['kode_akun'];
            else $b_dev[] = $r['kode_akun'];
        }
    }
}

$ops_in = getExpandedCoasSQL($conn, $b_ops);
$dev_in = getExpandedCoasSQL($conn, $b_dev);

// 1. Tarik yang Ter-Mapping ke Operasional
if ($ops_in !== "''") {
    $sql_ops = "SELECT MONTH(j.tgl_jurnal) as bln, SUM(jd.debit - jd.kredit) as val FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun IN ($ops_in) AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted=0 GROUP BY bln";
    $res_ops = $conn->query($sql_ops);
    if ($res_ops) while($r = $res_ops->fetch_assoc()) $monthly_data[(int)$r['bln']]['ops_70'] += (double)$r['val'];
}

// 2. Tarik yang Ter-Mapping ke Pengembangan
if ($dev_in !== "''") {
    $sql_dev = "SELECT MONTH(j.tgl_jurnal) as bln, SUM(jd.debit - jd.kredit) as val FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun IN ($dev_in) AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted=0 GROUP BY bln";
    $res_dev = $conn->query($sql_dev);
    if ($res_dev) while($r = $res_dev->fetch_assoc()) $monthly_data[(int)$r['bln']]['dev_30'] += (double)$r['val'];
}

// 3. 🚀 UNMAPPED CATCHER: Tangkap semua pengeluaran (Beban) yang BELUM ter-mapping ke anggaran, paksa masuk ke Operasional!
$all_mapped_in = "''";
if ($ops_in !== "''" && $dev_in !== "''") $all_mapped_in = "$ops_in, $dev_in";
elseif ($ops_in !== "''") $all_mapped_in = $ops_in;
elseif ($dev_in !== "''") $all_mapped_in = $dev_in;

$sql_unmapped = "SELECT MONTH(j.tgl_jurnal) as bln, SUM(jd.debit - jd.kredit) as val 
                 FROM syifa_jurnal_detail jd 
                 JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                 JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
                 WHERE a.kategori = 'Beban' AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted=0 ";
if ($all_mapped_in !== "''") {
    $sql_unmapped .= " AND jd.kode_akun NOT IN ($all_mapped_in)";
}
$sql_unmapped .= " GROUP BY bln";

$res_unmapped = $conn->query($sql_unmapped);
if ($res_unmapped) {
    while($r = $res_unmapped->fetch_assoc()) {
        $monthly_data[(int)$r['bln']]['ops_70'] += (double)$r['val'];
    }
}

// E. DATA PAGU (TARGET)
$sql_pagu = "SELECT SUM(CASE WHEN kategori = 'Pendapatan' THEN nominal_pagu ELSE 0 END) as pagu_pend, SUM(CASE WHEN kategori = 'Pengeluaran' AND jenis_belanja = 'Operasional' THEN nominal_pagu ELSE 0 END) as pagu_70, SUM(CASE WHEN kategori = 'Pengeluaran' AND jenis_belanja = 'Pengembangan' THEN nominal_pagu ELSE 0 END) as pagu_30 FROM syifa_budgets WHERE tahun_anggaran = '$tahun' AND status = 'Disetujui'";
$pagu = $conn->query($sql_pagu)->fetch_assoc();

// FINAL KPI CALCULATIONS
$total_penerimaan_jurnal = array_sum(array_column($monthly_data, 'in'));
$total_pengeluaran_jurnal = array_sum(array_column($monthly_data, 'out_cash'));

$total_realisasi_penerimaan = $total_penerimaan_jurnal + $saldo_awal_kas;
$dana_belum_dibelanjakan = $total_penerimaan_jurnal - $total_pengeluaran_jurnal;
$kas_saat_ini_final = $total_realisasi_penerimaan - $total_pengeluaran_jurnal;

$pct_pend = ($pagu['pagu_pend'] > 0) ? ($total_penerimaan_jurnal / $pagu['pagu_pend']) * 100 : 0;
$pct_70 = ($pagu['pagu_70'] > 0) ? (array_sum(array_column($monthly_data, 'ops_70')) / $pagu['pagu_70']) * 100 : 0;
$pct_30 = ($pagu['pagu_30'] > 0) ? (array_sum(array_column($monthly_data, 'dev_30')) / $pagu['pagu_30']) * 100 : 0;
?>

<style>
    /* 🛡️ PERBAIKAN: SCALING DOWN CSS KHUSUS RAPB */
    .kpi-card-full { border: none; border-radius: 14px; transition: 0.3s; color: #fff; overflow: hidden; position: relative; padding: 20px; min-height: 110px; display: flex; flex-direction: column; justify-content: center; box-shadow: 0 8px 15px rgba(0,0,0,0.08); }
    .kpi-card-full:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(0,0,0,0.12) !important; }
    .kpi-card-full .kpi-icon { position: absolute; right: -15px; bottom: -20px; font-size: 70px; opacity: 0.15; z-index: 1; transform: rotate(-10deg); }
    
    .bg-gradient-red { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); }
    .bg-gradient-blue { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
    .bg-gradient-orange { background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%); }
    .bg-gradient-green { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
    
    .kpi-label { font-size: 10px; font-weight: 800; text-transform: uppercase; opacity: 0.9; letter-spacing: 0.5px; z-index: 2; margin-bottom: 4px; }
    .kpi-value { font-size: 22px; font-weight: 900; line-height: 1.1; margin-top: 0; z-index: 2; }
    
    .table-custom thead th { background: #1e293b; color: #fff; font-size: 10px; text-transform: uppercase; padding: 12px; }
    .table-custom tbody td { font-size: 12.5px; border-bottom: 1px solid #f1f5f9; padding: 10px; }
    .bg-total-summary { background: #0f172a !important; color: #fff !important; }
    
    /* 🛡️ MENGECILKAN TINGGI GRAFIK/CHART */
    .chart-box { height: 140px; position: relative; }

    /* CSS MANAJEMEN UNIT & TABS */
    .nav-tabs .nav-link { font-weight: 800; color: #64748b; border: none; padding: 15px 25px; transition: 0.3s; border-radius: 12px 12px 0 0; text-decoration: none; }
    .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 4px solid #0d6efd; background: #fff; }
    .unit-analytics-card { border: 1.5px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.03); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.4s ease; transform-origin: center center; }
    .bar-container-premium { position: relative; height: 10px; background: #f1f5f9; border-radius: 50px; overflow: hidden; display: flex; margin-bottom: 6px; border: 1px solid #e2e8f0; }
    .bar-fill-premium { height: 100%; transition: 0.6s; }
    .coa-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1.5px solid #0d6efd; border-radius: 12px; z-index: 9999; max-height: 250px; overflow-y: auto; display: none; box-shadow: 0 10px 40px rgba(0,0,0,0.15); margin-top: 5px; text-align: left; }
    .coa-item { padding: 12px 20px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; text-align: left; }
    .coa-item:hover { background: #f0f7ff; color: #0d6efd; font-weight: 800; }
    
    /* ZOOM EFFECT CSS */
    .unit-analytics-card.zoomed {
        transform: scale(1.15);
        z-index: 1050;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4) !important;
        position: relative;
    }
    .zoom-overlay {
        display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
        background: rgba(15, 23, 42, 0.6); z-index: 1040; backdrop-filter: blur(3px);
        transition: opacity 0.3s ease; opacity: 0;
    }
    .zoom-overlay.active { display: block; opacity: 1; }
    .hover-unit-link { text-decoration: none !important; transition: 0.2s; }
    .hover-unit-link:hover { color: #0d6efd !important; text-decoration: underline !important; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4">
        <div>
            <h4 class="fw-bold text-dark mb-0"><i class="fas fa-project-diagram me-2 text-primary"></i>Manajemen RAPB & Unit</h4>
            <small class="text-muted fw-bold">Monitoring Realisasi Anggaran & Konfigurasi Unit Kerja</small>
        </div>
        <form method="GET" class="d-flex bg-light rounded-pill px-2 border">
            <input type="hidden" name="page" value="rapb">
            <input type="hidden" name="tab" value="<?= $active_tab ?>">
            <select name="tahun" class="form-select border-0 bg-transparent fw-bold text-primary shadow-none pe-4" style="width: 130px;" onchange="this.form.submit()">
                <?php for($y=date('Y')+1; $y>=2020; $y--) echo "<option value='$y' ".($tahun==$y?'selected':'').">$y</option>"; ?>
            </select>
        </form>
    </div>

    <!-- TABS NAVIGASI -->
    <ul class="nav nav-tabs mb-4 border-bottom-0 text-dark" id="rapbTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link <?= $active_tab=='dashboard'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button">
                <i class="fas fa-chart-pie me-2"></i>Dashboard RAPB
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab=='manajemen'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-manajemen" type="button">
                <i class="fas fa-building me-2"></i>Manajemen Unit
            </button>
        </li>
    </ul>

    <!-- FLASH MESSAGES -->
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4 text-dark text-start">
            <i class="fas fa-info-circle me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="tab-content" id="rapbTabsContent">
        
        <!-- ========================================================================= -->
        <!-- TAB 1: DASHBOARD RAPB (PREMIUM GRADIENT ALIGNMENT) -->
        <!-- ========================================================================= -->
        <div class="tab-pane fade <?= $active_tab=='dashboard'?'show active':'' ?>" id="tab-dashboard">
            <div class="row g-3 mb-4 text-start">
                <div class="col-md-3 col-sm-6">
                    <div class="card kpi-card-full bg-gradient-red shadow-sm h-100">
                        <i class="fas fa-shopping-cart kpi-icon"></i>
                        <div class="kpi-label">Total Realisasi Belanja</div>
                        <div class="kpi-value">Rp <?= number_format($total_pengeluaran_jurnal) ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card kpi-card-full bg-gradient-blue shadow-sm h-100">
                        <i class="fas fa-hand-holding-usd kpi-icon"></i>
                        <div class="kpi-label">Total Realisasi Penerimaan</div>
                        <div class="kpi-value">Rp <?= number_format($total_realisasi_penerimaan) ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card kpi-card-full bg-gradient-orange shadow-sm h-100 text-white">
                        <i class="fas fa-wallet kpi-icon"></i>
                        <div class="kpi-label">Dana Belum Dibelanjakan</div>
                        <div class="kpi-value">Rp <?= number_format($dana_belum_dibelanjakan) ?></div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card kpi-card-full bg-gradient-green shadow-sm h-100">
                        <i class="fas fa-piggy-bank kpi-icon"></i>
                        <div class="kpi-label">Saldo Kas Saat Ini</div>
                        <div class="kpi-value">Rp <?= number_format($kas_saat_ini_final) ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100 bg-white">
                        <div class="card-header bg-white p-3 fw-bold border-bottom"><span><i class="fas fa-wallet me-2 text-success"></i>Tabel Saldo Kas & Penerimaan</span></div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 text-center table-custom">
                                <thead><tr><th>Bulan</th><th class="text-end pe-4">Kas Diterima (Inflow)</th></tr></thead>
                                <tbody>
                                    <?php foreach($monthly_data as $m => $d): ?>
                                    <tr><td><?= $nama_bulan[$m] ?></td><td class="text-end pe-4 fw-bold">Rp <?= number_format($d['in']) ?></td></tr>
                                    <?php endforeach; ?>
                                    <tr class="bg-light"><td class="fw-bold text-start ps-4">SALDO AWAL KAS (<?= $tahun ?>-01-01)</td><td class="text-end pe-4 fw-bold text-muted">Rp <?= number_format($saldo_awal_kas) ?></td></tr>
                                </tbody>
                                <tfoot class="bg-total-summary fw-bold text-white">
                                    <tr><td class="py-3 text-start ps-4 text-white">TOTAL REALISASI PENERIMAAN</td><td class="text-end pe-4 text-white">Rp <?= number_format($total_realisasi_penerimaan) ?></td></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100 bg-white">
                        <div class="card-header bg-white p-3 fw-bold border-bottom"><span><i class="fas fa-chart-pie me-2 text-danger"></i>Realisasi Anggaran Belanja (70/30)</span></div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 text-center table-custom">
                                <!-- 🚀 MURNI FIX: Tambah Kolom Total Realisasi -->
                                <thead>
                                    <tr>
                                        <th>Bulan</th>
                                        <th class="text-end">Operasional (70%)</th>
                                        <th class="text-end">Pengembangan (30%)</th>
                                        <th class="text-end pe-4">Total Realisasi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($monthly_data as $m => $d): ?>
                                    <tr>
                                        <td><?= $nama_bulan[$m] ?></td>
                                        <td class="text-end text-success fw-bold">Rp <?= number_format($d['ops_70']) ?></td>
                                        <td class="text-end text-warning fw-bold">Rp <?= number_format($d['dev_30']) ?></td>
                                        <td class="text-end text-primary fw-bold pe-4">Rp <?= number_format($d['ops_70'] + $d['dev_30']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="bg-dark text-white fw-bold">
                                    <tr>
                                        <td class="text-white">TOTAL REALISASI BELANJA</td>
                                        <td class="text-end text-white">Rp <?= number_format(array_sum(array_column($monthly_data, 'ops_70'))) ?></td>
                                        <td class="text-end text-white">Rp <?= number_format(array_sum(array_column($monthly_data, 'dev_30'))) ?></td>
                                        <td class="text-end pe-4 text-white">Rp <?= number_format(array_sum(array_column($monthly_data, 'ops_70')) + array_sum(array_column($monthly_data, 'dev_30'))) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bagian Bawah Tetap Utuh Sesuai Aslinya -->
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 h-100">
                        <h6 class="fw-bold mb-3 text-uppercase text-muted"><i class="fas fa-tachometer-alt me-2 text-primary"></i>Performance Index (Realisasi VS Target)</h6>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-1 small fw-bold"><span>ANGGARAN PENDAPATAN (REVENUE)</span><span class="text-primary"><?= round($pct_pend, 2) ?>%</span></div>
                            <div class="chart-box"><canvas id="chartPendapatan"></canvas></div>
                            <div class="d-flex justify-content-between mt-1 small"><span class="text-muted"><i class="fas fa-circle text-primary me-1"></i> Realisasi: Rp <?= number_format($total_penerimaan_jurnal) ?></span><span class="text-muted">Target: Rp <?= number_format($pagu['pagu_pend']) ?></span></div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-1 small fw-bold"><span>BELANJA OPERASIONAL (70%)</span><span class="text-success"><?= round($pct_70, 2) ?>%</span></div>
                            <div class="chart-box"><canvas id="chart70"></canvas></div>
                            <div class="d-flex justify-content-between mt-1 small"><span class="text-muted"><i class="fas fa-circle text-success me-1"></i> Realisasi: Rp <?= number_format(array_sum(array_column($monthly_data, 'ops_70'))) ?></span><span class="text-muted">Target: Rp <?= number_format($pagu['pagu_70']) ?></span></div>
                        </div>
                        <div>
                            <div class="d-flex justify-content-between mb-1 small fw-bold"><span>BELANJA PENGEMBANGAN (30%)</span><span class="text-warning"><?= round($pct_30, 2) ?>%</span></div>
                            <div class="chart-box"><canvas id="chart30"></canvas></div>
                            <div class="d-flex justify-content-between mt-1 small"><span class="text-muted"><i class="fas fa-circle text-warning me-1"></i> Realisasi: Rp <?= number_format(array_sum(array_column($monthly_data, 'dev_30'))) ?></span><span class="text-muted">Target: Rp <?= number_format($pagu['pagu_30']) ?></span></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 h-100">
                        <h6 class="fw-bold mb-3 text-uppercase text-muted"><i class="fas fa-chart-line me-2 text-primary"></i>Tren Arus Kas Bulanan</h6>
                        <div style="height: 320px;"><canvas id="chartMonthlyTrend"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- TAB 2: MANAJEMEN UNIT (GRID 3 KOLOM, ZOOM, MENU KANAN, NAMA LINK) -->
        <!-- ========================================================================= -->
        <div class="tab-pane fade <?= $active_tab=='manajemen'?'show active':'' ?>" id="tab-manajemen">
            <div class="d-flex justify-content-end mb-4"><button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="modalManageUnit()"><i class="fas fa-plus me-2"></i>Tambah Unit Baru</button></div>
            
            <!-- Overlay untuk fitur Zoom -->
            <div id="zoomOverlay" class="zoom-overlay"></div>

            <div class="row g-3 text-dark text-center">
                <?php foreach($unit_list as $u): 
                    $res_u_m = $conn->query("SELECT kode_akun FROM unit_coa_map WHERE unit_id = {$u['id']}");
                    $u_cs = []; while($rm = $res_u_m->fetch_assoc()) $u_cs[] = $rm['kode_akun'];
                    
                    // VARIABEL KALKULASI
                    $p_u = 0; $p_rapb = 0; $p_tambahan = 0; $r_u = 0; $k_u = 0; $s_u = 0;
                    
                    if(!empty($u_cs)) { 
                        $u_c_q = "'" . implode("','", $u_cs) . "'"; 
                        $p_u = safeQuerySum($conn, "SELECT SUM(nominal_pagu) FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND status='Disetujui' AND kode_akun IN ($u_c_q) AND sumber_data IN ('RAPB', 'UNIT_APPROVED')"); 
                        $p_rapb = safeQuerySum($conn, "SELECT SUM(nominal_pagu) FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND status='Disetujui' AND kode_akun IN ($u_c_q) AND sumber_data = 'RAPB'"); 
                        $p_tambahan = safeQuerySum($conn, "SELECT SUM(nominal_pagu) FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND status='Disetujui' AND kode_akun IN ($u_c_q) AND sumber_data = 'UNIT_APPROVED'"); 
                        
                        $u_c_q_expanded = getExpandedCoasSQL($conn, $u_cs);
                        if ($u_c_q_expanded !== "''") {
                            $r_u = safeQuerySum($conn, "SELECT SUM(jd.debit - jd.kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun IN ($u_c_q_expanded) AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted = 0");
                        }
                    }
                    
                    if(!empty($u['kas_bank_akun'])) {
                        $k_u = safeQuerySum($conn, "SELECT (a.opening_balance + COALESCE(mut.net, 0)) FROM syifa_akun a LEFT JOIN (SELECT kode_akun, SUM(debit-kredit) as net FROM syifa_jurnal_detail GROUP BY kode_akun) mut ON a.kode_akun = mut.kode_akun WHERE a.kode_akun='{$u['kas_bank_akun']}'");
                        $s_u = safeQuerySum($conn, "SELECT SUM(jd.debit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '{$u['kas_bank_akun']}' AND YEAR(j.tgl_jurnal) = '$tahun'");
                    }
                    
                    $p_disb = ($p_u > 0) ? ($s_u / $p_u) * 100 : 0;
                    $p_real = ($p_u > 0) ? ($r_u / $p_u) * 100 : 0;
                    $sisa_pagu = $p_u - $r_u;
                    $coas_disp = $conn->query("SELECT m.kode_akun, a.nama_akun FROM unit_coa_map m JOIN syifa_akun a ON m.kode_akun = a.kode_akun WHERE m.unit_id={$u['id']}")->fetch_all(MYSQLI_ASSOC);
                ?>
                <div class="col-md-6 col-lg-4"> 
                    <div class="unit-analytics-card p-3 bg-white border rounded-4 shadow-sm text-center h-100 d-flex flex-column position-relative" id="card_<?= $u['id'] ?>">
                        
                        <button class="btn btn-sm btn-light border rounded-circle shadow-none position-absolute" style="top: 15px; left: 15px; z-index: 10;" onclick="toggleZoom('card_<?= $u['id'] ?>')" title="Zoom Grid">
                            <i class="fas fa-expand-arrows-alt text-secondary"></i>
                        </button>

                        <div class="dropdown position-absolute" style="top: 15px; right: 15px; z-index: 10;">
                            <button class="btn btn-sm btn-light border rounded-circle shadow-none" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu border-0 shadow-lg rounded-4 p-2 text-dark text-center">
                                <li><button class="dropdown-item rounded-3 small py-2 fw-bold text-dark text-center" onclick='editUnit(<?= json_encode($u) ?>, <?= json_encode($coas_disp) ?>)'><i class="fas fa-cog me-2 text-primary"></i>Ubah Mapping</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item rounded-3 small py-2 text-danger fw-bold text-center" href="budget_unit_action.php?action=delete_unit&id=<?= $u['id'] ?>&return_page=rapb" onclick="return confirm('Hapus Unit?')"><i class="fas fa-trash me-2"></i>Hapus Unit</a></li>
                            </ul>
                        </div>
                        
                        <div class="mt-4 pt-1"></div>

                        <a href="index.php?page=anggaran_unit&tab=dashboard&unit_id=<?= $u['id'] ?>&tahun=<?= $tahun ?>" class="hover-unit-link">
                            <h6 class="fw-bold mb-1 text-dark"><?= $u['nama_unit'] ?></h6>
                        </a>
                        
                        <div class="d-flex justify-content-center gap-2 mb-3 mt-1">
                            <code class="small text-muted fw-bold text-uppercase"><?= $u['kode_unit'] ?></code>
                            <span class="badge bg-<?= $u['status'] ? 'success' : 'danger' ?> small px-2 py-1"><?= $u['status'] ? 'AKTIF' : 'NON-AKTIF' ?></span>
                        </div>
                        
                        <div class="row text-start mb-3 bg-light rounded-4 p-3 g-2 shadow-sm border flex-grow-1" style="font-size: 10px;">
                            <div class="col-6 border-end border-2 border-white d-flex flex-column justify-content-center">
                                <div class="text-muted fw-bold mb-1">Saldo Pagu Anggaran:</div>
                                <div class="fw-bold text-primary mb-2 fs-6">Rp <?= number_format($p_u) ?></div>
                                
                                <div class="text-muted fw-bold mb-1">Saldo Anggaran RAPB:</div>
                                <div class="fw-bold text-dark mb-2">Rp <?= number_format($p_rapb) ?></div>
                                
                                <div class="text-muted fw-bold mb-1">Saldo Anggaran Tambahan:</div>
                                <div class="fw-bold text-success">Rp <?= number_format($p_tambahan) ?></div>
                            </div>
                            <div class="col-6 ps-3 d-flex flex-column justify-content-center">
                                <div class="text-muted fw-bold mb-1">Saldo Kas Unit:</div>
                                <div class="fw-bold text-info mb-2 fs-6">Rp <?= number_format($k_u) ?></div>
                                
                                <div class="text-muted fw-bold mb-1">Dana Disalurkan:</div>
                                <div class="fw-bold text-dark mb-2">Rp <?= number_format($s_u) ?></div>
                                
                                <div class="text-muted fw-bold mb-1">Sisa Pagu Belum Realisasi:</div>
                                <div class="fw-bold text-danger">Rp <?= number_format($sisa_pagu) ?></div>
                            </div>
                        </div>

                        <div class="mt-auto">
                            <div class="mb-2 text-center">
                                <div class="d-flex justify-content-between small fw-bold opacity-75 mb-1" style="font-size: 9px;"><span>% PENYALURAN DARI PAGU</span><span><?= round($p_disb, 1) ?>%</span></div>
                                <div class="bar-container-premium"><div class="bar-fill-premium" style="width:<?= min(100,$p_disb) ?>%; background:#3b82f6;"></div></div>
                            </div>
                            <div class="mb-1 text-center">
                                <div class="d-flex justify-content-between small fw-bold opacity-75 mb-1" style="font-size: 9px;"><span>% REALISASI DARI PAGU</span><span><?= round($p_real, 1) ?>%</span></div>
                                <div class="bar-container-premium"><div class="bar-fill-premium" style="width:<?= min(100,$p_real) ?>%; background:#10b981;"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
    </div>
</div>

<!-- MODAL MANAJEMEN UNIT -->
<div class="modal fade" id="mdlManageUnit" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered text-dark">
        <form action="budget_unit_action.php" method="POST" id="formManageUnit" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_unit_mapping">
            <input type="hidden" name="id" id="unit_id">
            <input type="hidden" name="return_page" value="rapb">
            
            <div class="modal-header bg-dark text-white p-4 border-0 d-block text-center">
                <h5 class="modal-title fw-bold text-white text-center">Konfigurasi Unit Kerja & Mapping Akun</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark text-start">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Nama Unit / Lembaga</label>
                        <input type="text" name="nama_unit" id="u_nama" class="form-control rounded-pill border shadow-sm px-4 fw-bold" required>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Kode Registrasi (Singkatan)</label>
                        <input type="text" name="kode_unit" id="u_kode" class="form-control rounded-pill border shadow-sm px-4" required>
                    </div>
                    <div class="col-md-8">
                        <label class="small fw-bold text-primary uppercase mb-1">Rekening Kas Utama (Buku Besar)</label>
                        <select name="kas_bank_akun" id="u_kas" class="form-select rounded-pill border shadow-sm px-4 fw-bold text-dark" required>
                            <option value="">-- Pilih Kas/Bank --</option>
                            <?php foreach($coa_kas as $k) echo "<option value='{$k['kode_akun']}'>{$k['kode_akun']} - {$k['nama_akun']}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="small fw-bold text-muted uppercase mb-1">Status Unit</label>
                        <select name="status" id="u_status" class="form-select rounded-pill border shadow-sm px-4 fw-bold text-dark">
                            <option value="1">Aktif</option>
                            <option value="0">Non-Aktif</option>
                        </select>
                    </div>
                    
                    <div class="col-12 mt-4 position-relative">
                        <label class="small fw-bold text-danger mb-2 uppercase ps-2"><i class="fas fa-link me-1"></i> Mapping Akun Biaya Terkait</label>
                        <div class="input-group shadow-sm rounded-pill overflow-hidden border">
                            <span class="input-group-text border-0 bg-white px-3"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="coa_search_input" class="form-control border-0 px-3 fw-bold" placeholder="Ketik minimal 3 huruf nama akun..." autocomplete="off">
                        </div>
                        <div id="coa_search_results" class="position-absolute w-100 bg-white border rounded-3 shadow-lg mt-1" style="display:none; max-height: 200px; overflow-y: auto; z-index: 2000;"></div>
                        
                        <div id="mapped_coa_container" class="bg-white border rounded-4 p-3 mt-3 shadow-inner d-flex flex-wrap gap-2" style="min-height: 100px;"></div>
                        <input type="hidden" name="coa_real_json" id="coa_real_json">
                    </div>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white text-center d-block text-center">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow text-center text-center">SIMPAN KONFIGURASI</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // === CHART LOGIC FOR DASHBOARD RAPB ===
    const getBarOptions = (maxVal) => ({
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { stacked: true, display: false, max: maxVal * 1.1 },
            y: { stacked: true, display: false }
        }
    });

    const createPerformanceChart = (id, realVal, paguVal, color) => {
        new Chart(document.getElementById(id), {
            type: 'bar',
            data: {
                labels: ['Real vs Plan'],
                datasets: [
                    { data: [realVal], backgroundColor: color, borderRadius: 10, barThickness: 30, z: 2 },
                    { data: [paguVal], backgroundColor: '#f1f5f9', borderRadius: 10, barThickness: 30, z: 1 }
                ]
            },
            options: getBarOptions(Math.max(realVal, paguVal))
        });
    };

    createPerformanceChart('chartPendapatan', <?= $total_penerimaan_jurnal ?>, <?= $pagu['pagu_pend'] ?>, '#3b82f6');
    createPerformanceChart('chart70', <?= array_sum(array_column($monthly_data, 'ops_70')) ?>, <?= $pagu['pagu_70'] ?>, '#10b981');
    createPerformanceChart('chart30', <?= array_sum(array_column($monthly_data, 'dev_30')) ?>, <?= $pagu['pagu_30'] ?>, '#f59e0b');

    new Chart(document.getElementById('chartMonthlyTrend'), {
        type: 'line',
        data: {
            labels: ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"],
            datasets: [
                { 
                    label: 'Kas Masuk', 
                    data: <?= json_encode(array_values(array_column($monthly_data, 'in'))) ?>, 
                    borderColor: '#3b82f6', 
                    backgroundColor: 'rgba(59, 130, 246, 0.05)',
                    tension: 0.4, 
                    fill: true 
                },
                { 
                    label: 'Kas Keluar', 
                    data: <?= json_encode(array_values(array_column($monthly_data, 'out_cash'))) ?>, 
                    borderColor: '#ef4444', 
                    tension: 0.4, 
                    fill: false 
                }
            ]
        },
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // === ZOOM OVERLAY LOGIC ===
    const overlay = document.getElementById('zoomOverlay');
    overlay.onclick = () => {
        document.querySelectorAll('.unit-analytics-card.zoomed').forEach(c => c.classList.remove('zoomed'));
        overlay.classList.remove('active');
    };
});

// === FUNCTION TO TOGGLE ZOOM CARD ===
function toggleZoom(cardId) {
    const card = document.getElementById(cardId);
    const overlay = document.getElementById('zoomOverlay');
    if (card.classList.contains('zoomed')) {
        card.classList.remove('zoomed');
        overlay.classList.remove('active');
    } else {
        document.querySelectorAll('.unit-analytics-card.zoomed').forEach(c => c.classList.remove('zoomed'));
        card.classList.add('zoomed');
        overlay.classList.add('active');
    }
}

// === LOGIC FOR MANAJEMEN UNIT ===
const coaMasterData = <?= json_encode($coa_beban) ?>; 
let mappedCoas = [];

const searchInput = document.getElementById('coa_search_input');
const searchResults = document.getElementById('coa_search_results');

if(searchInput) {
    searchInput.addEventListener('input', function() {
        const val = this.value.toLowerCase(); 
        searchResults.innerHTML = '';
        if(val.length < 3) { searchResults.style.display = 'none'; return; }
        
        const matches = coaMasterData.filter(c => c.nama_akun.toLowerCase().includes(val) || c.kode_akun.includes(val)).slice(0, 15);
        if(matches.length > 0) { 
            matches.forEach(m => { 
                const d = document.createElement('div'); 
                d.className = 'p-2 border-bottom cursor-pointer text-dark small coa-item'; 
                d.innerHTML = `<strong>${m.kode_akun}</strong> - ${m.nama_akun}`; 
                d.onmousedown = (e) => { 
                    e.preventDefault();
                    if(!mappedCoas.some(x => x.kode_akun === m.kode_akun)) { 
                        mappedCoas.push(m); renderCoaTags(); 
                    } 
                    searchInput.value = ''; searchResults.style.display='none'; 
                }; 
                searchResults.appendChild(d); 
            }); 
            searchResults.style.display = 'block'; 
        } else { searchResults.style.display = 'none'; }
    });
    searchInput.addEventListener('blur', function() { setTimeout(() => { searchResults.style.display = 'none'; }, 200); });
}

function renderCoaTags() { 
    const container = document.getElementById('mapped_coa_container'); 
    container.innerHTML = mappedCoas.length ? '' : '<span class="text-muted small w-100 text-center py-3">Belum ada akun yang di-mapping.</span>'; 
    mappedCoas.forEach((c, idx) => { 
        container.innerHTML += `<span class="badge bg-primary bg-opacity-10 text-primary border border-primary rounded-pill px-3 py-2 d-flex align-items-center gap-2"><span><b>${c.kode_akun}</b> - ${c.nama_akun}</span> <i class="fas fa-times text-danger" style="cursor:pointer;" onclick="removeCoaTag(${idx})" title="Hapus"></i></span>`; 
    }); 
    document.getElementById('coa_real_json').value = JSON.stringify(mappedCoas.map(c => c.kode_akun)); 
}

function removeCoaTag(idx) { mappedCoas.splice(idx, 1); renderCoaTags(); }

function modalManageUnit() { 
    document.getElementById('formManageUnit')?.reset();
    document.getElementById('unit_id').value=''; 
    document.getElementById('u_nama').value=''; 
    document.getElementById('u_kode').value=''; 
    document.getElementById('u_kas').value=''; 
    document.getElementById('u_status').value='1';
    mappedCoas = []; renderCoaTags(); 
    new bootstrap.Modal(document.getElementById('mdlManageUnit')).show(); 
}

function editUnit(u, coas) { 
    document.getElementById('unit_id').value=u.id; 
    document.getElementById('u_nama').value=u.nama_unit; 
    document.getElementById('u_kode').value=u.kode_unit; 
    document.getElementById('u_kas').value=u.kas_bank_akun || ''; 
    document.getElementById('u_status').value=u.status !== null ? u.status : 1; 
    mappedCoas = [];
    if(coas && coas.length > 0) { mappedCoas = coas.map(c => ({kode_akun: c.kode_akun, nama_akun: c.nama_akun})); }
    renderCoaTags(); 
    new bootstrap.Modal(document.getElementById('mdlManageUnit')).show(); 
}
</script>