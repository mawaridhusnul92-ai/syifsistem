<?php
/**
 * access_guard.php - URL PROTECTION SHIELD
 * Versi: 7.0 (Global State Validation)
 * Deskripsi: Memvalidasi rute berdasarkan array global yang sudah diolah index.php
 */
function checkMenuAccess($conn, $role_id, $page_key) {
    global $current_permissions;
    
    // Superadmin, Dashboard, dan Fitur Dasar bebas hambatan
    if ((int)$role_id === 1 || in_array($page_key, ['dashboard', 'profile', 'logout'])) return true;
    
    // Cek pada array izin yang sudah melalui proses Bubble-Up dan Smart Alias di index.php
    if (!isset($current_permissions[$page_key]) || (int)$current_permissions[$page_key]['can_view'] !== 1) {
        die("
        <div style='background:#f8fafc; height:100vh; display:flex; justify-content:center; align-items:center; font-family:sans-serif;'>
            <div style='background:#fff; padding:40px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1); text-align:center;'>
                <img src='https://cdn-icons-png.flaticon.com/512/7486/7486744.png' style='width:100px; margin-bottom:20px; opacity:0.8;'>
                <h2 style='color:#ef4444; margin-top:0;'>Akses Ditolak (403)</h2>
                <p style='color:#64748b;'>Anda tidak memiliki izin otorisasi (RBAC) untuk mengakses modul ini.</p>
                <p style='color:#cbd5e1; font-size:10px;'>Ditolak akses ke rute sistem: <b>$page_key</b></p>
                <a href='index.php?page=dashboard' style='display:inline-block; margin-top:15px; padding:10px 20px; background:#0d6efd; color:#fff; text-decoration:none; border-radius:50px; font-weight:bold;'>Kembali ke Dashboard</a>
            </div>
        </div>
        ");
    }
    return true;
}
?>