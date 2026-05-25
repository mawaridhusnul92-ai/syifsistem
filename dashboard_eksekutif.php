<?php
/**
 * dashboard_eksekutif.php - EXECUTIVE COMMAND CENTER
 * Versi: 11.5 (Sovereign Grand Master - True RBAC Edition)
 * Perbaikan Mutlak: 
 * MENGHAPUS TOTAL 'The Teleporter Engine' yang sebelumnya hardcoded menolak 
 * akses user non-pimpinan. Sekarang, jika matriks "Dashboard Eksekutif" dicentang 
 * untuk Role apapun (misal: Demo), halaman ini 100% bisa dibuka dengan lancar!
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Inisialisasi Engine Data & AI
require_once 'dashboard_query.php';
require_once 'dashboard_ai_engine.php';

// 🚀 SABOTASE TELEPORTER ENGINE TELAH DIMUSNAHKAN DARI SINI!
// Halaman ini sekarang murni diatur oleh Centang Matriks Izin (Gatekeeper index.php)

function fRp($angka) { return "Rp " . number_format($angka ?? 0, 0, ',', '.'); }

$surplus = $data['realisasi_pendapatan'] - $data['realisasi_belanja'];
$surplus_color = $surplus >= 0 ? 'text-success' : 'text-danger';

// Persentase Mahasiswa
$total_mhs_bertagihan = $data['mhs_status']['lunas'] + $data['mhs_status']['mencicil'] + $data['mhs_status']['belum'];
$pct_mhs_lunas = $total_mhs_bertagihan > 0 ? ($data['mhs_status']['lunas'] / $total_mhs_bertagihan) * 100 : 0;
$pct_mhs_cicil = $total_mhs_bertagihan > 0 ? ($data['mhs_status']['mencicil'] / $total_mhs_bertagihan) * 100 : 0;
$pct_mhs_belum = $total_mhs_bertagihan > 0 ? ($data['mhs_status']['belum'] / $total_mhs_bertagihan) * 100 : 0;

$ops_pagu = (double)$data['komposisi']['ops_pagu'];
$ops_real = (double)$data['komposisi']['ops_real'];
$sisa_ops = max(0, $ops_pagu - $ops_real);

$dev_pagu = (double)$data['komposisi']['dev_pagu'];
$dev_real = (double)$data['komposisi']['dev_real'];
$sisa_dev = max(0, $dev_pagu - $dev_real);

// =========================================================================
// 🚀 THE BULLETPROOF VARIABLES
// =========================================================================
$pct_belanja = ($data['pagu_belanja'] > 0) ? ($data['realisasi_belanja'] / $data['pagu_belanja']) * 100 : 0;
$pct_pendapatan = ($data['pagu_pendapatan'] > 0) ? ($data['realisasi_pendapatan'] / $data['pagu_pendapatan']) * 100 : 0;
$pct_piutang = ($data['piutang_total'] > 0) ? ($data['piutang_dibayar'] / $data['piutang_total']) * 100 : 0;

$f_thn = $_GET['tahun'] ?? date('Y');
$f_bln = $_GET['bulan'] ?? '';
$f_ta = $_GET['tanggal_awal'] ?? '';
$f_tk = $_GET['tanggal_akhir'] ?? '';
$f_prodi = $_GET['prodi'] ?? '';

$lbl_trend = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"];
$val_trend_in = array_map('floatval', array_column($data['trend'], 'pendapatan'));
$val_trend_out = array_map('floatval', array_column($data['trend'], 'belanja'));

$val_forecast = array_fill(0, 12, null);
$current_m_idx = ($f_thn == date('Y')) ? (int)date('n') - 1 : 11;
if (!empty($f_bln)) $current_m_idx = (int)$f_bln - 1;

for($i = $current_m_idx; $i < 12; $i++) { $val_forecast[$i] = (float)$data['burn_rate']; }
if($current_m_idx >= 0 && $current_m_idx < 12) { $val_forecast[$current_m_idx] = $val_trend_out[$current_m_idx]; }

$lbl_top = array_column($data['top_expense'], 'nama');
$val_top = array_map('floatval', array_column($data['top_expense'], 'total'));

$lbl_prodi = !empty($data['piutang_prodi']) ? array_column($data['piutang_prodi'], 'nama_prodi') : ['Belum Ada Data'];
$val_prodi_target = !empty($data['piutang_prodi']) ? array_map('floatval', array_column($data['piutang_prodi'], 'target')) : [0];
$val_prodi_real = !empty($data['piutang_prodi']) ? array_map('floatval', array_column($data['piutang_prodi'], 'realisasi')) : [0];

// =========================================================================
// 🚀 GENERATE AI INSIGHTS
// =========================================================================
$bulan_berjalan = ($f_bln) ? (int)$f_bln : (($f_thn == date('Y')) ? (int)date('n') : 12);
$ai_insights = generateExecutiveInsight($data, $bulan_berjalan);
$health = $ai_insights['health'];

// =========================================================================
// 🚀 THE SEAMLESS AJAX ENGINE (API RESPONSE)
// =========================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    while (ob_get_level()) ob_end_clean(); 
    header('Content-Type: application/json');
    
    $ai_list_html = '';
    foreach($ai_insights['points'] as $pt) {
        $ai_list_html .= "<li>$pt</li>";
    }

    $response = [
        'saldo_kas' => fRp($data['saldo_kas']),
        'realisasi_pendapatan' => fRp($data['realisasi_pendapatan']),
        'pagu_pendapatan' => fRp($data['pagu_pendapatan']),
        'realisasi_belanja' => fRp($data['realisasi_belanja']),
        'pagu_belanja' => fRp($data['pagu_belanja']),
        'surplus' => fRp($surplus),
        'surplus_color' => $surplus_color,
        'ai_health_index' => $ai_insights['health']['index'],
        'ai_health_status' => $ai_insights['health']['status'],
        'ai_health_badge' => $ai_insights['health']['badge'],
        'ai_health_ringkasan' => $ai_insights['health']['ringkasan'],
        'ai_list_html' => $ai_list_html,
        'pct_pendapatan' => round($pct_pendapatan, 1),
        'pct_belanja' => round($pct_belanja, 1),
        'pct_piutang' => round($pct_piutang, 1),
        'sisa_anggaran' => fRp($data['sisa_anggaran']),
        'sisa_pendapatan' => fRp($data['sisa_pendapatan']),
        'aset_total' => fRp($data['aset_total']),
        'ops_real' => $ops_real,
        'sisa_ops' => $sisa_ops,
        'dev_real' => $dev_real,
        'sisa_dev' => $sisa_dev,
        'ops_pagu_str' => fRp($ops_pagu),
        'dev_pagu_str' => fRp($dev_pagu),
        'lbl_trend' => $lbl_trend,
        'val_trend_in' => $val_trend_in,
        'val_trend_out' => $val_trend_out,
        'val_forecast' => $val_forecast,
        'lbl_top' => $lbl_top,
        'val_top' => $val_top,
        'lbl_prodi' => $lbl_prodi,
        'val_prodi_target' => $val_prodi_target,
        'val_prodi_real' => $val_prodi_real,
        'mhs_lunas' => number_format($data['mhs_status']['lunas']),
        'mhs_mencicil' => number_format($data['mhs_status']['mencicil']),
        'mhs_belum' => number_format($data['mhs_status']['belum']),
        'pct_mhs_lunas' => round($pct_mhs_lunas, 1),
        'pct_mhs_cicil' => round($pct_mhs_cicil, 1),
        'pct_mhs_belum' => round($pct_mhs_belum, 1),
        'mhs_total' => number_format($data['mhs_total'])
    ];
    
    echo json_encode($response);
    exit;
}

// =========================================================================
// 🚀 DYNAMIC PATH RESOLVER (ENGINE JADWAL SHOLAT OFFLINE)
// =========================================================================
if(!defined('APP_LAT')) define('APP_LAT', -0.0263);
if(!defined('APP_LNG')) define('APP_LNG', 109.3425);
if(!defined('APP_TZ')) define('APP_TZ', 7); 

$prayer_paths = [
    __DIR__ . '/core/prayer_time.php',
    __DIR__ . '/prayer_time.php',
    'core/prayer_time.php',
    'prayer_time.php'
];

$prayerTimes = ['Subuh'=>'-','Dzuhur'=>'-','Ashar'=>'-','Maghrib'=>'-','Isya'=>'-'];
foreach ($prayer_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        if (function_exists('getPrayerTimes')) {
            $prayerTimes = getPrayerTimes(APP_LAT, APP_LNG, APP_TZ);
        }
        break; 
    }
}

// 🛡️ TIMEZONE GUARD
$old_tz_main = @date_default_timezone_get();
date_default_timezone_set('Asia/Jakarta');
$current_time = date('H:i');
date_default_timezone_set($old_tz_main);

$next_prayer_name = ''; $next_prayer_time = ''; $found_next = false;
foreach ($prayerTimes as $name => $time) {
    if ($current_time < $time && $time !== '-') { 
        $next_prayer_name = $name; 
        $next_prayer_time = $time; 
        $found_next = true; 
        break; 
    }
}
if (!$found_next) { 
    $next_prayer_name = 'Subuh'; 
    $next_prayer_time = $prayerTimes['Subuh']; 
}

if(function_exists('guardPage')) { guardPage('dashboard_eksekutif'); }
?>

<style>
    /* Transisi untuk Seamless AJAX */
    #exec_dashboard_body { transition: opacity 0.4s ease-in-out; }
    
    /* 🚀 ANIMASI CINEMATIC SLIDE */
    @keyframes swoopInLeft { 0% { transform: translateX(-100px); opacity: 0; } 100% { transform: translateX(0); opacity: 1; } }
    @keyframes swoopInRight { 0% { transform: translateX(100px); opacity: 0; } 100% { transform: translateX(0); opacity: 1; } }
    @keyframes swoopInBottom { 0% { transform: translateY(50px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }

    .swoop-left { animation: swoopInLeft 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; }
    .swoop-right { animation: swoopInRight 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; }
    .swoop-bottom { animation: swoopInBottom 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) forwards; opacity: 0; }

    .del-100 { animation-delay: 0.1s; } .del-200 { animation-delay: 0.2s; }
    .del-300 { animation-delay: 0.3s; } .del-400 { animation-delay: 0.4s; }

    /* 🛡️ COMPACT ENTERPRISE SCALING (Mengecilkan margin/padding agar rapi di Zoom 100%) */
    .hero-banner { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); position: relative; z-index: 1060; border: none; overflow: visible !important; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
    .hero-glow-1 { position: absolute; top: -50px; right: 15%; width: 250px; height: 250px; background: radial-gradient(circle, rgba(59,130,246,0.15) 0%, rgba(0,0,0,0) 70%); border-radius: 50%; pointer-events: none; z-index: 0; }
    .hero-glow-2 { position: absolute; bottom: -50px; left: 5%; width: 150px; height: 150px; background: radial-gradient(circle, rgba(16,185,129,0.1) 0%, rgba(0,0,0,0) 70%); border-radius: 50%; pointer-events: none; z-index: 0; }
    
    .glass-panel { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-radius: 16px; padding: 12px 20px; display: flex; align-items: center; position: relative; z-index: 2; }
    
    .widget-time { padding-right: 20px; border-right: 1px solid rgba(255,255,255,0.15); text-align: right; }
    .w-clock { font-family: 'Courier New', Courier, monospace; font-size: 30px; font-weight: 900; color: #ffffff; letter-spacing: -1px; line-height: 1; text-shadow: 0 2px 10px rgba(0,0,0,0.3); }
    .w-date { font-size: 10px; font-weight: 800; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 5px; }
    .w-hijri { font-size: 9px; font-weight: 700; color: #94a3b8; margin-top: 2px; }
    
    .prayer-box { min-width: 160px; }
    .prayer-title { font-size: 9px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 2px; letter-spacing: 0.5px; }
    .prayer-row { display: flex; justify-content: space-between; font-size: 12px; padding: 6px 0; border-bottom: 1px dashed #e2e8f0; align-items: center; }
    .prayer-row:last-child { border-bottom: none; }
    .prayer-dropdown { transform: translateX(-15px); width: calc(100% + 30px) !important; border: 1px solid #e2e8f0; box-shadow: 0 20px 40px rgba(0,0,0,0.2) !important; z-index: 9999 !important; }
    .hover-glass:hover { background: rgba(255,255,255,0.2) !important; color: white !important; }

    .exec-card { border: none; border-radius: 16px; background: #fff; box-shadow: 0 8px 20px rgba(0,0,0,0.03); margin-bottom: 20px; padding: 20px; transition: 0.3s;}
    .exec-card:hover { box-shadow: 0 12px 25px rgba(16, 185, 129, 0.06); } 
    .card-xl-title { font-weight: 800; color: #334155; font-size: 13px; text-transform: uppercase; margin-bottom: 15px; display: flex; align-items: center;}
    
    .sum-card { border-radius: 16px; padding: 20px; color: #ffffff !important; position: relative; overflow: hidden; border: none; height: 100%; box-shadow: 0 8px 15px rgba(0,0,0,0.08); transition: 0.3s ease;}
    .sum-card:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(16, 185, 129, 0.2); }
    .sum-icon { position: absolute; right: -10px; bottom: -20px; font-size: 70px; opacity: 0.15; transform: rotate(-10deg); transition: 0.5s; }
    .sum-card:hover .sum-icon { transform: rotate(0deg) scale(1.1); opacity: 0.25; }
    
    .sum-title { font-size: 11px; font-weight: 800; text-transform: uppercase; opacity: 0.9; margin-bottom: 4px; letter-spacing: 0.5px; position: relative; z-index: 2; }
    .sum-val { font-size: 22px; font-weight: 900; line-height: 1.1; position: relative; z-index: 2; margin-bottom: 4px; }
    .sum-card .small { position: relative; z-index: 2; font-size: 10px; }
    
    .bg-c-blue { background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); color: white !important;} 
    .bg-c-emerald { background: linear-gradient(135deg, #10b981 0%, #047857 100%); color: white !important;}
    .bg-c-amber { background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%); color: white !important;}
    .bg-c-rose { background: linear-gradient(135deg, #f43f5e 0%, #be123c 100%); color: white !important;}

    .ai-panel { background: linear-gradient(180deg, #064e3b 0%, #022c22 100%); color: #fff !important; border-radius: 16px; padding: 25px; position: relative; overflow: hidden; height: 100%; }
    .ai-glow { position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(16,185,129,0.3) 0%, rgba(0,0,0,0) 70%); border-radius: 50%; }
    .ai-list { margin: 0; padding: 0; list-style: none; }
    .ai-list li { padding: 10px 12px; background: rgba(255,255,255,0.05); border-radius: 10px; margin-bottom: 8px; font-size: 11.5px; line-height: 1.4; color: #ffffff !important; display: flex; align-items: start; border: 1px solid rgba(255,255,255,0.05); }

    .filter-box { background: #fff; border-radius: 12px; padding: 15px 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; margin-bottom: 20px; }
    
    .chart-container { position: relative; width: 100%; max-height: 100%; }
    .h-150 { height: 150px !important; } .h-200 { height: 200px !important; }
    .h-250 { height: 250px !important; } .h-300 { height: 300px !important; }

    .pg-wrap { height: 10px; background: #e2e8f0; border-radius: 50px; overflow: hidden; margin-top: 6px; }
    .pg-fill { height: 100%; border-radius: 50px; transition: 1.5s ease-in-out; }

    .mhs-stat-box { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; text-align: center; flex: 1; }
    .mhs-stat-val { font-size: 18px; font-weight: 900; line-height: 1; margin-bottom: 3px; }
</style>

<div id="exec_dashboard_body" class="exec-body animate__animated animate__fadeIn" style="overflow-x: hidden;">
    
    <!-- 🚀 HEADER & WIDGET WAKTU + SHOLAT (COMPACT HERO BANNER) -->
    <div class="card hero-banner rounded-4 mb-3 swoop-bottom">
        <div class="hero-glow-1"></div>
        <div class="hero-glow-2"></div>
        
        <div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center position-relative z-1 gap-3">
            <div class="text-start">
                <h2 class="fw-bold text-white mb-1" style="letter-spacing: -0.5px; font-size: 24px;">Dashboard Eksekutif</h2>
                <div class="text-white-50 fw-bold small text-uppercase" style="letter-spacing: 1px; font-size: 11px;"><i class="fas fa-network-wired me-2 text-info"></i>Financial & Operational Intelligence Center</div>
            </div>
            
            <div class="d-none d-md-flex flex-wrap align-items-center">
                <div class="glass-panel shadow-sm">
                    <!-- Widget Jam Digital -->
                    <div class="widget-time">
                        <div class="w-clock text-white mb-1" id="liveClock">00:00:00</div>
                        <div class="w-date text-info fw-bold text-uppercase" id="liveDate">Memuat...</div>
                        <div class="w-hijri text-white-50 fw-bold mt-1" id="liveHijri">Memuat Hijriyah...</div>
                    </div>

                    <!-- Widget Jadwal Sholat Kaca -->
                    <div class="prayer-box position-relative ms-3 bg-transparent border-0 shadow-none p-0">
                        <div class="d-flex justify-content-between align-items-center cursor-pointer" data-bs-toggle="collapse" data-bs-target="#prayerList" aria-expanded="false" style="outline: none;">
                            <div class="text-start pe-2">
                                <div class="prayer-title text-white-50 mb-1"><i class="fas fa-mosque me-1 text-success"></i> JADWAL SHOLAT</div>
                                <div class="d-flex align-items-baseline">
                                    <span class="text-white-50 fw-bold me-2 text-uppercase" style="font-size:10px;"><?= strtoupper($next_prayer_name) ?></span>
                                    <strong class="text-success" style="font-size: 22px; text-shadow: 0 0 10px rgba(16,185,129,0.3);"><?= $next_prayer_time ?></strong>
                                </div>
                            </div>
                            <div class="bg-white bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center border border-white border-opacity-25 hover-glass" style="width: 30px; height: 30px; transition: 0.3s;">
                                <i class="fas fa-chevron-down text-white small"></i>
                            </div>
                        </div>
                        
                        <!-- Panel Dropdown Sholat -->
                        <div class="collapse position-absolute prayer-dropdown mt-2 rounded-4 bg-white p-3 border-0" id="prayerList">
                            <div class="prayer-title border-bottom pb-2 mb-2 text-center" style="color:#64748b !important;">PONTIANAK HARI INI</div>
                            <?php foreach($prayerTimes as $name => $time): ?>
                                <div class="prayer-row <?= ($name == $next_prayer_name) ? 'fw-bold text-success' : 'text-dark' ?>">
                                    <span><?= $name ?></span>
                                    <strong><?= $time ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER PERIODE DINAMIS -->
    <div class="filter-box swoop-bottom del-100" style="position: relative; z-index: 10;">
        <form method="GET" class="row g-2 align-items-end" id="mainFilterForm" onsubmit="applyFilter(event)">
            <input type="hidden" name="page" value="dashboard_eksekutif">
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1 text-uppercase" style="font-size: 10px;">Tahun</label>
                <select name="tahun" class="form-select border-0 bg-light fw-bold shadow-none rounded-3" onchange="applyFilter(event)">
                    <?php for($y=date('Y')+1; $y>=2020; $y--) echo "<option value='$y' ".($f_thn==$y?'selected':'').">$y</option>"; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-muted mb-1 text-uppercase" style="font-size: 10px;">Bulan</label>
                <select name="bulan" class="form-select border-0 bg-light fw-bold shadow-none rounded-3" onchange="applyFilter(event)">
                    <option value="">Semua Bulan</option>
                    <?php 
                    $nm_bln = ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"];
                    for($m=1; $m<=12; $m++) echo "<option value='$m' ".($f_bln==$m?'selected':'').">".$nm_bln[$m-1]."</option>"; 
                    ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="small fw-bold text-muted mb-1 text-uppercase" style="font-size: 10px;">Tgl Rentang Jurnal (Opsional)</label>
                <div class="input-group">
                    <input type="date" name="tanggal_awal" class="form-control border-0 bg-light shadow-none rounded-start-3 text-muted fw-bold" value="<?= $f_ta ?>" style="font-size: 12px;" onchange="applyFilter(event)">
                    <span class="input-group-text border-0 bg-light px-2">-</span>
                    <input type="date" name="tanggal_akhir" class="form-control border-0 bg-light shadow-none rounded-end-3 text-muted fw-bold" value="<?= $f_tk ?>" style="font-size: 12px;" onchange="applyFilter(event)">
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 rounded-3 fw-bold shadow-sm"><i class="fas fa-sync-alt me-1"></i> UPDATE</button>
            </div>
        </form>
    </div>

    <!-- 1. KARTU METRIK UTAMA -->
    <div class="row g-3 mb-4">
        <!-- Saldo Kas -->
        <div class="col-md-6 col-xl-3 swoop-left del-100">
            <div class="sum-card <?= $ai_insights['health']['status'] == 'KRITIS' ? 'bg-c-rose' : 'bg-c-blue' ?>">
                <i class="fas fa-vault sum-icon"></i>
                <div class="sum-title">Saldo Kas Institusi</div>
                <div class="sum-val" id="txt_saldo_kas"><?= fRp($data['saldo_kas']) ?></div>
                <div class="small fw-bold opacity-75" id="txt_health_status">Status Keamanan: <?= $ai_insights['health']['status'] ?></div>
            </div>
        </div>
        <!-- Realisasi Pendapatan -->
        <div class="col-md-6 col-xl-3 swoop-left del-200">
            <div class="sum-card bg-c-emerald">
                <i class="fas fa-hand-holding-usd sum-icon"></i>
                <div class="sum-title">Pendapatan (Realisasi)</div>
                <div class="sum-val" id="txt_real_pendapatan"><?= fRp($data['realisasi_pendapatan']) ?></div>
                <div class="small fw-bold opacity-75" id="txt_pagu_pendapatan">Target Pagu: <?= fRp($data['pagu_pendapatan']) ?></div>
            </div>
        </div>
        <!-- Realisasi Belanja -->
        <div class="col-md-6 col-xl-3 swoop-right del-200">
            <div class="sum-card bg-c-rose">
                <i class="fas fa-shopping-cart sum-icon"></i>
                <div class="sum-title">Belanja (Realisasi)</div>
                <div class="sum-val" id="txt_real_belanja"><?= fRp($data['realisasi_belanja']) ?></div>
                <div class="small fw-bold opacity-75" id="txt_pagu_belanja">Batas Pagu: <?= fRp($data['pagu_belanja']) ?></div>
            </div>
        </div>
        <!-- Surplus / Defisit -->
        <div class="col-md-6 col-xl-3 swoop-right del-100">
            <div class="sum-card bg-dark text-white">
                <i class="fas fa-balance-scale sum-icon"></i>
                <div class="sum-title">Surplus / (Defisit)</div>
                <div class="sum-val <?= $surplus_color ?>" id="txt_surplus"><?= fRp($surplus) ?></div>
                <div class="small fw-bold opacity-75 text-white">Selisih Pendapatan & Belanja</div>
            </div>
        </div>
    </div>

    <!-- 2. AI ENGINE & HEALTH INDEX -->
    <div class="row g-3 mb-4">
        <!-- AI Insight Engine -->
        <div class="col-lg-4 swoop-left del-300">
            <div class="ai-panel shadow-sm">
                <div class="ai-glow"></div>
                <div class="d-flex align-items-center mb-3 relative z-10">
                    <div class="bg-white bg-opacity-10 p-2 rounded-circle me-3"><i class="fas fa-robot fa-lg text-white"></i></div>
                    <div>
                        <h6 class="fw-bold mb-0 text-white" style="font-size: 13px;">Sistem Analisa Cerdas</h6>
                        <span id="txt_ai_score" class="badge bg-<?= $ai_insights['health']['badge'] ?> mt-1 border border-light border-opacity-25 py-1 px-2 text-white">Skor Aman: <?= $ai_insights['health']['index'] ?>/100</span>
                    </div>
                </div>
                <p class="text-light fw-bold small mb-2 z-10 relative" id="txt_ai_ringkasan">"<?= $ai_insights['health']['ringkasan'] ?>"</p>
                <ul class="ai-list z-10 relative" id="txt_ai_list">
                    <?php foreach($ai_insights['points'] as $pt): ?>
                        <li><?= $pt ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Progress Pencapaian -->
        <div class="col-lg-4 swoop-bottom del-300">
            <div class="exec-card h-100 mb-0 d-flex flex-column">
                <div class="card-xl-title"><i class="fas fa-tasks text-primary me-2"></i> Realisasi vs Target (YTD)</div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between fw-bold small text-dark mb-1">
                        <span>Pencapaian Pendapatan</span><span class="text-success" id="txt_pct_pend"><?= round($pct_pendapatan, 1) ?>%</span>
                    </div>
                    <div class="pg-wrap"><div class="pg-fill bg-success" id="bar_pct_pend" style="width: <?= min(100, $pct_pendapatan) ?>%;"></div></div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between fw-bold small text-dark mb-1">
                        <span>Serapan Anggaran Belanja</span><span class="text-danger" id="txt_pct_bel"><?= round($pct_belanja, 1) ?>%</span>
                    </div>
                    <div class="pg-wrap"><div class="pg-fill bg-danger" id="bar_pct_bel" style="width: <?= min(100, $pct_belanja) ?>%;"></div></div>
                </div>
                <div>
                    <div class="d-flex justify-content-between fw-bold small text-dark mb-1">
                        <span>Penagihan Piutang Mahasiswa</span><span class="text-info" id="txt_pct_piut"><?= round($pct_piutang, 1) ?>%</span>
                    </div>
                    <div class="pg-wrap"><div class="pg-fill bg-info" id="bar_pct_piut" style="width: <?= min(100, $pct_piutang) ?>%;"></div></div>
                </div>
                <div class="row g-2 mt-auto pt-3 border-top">
                    <div class="col-6">
                        <div class="bg-danger bg-opacity-10 rounded-3 p-2 text-center border border-danger border-opacity-25 h-100">
                            <span class="small fw-bold text-danger d-block mb-1" style="font-size: 9px;">SISA PAGU BELANJA</span>
                            <h6 class="fw-bold text-danger mb-0" id="txt_sisa_belanja" style="font-size: 13px;"><?= fRp($data['sisa_anggaran']) ?></h6>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-success bg-opacity-10 rounded-3 p-2 text-center border border-success border-opacity-25 h-100">
                            <span class="small fw-bold text-success d-block mb-1" style="font-size: 9px;">TARGET BLM TERCAPAI</span>
                            <h6 class="fw-bold text-success mb-0" id="txt_sisa_pendapatan" style="font-size: 13px;"><?= fRp($data['sisa_pendapatan']) ?></h6>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Budget Health Index -->
        <div class="col-lg-4 swoop-right del-300">
            <div class="exec-card h-100 mb-0 text-center d-flex flex-column justify-content-center">
                <div class="card-xl-title justify-content-center border-0"><i class="fas fa-heartbeat text-success me-2"></i> Budget Health Index</div>
                <div class="chart-container h-150"><canvas id="cHealth"></canvas></div>
                <h2 class="fw-bold mt-2 mb-0 text-<?= $ai_insights['health']['badge'] ?>" id="txt_health_big"><?= $ai_insights['health']['index'] ?></h2>
                
                <div class="mt-auto pt-3 border-top text-start">
                    <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                        <small class="text-muted fw-bold ps-2">Total Nilai Buku Aset</small>
                        <span class="fw-bold text-primary pe-2" id="txt_aset_total"><?= fRp($data['aset_total']) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. BAWAH: GRAFIK GARIS & KOMPOSISI -->
    <div class="row g-3 mb-4">
        <!-- Forecast Line Chart -->
        <div class="col-xl-7 swoop-left del-400">
            <div class="exec-card h-100">
                <div class="card-xl-title d-flex justify-content-between align-items-center flex-wrap gap-2 border-0 mb-0">
                    <span><i class="fas fa-project-diagram text-info me-2"></i> Tren Pendapatan vs Belanja & Proyeksi</span>
                    <span class="badge bg-light text-dark border">Tahun <?= $f_thn ?></span>
                </div>
                <div class="chart-container h-250 mt-2"><canvas id="cForecast"></canvas></div>
            </div>
        </div>

        <!-- Komposisi 70/30 SPLIT DONUT -->
        <div class="col-xl-5 swoop-right del-400">
            <div class="exec-card h-100">
                <div class="card-xl-title"><i class="fas fa-balance-scale text-warning me-2"></i> Realisasi Komposisi Belanja</div>
                <div class="row text-center h-100 align-items-center mt-2">
                    <div class="col-6 border-end pb-2">
                        <div class="chart-container h-150 position-relative mx-auto" style="width:130px;">
                            <canvas id="cOps"></canvas>
                        </div>
                        <div class="mt-2">
                            <span class="badge bg-primary rounded-pill mb-1 shadow-sm px-2 text-white" style="font-size: 9px;">OPERASIONAL (70%)</span>
                            <div class="small text-muted fw-bold" style="font-size:9px;" id="txt_ops_pagu">Pagu: <?= fRp($ops_pagu) ?></div>
                        </div>
                    </div>
                    <div class="col-6 pb-2">
                        <div class="chart-container h-150 position-relative mx-auto" style="width:130px;">
                            <canvas id="cDev"></canvas>
                        </div>
                        <div class="mt-2">
                            <span class="badge bg-warning text-dark rounded-pill mb-1 shadow-sm px-2" style="font-size: 9px;">PENGEMBANGAN (30%)</span>
                            <div class="small text-muted fw-bold" style="font-size:9px;" id="txt_dev_pagu">Pagu: <?= fRp($dev_pagu) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row g-3 mb-5">
        <!-- Top Expense Bar -->
        <div class="col-xl-6 swoop-left del-500">
            <div class="exec-card h-100 d-flex flex-column">
                <div class="card-xl-title"><i class="fas fa-sort-amount-down text-danger me-2"></i> Top 5 Pengeluaran Operasional</div>
                <div class="chart-container h-200"><canvas id="cTop"></canvas></div>
            </div>
        </div>
        
        <!-- Piutang Mhs DENGAN KARTU STATUS -->
        <div class="col-xl-6 swoop-right del-500">
            <div class="exec-card h-100 d-flex flex-column">
                <div class="card-xl-title d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 border-0">
                    <span><i class="fas fa-graduation-cap text-success me-2"></i> Piutang Prodi & Status Mhs</span>
                    
                    <div class="d-flex align-items-center gap-2">
                        <?php $master_prodi_list = $conn->query("SELECT id, nama_prodi FROM mhs_prodi ORDER BY nama_prodi ASC")->fetch_all(MYSQLI_ASSOC); ?>
                        <select form="mainFilterForm" id="prodiSelect" name="prodi" class="form-select form-select-sm border-0 bg-light fw-bold shadow-sm rounded-3 text-primary" style="width: auto; max-width: 140px; font-size: 11px;" onchange="applyFilter(event)">
                            <option value="">Semua Prodi</option>
                            <?php foreach($master_prodi_list as $p) echo "<option value='{$p['id']}' ".($f_prodi==$p['id']?'selected':'').">{$p['nama_prodi']}</option>"; ?>
                        </select>
                        
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1" style="font-size: 10px;">
                            <i class="fas fa-users me-1"></i> <span id="txt_mhs_total"><?= number_format($data['mhs_total']) ?> Mhs</span>
                        </span>
                    </div>
                </div>
                
                <div class="d-flex gap-2 mb-3">
                    <div class="mhs-stat-box" style="background-color: #dcfce7; border-color: #86efac; color: #166534;">
                        <div class="mhs-stat-val" id="txt_mhs_lunas"><?= number_format($data['mhs_status']['lunas']) ?></div>
                        <div class="small fw-bold text-uppercase" style="font-size: 9px;">Lunas (<span id="txt_mhs_lunas_pct"><?= round($pct_mhs_lunas, 1) ?></span>%)</div>
                    </div>
                    <div class="mhs-stat-box" style="background-color: #fef9c3; border-color: #fde047; color: #854d0e;">
                        <div class="mhs-stat-val" id="txt_mhs_mencicil"><?= number_format($data['mhs_status']['mencicil']) ?></div>
                        <div class="small fw-bold text-uppercase" style="font-size: 9px;">Mencicil (<span id="txt_mhs_mencicil_pct"><?= round($pct_mhs_cicil, 1) ?></span>%)</div>
                    </div>
                    <div class="mhs-stat-box" style="background-color: #fee2e2; border-color: #fca5a5; color: #991b1b;">
                        <div class="mhs-stat-val" id="txt_mhs_belum"><?= number_format($data['mhs_status']['belum']) ?></div>
                        <div class="small fw-bold text-uppercase" style="font-size: 9px;">Nunggak (<span id="txt_mhs_belum_pct"><?= round($pct_mhs_belum, 1) ?></span>%)</div>
                    </div>
                </div>

                <div class="chart-container h-150"><canvas id="cProdi"></canvas></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Live Clock & Hijri Widget
    function updateClock() {
        const now = new Date();
        const days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        
        document.getElementById('liveClock').innerText = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0') + ':' + String(now.getSeconds()).padStart(2,'0');
        document.getElementById('liveDate').innerText = days[now.getDay()] + ', ' + now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
        
        try {
            const hijriFormatter = new Intl.DateTimeFormat('id-ID-u-ca-islamic', {day: 'numeric', month: 'long', year:'numeric'});
            let resHijri = hijriFormatter.format(now);
            resHijri = resHijri.replace(/ H/g, '').replace(/ AH/g, '') + ' H';
            document.getElementById('liveHijri').innerText = resHijri;
        } catch(e) { document.getElementById('liveHijri').innerText = ''; }
    }
    setInterval(updateClock, 1000); updateClock();

    const fmtRupiah = (num) => 'Rp ' + new Intl.NumberFormat('id-ID').format(num);

    let chartInstances = {};
    function safeRenderChart(canvasId, config) {
        let existingChart = Chart.getChart(canvasId);
        if (existingChart) { existingChart.destroy(); }
        chartInstances[canvasId] = new Chart(document.getElementById(canvasId), config);
    }

    // 🚀 THE SEAMLESS AJAX FILTER ENGINE
    function applyFilter(e) {
        if(e) e.preventDefault();
        const wrapper = document.getElementById('exec_dashboard_body');
        wrapper.style.opacity = '0.4';

        const form = document.getElementById('mainFilterForm');
        const formData = new FormData(form);
        
        const prodiSelect = document.getElementById('prodiSelect');
        if (prodiSelect && !formData.has('prodi')) {
            formData.append('prodi', prodiSelect.value);
        }

        const params = new URLSearchParams(formData);
        params.append('ajax', '1');

        fetch('index.php?' + params.toString())
            .then(res => res.json())
            .then(data => {
                document.getElementById('txt_saldo_kas').innerText = data.saldo_kas;
                document.getElementById('txt_real_pendapatan').innerText = data.realisasi_pendapatan;
                document.getElementById('txt_pagu_pendapatan').innerText = 'Target Pagu: ' + data.pagu_pendapatan;
                document.getElementById('txt_real_belanja').innerText = data.realisasi_belanja;
                document.getElementById('txt_pagu_belanja').innerText = 'Batas Pagu: ' + data.pagu_belanja;
                
                const surplusEl = document.getElementById('txt_surplus');
                surplusEl.innerText = data.surplus;
                surplusEl.className = 'sum-val ' + data.surplus_color;

                document.getElementById('txt_health_status').innerText = 'Status Keamanan: ' + data.ai_health_status;
                document.getElementById('txt_ai_score').innerText = 'Skor Aman: ' + data.ai_health_index + '/100';
                document.getElementById('txt_ai_score').className = 'badge mt-1 border border-light border-opacity-25 py-1 px-2 text-white bg-' + data.ai_health_badge;
                document.getElementById('txt_ai_ringkasan').innerText = '"' + data.ai_health_ringkasan + '"';
                document.getElementById('txt_ai_list').innerHTML = data.ai_list_html;

                document.getElementById('txt_pct_pend').innerText = data.pct_pendapatan + '%';
                document.getElementById('bar_pct_pend').style.width = Math.min(100, data.pct_pendapatan) + '%';
                document.getElementById('txt_pct_bel').innerText = data.pct_belanja + '%';
                document.getElementById('bar_pct_bel').style.width = Math.min(100, data.pct_belanja) + '%';
                document.getElementById('txt_pct_piut').innerText = data.pct_piutang + '%';
                document.getElementById('bar_pct_piut').style.width = Math.min(100, data.pct_piutang) + '%';
                document.getElementById('txt_sisa_belanja').innerText = data.sisa_anggaran;
                document.getElementById('txt_sisa_pendapatan').innerText = data.sisa_pendapatan;

                document.getElementById('txt_health_big').innerText = data.ai_health_index;
                document.getElementById('txt_health_big').className = 'fw-bold mt-2 mb-0 text-' + data.ai_health_badge;
                document.getElementById('txt_aset_total').innerText = data.aset_total;
                document.getElementById('txt_ops_pagu').innerText = 'Pagu: ' + data.ops_pagu_str;
                document.getElementById('txt_dev_pagu').innerText = 'Pagu: ' + data.dev_pagu_str;

                document.getElementById('txt_mhs_total').innerText = data.mhs_total + ' Mhs';
                document.getElementById('txt_mhs_lunas').innerText = data.mhs_lunas;
                document.getElementById('txt_mhs_lunas_pct').innerText = data.pct_mhs_lunas;
                document.getElementById('txt_mhs_mencicil').innerText = data.mhs_mencicil;
                document.getElementById('txt_mhs_mencicil_pct').innerText = data.pct_mhs_cicil;
                document.getElementById('txt_mhs_belum').innerText = data.mhs_belum;
                document.getElementById('txt_mhs_belum_pct').innerText = data.pct_mhs_belum;

                if (chartInstances['cHealth']) {
                    chartInstances['cHealth'].data.datasets[0].data = [data.ai_health_index, 100 - data.ai_health_index];
                    const gaugeColor = data.ai_health_index > 80 ? '#10b981' : (data.ai_health_index > 50 ? '#f59e0b' : '#ef4444');
                    chartInstances['cHealth'].data.datasets[0].backgroundColor = [gaugeColor, '#f1f5f9'];
                    chartInstances['cHealth'].update();
                }

                if (chartInstances['cForecast']) {
                    chartInstances['cForecast'].data.labels = data.lbl_trend;
                    chartInstances['cForecast'].data.datasets[0].data = data.val_trend_in;
                    chartInstances['cForecast'].data.datasets[1].data = data.val_trend_out;
                    chartInstances['cForecast'].data.datasets[2].data = data.val_forecast;
                    chartInstances['cForecast'].update();
                }

                if (chartInstances['cOps']) {
                    chartInstances['cOps'].data.datasets[0].data = [data.ops_real, data.sisa_ops];
                    chartInstances['cOps'].update();
                }

                if (chartInstances['cDev']) {
                    chartInstances['cDev'].data.datasets[0].data = [data.dev_real, data.sisa_dev];
                    chartInstances['cDev'].update();
                }

                if (chartInstances['cTop']) {
                    chartInstances['cTop'].data.labels = data.lbl_top;
                    chartInstances['cTop'].data.datasets[0].data = data.val_top;
                    chartInstances['cTop'].update();
                }

                if (chartInstances['cProdi']) {
                    chartInstances['cProdi'].data.labels = data.lbl_prodi;
                    chartInstances['cProdi'].data.datasets[0].data = data.val_prodi_real;
                    chartInstances['cProdi'].data.datasets[1].data = data.val_prodi_target;
                    chartInstances['cProdi'].update();
                }

                setTimeout(() => { wrapper.style.opacity = '1'; }, 150);
            })
            .catch(err => { form.submit(); });
    }

    document.addEventListener("DOMContentLoaded", function() {
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.color = '#64748b';
        Chart.defaults.font.weight = 'bold';

        const score = <?= $ai_insights['health']['index'] ?>;
        const gaugeColor = score > 80 ? '#10b981' : (score > 50 ? '#f59e0b' : '#ef4444');
        safeRenderChart('cHealth', {
            type: 'doughnut',
            data: { labels: ['Skor', 'Gap'], datasets: [{ data: [score, 100 - score], backgroundColor: [gaugeColor, '#f1f5f9'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, circumference: 180, rotation: -90, cutout: '85%', plugins: { legend: { display: false }, tooltip: { enabled: false } } }
        });

        safeRenderChart('cForecast', {
            type: 'line',
            data: {
                labels: <?= json_encode($lbl_trend) ?>,
                datasets: [
                    { label: 'Realisasi Pendapatan', data: <?= json_encode($val_trend_in) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', tension: 0.4, fill: true, borderWidth: 3, pointRadius: 4 },
                    { label: 'Realisasi Belanja', data: <?= json_encode($val_trend_out) ?>, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.05)', tension: 0.4, fill: true, borderWidth: 3, pointRadius: 4 },
                    { label: 'Proyeksi Belanja (Forecast)', data: <?= json_encode($val_forecast) ?>, borderColor: '#f59e0b', borderDash: [5, 5], tension: 0.4, fill: false, borderWidth: 2, pointRadius: 0 }
                ]
            },
            options: { 
                responsive: true, maintainAspectRatio: false, 
                plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 } } }, tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + fmtRupiah(ctx.raw); } } } }, 
                scales: { 
                    y: { beginAtZero: true, suggestedMax: 1000000, grid: {color: '#f8fafc'}, ticks: { font:{size:10}, callback: function(val) { return 'Rp ' + (val/1000000) + ' Jt'; } } }, 
                    x: { grid: {display: false}, ticks: {font:{size:10}} } 
                } 
            }
        });

        safeRenderChart('cOps', {
            type: 'doughnut',
            data: { labels: ['Realisasi Ops', 'Sisa Pagu Ops'], datasets: [{ data: [<?= $ops_real ?>, <?= $sisa_ops ?>], backgroundColor: ['#3b82f6', '#e2e8f0'], borderWidth: 0, hoverOffset: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '75%', plugins: { legend: { display: false }, tooltip: { bodyFont: { size: 12 }, padding: 10, callbacks: { label: function(ctx) { let total = ctx.chart._metasets[ctx.datasetIndex].total || 1; return [ctx.label + ':', fmtRupiah(ctx.raw), 'Rasio: ' + ((ctx.raw / total) * 100).toFixed(1) + '%']; } } } } }
        });

        safeRenderChart('cDev', {
            type: 'doughnut',
            data: { labels: ['Realisasi Dev', 'Sisa Pagu Dev'], datasets: [{ data: [<?= $dev_real ?>, <?= $sisa_dev ?>], backgroundColor: ['#f59e0b', '#e2e8f0'], borderWidth: 0, hoverOffset: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '75%', plugins: { legend: { display: false }, tooltip: { bodyFont: { size: 12 }, padding: 10, callbacks: { label: function(ctx) { let total = ctx.chart._metasets[ctx.datasetIndex].total || 1; return [ctx.label + ':', fmtRupiah(ctx.raw), 'Rasio: ' + ((ctx.raw / total) * 100).toFixed(1) + '%']; } } } } }
        });

        safeRenderChart('cTop', {
            type: 'bar',
            data: { labels: <?= json_encode($lbl_top) ?>, datasets: [{ label: 'Total Rp', data: <?= json_encode($val_top) ?>, backgroundColor: '#f43f5e', borderRadius: 6 }] },
            options: { 
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, 
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { let total = ctx.dataset.data.reduce((a, b) => a + Number(b), 0) || 1; return `Terserap: ${fmtRupiah(ctx.raw)} (${((ctx.raw / total) * 100).toFixed(1)}% dari Top 5)`; } } } }, 
                scales: { 
                    x: { display: false, grid: {display:false}, suggestedMax: 1000000 }, 
                    y:{ grid: {display:false}, ticks: {font:{size:10}} } 
                } 
            }
        });

        safeRenderChart('cProdi', {
            type: 'bar',
            data: {
                labels: <?= json_encode($lbl_prodi) ?>,
                datasets: [
                    { label: 'Telah Dibayar', data: <?= json_encode($val_prodi_real) ?>, backgroundColor: '#10b981', borderRadius: 4 },
                    { label: 'Target Piutang', data: <?= json_encode($val_prodi_target) ?>, backgroundColor: '#cbd5e1', borderRadius: 4 }
                ]
            },
            options: { 
                responsive: true, maintainAspectRatio: false, 
                plugins: { 
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }, 
                    tooltip: { callbacks: { label: function(ctx) { let label = ctx.dataset.label || ''; let val = ctx.raw || 0; let idx = ctx.dataIndex; let pctText = ''; if (label === 'Telah Dibayar') { let target = ctx.chart.data.datasets[1].data[idx] || 1; let pct = target > 0 ? ((val / target) * 100).toFixed(1) : 0; pctText = ` (${pct}% dari Target)`; } else if (label === 'Target Piutang') { let dibayar = ctx.chart.data.datasets[0].data[idx] || 0; let pct = val > 0 ? ((dibayar / val) * 100).toFixed(1) : 0; pctText = ` (Terkumpul: ${pct}%)`; } return `${label}: ${fmtRupiah(val)}${pctText}`; } } } 
                }, 
                scales: { 
                    y: { display: false, grid:{display:false}, suggestedMax: 1000000 }, 
                    x: {grid:{display:false}, ticks: {font:{size:10}}} 
                } 
            }
        });
    });
</script>