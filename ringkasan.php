<?php
/**
 * ringkasan.php - EXECUTIVE SUMMARY DASHBOARD (PURE GL EDITION)
 * Versi: 61.2 (Sovereign Grand Master - Perfect Alignment Fix)
 * Perbaikan: 
 * 1. Flexbox Alignment: Memastikan nama akun yang panjang sejajar lurus dengan
 * baris pertamanya dan tidak tumpah/mundur ke area kode akun.
 * 2. Solid Sticky Header: Memastikan background grup akun (Pendapatan, Beban, dll)
 * berwarna solid putih/abu agar teks yang melintas di bawahnya saat discroll 
 * benar-benar tenggelam (tidak tumpang tindih).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }
if(function_exists('guardPage')) { guardPage('ringkasan'); }

// =========================================================================
// 1. FILTER LOGIC ENGINE
// =========================================================================
$f_jenis = $_GET['jenis'] ?? 'bulanan';
$f_tahun = $_GET['tahun'] ?? date('Y');
$f_bulan = $_GET['bulan'] ?? date('m');
$f_triwulan = $_GET['triwulan'] ?? 1;
$f_semester = $_GET['semester'] ?? 1;
$f_start_custom = $_GET['start_custom'] ?? date('Y-m-01');
$f_end_custom   = $_GET['end_custom'] ?? date('Y-m-t');

switch ($f_jenis) {
    case 'bulanan':
        $start_date = "$f_tahun-$f_bulan-01";
        $end_date   = date('Y-m-t', strtotime($start_date));
        $label_periode = "Periode Bulan " . date('F Y', strtotime($start_date));
        break;
    case 'triwulanan': 
        $m_start = ($f_triwulan - 1) * 3 + 1; $m_end = $m_start + 2;              
        $start_date = "$f_tahun-" . sprintf("%02d", $m_start) . "-01";
        $end_date   = date('Y-m-t', mktime(0, 0, 0, $m_end, 1, $f_tahun)); 
        $label_periode = "Periode Triwulan $f_triwulan Tahun $f_tahun";
        break;
    case 'semester':
        $m_start = ($f_semester == 1) ? 1 : 7; $m_end = ($f_semester == 1) ? 6 : 12;
        $start_date = "$f_tahun-" . sprintf("%02d", $m_start) . "-01";
        $end_date   = date('Y-m-t', mktime(0, 0, 0, $m_end, 1, $f_tahun));
        $label_periode = "Periode Semester " . ($f_semester==1?'Ganjil (I)':'Genap (II)') . " Tahun $f_tahun";
        break;
    case 'tahunan':
        $start_date = "$f_tahun-01-01"; $end_date = "$f_tahun-12-31";
        $label_periode = "Tahun Buku $f_tahun";
        break;
    case 'custom':
        $start_date = $f_start_custom; $end_date = $f_end_custom;
        $label_periode = "Periode Kustom: " . date('d M Y', strtotime($start_date)) . " s.d " . date('d M Y', strtotime($end_date));
        break;
    default: 
        $start_date = date('Y-m-01'); $end_date = date('Y-m-t');
        $label_periode = "Periode Bulan Ini";
}

// =========================================================================
// 2. DATA AGGREGATION DENGAN TRIM ARMOR (TRUE GL SOURCE)
// =========================================================================
$sql_posisi = "SELECT TRIM(jd.kode_akun) as kode_akun, SUM(jd.debit) as d, SUM(jd.kredit) as k 
               FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
               WHERE j.tgl_jurnal <= '$end_date' 
               GROUP BY TRIM(jd.kode_akun)";
$res_pos = $conn->query($sql_posisi);
$map_posisi = [];
if($res_pos) { while($m = $res_pos->fetch_assoc()) { $map_posisi[$m['kode_akun']] = $m; } }

$sql_aktivitas = "SELECT TRIM(jd.kode_akun) as kode_akun, SUM(jd.debit) as d, SUM(jd.kredit) as k 
                  FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                  WHERE j.tgl_jurnal BETWEEN '$start_date' AND '$end_date' 
                  GROUP BY TRIM(jd.kode_akun)";
$res_akt = $conn->query($sql_aktivitas);
$map_aktivitas = [];
if($res_akt) { while($m = $res_akt->fetch_assoc()) { $map_aktivitas[$m['kode_akun']] = $m; } }

$all_accounts = $conn->query("SELECT * FROM syifa_akun ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

// =========================================================================
// 3. FLAT MATH ENGINE: Menghitung total absolut untuk 4 KPI Cards
// =========================================================================
$t_aset = 0; $t_liab = 0; $t_neto = 0; $t_pend = 0; $t_beban = 0;
foreach($all_accounts as $acc) {
    if ($acc['is_group'] == 0) {
        $kode = trim($acc['kode_akun']);
        $ob = (double)$acc['opening_balance'];
        
        // Mutasi Posisi (Neraca) - Life-to-Date
        $mut_p = $map_posisi[$kode] ?? ['d'=>0, 'k'=>0];
        $net_debit_p = ($acc['saldo_normal'] === 'D' ? $ob : -$ob) + $mut_p['d'] - $mut_p['k'];
        $net_kredit_p = ($acc['saldo_normal'] === 'K' ? $ob : -$ob) + $mut_p['k'] - $mut_p['d'];

        if ($acc['kategori'] == 'Aset') $t_aset += $net_debit_p;
        if ($acc['kategori'] == 'Liabilitas') $t_liab += $net_kredit_p;
        if (in_array($acc['kategori'], ['Aset Neto', 'Ekuitas'])) $t_neto += $net_kredit_p;
        
        // Mutasi Aktivitas (Laba/Rugi) - Period-to-Date (TANPA SALDO AWAL)
        $mut_a = $map_aktivitas[$kode] ?? ['d'=>0, 'k'=>0];
        $net_debit_a = $mut_a['d'] - $mut_a['k'];
        $net_kredit_a = $mut_a['k'] - $mut_a['d'];
        
        if ($acc['kategori'] == 'Pendapatan') $t_pend += $net_kredit_a;
        if ($acc['kategori'] == 'Beban') $t_beban += $net_debit_a;
    }
}
$surplus_periode = $t_pend - $t_beban;

// =========================================================================
// 4. RECURSIVE UI ENGINE (PURE GL MATH)
// =========================================================================
function calculateBalanceRecursive($kode, $all_accounts, $map_data, $use_opening = true) {
    $total = 0;
    $kode = trim($kode);
    $parent_sn = 'D'; 
    foreach($all_accounts as $a) { if(trim($a['kode_akun']) == $kode) { $parent_sn = $a['saldo_normal']; break; } }
    
    foreach($all_accounts as $acc) {
        $c_kode = trim($acc['kode_akun']);
        $p_kode = trim($acc['parent_kode']);
        
        if ($c_kode == $kode && (int)$acc['is_group'] === 0) {
            $mut = $map_data[$c_kode] ?? ['d'=>0, 'k'=>0];
            $ob = $use_opening ? (double)$acc['opening_balance'] : 0;
            $sn = $acc['saldo_normal'];
            return ($sn === 'D') ? ($ob + $mut['d'] - $mut['k']) : ($ob + $mut['k'] - $mut['d']);
        } elseif ($p_kode == $kode) {
            $child_val = calculateBalanceRecursive($c_kode, $all_accounts, $map_data, $use_opening);
            $child_sn = $acc['saldo_normal'];
            
            if ($child_sn === $parent_sn) {
                $total += $child_val;
            } else {
                $total -= $child_val;
            }
        }
    }
    return $total;
}

// UI RENDERER MURNI BUKU BESAR
function renderUI($parent_kode, $kategori, $all_accounts, $map_data, $use_opening, $start_date, $end_date, $level = 0) {
    $html = "";
    foreach($all_accounts as $acc) {
        $is_match = ($level === 0) ? (empty($acc['parent_kode']) && $acc['kategori'] == $kategori) : (trim($acc['parent_kode']) == trim($parent_kode));
        if ($is_match) {
            $c_kode = trim($acc['kode_akun']);
            $saldo = calculateBalanceRecursive($c_kode, $all_accounts, $map_data, $use_opening);
            
            $is_g = (int)$acc['is_group'] === 1;
            $padding = ($level * 15) + 15;
            $toggle = $is_g ? "onclick=\"toggleFolder('".addslashes($c_kode)."')\" style='cursor:pointer;'" : "";
            
            $text_color = ($saldo < 0) ? 'text-danger' : ($is_g ? 'text-dark' : 'text-primary');
            $icon = $is_g ? "<i class='fas fa-folder text-warning me-2' id='icon-{$c_kode}'></i>" : "<i class='fas fa-file-alt text-muted me-2 opacity-50'></i>";
            $display_saldo = ($saldo < 0 ? "(".number_format(abs($saldo)).")" : number_format($saldo));
            
            if (!$is_g) {
                $url = "index.php?page=buku_besar_ringkasan&akun={$c_kode}&tgl_awal=$start_date&tgl_akhir=$end_date&source=ringkasan";
                $saldo_html = "<a href='$url' class='text-decoration-none fw-bold $text_color drill-hover' title='Bedah Transaksi Akun'>$display_saldo <i class='fas fa-search-plus ms-1 small opacity-25'></i></a>";
            } else {
                $saldo_html = "<span class='fw-bold $text_color'>$display_saldo</span>";
            }

            $html .= "<div class='row-acc level-$level ".($is_g?'fw-bold bg-light border-bottom':'border-bottom')."' $toggle>";
            $html .= "<div class='d-flex justify-content-between align-items-center py-2 px-2' style='padding-left: {$padding}px !important;'>";
            
            // 🚀 PERBAIKAN 1: Flexbox Mutlak untuk menahan struktur Kode dan Nama Akun
            $html .= "<div class='d-flex align-items-start text-start' style='flex:1; padding-right:15px;'>";
            $html .= "  <div style='min-width: 25px;'>$icon</div>";
            $html .= "  <div style='min-width: 75px;'><span class='code-badge'>{$c_kode}</span></div>";
            $html .= "  <div class='acc-name lh-sm'>{$acc['nama_akun']}</div>";
            $html .= "</div>";

            $html .= "<div class='text-end'>$saldo_html</div>";
            $html .= "</div></div>";

            if ($is_g) {
                $html .= "<div id='child-{$c_kode}' class='d-none ps-2 border-start ms-3'>";
                $html .= renderUI($c_kode, $kategori, $all_accounts, $map_data, $use_opening, $start_date, $end_date, $level + 1);
                $html .= "</div>";
            }
        }
    }
    return $html;
}
?>

<style>
    .scroll-area { height: 600px; overflow-y: auto; scrollbar-width: thin; position: relative; }
    .code-badge { font-size: 0.75rem; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; color: #475569; font-family: monospace; font-weight: bold; }
    .acc-name { color: #1e293b; font-weight: 600; font-size: 0.85rem; }
    
    /* 🚀 PERBAIKAN 2: Sticky Header Solid Mutlak (Tidak Transparan) */
    .sticky-head { 
        position: sticky; 
        top: 0; 
        z-index: 10; 
        padding: 12px 15px; 
        font-weight: 800; 
        text-transform: uppercase; 
        letter-spacing: 0.5px; 
        background-color: #f8fafc !important; /* Warna Solid/Pekat */
        border-bottom: 2px solid #cbd5e1 !important;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); /* Memberi batas tegas */
    }
    
    /* Pewarnaan spesifik Header Solid */
    .sticky-head-aset { background-color: #eff6ff !important; border-bottom-color: #bae6fd !important; }
    .sticky-head-liab { background-color: #fef2f2 !important; border-bottom-color: #fecaca !important; }
    .sticky-head-neto { background-color: #f0fdf4 !important; border-bottom-color: #a7f3d0 !important; }
    .sticky-head-pend { background-color: #ecfdf5 !important; border-bottom-color: #a7f3d0 !important; }
    .sticky-head-bebn { background-color: #fffbeb !important; border-bottom-color: #fde68a !important; }

    .drill-hover:hover { text-decoration: underline !important; background-color: #fef9c3; padding: 2px 5px; border-radius: 4px; }
    .kpi-card-full { border: none; border-radius: 20px; transition: 0.3s; color: #fff; overflow: hidden; position: relative; min-height: 120px; display: flex; flex-direction: column; justify-content: center; }
    .bg-grad-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
    .bg-grad-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
    .bg-grad-orange { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .bg-grad-purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
    .bg-grad-danger { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    <!-- 1. FILTER BAR MODERN -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-4">
            <h5 class="fw-bold text-primary mb-3"><i class="fas fa-filter me-2"></i>Filter Analisis Keuangan</h5>
            <form method="GET" action="index.php" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="ringkasan">
                <div class="col-md-2">
                    <label class="small text-muted fw-bold mb-1">Periode Analisis</label>
                    <select name="jenis" id="filterJenis" class="form-select rounded-pill border-0 bg-light fw-bold text-primary" onchange="toggleFilter()">
                        <option value="bulanan" <?= $f_jenis=='bulanan'?'selected':'' ?>>Bulanan</option>
                        <option value="triwulanan" <?= $f_jenis=='triwulanan'?'selected':'' ?>>Triwulanan</option>
                        <option value="semester" <?= $f_jenis=='semester'?'selected':'' ?>>Semester</option>
                        <option value="tahunan" <?= $f_jenis=='tahunan'?'selected':'' ?>>Tahunan</option>
                        <option value="custom" <?= $f_jenis=='custom'?'selected':'' ?>>Custom Range</option>
                    </select>
                </div>
                <div class="col-md-2" id="divTahun">
                    <label class="small text-muted fw-bold mb-1">Tahun Buku</label>
                    <select name="tahun" class="form-select rounded-pill border-0 bg-light">
                        <?php for($y=date('Y')+2; $y>=2020; $y--) echo "<option value='$y' ".($f_tahun==$y?'selected':'').">$y</option>"; ?>
                    </select>
                </div>
                <div class="col-md-2 filter-grp" id="divBulan">
                    <label class="small text-muted fw-bold mb-1">Pilih Bulan</label>
                    <select name="bulan" class="form-select rounded-pill border-0 bg-light">
                        <?php for($m=1; $m<=12; $m++) echo "<option value='".sprintf("%02d",$m)."' ".($f_bulan==$m?'selected':'').">".date('F', mktime(0,0,0,$m,1))."</option>"; ?>
                    </select>
                </div>
                <div class="col-md-2 filter-grp d-none" id="divTriwulan">
                    <label class="small text-muted fw-bold mb-1">Pilih Triwulan</label>
                    <select name="triwulan" class="form-select rounded-pill border-0 bg-light">
                        <option value="1" <?= $f_triwulan==1?'selected':'' ?>>Triwulan I (Jan-Mar)</option><option value="2" <?= $f_triwulan==2?'selected':'' ?>>Triwulan II (Apr-Jun)</option><option value="3" <?= $f_triwulan==3?'selected':'' ?>>Triwulan III (Jul-Sep)</option><option value="4" <?= $f_triwulan==4?'selected':'' ?>>Triwulan IV (Okt-Des)</option>
                    </select>
                </div>
                <div class="col-md-2 filter-grp d-none" id="divSemester">
                    <label class="small text-muted fw-bold mb-1">Pilih Semester</label>
                    <select name="semester" class="form-select rounded-pill border-0 bg-light">
                        <option value="1" <?= $f_semester==1?'selected':'' ?>>Semester I (Ganjil)</option><option value="2" <?= $f_semester==2?'selected':'' ?>>Semester II (Genap)</option>
                    </select>
                </div>
                <div class="col-md-4 filter-grp d-none" id="divCustom">
                    <label class="small text-muted fw-bold mb-1">Rentang Tanggal Khusus</label>
                    <div class="input-group">
                        <input type="date" name="start_custom" class="form-control rounded-start-pill border-0 bg-light" value="<?= $f_start_custom ?>">
                        <span class="input-group-text bg-light border-0 px-1 text-muted">s/d</span>
                        <input type="date" name="end_custom" class="form-control rounded-end-pill border-0 bg-light" value="<?= $f_end_custom ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold shadow"><i class="fas fa-sync-alt me-2"></i>PROCESS</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. INFO PERIODE AKTIF -->
    <div class="alert bg-success border-success border-start border-4 rounded-3 p-3 mb-4 d-flex justify-content-between align-items-center text-white shadow-sm">
        <div>
            <h5 class="fw-bold mb-0"><?= $label_periode ?></h5>
            <small class="opacity-75">Posisi Keuangan per <b><?= date('d M Y', strtotime($end_date)) ?></b> | Aktivitas Periode <b><?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?></b></small>
        </div>
        <i class="fas fa-calendar-check fa-2x opacity-50"></i>
    </div>

    <!-- 3. KPI GRID CARDS (4 CARDS) -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card kpi-card-full bg-grad-blue shadow-sm h-100 p-4 border-0 rounded-4 text-white">
                <div class="kpi-label-top fw-bold small text-uppercase mb-1">TOTAL ASET</div>
                <h3 class="kpi-main-val fw-bold mb-1">Rp <?= number_format($t_aset) ?></h3>
                <div class="kpi-sub-info small"><i class="fas fa-arrow-up me-1 opacity-50"></i>Akumulasi Aktiva</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card kpi-card-full bg-grad-green shadow-sm h-100 p-4 border-0 rounded-4 text-white">
                <div class="kpi-label-top fw-bold small text-uppercase mb-1">PENDAPATAN</div>
                <h3 class="kpi-main-val fw-bold mb-1">Rp <?= number_format($t_pend) ?></h3>
                <div class="kpi-sub-info small"><i class="fas fa-arrow-up me-1 opacity-50"></i>Periode Berjalan</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card kpi-card-full bg-grad-orange shadow-sm h-100 p-4 border-0 rounded-4 text-white">
                <div class="kpi-label-top fw-bold small text-uppercase mb-1">BEBAN & BIAYA</div>
                <h3 class="kpi-main-val fw-bold mb-1">Rp <?= number_format($t_beban) ?></h3>
                <div class="kpi-sub-info small"><i class="fas fa-arrow-down me-1 opacity-50"></i>Periode Berjalan</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card kpi-card-full <?= $surplus_periode >= 0 ? 'bg-grad-purple' : 'bg-grad-danger' ?> shadow-sm h-100 p-4 border-0 rounded-4 text-white">
                <div class="kpi-label-top fw-bold small text-uppercase mb-1">SURPLUS / (DEFISIT)</div>
                <h3 class="kpi-main-val fw-bold mb-1">Rp <?= number_format($surplus_periode) ?></h3>
                <div class="kpi-sub-info small"><i class="fas fa-balance-scale me-1 opacity-50"></i>Laba/Rugi Berjalan</div>
            </div>
        </div>
    </div>

    <!-- 4. KONTEN UTAMA (SPLIT VIEW: NERACA KIRI - AKTIVITAS KANAN) -->
    <div class="row g-4">
        <!-- KOLOM KIRI: NERACA (POSISI KEUANGAN) -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden h-100">
                <div class="card-header bg-dark text-white p-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="fas fa-balance-scale me-2"></i>POSISI KEUANGAN (NERACA)</h6>
                    <span class="badge bg-white text-dark small">Akumulatif</span>
                </div>
                <div class="card-body p-0 scroll-area bg-white">
                    <div class="sticky-head sticky-head-aset text-primary d-flex justify-content-between">
                        <span>ASET (AKTIVA)</span><span>Rp <?= number_format($t_aset) ?></span>
                    </div>
                    <div class="px-2"><?= renderUI(NULL, 'Aset', $all_accounts, $map_posisi, true, $start_date, $end_date) ?></div>

                    <div class="sticky-head sticky-head-liab text-danger mt-3 d-flex justify-content-between">
                        <span>KEWAJIBAN (LIABILITAS)</span><span>Rp <?= number_format($t_liab) ?></span>
                    </div>
                    <div class="px-2"><?= renderUI(NULL, 'Liabilitas', $all_accounts, $map_posisi, true, $start_date, $end_date) ?></div>

                    <div class="sticky-head sticky-head-neto text-success mt-3 d-flex justify-content-between">
                        <span>ASET NETO (EKUITAS)</span><span>Rp <?= number_format($t_neto) ?></span>
                    </div>
                    <div class="px-2"><?= renderUI(NULL, 'Aset Neto', $all_accounts, $map_posisi, true, $start_date, $end_date) ?></div>
                </div>
                <div class="card-footer bg-light p-3 border-top">
                    <div class="d-flex justify-content-between fw-bold small text-muted">
                        <span>Aset = Kewajiban + Aset Neto + Surplus</span>
                        <span>Balance Check: <?= number_format($t_aset - ($t_liab + $t_neto + $surplus_periode)) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- KOLOM KANAN: AKTIVITAS (LABA RUGI) -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden h-100">
                <div class="card-header bg-success text-white p-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="fas fa-chart-line me-2"></i>LAPORAN AKTIVITAS</h6>
                    <span class="badge bg-white text-success small">Periode Berjalan</span>
                </div>
                <div class="card-body p-0 scroll-area bg-white">
                    <div class="sticky-head sticky-head-pend text-success d-flex justify-content-between">
                        <span>PENDAPATAN</span><span>Rp <?= number_format($t_pend) ?></span>
                    </div>
                    <div class="px-2"><?= renderUI(NULL, 'Pendapatan', $all_accounts, $map_aktivitas, false, $start_date, $end_date) ?></div>

                    <div class="sticky-head sticky-head-bebn text-dark mt-3 d-flex justify-content-between">
                        <span>BEBAN & BIAYA</span><span>Rp <?= number_format($t_beban) ?></span>
                    </div>
                    <div class="px-2"><?= renderUI(NULL, 'Beban', $all_accounts, $map_aktivitas, false, $start_date, $end_date) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleFilter() {
    const j = document.getElementById('filterJenis').value;
    const divTahun = document.getElementById('divTahun');
    document.querySelectorAll('.filter-grp').forEach(e => e.classList.add('d-none'));
    divTahun.classList.remove('d-none');
    if(j === 'bulanan') document.getElementById('divBulan').classList.remove('d-none');
    else if(j === 'triwulanan') document.getElementById('divTriwulan').classList.remove('d-none');
    else if(j === 'semester') document.getElementById('divSemester').classList.remove('d-none');
    else if(j === 'custom') { document.getElementById('divCustom').classList.remove('d-none'); divTahun.classList.add('d-none'); }
}
function toggleFolder(kode) {
    const childBox = document.getElementById('child-' + kode);
    const icon = document.getElementById('icon-' + kode);
    if (!childBox || !icon) return;
    if (childBox.classList.contains('d-none')) { childBox.classList.remove('d-none'); icon.classList.replace('fa-folder', 'fa-folder-open'); } 
    else { childBox.classList.add('d-none'); icon.classList.replace('fa-folder-open', 'fa-folder'); }
}
toggleFilter();
</script>