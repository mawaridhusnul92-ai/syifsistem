<?php
/**
 * index.php - PUSAT NAVIGASI & ROUTER ERP SYIFA
 * Versi: 91.0 (Enterprise - Native Honorarium Hub Edition)
 * Perbaikan Mutlak: 
 * Menyuntikkan Auto-Healer Honorarium ke rute yang benar dan 
 * menghilangkan error "sistem di dalam sistem".
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (file_exists('config/koneksi.php')) { require_once 'config/koneksi.php'; } 
else if (file_exists('koneksi.php')) { require_once 'koneksi.php'; } 
else { die("<div style='padding:50px; text-align:center;'><h4 style='color:#e74c3c;'>Ralat Kritikal: Fail koneksi.php tidak dijumpai!</h4></div>"); }

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// 🚀 0. AUTO-HEALER: INJEKSI MENU HONORARIUM KE DATABASE
try {
    $cek_honor = $conn->query("SELECT id FROM menus WHERE menu_key = 'honorarium' LIMIT 1");
    if ($cek_honor && $cek_honor->num_rows == 0) {
        $parent_q = $conn->query("SELECT id, menu_key FROM menus WHERE menu_key IN ('pegawai', 'hr_pegawai', 'peg_data') OR menu_name LIKE '%Kepegawaian%' LIMIT 1");
        if ($parent_q && $parent_q->num_rows > 0) {
            $parent = $parent_q->fetch_assoc();
            $pid = $parent['id'];
            $pkey = $parent['menu_key'];
            // Insert Sub-Menu Honorarium
            $conn->query("INSERT INTO menus (menu_name, icon, menu_key, menu_level, parent_id, parent_key, urutan) VALUES ('Manajemen Honorarium', 'fas fa-chalkboard-teacher', 'honorarium', 'Sub', $pid, '$pkey', 95)");
            $new_id = $conn->insert_id;
            // Berikan Akses ke Superadmin
            $conn->query("INSERT IGNORE INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) VALUES (1, $new_id, 1, 1, 1, 1)");
        }
    }
} catch(Exception $e) {}

// 🚀 1. SINKRONISASI DATA USER & ROLE
$uid_sync = (int)$_SESSION['user_id'];
$sync_q = $conn->query("SELECT r.role_name, r.landing_page, r.is_ka_unit, u.name, u.role_id, u.unit_id, u.sidebar_style FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = $uid_sync");

if ($sync_q && $sync_q->num_rows > 0) {
    $sync_data = $sync_q->fetch_assoc();
    $_SESSION['role_name'] = $sync_data['role_name'] ?: 'User';
    $_SESSION['name'] = $sync_data['name'];
    $_SESSION['role_id'] = $sync_data['role_id'];
    $_SESSION['unit_id'] = $sync_data['unit_id'];
    $_SESSION['is_ka_unit'] = $sync_data['is_ka_unit'];
    $_SESSION['landing_page'] = $sync_data['landing_page'];
    $_SESSION['sidebar_style'] = $sync_data['sidebar_style'] ?? 'accordion';
}

// 🚀 2. ENGINE PENARIK OTORITAS MATRIKS (RBAC)
function getFullPermissions($conn, $role_id) {
    $role_id = (int)$role_id;
    $perms = [];
    $stmt = $conn->prepare("SELECT m.menu_key, m.parent_key, rp.can_view, rp.can_add, rp.can_edit, rp.can_delete FROM role_permissions rp JOIN menus m ON rp.menu_id = m.id WHERE rp.role_id = ?");
    if($stmt) {
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) $perms[$row['menu_key']] = $row;
        $stmt->close();
    }
    
    $all_menus = [];
    $res_all = $conn->query("SELECT menu_key, parent_key FROM menus");
    if ($res_all) { while ($m = $res_all->fetch_assoc()) $all_menus[$m['menu_key']] = $m['parent_key']; }
    
    $added = true;
    while ($added) {
        $added = false;
        $current_keys = array_keys($perms);
        foreach ($current_keys as $key) {
            if (!empty($all_menus[$key])) {
                $parent = $all_menus[$key];
                if (!isset($perms[$parent])) {
                    $perms[$parent] = ['can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0];
                    $added = true;
                }
            }
        }
    }
    
    // 🛡️ OMNI-MAPPING KUNCI MATRIKS DB KE ROUTING URL
    $route_map = [
        'dashboard_eksekutif'  => ['dashboard_eksekutif', 'menu_dashboard', 'dashboard'],
        'dashboard_unit'       => ['dashboard_unit'],
        'ringkasan'            => ['ringkasan', 'ring_exec', 'menu_ringkasan'],
        'buku_besar_ringkasan' => ['buku_besar_ringkasan', 'ring_drill'],
        'transaksi_kas'        => ['transaksi_kas', 'keu_trx'],
        'transaksi_unit'       => ['transaksi_unit', 'keu_trx_unit'],
        'akun_kas'             => ['akun_kas', 'keu_akun'],
        'jurnal'               => ['jurnal', 'keu_jurnal'],
        'rapb'                 => ['rapb', 'ang_rapb'],
        'anggaran_pendapatan'  => ['anggaran_pendapatan', 'ang_pendapatan'],
        'anggaran_belanja'     => ['anggaran_belanja', 'ang_belanja'],
        'anggaran_unit'        => ['anggaran_unit', 'ang_unit'],
        
        'mahasiswa'            => ['mahasiswa', 'mhs_data'],
        'tagihan_generate'     => ['tagihan_generate', 'mhs_tagihan'],
        'tagihan_monitoring'   => ['tagihan_monitoring', 'mhs_monitoring'],
        'mhs_setting'          => ['mhs_setting'],
        
        'pegawai'              => ['pegawai', 'hr_pegawai'],
        'penggajian'           => ['penggajian', 'hr_payroll'],
        'hr_payroll_setup'     => ['hr_payroll_setup', 'hr_setup'],
        
        // 🚀 INJEKSI RUTING HONORARIUM
        'honorarium'           => ['honorarium', 'hr_honorarium'],
        
        'laporan_keuangan'     => ['laporan_keuangan', 'rep_fina', 'rep_keuangan'],
        'laporan_posisi_keuangan'     => ['laporan_posisi_keuangan'],
        'laporan_aktivitas'           => ['laporan_aktivitas'],
        'laporan_perubahan_aset_neto' => ['laporan_perubahan_aset_neto'],
        'laporan_kas_detail'          => ['laporan_kas_detail'],
        'laporan_calk'                => ['laporan_calk'],
        'generate_laporan'            => ['generate_laporan'],
        'laporan_buku_besar'          => ['laporan_buku_besar'],
        'laporan_kas_summary'         => ['laporan_kas_summary'],
        'laporan_piutang_mhs'         => ['laporan_piutang_mhs'],
        'laporan_perubahan_aset'      => ['laporan_perubahan_aset'],
        'neraca_saldo'                => ['neraca_saldo'],
        'hr_laporan_gaji'             => ['hr_laporan_gaji', 'hr_laporan'],
        'laporan_bendahara'           => ['laporan_bendahara', 'menu_bendahara'],

        'aset_manajemen'       => ['aset_manajemen', 'rep_asset'],
        'arsip_dokumen'        => ['arsip_dokumen'], 
        'user_management'      => ['user_management', 'set_users', 'set_accounts'],
        'pengaturan_sistem'    => ['pengaturan_sistem', 'set_global', 'set_system'],
        'user_profile'         => ['user_profile'],
        'riwayat_sistem'       => ['riwayat_sistem'] 
    ];
    
    foreach ($route_map as $legacy => $db_keys) {
        foreach ((array)$db_keys as $db_k) {
            if (isset($perms[$db_k])) { $perms[$legacy] = $perms[$db_k]; break; }
        }
    }
    return $perms;
}

global $current_permissions;
$current_permissions = getFullPermissions($conn, $_SESSION['role_id'] ?? 0);

function hasAccess($menu_key) {
    global $current_permissions;
    if (isset($_SESSION['role_id']) && (int)$_SESSION['role_id'] === 1) return true; // Superadmin Bypass
    
    // 🛡️ AUTO-GRANT DASHBOARD
    if (in_array($menu_key, ['dashboard_eksekutif', 'dashboard_unit', 'menu_dashboard', 'dashboard'])) {
        if (isset($_SESSION['landing_page']) && $_SESSION['landing_page'] === $menu_key) return true;
        if (isset($current_permissions['menu_dashboard']) && (int)$current_permissions['menu_dashboard']['can_view'] === 1) return true;
        if (isset($current_permissions['dashboard']) && (int)$current_permissions['dashboard']['can_view'] === 1) return true;
        
        if ($menu_key === 'dashboard_unit' && isset($_SESSION['is_ka_unit']) && $_SESSION['is_ka_unit'] == 1) return true;
        if (($menu_key === 'dashboard_eksekutif' || $menu_key === 'menu_dashboard' || $menu_key === 'dashboard') && (!isset($_SESSION['is_ka_unit']) || $_SESSION['is_ka_unit'] == 0)) return true;
    }
    
    return isset($current_permissions[$menu_key]) && (int)$current_permissions[$menu_key]['can_view'] === 1;
}

require_once 'menu_builder.php';

// 🚀 3. LOGIKA LANDING PAGE & ROUTING CERDAS
$default_landing = 'dashboard_eksekutif'; 
if (!empty($_SESSION['landing_page']) && $_SESSION['landing_page'] !== 'dashboard' && $_SESSION['landing_page'] !== 'Auto (Dashboard Sistem)') {
    $default_landing = $_SESSION['landing_page'];
} elseif (isset($_SESSION['is_ka_unit']) && $_SESSION['is_ka_unit'] == 1) {
    $default_landing = 'dashboard_unit'; 
}

$page = $_GET['page'] ?? $default_landing;

if ($page === 'dashboard' || $page === 'Auto (Dashboard Sistem)') {
    $page = $default_landing;
    header("Location: index.php?page=$page"); exit;
}

// 🚀 4. THE ABSOLUTE GATEKEEPER (MENGUNCI AKSES ILEGAL DARI URL)
if ($page != 'logout' && $page != 'user_profile' && $page != 'riwayat_sistem') {
    if ($_SESSION['role_id'] != 1 && !hasAccess($page)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Akses Ditolak: Anda tidak memiliki izin matriks untuk mengakses modul <b>'.$page.'</b>.'];
        if ($page === $default_landing || !hasAccess($default_landing)) {
            die("<div style='padding:50px; text-align:center; font-family:sans-serif;'><h3 style='color:red;'>AKSES DITOLAK: MATRIKS BELUM DISIAPKAN</h3><p>Role Jabatan Anda tidak memiliki izin untuk melihat Halaman Utama (Landing Page) ini. Hubungi Administrator untuk mencentang matriks izin Anda pada menu <b>$default_landing</b>.</p><a href='index.php?page=logout'>Kembali & Keluar</a></div>");
        }
        header("Location: index.php?page=" . $default_landing);
        exit;
    }
}

// Set RBAC Konstanta untuk tombol Add/Edit/Del
if ($_SESSION['role_id'] == 1 || in_array($page, ['user_profile', 'riwayat_sistem'])) {
    if(!defined('RBAC_ADD')) { define('RBAC_ADD', true); define('RBAC_EDIT', true); define('RBAC_DEL', true); }
} else {
    $can_a = isset($current_permissions[$page]) ? (int)$current_permissions[$page]['can_add'] === 1 : false;
    $can_e = isset($current_permissions[$page]) ? (int)$current_permissions[$page]['can_edit'] === 1 : false;
    $can_d = isset($current_permissions[$page]) ? (int)$current_permissions[$page]['can_delete'] === 1 : false;
    if(!defined('RBAC_ADD')) { define('RBAC_ADD', $can_a); define('RBAC_EDIT', $can_e); define('RBAC_DEL', $can_d); }
}

// 🚀 5. PEMETAAN FILE FISIK (THE SMART DASHBOARD RESOLVER)
$is_ka_unit = (isset($_SESSION['is_ka_unit']) && $_SESSION['is_ka_unit'] == 1);

$physical_files = [
    'dashboard_eksekutif' => 'dashboard_eksekutif.php',
    'dashboard_unit' => 'anggaran_unit.php', 
    'menu_dashboard' => $is_ka_unit ? 'anggaran_unit.php' : 'dashboard_eksekutif.php',
    'dashboard' => $is_ka_unit ? 'anggaran_unit.php' : 'dashboard_eksekutif.php',
    
    'profile' => 'profile.php',
    'user_profile' => 'user_profile.php', 
    'ringkasan' => 'ringkasan.php', 'ring_exec' => 'ringkasan.php', 'menu_ringkasan' => 'ringkasan.php',
    'buku_besar_ringkasan' => 'buku_besar_ringkasan.php', 'ring_drill' => 'buku_besar_ringkasan.php',
    'akun_kas' => 'transactions.php', 'keu_akun' => 'transactions.php',
    'transaksi_kas' => 'cash_transactions.php', 'keu_trx' => 'cash_transactions.php',
    'transaksi_unit' => 'transaksi_unit.php', 'keu_trx_unit' => 'transaksi_unit.php',
    'jurnal' => 'jurnal.php', 'keu_jurnal' => 'jurnal.php',
    'rapb' => 'rapb.php', 'ang_rapb' => 'rapb.php',
    'anggaran_pendapatan' => 'anggaran_pendapatan.php', 'ang_pendapatan' => 'anggaran_pendapatan.php',
    'anggaran_belanja' => 'anggaran_belanja.php', 'ang_belanja' => 'anggaran_belanja.php',
    'anggaran_unit' => 'anggaran_unit.php', 'ang_unit' => 'anggaran_unit.php',
    'mahasiswa' => 'mahasiswa.php', 'mhs_data' => 'mahasiswa.php',
    'mhs_pembayaran' => 'mhs_pembayaran.php', 'mhs_tarif' => 'mhs_tarif.php',
    'tagihan_generate' => 'mhs_tagihan.php', 'mhs_tagihan' => 'mhs_tagihan.php',
    'tagihan_monitoring' => 'mhs_monitoring.php', 'mhs_monitoring' => 'mhs_monitoring.php',
    'mhs_setting' => 'mhs_setting.php',
    
    'pegawai' => 'hr_pegawai.php', 'hr_pegawai' => 'hr_pegawai.php',
    'hr_payroll_setup' => 'hr_payroll_setup.php', 'hr_setup' => 'hr_payroll_setup.php',
    'penggajian' => 'hr_payroll.php', 'hr_payroll' => 'hr_payroll.php',
    'hr_laporan_gaji' => 'hr_laporan_gaji.php', 'hr_laporan' => 'hr_laporan_gaji.php',
    
    // 🚀 INJEKSI FILE FISIK HONORARIUM (MASTER HUB)
    'honorarium' => 'honorarium.php',
    
    'aset_manajemen' => 'asset_management.php', 'rep_asset' => 'asset_management.php',
    'laporan_keuangan' => 'laporan_keuangan.php', 'rep_fina' => 'laporan_keuangan.php', 'rep_keuangan' => 'laporan_keuangan.php',
    'arsip_dokumen' => 'arsip_dokumen.php',
    'laporan_posisi_keuangan' => 'laporan_posisi_keuangan.php', 
    'laporan_aktivitas' => 'laporan_aktivitas.php',
    'laporan_calk' => 'laporan_calk.php', 
    'generate_laporan' => 'generate_laporan.php',
    'laporan_kas_detail' => 'laporan_kas_detail.php', 'laporan_kas_summary' => 'laporan_kas_summary.php',
    'laporan_perubahan_aset' => 'laporan_perubahan_aset.php', 'laporan_perubahan_aset_neto' => 'laporan_perubahan_aset_neto.php',
    'laporan_buku_besar' => 'laporan_buku_besar.php', 'periode_setting' => 'periode_setting.php',
    'neraca_saldo' => 'neraca_saldo.php', 'laporan_piutang_mhs' => 'laporan_piutang_mhs.php',
    'laporan_bendahara' => 'laporan_bendahara.php',
    'user_management' => 'user_management.php', 'set_users' => 'user_management.php', 'set_accounts' => 'user_management.php',
    'pengaturan_sistem' => 'settings.php', 'set_global' => 'settings.php', 'set_system' => 'settings.php',
    'riwayat_sistem' => 'riwayat_sistem.php'
];

$appr_index = null;
if (isset($conn)) { try { $appr_index = $conn->query("SELECT browser_title FROM sys_appearance WHERE id=1")->fetch_assoc(); } catch(Exception $e){} }
$browser_title = !empty($appr_index['browser_title']) ? $appr_index['browser_title'] : 'SYIFA ERP System';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($browser_title) ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <link href="assets/css/style_syifa.css?v=<?= time() ?>" rel="stylesheet">
    <link href="assets/css/syifa-bootstrap-bridge.css?v=<?= time() ?>" rel="stylesheet">
    <link href="assets/css/syifa-bs5-fix.css?v=<?= time() ?>" rel="stylesheet">
    
    <style>
        #sidebar-wrapper { 
            width: 275px; height: 100vh; position: fixed; left: 0; top: 0; z-index: 1100; 
            background-color: #ffffff !important; border-right: 1px solid #f1f5f9; transition: all 0.3s ease;
        }
        #main-content { 
            margin-left: 275px; width: calc(100% - 275px); min-height: 100vh; 
            transition: all 0.3s ease; background-color: #f8fafc !important; 
        }
        .content-container { padding: 30px; }
        body.sidebar-toggled #sidebar-wrapper { transform: translateX(-100%); }
        body.sidebar-toggled #main-content { margin-left: 0; width: 100%; }
        .page-transition { animation: slideUpFade 0.5s cubic-bezier(0.25, 0.8, 0.25, 1); }
        @keyframes slideUpFade { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

    <div id="sidebar-wrapper">
        <?php if(file_exists('sidebar.php')) include 'sidebar.php'; ?>
    </div>

    <div id="main-content" class="main-content">
        <?php if(file_exists('header_nav.php')) include 'header_nav.php'; ?>

        <div class="content-container page-transition">
            <?php 
            if (isset($_SESSION['flash'])) {
                echo "<div class='alert alert-{$_SESSION['flash']['type']} alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4 animate__animated animate__headShake'>
                        <div class='d-flex align-items-center text-dark'>
                            <i class='fas fa-info-circle me-3 fa-lg text-{$_SESSION['flash']['type']}'></i>
                            <div class='text-dark fw-bold'>{$_SESSION['flash']['msg']}</div>
                        </div>
                        <button type='button' class='btn-close shadow-none' data-bs-dismiss='alert'></button>
                      </div>";
                unset($_SESSION['flash']);
            }

            try {
                if ($page == 'logout') { session_destroy(); header("Location: login.php"); exit; }
                $target_file = $physical_files[$page] ?? null;
                
                if (in_array($page, ['dashboard_eksekutif', 'dashboard_unit', 'menu_dashboard', 'dashboard'])) {
                    $is_dashboard_mode = true;
                }

                if ($target_file && file_exists($target_file)) { include $target_file; } 
                else {
                    echo "<div class='text-center py-5'>
                            <i class='fas fa-tools fa-5x text-muted opacity-25 mb-4 d-block'></i>
                            <h4 class='fw-bold'>Modul [$page] Sedang Dalam Pengembangan</h4>
                            <a href='index.php?page=dashboard_eksekutif' class='btn btn-primary rounded-pill px-5 shadow-sm mt-3'>Kembali ke Dashboard Pusat</a>
                          </div>";
                }
            } catch (Exception $e) {
                echo "<div class='alert alert-danger rounded-4 shadow-sm'>
                        <h6 class='fw-bold'><i class='fas fa-bug me-2'></i>Ralat Sistem Kritikal</h6>
                        <code>" . $e->getMessage() . "</code>
                      </div>";
            }
            ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        (function() {
            const SYIFA_RBAC = { add: <?= RBAC_ADD ? 'true' : 'false' ?>, edit: <?= RBAC_EDIT ? 'true' : 'false' ?>, del: <?= RBAC_DEL ? 'true' : 'false' ?> };

            function enforceRBAC(node) {
                if (!node || !node.querySelectorAll) return;
                const elements = node.querySelectorAll('button, a, .btn, .dropdown-item');
                
                elements.forEach(el => {
                    const html = el.innerHTML.toLowerCase();
                    const text = el.innerText.toLowerCase();
                    const onclick = (el.getAttribute('onclick') || '').toLowerCase();
                    const href = (el.getAttribute('href') || '').toLowerCase();

                    if(el.classList.contains('nav-link') || el.closest('#sidebar-wrapper') || el.closest('#main-content > header')) return;

                    if (!SYIFA_RBAC.add && (html.includes('fa-plus') || text.match(/\b(tambah|add|baru|buat)\b/i) || onclick.match(/\b(add|tambah|create|baru)\b/i) || href.match(/\b(add|tambah)\b/i))) { el.remove(); }
                    if (!SYIFA_RBAC.edit && (html.includes('fa-edit') || html.includes('fa-pen') || html.includes('fa-sync') || text.match(/\b(ubah|edit|perbarui|sinkron|koreksi)\b/i) || onclick.match(/\b(edit|ubah|update|sync)\b/i) || href.match(/\b(edit|ubah)\b/i))) { el.remove(); }
                    if (!SYIFA_RBAC.del && (html.includes('fa-trash') || html.includes('fa-times-circle') || text.match(/\b(hapus|delete|buang|batalkan)\b/i) || onclick.match(/\b(delete|hapus|remove)\b/i) || href.match(/\b(delete|hapus)\b/i))) { el.remove(); }
                });
            }

            document.addEventListener('DOMContentLoaded', () => { enforceRBAC(document.body); });
            const observer = new MutationObserver((mutationsList) => {
                for (const mutation of mutationsList) {
                    if (mutation.type === 'childList') {
                        mutation.addedNodes.forEach(node => {
                            if (node.nodeType === 1) { enforceRBAC(node); enforceRBAC({ querySelectorAll: () => [node] }); }
                        });
                    }
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        })();
    </script>
</body>
</html>
<?php ob_end_flush(); ?>