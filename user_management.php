<?php
/**
 * user_management.php - ANTARMUKA KENDALI AKSES & WORKFLOW ERP SYIFA
 * Versi: 54.2 (Enterprise - Absolute Database Guard & No menu_link Error Edition)
 * Perbaikan Mutlak:
 * 1. DYNAMIC QUERY BUILDER: Menyamarkan pencarian 'menu_link' di dalam kueri $sql_menus jika 
 * kolom tersebut tidak ada di database Anda, menuntaskan ralat "Unknown column 'menu_link'".
 * 2. PREVENTIVE OPTION MAP: Memastikan dropdown pemilihan Halaman Utama (Landing Page) 
 * aman dari notice PHP undefined index jika kolom menu_link tidak eksis.
 * 3. BLACK CHECKBOX LABELS: Mempertahankan label checklist berwarna Hitam Pekat (#000000) agar kontras.
 * 4. MENGHILANGKAN MUTLAK: Seluruh bentuk Dashboard dari Matriks UI.
 * STATUS: FULL CODE - NO TRUNCATION (100% UTUH)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

if(function_exists('guardPage')) { guardPage('user_management'); }

// =========================================================================
// 🚀 1. THE ADAPTIVE DB INJECTOR (ANTI-CRASH)
// =========================================================================
$has_link = false;
$has_icon = false;
$has_pkey = false;

try { 
    $conn->query("DELETE m1 FROM menus m1 INNER JOIN menus m2 WHERE m1.id > m2.id AND m1.menu_key = m2.menu_key AND m1.menu_key IS NOT NULL AND m1.menu_key != ''");

    // Deteksi Struktur Kolom Database Anda
    $has_link = $conn->query("SHOW COLUMNS FROM menus LIKE 'menu_link'")->num_rows > 0;
    $has_icon = $conn->query("SHOW COLUMNS FROM menus LIKE 'icon'")->num_rows > 0;
    $has_pkey = $conn->query("SHOW COLUMNS FROM menus LIKE 'parent_key'")->num_rows > 0;

    if (!function_exists('safeInsertMenu')) {
        function safeInsertMenu($conn, $name, $key, $level, $parentId, $urutan, $has_link, $has_icon, $has_pkey) {
            $check = $conn->query("SELECT id FROM menus WHERE menu_key='$key'");
            if ($check && $check->num_rows > 0) {
                $id = $check->fetch_assoc()['id'];
                $conn->query("UPDATE menus SET parent_id=$parentId, menu_level='$level', menu_name='$name' WHERE id=$id");
                return $id;
            }
            $cols = "menu_name, menu_key, menu_level, parent_id, urutan";
            $vals = "'$name', '$key', '$level', $parentId, $urutan";
            if ($has_link) { $cols .= ", menu_link"; $vals .= ", '?page=$key'"; }
            if ($has_icon) { $cols .= ", icon"; $vals .= ", 'fas fa-circle'"; }
            if ($has_pkey) { $cols .= ", parent_key"; $vals .= ", ''"; }
            
            $conn->query("INSERT INTO menus ($cols) VALUES ($vals)");
            return $conn->insert_id;
        }
    }

    // Cari ID Laporan Keuangan di DB
    $lap_id = 0;
    $find_lap = $conn->query("SELECT id FROM menus WHERE menu_key IN ('laporan_keuangan', 'rep_fina', 'rep_keuangan') OR TRIM(menu_name) = 'Laporan Keuangan' ORDER BY id DESC LIMIT 1");
    if($find_lap && $find_lap->num_rows > 0) {
        $lap_id = (int)$find_lap->fetch_assoc()['id'];
    } else {
        $lap_id = safeInsertMenu($conn, 'Laporan Keuangan', 'laporan_keuangan', 'Sub', 0, 90, $has_link, $has_icon, $has_pkey);
    }
    
    // Injeksi 12 Tab Laporan dengan Aman!
    if($lap_id > 0) {
        $sub_laporans = [
            'laporan_posisi_keuangan'     => ['Tab: Posisi Keuangan (Neraca)', 1],
            'laporan_aktivitas'           => ['Tab: Laporan Aktivitas (L/R)', 2],
            'laporan_perubahan_aset_neto' => ['Tab: Perubahan Aset Neto', 3],
            'laporan_kas_detail'          => ['Tab: Laporan Arus Kas', 4],
            'laporan_calk'                => ['Tab: Catatan Laporan (CALK)', 5],
            'generate_laporan'            => ['Tab: Konsolidasi Laporan Full', 6],
            'laporan_buku_besar'          => ['Tab: Buku Besar Detail', 7],
            'laporan_kas_summary'         => ['Tab: Ringkasan Kas', 8],
            'laporan_piutang_mhs'         => ['Tab: Tagihan & Piutang Mhs', 9],
            'laporan_perubahan_aset'      => ['Tab: Laporan Aset Inventaris', 10],
            'neraca_saldo'                => ['Tab: Neraca Saldo', 11],
            'hr_laporan_gaji'             => ['Tab: Laporan Gaji Pegawai', 12]
        ];
        foreach($sub_laporans as $k_sub => $v_sub) {
            safeInsertMenu($conn, $v_sub[0], $k_sub, 'Tab', $lap_id, $v_sub[1], $has_link, $has_icon, $has_pkey);
        }
    }
} catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'user';
$edit_role_id = $_GET['edit_role_id'] ?? null;

$roles = $conn->query("SELECT * FROM roles ORDER BY role_name ASC")->fetch_all(MYSQLI_ASSOC);
$unit_list = $conn->query("SELECT id, nama_unit FROM m_unit ORDER BY nama_unit ASC")->fetch_all(MYSQLI_ASSOC);

// =========================================================================
// 🚀 2. THE CLEAN TREE BUILDER (KINI DIKUNCI OLEH DETEKTOR SKEMA KONDISIONAL)
// =========================================================================
$sql_menus = "SELECT * FROM menus 
              WHERE menu_key NOT IN ('dashboard_eksekutif', 'dashboard_unit', 'menu_dashboard') ";

// 🛡️ DILINDUNGI MUTLAK: Hanya memanggil kolom menu_link jika terbukti eksis di tabel database
if ($has_link) {
    $sql_menus .= " AND menu_link NOT LIKE '%dashboard_eksekutif%' 
                    AND menu_link NOT LIKE '%dashboard_unit%' ";
}

$sql_menus .= " AND menu_name NOT LIKE '%Dashboard Eksekutif%' 
              AND menu_name NOT LIKE '%Dashboard Unit%' 
              AND menu_name NOT LIKE '%Dashboard (Akses Langsung)%'
              ORDER BY urutan ASC, menu_name ASC";
              
$menus_raw = $conn->query($sql_menus)->fetch_all(MYSQLI_ASSOC);

$menu_tree = []; $subs = []; $tabs = [];
$processed_ids = []; 

foreach($menus_raw as $m) {
    if(empty($m['menu_name'])) continue; 
    $lvl = strtolower(trim($m['menu_level'])); 
    $pid = (int)$m['parent_id'];
    
    if($lvl == 'tab') { $tabs[$m['id']] = $m; }
    elseif($lvl == 'sub') { $subs[$m['id']] = $m; $subs[$m['id']]['tabs'] = []; }
    else { 
        $menu_tree[$m['id']] = $m; 
        $menu_tree[$m['id']]['subs'] = []; 
        $menu_tree[$m['id']]['tabs'] = []; 
        $processed_ids[] = $m['id'];
    }
}

foreach($tabs as $t) { 
    if(isset($subs[$t['parent_id']])) { $subs[$t['parent_id']]['tabs'][] = $t; $processed_ids[] = $t['id']; } 
    elseif(isset($menu_tree[$t['parent_id']])) { $menu_tree[$t['parent_id']]['tabs'][] = $t; $processed_ids[] = $t['id']; }
}
foreach($subs as $s) { 
    if(isset($menu_tree[$s['parent_id']])) { $menu_tree[$s['parent_id']]['subs'][] = $s; $processed_ids[] = $s['id']; } 
    else { $s['menu_level'] = 'Main'; $menu_tree[$s['id']] = $s; $menu_tree[$s['id']]['subs'] = []; $menu_tree[$s['id']]['tabs'] = []; $processed_ids[] = $s['id']; }
}

$orphans = [];
foreach($menus_raw as $m) { if(!in_array($m['id'], $processed_ids) && !empty($m['menu_name'])) { $orphans[] = $m; } }

$res_wf_raw = $conn->query("SELECT w.*, r.role_name FROM approval_workflow w JOIN roles r ON w.role_id = r.id ORDER BY r.role_name ASC, w.step_order ASC");
$grouped_wf = [];
if($res_wf_raw) {
    while($row = $res_wf_raw->fetch_assoc()) {
        $grouped_wf[$row['role_id']]['role_name'] = $row['role_name'];
        $grouped_wf[$row['role_id']]['items'][] = $row;
    }
}
function getWfLabel($order) {
    return match((int)$order) {
        1 => "1 (MAKER / Pengaju)", 2 => "2 (CHECKER / Pemeriksa)",
        3 => "3 (APPROVER / Penyetuju)", 4 => "4 (RELEASE / Pembayar)",
        default => $order . " (Langkah Tambahan)"
    };
}
?>

<style>
    /* 🚀 REKOMENDASI TATA GAYA: SOLID BLACK COLOR AT WORKFLOW CHECKLIST MODUL ACCORDION */
    #collapseModul, #collapseModul label, #collapseModul .form-check-label {
        color: #000000 !important;
        opacity: 1 !important;
    }

    .active-role { background-color: rgba(var(--bs-primary-rgb), 0.1) !important; border-left: 5px solid var(--bs-primary) !important; color: var(--bs-primary) !important; transition: 0.3s; }
    .accordion-button:not(.collapsed) { background: #f8fafc; color: var(--bs-primary); box-shadow: none; }
    .accordion-button:not(.collapsed)::after { transform: rotate(-180deg); }
    .table-fixed { table-layout: fixed; }
    .text-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .btn-white { background: #fff; border: none; transition: 0.2s; }
    .btn-white:hover { background: #f8f9fa; color: var(--bs-primary) !important; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4 text-dark">
        <div>
            <h4 class="fw-bold text-primary mb-0"><i class="fas fa-user-shield me-2"></i>Manajemen Akses & Otoritas</h4>
            <small class="text-muted fw-bold">Pusat Konfigurasi Matrix Izin, Gaya Sidebar, & Alur Pengesahan.</small>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4 border-0">
        <li class="nav-item"><a href="?page=user_management&tab=user" class="nav-link <?= $tab=='user'?'active':'' ?>">User Akun</a></li>
        <li class="nav-item"><a href="?page=user_management&tab=role" class="nav-link <?= $tab=='role'?'active':'' ?>">Matrix Otoritas</a></li>
        <li class="nav-item"><a href="?page=user_management&tab=workflow" class="nav-link <?= $tab=='workflow'?'active':'' ?>">Workflow</a></li>
    </ul>

    <div class="tab-content">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark p-4">
            
            <?php if ($tab == 'user'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h6 class="fw-bold mb-0 text-dark">Database Pengguna</h6>
                    <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="showModalUser()"><i class="fas fa-plus me-2"></i>TAMBAH USER</button>
                </div>
                <div class="table-responsive rounded-4 border">
                    <table class="table table-hover align-middle mb-0 text-center text-dark">
                        <thead class="table-light small text-uppercase fw-bold text-muted">
                            <tr><th class="ps-4 text-start py-3">Nama Pengguna</th><th>Role Izin (Menu)</th><th>Gaya Sidebar</th><th>Workflow Auth</th><th>Status</th><th class="text-center pe-4" width="100">Aksi</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $res_u = $conn->query("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY u.name ASC");
                            while($u = $res_u->fetch_assoc()): 
                                $wf_val = strtoupper($u['jabatan_workflow'] ?? 'MAKER');
                                $wf_badge = match($wf_val) {
                                    'ALL'      => 'bg-danger text-white',
                                    'PIMPINAN' => 'bg-warning text-dark border border-dark',
                                    'APPROVER' => 'bg-primary text-white',
                                    'CHECKER'  => 'bg-info text-dark',
                                    default    => 'bg-secondary text-white'
                                };
                            ?>
                            <tr>
                                <td class="ps-4 text-start"><div class="fw-bold text-dark"><?= $u['name'] ?></div><small class="text-muted"><?= $u['email'] ?></small></td>
                                <td><span class="badge bg-info bg-opacity-10 text-info border px-3"><?= $u['role_name'] ?: 'No Role' ?></span></td>
                                <td>
                                    <?php if(($u['sidebar_style']??'accordion') == 'flat'): ?>
                                        <span class="badge bg-dark bg-opacity-10 text-dark border"><i class="fas fa-list-ul me-1"></i> Flat List</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border"><i class="fas fa-folder me-1"></i> Accordion</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $wf_badge ?> px-3 fw-bold"><?= $wf_val ?></span></td>
                                <td><?= $u['status'] ? '<span class="text-success small fw-bold">AKTIF</span>' : '<span class="text-danger small fw-bold">BLOKIR</span>' ?></td>
                                <td class="text-center pe-4">
                                    <div class="btn-group btn-group-sm rounded-pill border overflow-hidden shadow-sm bg-white">
                                        <button class="btn btn-white text-warning border-end" onclick='editUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES, "UTF-8") ?>)' title="Ubah User"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-white text-danger" onclick="confirmDeleteUserLocal(<?= $u['id'] ?>)" title="Hapus User"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($tab == 'role'): ?>
                <div class="row g-4 text-dark animate__animated animate__fadeIn">
                    <div class="col-md-4">
                        <div class="border rounded-4 bg-white overflow-hidden shadow-sm h-100">
                            <div class="bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-dark">Role & Jabatan</h6>
                                <button class="btn btn-sm btn-primary rounded-circle shadow" onclick="showModalRole()"><i class="fas fa-plus"></i></button>
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach($roles as $r): ?>
                                    <div class="list-group-item list-group-item-action py-3 px-3 d-flex justify-content-between align-items-center border-0 border-bottom <?= ($edit_role_id == $r['id']) ? 'active-role fw-bold' : '' ?>">
                                        <a href="?page=user_management&tab=role&edit_role_id=<?= $r['id'] ?>" class="text-decoration-none flex-grow-1 <?= ($edit_role_id == $r['id']) ? 'text-primary' : 'text-dark' ?>">
                                            <i class="fas fa-user-tag me-2 opacity-50"></i> <?= strtoupper($r['role_name']) ?>
                                        </a>
                                        <div class="btn-group btn-group-sm bg-white rounded-pill border shadow-sm">
                                            <button class="btn btn-white text-warning border-end p-1 px-2" onclick='showModalRole(<?= json_encode($r) ?>)' title="Ubah Konfigurasi Role"><i class="fas fa-pen-nib"></i></button>
                                            <button class="btn btn-white text-info border-end p-1 px-2" onclick="confirmDuplicateRoleLocal(<?= $r['id'] ?>)" title="Duplikasi Role"><i class="fas fa-copy"></i></button>
                                            <button class="btn btn-white text-danger p-1 px-2" onclick="confirmDeleteRoleLocal(<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <?php if($edit_role_id): 
                            $curr_role = $conn->query("SELECT * FROM roles WHERE id = $edit_role_id")->fetch_assoc();
                            $p_res = $conn->query("SELECT * FROM role_permissions WHERE role_id = $edit_role_id");
                            $curr_perms = []; if($p_res) while($p = $p_res->fetch_assoc()) $curr_perms[$p['menu_id']] = $p;
                        ?>
                        <form action="user_action.php" method="POST" class="border rounded-4 bg-white overflow-hidden shadow-sm h-100">
                            <input type="hidden" name="action" value="update_permissions">
                            <input type="hidden" name="role_id" value="<?= $edit_role_id ?>">
                            
                            <div class="bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-dark uppercase">Matriks Izin: <span class="text-primary"><?= $curr_role['role_name'] ?></span></h6>
                                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm"><i class="fas fa-save me-2"></i>SIMPAN MATRIKS</button>
                            </div>
                            
                            <!-- 🚀 INFO DASHBOARD -->
                            <div class="alert alert-info rounded-0 border-0 border-bottom mb-0 px-4 py-3 text-dark small fw-bold">
                                <i class="fas fa-info-circle text-primary me-2"></i>Dashboard Eksekutif & Unit <b>disembunyikan</b> dari tabel ini agar lebih rapi. Akses ke sana otomatis terbuka melalui pilihan 'Halaman Utama' pada Konfigurasi Role.
                            </div>

                            <div class="accordion accordion-flush" id="accRBAC" style="max-height: 600px; overflow-y: auto;">
                                <?php foreach($menu_tree as $rootId => $root): ?>
                                <div class="accordion-item border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed bg-white fw-bold py-3 text-dark shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#modul-<?= $rootId ?>">
                                            <i class="<?= $root['icon'] ?: 'fas fa-folder' ?> me-3 text-primary" style="width:25px; text-align:center;"></i> <?= $root['menu_name'] ?>
                                        </button>
                                    </h2>
                                    <div id="modul-<?= $rootId ?>" class="accordion-collapse collapse" data-bs-parent="#accRBAC">
                                        <div class="accordion-body p-0">
                                            <table class="table table-hover mb-0 text-dark" style="font-size: 0.85rem;">
                                                <thead class="table-light small">
                                                    <tr class="text-center">
                                                        <th class="ps-4 text-start">Menu / Sub / Tab</th>
                                                        <th width="80">View</th><th width="80">Add</th><th width="80">Edit</th><th width="80">Del</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $sp = $curr_perms[$root['id']] ?? ['can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0]; ?>
                                                    
                                                    <tr class="table-secondary border-top">
                                                        <td class="ps-4 text-start fw-bold text-dark"><i class="fas fa-folder-open me-2 text-primary"></i> <?= $root['menu_name'] ?> <?= (empty($root['subs']) && empty($root['tabs'])) ? '(Akses Langsung)' : '(Folder Utama)' ?></td>
                                                        <td class="text-center"><input type="checkbox" name="perm[<?= $root['id'] ?>][view]" value="1" <?= (int)$sp['can_view']===1?'checked':'' ?> class="form-check-input border-dark cb-view matrix-cb cb-parent-<?= $root['id'] ?>"></td>
                                                        <td class="text-center"><input type="checkbox" name="perm[<?= $root['id'] ?>][add]" value="1" <?= (int)$sp['can_add']===1?'checked':'' ?> class="form-check-input border-dark cb-act matrix-cb cb-parent-<?= $root['id'] ?>"></td>
                                                        <td class="text-center"><input type="checkbox" name="perm[<?= $root['id'] ?>][edit]" value="1" <?= (int)$sp['can_edit']===1?'checked':'' ?> class="form-check-input border-dark cb-act matrix-cb cb-parent-<?= $root['id'] ?>"></td>
                                                        <td class="text-center"><input type="checkbox" name="perm[<?= $root['id'] ?>][delete]" value="1" <?= (int)$sp['can_delete']===1?'checked':'' ?> class="form-check-input border-dark cb-act matrix-cb cb-parent-<?= $root['id'] ?>"></td>
                                                    </tr>

                                                    <!-- TABS DIRECTLY UNDER MAIN FOLDER -->
                                                    <?php if(!empty($root['tabs'])): foreach($root['tabs'] as $tMenu): 
                                                        $tp = $curr_perms[$tMenu['id']] ?? ['can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0]; ?>
                                                        <tr class="bg-white">
                                                            <td class="text-start text-dark fw-bold small" style="padding-left: 3.5rem;"><i class="fas fa-minus me-2 ms-2 text-primary opacity-50"></i> <?= $tMenu['menu_name'] ?></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][view]" value="1" <?= (int)$tp['can_view']===1?'checked':'' ?> class="form-check-input border-primary cb-view matrix-cb child-of-<?= $root['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][add]" value="1" <?= (int)$tp['can_add']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][edit]" value="1" <?= (int)$tp['can_edit']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][delete]" value="1" <?= (int)$tp['can_delete']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                        </tr>
                                                    <?php endforeach; endif; ?>

                                                    <!-- SUB MENUS AND THEIR TABS -->
                                                    <?php if(!empty($root['subs'])): foreach($root['subs'] as $sub): 
                                                        $sp_sub = $curr_perms[$sub['id']] ?? ['can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0]; ?>
                                                        <tr class="table-light border-top">
                                                            <td class="ps-5 text-start fw-bold text-dark"><i class="<?= $sub['icon'] ?: 'fas fa-caret-right' ?> me-2 text-muted"></i> <?= $sub['menu_name'] ?></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $sub['id'] ?>][view]" value="1" <?= (int)$sp_sub['can_view']===1?'checked':'' ?> class="form-check-input border-secondary cb-view matrix-cb child-of-<?= $root['id'] ?> cb-parent-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $sub['id'] ?>][add]" value="1" <?= (int)$sp_sub['can_add']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?> cb-parent-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $sub['id'] ?>][edit]" value="1" <?= (int)$sp_sub['can_edit']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?> cb-parent-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $sub['id'] ?>][delete]" value="1" <?= (int)$sp_sub['can_delete']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?> cb-parent-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $root['id'] ?>)"></td>
                                                        </tr>
                                                        <?php if(!empty($sub['tabs'])) foreach($sub['tabs'] as $tMenu): 
                                                            $tp = $curr_perms[$tMenu['id']] ?? ['can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0]; ?>
                                                            <tr class="bg-white">
                                                                <td class="text-start text-dark fw-bold small" style="padding-left: 4.5rem;"><i class="fas fa-minus me-2 ms-4 text-primary opacity-50"></i> <?= $tMenu['menu_name'] ?></td>
                                                                <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][view]" value="1" <?= (int)$tp['can_view']===1?'checked':'' ?> class="form-check-input border-primary cb-view matrix-cb child-of-<?= $root['id'] ?> child-of-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $sub['id'] ?>); checkParentCb(<?= $root['id'] ?>);"></td>
                                                                <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][add]" value="1" <?= (int)$tp['can_add']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?> child-of-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $sub['id'] ?>); checkParentCb(<?= $root['id'] ?>);"></td>
                                                                <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][edit]" value="1" <?= (int)$tp['can_edit']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?> child-of-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $sub['id'] ?>); checkParentCb(<?= $root['id'] ?>);"></td>
                                                                <td class="text-center"><input type="checkbox" name="perm[<?= $tMenu['id'] ?>][delete]" value="1" <?= (int)$tp['can_delete']===1?'checked':'' ?> class="form-check-input border-secondary cb-act matrix-cb child-of-<?= $root['id'] ?> child-of-<?= $sub['id'] ?>" onchange="checkParentCb(<?= $sub['id'] ?>); checkParentCb(<?= $root['id'] ?>);"></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endforeach; endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <!-- ORPHAN CATCHER -->
                                <?php if(!empty($orphans)): ?>
                                <div class="accordion-item border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed bg-danger bg-opacity-10 fw-bold py-3 text-danger shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#modul-orphans">
                                            <i class="fas fa-link-slash me-3 text-danger" style="width:25px; text-align:center;"></i> Menu Lainnya (Tidak Terkategori)
                                        </button>
                                    </h2>
                                    <div id="modul-orphans" class="accordion-collapse collapse" data-bs-parent="#accRBAC">
                                        <div class="accordion-body p-0">
                                            <table class="table table-hover mb-0 text-dark" style="font-size: 0.85rem;">
                                                <thead class="table-light small">
                                                    <tr class="text-center"><th class="ps-4 text-start">Menu Tercecer</th><th width="80">View</th><th width="80">Add</th><th width="80">Edit</th><th width="80">Del</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($orphans as $orp): 
                                                        $sp = $curr_perms[$orp['id']] ?? ['can_view'=>0,'can_add'=>0,'can_edit'=>0,'can_delete'=>0]; ?>
                                                        <tr class="bg-white">
                                                            <td class="ps-4 text-start fw-bold text-dark"><i class="<?= $orp['icon'] ?: 'fas fa-file' ?> me-2 text-muted"></i> <?= $orp['menu_name'] ?></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $orp['id'] ?>][view]" value="1" <?= (int)$sp['can_view']===1?'checked':'' ?> class="form-check-input border-dark cb-view matrix-cb"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $orp['id'] ?>][add]" value="1" <?= (int)$sp['can_add']===1?'checked':'' ?> class="form-check-input border-dark cb-act matrix-cb"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $orp['id'] ?>][edit]" value="1" <?= (int)$sp['can_edit']===1?'checked':'' ?> class="form-check-input border-dark cb-act matrix-cb"></td>
                                                            <td class="text-center"><input type="checkbox" name="perm[<?= $orp['id'] ?>][delete]" value="1" <?= (int)$sp['can_delete']===1?'checked':'' ?> class="form-check-input border-dark cb-act matrix-cb"></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                            </div>
                        </form>
                        <?php else: echo '<div class="text-center py-5 text-muted small italic border rounded-4 bg-light">Pilih role dari kiri untuk mengatur otorisasi matriks.</div>'; endif; ?>
                    </div>
                </div>

            <?php elseif ($tab == 'workflow'): ?>
                <div class="row g-4 text-dark animate__animated animate__fadeIn">
                    <div class="col-md-5">
                        <div class="border rounded-4 bg-primary text-white p-4 shadow-lg sticky-top" style="top: 20px; z-index: 10;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="fw-bold mb-0" id="wf_form_title"><i class="fas fa-project-diagram me-2 text-white"></i>Atur Otoritas Alur</h5>
                                <button type="button" class="btn btn-sm btn-light text-primary rounded-pill fw-bold px-3 shadow-sm" onclick="resetWfForm()"><i class="fas fa-plus me-1"></i> Baru</button>
                            </div>
                            
                            <form action="user_action.php" method="POST" id="formWorkflow">
                                <input type="hidden" name="action" value="save_workflow_step">
                                <input type="hidden" name="id" id="wf_id">
                                <div class="mb-3">
                                    <label class="small fw-bold opacity-75 uppercase text-white">Modul Terdampak (Checklist)</label>
                                    <div class="accordion border-0" id="accModulSelect">
                                        <div class="accordion-item bg-transparent border-0">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button bg-white text-primary rounded-pill py-2 px-4 shadow-sm fw-bold border-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapseModul">PILIH MODUL</button>
                                            </h2>
                                            <!-- 🚀 MEMAKSA BG & TEXT COLOR SELALU KONTRAS HITAM PEKAT DI DALAM CHECKLIST -->
                                            <div id="collapseModul" class="accordion-collapse collapse show bg-white rounded-4 mt-2 p-3 text-dark shadow border">
                                                <div class="row g-2">
                                                    <div class="col-6"><div class="form-check"><input class="form-check-input border-secondary" type="checkbox" name="modules[]" value="anggaran_unit" id="mod_unit"><label class="form-check-label small fw-bold" style="color: #000000 !important; opacity:1 !important;" for="mod_unit">Anggaran Unit</label></div></div>
                                                    <div class="col-6"><div class="form-check"><input class="form-check-input border-secondary" type="checkbox" name="modules[]" value="lpj" id="mod_lpj"><label class="form-check-label small fw-bold" style="color: #000000 !important; opacity:1 !important;" for="mod_lpj">LPJ (Laporan)</label></div></div>
                                                    <div class="col-6"><div class="form-check"><input class="form-check-input border-secondary" type="checkbox" name="modules[]" value="belanja" id="mod_belanja"><label class="form-check-label small fw-bold" style="color: #000000 !important; opacity:1 !important;" for="mod_belanja">Pengajuan Belanja</label></div></div>
                                                    <div class="col-6"><div class="form-check"><input class="form-check-input border-secondary" type="checkbox" name="modules[]" value="hr_payroll" id="mod_hr"><label class="form-check-label small fw-bold" style="color: #000000 !important; opacity:1 !important;" for="mod_hr">HR Payroll</label></div></div>
                                                </div>
                                                <input type="hidden" name="module_single" id="wf_module_single">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3"><label class="small fw-bold opacity-75 uppercase text-white">Jabatan / Role</label><select name="role_id" id="wf_role_id" class="form-select border-0 shadow-sm px-3" required><?php foreach($roles as $r) echo "<option value='{$r['id']}'>{$r['role_name']}</option>"; ?></select></div>
                                <div class="mb-3"><label class="small fw-bold opacity-75 uppercase text-white">Urutan Langkah</label><select name="step_order" id="wf_step_order" class="form-select border-0 shadow-sm px-3" required><?php for($i=1;$i<=4;$i++) echo "<option value='$i'>".getWfLabel($i)."</option>"; ?></select></div>
                                <div class="mb-3"><label class="small fw-bold opacity-75 uppercase text-white">Nama Langkah</label><input type="text" name="step_name" id="wf_step_name" class="form-control border-0 shadow-sm px-3" placeholder="Misal: Verifikasi Dokumen" required></div>
                                <div class="form-check form-switch mb-4 text-center d-flex justify-content-center gap-2 text-white">
                                    <input class="form-check-input" type="checkbox" name="is_final" id="wf_is_final" value="1">
                                    <label class="form-check-label fw-bold" for="wf_is_final">Langkah Final (Post Jurnal)</label>
                                </div>
                                <button type="submit" class="btn btn-dark text-white w-100 rounded-pill py-3 fw-bold shadow border-white border-opacity-25" id="wf_btn_submit">SIMPAN ATURAN ALUR</button>
                                <button type="button" class="btn btn-link text-white w-100 mt-2 d-none" id="wf_btn_cancel" onclick="resetWfForm()">Batal Ubah</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="border rounded-4 bg-white overflow-hidden shadow-sm text-dark h-100">
                            <div class="bg-light p-3 border-bottom text-dark">
                                <h6 class="fw-bold mb-0">Workflow Matrix Otorisasi Aktif</h6>
                                <small class="text-muted">Klik nama jabatan untuk melihat rincian modul yang diawasi.</small>
                            </div>
                            <div class="accordion accordion-flush" id="accWorkflowActive">
                                <?php if(!empty($grouped_wf)): foreach($grouped_wf as $role_id => $data): ?>
                                <div class="accordion-item border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-3 bg-white shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#wf-role-<?= $role_id ?>">
                                            <div class="row w-100 align-items-center m-0 pe-2">
                                                <div class="col-md-7 text-start d-flex align-items-center p-0">
                                                    <i class="fas fa-user-tie me-3 text-primary opacity-50 fs-5"></i> 
                                                    <span class="fw-bold text-dark text-truncate"><?= strtoupper($data['role_name']) ?></span>
                                                </div>
                                                <div class="col-md-5 text-end p-0">
                                                    <span class="badge bg-primary text-white border-0 shadow-sm rounded-pill px-0 py-2 small d-inline-block" style="width: 130px; text-align: center;"><?= count($data['items']) ?> Modul Aktif</span>
                                                </div>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="wf-role-<?= $role_id ?>" class="accordion-collapse collapse" data-bs-parent="#accWorkflowActive">
                                        <div class="accordion-body p-0 border-top bg-light bg-opacity-50">
                                            <div class="table-responsive">
                                                <table class="table table-hover align-middle mb-0 text-center text-dark" style="font-size: 0.85rem; table-layout: fixed;">
                                                    <thead class="bg-white small text-uppercase fw-bold text-muted">
                                                        <tr>
                                                            <th class="ps-3 text-start" width="35%">Modul Sistem</th><th width="15%">Step</th><th width="35%">Keterangan</th><th class="pe-3" width="15%">Aksi</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach($data['items'] as $item): ?>
                                                        <tr>
                                                            <td class="ps-3 text-start fw-bold text-primary text-truncate">
                                                                <i class="fas fa-cube me-2 opacity-50"></i><?= strtoupper(str_replace('_',' ', $item['module'])) ?>
                                                            </td>
                                                            <td><span class="badge bg-dark text-white px-3">Ke-<?= $item['step_order'] ?></span></td>
                                                            <td class="text-truncate"><small class="italic"><?= $item['step_name'] ?></small></td>
                                                            <td class="pe-3 text-end">
                                                                <div class="btn-group btn-group-sm bg-white border rounded-pill shadow-sm">
                                                                    <button class="btn btn-white text-warning border-end p-1 px-2" onclick='editWf(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, "UTF-8") ?>)'><i class="fas fa-edit"></i></button>
                                                                    <a href="user_action.php?action=delete_workflow_step&id=<?= $item['id'] ?>" class="btn btn-white text-danger p-1 px-2" onclick="return confirm('Hapus aturan ini?')"><i class="fas fa-trash-alt"></i></a>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; else: ?>
                                <div class="text-center py-5 text-muted italic small">Belum ada alur workflow yang terdefinisi.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<!-- Modal User (Tetap) -->
<div class="modal fade" id="modalUser" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered"><form action="user_action.php" method="POST" class="modal-content rounded-4 shadow-lg border-0 overflow-hidden text-dark">
        <input type="hidden" name="action" value="save_user"><input type="hidden" name="id" id="user_id">
        <div class="modal-header bg-primary text-white p-4 border-0"><h5 class="modal-title fw-bold text-white">Konfigurasi Pengguna</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4 bg-light text-start">
            <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Nama Lengkap</label><input type="text" name="name" id="user_name" class="form-control rounded-pill border-0 shadow-sm px-3" required></div>
            <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Login ID / Email</label><input type="text" name="email" id="user_email" class="form-control rounded-pill border-0 shadow-sm px-3" required></div>
            <div class="mb-3"><label class="small fw-bold text-primary mb-1 uppercase">Kunci Akses (Password)</label><div class="input-group shadow-sm rounded-pill overflow-hidden"><input type="password" name="password" id="user_pass" class="form-control border-0 px-3 bg-white" placeholder="Ketik password baru untuk mereset..."><button class="btn btn-white border-0 px-3 bg-white" type="button" onclick="togglePassVisibility('user_pass', this)"><i class="fas fa-eye text-muted"></i></button></div><small class="text-muted d-block mt-2 px-2" style="font-size: 10px;">*Sandi dienkripsi di sistem. Biarkan terisi <b>********</b> jika Anda tidak mereset sandi.</small></div>
            <div class="row g-2 mb-3">
                <div class="col-md-6"><label class="small fw-bold text-muted mb-1 uppercase">Role Izin (Menu)</label><select name="role_id" id="user_role" class="form-select rounded-pill border-0 shadow-sm px-3" required><option value="">-- Pilih Role --</option><?php foreach($roles as $r) echo "<option value='{$r['id']}'>{$r['role_name']}</option>"; ?></select></div>
                <div class="col-md-6"><label class="small fw-bold text-muted mb-1 uppercase">Otoritas Workflow</label><select name="jabatan_workflow" id="user_wf" class="form-select rounded-pill border-0 shadow-sm px-3" required><option value="MAKER">MAKER (Pembuat)</option><option value="CHECKER">CHECKER (Verifikator)</option><option value="APPROVER">APPROVER (Penyetuju)</option><option value="PIMPINAN">PIMPINAN (Eksekutif)</option><option value="ALL">ALL (Super Akses)</option></select></div>
            </div>
            <div class="mb-0"><label class="small fw-bold text-muted mb-1 uppercase"><i class="fas fa-palette me-1 text-primary"></i> Gaya Tampilan Sidebar</label><select name="sidebar_style" id="user_sidebar" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold text-primary" required><option value="accordion">Accordion (Menu Dikelompokkan / Folder)</option><option value="flat">Flat List (Menu Diurai Satu Per Satu ke Bawah)</option></select></div>
        </div>
        <div class="modal-footer p-4 bg-white border-0"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">SIMPAN KONFIGURASI</button></div>
    </form></div>
</div>

<!-- 🚀 MODAL ROLE -->
<div class="modal fade" id="modalRole" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered"><form action="user_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 text-dark overflow-hidden">
        <input type="hidden" name="action" value="save_role"><input type="hidden" name="id" id="role_id_inp">
        <div class="modal-header bg-dark text-white p-4 border-0"><h5 class="modal-title fw-bold text-white">Konfigurasi Otoritas Role</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4 bg-light">
            <div class="mb-3"><label class="small fw-bold text-muted mb-1 uppercase">Nama Role / Jabatan</label><input type="text" name="role_name" id="role_name_inp" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required></div>
            
            <div class="mb-3">
                <label class="small fw-bold text-primary mb-1 uppercase">Halaman Utama (Landing Page)</label>
                <select name="landing_page" id="role_landing_page" class="form-select rounded-pill border-0 shadow-sm px-4 py-2 text-dark fw-bold" required>
                    <option value="dashboard_eksekutif">Dashboard Eksekutif (Pusat)</option>
                    <option value="dashboard_unit">Dashboard Unit (Operasional)</option>
                    <?php 
                    $opt_menus = $conn->query("SELECT * FROM menus WHERE menu_key NOT IN ('dashboard_eksekutif', 'dashboard_unit') ORDER BY menu_name ASC")->fetch_all(MYSQLI_ASSOC);
                    foreach($opt_menus as $m): 
                        if(empty($m['menu_name'])) continue; 
                        
                        $link_val = $m['menu_key'];
                        // 🛡️ DILINDUNGI MUTLAK: Mencegah error crash jika kolom menu_link tidak eksis di database user
                        if ($has_link && isset($m['menu_link']) && !empty($m['menu_link'])) {
                            parse_str(parse_url(html_entity_decode($m['menu_link']), PHP_URL_QUERY), $queries);
                            $link_val = $queries['page'] ?? $m['menu_key'];
                        }
                    ?>
                        <option value="<?= htmlspecialchars($link_val) ?>"><?= $m['menu_name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-0"><div class="card-body text-start">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input border-danger" type="checkbox" name="is_ka_unit" id="chkKaUnit" value="1" onchange="toggleUnitSelect(this.checked, true)">
                    <label class="form-check-label fw-bold text-danger" for="chkKaUnit">Set sebagai Kepala Unit</label>
                </div>
                <div id="divUnitSelect" class="d-none animate__animated animate__fadeIn">
                    <label class="small fw-bold text-muted mb-1 mt-2">PILIH UNIT KERJA (Pusat Wewenang)</label>
                    <select name="unit_id" id="role_unit_id" class="form-select rounded-pill border-0 bg-light px-3 fw-bold text-primary">
                        <option value="0">-- Semua Unit Terdaftar (All Around) --</option>
                        <?php foreach($unit_list as $u) echo "<option value='{$u['id']}'>{$u['nama_unit']}</option>"; ?>
                    </select>
                </div>
            </div></div>
        </div>
        <div class="modal-footer p-4 bg-light border-0"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">SIMPAN ROLE</button></div>
    </form></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.matrix-cb:not([class*="cb-parent"])').forEach(cb => {
        cb.addEventListener('change', function() {
            const tr = this.closest('tr');
            const viewCb = tr.querySelector('.cb-view');
            if (this.classList.contains('cb-act') && this.checked) { if(viewCb) viewCb.checked = true; }
            if (this.classList.contains('cb-view') && !this.checked) { tr.querySelectorAll('.cb-act').forEach(actCb => actCb.checked = false); }
        });
    });

    document.querySelectorAll('.cb-view').forEach(cb => {
        cb.addEventListener('change', function() {
            const parentIdMatch = this.className.match(/cb-parent-(\d+)/);
            if(parentIdMatch) {
                const pId = parentIdMatch[1];
                if(!this.checked) {
                    document.querySelectorAll('.child-of-' + pId).forEach(childCb => { childCb.checked = false; });
                }
            }
        });
    });
});

function checkParentCb(parentId) {
    const children = document.querySelectorAll('.child-of-' + parentId + '.cb-view:checked');
    if (children.length > 0) {
        const parentViews = document.querySelectorAll('.cb-parent-' + parentId + '.cb-view');
        parentViews.forEach(cb => { cb.checked = true; });
    }
}

function togglePassVisibility(id, btn) { const input = document.getElementById(id); const icon = btn.querySelector('i'); if (input.type === "password") { input.type = "text"; icon.classList.replace('fa-eye', 'fa-eye-slash'); } else { input.type = "password"; icon.classList.replace('fa-eye-slash', 'fa-eye'); } }

function showModalUser(data = null) { 
    const mEl = document.getElementById('modalUser'); const m = bootstrap.Modal.getOrCreateInstance(mEl); 
    document.getElementById('user_id').value = data ? data.id : ''; 
    document.getElementById('user_name').value = data ? data.name : ''; 
    document.getElementById('user_email').value = data ? data.email : ''; 
    document.getElementById('user_role').value = data ? data.role_id : ''; 
    const wf_val = data ? (data.jabatan_workflow || 'MAKER') : 'MAKER'; 
    document.getElementById('user_wf').value = wf_val.toUpperCase(); 
    document.getElementById('user_sidebar').value = data ? (data.sidebar_style || 'accordion') : 'accordion'; 
    document.getElementById('user_pass').value = data ? '********' : ''; 
    m.show(); 
}
function editUser(data) { showModalUser(data); }

function showModalRole(data = null) { 
    const mEl = document.getElementById('modalRole'); const m = bootstrap.Modal.getOrCreateInstance(mEl); 
    document.getElementById('role_id_inp').value = data ? data.id : ''; 
    document.getElementById('role_name_inp').value = data ? data.role_name : ''; 
    
    const isKaUnit = data ? (data.is_ka_unit == 1) : false;
    document.getElementById('chkKaUnit').checked = isKaUnit; 
    document.getElementById('role_unit_id').value = (data && data.unit_id) ? data.unit_id : '0'; 
    
    let landingDef = 'dashboard_eksekutif';
    if(data && data.landing_page && data.landing_page.trim() !== '') { 
        landingDef = data.landing_page; 
    } else if(isKaUnit) { 
        landingDef = 'dashboard_unit'; 
    }
    
    let selectEl = document.getElementById('role_landing_page');
    let optionExists = Array.from(selectEl.options).some(opt => opt.value === landingDef);
    if(!optionExists) { selectEl.add(new Option(landingDef + " (Data Tersimpan)", landingDef)); }
    selectEl.value = landingDef; 
    
    toggleUnitSelect(isKaUnit, false); 
    m.show(); 
}

function toggleUnitSelect(checked, isManualClick = false) { 
    document.getElementById('divUnitSelect').className = checked ? '' : 'd-none'; 
    if (isManualClick) { 
        if(checked) { document.getElementById('role_landing_page').value = 'dashboard_unit'; } 
        else { document.getElementById('role_landing_page').value = 'dashboard_eksekutif'; }
    }
}

function editWf(data) {
    document.getElementById('wf_form_title').innerHTML = '<i class="fas fa-edit me-2 text-white"></i>Ubah Aturan Alur';
    document.getElementById('wf_id').value = data.id; document.getElementById('wf_role_id').value = data.role_id; document.getElementById('wf_step_order').value = data.step_order; document.getElementById('wf_step_name').value = data.step_name; document.getElementById('wf_is_final').checked = (data.is_final == 1); document.getElementById('accModulSelect').classList.remove('d-none'); document.getElementById('wf_module_single').value = data.module; document.getElementById('wf_btn_submit').innerText = "SIMPAN PERUBAHAN"; document.getElementById('wf_btn_cancel').classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function resetWfForm() {
    document.getElementById('wf_form_title').innerHTML = '<i class="fas fa-project-diagram me-2 text-white"></i>Atur Otoritas Alur';
    document.getElementById('wf_id').value = ''; document.getElementById('wf_module_single').value = ''; document.getElementById('formWorkflow').reset(); document.getElementById('accModulSelect').classList.remove('d-none'); document.getElementById('wf_btn_submit').innerText = "SIMPAN ATURAN ALUR"; document.getElementById('wf_btn_cancel').add('d-none');
}

function confirmDeleteRoleLocal(id) { 
    if(id == 1) { alert("Role Superadmin tidak boleh dihapus."); return; } 
    if(confirm('Hapus role? Seluruh matriks izin terkait juga akan terhapus.')) {
        const form = document.createElement('form'); form.method = 'POST'; form.action = 'user_action.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete_role"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form); form.submit();
    }
}
function confirmDuplicateRoleLocal(id) { 
    if(confirm('Duplikasi role ini beserta seluruh matriks izinnya?')) {
        const form = document.createElement('form'); form.method = 'POST'; form.action = 'user_action.php';
        form.innerHTML = `<input type="hidden" name="action" value="duplicate_role"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form); form.submit();
    }
}
function confirmDeleteUserLocal(id) { 
    if(id == 1) { alert("User Superadmin tidak boleh dihapus."); return; } 
    if(confirm('Hapus akun pengguna?')) {
        const form = document.createElement('form'); form.method = 'POST'; form.action = 'user_action.php';
        form.innerHTML = `<input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(form); form.submit();
    }
}
</script>