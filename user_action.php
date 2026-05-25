<?php
/**
 * user_action.php - CONTROLLER KEAMANAN, RBAC & WORKFLOW ERP SYIFA
 * Versi: 20.0 (Sovereign Grand Master - Database Binder Edition)
 * STATUS: FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak: 
 * 1. Mengubah struktur Query UPDATE & INSERT agar MENGANDUNG `landing_page` dan `unit_id`.
 * 2. Fungsi Duplicate & Delete dipastikan aman.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$uid_actor = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * HELPER: Done Notification Redirect
 */
function done($type, $msg, $tab = 'user') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: index.php?page=user_management&tab=$tab");
    exit;
}

function grantParentRecursive($conn, $role_id, $menu_id) {
    $q = $conn->query("SELECT parent_id FROM menus WHERE id = $menu_id")->fetch_assoc();
    if ($q && !empty($q['parent_id']) && $q['parent_id'] > 0) {
        $pid = (int)$q['parent_id'];
        $check = $conn->query("SELECT id FROM role_permissions WHERE role_id=$role_id AND menu_id=$pid");
        if ($check->num_rows == 0) {
            $conn->query("INSERT INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) VALUES ($role_id, $pid, 1, 0, 0, 0)");
        } else {
            $conn->query("UPDATE role_permissions SET can_view=1 WHERE role_id=$role_id AND menu_id=$pid");
        }
        grantParentRecursive($conn, $role_id, $pid);
    }
}

// 🛡️ AUTO-HEALER DB COLUMNS
@$conn->query("ALTER TABLE roles ADD COLUMN IF NOT EXISTS landing_page VARCHAR(100) DEFAULT 'dashboard_eksekutif'");
@$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS sidebar_style VARCHAR(20) DEFAULT 'accordion'");

switch ($action) {
    case 'save_user':
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role_id = (int)$_POST['role_id'];
        $wf = $_POST['jabatan_workflow'] ?? 'MAKER';
        $sidebar = $_POST['sidebar_style'] ?? 'accordion';
        $status = 1;
        $password = trim($_POST['password'] ?? '');

        if ($id) {
            if (!empty($password) && $password !== '********') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name=?, email=?, role_id=?, jabatan_workflow=?, sidebar_style=?, status=?, password=? WHERE id=?");
                $stmt->bind_param("ssissssi", $name, $email, $role_id, $wf, $sidebar, $status, $hashed, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name=?, email=?, role_id=?, jabatan_workflow=?, sidebar_style=?, status=? WHERE id=?");
                $stmt->bind_param("ssisssi", $name, $email, $role_id, $wf, $sidebar, $status, $id);
            }
        } else {
            $password_to_hash = (!empty($password) && $password !== '********') ? $password : '123456';
            $hashed = password_hash($password_to_hash, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, role_id, jabatan_workflow, sidebar_style, status, password) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssissss", $name, $email, $role_id, $wf, $sidebar, $status, $hashed);
        }

        if ($stmt->execute()) {
            if ($id == $uid_actor) { $_SESSION['sidebar_style'] = $sidebar; }
            done('success', "Data pengguna dan gaya sidebar diperbarui.");
        } else {
            done('danger', "Gagal menyimpan konfigurasi: " . $conn->error);
        }
        break;

    case 'save_role':
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $role_name = trim($_POST['role_name']);
        $is_ka_unit = isset($_POST['is_ka_unit']) ? 1 : 0;
        
        // 🚀 MURNI MENANGKAP VARIABEL UNIT ALL AROUND DAN LANDING PAGE
        $unit_id = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : 0; 
        $landing_page = trim($_POST['landing_page'] ?? 'dashboard_eksekutif');

        // 🚀 MENYUNTIKKAN PARAMETER KE DATABASE
        if ($id) {
            $stmt = $conn->prepare("UPDATE roles SET role_name=?, is_ka_unit=?, unit_id=?, landing_page=? WHERE id=?");
            $stmt->bind_param("siisi", $role_name, $is_ka_unit, $unit_id, $landing_page, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO roles (role_name, is_ka_unit, unit_id, landing_page) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("siis", $role_name, $is_ka_unit, $unit_id, $landing_page);
        }
        
        if ($stmt->execute()) done('success', "Role Jabatan dan Halaman Utama berhasil disimpan.", 'role');
        else done('danger', "Error: " . $conn->error, 'role');
        break;

    case 'delete_role':
        $id = (int)$_POST['id'];
        if($id != 1) { 
            $conn->query("DELETE FROM role_permissions WHERE role_id = $id");
            $conn->query("DELETE FROM approval_workflow WHERE role_id = $id");
            $conn->query("DELETE FROM roles WHERE id = $id");
        }
        done('success', "Role Jabatan beserta seluruh otoritasnya berhasil dihapus.", 'role');
        break;

    case 'duplicate_role':
        $id = (int)$_POST['id'];
        $conn->begin_transaction();
        try {
            $old = $conn->query("SELECT * FROM roles WHERE id = $id")->fetch_assoc();
            if($old) {
                $new_name = $old['role_name'] . ' (Copy)';
                $stmt = $conn->prepare("INSERT INTO roles (role_name, is_ka_unit, unit_id, landing_page) VALUES (?, ?, ?, ?)");
                $landing = $old['landing_page'] ?? 'dashboard_eksekutif';
                $stmt->bind_param("siis", $new_name, $old['is_ka_unit'], $old['unit_id'], $landing);
                $stmt->execute();
                $new_id = $conn->insert_id;

                $conn->query("INSERT INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) SELECT $new_id, menu_id, can_view, can_add, can_edit, can_delete FROM role_permissions WHERE role_id = $id");
                
                $conn->commit();
                done('success', "Role <b>{$old['role_name']}</b> berhasil diduplikasi beserta matriks izinnya.", 'role');
            } else { throw new Exception("Role sumber tidak ditemukan."); }
        } catch (Exception $e) { $conn->rollback(); done('danger', "Gagal menduplikasi role.", 'role'); }
        break;

    case 'update_permissions':
        $role_id = (int)$_POST['role_id'];
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM role_permissions WHERE role_id = $role_id");
            if (isset($_POST['perm'])) {
                $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($_POST['perm'] as $menu_id => $acts) {
                    $v = isset($acts['view']) ? 1 : 0; 
                    $a = isset($acts['add'])  ? 1 : 0;
                    $e = isset($acts['edit']) ? 1 : 0; 
                    $d = isset($acts['delete'])? 1 : 0;
                    
                    if ($v || $a || $e || $d) {
                        $v = 1; // Auto set view = 1
                        $stmt->bind_param("iiiiii", $role_id, $menu_id, $v, $a, $e, $d);
                        $stmt->execute();
                        grantParentRecursive($conn, $role_id, $menu_id);
                    }
                }
            }
            $conn->commit();
            done('success', "Matriks Izin disinkronkan. Struktur hirarki menu otomatis disesuaikan.", 'role');
        } catch (Exception $e) { 
            $conn->rollback(); 
            done('danger', "Gagal simpan matriks: " . $e->getMessage(), 'role'); 
        }
        break;

    case 'save_workflow_step':
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $role_id = (int)$_POST['role_id'];
        $step_order = (int)$_POST['step_order'];
        $step_name = trim($_POST['step_name']);
        $is_final = isset($_POST['is_final']) ? 1 : 0;
        
        if ($id) {
            $stmt = $conn->prepare("UPDATE approval_workflow SET role_id=?, step_order=?, step_name=?, is_final=? WHERE id=?");
            $stmt->bind_param("iisii", $role_id, $step_order, $step_name, $is_final, $id);
            $stmt->execute();
        } else {
            if (isset($_POST['modules']) && is_array($_POST['modules'])) {
                $stmt = $conn->prepare("INSERT INTO approval_workflow (module, role_id, step_order, step_name, is_final) VALUES (?, ?, ?, ?, ?)");
                foreach ($_POST['modules'] as $mod) {
                    $stmt->bind_param("siisi", $mod, $role_id, $step_order, $step_name, $is_final);
                    $stmt->execute();
                }
            }
        }
        done('success', "Aturan alur workflow diperbarui.", 'workflow');
        break;

    case 'delete_workflow_step':
        $id = (int)$_GET['id'];
        $conn->query("DELETE FROM approval_workflow WHERE id = $id");
        done('success', "Langkah alur dihapus.", 'workflow');
        break;

    case 'delete_user':
        $id = (int)$_POST['id'];
        if ($id == $uid_actor) done('danger', "Dilarang menghapus akun sendiri.");
        $conn->query("DELETE FROM users WHERE id = $id");
        done('success', "Pengguna dihapus.");
        break;

    default: done('danger', "Aksi tidak dikenali."); break;
}
ob_end_flush();
?>