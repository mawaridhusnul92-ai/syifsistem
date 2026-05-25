<?php
/**
 * anggaran_belanja.php - BUDGET INTELLIGENCE COCKPIT (SUPREME EDITION)
 * Versi: 142.0 (Sovereign Grand Master - True Active Revert Guard Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak: 
 * 1. Mencegah layar blank (PHP backward compatibility fix).
 * 2. KPI Dashboard Realisasi kini menyedot MURNI seluruh transaksi pengeluaran (Beban).
 * 3. Logika Sisa/Lebih disempurnakan dengan metode cascading deduction.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

if (file_exists('helper_intelligence.php')) { require_once 'helper_intelligence.php'; }

// =========================================================================
// 🚀 THE BULLETPROOF AUTO-HEALER & GHOST SWEEPER ENGINE
// =========================================================================
try {
    @$conn->query("ALTER TABLE syifa_budget_headers MODIFY COLUMN status VARCHAR(50) DEFAULT 'Draft'");
    @$conn->query("ALTER TABLE syifa_budgets MODIFY COLUMN status VARCHAR(50) DEFAULT 'Draft'");
    
    @$conn->query("UPDATE syifa_budget_headers SET status = 'Draft' WHERE deskripsi LIKE '%[PERUBAHAN]%' AND (status = '' OR status IS NULL OR status = 'Draft Perubahan')");

    // 🛡️ THE AGGRESSIVE REVERT HEALER
    $tahun_list = $conn->query("SELECT DISTINCT tahun_anggaran FROM syifa_budget_headers");
    if ($tahun_list) {
        while ($t = $tahun_list->fetch_assoc()) {
            $thn = $t['tahun_anggaran'];
            $cek_app = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$thn' AND kategori='Belanja' AND status IN ('Approved', 'Generated')")->num_rows;
            if ($cek_app == 0) {
                // Tarik dokumen yang baru saja digantikan (Archived/Replaced)
                $rev = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$thn' AND kategori='Belanja' AND status IN ('Replaced', 'Archived', 'DIARSIPKAN (DIGANTIKAN)') ORDER BY id DESC LIMIT 1")->fetch_assoc();
                if ($rev) {
                    $r_id = $rev['id'];
                    $conn->query("UPDATE syifa_budget_headers SET status='Approved' WHERE id=$r_id");
                    $conn->query("UPDATE syifa_budgets SET status='Disetujui' WHERE header_id=$r_id AND kategori='Pengeluaran'");
                }
            }
        }
    }
} catch(Exception $e) {}

// =========================================================================
// 🚀 FUNCTION GUARD
// =========================================================================
if (!function_exists('formatRp')) {
    function formatRp($n) { return "Rp " . number_format($n ?? 0, 0, ',', '.'); }
}
if(!function_exists('cleanNumLocal')){ 
    function cleanNumLocal($val) { return (double)str_replace(['.', ','], '', $val ?? '0'); } 
}
if(!function_exists('safeQuerySumLocal')){
    function safeQuerySumLocal($conn, $sql) {
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) { $r = $res->fetch_row(); return (double)($r[0] ?? 0); }
        return 0;
    }
}
if(!function_exists('sendBellNotificationLocal')) {
    function sendBellNotificationLocal($conn, $judul, $pesan, $url, $target_group) {
        $role_filter = "u.jabatan_workflow = 'ALL' OR r.role_name = 'Superadmin' OR r.role_name = 'SUPERADMIN'";
        if ($target_group == 'checker') { $role_filter .= " OR u.jabatan_workflow = 'CHECKER'"; }
        if ($target_group == 'approver') { $role_filter .= " OR u.jabatan_workflow = 'APPROVER' OR u.jabatan_workflow = 'PIMPINAN'"; }
        $sql = "SELECT u.id FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE ($role_filter) AND u.status = 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO syifa_notifications (user_id, judul, pesan, url, action_url, is_read, status, created_at) VALUES (?, ?, ?, ?, ?, 0, 'unread', NOW())");
            if ($stmt) { while($u = $res->fetch_assoc()) { $stmt->bind_param("issss", $u['id'], $judul, $pesan, $url, $url); $stmt->execute(); } }
        }
    }
}

$check_cols = $conn->query("SHOW COLUMNS FROM syifa_budgets LIKE 'is_category'");
$has_hierarchy = ($check_cols && $check_cols->num_rows > 0);

$history = $conn->query("SELECT * FROM syifa_budget_headers WHERE kategori='Belanja' ORDER BY tahun_anggaran DESC, id DESC");

// =========================================================================
// 🚀 LOGIKA IZIN ADAPTIF (ANTI-BLANK FAILSAFE)
// =========================================================================
$allowed_tabs = [];
if(function_exists('hasAccess')) {
    if(hasAccess('ang_belanja') || hasAccess('anggaran_belanja') || isset($_SESSION['permissions']['ang_belanja'])) {
        $allowed_tabs = ['dashboard', 'input', 'monitoring'];
    } else {
        if(hasAccess('ang_bel_dash')) $allowed_tabs[] = 'dashboard';
        if(hasAccess('ang_bel_work')) $allowed_tabs[] = 'input'; 
        if(hasAccess('ang_bel_mon'))  $allowed_tabs[] = 'monitoring';
    }
}
if(empty($allowed_tabs)) { $allowed_tabs = ['dashboard', 'input', 'monitoring']; }

// =========================================================================
// 🚀 THE SELF-CONTAINED CONTROLLER
// =========================================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'count_mhs' && isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    ob_clean();
    header('Content-Type: application/json');
    $prodi = $conn->real_escape_string($_GET['prodi'] ?? '');
    $sistem = $conn->real_escape_string($_GET['sistem'] ?? '');
    $angkatan = $conn->real_escape_string($_GET['angkatan'] ?? '');
    $where = "1=1";
    if($prodi) $where .= " AND prodi_id = '$prodi'";
    if($sistem) $where .= " AND sistem_kuliah = '$sistem'";
    if($angkatan) $where .= " AND angkatan = '$angkatan'";
    $q = $conn->query("SELECT COUNT(id) as jml FROM syifa_mahasiswa WHERE $where");
    echo json_encode(['status' => 'success', 'jml' => $q ? $q->fetch_assoc()['jml'] : 0]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($action)) {
    $uid = (int)($_SESSION['user_id'] ?? 1);

    if ($action === 'create_header') {
        $deskripsi = $conn->real_escape_string($_POST['deskripsi']);
        $tahun = (int)$_POST['tahun_anggaran'];
        try {
            $conn->query("INSERT INTO syifa_budget_headers (deskripsi, tahun_anggaran, kategori, status, created_by, created_at) VALUES ('$deskripsi', $tahun, 'Belanja', 'Draft', $uid, NOW())");
            $new_h_id = $conn->insert_id; 
            header("Location: index.php?page=anggaran_belanja&tab=input&msg_type=success_create&new_id=$new_h_id&tahun=$tahun"); exit;
        } catch (Exception $e) { header("Location: index.php?page=anggaran_belanja&tab=input"); exit; }
    }

    if ($action === 'rename_worksheet') {
        $id = (int)$_POST['id'];
        $nama = $conn->real_escape_string($_POST['nama_baru']);
        $conn->query("UPDATE syifa_budget_headers SET deskripsi='$nama' WHERE id=$id");
        
        if (isset($_POST['return_view']) && $_POST['return_view'] == 'worksheet') {
            $hid = (int)$_POST['header_id'];
            header("Location: index.php?page=anggaran_belanja&view=worksheet&header_id=$hid&tab=input"); exit;
        }
        header("Location: index.php?page=anggaran_belanja&tab=input"); exit;
    }

    if ($action === 'duplicate_header' || $action === 'create_perubahan_header') {
        $id = (int)$_POST['id'];
        $new_name = $conn->real_escape_string($_POST['new_name']);
        
        $conn->begin_transaction();
        try {
            $q_old = $conn->query("SELECT * FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
            $tot = (double)$q_old['total_anggaran'];
            $target_year = ($action === 'create_perubahan_header') ? (int)$q_old['tahun_anggaran'] : (int)$_POST['new_year'];
            
            $conn->query("INSERT INTO syifa_budget_headers (deskripsi, tahun_anggaran, kategori, total_anggaran, status, created_by, created_at) VALUES ('$new_name', $target_year, 'Belanja', $tot, 'Draft', $uid, NOW())");
            $new_h_id = $conn->insert_id;

            $cats = $conn->query("SELECT * FROM syifa_budgets WHERE header_id=$id AND is_category=1 AND kategori='Pengeluaran'");
            while($c = $cats->fetch_assoc()) {
                $conn->query("INSERT INTO syifa_budgets (header_id, kode_akun, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES ($new_h_id, '{$c['kode_akun']}', $target_year, 'Pengeluaran', '{$c['jenis_belanja']}', {$c['nominal_pagu']}, 'Draft', 'RAPB', '{$c['uraian_manual']}', 1)");
                $new_c_id = $conn->insert_id;

                $items = $conn->query("SELECT * FROM syifa_budgets WHERE parent_id={$c['id']}");
                while($i = $items->fetch_assoc()) {
                    $conn->query("INSERT INTO syifa_budgets (header_id, parent_id, kode_akun, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES ($new_h_id, $new_c_id, '{$i['kode_akun']}', $target_year, 'Pengeluaran', '{$i['jenis_belanja']}', {$i['nominal_pagu']}, 'Draft', 'RAPB', '{$i['uraian_manual']}', 0)");
                    $new_i_id = $conn->insert_id;

                    $plans = $conn->query("SELECT * FROM syifa_budget_monthly_plan WHERE budget_id={$i['id']}");
                    while($p = $plans->fetch_assoc()) {
                        $conn->query("INSERT INTO syifa_budget_monthly_plan (budget_id, bulan, nominal_rencana) VALUES ($new_i_id, {$p['bulan']}, {$p['nominal_rencana']})");
                    }
                }
            }
            $conn->commit();
            $msg_type = ($action === 'create_perubahan_header') ? 'success_perubahan' : 'success_dup';
            header("Location: index.php?page=anggaran_belanja&tab=input&msg_type=$msg_type&tahun=$target_year&new_id=$new_h_id"); exit;
        } catch(Exception $e) { $conn->rollback(); header("Location: index.php?page=anggaran_belanja&tab=input"); exit; }
    }

    if ($action === 'save_worksheet_expense') {
        $header_id = (int)$_POST['header_id'];
        $thn = (int)($_POST['tahun_anggaran_worksheet'] ?? date('Y'));
        
        $status_to = 'Draft';
        if (isset($_POST['final'])) $status_to = 'Reviewed'; 
        if (isset($_POST['cancel'])) $status_to = 'Draft';

        $conn->begin_transaction();
        try {
            if (isset($_POST['cancel'])) {
                $conn->query("UPDATE syifa_budget_headers SET status='$status_to' WHERE id=$header_id");
                $conn->query("UPDATE syifa_budgets SET status='$status_to' WHERE header_id=$header_id AND kategori='Pengeluaran'");
                $conn->commit();
                header("Location: index.php?page=anggaran_belanja&view=worksheet&header_id=$header_id&tab=input&msg_type=success_cancel"); exit;
            } else {
                $row_types = $_POST['row_type'] ?? [];
                $ui_keys = $_POST['ui_key'] ?? [];
                $parent_keys = $_POST['parent_key'] ?? [];
                $jenis_arr = $_POST['jenis'] ?? []; 
                $coa_arr = $_POST['coa'] ?? [];
                $uraians = $_POST['uraian_manual'] ?? [];
                
                $conn->query("DELETE FROM syifa_budget_monthly_plan WHERE budget_id IN (SELECT id FROM syifa_budgets WHERE header_id=$header_id AND kategori='Pengeluaran')");
                $conn->query("DELETE FROM syifa_budgets WHERE header_id=$header_id AND kategori='Pengeluaran'");

                $cat_map = []; $total_anggaran = 0; $t_ops = 0; $t_dev = 0;

                for ($i = 0; $i < count($row_types); $i++) {
                    $r_type = $row_types[$i];
                    $u_key = $ui_keys[$i];
                    $p_key = $parent_keys[$i];
                    $jenis = !empty($jenis_arr[$i]) ? $conn->real_escape_string($jenis_arr[$i]) : 'Operasional'; 
                    $coa = $conn->real_escape_string($coa_arr[$i]);
                    $uraian = $conn->real_escape_string($uraians[$i]);
                    
                    $pagu_total_input = cleanNumLocal($_POST['total'][$i] ?? 0);
                    $m_vals = [];
                    for($m=1; $m<=12; $m++) { $m_vals[$m] = cleanNumLocal($_POST["m{$m}"][$i] ?? 0); }

                    if ($r_type == 'category') {
                        $stmt = $conn->prepare("INSERT INTO syifa_budgets (header_id, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES (?, ?, 'Pengeluaran', ?, 0, ?, 'RAPB', ?, 1)");
                        $stmt->bind_param("iisss", $header_id, $thn, $jenis, $status_to, $uraian);
                        $stmt->execute();
                        $cat_map[$u_key] = $conn->insert_id;
                    } else if ($r_type == 'item') {
                        $p_id = $cat_map[$p_key] ?? 0;
                        
                        $item_status = $status_to;
                        if($status_to == 'Reviewed') $item_status = 'Menunggu Approval';
                        else if($status_to == 'Approved') $item_status = 'Disetujui';
                        
                        $stmt = $conn->prepare("INSERT INTO syifa_budgets (header_id, parent_id, kode_akun, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES (?, ?, ?, ?, 'Pengeluaran', ?, ?, ?, 'RAPB', ?, 0)");
                        $stmt->bind_param("iisisdss", $header_id, $p_id, $coa, $thn, $jenis, $pagu_total_input, $item_status, $uraian);
                        $stmt->execute();
                        $item_id = $conn->insert_id;

                        $total_anggaran += $pagu_total_input;
                        if($jenis == 'Operasional') $t_ops += $pagu_total_input; else $t_dev += $pagu_total_input;

                        $conn->query("UPDATE syifa_budgets SET nominal_pagu = nominal_pagu + $pagu_total_input WHERE id = $p_id");

                        $stmt_m = $conn->prepare("INSERT INTO syifa_budget_monthly_plan (budget_id, bulan, nominal_rencana) VALUES (?, ?, ?)");
                        foreach ($m_vals as $m => $v) {
                            if ($v > 0) { $stmt_m->bind_param("iid", $item_id, $m, $v); $stmt_m->execute(); }
                        }
                    }
                }
                
                $conn->query("UPDATE syifa_budget_headers SET total_anggaran=$total_anggaran, status='$status_to' WHERE id=$header_id");
                $conn->commit();
                
                if($status_to == 'Reviewed') {
                    $h_info = $conn->query("SELECT deskripsi FROM syifa_budget_headers WHERE id=$header_id")->fetch_assoc();
                    $notif_title = (strpos($h_info['deskripsi'], '[PERUBAHAN]') !== false) ? "Anggaran Perubahan Menunggu Approval" : "RAPB Menunggu Approval";
                    sendBellNotificationLocal($conn, $notif_title, "Worksheet {$h_info['deskripsi']} telah diajukan.", "index.php?page=anggaran_belanja&tab=input", "approver");
                }

                $act_lbl = isset($_POST['final']) ? 'success_review' : 'success_draft';
                header("Location: index.php?page=anggaran_belanja&view=worksheet&header_id=$header_id&tab=input&tahun=$thn&msg_type=$act_lbl&t=$total_anggaran&ops=$t_ops&dev=$t_dev"); exit;
            }
        } catch (Exception $e) { $conn->rollback(); header("Location: index.php?page=anggaran_belanja&view=worksheet&header_id=$header_id&tab=input"); exit; }
    }

    if ($action === 'approve_budget_async') {
        ob_clean();
        $id = (int)$_POST['id'];
        $conn->begin_transaction();
        try {
            $q = $conn->query("SELECT tahun_anggaran, deskripsi FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
            $thn_anggaran = $q['tahun_anggaran'];
            $is_perubahan = (strpos($q['deskripsi'], '[PERUBAHAN]') !== false);
            
            $conn->query("UPDATE syifa_budget_headers SET status='Replaced' WHERE tahun_anggaran=$thn_anggaran AND kategori='Belanja' AND status='Approved' AND id != $id");
            $conn->query("UPDATE syifa_budgets SET status='Replaced' WHERE tahun_anggaran=$thn_anggaran AND kategori='Pengeluaran' AND status='Disetujui' AND sumber_data='RAPB' AND header_id != $id");
            
            $conn->query("UPDATE syifa_budget_headers SET status='Approved' WHERE id=$id");
            $conn->query("UPDATE syifa_budgets SET status='Disetujui' WHERE header_id=$id AND kategori='Pengeluaran'");
            
            $conn->commit();
            $msg = $is_perubahan ? 'Anggaran Perubahan Disetujui! RAPB lama diarsipkan.' : 'RAPB Disetujui! Anggaran resmi aktif.';
            echo json_encode(['status' => 'success', 'msg' => $msg]); exit;
        } catch(Exception $e) { $conn->rollback(); echo json_encode(['status' => 'error', 'msg' => 'Gagal: '.$e->getMessage()]); exit; }
    }
    
    if ($action === 'cancel_approval_async') {
        ob_clean();
        $id = (int)$_POST['id'];
        $conn->begin_transaction();
        try {
            $q = $conn->query("SELECT tahun_anggaran, deskripsi FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
            
            $conn->query("UPDATE syifa_budget_headers SET status='Reviewed' WHERE id=$id");
            $conn->query("UPDATE syifa_budgets SET status='Menunggu Approval' WHERE header_id=$id AND kategori='Pengeluaran'");
            
            $thn = $q['tahun_anggaran'];
            $cek_app = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$thn' AND kategori='Belanja' AND status IN ('Approved', 'Generated')")->num_rows;
            if ($cek_app == 0) {
                $conn->query("UPDATE syifa_budget_headers SET status='Approved' WHERE tahun_anggaran='$thn' AND kategori='Belanja' AND status IN ('Replaced', 'Archived') ORDER BY id DESC LIMIT 1");
                $conn->query("UPDATE syifa_budgets SET status='Disetujui' WHERE tahun_anggaran='$thn' AND kategori='Pengeluaran' AND status IN ('Replaced', 'Archived') AND sumber_data='RAPB'");
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'msg' => 'Persetujuan dibatalkan. RAPB sebelumnya diaktifkan kembali.']); exit;
        } catch(Exception $e) { $conn->rollback(); echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]); exit; }
    }
}

if ($action === 'delete_header' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $check = $conn->query("SELECT status FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
    if($check && in_array($check['status'], ['Approved', 'Generated'])) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: Worksheet yang sudah Disahkan tidak dapat dihapus.'];
        header("Location: index.php?page=anggaran_belanja&tab=input"); exit;
    }
    $conn->begin_transaction();
    try {
        $q = $conn->query("SELECT tahun_anggaran FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
        
        $conn->query("DELETE FROM syifa_budget_monthly_plan WHERE budget_id IN (SELECT id FROM syifa_budgets WHERE header_id=$id AND kategori='Pengeluaran')");
        $conn->query("DELETE FROM syifa_budgets WHERE header_id=$id AND kategori='Pengeluaran'");
        $conn->query("DELETE FROM syifa_budget_headers WHERE id=$id");
        
        if ($q) {
            $thn = $q['tahun_anggaran'];
            $cek_app = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$thn' AND kategori='Belanja' AND status IN ('Approved', 'Generated')")->num_rows;
            if ($cek_app == 0) {
                $revert_h = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$thn' AND kategori='Belanja' AND status IN ('Replaced', 'Archived', 'DIARSIPKAN (DIGANTIKAN)') ORDER BY id DESC LIMIT 1")->fetch_assoc();
                if ($revert_h) {
                    $r_hid = $revert_h['id'];
                    $conn->query("UPDATE syifa_budget_headers SET status='Approved' WHERE id=$r_hid");
                    $conn->query("UPDATE syifa_budgets SET status='Disetujui' WHERE header_id=$r_hid AND kategori='Pengeluaran'");
                }
            }
        }
        
        $conn->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Worksheet Dihapus! Anggaran sebelumnya otomatis aktif kembali.'];
    } catch(Exception $e) { $conn->rollback(); }
    header("Location: index.php?page=anggaran_belanja&tab=input"); exit;
}
// =========================================================================

echo '<script src="assets/js/toast.js"></script>';

$tahun = $_GET['tahun'] ?? date('Y');
$view_mode = $_GET['view'] ?? 'hub';
$header_id = (int)($_GET['header_id'] ?? 0);

$uid_actor = $_SESSION['user_id'];
$u_wf_query = $conn->query("SELECT u.jabatan_workflow, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = $uid_actor");
$u_wf_data = $u_wf_query->fetch_assoc();
$workflow_auth = strtoupper($u_wf_data['jabatan_workflow'] ?? '');
$role_name_upper = strtoupper($u_wf_data['role_name'] ?? '');
$is_superadmin_root = ($_SESSION['role_id'] == 1 || $role_name_upper == 'SUPERADMIN');

$active_tab = $_GET['tab'] ?? ($allowed_tabs[0] ?? 'dashboard');
if($view_mode == 'worksheet') $active_tab = "input";

if (!in_array($active_tab, $allowed_tabs) && count($allowed_tabs) > 0) {
    $active_tab = $allowed_tabs[0]; 
}

// =========================================================================
// 🚀 THE ULTIMATE SINGLE SOURCE OF TRUTH ENGINE
// =========================================================================

$q_active = $conn->query("SELECT id, total_anggaran, created_at FROM syifa_budget_headers WHERE tahun_anggaran='$tahun' AND kategori='Belanja' AND status='Approved' ORDER BY id DESC LIMIT 1");
$active_header_id = 0; $pagu_worksheet_aktif = 0; $tgl_aktif = date('Y-m-d H:i:s');
if($q_active && $q_active->num_rows > 0) {
    $row_act = $q_active->fetch_assoc();
    $active_header_id = (int)$row_act['id'];
    $pagu_worksheet_aktif = (double)$row_act['total_anggaran'];
    $tgl_aktif = $row_act['created_at'];
}

$q_awal = $conn->query("SELECT total_anggaran FROM syifa_budget_headers WHERE tahun_anggaran='$tahun' AND kategori='Belanja' AND status IN ('Approved', 'Replaced', 'Archived') AND deskripsi NOT LIKE '%[PERUBAHAN]%' ORDER BY id ASC LIMIT 1");
$pagu_awal_murni = $q_awal && $q_awal->num_rows > 0 ? (double)$q_awal->fetch_assoc()['total_anggaran'] : $pagu_worksheet_aktif;

$q_unit = $conn->query("SELECT SUM(nominal_pagu) as t FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND is_category=0 AND kategori='Pengeluaran' AND sumber_data='UNIT_APPROVED' AND status='Disetujui'");
$pagu_tambahan_unit = (double)($q_unit->fetch_assoc()['t'] ?? 0);

$selisih_worksheet = $pagu_worksheet_aktif - $pagu_awal_murni;
$total_perubahan_kpi = $selisih_worksheet + $pagu_tambahan_unit;
$total_pagu_akhir = $pagu_awal_murni + $total_perubahan_kpi;
$total_pagu = $total_pagu_akhir; 

// A. Tarik Tambahan Pagu dari Unit
$tabel_perubahan_unified = [];
$res_unit = $conn->query("SELECT uraian_manual, nominal_pagu, created_at FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND status='Disetujui' AND is_category=0 AND kategori='Pengeluaran' AND sumber_data='UNIT_APPROVED' ORDER BY created_at ASC");
if($res_unit) {
    while($r = $res_unit->fetch_assoc()) {
        $tabel_perubahan_unified[] = ['sumber' => 'UNIT', 'uraian' => 'Tambahan Pagu Unit: ' . $r['uraian_manual'], 'tgl' => $r['created_at'], 'sebelum' => 0, 'sesudah' => (double)$r['nominal_pagu'], 'selisih' => (double)$r['nominal_pagu']];
    }
}

$q_all_headers = $conn->query("SELECT id, created_at FROM syifa_budget_headers WHERE tahun_anggaran='$tahun' AND kategori='Belanja' AND status IN ('Approved', 'Replaced', 'Archived') ORDER BY id ASC");
$header_history = [];
if($q_all_headers) while($row = $q_all_headers->fetch_assoc()) { $header_history[] = $row; }

if(count($header_history) > 1) {
    for($i = 1; $i < count($header_history); $i++) {
        $id_lama = $header_history[$i-1]['id'];
        $id_baru = $header_history[$i]['id'];
        $tgl_sah = $header_history[$i]['created_at'];
        
        $items_lama = [];
        $q_lama = $conn->query("SELECT kode_akun, nominal_pagu FROM syifa_budgets WHERE header_id=$id_lama AND is_category=0");
        if($q_lama) while($r = $q_lama->fetch_assoc()) $items_lama[$r['kode_akun']] = (double)$r['nominal_pagu'];
        
        $items_baru = [];
        $q_baru = $conn->query("SELECT kode_akun, uraian_manual, nominal_pagu FROM syifa_budgets WHERE header_id=$id_baru AND is_category=0");
        if($q_baru) while($r = $q_baru->fetch_assoc()) $items_baru[$r['kode_akun']] = $r;
        
        $all_kodes = array_unique(array_merge(array_keys($items_lama), array_keys($items_baru)));
        foreach($all_kodes as $kode) {
            $v_lama = $items_lama[$kode] ?? 0;
            $v_baru = $items_baru[$kode]['nominal_pagu'] ?? 0;
            if($v_lama != $v_baru) {
                $uraian = $items_baru[$kode]['uraian_manual'] ?? 'Item Terhapus / Diganti';
                $tabel_perubahan_unified[] = [
                    'sumber' => 'WORKSHEET', 'uraian' => 'Revisi RAPB: ' . $uraian, 'tgl' => $tgl_sah, 'sebelum' => $v_lama, 'sesudah' => $v_baru, 'selisih' => $v_baru - $v_lama
                ];
            }
        }
    }
}

// 🚀 FIX: Fallback untuk support versi PHP lama agar layar tidak blank
usort($tabel_perubahan_unified, function($a, $b) { return (strtotime($b['tgl']) > strtotime($a['tgl'])) ? 1 : -1; });

$sql_p_split = "SELECT SUM(CASE WHEN jenis_belanja='Operasional' THEN nominal_pagu ELSE 0 END) AS p70, SUM(CASE WHEN jenis_belanja='Pengembangan' THEN nominal_pagu ELSE 0 END) AS p30 FROM syifa_budgets WHERE tahun_anggaran = '$tahun' AND status='Disetujui' AND is_category=0 AND kategori='Pengeluaran' AND (header_id=$active_header_id OR sumber_data='UNIT_APPROVED')";
$dp = $conn->query($sql_p_split)->fetch_assoc();
$p_70 = (double)($dp['p70'] ?? 0); $p_30 = (double)($dp['p30'] ?? 0);

// 🚀 5. REALISASI KAS MURNI (GL) - ABSOLUTE KPI EXTRACTOR
$sql_r_total = "SELECT a.nama_akun as uraian_manual, SUM(jd.debit - jd.kredit) as total_v, a.kode_akun 
                FROM syifa_jurnal_detail jd 
                JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                JOIN syifa_akun a ON jd.kode_akun = a.kode_akun 
                WHERE YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted = 0 AND a.kategori IN ('Beban', 'Pengeluaran') 
                GROUP BY jd.kode_akun HAVING total_v > 0";

$budget_jenis_map = [];
if ($active_header_id > 0) {
    $q_b = $conn->query("SELECT kode_akun, jenis_belanja FROM syifa_budgets WHERE header_id=$active_header_id AND is_category=0");
    if($q_b) { while($qb = $q_b->fetch_assoc()){ $budget_jenis_map[$qb['kode_akun']] = $qb['jenis_belanja']; } }
}

$res_r = $conn->query($sql_r_total);
$r_70 = 0; $r_30 = 0; $total_real = 0;
$breakdown_real = [];

if($res_r) {
    while($row = $res_r->fetch_assoc()){
        $val = (double)$row['total_v'];
        $total_real += $val;
        
        $jns = $budget_jenis_map[$row['kode_akun']] ?? 'Operasional';
        if($jns == 'Pengembangan') $r_30 += $val;
        else $r_70 += $val;
        
        $breakdown_real[] = $row;
    }
}

// 🚀 FIX: Fallback usort untuk Grafik Top Pos Belanja (Layar Blank Fix)
usort($breakdown_real, function($a, $b) { return ($b['total_v'] > $a['total_v']) ? 1 : -1; });

$s_70 = max(0, $p_70 - $r_70); $s_30 = max(0, $p_30 - $r_30);
$variance = $total_pagu - $total_real;

if(!function_exists('calculateBurnRate')) { function calculateBurnRate($pagu, $real) { return $pagu > 0 ? ($real / $pagu) * 100 : 0; } }
$burn_rate = calculateBurnRate($total_pagu, $total_real);
$ratio_ops_pct = ($total_real > 0) ? ($r_70 / $total_real) * 100 : 0;
$ratio_narrative = ($ratio_ops_pct > 0) ? ($ratio_ops_pct <= 70.5 ? "Sesuai Kebijakan" : "Waspada: Ops Tinggi") : "Belum Ada Realisasi";

$coa_raw = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE kategori='Beban' AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC");
$coa_list = []; while($c = $coa_raw->fetch_assoc()) { $c['nama_akun'] = str_replace(["'", '"', '`'], "", $c['nama_akun']); $coa_list[] = $c; }

$trend_data = array_fill(1, 12, ['ops'=>0, 'dev'=>0]);
$sql_t = "SELECT MONTH(j.tgl_jurnal) as bulan, 
            SUM(CASE WHEN b.jenis_belanja='Pengembangan' THEN 0 ELSE (jd.debit-jd.kredit) END) AS ops, 
            SUM(CASE WHEN b.jenis_belanja='Pengembangan' THEN (jd.debit-jd.kredit) ELSE 0 END) AS dev 
          FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON j.id=jd.jurnal_id JOIN syifa_akun a ON jd.kode_akun=a.kode_akun 
          LEFT JOIN syifa_budgets b ON jd.kode_akun=b.kode_akun AND b.header_id=$active_header_id 
          WHERE YEAR(j.tgl_jurnal)='$tahun' AND j.is_deleted=0 AND a.kategori IN ('Beban', 'Pengeluaran') 
          GROUP BY MONTH(j.tgl_jurnal)";
$res_t = $conn->query($sql_t);
if($res_t) while($t = $res_t->fetch_assoc()) { $trend_data[(int)$t['bulan']] = ['ops'=>$t['ops'], 'dev'=>$t['dev']]; }

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    .modal.fade .modal-dialog { transform: scale(0.6); opacity: 0; transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .modal.show .modal-dialog { transform: scale(1); opacity: 1; }
    .supreme-container { width: 100%; overflow: visible; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; position: relative; }
    .table-responsive-supreme { overflow-x: auto; overflow-y: hidden; position: relative; border-radius: 16px; scroll-behavior: smooth; transform: translateZ(0); cursor: grab; }
    .table-responsive-supreme:active { cursor: grabbing; }
    .table-supreme { border-collapse: separate; border-spacing: 0; table-layout: auto !important; width: max-content !important; min-width: 100%; }
    .table-supreme td, .table-supreme th { white-space: nowrap; padding: 12px 10px; border-right: 1px solid #f1f5f9; vertical-align: middle; }
    .table-supreme thead th { position: sticky; top: 0; background: #f8fafc !important; z-index: 100; border-bottom: 2px solid #cbd5e1; font-size: 10px; font-weight: 800; text-transform: uppercase; color: #475569; text-align: center !important; }
    .table-supreme thead tr:nth-child(2) th { top: 38px !important; z-index: 99 !important; }
    .table-supreme thead th[class*="-f-"] { z-index: 501 !important; background: #f8fafc !important; }
    
    .ws-f-action, .mon-f-uraian { position: sticky; left: 0; z-index: 110 !important; background: #ffffff !important; width: 65px; border-right: 1px solid #cbd5e1; }
    .ws-f-uraian   { position: sticky; left: 65px; z-index: 110 !important; background: #ffffff !important; border-right: 2px solid #cbd5e1 !important; width: 350px; }
    .ws-f-kategori { position: sticky; left: 415px; z-index: 110 !important; background: #fcfcfc !important; border-right: 1px solid #cbd5e1 !important; width: 180px; text-align: center; }
    .ws-f-coa      { position: sticky; left: 595px; z-index: 110 !important; background: #ffffff !important; border-right: 1px solid #cbd5e1 !important; width: 220px; text-align: center; }
    .ws-f-pagu     { position: sticky; left: 815px; z-index: 110 !important; background: #f8fafc !important; border-right: 4px double #cbd5e1 !important; width: 180px; text-align: right; }
    
    .mon-f-uraian { width: 380px; }
    .mon-f-pagu   { position: sticky; left: 380px; z-index: 110 !important; background: #f8fafc !important; width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1; }
    .mon-f-real   { position: sticky; left: 560px; z-index: 110 !important; background: #fcfcfc !important; width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1; }
    .mon-f-sisa   { position: sticky; left: 740px; z-index: 110 !important; background: #ffffff !important; width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1; }
    .mon-f-persen { position: sticky; left: 920px; z-index: 110 !important; background: #f8fafc !important; width: 120px; text-align: center !important; border-right: 4px double #cbd5e1 !important; }

    .inp-uraian { width: 100%; border: none !important; background: transparent !important; padding: 8px 5px; font-size: 13.5px; transition: 0.3s; color: #1e293b; box-shadow: none !important; outline: none !important; }
    .inp-uraian:focus { border-bottom: 2px solid #0d6efd !important; background: rgba(13, 110, 253, 0.03) !important; }
    .coa-search-input, .inp-amt { border: 1.5px solid #e2e8f0 !important; border-radius: 10px !important; padding: 10px 15px !important; background: #fcfdfe !important; transition: 0.3s all ease; font-size: 13px; font-weight: 600; color: #1e293b; }
    .coa-search-input:focus, .inp-amt:focus { border-color: #0d6efd !important; background: #ffffff !important; outline: none !important; box-shadow: 0 0 0 4px rgba(13,110,253,0.12) !important; }
    .row-approved { background-color: rgba(25, 135, 84, 0.08) !important; transition: background-color 0.6s ease; }
    .status-msg .badge { font-size: 10px !important; padding: 6px 10px !important; border-radius: 6px !important; font-weight: 800 !important; }
    .coa-results-list { position: fixed !important; transform: translateZ(0); background: #ffffff !important; border: 1.5px solid #0d6efd !important; border-radius: 12px !important; z-index: 999999 !important; max-height: 280px; overflow-y: auto; display: none; box-shadow: 0 15px 45px rgba(0,0,0,0.25) !important; padding: 5px 0; }
    .coa-item { padding: 12px 18px; cursor: pointer; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; font-size: 12.5px; color: #334155; }
    .coa-item:hover { background: #f0f7ff; color: #0d6efd; font-weight: 700; }
    .coa-item code { color: #ef4444; font-weight: 900; background: #fff1f2; padding: 2px 8px; border-radius: 6px; margin-right: 15px; font-family: 'JetBrains Mono'; }
    .tr-cat td { background-color: #f0f9ff !important; font-weight: 800; color: #0369a1; border-top: 1px solid #bae6fd !important; }
    .tr-subtotal td { background-color: #f8fafc !important; font-weight: 800; color: #1e293b; border-top: 2px solid #cbd5e1; }
    .row-grand-total td { background: #1e293b !important; color: #fff !important; font-weight: 900; }
    .unmapped-row td { background: #fffbeb !important; color: #92400e !important; font-style: italic; }
    
    .kpi-card-solid { border-radius: 16px; padding: 24px; color: white; position: relative; overflow: hidden; box-shadow: 0 8px 15px rgba(0,0,0,0.08); display: flex; flex-direction: column; justify-content: center; min-height: 130px; transition: 0.3s; border: none; }
    .kpi-card-solid:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(0,0,0,0.12); }
    .kpi-card-solid .kpi-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; opacity: 0.9; margin-bottom: 8px; }
    .kpi-card-solid .kpi-value { font-size: 26px; font-weight: 900; line-height: 1.1; z-index: 2; margin-bottom: 0; }
    .kpi-card-solid .kpi-icon { position: absolute; right: -15px; bottom: -20px; font-size: 90px; opacity: 0.15; z-index: 1; transform: rotate(-10deg); }
    
    .bg-solid-blue { background-color: #2563eb !important; }
    .bg-solid-yellow { background-color: #f59e0b !important; }
    .bg-solid-green { background-color: #10b981 !important; }
    .bg-solid-info { background-color: #06b6d4 !important; }

    .nav-tabs .nav-link { color: #64748b; font-weight: 700; border: none; padding: 12px 20px; transition: 0.3s; border-radius: 12px 12px 0 0; }
    .nav-tabs .nav-link.active { color: #0d6efd !important; border-bottom: 4px solid #0d6efd !important; background: rgba(13, 110, 253, 0.05) !important; }
    .btn-insight { border-radius: 50%; width: 22px; height: 22px; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; margin-left: 8px; cursor: pointer; }
    .btn-rename { cursor: pointer; color: #64748b; transition: 0.2s; margin-left: 8px; font-size: 10px; }
    .btn-rename:hover { color: var(--bs-primary); transform: scale(1.2); }
    .policy-footer { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 15px 25px; border-radius: 12px; font-size: 12px; margin-top: 25px; }
    
    .progress-thin { height: 8px; border-radius: 10px; background-color: #e2e8f0; overflow: hidden; margin-top: 6px; }
    .progress-fill { height: 100%; border-radius: 10px; transition: width 1s ease-in-out; }
    .custom-list-item { padding: 14px 15px; border-bottom: 1px dashed #e2e8f0; transition: 0.2s; }
    .custom-list-item:hover { background-color: #f8fafc; }
    .custom-list-item:last-child { border-bottom: none; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4">
        <div><h6 class="text-uppercase text-muted fw-bold mb-1" style="font-size: 10px;">FINANCIAL CONTROL CENTER</h6><h3 class="fw-bold text-dark mb-0">Manajemen Anggaran Belanja <?= $tahun ?></h3></div>
        <div class="d-flex gap-2 align-items-center">
            <div class="d-none d-lg-flex px-3 py-2 bg-light rounded-pill border shadow-sm"><span class="fw-bold text-dark small">Status Rasio Ops: <span class="text-primary small"><?= $ratio_narrative ?></span></span></div>
            <select class="form-select border-0 bg-light rounded-pill px-3 fw-bold shadow-sm pe-4 text-primary" style="width: 130px;" onchange="location.href='?page=anggaran_belanja&tahun='+this.value">
                <?php for($y=date('Y')+1; $y>=2024; $y--) echo "<option value='$y' ".($tahun==$y?'selected':'').">$y</option>"; ?>
            </select>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4 border-bottom-0" id="budgetTabs">
        <?php if(in_array('dashboard', $allowed_tabs)): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='dashboard'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button"><i class="fas fa-chart-pie me-2"></i>Dashboard Analitik</button></li>
        <?php endif; ?>
        <?php if(in_array('input', $allowed_tabs)): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='input'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-input" type="button"><i class="fas fa-edit me-2"></i>Worksheet Anggaran</button></li>
        <?php endif; ?>
        <?php if(in_array('monitoring', $allowed_tabs)): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='monitoring'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-monitoring" type="button"><i class="fas fa-desktop me-2"></i>Monitoring Anggaran</button></li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">
        <?php if(in_array('dashboard', $allowed_tabs)): ?>
        <div class="tab-pane fade <?= $active_tab=='dashboard'?'show active':'' ?>" id="tab-dashboard">
            <div class="row g-3 mb-4 text-start">
                <div class="col-md-3"><div class="kpi-card-solid bg-solid-blue"><i class="fas fa-file-invoice-dollar kpi-icon"></i><div class="kpi-title">TOTAL PAGU AWAL (RAPB)</div><h3 class="kpi-value">Rp <?= number_format($pagu_awal_murni) ?></h3><div class="small opacity-75 mt-2 fw-bold" style="font-size: 10px;">(Yang disahkan awal tahun)</div></div></div>
                <div class="col-md-3"><div class="kpi-card-solid bg-solid-yellow"><i class="fas fa-folder-plus kpi-icon"></i><div class="kpi-title">TOTAL ANGGARAN PERUBAHAN</div><h3 class="kpi-value">Rp <?= number_format($total_perubahan_kpi) ?></h3><div class="small opacity-75 mt-2 fw-bold" style="font-size: 10px;">(Pagu tambahan & pergeseran)</div></div></div>
                <div class="col-md-3"><div class="kpi-card-solid bg-solid-green"><i class="fas fa-hand-holding-usd kpi-icon"></i><div class="kpi-title">REALISASI ANGGARAN (GL)</div><h3 class="kpi-value">Rp <?= number_format($total_real) ?></h3><div class="small opacity-75 mt-2 fw-bold" style="font-size: 10px;">(Berdasarkan Jurnal Buku Besar)</div></div></div>
                <div class="col-md-3"><div class="kpi-card-solid bg-solid-info"><i class="fas fa-chart-pie kpi-icon"></i><div class="kpi-title">SISA ANGGARAN BELANJA</div><h3 class="kpi-value">Rp <?= number_format($variance) ?></h3><div class="small opacity-75 mt-2 fw-bold" style="font-size: 10px;">(Total Pagu Keseluruhan - Realisasi)</div></div></div>
            </div>
            
            <div class="row g-4 mb-4 text-dark">
                <div class="col-lg-8"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100"><h6 class="fw-bold mb-4 text-muted uppercase text-start"><i class="fas fa-chart-line me-2 text-primary"></i>Tren Serapan Bulanan <button class="btn btn-warning btn-insight text-white shadow-sm" onclick="showInsight('trend')"><i class="fas fa-lightbulb"></i></button></h6><div class="chart-box" style="height: 300px;"><canvas id="chartTrendOpsDev"></canvas></div></div></div>
                <div class="col-lg-4"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100 text-center d-flex flex-column justify-content-center"><h6 class="fw-bold mb-3 text-muted uppercase text-start"><i class="fas fa-tachometer-alt me-2 text-warning"></i>Tingkat Kecepatan Serapan <button class="btn btn-warning btn-insight text-white shadow-sm" onclick="showInsight('serapan')"><i class="fas fa-lightbulb"></i></button></h6><div class="my-auto"><h1 class="display-2 fw-bold text-dark mb-0" style="letter-spacing: -2px;"><?= round($burn_rate, 1) ?>%</h1><p class="text-muted fw-bold mb-4 mt-2">Dari Total Pagu Tersedia</p><div class="progress-thin"><div class="progress-fill <?= $burn_rate > 80 ? 'bg-danger' : 'bg-primary' ?>" style="width: <?= min(100, $burn_rate) ?>%"></div></div></div></div></div>
            </div>

            <div class="row g-4 mb-4 text-dark">
                <div class="col-lg-4"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100 text-center"><h6 class="fw-bold mb-4 text-muted uppercase text-start"><i class="fas fa-chart-pie me-2 text-danger"></i>Top Pos Belanja (Realisasi) <button class="btn btn-warning btn-insight text-white shadow-sm" onclick="showInsight('rincian')"><i class="fas fa-lightbulb"></i></button></h6><div class="chart-box" style="height: 300px;"><canvas id="chartDonutRealization"></canvas></div></div></div>
                <div class="col-lg-8"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100"><h6 class="fw-bold mb-4 text-muted text-uppercase"><i class="fas fa-list-ol me-2 text-info"></i>Analisis Detail Top 5 Realisasi Belanja</h6><div class="table-responsive" style="overflow-x: hidden;"><table class="table table-borderless align-middle mb-0"><tbody>
                    <?php if(!empty($breakdown_real)) { $top5 = array_slice($breakdown_real, 0, 5); foreach($top5 as $idx => $br) { $val = (double)$br['total_v']; $pct = ($total_real > 0) ? ($val / $total_real) * 100 : 0; $colorClass = 'bg-primary'; if($idx == 0) $colorClass = 'bg-danger'; elseif($idx == 1) $colorClass = 'bg-warning'; elseif($idx == 2) $colorClass = 'bg-success'; ?>
                        <tr class="custom-list-item"><td class="w-50 text-start ps-0"><div class="fw-bold text-dark text-truncate" title="<?= htmlspecialchars($br['uraian_manual']) ?>"><?= $br['uraian_manual'] ?></div></td><td class="text-end fw-bold text-muted w-25">Rp <?= number_format($val, 0, ',', '.') ?></td><td class="w-25 pe-0"><div class="d-flex justify-content-between small fw-bold mb-1"><span class="text-muted">Andil</span><span class="text-dark"><?= round($pct, 1) ?>%</span></div><div class="progress-thin" style="height: 6px;"><div class="progress-fill <?= $colorClass ?>" style="width: <?= min(100, $pct) ?>%"></div></div></td></tr>
                    <?php } } else { echo '<tr><td colspan="3" class="text-center py-4 text-muted italic">Belum ada data realisasi belanja.</td></tr>'; } ?>
                </tbody></table></div></div></div>
            </div>

            <div class="row g-4 text-dark mb-4">
                <div class="col-lg-6"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100"><h6 class="fw-bold mb-4 text-muted uppercase"><i class="fas fa-chart-bar me-2 text-success"></i>Tingkat Penyerapan Ops vs Dev (%) </h6><div class="chart-box" style="height: 300px;"><canvas id="chartSerapan"></canvas></div></div></div>
                <div class="col-lg-6"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100"><h6 class="fw-bold mb-4 text-muted uppercase"><i class="fas fa-wallet me-2 text-primary"></i>Sisa Ruang Fiskal Ops vs Dev (Rp) </h6><div class="chart-box" style="height: 300px;"><canvas id="chartSisa"></canvas></div></div></div>
            </div>

            <div class="row text-dark mb-4">
                <div class="col-12"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white border-start border-warning border-4"><h6 class="fw-bold mb-4 text-muted text-uppercase"><i class="fas fa-file-signature me-2 text-warning"></i>Riwayat Pengesahan Anggaran Perubahan (Pagu Tambahan)</h6><div class="table-responsive"><table class="table table-hover align-middle mb-0 text-dark text-start"><thead class="table-light small text-muted uppercase fw-bold"><tr><th class="ps-4 text-start">Uraian / Deskripsi Tambahan</th><th class="text-end">Anggaran Sebelum Perubahan</th><th class="text-end">Anggaran Sesudah Perubahan</th><th class="text-center">Tanggal Disahkan</th><th class="text-end pe-4">Nominal Perubahan (Rp)</th></tr></thead><tbody>
                    <?php if(!empty($tabel_perubahan_unified)): foreach($tabel_perubahan_unified as $tp): $warna_selisih = $tp['selisih'] >= 0 ? 'text-success' : 'text-danger'; $tanda_selisih = $tp['selisih'] > 0 ? '+' : ''; ?>
                        <tr><td class="ps-4 fw-bold text-dark"><?= $tp['uraian'] ?></td><td class="text-end text-muted"><?= $tp['sebelum'] == 0 ? '-' : 'Rp ' . number_format($tp['sebelum'], 0, ',', '.') ?></td><td class="text-end fw-bold text-dark">Rp <?= number_format($tp['sesudah'], 0, ',', '.') ?></td><td class="text-center small"><span class="badge bg-light text-dark border px-3 py-1"><?= date('d F Y', strtotime($tp['tgl'])) ?></span></td><td class="text-end fw-bold <?= $warna_selisih ?> pe-4 fs-6"><?= $tanda_selisih ?> Rp <?= number_format($tp['selisih'], 0, ',', '.') ?></td></tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted italic">Belum ada revisi / perubahan anggaran yang disahkan.</td></tr>
                    <?php endif; ?>
                </tbody><tfoot class="bg-light fw-bold text-dark"><tr><td colspan="4" class="text-end pe-4 py-2">ANGGARAN BELANJA AWAL (RAPB MURNI)</td><td class="text-end pe-4 text-primary fs-6">Rp <?= number_format($pagu_awal_murni, 0, ',', '.') ?></td></tr><tr><td colspan="4" class="text-end pe-4 py-2">TOTAL ANGGARAN PERUBAHAN (PAGU TAMBAHAN)</td><td class="text-end pe-4 text-warning fs-6">+ Rp <?= number_format($total_perubahan_kpi, 0, ',', '.') ?></td></tr><tr class="table-dark text-white fs-5"><td colspan="4" class="text-end pe-4 py-3 uppercase">TOTAL ANGGARAN KESELURUHAN (Setelah Pagu Tambahan)</td><td class="text-end pe-4 text-white">Rp <?= number_format($total_pagu_akhir, 0, ',', '.') ?></td></tr></tfoot></table></div></div></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if(in_array('input', $allowed_tabs)): ?>
        <div class="tab-pane fade <?= $active_tab=='input'?'show active':'' ?>" id="tab-input">
            <?php if($view_mode == 'hub'): ?>
                <div class="text-center py-5 bg-white rounded-4 shadow-sm border mb-4">
                    <i class="fas fa-folder-open fa-3x text-primary mb-3"></i>
                    <h4 class="fw-bold text-dark">Manajemen Lembar Kerja Belanja</h4>
                    <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                        <button class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg mt-3" onclick="triggerModalNew()"><i class="fas fa-plus me-2"></i>BUAT WORKSHEET BARU</button>
                    <?php endif; ?>
                </div>
                
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark shadow-sm">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="bg-light small text-uppercase">
                            <tr><th>Aksi</th><th class="text-start ps-4">Uraian Worksheet</th><th>Tahun</th><th>Pagu</th><th>Status</th><th>Otorisasi</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            if($history && $history->num_rows > 0) { 
                                while($h = $history->fetch_assoc()) { 
                                    $is_perubahan = (strpos($h['deskripsi'], '[PERUBAHAN]') !== false);
                                    $is_approved = ($h['status'] == 'Approved' || $h['status'] == 'Generated');
                                    $is_reviewed = ($h['status'] == 'Reviewed');
                                    $is_draft    = ($h['status'] == 'Draft');
                                    $is_archived = ($h['status'] == '' || $h['status'] == 'Archived' || $h['status'] == 'Replaced');
                            ?>
                            <tr class="<?= $is_approved ? 'row-approved' : ($is_archived ? 'bg-light opacity-50' : '') ?>">
                                <td>
                                    <div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm">
                                        <a href="?page=anggaran_belanja&view=worksheet&header_id=<?= $h['id'] ?>&tab=input" class="btn btn-white text-primary border-end" title="<?= (!$is_approved && !$is_archived && defined('RBAC_EDIT') && RBAC_EDIT) ? 'Ubah/Tinjau' : 'Lihat Detail' ?>"><i class="fas <?= (!$is_approved && !$is_archived && defined('RBAC_EDIT') && RBAC_EDIT) ? 'fa-edit' : 'fa-eye' ?>"></i></a>
                                        
                                        <?php if(defined('RBAC_ADD') && RBAC_ADD && !$is_archived): ?>
                                            <button type="button" class="btn btn-white text-info border-end" onclick="triggerCloneModal(<?= $h['id'] ?>, '<?= addslashes($h['deskripsi']) ?>', '<?= $h['tahun_anggaran'] ?>')" title="Duplikasi"><i class="fas fa-clone"></i></button>
                                        <?php endif; ?>

                                        <?php if($is_draft && defined('RBAC_DEL') && RBAC_DEL): ?>
                                            <button onclick="confirmDelete(<?= $h['id'] ?>)" class="btn btn-white text-danger" title="Hapus"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-start ps-4 fw-bold">
                                    <span><?= htmlspecialchars($h['deskripsi']) ?></span>
                                    <?php if($is_draft && defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                        <i class="fas fa-pen-nib btn-rename" onclick="triggerRenameModal(<?= $h['id'] ?>, '<?= addslashes($h['deskripsi']) ?>')" title="Ubah Nama"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?= $h['tahun_anggaran'] ?></td>
                                <td class="text-end pe-4 fw-bold">Rp <?= number_format($h['total_anggaran']) ?></td>
                                
                                <td>
                                    <?php if($is_archived): ?>
                                        <span class="badge bg-light text-muted border border-secondary rounded-pill px-3">DIARSIPKAN (DIGANTIKAN)</span>
                                    <?php elseif($is_draft): ?>
                                        <span class="badge <?= $is_perubahan ? 'bg-warning text-dark border border-warning' : 'bg-secondary' ?> rounded-pill px-3"><?= $is_perubahan ? 'DRAFT PERUBAHAN' : 'DRAFT' ?></span>
                                    <?php elseif($is_reviewed): ?>
                                        <span class="badge <?= $is_perubahan ? 'bg-info text-dark' : 'bg-warning text-dark' ?> rounded-pill px-3"><?= $is_perubahan ? 'MENUNGGU PENGESAHAN PERUBAHAN' : 'MENUNGGU APPROVAL' ?></span>
                                    <?php elseif($is_approved): ?>
                                        <span class="badge bg-success rounded-pill px-3"><?= $is_perubahan ? '<i class="fas fa-check-double me-1"></i> SAH - ANGGARAN PERUBAHAN' : 'APPROVED' ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if($is_draft || $is_archived): ?>
                                        <span class="text-muted small italic">-</span>
                                    <?php elseif($is_reviewed): ?>
                                        <span class="text-warning fw-bold small"><i class="fas fa-hourglass-half me-1"></i> Menunggu Eksekusi</span>
                                    <?php elseif($is_approved): ?>
                                        <span class="text-success fw-bold small mb-1 d-block"><i class="fas fa-check-double me-1"></i> TEROTORISASI</span>
                                        <?php if(defined('RBAC_DEL') && RBAC_DEL && (in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root)): ?>
                                            <button type="button" class="btn btn-xs btn-outline-danger rounded-pill px-2 fw-bold" style="font-size: 9px;" onclick="cancelApproveAction({action: 'cancel_approval_async', id: <?= $h['id'] ?>}, this)"><i class="fas fa-undo me-1"></i> Batal Approve</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                } 
                            } else { 
                                echo "<tr><td colspan='6' class='text-center py-5 text-muted small italic'>Belum ada riwayat lembar kerja.</td></tr>"; 
                            } 
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php 
                    $h_data = $conn->query("SELECT * FROM syifa_budget_headers WHERE id=$header_id")->fetch_assoc(); 
                    $is_locked_status = in_array($h_data['status'], ['Approved', 'Reviewed', 'Generated', 'Archived', 'Replaced']);
                    
                    $has_edit_right = $is_superadmin_root || (function_exists('hasAccess') && hasAccess('ang_bel_edit')) || (defined('RBAC_EDIT') && RBAC_EDIT);
                    $has_add_right = $is_superadmin_root || (function_exists('hasAccess') && hasAccess('ang_bel_add')) || (defined('RBAC_ADD') && RBAC_ADD);
                    $has_del_right = $is_superadmin_root || (function_exists('hasAccess') && hasAccess('ang_bel_del')) || (defined('RBAC_DEL') && RBAC_DEL);
                    if ($is_superadmin_root || (!function_exists('hasAccess') && !defined('RBAC_EDIT'))) { $has_edit_right = true; $has_add_right = true; $has_del_right = true; }
                    
                    $is_readonly = $is_locked_status || !$has_edit_right;
                    $is_perubahan = (strpos($h_data['deskripsi'], '[PERUBAHAN]') !== false);
                ?>
                <div class="bg-white p-4 rounded-4 shadow-sm border-top border-primary border-4 mb-4 text-dark shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-4 px-2">
                        <a href="?page=anggaran_belanja&view=hub&tab=input&tahun=<?= $tahun ?>" class="btn btn-sm btn-light border rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-2"></i>Kembali</a>
                        <h5 class="fw-bold mb-0 text-primary uppercase">PENYUSUNAN RAPB: <?= htmlspecialchars($h_data['deskripsi'] ?? '') ?></h5>
                        <?php if(!$is_readonly && $has_add_right): ?>
                            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="modalAddCategory()"><i class="fas fa-layer-group me-2"></i>Tambah Kategori</button>
                        <?php endif; ?>
                    </div>
                    <form action="index.php?page=anggaran_belanja" method="POST">
                        <input type="hidden" name="action" value="save_worksheet_expense">
                        <input type="hidden" name="header_id" value="<?= $header_id ?>">
                        <input type="hidden" name="tahun_anggaran_worksheet" value="<?= $h_data['tahun_anggaran'] ?>">
                        <input type="hidden" name="current_status" value="<?= $h_data['status'] ?>">
                        
                        <div class="table-responsive-supreme drag-active" id="supremeScrollContainerInput">
                            <table class="table-supreme">
                                <thead><tr><th class="ws-f-action">Opsi</th><th class="ws-f-uraian">Uraian / Komponen Belanja</th><th class="ws-f-kategori">Kategori Anggaran</th><th class="ws-f-coa">Akun COA Belanja</th><th class="ws-f-pagu">Total Pagu (IDR)</th><?php for($m=1;$m<=12;$m++) echo "<th class='month-col'>".date('M', mktime(0,0,0,$m,1,2020))."</th>"; ?></tr></thead>
                                <tbody id="ws_body">
                                    <?php if($has_hierarchy): $cats_res = $conn->query("SELECT * FROM syifa_budgets WHERE header_id=$header_id AND is_category=1 AND kategori='Pengeluaran' ORDER BY id ASC"); while($cat = $cats_res->fetch_assoc()): $u_key = "CAT_".rand(1000,9999).$cat['id']; $cat_sum = safeQuerySumLocal($conn, "SELECT SUM(nominal_pagu) FROM syifa_budgets WHERE parent_id={$cat['id']} AND kategori='Pengeluaran'"); ?>
                                        <tr class="tr-cat ws-row" id="row_<?= $u_key ?>">
                                            <td class="ws-f-action text-center">
                                                <input type="hidden" name="row_type[]" value="category"><input type="hidden" name="ui_key[]" value="<?= $u_key ?>"><input type="hidden" name="parent_key[]" value=""><input type="hidden" name="jenis[]" class="cat-jenis-input" value="<?= $cat['jenis_belanja'] ?>"><input type="hidden" name="coa[]" value=""><input type="hidden" name="total[]" value="0"><?php for($m=1; $m<=12; $m++) echo "<input type='hidden' name='m{$m}[]' value='0'>"; ?>
                                                <?php if(!$is_readonly && $has_add_right): ?><button type="button" class="btn btn-xs btn-primary rounded-circle shadow-sm" onclick="addChildRow('<?= $u_key ?>')" style="width:28px;height:28px;padding:0;"><i class="fas fa-plus"></i></button><?php endif; ?>
                                            </td>
                                            <td class="ws-f-uraian text-start"><input type="text" name="uraian_manual[]" class="inp-uraian fw-bold text-uppercase" value="<?= $cat['uraian_manual'] ?>" <?= $is_readonly?'readonly':'' ?>></td><td class="ws-f-kategori"><select class="form-select border rounded-3 small fw-bold text-primary text-center py-1 shadow-sm" onchange="this.closest('tr').querySelector('.cat-jenis-input').value=this.value; updateChildInheritance(this)" data-ukey="<?= $u_key ?>" <?= $is_readonly?'disabled':'' ?>><option value="Operasional" <?= $cat['jenis_belanja']=='Operasional'?'selected':'' ?>>Operasional</option><option value="Pengembangan" <?= $cat['jenis_belanja']=='Pengembangan'?'selected':'' ?>>Pengembangan</option></select></td><td class="ws-f-coa text-center text-muted small">-</td><td class="ws-f-pagu category-total-display fw-bold text-primary" style="font-family:'JetBrains Mono';">IDR <?= number_format($cat_sum) ?></td><?php for($m=1;$m<=12;$m++) echo "<td class='text-center opacity-25 small'>-</td>"; ?>
                                        </tr>
                                        <?php $items = $conn->query("SELECT * FROM syifa_budgets WHERE parent_id={$cat['id']} AND kategori='Pengeluaran' ORDER BY id ASC"); while($item = $items->fetch_assoc()): $m_vals = array_fill(1, 12, 0); $plans = $conn->query("SELECT bulan, nominal_rencana FROM syifa_budget_monthly_plan WHERE budget_id=".$item['id'])->fetch_all(MYSQLI_ASSOC); foreach($plans as $p) $m_vals[$p['bulan']] = $p['nominal_rencana']; ?>
                                            <tr class="child-of-<?= $u_key ?> ws-row bg-white">
                                                <td class="ws-f-action text-center">
                                                    <input type="hidden" name="row_type[]" value="item"><input type="hidden" name="ui_key[]" value="ITEM_<?= rand() ?>"><input type="hidden" name="parent_key[]" value="<?= $u_key ?>"><input type="hidden" name="jenis[]" class="child-jenis-input" value="<?= $item['jenis_belanja'] ?>">
                                                    <?php if(!$is_readonly && $has_del_right): ?><button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="deleteChildRow(this, '<?= $u_key ?>')"><i class="fas fa-times-circle"></i></button><?php endif; ?>
                                                </td>
                                                <td class="ws-f-uraian text-start" style="padding-left:35px !important;"><input type="text" name="uraian_manual[]" class="inp-uraian" value="<?= $item['uraian_manual'] ?>" placeholder="Rincian biaya..." <?= $is_readonly?'readonly':'' ?>></td><td class="ws-f-kategori text-center text-muted small"><i class="fas fa-arrow-up opacity-25"></i></td><td class="ws-f-coa coa-search-container"><input type="text" class="form-control coa-search-input text-center py-1" value="<?= $item['kode_akun'] ?>" placeholder="Ketik COA..." <?= $is_readonly?'readonly':'' ?>><input type="hidden" name="coa[]" class="coa-hidden-val" value="<?= $item['kode_akun'] ?>"></td><td class="ws-f-pagu"><input type="text" name="total[]" class="inp-amt target-val" onkeyup="fmtRp(this); checkBalance(this);" value="<?= number_format($item['nominal_pagu'],0,',','.') ?>" <?= $is_readonly?'readonly':'' ?>><div class="status-msg"></div></td>
                                                <?php for($m=1;$m<=12;$m++) echo "<td class='month-col-cell'><input type='text' name='m{$m}[]' class='inp-amt month-input' value='".number_format($m_vals[$m],0,',','.')."' ".($is_readonly?'readonly':'')."></td>"; ?>
                                            </tr>
                                        <?php endwhile; endwhile; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-3 mb-2 px-3">
                            <div class="bg-dark text-white rounded-pill px-4 py-2 shadow-sm d-flex align-items-center gap-3">
                                <span class="small fw-bold text-uppercase opacity-75">Total Pagu Keseluruhan:</span>
                                <span class="fs-5 fw-bold text-warning" style="font-family: 'JetBrains Mono', monospace;" id="grandTotalPaguDisplay">IDR 0</span>
                            </div>
                        </div>

                        <div class="policy-footer shadow-sm"><h6 class="fw-bold mb-1"><i class="fas fa-info-circle me-2"></i>Kebijakan Alokasi Fiskal Institusi:</h6><p class="mb-0">Sesuai strategi keuangan Institusi, rasio ideal belanja adalah <b>70% Operasional</b> dan <b>30% Pengembangan</b>.</p></div>
                        <div class="text-center mt-4 mb-2">
                            <?php if($h_data['status'] == 'Draft'): ?>
                                <?php if($has_edit_right): ?>
                                    <button type="submit" name="draft" class="btn btn-warning rounded-pill px-5 py-3 fw-bold shadow me-2 text-uppercase text-dark"><i class="fas fa-save me-2"></i>Simpan <?= $is_perubahan ? 'Draft Perubahan' : 'Draft' ?></button>
                                    <button type="submit" name="final" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase"><i class="fas fa-paper-plane me-2"></i>Ajukan <?= $is_perubahan ? 'Anggaran Perubahan' : 'Persetujuan (Approval)' ?></button>
                                <?php endif; ?>
                            <?php elseif($h_data['status'] == 'Reviewed'): ?>
                                <?php if($has_edit_right): ?>
                                    <button type="submit" name="cancel" class="btn btn-danger rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase ms-2" onclick="return confirm('Tarik kembali pengajuan ini ke Draft?')"><i class="fas fa-undo me-2"></i>Tarik Pengajuan (Kembali ke Draft)</button>
                                <?php endif; ?>
                                <?php if(in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root): ?>
                                    <button type="button" class="btn btn-success rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase ms-2" onclick="approveAction({action: 'approve_budget_async', id: <?= $header_id ?>}, this)"><i class="fas fa-check-circle me-2"></i>Approve & Sahkan <?= $is_perubahan ? 'Anggaran Perubahan' : 'RAPB' ?></button>
                                <?php endif; ?>
                            <?php elseif(in_array($h_data['status'], ['Approved', 'Generated'])): ?>
                                <button type="button" class="btn btn-secondary rounded-pill px-4 py-3 fw-bold shadow-lg text-uppercase" disabled><i class="fas fa-lock me-2"></i>RAPB Telah Disahkan & Terkunci</button>
                                <?php if($has_del_right): ?>
                                    <?php if(in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root): ?>
                                        <button type="button" class="btn btn-danger rounded-pill px-4 py-3 fw-bold shadow-lg text-uppercase ms-2" onclick="cancelApproveAction({action: 'cancel_approval_async', id: <?= $header_id ?>}, this)"><i class="fas fa-undo me-1"></i> Batal Approve</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if($has_edit_right && !$is_perubahan): ?>
                                    <button type="button" class="btn btn-warning rounded-pill px-4 py-3 fw-bold shadow-lg text-dark text-uppercase ms-2" onclick="triggerPerubahanModal(<?= $header_id ?>, '<?= addslashes($h_data['deskripsi']) ?>')"><i class="fas fa-file-signature me-1"></i> Buat Anggaran Perubahan</button>
                                <?php endif; ?>
                            <?php elseif(in_array($h_data['status'], ['Archived', 'Replaced'])): ?>
                                <div class="alert alert-secondary rounded-pill fw-bold border-secondary border text-center shadow-sm">
                                    <i class="fas fa-archive me-2"></i>DOKUMEN INI TELAH DIARSIPKAN (DIGANTIKAN OLEH ANGGARAN PERUBAHAN)
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if(in_array('monitoring', $allowed_tabs)): ?>
        <div class="tab-pane fade <?= $active_tab=='monitoring'?'show active':'' ?>" id="tab-monitoring">
            <div class="supreme-container shadow-sm bg-white rounded-4 text-dark shadow-sm">
                <div class="table-responsive-supreme drag-active" id="supremeScrollContainerMon">
                    <table class="table-supreme table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th rowspan="2" class="mon-f-uraian">Uraian dan Komponen Belanja</th>
                                <th rowspan="2" class="mon-f-pagu">Total Pagu</th>
                                <th rowspan="2" class="mon-f-real">Total Realisasi</th>
                                <th rowspan="2" class="mon-f-sisa">Variance (Sisa)</th>
                                <th rowspan="2" class="mon-f-persen">% Serapan</th>
                                <?php for($m=1;$m<=12;$m++) echo "<th colspan='2' class='text-center border-start month-col'>".date('M', mktime(0,0,0,$m,1,2020))."</th>"; ?>
                            </tr>
                            <tr><?php for($m=1;$m<=12;$m++) echo "<th class='text-end bg-plan small border-start month-col'>ANGGARAN</th><th class='text-end bg-white small month-col'>REALISASI</th>"; ?></tr>
                        </thead>
                        <tbody>
                            <?php 
                            if($active_header_id > 0) {
                                $res_cat = $conn->query("SELECT * FROM syifa_budgets WHERE header_id=$active_header_id AND is_category=1 AND kategori='Pengeluaran' ORDER BY id ASC");
                                
                                $grand_pagu_mon = 0; $grand_real_mon = 0; 
                                $grand_m_p = array_fill(1, 12, 0); $grand_m_r = array_fill(1, 12, 0);

                                if($res_cat && $res_cat->num_rows > 0) { 
                                    while($cat = $res_cat->fetch_assoc()) { 
                                        $sub_p = 0; $sub_r = 0; $sub_m_p = array_fill(1, 12, 0); $sub_m_r = array_fill(1, 12, 0); 
                            ?>
                                    <tr class="tr-cat">
                                        <td class="mon-f-uraian text-uppercase ps-3"><i class="fas fa-folder-open me-2 text-primary"></i><?= $cat['uraian_manual'] ?><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-2 small rounded-pill px-2 py-1"><?= $cat['jenis_belanja'] ?></span></td>
                                        <td class="mon-f-pagu"></td><td class="mon-f-real"></td><td class="mon-f-sisa"></td><td class="mon-f-persen"></td><?php for($m=1;$m<=12;$m++) echo "<td colspan='2' class='border-start'></td>"; ?>
                                    </tr>
                                    <?php 
                                    $items = $conn->query("SELECT * FROM syifa_budgets WHERE parent_id={$cat['id']} AND kategori='Pengeluaran' ORDER BY id ASC");
                                    while($i = $items->fetch_assoc()) {
                                        $rt = safeQuerySumLocal($conn, "SELECT SUM(jd.debit - jd.kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id=j.id WHERE jd.kode_akun='{$i['kode_akun']}' AND YEAR(j.tgl_jurnal)='$tahun'");
                                        $sub_p += (double)$i['nominal_pagu']; $sub_r += (double)$rt;
                                        $pct = ($i['nominal_pagu'] > 0) ? round(($rt / $i['nominal_pagu']) * 100, 1) : 0; 
                                    ?>
                                    <tr class="bg-white">
                                        <td class="mon-f-uraian ps-5 text-dark fw-bold"><?= $i['uraian_manual'] ?></td>
                                        <td class="mon-f-pagu fw-bold">Rp <?= number_format($i['nominal_pagu']) ?></td>
                                        <td class="mon-f-real text-danger fw-bold">Rp <?= number_format($rt) ?></td>
                                        <td class="mon-f-sisa text-primary fw-bold">Rp <?= number_format($i['nominal_pagu']-$rt) ?></td>
                                        <td class="mon-f-persen text-center fw-bold" style="color: <?= $pct > 90 ? '#ef4444' : ($pct > 70 ? '#f59e0b' : '#10b981') ?>;"><?= $pct ?>%</td>
                                        <?php 
                                        for($m=1;$m<=12;$m++): 
                                            $p_val = safeQuerySumLocal($conn, "SELECT nominal_rencana FROM syifa_budget_monthly_plan WHERE budget_id={$i['id']} AND bulan=$m"); 
                                            $r_val = safeQuerySumLocal($conn, "SELECT SUM(jd.debit-kredit) FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id=j.id WHERE jd.kode_akun='{$i['kode_akun']}' AND MONTH(j.tgl_jurnal)=$m AND YEAR(j.tgl_jurnal)='$tahun'"); 
                                            $sub_m_p[$m] += $p_val; $sub_m_r[$m] += $r_val; 
                                            echo "<td class='text-end small bg-plan border-start month-col-cell fw-bold text-dark' style='font-family:JetBrains Mono;'>".number_format($p_val)."</td><td class='text-end small bg-white text-danger fw-bold month-col-cell' style='font-family:JetBrains Mono;'>".number_format($r_val)."</td>"; 
                                        endfor; 
                                        ?>
                                    </tr>
                                    <?php } ?>
                                    <tr class="tr-subtotal">
                                        <td class="mon-f-uraian ps-3 text-uppercase text-dark"><i class="fas fa-calculator me-2 opacity-50"></i>Total <?= $cat['uraian_manual'] ?></td>
                                        <td class="mon-f-pagu fw-bold text-dark">Rp <?= number_format($sub_p) ?></td><td class="mon-f-real fw-bold text-danger">Rp <?= number_format($sub_r) ?></td><td class="mon-f-sisa fw-bold text-primary">Rp <?= number_format($sub_p - $sub_r) ?></td><td class="mon-f-persen text-center fw-bold"><?= ($sub_p > 0) ? round(($sub_r / $sub_p) * 100, 1) : 0 ?>%</td>
                                        <?php for($m=1;$m<=12;$m++) echo "<td class='text-end small bg-plan border-start fw-bold text-dark month-col-cell'>".number_format($sub_m_p[$m])."</td><td class='text-end small bg-white fw-bold text-danger month-col-cell'>".number_format($sub_m_r[$m])."</td>"; ?>
                                    </tr>
                                <?php 
                                        $grand_pagu_mon += $sub_p; $grand_real_mon += $sub_r;
                                        for($m=1;$m<=12;$m++) { $grand_m_p[$m] += $sub_m_p[$m]; $grand_m_r[$m] += $sub_m_r[$m]; }
                                    } 
                                ?>
                                    <tr class="row-grand-total">
                                        <td class="mon-f-uraian ps-3 py-3 text-uppercase">TOTAL ANGGARAN BELANJA</td>
                                        <td class="mon-f-pagu fw-bold">Rp <?= number_format($grand_pagu_mon) ?></td><td class="mon-f-real fw-bold text-white">Rp <?= number_format($grand_real_mon) ?></td><td class="mon-f-sisa fw-bold text-white">Rp <?= number_format($grand_pagu_mon - $grand_real_mon) ?></td><td class="mon-f-persen text-center fw-bold"><?= ($grand_pagu_mon > 0) ? round(($grand_real_mon / $grand_pagu_mon) * 100, 1) : 0 ?>%</td>
                                        <?php for($m=1;$m<=12;$m++) echo "<td class='text-end small bg-plan border-start fw-bold text-white month-col-cell'>".number_format($grand_m_p[$m])."</td><td class='text-end small bg-white fw-bold text-white month-col-cell' style='background-color:#1e293b !important;'>".number_format($grand_m_r[$m])."</td>"; ?>
                                    </tr>
                            <?php
                                } else { echo '<tr><td colspan="29" class="text-center py-5 text-muted small italic">Data belanja belum tersedia dalam worksheet aktif.</td></tr>'; }
                            } else { 
                                echo '<tr><td colspan="29" class="text-center py-5 text-muted small italic">Data belanja formal belum tersedia atau belum disahkan.</td></tr>'; 
                            } 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="mdlNew" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="index.php?page=anggaran_belanja" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="create_header">
            <div class="modal-header bg-primary text-white p-4 border-0 text-center d-block">
                <i class="fas fa-file-invoice-dollar fa-3x mb-3 animate__animated animate__pulse animate__infinite"></i>
                <h5 class="modal-title fw-bold text-white">Buat Lembar Kerja (RAPB) Baru</h5>
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark text-center">
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Deskripsi / Nama Worksheet</label>
                    <input type="text" name="deskripsi" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold" placeholder="Contoh: RAPB Tahun Anggaran 2026" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Tahun Anggaran</label>
                    <input type="number" name="tahun_anggaran" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold text-primary" value="<?= $tahun ?>" required>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">BUAT WORKSHEET</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="mdlPerubahan" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="index.php?page=anggaran_belanja" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="create_perubahan_header">
            <input type="hidden" name="id" id="perubahan_id">
            <div class="modal-header bg-warning text-dark p-4 border-0 text-center d-block">
                <i class="fas fa-file-signature fa-3x mb-3 animate__animated animate__pulse animate__infinite"></i>
                <h5 class="modal-title fw-bold">Buat Anggaran Perubahan</h5>
                <button type="button" class="btn-close shadow-none position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark text-center">
                <div class="alert alert-warning border-warning shadow-sm rounded-4 small fw-bold mb-4 text-start">
                    <i class="fas fa-info-circle me-2"></i>Proses ini akan menduplikasi RAPB yang sudah disahkan ini menjadi draft baru untuk Anda ubah. RAPB asli tetap berjalan hingga Anggaran Perubahan ini disahkan.
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Nama Laporan Perubahan</label>
                    <input type="text" name="new_name" id="perubahan_new_name" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold text-primary" required>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white">
                <button type="submit" class="btn btn-warning text-dark w-100 rounded-pill py-3 fw-bold shadow">DUPLIKASI UNTUK PERUBAHAN</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="mdlClone" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="index.php?page=anggaran_belanja" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="duplicate_header">
            <input type="hidden" name="id" id="clone_id">
            <div class="modal-header bg-info text-white p-4 border-0 text-center d-block">
                <i class="fas fa-clone fa-3x mb-3 animate__animated animate__pulse animate__infinite"></i>
                <h5 class="modal-title fw-bold text-white">Duplikasi Lembar Kerja</h5>
            </div>
            <div class="modal-body p-4 bg-light text-dark text-center">
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Nama Baru</label>
                    <input type="text" name="new_name" id="clone_new_name" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Tahun Anggaran Tujuan</label>
                    <input type="number" name="new_year" id="clone_new_year" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold text-primary" required>
                </div>
                <p class="small text-muted italic">Seluruh rincian belanja dan rencana bulanan akan disalin ke tahun baru dengan status <b>Draft</b>.</p>
            </div>
            <div class="modal-footer p-4 border-0 bg-white"><button type="submit" class="btn btn-info text-white w-100 rounded-pill py-3 fw-bold shadow">DUPLIKAT SEKARANG</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="mdlRename" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="index.php?page=anggaran_belanja" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="rename_worksheet">
            <input type="hidden" name="id" id="rename_id">
            <input type="hidden" name="return_view" id="rename_return_view" value="<?= $view_mode ?>">
            <input type="hidden" name="header_id" id="rename_header_id" value="<?= $header_id ?>">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-pen-nib me-2"></i>Ubah Nama Worksheet</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <label class="small fw-bold text-muted mb-1 uppercase">Nama Baru</label>
                <input type="text" name="nama_baru" id="rename_name_input" class="form-control rounded-pill border-0 shadow-sm px-4 py-2 fw-bold" required>
            </div>
            <div class="modal-footer p-4 border-0 bg-white"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">UPDATE NAMA</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="mdlInsight" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning text-white p-4 border-0 text-center d-block">
                <i class="fas fa-lightbulb fa-3x mb-3"></i>
                <h5 class="modal-title fw-bold mb-0" id="insightTitle">EXECUTIVE ANALYSIS</h5>
            </div>
            <div class="modal-body p-4 bg-light text-dark" id="insightBody" style="line-height:1.7;"></div>
            <div class="modal-footer p-3 border-0 bg-white">
                <button type="button" class="btn btn-dark w-100 rounded-pill py-2 fw-bold shadow-sm" data-bs-dismiss="modal">DIMENGERTI</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="mdlWorkflowPortal" data-bs-backdrop="static" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered text-dark modal-sm"> 
        <div class="modal-content shadow-lg" style="border-radius: 28px !important;">
            <div class="modal-header p-4 border-0 text-center d-block" id="modalHeader"><i id="modalIcon" class="fas fa-check-circle fa-4x mb-3 animate__animated animate__bounceIn"></i><h4 class="modal-title fw-bold mb-0" id="modalTitle">PROSES BERHASIL</h4></div>
            <div class="modal-body p-4 bg-light text-dark text-center">
                <div class="card border-0 rounded-4 shadow-sm p-4 mb-3 bg-white" id="cardMainTotal"><small class="text-muted fw-bold text-uppercase d-block mb-1" id="labelMain" style="font-size:9px;">Total Anggaran</small><h4 class="fw-bold text-primary mb-0" id="m_total">Rp 0</h4></div>
                <div id="extraInfo" class="row g-2"><div class="col-6"><div class="p-2 bg-white rounded-3 shadow-sm border-start border-4 border-info"><small class="text-muted fw-bold uppercase d-block" style="font-size:7px;">Ops (70%)</small><small class="fw-bold text-dark" id="m_ops">Rp 0</small></div></div><div class="col-6"><div class="p-2 bg-white rounded-3 shadow-sm border-start border-4 border-warning"><small class="text-muted fw-bold uppercase d-block" style="font-size:7px;">Dev (30%)</small><small class="fw-bold text-dark" id="m_dev">Rp 0</small></div></div></div>
                <div id="cancelInfo" class="p-2 bg-white rounded-3 shadow-sm d-none text-center"><p class="mb-0 small text-muted fw-bold uppercase" style="font-size:9px;">Status: <span class="badge bg-warning text-dark">DRAFT (Rollback)</span></p></div>
            </div>
            <div class="modal-footer p-3 border-0 bg-white" id="modalFooterPortal"><button type="button" class="btn btn-dark w-100 rounded-pill py-2 fw-bold shadow-sm" onclick="closePortal()">LANJUTKAN</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="mdlAddCat" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered text-dark"><div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden"><div class="modal-header bg-dark text-white p-4 border-0"><h5 class="modal-title fw-bold text-white">Tambah Kategori Belanja</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body p-4 bg-light text-dark"><div class="mb-3 text-center"><label class="small fw-bold text-muted uppercase">Nama Kategori</label><input type="text" id="cat_name" class="form-control rounded-pill border-0 shadow-sm px-3 text-center" placeholder="Misal: Biaya Perjalanan Dinas"></div><div class="mb-0 text-center"><label class="small fw-bold text-muted uppercase">Tipe Anggaran</label><select id="cat_type" class="form-select rounded-pill border-0 shadow-sm px-3 text-center"><option value="Operasional">Operasional</option><option value="Pengembangan">Pengembangan</option></select></div></div><div class="modal-footer p-4 border-0 bg-white text-center d-block"><button type="button" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow" onclick="saveCategoryRow()">TAMBAHKAN KE LEMBAR KERJA</button></div></div></div></div>

<script>
function approveAction(data, btn) {
    if(!confirm("Setujui dan Sahkan RAPB ini menjadi Pagu Target Tahunan? (RAPB versi sebelumnya untuk tahun ini akan diarsipkan jika ini adalah Anggaran Perubahan)")) return;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    fetch('index.php?page=anggaran_belanja', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') { window.location.reload(); }
        else { alert(res.msg); btn.innerHTML = '<i class="fas fa-check me-1"></i> APPROVE'; btn.disabled = false; }
    })
    .catch(e => { window.location.reload(); });
}

function cancelApproveAction(data, btn) {
    if(!confirm("Batalkan persetujuan RAPB ini dan kembalikan ke status Menunggu Approval?")) return;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    fetch('index.php?page=anggaran_belanja', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') { window.location.reload(); }
        else { alert(res.msg); btn.innerHTML = '<i class="fas fa-undo me-1"></i> Batal Approve'; btn.disabled = false; }
    })
    .catch(e => { window.location.reload(); });
}

function confirmDelete(id) { 
    if(confirm('Hapus lembar kerja ini secara permanen? Jika ini Anggaran Perubahan, Anggaran Awal Anda akan diaktifkan kembali secara otomatis.')) { 
        window.location.href = "index.php?page=anggaran_belanja&action=delete_header&id=" + id; 
    } 
}

const masterCOA = <?= json_encode($coa_list) ?>;
const trendOpsDev = <?= json_encode(array_values($trend_data), JSON_NUMERIC_CHECK) ?>;
const breakdownData = <?= json_encode($breakdown_real, JSON_NUMERIC_CHECK) ?>;
let chartInstances = {};

const coaFloatingBox = document.createElement("div");
coaFloatingBox.className = "coa-results-list";
document.body.appendChild(coaFloatingBox);

function triggerModalNew() { bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlNew')).show(); }

function triggerPerubahanModal(id, oldName) {
    document.getElementById('perubahan_id').value = id;
    document.getElementById('perubahan_new_name').value = "[PERUBAHAN] " + oldName;
    new bootstrap.Modal(document.getElementById('mdlPerubahan')).show();
}

function modalAddCategory() { const modalEl = document.getElementById('mdlAddCat'); const modal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true, focus: true }); modal.show(); }

function triggerCloneModal(id, oldName, oldYear) { 
    document.getElementById('clone_id').value = id; 
    document.getElementById('clone_new_name').value = oldName + " (Copy)"; 
    document.getElementById('clone_new_year').value = oldYear;
    new bootstrap.Modal(document.getElementById('mdlClone')).show(); 
}

function triggerRenameModal(id, name) { 
    document.getElementById('rename_id').value = id; 
    document.getElementById('rename_name_input').value = name; 
    new bootstrap.Modal(document.getElementById('mdlRename')).show(); 
}

function closePortal() {
    const modalEl = document.getElementById('mdlWorkflowPortal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) return;
    modal.hide();
    modalEl.addEventListener('hidden.bs.modal', function handler() {
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style = '';
        document.body.style.pointerEvents = 'auto';
        const url = new URL(window.location.href);
        url.searchParams.delete('msg_type'); url.searchParams.delete('t'); url.searchParams.delete('ops'); url.searchParams.delete('dev'); url.searchParams.delete('new_id');
        window.history.replaceState({}, document.title, url.pathname + url.search);
        modalEl.removeEventListener('hidden.bs.modal', handler);
    });
}

document.addEventListener('hidden.bs.modal', function () {
    setTimeout(() => {
        if (document.querySelectorAll('.modal.show').length === 0) {
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style = '';
            document.body.style.pointerEvents = 'auto';
        }
    }, 200);
});

function safeCreateChart(id, config){ if(chartInstances[id]) { chartInstances[id].destroy(); } const ctx = document.getElementById(id); if(ctx) { chartInstances[id] = new Chart(ctx, config); } }

function initBudgetCharts() {
    if (typeof Chart === "undefined") return;
    const sd = (val) => isNaN(val) ? 0 : val;
    
    // 1. Chart Line Trend
    safeCreateChart('chartTrendOpsDev', { 
        type: 'line', 
        data: { 
            labels: ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'], 
            datasets: [
                { label: 'Operasional', data: trendOpsDev.map(x=>x.ops), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true, tension: 0.4 }, 
                { label: 'Pengembangan', data: trendOpsDev.map(x=>x.dev), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.1)', fill: true, tension: 0.4 }
            ] 
        }, 
        options: { responsive: true, maintainAspectRatio: false } 
    });
    
    // 2. Chart Top Belanja (Donut)
    safeCreateChart('chartDonutRealization', { 
        type: 'doughnut', 
        data: { 
            labels: breakdownData.map(b => b.uraian_manual), 
            datasets: [{ data: breakdownData.map(b => b.total_v), backgroundColor: ['#ef4444', '#f59e0b', '#10b981', '#0d6efd', '#6366f1', '#ec4899', '#8b5cf6', '#14b8a6', '#f97316', '#06b6d4'] }] 
        }, 
        options: { 
            responsive: true, maintainAspectRatio: false, cutout: '65%', 
            plugins: { 
                legend: { display: false }, 
                tooltip: { callbacks: { label: (ctx) => { const v = ctx.raw; const t = ctx.dataset.data.reduce((a,b)=>a+b,0); return [ctx.label, 'Realisasi: Rp '+new Intl.NumberFormat('id-ID').format(v), 'Andil: '+((v/t)*100).toFixed(1)+'%']; } } } 
            } 
        } 
    });

    // 3. Chart Serapan (Bar)
    safeCreateChart('chartSerapan', { 
        type: 'bar', 
        data: { 
            labels: ['Operasional','Pengembangan'], 
            datasets: [
                { label:'Pagu', data:[sd(<?= $p_70 ?>), sd(<?= $p_30 ?>)], backgroundColor: '#cbd5e1', borderRadius: 4 }, 
                { label:'Realisasi', data:[sd(<?= $r_70 ?>), sd(<?= $r_30 ?>)], backgroundColor: '#10b981', borderRadius: 4 }
            ] 
        }, 
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } } 
    });
    
    // 4. Chart Sisa Fiskal (Bar)
    safeCreateChart('chartSisa', { 
        type: 'bar', 
        data: { 
            labels: ['Sisa Ops','Sisa Dev'], 
            datasets: [{ label: 'Rp', data:[sd(<?= $s_70 ?>), sd(<?= $s_30 ?>)], backgroundColor:['#3b82f6','#fbbf24'], borderRadius: 4 }] 
        }, 
        options: { 
            indexAxis: 'y', responsive: true, maintainAspectRatio: false, 
            plugins: { legend: { display: false } } 
        } 
    });
}

function showInsight(type) {
    let t = "", b = "";
    if(type==='trend') { t="Tren Serapan"; b="Memantau kecepatan belanja bulanan."; }
    else if(type==='serapan') { t="Budget Absorption"; b="Menilai performa eksekusi anggaran."; }
    else if(type==='rincian') { t="Top Pengeluaran"; b="Audit efisiensi pada pos biaya terbesar."; }
    else if(type==='sisa') { t="Ruang Fiskal"; b="Daya tahan kas sisa tahun."; }
    document.getElementById('insightTitle').innerText = t; document.getElementById('insightBody').innerHTML = b;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlInsight')).show();
}

function positionCoaBox(input) { const rect = input.getBoundingClientRect(); coaFloatingBox.style.top = (rect.bottom + 4) + "px"; coaFloatingBox.style.left = rect.left + "px"; coaFloatingBox.style.width = rect.width + "px"; }
function syncFloatingPosition() { const active = document.activeElement; if(active && active.classList.contains("coa-search-input") && coaFloatingBox.style.display === "block") { positionCoaBox(active); } }

function bindMonthInputs(row) { row.querySelectorAll('.month-input').forEach(inp => { inp.addEventListener('keyup', function() { fmtRp(this); checkBalance(this); }); }); }

function initSmoothHorizontalDrag() {
    const containers = document.querySelectorAll('.table-responsive-supreme');
    containers.forEach(container => {
        let isDown = false; let startX; let scrollLeft;
        container.addEventListener('mousedown', (e) => { 
            if (e.target.tagName === 'INPUT' || e.target.closest('.btn') || e.target.closest('.coa-item') || e.target.tagName === 'SELECT') return; 
            isDown = true; startX = e.pageX - container.offsetLeft; scrollLeft = container.scrollLeft; 
            container.style.cursor = 'grabbing'; container.style.userSelect = 'none'; 
        });
        const stop = () => { isDown = false; container.style.cursor = 'grab'; container.style.removeProperty('user-select'); };
        container.addEventListener('mouseleave', stop); container.addEventListener('mouseup', stop);
        container.addEventListener('mousemove', (e) => { if (!isDown) return; e.preventDefault(); const x = e.pageX - container.offsetLeft; const walk = (x - startX) * 1.2; container.scrollLeft = scrollLeft - walk; });
    });
}

document.addEventListener("input", function(e) { if(e.target.classList.contains("coa-search-input")) { filterCoaList(e.target); } });
document.addEventListener("click", function(e) { if(!e.target.classList.contains("coa-search-input") && !e.target.closest(".coa-results-list")) { coaFloatingBox.style.display = "none"; } });
window.addEventListener("scroll", syncFloatingPosition);
window.addEventListener("resize", syncFloatingPosition);
document.querySelectorAll('.table-responsive-supreme').forEach(el => el.addEventListener("scroll", syncFloatingPosition));

function filterCoaList(input) {
    positionCoaBox(input);
    const keyword = input.value.toLowerCase();
    coaFloatingBox.innerHTML = '';
    if(keyword.length === 0){ coaFloatingBox.style.display = 'none'; return; }
    const filtered = masterCOA.filter(c => c.kode_akun.toLowerCase().includes(keyword) || c.nama_akun.toLowerCase().includes(keyword)).slice(0,15);
    if(filtered.length === 0){ coaFloatingBox.style.display = 'none'; return; }
    filtered.forEach(c=>{
        const item = document.createElement("div"); item.className = "coa-item";
        item.innerHTML = `<code>${c.kode_akun}</code> ${c.nama_akun}`;
        item.addEventListener("click", function(){ 
            input.value = `${c.kode_akun} - ${c.nama_akun}`; 
            const row = input.closest('tr');
            row.querySelector(".coa-hidden-val").value = c.kode_akun; 
            coaFloatingBox.style.display = "none"; 
        });
        coaFloatingBox.appendChild(item);
    });
    coaFloatingBox.style.display = "block";
}

function saveCategoryRow() {
    const name = document.getElementById('cat_name').value; const type = document.getElementById('cat_type').value; if(!name) return;
    const uKey = 'CAT_' + Date.now(); const tr = document.createElement('tr'); tr.className = 'tr-cat ws-row'; tr.id = `row_${uKey}`;
    let mHid = ''; for(let m=1;m<=12;m++) mHid += `<input type='hidden' name='m${m}[]' value='0'>`;
    tr.innerHTML = `
        <td class="ws-f-action text-center">
            <input type="hidden" name="row_type[]" value="category">
            <input type="hidden" name="ui_key[]" value="${uKey}">
            <input type="hidden" name="parent_key[]" value="">
            <input type="hidden" name="jenis[]" class="cat-jenis-input" value="${type}">
            <input type="hidden" name="coa[]" value="">
            <input type="hidden" name="total[]" value="0">
            ${mHid}
            <button type="button" class="btn btn-xs btn-primary rounded-circle shadow-sm" onclick="addChildRow('${uKey}')" style="width:28px;height:28px;padding:0;"><i class="fas fa-plus"></i></button>
        </td>
        <td class="ws-f-uraian text-start"><input type="text" name="uraian_manual[]" class="inp-uraian fw-bold text-uppercase" value="${name}"></td>
        <td class="ws-f-kategori"><select class="form-select border rounded-3 small fw-bold text-primary text-center py-1 shadow-sm" onchange="this.closest('tr').querySelector('.cat-jenis-input').value=this.value; updateChildInheritance(this)" data-ukey="${uKey}"><option value="Operasional" ${type==='Operasional'?'selected':''}>Operasional</option><option value="Pengembangan" ${type==='Pengembangan'?'selected':''}>Pengembangan</option></select></td>
        <td class="ws-f-coa text-center text-muted small">-</td>
        <td class="ws-f-pagu category-total-display fw-bold text-primary">IDR 0</td>
        <td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td><td class='text-center opacity-25 small'>-</td>
    `;
    document.getElementById('ws_body').appendChild(tr); const modal = bootstrap.Modal.getInstance(document.getElementById('mdlAddCat')); if(modal) modal.hide(); document.getElementById('cat_name').value = '';
}

function addChildRow(uKey) {
    const trParent = document.getElementById('row_' + uKey);
    const catType = trParent ? trParent.querySelector('.cat-jenis-input').value : 'Operasional';
    const randId = Date.now() + Math.floor(Math.random() * 1000);
    const rowId = `item_row_${randId}`; 
    
    const tr = document.createElement('tr'); tr.id = rowId; tr.className = `child-of-${uKey} ws-row bg-white`;
    const MONTH_COLUMNS = `<td><input type='text' name='m1[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m2[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m3[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m4[]' class='inp-amt month-input' onkeyup='fmtRp(this); checkBalance(this);' value='0'></td><td><input type='text' name='m5[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m6[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m7[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m8[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m9[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m10[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m11[]' class='inp-amt month-input' value='0'></td><td><input type='text' name='m12[]' class='inp-amt month-input' value='0'></td>`;
    tr.innerHTML = `
        <td class="ws-f-action text-center">
            <input type="hidden" name="row_type[]" value="item">
            <input type="hidden" name="ui_key[]" value="ITEM_${randId}">
            <input type="hidden" name="parent_key[]" value="${uKey}">
            <input type="hidden" name="jenis[]" class="child-jenis-input" value="${catType}">
            <button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="deleteChildRow(this, '${uKey}')"><i class="fas fa-times-circle"></i></button>
        </td>
        <td class="ws-f-uraian text-start" style="padding-left:35px !important;"><input type="text" name="uraian_manual[]" class="inp-uraian" placeholder="Rincian biaya..."></td>
        <td class="ws-f-kategori text-center text-muted small"><i class="fas fa-arrow-up opacity-25"></i></td>
        <td class="ws-f-coa coa-search-container"><input type="text" class="form-control coa-search-input text-center py-1" placeholder="Ketik COA..."><input type="hidden" name="coa[]" class="coa-hidden-val"></td>
        <td class="ws-f-pagu"><input type="text" name="total[]" class="inp-amt target-val" onkeyup="fmtRp(this); checkBalance(this);" value="0"><div class="status-msg"></div></td>
        ${MONTH_COLUMNS}
    `;
    const lastRow = Array.from(document.querySelectorAll(`.child-of-${uKey}`)).pop() || document.getElementById(`row_${uKey}`); lastRow.after(tr); bindMonthInputs(tr);
}

function deleteChildRow(btn, uKey) {
    btn.closest('tr').remove(); 
    updateCatTotal(uKey);
    updateGrandTotalPagu();
}

document.addEventListener("DOMContentLoaded", function() {
    initBudgetCharts(); initSmoothHorizontalDrag(); document.querySelectorAll('.ws-row').forEach(row => bindMonthInputs(row));
    const p = new URLSearchParams(window.location.search); const mType = p.get('msg_type');
    
    // 🚀 THE NAVIGATION SHIELD
    const savedTab = localStorage.getItem('activeBudgetTab');
    if (!p.has('tab') && savedTab) {
        const tabLink = document.querySelector(`.nav-link[href="?page=anggaran_belanja&tab=${savedTab}"]`);
        if (tabLink) window.location.href = tabLink.href; 
    }

    document.querySelectorAll('.nav-link[href^="?page=anggaran_belanja&tab="]').forEach(link => {
        link.addEventListener('click', function(e) {
            const tabName = new URL(this.href).searchParams.get('tab');
            localStorage.setItem('activeBudgetTab', tabName);
        });
    });
    
    if (mType) {
        document.getElementById('m_total').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(p.get('t') || 0);
        
        if (mType === 'success_review') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-info text-white";
            document.getElementById('modalTitle').innerText = "PENGAJUAN TERKIRIM";
            document.getElementById('modalIcon').className = "fas fa-paper-plane fa-4x mb-3 text-white";
            document.getElementById('m_ops').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(p.get('ops') || 0);
            document.getElementById('m_dev').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(p.get('dev') || 0);
            document.getElementById('labelMain').innerText = "Total Pengajuan Menunggu Approval";
        } else if (mType === 'success_create') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-success text-white";
            document.getElementById('modalTitle').innerText = "WORKSHEET BERHASIL DIBUAT";
            document.getElementById('modalIcon').className = "fas fa-clipboard-check fa-4x mb-3 text-white";
            
            document.getElementById('labelMain').innerText = "";
            document.getElementById('m_total').innerHTML = "Lembar Kerja Siap Digunakan";
            document.getElementById('m_total').className = "fw-bold text-success mb-0 fs-5 mt-1";
            
            document.getElementById('extraInfo').classList.add('d-none'); document.getElementById('cancelInfo').classList.add('d-none');
            
            const newId = p.get('new_id');
            const footer = document.getElementById('modalFooterPortal');
            footer.innerHTML = `
                <button type="button" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase mb-2" onclick="window.location.href='index.php?page=anggaran_belanja&view=worksheet&header_id=${newId}&tab=input&tahun=<?= $tahun ?>'"><i class="fas fa-arrow-right me-2"></i>LANJUT BUAT ANGGARAN</button>
                <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold text-muted shadow-sm" onclick="closePortal()">Nanti Saja</button>
            `;
        } else if (mType === 'success_cancel') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-danger text-white";
            document.getElementById('modalTitle').innerText = "PENGAJUAN DIBATALKAN";
            document.getElementById('modalIcon').className = "fas fa-undo fa-4x mb-3 text-white";
            document.getElementById('labelMain').innerText = "Pengajuan yang Ditarik ke Draft";
            document.getElementById('extraInfo').classList.add('d-none'); document.getElementById('cancelInfo').classList.remove('d-none');
        } else if (mType === 'success_perubahan') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-warning text-dark";
            document.getElementById('modalTitle').innerText = "DUPLIKASI PERUBAHAN BERHASIL";
            document.getElementById('modalIcon').className = "fas fa-file-signature fa-4x mb-3 text-dark";
            
            document.getElementById('labelMain').innerText = "Draf Anggaran Perubahan";
            document.getElementById('m_total').innerHTML = "<span class='fs-6 fw-bold text-secondary'>Anda kini bisa mengedit nominal untuk Anggaran Perubahan.</span>";
            document.getElementById('m_total').className = "mt-2";

            document.getElementById('extraInfo').classList.add('d-none'); document.getElementById('cancelInfo').classList.add('d-none');
            
            const newId = p.get('new_id');
            const footer = document.getElementById('modalFooterPortal');
            footer.innerHTML = `
                <button type="button" class="btn btn-warning text-dark w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase mb-2" onclick="window.location.href='index.php?page=anggaran_belanja&view=worksheet&header_id=${newId}&tab=input&tahun=${p.get('tahun')}'"><i class="fas fa-edit me-2"></i>MULAI UBAH NOMINAL</button>
                <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold text-muted shadow-sm" onclick="closePortal()">Nanti Saja</button>
            `;
        } else if (mType === 'success_dup') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-info text-white";
            document.getElementById('modalTitle').innerText = "DUPLIKASI BERHASIL";
            document.getElementById('modalIcon').className = "fas fa-clone fa-4x mb-3 text-white";
            
            document.getElementById('labelMain').innerText = "Anggaran Baru Tahun " + p.get('tahun');
            document.getElementById('m_total').innerHTML = "<span class='fs-6 fw-bold text-secondary'>Worksheet telah berhasil digandakan ke status Draft.</span>";
            document.getElementById('m_total').className = "mt-2";

            document.getElementById('extraInfo').classList.add('d-none'); document.getElementById('cancelInfo').classList.add('d-none');
            
            const newId = p.get('new_id');
            const footer = document.getElementById('modalFooterPortal');
            footer.innerHTML = `
                <button type="button" class="btn btn-info text-white w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase mb-2" onclick="window.location.href='index.php?page=anggaran_belanja&view=worksheet&header_id=${newId}&tab=input&tahun=${p.get('tahun')}'"><i class="fas fa-edit me-2"></i>LANJUT REVISI ANGGARAN</button>
                <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold text-muted shadow-sm" onclick="closePortal()">Nanti Saja</button>
            `;
        }
        const portal = new bootstrap.Modal(document.getElementById('mdlWorkflowPortal'), { backdrop: 'static', keyboard: false }); portal.show();
    }
});

function updateGrandTotalPagu() {
    let grandTotal = 0;
    document.querySelectorAll('.target-val').forEach(inp => {
        grandTotal += prs(inp.value);
    });
    const gtDisplay = document.getElementById('grandTotalPaguDisplay');
    if (gtDisplay) {
        gtDisplay.innerText = 'IDR ' + new Intl.NumberFormat('id-ID').format(grandTotal);
    }
}

// 🚀 FUNGSI MELEMPAR ATAU MENGURANGI ANGGARAN SPESIFIK KE/DARI DESEMBER (CASCADING DEDUCTION)
function kelolaSisaLebih(btn, amount, mode) {
    const tr = btn.closest('tr');
    const targetInp = tr.querySelector('.target-val');
    const monthInputs = tr.querySelectorAll('.month-input');
    
    if (mode === 'sisa') {
        if(confirm(`Sisa pagu sebesar Rp ${new Intl.NumberFormat('id-ID').format(amount)} akan ditambahkan ke bulan Desember. Lanjutkan?`)) {
            let desInput = monthInputs[11];
            let currentDes = prs(desInput.value);
            desInput.value = new Intl.NumberFormat('id-ID').format(currentDes + amount);
            checkBalance(targetInp);
        }
    } else if (mode === 'lebih') {
        if(confirm(`Kelebihan pagu sebesar Rp ${new Intl.NumberFormat('id-ID').format(amount)} akan dikurangi dari bulan Desember (menyebar mundur jika tidak cukup). Lanjutkan?`)) {
            let remainingExcess = amount;
            // 🚀 CASCADING DEDUCTION: Mulai dari Desember (11) mundur ke Januari (0)
            for (let i = 11; i >= 0; i--) {
                let mVal = prs(monthInputs[i].value);
                if (mVal > 0) {
                    if (mVal >= remainingExcess) {
                        monthInputs[i].value = new Intl.NumberFormat('id-ID').format(mVal - remainingExcess);
                        remainingExcess = 0;
                        break;
                    } else {
                        remainingExcess -= mVal;
                        monthInputs[i].value = "0"; // Habiskan bulan ini, lanjut potong bulan sebelumnya
                    }
                }
            }
            
            if (remainingExcess > 0) {
                alert("GAGAL: Nominal di seluruh bulan tidak cukup untuk menutupi kelebihan ini. Anda perlu menambah Total Pagu.");
            }
            checkBalance(targetInp);
        }
    }
}

function fmtRp(el) { let v = el.value.replace(/\D/g, ""); el.value = new Intl.NumberFormat('id-ID').format(v); }
function prs(s) { return parseFloat(s.toString().replace(/\./g, '')) || 0; }
function updateChildInheritance(sel) { const ukey = sel.dataset.ukey; const val = sel.value; document.querySelectorAll(`.child-of-${ukey} .child-jenis-input`).forEach(inp => inp.value = val); }

function checkBalance(el) {
    const tr = el.closest('tr'); 
    const targetInp = tr.querySelector('.target-val'); 
    if(!targetInp) return;
    
    const targetPagu = prs(targetInp.value); 
    let currentSum = 0; 
    tr.querySelectorAll('.month-input').forEach(mi => currentSum += prs(mi.value));
    
    const statusMsg = tr.querySelector('.status-msg'); 
    const diff = targetPagu - currentSum;
    
    if(diff === 0 && targetPagu > 0) { 
        statusMsg.innerHTML = '<span class="badge bg-success mt-1">Sesuai</span>'; 
    } 
    else if (diff > 0) { 
        statusMsg.innerHTML = `
            <div class="d-flex align-items-center justify-content-end gap-1 mt-1">
                <span class="badge bg-warning text-dark">SISA Rp ${new Intl.NumberFormat('id-ID').format(diff)}</span>
                <button type="button" class="btn btn-sm btn-primary rounded-pill py-0 px-2 shadow-sm" style="font-size: 10px;" onclick="kelolaSisaLebih(this, ${diff}, 'sisa')" title="Lempar sisa ke Desember"><i class="fas fa-arrow-right me-1"></i>Ke Des</button>
            </div>`; 
    } 
    else if (diff < 0) { 
        let absDiff = Math.abs(diff);
        statusMsg.innerHTML = `
            <div class="d-flex align-items-center justify-content-end gap-1 mt-1">
                <span class="badge bg-danger text-white">LEBIH Rp ${new Intl.NumberFormat('id-ID').format(absDiff)}</span>
                <button type="button" class="btn btn-sm btn-danger rounded-pill py-0 px-2 shadow-sm" style="font-size: 10px;" onclick="kelolaSisaLebih(this, ${absDiff}, 'lebih')" title="Kurangi kelebihan dari Desember"><i class="fas fa-minus me-1"></i>Dari Des</button>
            </div>`; 
    } 
    else {
        statusMsg.innerHTML = '';
    }
    
    const uKeyMatch = tr.className.match(/child-of-(CAT_\d+)/);
    if (uKeyMatch) updateCatTotal(uKeyMatch[1]);
    
    updateGrandTotalPagu();
}

function updateCatTotal(uKey) {
    let totalCat = 0; document.querySelectorAll(`.child-of-${uKey}`).forEach(tr => { const tVal = tr.querySelector('.target-val'); if(tVal) totalCat += prs(tVal.value); });
    const disp = document.querySelector(`#row_${uKey} .category-total-display`); if(disp) disp.innerText = 'IDR ' + new Intl.NumberFormat('id-ID').format(totalCat);
}

window.onload = function() { 
    document.querySelectorAll('.target-val').forEach(el => checkBalance(el)); 
    updateGrandTotalPagu();
};
</script>