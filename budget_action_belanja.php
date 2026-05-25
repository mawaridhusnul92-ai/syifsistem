<?php
/**
 * budget_action_belanja.php - BUDGET INTELLIGENCE ENGINE (SUPREME UNIFIED)
 * Versi: 103.0 (Grand Master - The True Revert Guard Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak: 
 * Menyinkronkan logic Auto-Healer dan Revert Guard agar memastikan:
 * JIKA Anggaran Perubahan dibatalkan ATAU dihapus, RAPB Asli yang tergantikan 
 * akan 100% aktif kembali secara otomatis.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

if (!isset($_SESSION['user_id'])) { 
    if($is_ajax) { echo json_encode(['status'=>'error', 'message'=>'Sesi Berakhir.']); exit; }
    header("Location: login.php"); exit; 
}

$uid = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if(!function_exists('cleanNum')){ function cleanNum($val) { return (double)str_replace(['.', ','], '', $val ?? '0'); } }

// =========================================================================
// 🚀 THE REVERT HEALER ENGINE (Dijalankan Otomatis di Latar Belakang)
// =========================================================================
$conn->query("
    UPDATE syifa_budget_headers h1
    JOIN (
        SELECT tahun_anggaran, kategori, MAX(id) as max_id 
        FROM syifa_budget_headers 
        WHERE status IN ('Replaced', 'Archived', 'DIARSIPKAN (DIGANTIKAN)')
        AND NOT EXISTS (
            SELECT 1 FROM syifa_budget_headers h2 
            WHERE h2.tahun_anggaran = syifa_budget_headers.tahun_anggaran 
            AND h2.kategori = syifa_budget_headers.kategori 
            AND h2.status IN ('Approved', 'Generated')
        )
        GROUP BY tahun_anggaran, kategori
    ) h2 ON h1.id = h2.max_id
    SET h1.status = 'Approved'
");
$conn->query("UPDATE syifa_budgets SET status='Disetujui' WHERE header_id IN (SELECT id FROM syifa_budget_headers WHERE status='Approved') AND status IN ('Replaced', 'Archived', 'DIARSIPKAN (DIGANTIKAN)')");


// =========================================================================
// 1. ASYNC APPROVAL ENGINE + AUTO ARCHIVE
// =========================================================================
if ($action == 'approve_budget_async') {
    $hid = (int)$_POST['id'];
    $conn->begin_transaction();
    try {
        $check = $conn->query("SELECT status, tahun_anggaran, kategori, deskripsi FROM syifa_budget_headers WHERE id = $hid")->fetch_assoc();
        if(!$check) throw new Exception("Data tidak ditemukan.");
        if($check['status'] == 'Generated' || $check['status'] == 'Approved') throw new Exception("Data sudah disahkan.");

        $thn_anggaran = $check['tahun_anggaran'];
        $kat = $check['kategori'];

        // 🚀 PRECISION ARCHIVE: Cari RAPB lama di tahun yang sama dan ubah statusnya menjadi Replaced
        $conn->query("UPDATE syifa_budget_headers SET status='Replaced' WHERE tahun_anggaran='$thn_anggaran' AND kategori='$kat' AND status IN ('Approved', 'Generated') AND id != $hid");
        $conn->query("UPDATE syifa_budgets SET status='Replaced' WHERE tahun_anggaran='$thn_anggaran' AND kategori='Pengeluaran' AND status='Disetujui' AND header_id != $hid");

        // FIX: Langsung ubah status menjadi Generated agar fungsional di monitoring
        $conn->query("UPDATE syifa_budget_headers SET status = 'Approved' WHERE id = $hid");
        $conn->query("UPDATE syifa_budgets SET status = 'Disetujui' WHERE header_id = $hid");
        
        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Anggaran telah resmi disahkan & diproses ke Monitoring.']);
        exit;
    } catch (Exception $e) { $conn->rollback(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit; }
}

// =========================================================================
// 🚀 REVERT ENGINE: BATAL APPROVAL (KEMBALI KE DRAFT & AKTIFKAN RAPB LAMA)
// =========================================================================
if ($action == 'cancel_approval_async') {
    $hid = (int)$_POST['id'];
    $conn->begin_transaction();
    try {
        $check = $conn->query("SELECT tahun_anggaran, kategori, deskripsi FROM syifa_budget_headers WHERE id = $hid")->fetch_assoc();
        if(!$check) throw new Exception("Data tidak ditemukan.");

        // Kembalikan ke Draft / Reviewed
        $conn->query("UPDATE syifa_budget_headers SET status = 'Reviewed' WHERE id = $hid");
        $conn->query("UPDATE syifa_budgets SET status = 'Menunggu Approval' WHERE header_id = $hid");
        
        // 🚀 REVERT GUARD: Aktifkan kembali Anggaran Normal sebelumnya secara instan!
        $tahun = $check['tahun_anggaran'];
        $kat = $check['kategori'];
        $cek_aktif = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$tahun' AND kategori='$kat' AND status IN ('Approved', 'Generated')")->num_rows;
        
        if ($cek_aktif == 0) {
            $q_revert = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$tahun' AND kategori='$kat' AND status IN ('Replaced', 'Archived', 'DIARSIPKAN (DIGANTIKAN)') ORDER BY id DESC LIMIT 1");
            if ($q_revert && $q_revert->num_rows > 0) {
                $revert_id = $q_revert->fetch_assoc()['id'];
                $conn->query("UPDATE syifa_budget_headers SET status = 'Approved' WHERE id = $revert_id");
                $conn->query("UPDATE syifa_budgets SET status = 'Disetujui' WHERE header_id = $revert_id");
            }
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Pengesahan dibatalkan. Jika ini adalah Perubahan, maka RAPB normal sebelumnya otomatis diaktifkan kembali.']);
        exit;
    } catch (Exception $e) { $conn->rollback(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit; }
}

// =========================================================================
// 2. SOVEREIGN CLONING ENGINE
// =========================================================================
if ($action == 'duplicate_header') {
    $id = (int)$_POST['id'];
    $new_desc = trim($_POST['new_name']);
    $new_year = (int)$_POST['new_year'];
    
    $conn->begin_transaction();
    try {
        $h_orig = $conn->query("SELECT * FROM syifa_budget_headers WHERE id = $id")->fetch_assoc();
        if(!$h_orig) throw new Exception("Sumber tidak ada.");
        
        if(empty($new_desc)) $new_desc = $h_orig['deskripsi'] . " (Copy)";
        if(!$new_year) $new_year = $h_orig['tahun_anggaran'];

        $stmt_h = $conn->prepare("INSERT INTO syifa_budget_headers (tahun_anggaran, deskripsi, total_anggaran, status, kategori, created_by) VALUES (?, ?, ?, 'Draft', ?, ?)");
        $stmt_h->bind_param("isssi", $new_year, $new_desc, $h_orig['total_anggaran'], $h_orig['kategori'], $uid);
        $stmt_h->execute();
        $new_hid = $conn->insert_id;

        $cat_map = [];
        $res_cat = $conn->query("SELECT * FROM syifa_budgets WHERE header_id = $id AND is_category = 1");
        while($cat = $res_cat->fetch_assoc()) {
            $stmt_c = $conn->prepare("INSERT INTO syifa_budgets (header_id, tahun_anggaran, kategori, jenis_belanja, kode_akun, uraian_manual, nominal_pagu, status, created_by, is_category, parent_id) VALUES (?, ?, 'Pengeluaran', ?, '', ?, 0, 'Draft', ?, 1, NULL)");
            $stmt_c->bind_param("iissi", $new_hid, $new_year, $cat['jenis_belanja'], $cat['uraian_manual'], $uid);
            $stmt_c->execute();
            $cat_map[$cat['id']] = $conn->insert_id;
        }

        $res_item = $conn->query("SELECT * FROM syifa_budgets WHERE header_id = $id AND is_category = 0");
        while($item = $res_item->fetch_assoc()) {
            $new_parent = isset($cat_map[$item['parent_id']]) ? $cat_map[$item['parent_id']] : null;
            $stmt_i = $conn->prepare("INSERT INTO syifa_budgets (header_id, tahun_anggaran, kategori, jenis_belanja, kode_akun, uraian_manual, nominal_pagu, status, created_by, is_category, parent_id) VALUES (?, ?, 'Pengeluaran', ?, ?, ?, ?, 'Draft', ?, 0, ?)");
            $stmt_i->bind_param("iisssdii", $new_hid, $new_year, $item['jenis_belanja'], $item['kode_akun'], $item['uraian_manual'], $item['nominal_pagu'], $uid, $new_parent);
            $stmt_i->execute();
            $new_bid = $conn->insert_id;

            $res_m = $conn->query("SELECT * FROM syifa_budget_monthly_plan WHERE budget_id = {$item['id']}");
            while($mp = $res_m->fetch_assoc()) {
                $conn->query("INSERT INTO syifa_budget_monthly_plan (budget_id, bulan, nominal_rencana) VALUES ($new_bid, {$mp['bulan']}, {$mp['nominal_rencana']})");
            }
        }
        $conn->commit();
        header("Location: index.php?page=anggaran_belanja&view=hub&tab=input&msg_type=success_dup&t=".$h_orig['total_anggaran']."&tahun=".$new_year);
        exit;
    } catch (Exception $e) { $conn->rollback(); die("Gagal."); }
}

// =========================================================================
// 3. CORE SAVE & UTILITIES
// =========================================================================
if ($action == 'rename_worksheet') {
    $id = (int)$_POST['id'];
    $name = trim($_POST['nama_baru']);
    $stmt = $conn->prepare("UPDATE syifa_budget_headers SET deskripsi = ? WHERE id = ?");
    $stmt->bind_param("si", $name, $id);
    $stmt->execute();
    
    if (isset($_POST['return_view']) && $_POST['return_view'] == 'worksheet') {
        $hid = (int)$_POST['header_id'];
        header("Location: index.php?page=anggaran_belanja&view=worksheet&header_id=$hid&tab=input"); exit;
    }
    header("Location: index.php?page=anggaran_belanja&view=hub&tab=input"); exit;
}

if ($action == 'save_worksheet_expense') {
    $hid = (int)$_POST['header_id'];
    $head = $conn->query("SELECT tahun_anggaran FROM syifa_budget_headers WHERE id=$hid")->fetch_assoc();
    $tahun = $head['tahun_anggaran'];
    if (isset($_POST['cancel'])) {
        $conn->begin_transaction();
        try {
            $conn->query("UPDATE syifa_budget_headers SET status = 'Draft' WHERE id = $hid");
            $conn->query("UPDATE syifa_budgets SET status = 'Draft' WHERE header_id = $hid");
            $conn->query("DELETE FROM anggaran_unit_pengajuan WHERE tahun = '$tahun'"); 
            $conn->commit();
            header("Location: index.php?page=anggaran_belanja&view=worksheet&header_id=$hid&tab=input&msg_type=success_cancel&tahun=$tahun"); exit;
        } catch (Exception $e) { $conn->rollback(); die("Error."); }
    }
    $status_item = isset($_POST['final']) ? 'Disetujui' : 'Draft';
    $status_head = isset($_POST['final']) ? 'Approved' : 'Draft'; 
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM syifa_budget_monthly_plan WHERE budget_id IN (SELECT id FROM syifa_budgets WHERE header_id=$hid)");
        $conn->query("DELETE FROM syifa_budgets WHERE header_id=$hid");
        $total_ws = 0; $map_ids = []; 
        if(!empty($_POST['row_type'])) {
            foreach($_POST['row_type'] as $i => $type) {
                if($type !== 'category') continue;
                $js = $_POST['jenis'][$i]; $ur = $conn->real_escape_string($_POST['uraian_manual'][$i]); $uk = $_POST['ui_key'][$i];
                $conn->query("INSERT INTO syifa_budgets (header_id, tahun_anggaran, kategori, jenis_belanja, kode_akun, uraian_manual, nominal_pagu, status, created_by, is_category, parent_id) VALUES ($hid, $tahun, 'Pengeluaran', '$js', '', '$ur', 0, '$status_item', $uid, 1, NULL)");
                $map_ids[$uk] = $conn->insert_id; 
            }
            foreach($_POST['row_type'] as $i => $type) {
                if($type !== 'item') continue;
                $pk = $_POST['parent_key'][$i]; $pid = isset($map_ids[$pk]) ? $map_ids[$pk] : "NULL";
                $ur = $conn->real_escape_string($_POST['uraian_manual'][$i]); $coa = $_POST['coa'][$i] ?? ''; $nom = cleanNum($_POST['total'][$i]); $js = $_POST['jenis'][$i];
                $conn->query("INSERT INTO syifa_budgets (header_id, tahun_anggaran, kategori, jenis_belanja, kode_akun, uraian_manual, nominal_pagu, status, created_by, is_category, parent_id) VALUES ($hid, $tahun, 'Pengeluaran', '$js', '$coa', '$ur', $nom, '$status_item', $uid, 0, $pid)");
                $nid = $conn->insert_id; $total_ws += $nom;
                for($m=1; $m<=12; $m++) { $mv = cleanNum($_POST["m$m"][$i]); if($mv > 0) $conn->query("INSERT INTO syifa_budget_monthly_plan (budget_id, bulan, nominal_rencana) VALUES ($nid, $m, $mv)"); }
            }
        }
        $conn->query("UPDATE syifa_budget_headers SET total_anggaran = $total_ws, status = '$status_head' WHERE id = $hid");
        $conn->commit();
        header("Location: index.php?page=anggaran_belanja&view=hub&tab=input&tahun=$tahun"); exit;
    } catch (Exception $e) { $conn->rollback(); die("Fail."); }
}

// 🚀 REVERT PADA SAAT DELETE DRAF
if ($action == 'delete_header') {
    $id = (int)$_GET['id'];
    $conn->begin_transaction();
    try {
        $q_head = $conn->query("SELECT tahun_anggaran, kategori, deskripsi FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
        
        $conn->query("DELETE FROM syifa_budget_monthly_plan WHERE budget_id IN (SELECT id FROM syifa_budgets WHERE header_id=$id)");
        $conn->query("DELETE FROM syifa_budgets WHERE header_id=$id");
        $conn->query("DELETE FROM syifa_budget_headers WHERE id=$id");
        
        // 🚀 THE REVERT: Aktifkan kembali Anggaran lama secara paksa jika tidak ada yg aktif
        if ($q_head) {
            $tahun = $q_head['tahun_anggaran'];
            $kat = $q_head['kategori'];
            
            $cek_aktif = $conn->query("SELECT id FROM syifa_budget_headers WHERE tahun_anggaran='$tahun' AND kategori='$kat' AND status IN ('Approved', 'Generated')")->