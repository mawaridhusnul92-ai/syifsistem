<?php
/**
 * sidebar.php - NAVIGASI DINAMIS SYIFA ERP
 * Versi: 17.2 (Enterprise Accordion & Flat List Support)
 * Deskripsi: 
 * Menambahkan dukungan penuh terhadap preferensi tampilan (sidebar_style)
 * yang disetting dari Manajemen User (Accordion/Folder vs Flat List).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'menu_builder.php';

$role_id = (int)($_SESSION['role_id'] ?? 0);
$current_page = $_GET['page'] ?? 'dashboard';

// 🚀 TARIK GAYA TAMPILAN DARI SESSION (Diset otomatis di index.php / user_action)
$sidebar_style = $_SESSION['sidebar_style'] ?? 'accordion'; 

$tree = getUserMenus($conn, $role_id);
$is_admin = ($role_id === 1); 

// 🚀 AMBIL JUDUL, LOGO, DAN SLOGAN SIDEBAR DARI DATABASE DENGAN AMAN
$appr_sb = null;
$prof_sb = null;
if(isset($conn)) {
    try { $appr_sb = $conn->query("SELECT sidebar_title, app_slogan, primary_color FROM sys_appearance WHERE id=1")->fetch_assoc(); } catch(Exception $e){}
    try { $prof_sb = $conn->query("SELECT logo, sidebar_slogan FROM system_profile WHERE id=1")->fetch_assoc(); } catch(Exception $e){}
}

// Fallback Branding
$sidebar_title_raw = !empty($appr_sb['sidebar_title']) ? $appr_sb['sidebar_title'] : 'SYIFA ERP';
$sidebar_slogan = !empty($prof_sb['sidebar_slogan']) ? $prof_sb['sidebar_slogan'] : (!empty($appr_sb['app_slogan']) ? $appr_sb['app_slogan'] : 'INTEGRATED SYSTEM');
$sb_primary_color  = !empty($appr_sb['primary_color']) ? $appr_sb['primary_color'] : '#0d6efd';
$logo_path = !empty($prof_sb['logo']) ? "assets/img/" . $prof_sb['logo'] : "";

// Pecah kata agar kata terakhir mendapatkan warna Primer dengan aman
$words = explode(" ", trim($sidebar_title_raw));
if (count($words) > 1) {
    $last_word = array_pop($words);
    $first_words = implode(" ", $words);
} else {
    $first_words = $sidebar_title_raw;
    $last_word = "";
}

if (!function_exists('getSmartRoute')) {
    function getSmartRoute($key) {
        $route = function_exists('getLegacyRoute') ? getLegacyRoute($key) : $key;
        $map = [
            'menu_dashboard'      => 'dashboard_eksekutif',
            'dashboard_eksekutif' => 'dashboard_eksekutif',
            'dashboard_unit'      => 'dashboard_unit',
            'menu_ringkasan'      => 'ringkasan',
            'menu_laporan'        => 'laporan_keuangan',
            'menu_aset'           => 'aset_manajemen',
            'menu_user'           => 'user_management',
            'menu_periode'        => 'periode_setting'
        ];
        return $map[$route] ?? $route;
    }
}
?>

<style>
    /* 🛡️ CSS SIDEBAR MUTLAK */
    .sb-container {
        display: flex; flex-direction: column; height: 100%;
        background-color: #ffffff !important; 
        overflow: hidden;
    }
    .sb-header {
        padding: 25px 20px 20px; text-align: center;
        border-bottom: 1px dashed #e2e8f0;
    }
    .sb-scroll {
        flex-grow: 1; overflow-y: auto; padding-bottom: 80px;
        scrollbar-width: thin;
    }
    .sb-scroll::-webkit-scrollbar { width: 5px; }
    .sb-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    
    .acc-btn {
        display: block; width: 100%; text-align: left;
        background: transparent; border: none; outline: none;
        padding: 12px 18px; font-weight: 700; color: #475569;
        border-radius: 10px; margin-bottom: 5px; transition: 0.3s;
        font-size: 13.5px; cursor: pointer; text-decoration: none;
    }
    .acc-btn:hover { background-color: #f8fafc; color: var(--bs-primary); }
    .acc-btn i { width: 25px; text-align: center; margin-right: 10px; font-size: 16px; opacity: 0.7; }
    
    .active-menu {
        background-color: rgba(var(--bs-primary-rgb), 0.1) !important;
        color: var(--bs-primary) !important; font-weight: 800;
    }
    .active-menu i { color: var(--bs-primary); opacity: 1; }

    .sb-sub-container {
        padding-left: 15px; margin-bottom: 10px;
        border-left: 2px solid #f1f5f9; margin-left: 25px;
    }
    .sub-link {
        display: block; padding: 10px 15px; font-size: 12.5px;
        color: #64748b; font-weight: 600; text-decoration: none;
        border-radius: 8px; transition: 0.2s; margin-bottom: 2px;
    }
    .sub-link:hover { color: var(--bs-primary); background: #f8fafc; }
    .sub-link.active {
        color: var(--bs-primary); font-weight: 800; background: rgba(var(--bs-primary-rgb), 0.05);
    }
    
    /* 🚀 STYLE UNTUK FLAT LIST (MENU DIURAI) */
    .flat-header { 
        font-size: 10.5px; text-transform: uppercase; font-weight: 800; 
        color: #94a3b8; margin: 20px 0 5px 15px; letter-spacing: 0.5px; 
    }
    
    .drop-shadow-sm { filter: drop-shadow(0 4px 6px rgba(0,0,0,0.08)); }
</style>

<div class="sb-container no-print">
    <!-- Header Sidebar (LOGO INSTITUSI) -->
    <div class="sb-header">
        <?php if($logo_path && file_exists($logo_path)): ?>
            <img src="<?= $logo_path ?>" alt="Logo Institusi" class="img-fluid mb-2 drop-shadow-sm" style="max-height: 65px; object-fit: contain;">
        <?php else: ?>
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-2 fw-bold fs-3 shadow-sm" style="width: 55px; height: 55px; letter-spacing: -1px;">SY</div>
        <?php endif; ?>

        <h4 class="fw-bold text-dark mb-0 mt-2" style="letter-spacing: -0.5px; font-size: 15px;">
            <?= htmlspecialchars(strtoupper($first_words)) ?> 
            <?php if(!empty($last_word)): ?>
                <span style="color: <?= $sb_primary_color ?>;"><?= htmlspecialchars(strtoupper($last_word)) ?></span>
            <?php endif; ?>
        </h4>
        <small class="text-muted fw-bold" style="font-size: 10px; letter-spacing: 1px;"><?= htmlspecialchars(strtoupper($sidebar_slogan)) ?></small>
    </div>

    <!-- Menu List -->
    <div class="sb-scroll px-3 py-2 mt-2">
        <?php foreach ($tree as $main_key => $main_menu): ?>
            <?php 
            $clean_icon_acc = !empty($main_menu['icon']) ? $main_menu['icon'] : 'fas fa-folder';
            
            // =========================================================
            // 🚀 JIKA USER MEMILIH MODE SIDEBAR: "FLAT LIST" (DIURAI)
            // =========================================================
            if ($sidebar_style === 'flat' && !empty($main_menu['subs'])): ?>
                
                <div class="flat-header"><?= htmlspecialchars($main_menu['menu_name']) ?></div>
                <?php foreach ($main_menu['subs'] as $sub): 
                    $target_route = getSmartRoute($sub['menu_key']);
                    $is_active = ($current_page == $target_route || $current_page == $sub['menu_key']) ? 'active-menu' : '';
                    $clean_icon = !empty($sub['icon']) ? $sub['icon'] : 'fas fa-arrow-right';
                ?>
                    <a href="index.php?page=<?= $target_route ?>" class="nav-link acc-btn <?= $is_active ?>">
                        <i class="<?= trim($clean_icon) ?>"></i> <?= htmlspecialchars($sub['menu_name']) ?>
                    </a>
                <?php endforeach; ?>
                
            <?php 
            // =========================================================
            // 🚀 JIKA USER MEMILIH MODE SIDEBAR: "ACCORDION" ATAU MENU TUNGGAL
            // =========================================================
            else: 
                if (empty($main_menu['subs'])): 
                    $target_route = getSmartRoute($main_key);
                    $is_active = ($current_page == $target_route || $current_page == $main_key) ? 'active-menu' : '';
                    $clean_icon = !empty($main_menu['icon']) ? $main_menu['icon'] : 'fas fa-chevron-circle-right';
            ?>
                    <a href="index.php?page=<?= $target_route ?>" class="nav-link acc-btn <?= $is_active ?>">
                        <i class="<?= trim($clean_icon) ?>"></i> <?= htmlspecialchars($main_menu['menu_name']) ?>
                    </a>

                <?php else: 
                    $is_accordion_open = false;
                    foreach ($main_menu['subs'] as $sub) {
                        $target_route = getSmartRoute($sub['menu_key']);
                        if ($current_page == $target_route || $current_page == $sub['menu_key']) {
                            $is_accordion_open = true; break;
                        }
                    }
                ?>
                    <!-- MENU INDUK (ACCORDION) -->
                    <a class="nav-link acc-btn cursor-pointer <?= $is_accordion_open ? 'active-menu' : '' ?>" data-bs-toggle="collapse" data-bs-target="#coll_<?= $main_key ?>">
                        <i class="<?= trim($clean_icon_acc) ?>"></i> <?= htmlspecialchars($main_menu['menu_name']) ?>
                    </a>

                    <!-- SUB MENU LIST -->
                    <div class="collapse <?= $is_accordion_open ? 'show' : '' ?>" id="coll_<?= $main_key ?>">
                        <div class="sb-sub-container">
                            <?php foreach ($main_menu['subs'] as $sub): 
                                $target_route = getSmartRoute($sub['menu_key']);
                                $is_sub_active = ($current_page == $target_route || $current_page == $sub['menu_key']) ? 'active' : '';
                            ?>
                                <a href="index.php?page=<?= $target_route ?>" class="nav-link sub-link <?= $is_sub_active ?>">
                                    <?= htmlspecialchars($sub['menu_name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Footer Sidebar -->
    <div class="mt-auto p-4 bg-white border-top text-center no-print">
        <a href="index.php?page=logout" class="btn btn-danger btn-sm w-100 rounded-pill fw-bold shadow-sm" style="background-color: #ef4444 !important; border-color: #ef4444 !important;">
            <i class="fas fa-power-off me-2"></i> KELUAR SISTEM
        </a>
    </div>
</div>