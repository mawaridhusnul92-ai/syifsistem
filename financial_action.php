<?php
/**
 * financial_action.php - PUSAT KENDALI LAPORAN KEUANGAN ERP SYIFA
 * Versi: 15.0 (Sovereign Grand Master - Ultimate Universal Router Edition)
 * Perbaikan Mutlak: 
 * 1. Mengembalikan fungsi Routing Dinamis yang hilang di versi 14.8. Semua modul
 * seperti Neraca Saldo, Laporan Aset, dll kini memiliki rute pendaratannya masing-masing.
 * 2. Menerapkan ENUM Breaker secara sentral untuk mengobati Database Error.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

// Injeksi Engine jika ada (untuk Neraca Healing)
$engine_path = 'engine/LedgerAggregationEngine.php';
if(file_exists($engine_path)) { require_once $engine_path; }

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid = (int)$_SESSION['user_id'];

// ??? ENUM BREAKER CENTRAL: Mencegah error "Data truncated for column 'jenis_laporan'"
@$conn->query("ALTER TABLE laporan_keuangan_setting MODIFY COLUMN jenis_laporan VARCHAR(100)");

function done($type, $msg, $target = 'laporan_keuangan') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: index.php?page=$target");
    exit;
}

if ($action == 'save_report_setup') {
    $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $judul   = trim($_POST['judul'] ?? 'Laporan Baru');
    $type    = $_POST['jenis_laporan'] ?? 'posisi_keuangan'; 
    $start   = $_POST['start_date'] ?? date('Y-01-01');
    $akhir   = $_POST['end_date'] ?? date('Y-m-d');
    $desk    = $_POST['deskripsi'] ?? $_POST['desc'] ?? ''; 
    $metode  = $_POST['metode'] ?? 'Akrual';
    
    // ?? THE ULTIMATE ROUTER: Memetakan SEMUA Laporan secara akurat!
    $target_page = match($type) {
        'neraca', 'posisi_keuangan' => 'laporan_posisi_keuangan',
        'perubahan_aset_neto' => 'laporan_perubahan_aset_neto',
        'aktivitas'           => 'laporan_aktivitas',
        'perubahan_aset'      => 'laporan_perubahan_aset', // ??? Rute Laporan Aset Dipulihkan
        'neraca_saldo'        => 'neraca_saldo',           // ??? Rute Neraca Saldo Dipulihkan
        'kas_detail'          => 'laporan_kas_detail',
        'kas_summary'         => 'laporan_kas_summary',
        'buku_besar'          => 'laporan_buku_besar',
        'gaji_pegawai'        => 'hr_laporan_gaji',        // ??? Rute Laporan Gaji Dipulihkan
        default               => 'laporan_keuangan'
    };

    $comp_dates = [];
    $comp_starts = $_POST['comp_start'] ?? [];
    $comp_ends   = $_POST['comp_end'] ?? [];
    if (is_array($comp_ends)) {
        foreach ($comp_ends as $idx => $e_date) {
            if (!empty($e_date)) {
                $s_date = (!empty($comp_starts[$idx])) ? $comp_starts[$idx] : date('Y-01-01', strtotime($e_date));
                if ($type == 'posisi_keuangan' || $type == 'neraca') {
                    $comp_dates[] = ['e' => $e_date];
                } else {
                    $comp_dates[] = ['s' => $s_date, 'e' => $e_date];
                }
            }
        }
    }
    $json_comp = empty($comp_dates) ? NULL : json_encode($comp_dates);

    $conn->begin_transaction();
    try {
        if ($id) {
            $stmt = $conn->prepare("UPDATE laporan_keuangan_setting SET judul_laporan=?, tgl_mulai=?, tgl_akhir=?, deskripsi=?, comp_dates=?, metode=? WHERE id=?");
            $stmt->bind_param("ssssssi", $judul, $start, $akhir, $desk, $json_comp, $metode, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO laporan_keuangan_setting (judul_laporan, jenis_laporan, tgl_mulai, tgl_akhir, metode, deskripsi, comp_dates, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssi", $judul, $type, $start, $akhir, $metode, $desk, $json_comp, $uid);
        }

        if ($stmt->execute()) {
            $final_id = $id ?: $conn->insert_id;
            
            // Healing Database jika ada engine nya
            if (class_exists('LedgerAggregationEngine') && method_exists('LedgerAggregationEngine', 'autoHealDatabase')) {
                LedgerAggregationEngine::autoHealDatabase($conn);
            }
            
            $conn->commit();
            header("Location: index.php?page=$target_page&view=render&id=$final_id");
            exit;
        } else { throw new Exception($conn->error); }
    } catch (Exception $e) { 
        $conn->rollback(); 
        // STICKY ROUTING: Jangan ditendang keluar jika gagal!
        done('danger', "Gagal Simpan Laporan: " . $e->getMessage(), $target_page); 
    }
}

if ($action == 'delete_setting') {
    $id = (int)$_GET['id'];
    $info = $conn->query("SELECT jenis_laporan FROM laporan_keuangan_setting WHERE id = $id")->fetch_assoc();
    $type = $info['jenis_laporan'] ?? '';
    if ($conn->query("DELETE FROM laporan_keuangan_setting WHERE id = $id")) {
        $target = match($type) {
            'posisi_keuangan', 'neraca' => 'laporan_posisi_keuangan',
            'perubahan_aset_neto' => 'laporan_perubahan_aset_neto',
            'aktivitas'           => 'laporan_aktivitas',
            'perubahan_aset'      => 'laporan_perubahan_aset',
            'neraca_saldo'        => 'neraca_saldo',
            'kas_detail'          => 'laporan_kas_detail',
            'kas_summary'         => 'laporan_kas_summary',
            'buku_besar'          => 'laporan_buku_besar',
            'gaji_pegawai'        => 'hr_laporan_gaji',
            default => 'laporan_keuangan'
        };
        done('success', "Arsip laporan berhasil dihapus.", $target);
    } else { done('danger', "Gagal menghapus arsip."); }
}
ob_end_flush();
?>