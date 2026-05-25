<?php
/**
 * fix_menu_periode.php - MENU INJECTOR ENGINE (REVISI)
 * Menyesuaikan mutlak dengan struktur asli tabel 'menus' SYIFA ERP
 * (Menggunakan icon, parent_key, dan tanpa menu_link)
 */
require_once 'config/koneksi.php';

try {
    // 1. Cari Parent Key untuk "Aset & Pelaporan"
    $parent_q = $conn->query("SELECT menu_key FROM menus WHERE menu_name LIKE '%Aset & Pelaporan%' OR menu_key = 'rep_asset' LIMIT 1");
    $parent_key = ($parent_q && $parent_q->num_rows > 0) ? $parent_q->fetch_assoc()['menu_key'] : '';

    // 2. Cek apakah menu 'periode_setting' sudah ada di database
    $cek_menu = $conn->query("SELECT id FROM menus WHERE menu_key = 'periode_setting' LIMIT 1");
    
    if ($cek_menu && $cek_menu->num_rows == 0) {
        // Jika belum ada, INSERT baru menggunakan nama kolom yang TEPAT
        $conn->query("INSERT INTO menus (menu_name, icon, menu_key, menu_level, parent_key, urutan) 
                      VALUES ('Periode Laporan', 'fas fa-calendar-check', 'periode_setting', 'Sub', '$parent_key', 99)");
        $new_menu_id = $conn->insert_id;

        // Berikan akses ke Superadmin (Role 1)
        $conn->query("INSERT INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) 
                      VALUES (1, $new_menu_id, 1, 1, 1, 1)");
        
        echo "<div style='font-family:sans-serif; padding:30px;'>";
        echo "<h3 style='color:green;'>?? SUKSES: Menu 'Periode Laporan' berhasil disuntikkan ke dalam 'Aset & Pelaporan'.</h3>";
        echo "<p>Silakan tutup halaman ini dan <b>Refresh (F5)</b> Dashboard ERP Anda.</p>";
        echo "</div>";
    } else {
        // Jika menu sudah ada tapi induknya salah, perbaiki posisinya
        $menu_id = $cek_menu->fetch_assoc()['id'];
        $conn->query("UPDATE menus SET parent_key = '$parent_key' WHERE id = $menu_id");
        
        // Pastikan Superadmin (1) punya akses
        $conn->query("INSERT IGNORE INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) VALUES (1, $menu_id, 1, 1, 1, 1)");
        
        echo "<div style='font-family:sans-serif; padding:30px;'>";
        echo "<h3 style='color:blue;'>?? SUKSES: Posisi Menu 'Periode Laporan' berhasil diperbaiki ke 'Aset & Pelaporan'.</h3>";
        echo "<p>Silakan tutup halaman ini dan <b>Refresh (F5)</b> Dashboard ERP Anda.</p>";
        echo "</div>";
    }

} catch (Exception $e) {
    echo "<div style='font-family:sans-serif; padding:30px;'>";
    echo "<h3 style='color:red;'>?? GAGAL: " . $e->getMessage() . "</h3>";
    echo "</div>";
}
?>