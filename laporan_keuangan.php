<?php
/**
 * laporan_keuangan.php - PUSAT GATEWAY LAPORAN TERPADU
 * Versi: 108.0 (Grand Master - Dynamic Tab Hider Edition)
 * Perbaikan:
 * Dynamic Tab Hider: Menghitung izin akses sebelum merender Tab. Jika tidak ada 
 * satupun menu yang diizinkan di dalam sebuah Tab, Tab tersebut akan disembunyikan total!
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$active_tab_param = $_GET['tab'] ?? 'keuangan';
$role_id_now = (int)($_SESSION['role_id'] ?? 0);

// 🚀 FUNGSI PENGECEK HAK AKSES KARTU (RBAC GATEKEEPER)
if (!function_exists('canViewReport')) {
    function canViewReport($menu_key, $role_id) {
        if ($role_id === 1) return true; // Superadmin Bypass
        global $current_permissions;
        return isset($current_permissions[$menu_key]) && (int)$current_permissions[$menu_key]['can_view'] === 1;
    }
}

// 🚀 DAFTAR MENU PER TAB
$menus1 = [
    ['title' => 'Posisi Keuangan', 'desc' => 'Neraca Institusi: Analisis aset, liabilitas, dan ekuitas bersih.', 'icon' => 'fa-balance-scale', 'bg' => 'bg-primary', 'link' => 'laporan_posisi_keuangan'],
    ['title' => 'Laporan Aktivitas', 'desc' => 'Laba Rugi Nirlaba: Pantau surplus/defisit pendapatan operasional.', 'icon' => 'fa-chart-line', 'bg' => 'bg-success', 'link' => 'laporan_aktivitas'],
    ['title' => 'Perubahan Aset Neto', 'desc' => 'Rekonsiliasi Ekuitas: Lacak dana terikat & tidak terikat.', 'icon' => 'fa-arrow-up-right-dots', 'bg' => 'bg-danger', 'link' => 'laporan_perubahan_aset_neto'],
    ['title' => 'Laporan Arus Kas', 'desc' => 'Aliran Kas Riil: Detail arus masuk & keluar operasional.', 'icon' => 'fa-money-bill-transfer', 'bg' => 'bg-warning', 'link' => 'laporan_kas_detail'],
    ['title' => 'Catatan Laporan (CALK)', 'desc' => 'Pusat Entri Deskripsi dan Rincian Detail Standar Akuntansi.', 'icon' => 'fa-book-open', 'bg' => 'bg-info', 'link' => 'laporan_calk'],
    ['title' => 'Konsolidasi Laporan Full', 'desc' => 'Gabungkan & Download Laporan Keuangan Lengkap (PDF).', 'icon' => 'fa-file-pdf', 'bg' => 'bg-dark', 'link' => 'generate_laporan']
];

$menus2 = [
    ['title' => 'Buku Besar Detail', 'desc' => 'General Ledger: Penelusuran mutasi harian per akun COA.', 'icon' => 'fa-book', 'bg' => 'bg-dark', 'link' => 'laporan_buku_besar'],
    ['title' => 'Ringkasan Kas', 'desc' => 'Rekapitulasi penerimaan dan pengeluaran kas/bank.', 'icon' => 'fa-file-invoice-dollar', 'bg' => 'bg-info', 'link' => 'laporan_kas_summary'],
    ['title' => 'Tagihan & Piutang', 'desc' => 'Audit saldo piutang mahasiswa dan status penagihan.', 'icon' => 'fa-user-clock', 'bg' => 'bg-secondary', 'link' => 'laporan_piutang_mhs']
];

$menus3 = [
    ['title' => 'Laporan Aset', 'desc' => 'Audit Inventaris: Analisis perolehan, penyusutan, dan nilai buku.', 'icon' => 'fa-boxes-stacked', 'bg' => 'bg-info', 'link' => 'laporan_perubahan_aset'],
    ['title' => 'Neraca Saldo', 'desc' => 'Trial Balance: Keseimbangan debet dan kredit seluruh akun.', 'icon' => 'fa-scale-balanced', 'bg' => 'bg-dark', 'link' => 'neraca_saldo'],
    ['title' => 'Laporan Gaji Pegawai', 'desc' => 'Rincian biaya SDM per komponen, audit jurnal, dan slip gaji terintegrasi.', 'icon' => 'fa-file-invoice-dollar', 'bg' => 'bg-primary', 'link' => 'hr_laporan_gaji'],
    ['title' => 'Riwayat Aktivitas Sistem', 'desc' => 'Audit Trail Sentral: Rekam jejak seluruh aktivitas sistem ERP.', 'icon' => 'fa-history', 'bg' => 'bg-warning text-dark', 'link' => 'riwayat_sistem']
];

// 🚀 ENGINE MENGHITUNG IZIN TAB
$rendered1 = 0; foreach($menus1 as $m) { if(canViewReport($m['link'], $role_id_now)) $rendered1++; }
$rendered2 = 0; foreach($menus2 as $m) { if(canViewReport($m['link'], $role_id_now)) $rendered2++; }
$rendered3 = 0; foreach($menus3 as $m) { if(canViewReport($m['link'], $role_id_now)) $rendered3++; }

// AUTO-SELECT TAB YANG AKTIF (Bila Tab saat ini kosong, pindah ke Tab sebelahnya)
if ($active_tab_param == 'keuangan' && $rendered1 == 0) {
    if ($rendered2 > 0) $active_tab_param = 'transaksi'; elseif ($rendered3 > 0) $active_tab_param = 'asset';
} elseif ($active_tab_param == 'transaksi' && $rendered2 == 0) {
    if ($rendered1 > 0) $active_tab_param = 'keuangan'; elseif ($rendered3 > 0) $active_tab_param = 'asset';
} elseif ($active_tab_param == 'asset' && $rendered3 == 0) {
    if ($rendered1 > 0) $active_tab_param = 'keuangan'; elseif ($rendered2 > 0) $active_tab_param = 'transaksi';
}
?>

<style>
    .nav-tabs-executive { border-bottom: 2px solid #f1f5f9; gap: 10px; }
    .nav-tabs-executive .nav-link { 
        border: none; color: #94a3b8; font-weight: 700; padding: 14px 28px; 
        border-radius: 15px 15px 0 0; transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        font-size: 0.95rem; background: transparent; position: relative;
    }
    .nav-tabs-executive .nav-link:hover { color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), 0.05); }
    .nav-tabs-executive .nav-link.active { color: var(--bs-primary); background: #fff; }
    .nav-tabs-executive .nav-link.active::after {
        content: ""; position: absolute; bottom: -2px; left: 0; right: 0;
        height: 3px; background: var(--bs-primary); border-radius: 10px;
    }

    .report-card { 
        border: 1px solid #f1f5f9; border-radius: 20px; transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        background: #fff; text-decoration: none !important; display: flex; 
        flex-direction: column; height: 100%; position: relative; overflow: hidden; padding: 28px !important;
    }
    .report-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08); border-color: var(--bs-primary); }
    .report-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px; transition: 0.3s; }
    .report-card:hover .report-icon { transform: rotate(-5deg) scale(1.1); }
    .card-title { color: #0f172a; font-weight: 800; font-size: 1.1rem; margin-bottom: 10px; }
    .card-desc { font-size: 0.88rem; color: #64748b; line-height: 1.55; }
    
    .btn-open-module { margin-top: 25px; font-size: 0.8rem; font-weight: 800; color: var(--bs-primary); text-transform: uppercase; display: flex; align-items: center; letter-spacing: 1px; }
    .btn-open-module i { transition: 0.3s; font-size: 0.7rem; }
    .report-card:hover .btn-open-module i { transform: translateX(6px); }
    
    .header-gradient { background: linear-gradient(135deg, #fff 0%, #f8fafc 100%); }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4 header-gradient">
        <div>
            <h4 class="fw-bold text-dark mb-1"><i class="fas fa-chart-pie me-2 text-primary"></i>Executive Report Center</h4>
            <p class="text-muted mb-0 small fw-bold">Monitoring Finansial, Aset, CALK & Jejak Audit (Standar ISAK 35)</p>
        </div>
    </div>

    <!-- 🚀 TAB MENU YANG HILANG OTOMATIS JIKA KOSONG -->
    <ul class="nav nav-tabs nav-tabs-executive no-print mb-4" id="reportTab" role="tablist">
        <?php if($rendered1 > 0): ?>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab_param=='keuangan'?'active':'' ?>" id="keuangan-tab" data-bs-toggle="tab" data-bs-target="#tab-keuangan" type="button" role="tab">
                <i class="fas fa-university me-2"></i>Laporan Keuangan
            </button>
        </li>
        <?php endif; ?>
        
        <?php if($rendered2 > 0): ?>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab_param=='transaksi'?'active':'' ?>" id="transaksi-tab" data-bs-toggle="tab" data-bs-target="#tab-transaksi" type="button" role="tab">
                <i class="fas fa-receipt me-2"></i>Transaksi & Audit
            </button>
        </li>
        <?php endif; ?>
        
        <?php if($rendered3 > 0): ?>
        <li class="nav-item">
            <button class="nav-link <?= $active_tab_param=='asset'?'active':'' ?>" id="asset-tab" data-bs-toggle="tab" data-bs-target="#tab-asset" type="button" role="tab">
                <i class="fas fa-shield-halved me-2"></i>Asset & Monitoring
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <?php if ($rendered1 == 0 && $rendered2 == 0 && $rendered3 == 0): ?>
        <div class="card border-0 shadow-sm rounded-4 bg-white p-5 text-center">
            <i class="fas fa-file-excel fa-4x text-muted opacity-25 mb-3"></i>
            <h5 class="fw-bold text-dark">Tidak Ada Laporan Tersedia</h5>
            <p class="text-muted small">Akun Anda tidak memiliki izin untuk melihat satupun laporan di sistem ini.</p>
        </div>
    <?php else: ?>
        <div class="tab-content mt-4" id="reportTabContent">
            <!-- TAB 1: KEUANGAN -->
            <?php if($rendered1 > 0): ?>
            <div class="tab-pane fade <?= $active_tab_param=='keuangan'?'show active':'' ?>" id="tab-keuangan" role="tabpanel">
                <div class="row g-4">
                    <?php foreach($menus1 as $m): if (!canViewReport($m['link'], $role_id_now)) continue; ?>
                        <div class="col-md-6 col-lg-4">
                            <a href="index.php?page=<?= $m['link'] ?>" class="report-card">
                                <div class="report-icon <?= $m['bg'] ?>"><i class="fas <?= $m['icon'] ?> fa-2x text-white"></i></div>
                                <h5 class="card-title"><?= $m['title'] ?></h5>
                                <p class="card-desc"><?= $m['desc'] ?></p>
                                <div class="btn-open-module mt-auto">BUKA MENU <i class="fas fa-arrow-right ms-2"></i></div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- TAB 2: TRANSAKSI & AUDIT -->
            <?php if($rendered2 > 0): ?>
            <div class="tab-pane fade <?= $active_tab_param=='transaksi'?'show active':'' ?>" id="tab-transaksi" role="tabpanel">
                <div class="row g-4">
                    <?php foreach($menus2 as $m): if (!canViewReport($m['link'], $role_id_now)) continue; ?>
                        <div class="col-md-6 col-lg-4">
                            <a href="index.php?page=<?= $m['link'] ?>" class="report-card">
                                <div class="report-icon <?= $m['bg'] ?>"><i class="fas <?= $m['icon'] ?> fa-2x text-white"></i></div>
                                <h5 class="card-title"><?= $m['title'] ?></h5>
                                <p class="card-desc"><?= $m['desc'] ?></p>
                                <div class="btn-open-module mt-auto">BUKA MENU <i class="fas fa-arrow-right ms-2"></i></div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- TAB 3: ASSET & MONITORING -->
            <?php if($rendered3 > 0): ?>
            <div class="tab-pane fade <?= $active_tab_param=='asset'?'show active':'' ?>" id="tab-asset" role="tabpanel">
                <div class="row g-4">
                    <?php foreach($menus3 as $m): if (!canViewReport($m['link'], $role_id_now)) continue; ?>
                        <div class="col-md-6 col-lg-3">
                            <a href="index.php?page=<?= $m['link'] ?>" class="report-card">
                                <div class="report-icon <?= $m['bg'] ?>"><i class="fas <?= $m['icon'] ?> fa-2x <?= strpos($m['bg'], 'text-dark') ? 'text-dark' : 'text-white' ?>"></i></div>
                                <h5 class="card-title"><?= $m['title'] ?></h5>
                                <p class="card-desc"><?= $m['desc'] ?></p>
                                <div class="btn-open-module mt-auto">BUKA MENU <i class="fas fa-arrow-right ms-2"></i></div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>