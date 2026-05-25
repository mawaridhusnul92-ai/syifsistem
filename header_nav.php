<?php
/**
 * header_nav.php - NAVIGATION ENGINE SYIFA ERP
 * Versi: 137.0 (Grand Master - Repositioned Online Indicator)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan: Memindahkan titik indikator Online/Offline ke bawah 
 * nama pengguna (berdampingan dengan Role).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$uid_nav = $_SESSION['user_id'] ?? 0;

if (isset($_GET['read_notif'])) {
    $nid = (int)$_GET['read_notif'];
    if (isset($conn)) { $conn->query("UPDATE syifa_notifications SET is_read = 1, status = 'read' WHERE id = $nid"); }
    $goto = !empty($_GET['goto']) ? urldecode($_GET['goto']) : 'index.php?page=dashboard';
    echo "<script>window.location.href='$goto';</script>"; exit;
}

if (isset($_GET['read_all_notif'])) {
    if (isset($conn)) { $conn->query("UPDATE syifa_notifications SET is_read = 1, status = 'read' WHERE user_id = $uid_nav AND is_read = 0"); }
    $goto = !empty($_GET['goto']) ? urldecode($_GET['goto']) : 'index.php?page=dashboard';
    echo "<script>window.location.href='$goto';</script>"; exit;
}

$unread_notifs = []; $unread_count = 0; $user_avatar = "";

if (isset($conn) && $uid_nav > 0) {
    $res_n = $conn->query("SELECT * FROM syifa_notifications WHERE user_id = $uid_nav AND is_read = 0 ORDER BY created_at DESC LIMIT 15");
    if ($res_n) { $unread_notifs = $res_n->fetch_all(MYSQLI_ASSOC); $unread_count = count($unread_notifs); }
    
    try {
        $u_nav_data = $conn->query("SELECT avatar FROM users WHERE id = $uid_nav")->fetch_assoc();
        if (!empty($u_nav_data['avatar'])) { $user_avatar = "assets/img/avatars/" . $u_nav_data['avatar']; }
    } catch(Exception $e) {}
}

$userName = $_SESSION['name'] ?? 'User System';
$userRole = $_SESSION['role_name'] ?? 'Guest'; 
$roleId   = (int)($_SESSION['role_id'] ?? 0);
$is_superadmin = ($roleId === 1);
$display_role  = $is_superadmin ? 'SUPERADMIN (ROOT)' : strtoupper($userRole);
$badge_color   = $is_superadmin ? 'text-primary' : 'text-success';
$icon_role     = $is_superadmin ? 'fa-shield-alt' : 'fa-user-tie';

$page_active = $_GET['page'] ?? 'dashboard';
$breadcrumbs = [];
$current_title = "Dashboard";

switch ($page_active) {
    case 'akun_kas': case 'transaksi_kas': case 'jurnal': case 'kode_akun': case 'ringkasan': case 'transaksi_unit':
        $breadcrumbs[] = ['title' => 'Keuangan & Kas', 'url' => 'index.php?page=ringkasan'];
        $current_title = match($page_active) {
            'akun_kas'      => 'Manajemen Rekening Kas & Bank',
            'transaksi_kas' => 'Transaksi Kas',
            'transaksi_unit'=> 'Transaksi Kas Unit',
            'jurnal'        => 'Jurnal Akuntansi Umum & AJP',
            'kode_akun'     => 'Chart of Accounts (COA)',
            'ringkasan'     => 'Executive Summary Keuangan',
            default         => 'Keuangan'
        }; break;
    
    case 'rapb': case 'anggaran_pendapatan': case 'anggaran_belanja': case 'anggaran_pengeluaran': case 'anggaran_unit': case 'laporan_bendahara':
        $breadcrumbs[] = ['title' => 'Anggaran (RAPB)', 'url' => 'index.php?page=rapb'];
        $current_title = match($page_active) {
            'rapb'                 => 'Dashboard RAPB',
            'anggaran_pendapatan'  => 'Anggaran Pendapatan',
            'anggaran_belanja'     => 'Anggaran Belanja', 
            'anggaran_unit'        => 'Anggaran Unit Kerja',
            'laporan_bendahara'    => 'Laporan Bendahara & Realisasi SPM',
            default                => 'Manajemen Anggaran'
        }; break;
        
    case 'laporan_keuangan': case 'laporan_posisi_keuangan': case 'laporan_aktivitas': case 'laporan_kas_detail': case 'laporan_kas_summary': case 'laporan_buku_besar': case 'neraca_saldo': case 'laporan_piutang_mhs': case 'laporan_perubahan_aset': case 'laporan_perubahan_aset_neto': case 'periode_setting': case 'hr_laporan_gaji': case 'arsip_dokumen': case 'laporan_calk': case 'generate_laporan':
        $breadcrumbs[] = ['title' => 'Laporan Keuangan', 'url' => 'index.php?page=laporan_keuangan'];
        $current_title = match($page_active) {
            'laporan_posisi_keuangan'     => 'Neraca / Posisi Keuangan',
            'laporan_aktivitas'           => 'Laporan Aktivitas',
            'laporan_kas_detail'          => 'Laporan Arus Kas',
            'laporan_kas_summary'         => 'Ringkasan Penerimaan & Pembayaran',
            'laporan_buku_besar'          => 'Audit Buku Besar Detail',
            'neraca_saldo'                => 'Neraca Saldo & Rekonsiliasi',
            'laporan_piutang_mhs'         => 'Laporan Tagihan & Piutang',
            'laporan_perubahan_aset'      => 'Mutasi Aset Tetap',
            'laporan_perubahan_aset_neto' => 'Laporan Perubahan Aset Neto',
            'laporan_calk'                => 'Catatan Atas Laporan Keuangan',
            'generate_laporan'            => 'Konsolidasi & Cetak Laporan',
            'periode_setting'             => 'Konfigurasi Periode Laporan',
            'arsip_dokumen'               => 'Pusat Arsip Dokumen Digital',
            default                       => 'Executive Report Center'
        }; break;
    case 'mahasiswa': case 'mhs_pembayaran': case 'mhs_tarif': case 'tagihan_generate': case 'tagihan_monitoring': case 'mhs_setting':
        $breadcrumbs[] = ['title' => 'Kemahasiswaan', 'url' => 'index.php?page=mahasiswa'];
        $current_title = match($page_active) {
            'mhs_pembayaran'     => 'Terminal Kasir & Pembayaran',
            'mhs_tarif'          => 'Konfigurasi Tarif Akademik',
            'tagihan_generate'   => 'Generate Tagihan Billing Matrix',
            'tagihan_monitoring' => 'Monitoring & Kontrol Piutang',
            'mhs_setting'        => 'Master Data & Pengaturan Mhs',
            default              => 'Database Mahasiswa'
        }; break;
    case 'pegawai': case 'hr_payroll_setup': case 'penggajian':
        $breadcrumbs[] = ['title' => 'Kepegawaian (HRIS)', 'url' => 'index.php?page=pegawai'];
        $current_title = match($page_active) {
            'hr_payroll_setup' => 'Setup Gaji & Komponen Pegawai',
            'penggajian'       => 'Proses Payroll & Gaji Bulanan',
            default            => 'Database Pegawai Aktif'
        }; break;
    case 'aset_manajemen': $current_title = "Manajemen Aset & Inventaris"; break;
    case 'user_management': case 'pengaturan_sistem': case 'user_profile': case 'riwayat_sistem':
        $current_title = match($page_active) {
            'user_management'   => 'Manajemen Akses & Hak Role',
            'pengaturan_sistem' => 'Parameter & Konfigurasi Sistem',
            'user_profile'      => 'Pengaturan Akun & Profil Saya',
            'riwayat_sistem'    => 'Riwayat Aktivitas Sistem',
            default             => 'Pengaturan'
        }; break;
    default: $current_title = "Selamat Datang, " . $userName; break;
}

$appr = null;
if (isset($conn)) { try { $appr = $conn->query("SELECT * FROM sys_appearance WHERE id=1")->fetch_assoc(); } catch(Exception $e){} }
if (!$appr) { $appr = ['font_family'=>"'Inter', sans-serif", 'font_size'=>'13px', 'primary_color'=>'#0d6efd', 'outline_color'=>'#e2e8f0', 'tab_style'=>'modern']; }

$rgb = sscanf($appr['primary_color'], "#%02x%02x%02x");
$primary_rgb = $rgb ? implode(',', $rgb) : '13,110,253';
?>

<style>
    :root { --bs-primary: <?= $appr['primary_color'] ?> !important; --bs-primary-rgb: <?= $primary_rgb ?> !important; --bs-border-color: <?= $appr['outline_color'] ?> !important; }
    body, .form-control, .form-select, .btn, table, .dropdown-menu { font-family: <?= $appr['font_family'] ?> !important; font-size: <?= $appr['font_size'] ?> !important; }
    
    .bg-primary { background-color: var(--bs-primary) !important; color: #fff !important; }
    .text-primary { color: var(--bs-primary) !important; }
    .btn-primary, .badge.bg-primary { background-color: var(--bs-primary) !important; border-color: var(--bs-primary) !important; color: #ffffff !important; }
    .btn-primary:hover { opacity: 0.9; }
    .btn-outline-primary { color: var(--bs-primary) !important; border-color: var(--bs-primary) !important; }
    .btn-outline-primary:hover { background-color: var(--bs-primary) !important; color: #ffffff !important; }
    .border-primary, .border-start, .border-bottom, .border-top, .border-end, .border { border-color: var(--bs-border-color) !important; }

    .bg-primary .text-primary, .bg-primary .text-dark, .bg-primary .text-muted,
    .bg-primary h1, .bg-primary h2, .bg-primary h3, .bg-primary h4, .bg-primary h5, .bg-primary h6 { color: #ffffff !important; opacity: 1 !important; }

    ul.nav-tabs, ul.nav-pills, .btn-group.p-1.bg-light { display: inline-flex !important; flex-wrap: wrap !important; gap: 5px !important; background: transparent !important; border: none !important; border-radius: 0 !important; box-shadow: none !important; padding: 0 !important; margin-bottom: 20px !important; }
    .nav-tabs .nav-link, .nav-pills .nav-link, .nav-pills .nav-item button, .btn-group.p-1.bg-light a.btn { font-family: <?= $appr['font_family'] ?> !important; font-size: <?= $appr['font_size'] ?> !important; color: #64748b !important; background: transparent !important; font-weight: 700 !important; border: none !important; transition: all 0.3s ease !important; text-decoration: none !important; margin: 0 !important; box-shadow: none !important; }

    <?php if($appr['tab_style'] == 'modern' || empty($appr['tab_style'])): ?>
    ul.nav-tabs, ul.nav-pills, .btn-group.p-1.bg-light { border-bottom: 2px solid var(--bs-border-color) !important; width: 100%; }
    .nav-tabs .nav-link, .nav-pills .nav-link, .nav-pills .nav-item button, .btn-group.p-1.bg-light a.btn { padding: 14px 25px !important; border-radius: 12px 12px 0 0 !important; }
    .nav-tabs .nav-link.active, .nav-pills .nav-link.active, .nav-pills .nav-item button.active, .btn-group.p-1.bg-light a.btn.btn-primary { color: var(--bs-primary) !important; border-bottom: 4px solid var(--bs-primary) !important; background: rgba(var(--bs-primary-rgb), 0.08) !important; box-shadow: none !important; }
    .btn-group.p-1.bg-light .btn.btn-primary.text-white, .nav-tabs .nav-link.active.text-white, .nav-pills .nav-link.active.text-white, .nav-pills .nav-item button.active.text-white, .btn-group.p-1.bg-light .btn.btn-primary.text-muted, .nav-tabs .nav-link.active.text-muted, .nav-pills .nav-link.active.text-muted { color: var(--bs-primary) !important; }
    <?php else: ?>
    ul.nav-tabs, ul.nav-pills, .btn-group.p-1.bg-light { border-bottom: none !important; padding: 5px !important; background: #f8fafc !important; border-radius: 50px !important; }
    .nav-tabs .nav-link, .nav-pills .nav-link, .nav-pills .nav-item button, .btn-group.p-1.bg-light a.btn { padding: 10px 25px !important; border-radius: 50px !important; margin: 0 2px !important; }
    .nav-tabs .nav-link.active, .nav-pills .nav-link.active, .nav-pills .nav-item button.active, .btn-group.p-1.bg-light a.btn.btn-primary { background-color: var(--bs-primary) !important; color: #ffffff !important; box-shadow: 0 4px 12px rgba(var(--bs-primary-rgb), 0.3) !important; }
    .btn-group.p-1.bg-light .btn.btn-primary.text-dark, .nav-tabs .nav-link.active.text-dark, .nav-pills .nav-link.active.text-dark, .btn-group.p-1.bg-light .btn.btn-primary.text-white, .nav-tabs .nav-link.active.text-white, .nav-pills .nav-link.active.text-white { color: #ffffff !important; }
    <?php endif; ?>

    .top-navbar { background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(10px); box-shadow: 0 1px 10px rgba(0,0,0,0.03); height: 75px; position: sticky; top: 0; z-index: 1040; }
    .breadcrumb-nav { font-size: 11px; letter-spacing: 0.5px; }
    .breadcrumb-separator { margin: 0 10px; color: #cbd5e1; font-size: 9px; }
    .breadcrumb-active { color: #1e293b; font-weight: 800; }
    
    .user-avatar-box { width: 42px; height: 42px; background: linear-gradient(135deg, var(--bs-primary) 0%, #00d2d3 100%); border: 2px solid #fff; font-size: 1.1rem; overflow: hidden; }
    .user-avatar-box img { width: 100%; height: 100%; object-fit: cover; }
    
    .btn-toggle-sidebar { transition: 0.3s; color: #1e293b; }
    .btn-toggle-sidebar:hover { background: #f1f5f9; border-radius: 10px; }
    .hover-nav { transition: 0.2s; cursor: pointer; }
    .hover-nav:hover { opacity: 0.7; color: var(--bs-primary) !important; text-decoration: underline !important; }

    .notif-bell-container { position: relative; cursor: pointer; padding: 8px; margin-right: 15px; color: #64748b; transition: 0.3s; }
    .notif-bell-container:hover { color: var(--bs-primary); }
    .notif-bell-container i { font-size: 1.6rem; }
    .notif-badge-indicator { position: absolute; top: 2px; right: 2px; background-color: #ef4444; color: white; font-size: 0.65rem; font-weight: 900; padding: 3px 6px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 4px 8px rgba(239, 68, 68, 0.4); }

    @keyframes ringHeavy { 0% { transform: rotate(0); } 10% { transform: rotate(20deg); } 20% { transform: rotate(-20deg); } 30% { transform: rotate(15deg); } 40% { transform: rotate(-15deg); } 50% { transform: rotate(0); } 100% { transform: rotate(0); } }
    .bell-ringing { animation: ringHeavy 2.5s infinite ease-in-out; color: #ef4444 !important; }

    .dropdown-notif-menu { width: 360px; max-height: 450px; overflow-y: auto; padding: 0; border: none; border-radius: 16px; box-shadow: 0 15px 40px rgba(0,0,0,0.15); }
    .notif-item-link { display: block; padding: 15px 20px; border-bottom: 1px solid var(--bs-border-color); text-decoration: none; color: #1e293b; background: #fff; transition: 0.2s; }
    .notif-item-link:hover { background: #f8fafc; }
    .notif-item-title { font-size: 0.9rem; font-weight: 800; color: var(--bs-primary); margin-bottom: 4px; }
    .notif-item-msg { font-size: 0.8rem; color: #475569; line-height: 1.4; }
    .notif-item-time { font-size: 0.65rem; color: #94a3b8; margin-top: 6px; text-align: right; font-weight: bold; }

    /* 🚀 CSS ONLINE/OFFLINE INDICATOR */
    .status-indicator { width: 10px; height: 10px; border-radius: 50%; display: inline-block; box-shadow: 0 0 6px currentColor; transition: 0.3s; margin-right: 6px; }
    .status-indicator.online { background-color: #10b981; color: #10b981; }
    .status-indicator.offline { background-color: #ef4444; color: #ef4444; animation: blinkStatus 1.5s infinite; }
    @keyframes blinkStatus { 50% { opacity: 0.4; } }
</style>

<header class="top-navbar no-print border-bottom d-flex align-items-center px-4">
    <button class="btn btn-toggle-sidebar me-4 p-2 border-0 bg-transparent" id="btnToggleSidebar" title="Tampilkan/Sembunyikan Sidebar">
        <i class="fas fa-indent fa-lg"></i>
    </button>

    <nav class="breadcrumb-nav d-none d-lg-flex align-items-center overflow-hidden">
        <a href="index.php?page=dashboard" class="text-muted text-decoration-none d-flex align-items-center fw-bold hover-nav" title="Home">
            <i class="fas fa-home me-1"></i> HOME
        </a>
        
        <?php foreach ($breadcrumbs as $crumb): ?>
            <span class="breadcrumb-separator"><i class="fas fa-chevron-right"></i></span>
            <?php if (!empty($crumb['url']) && $crumb['url'] !== '#'): ?>
                <a href="<?= $crumb['url'] ?>" class="text-muted text-decoration-none fw-bold hover-nav" title="Ke <?= $crumb['title'] ?>">
                    <?= strtoupper($crumb['title']) ?>
                </a>
            <?php else: ?>
                <span class="text-muted fw-bold"><?= strtoupper($crumb['title']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>

        <span class="breadcrumb-separator"><i class="fas fa-chevron-right"></i></span>
        <span class="breadcrumb-active text-nowrap text-uppercase"><?= $current_title ?></span>
    </nav>

    <div class="ms-auto d-flex align-items-center gap-3">
        <ul class="navbar-nav d-flex align-items-center flex-row gap-3">
            <?php if($roleId == 1 || (function_exists('hasAccess') && hasAccess('riwayat_sistem'))): ?>
            <li class="nav-item animate__animated animate__fadeIn">
                <a class="btn btn-light border-0 shadow-sm rounded-circle d-flex justify-content-center align-items-center" href="index.php?page=riwayat_sistem" title="Riwayat Aktivitas Sistem" style="width: 40px; height: 40px; transition: 0.3s;">
                    <i class="fas fa-history text-primary"></i>
                </a>
            </li>
            <?php endif; ?>

            <?php if($roleId == 1 || (function_exists('hasAccess') && hasAccess('pengaturan_sistem'))): ?>
            <li class="nav-item animate__animated animate__fadeIn">
                <a class="btn btn-light border-0 shadow-sm rounded-circle d-flex justify-content-center align-items-center" href="index.php?page=pengaturan_sistem" title="Pengaturan Sistem" style="width: 40px; height: 40px; transition: 0.3s;">
                    <i class="fas fa-cog text-secondary"></i>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="dropdown ms-1">
            <div class="notif-bell-container" data-bs-toggle="dropdown" aria-expanded="false" title="Pusat Notifikasi">
                <i class="fas fa-bell <?= $unread_count > 0 ? 'bell-ringing' : '' ?>"></i>
                <?php if($unread_count > 0): ?>
                    <span class="notif-badge-indicator animate__animated animate__pulse animate__infinite"><?= $unread_count ?></span>
                <?php endif; ?>
            </div>
            <ul class="dropdown-menu dropdown-menu-end dropdown-notif-menu animate__animated animate__fadeInDown">
                <li class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center sticky-top">
                    <span class="fw-bold text-dark"><i class="fas fa-bell me-2 text-primary"></i>Pusat Notifikasi</span>
                    <span class="badge bg-danger rounded-pill"><?= $unread_count ?> Baru</span>
                </li>
                <?php if($unread_count > 0): foreach($unread_notifs as $n): 
                    $target_url = !empty($n['url']) ? $n['url'] : (!empty($n['action_url']) ? $n['action_url'] : 'index.php?page=dashboard');
                ?>
                    <li>
                        <a href="?read_notif=<?= $n['id'] ?>&goto=<?= urlencode($target_url) ?>" class="notif-item-link">
                            <div class="notif-item-title"><?= htmlspecialchars($n['judul']) ?></div>
                            <div class="notif-item-msg"><?= htmlspecialchars($n['pesan']) ?></div>
                            <div class="notif-item-time"><i class="fas fa-clock me-1"></i><?= date('d M Y, H:i', strtotime($n['created_at'])) ?> WIB</div>
                        </a>
                    </li>
                <?php endforeach; else: ?>
                    <li class="p-5 text-center text-muted small italic">
                        <i class="fas fa-envelope-open fa-3x mb-3 opacity-25 d-block text-secondary"></i>
                        Tidak ada pemberitahuan baru.
                    </li>
                <?php endif; ?>
                
                <?php if($unread_count > 0): ?>
                <li class="border-top p-2 text-center sticky-bottom bg-white" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
                    <a href="?read_all_notif=1&goto=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-light btn-sm text-primary fw-bold w-100 rounded-pill shadow-sm d-block text-decoration-none">
                        <i class="fas fa-check-double me-1"></i> Tandai Semua Sudah Dibaca
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="text-end d-none d-md-block ms-2" style="line-height: 1.2;">
            <div class="small fw-bold text-dark"><?= htmlspecialchars($userName) ?></div>
            <!-- 🚀 THE NETWORK STATUS INDICATOR (DIPINDAH KE BAWAH ROLE) -->
            <div class="text-primary fw-bold text-uppercase d-flex align-items-center justify-content-end mt-1" style="font-size: 9px; letter-spacing: 1px;">
                <span id="network-indicator" class="status-indicator online me-1" style="width: 8px; height: 8px;" title="Status Jaringan Koneksi Sistem"></span>
                <i class="fas <?= $icon_role ?> me-1"></i><?= htmlspecialchars($display_role) ?>
            </div>
        </div>
        
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="user-avatar-box rounded-circle shadow-sm d-flex align-items-center justify-content-center text-white ms-2">
                    <?php if($user_avatar): ?>
                        <img src="<?= $user_avatar ?>" alt="Avatar">
                    <?php else: ?>
                        <?= strtoupper(substr($userName, 0, 1)) ?>
                    <?php endif; ?>
                </div>
            </a>
            <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-4 mt-3 p-2 animate__animated animate__fadeIn">
                <li><a class="dropdown-item rounded-3 py-2 small fw-bold" href="?page=user_profile"><i class="fas fa-user-circle me-2 text-muted"></i>Profil Saya</a></li>
                <?php if($is_superadmin): ?>
                <li><a class="dropdown-item rounded-3 py-2 small fw-bold" href="?page=user_management"><i class="fas fa-key me-2 text-muted"></i>Manajemen Akses</a></li>
                <li><a class="dropdown-item rounded-3 py-2 small fw-bold" href="?page=pengaturan_sistem"><i class="fas fa-cogs me-2 text-muted"></i>Pengaturan Sistem</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item rounded-3 py-2 small text-danger fw-bold" href="index.php?page=logout" onclick="return confirm('Anda yakin ingin keluar dari sistem?')"><i class="fas fa-power-off me-2"></i>KELUAR SISTEM</a></li>
            </ul>
        </div>
    </div>
</header>

<script>
    // 🚀 ENGINE JARINGAN (ONLINE / OFFLINE DETECTOR)
    function updateNetworkStatus() {
        const ind = document.getElementById('network-indicator');
        if (navigator.onLine) {
            ind.className = 'status-indicator online me-1';
            ind.title = "Koneksi Stabil";
        } else {
            ind.className = 'status-indicator offline me-1';
            ind.title = "Koneksi Terputus (Offline)";
        }
    }
    window.addEventListener('online', updateNetworkStatus);
    window.addEventListener('offline', updateNetworkStatus);
    document.addEventListener('DOMContentLoaded', updateNetworkStatus);

    // KENDALI SIDEBAR
    const btnToggle = document.getElementById('btnToggleSidebar');
    if(btnToggle) {
        btnToggle.addEventListener('click', function(e) {
            e.stopPropagation(); 
            document.body.classList.toggle('sidebar-toggled');
            const icon = this.querySelector('i');
            if(document.body.classList.contains('sidebar-toggled')) {
                if(icon) icon.classList.replace('fa-indent', 'fa-outdent');
                localStorage.setItem('sidebarState', 'hidden');
            } else {
                if(icon) icon.classList.replace('fa-outdent', 'fa-indent');
                localStorage.setItem('sidebarState', 'visible');
            }
        });
    }
    
    if (localStorage.getItem('sidebarState') === 'hidden') {
        document.body.classList.add('sidebar-toggled');
        if(btnToggle) {
            const icon = btnToggle.querySelector('i');
            if(icon) icon.classList.replace('fa-indent', 'fa-outdent');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const mainContent = document.getElementById('main-content');
        if (mainContent) {
            mainContent.addEventListener('click', function(e) {
                if (e.target.closest('.top-navbar')) return;
                if (!document.body.classList.contains('sidebar-toggled')) {
                    document.body.classList.add('sidebar-toggled');
                    if (btnToggle) {
                        const icon = btnToggle.querySelector('i');
                        if (icon && icon.classList.contains('fa-indent')) { icon.classList.replace('fa-indent', 'fa-outdent'); }
                    }
                    localStorage.setItem('sidebarState', 'hidden');
                }
            });
        }
    });
</script>