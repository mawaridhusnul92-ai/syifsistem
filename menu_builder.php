<?php
/**
 * menu_builder.php - DYNAMIC RBAC MENU ENGINE
 * Versi: 24.0 (Sovereign Grand Master - Honorarium Sweep Fix)
 * Deskripsi: Menyapu bersih menu duplikat (honorarium_database_dosen) secara brutal 
 * dan absolut. Serta menginjeksi Honorarium utama ke dalam grup Kepegawaian.
 */

function getLegacyRoute($menu_key) {
    $map = [
        'ring_exec' => 'ringkasan',
        'menu_ringkasan' => 'ringkasan',
        'ring_drill' => 'buku_besar_ringkasan',
        'keu_trx' => 'transaksi_kas',
        'keu_trx_unit' => 'transaksi_unit',
        'keu_akun' => 'akun_kas',
        'keu_jurnal' => 'jurnal',
        'ang_rapb' => 'rapb',
        'ang_pendapatan' => 'anggaran_pendapatan',
        'ang_belanja' => 'anggaran_belanja',
        'ang_unit' => 'anggaran_unit',
        'mhs_data' => 'mahasiswa',
        'mhs_tarif' => 'mhs_tarif',
        'mhs_pembayaran' => 'mhs_pembayaran',
        'mhs_tagihan' => 'tagihan_generate',
        'tagihan_generate' => 'tagihan_generate',
        'mhs_monitoring' => 'tagihan_monitoring',
        'mhs_setting' => 'mhs_setting',
        'peg_data' => 'pegawai',
        'peg_gaji' => 'hr_payroll_setup',
        'peg_proses' => 'penggajian',
        'hr_honorarium' => 'honorarium',
        'rep_fin' => 'laporan_keuangan',
        'rep_asset' => 'aset_manajemen',
        'arsip_dokumen' => 'arsip_dokumen',
        'set_users' => 'user_management',
        'set_accounts' => 'user_management',
        'set_global' => 'pengaturan_sistem',
        'set_system' => 'pengaturan_sistem',
        'user_profile' => 'user_profile'
    ];
    return $map[$menu_key] ?? $menu_key;
}

function getUserMenus($conn, $role_id) {
    $role_id = (int)$role_id;
    
    // 🚀 THE GLOBAL SWEEPER: Menghapus Duplikat Menu Database Dosen yang bocor ke Sidebar
    try {
        $conn->query("DELETE FROM menus WHERE menu_key = 'honorarium_database_dosen'");
    } catch(Exception $e) {}

    // 🚀 THE GLOBAL AUTO-HEALER: INJEKSI MENU LAPORAN BENDAHARA
    try {
        $cek_menu = $conn->query("SELECT id FROM menus WHERE menu_key = 'laporan_bendahara' LIMIT 1");
        if ($cek_menu && $cek_menu->num_rows == 0) {
            $parent_q = $conn->query("SELECT id, menu_key FROM menus WHERE menu_key IN ('ang_rapb', 'rapb') OR menu_name LIKE '%Anggaran (RAPB)%' LIMIT 1");
            if ($parent_q && $parent_q->num_rows > 0) {
                $parent_row = $parent_q->fetch_assoc();
                $parent_id = (int)$parent_row['id'];
                $parent_key = $conn->real_escape_string($parent_row['menu_key']);
                
                $conn->query("INSERT INTO menus (menu_name, icon, menu_key, menu_level, parent_id, parent_key, urutan) VALUES ('Laporan Bendahara & SPM', 'fas fa-file-signature', 'laporan_bendahara', 'Sub', $parent_id, '$parent_key', 99)");
                $new_menu_id = $conn->insert_id;
                $conn->query("INSERT IGNORE INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) VALUES (1, $new_menu_id, 1, 1, 1, 1)");
            }
        }
    } catch (Exception $e) {}

    // 🚀 THE GLOBAL AUTO-HEALER: INJEKSI MENU HONORARIUM DI BAWAH KEPEGAWAIAN
    try {
        $cek_honor = $conn->query("SELECT id FROM menus WHERE menu_key = 'honorarium' LIMIT 1");
        if ($cek_honor && $cek_honor->num_rows == 0) {
            $parent_q = $conn->query("SELECT id, menu_key FROM menus WHERE menu_key IN ('pegawai', 'hr_pegawai', 'peg_data') OR menu_name LIKE '%Kepegawaian%' LIMIT 1");
            if ($parent_q && $parent_q->num_rows > 0) {
                $parent_row = $parent_q->fetch_assoc();
                $parent_id = (int)$parent_row['id'];
                $parent_key = $conn->real_escape_string($parent_row['menu_key']);
                
                $conn->query("INSERT INTO menus (menu_name, icon, menu_key, menu_level, parent_id, parent_key, urutan) VALUES ('Manajemen Honorarium', 'fas fa-chalkboard-teacher', 'honorarium', 'Sub', $parent_id, '$parent_key', 95)");
                $new_menu_id = $conn->insert_id;
                $conn->query("INSERT IGNORE INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) VALUES (1, $new_menu_id, 1, 1, 1, 1)");
            }
        }
    } catch (Exception $e) {}

    // 🚀 1. LIVE PERMISSION ENGINE
    $live_permissions = [];
    if ($role_id !== 1) {
        $res_p = $conn->query("SELECT m.menu_key FROM role_permissions rp JOIN menus m ON rp.menu_id = m.id WHERE rp.role_id = $role_id AND rp.can_view = 1");
        if ($res_p) {
            while ($p = $res_p->fetch_assoc()) {
                $live_permissions[$p['menu_key']] = true;
            }
        }
    }

    $all_menus = [];
    $res_all = $conn->query("SELECT * FROM menus WHERE menu_key IS NOT NULL AND menu_key != '' ORDER BY urutan ASC");
    if ($res_all) {
        while($m = $res_all->fetch_assoc()) {
            $all_menus[$m['menu_key']] = $m;
        }
    }

    $tree = [];
    
    // 🚀 2. RAKIT MAIN MENU & FOLDER
    foreach ($all_menus as $k => $m) {
        if ($m['menu_level'] == 'Main' || $m['menu_level'] == 'Single' || $m['parent_id'] == 0) {
            $m['subs'] = [];
            $is_permitted = ($role_id === 1 || isset($live_permissions[$k]) || $k == 'user_profile');
            
            if (!$is_permitted && $role_id !== 1) {
                foreach ($all_menus as $ck => $cm) {
                    if (($cm['parent_key'] === $k || $cm['parent_id'] == $m['id']) && isset($live_permissions[$ck])) {
                        $is_permitted = true;
                        break;
                    }
                }
            }
            
            if ($is_permitted) { $tree[$k] = $m; }
        }
    }

    // 🚀 3. RAKIT SUB MENU KE DALAM FOLDER
    foreach ($all_menus as $k => $m) {
        if ($m['menu_level'] == 'Sub' || ($m['parent_id'] > 0 && $m['menu_level'] != 'Tab')) {
            if ($role_id === 1 || isset($live_permissions[$k]) || $k == 'user_profile') {
                $pkey = $m['parent_key'];
                if(empty($pkey)) {
                    foreach($tree as $tk => $tv) {
                        if($tv['id'] == $m['parent_id']) { $pkey = $tk; break; }
                    }
                }
                if (isset($tree[$pkey])) { $tree[$pkey]['subs'][$k] = $m; }
            }
        }
    }

    // 🚀 4. THE ABSOLUTE SIDEBAR SWEEPER
    $keys_to_remove = [];
    foreach ($tree as $k => $m) {
        $nm = strtolower(trim($m['menu_name']));
        if (in_array($k, ['dashboard_eksekutif', 'dashboard_unit']) || 
            strpos($nm, 'dashboard eksekutif') !== false || 
            strpos($nm, 'dashboard unit') !== false) {
            $keys_to_remove[] = $k;
        }
    }
    foreach ($keys_to_remove as $kr) { unset($tree[$kr]); }

    // 🚀 5. SORTING BERDASARKAN URUTAN
    uasort($tree, function($a, $b) { return $a['urutan'] <=> $b['urutan']; });
    foreach ($tree as &$main) {
        if (isset($main['subs']) && is_array($main['subs'])) {
            uasort($main['subs'], function($a, $b) { return $a['urutan'] <=> $b['urutan']; });
        }
    }

    return $tree;
}
?>