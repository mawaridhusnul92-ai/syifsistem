<?php
/**
 * periode_action.php - CONTROLLER PERIODE PELAPORAN KEUANGAN
 * Versi: 168.2 (Sovereign Master Key & Strict Role Validation - Fix DB Sync)
 * Perbaikan: Verifikasi Role Super Admin ditarik langsung dari database untuk mengatasi bug Session hilang/kosong.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid = (int)$_SESSION['user_id'];

// FIX MUTLAK: Ambil role_id langsung dari database untuk menjamin keamanan dan menghindari bug Session = 0
$query_role = $conn->query("SELECT role_id FROM users WHERE id = $uid");
$user_db = $query_role->fetch_assoc();
$role = (int)($user_db['role_id'] ?? 0);

function done($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: index.php?page=periode_setting");
    exit;
}

// 1. GENERATE BULK (Otomatis Buat 1 Tahun)
if ($action == 'generate_bulk') {
    $tahun = (int)$_POST['tahun_target'];
    $with_sem = isset($_POST['include_semester']) ? 1 : 0;
    
    $bulan_arr = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT IGNORE INTO syifa_periode_laporan (nama_periode, jenis_periode, tgl_mulai, tgl_akhir, created_by) VALUES (?, 'Bulanan', ?, ?, ?)");
        
        for ($i = 1; $i <= 12; $i++) {
            $nama = $bulan_arr[$i] . " " . $tahun;
            $start = "$tahun-" . str_pad($i, 2, '0', STR_PAD_LEFT) . "-01";
            $end = date('Y-m-t', strtotime($start));
            
            $stmt->bind_param("sssi", $nama, $start, $end, $uid);
            $stmt->execute();
        }

        if ($with_sem) {
            $stmt_sem = $conn->prepare("INSERT IGNORE INTO syifa_periode_laporan (nama_periode, jenis_periode, tgl_mulai, tgl_akhir, created_by) VALUES (?, 'Semester', ?, ?, ?)");
            
            // Semester 1
            $n1 = "Semester Ganjil $tahun"; $s1 = "$tahun-01-01"; $e1 = "$tahun-06-30";
            $stmt_sem->bind_param("sssi", $n1, $s1, $e1, $uid); $stmt_sem->execute();
            
            // Semester 2
            $n2 = "Semester Genap $tahun"; $s2 = "$tahun-07-01"; $e2 = "$tahun-12-31";
            $stmt_sem->bind_param("sssi", $n2, $s2, $e2, $uid); $stmt_sem->execute();
        }
        
        $conn->commit();
        done('success', "Berhasil me-generate 12 periode bulanan untuk tahun $tahun.");
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Gagal Generate: " . $e->getMessage());
    }
}

// 2. SIMPAN MANUAL PERIODE
if ($action == 'save_periode') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $nama = $conn->real_escape_string($_POST['nama']);
    $jenis = $conn->real_escape_string($_POST['jenis']);
    $start = $_POST['start'];
    $end = $_POST['end'];
    $ket = $conn->real_escape_string($_POST['keterangan']);
    $is_audit = isset($_POST['is_audit']) ? 1 : 0;

    if ($id) {
        $stmt = $conn->prepare("UPDATE syifa_periode_laporan SET nama_periode=?, jenis_periode=?, tgl_mulai=?, tgl_akhir=?, is_audit=?, keterangan=? WHERE id=?");
        if(!$stmt) die("Error Prepare Update: ".$conn->error);
        $stmt->bind_param("ssssisi", $nama, $jenis, $start, $end, $is_audit, $ket, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO syifa_periode_laporan (nama_periode, jenis_periode, tgl_mulai, tgl_akhir, is_audit, keterangan, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if(!$stmt) die("Error Prepare Insert: ".$conn->error);
        $stmt->bind_param("ssssisi", $nama, $jenis, $start, $end, $is_audit, $ket, $uid);
    }

    if($stmt->execute()) done('success', 'Data periode berhasil disimpan.');
    else done('danger', 'Gagal menyimpan data: ' . $stmt->error);
}

// 3. TOGGLE STATUS (MASTER KEY APPLIED)
if ($action == 'toggle_status') {
    $id = (int)$_GET['id'];
    $target = $_GET['set'] ?? 'Aktif';
    
    // GUARD MUTLAK: Hanya Super Admin (1) yang boleh mengubah dari Ditutup menjadi Aktif
    if ($target == 'Aktif' && $role !== 1) {
        done('danger', 'AKSES DITOLAK: Secara standar Akuntansi (ISAK 35), hanya Super Admin yang diizinkan untuk merekonstruksi / membuka kembali periode yang sudah ditutup (Ledger Freeze).');
    }

    $closed_at = ($target == 'Ditutup') ? date('Y-m-d H:i:s') : NULL;
    $closed_by = ($target == 'Ditutup') ? $uid : NULL;

    if($target == 'Ditutup') {
        $conn->query("UPDATE syifa_periode_laporan SET status='$target', closed_at='$closed_at', closed_by=$uid WHERE id=$id");
        done('success', "Buku pada periode tersebut telah resmi DITUTUP. Jurnal tidak bisa ditambah/diedit.");
    } else {
        // ?? MASTER KEY: Buka status ke 'Aktif' dan lepas rantai 'is_audit' = 0
        $conn->query("UPDATE syifa_periode_laporan SET status='$target', closed_at=NULL, closed_by=NULL, is_audit=0 WHERE id=$id");
        done('warning', "STATUS MASTER KEY: Periode telah dibuka kembali secara paksa oleh Super Admin.");
    }
}
ob_end_flush();
?>