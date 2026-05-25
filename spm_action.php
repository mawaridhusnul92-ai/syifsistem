<?php
/**
 * spm_action.php - PUSAT KENDALI SURAT PERINTAH MEMBAYAR (SPM)
 * Versi: 3.0 (Sovereign Grand Master - Anggaran Perubahan Fetcher Edition)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak: 
 * API Tarik Anggaran kini dirancang untuk mengambil Worksheet TERBARU 
 * (ORDER BY id DESC LIMIT 1) sehingga Anggaran Perubahan (Revisi) 
 * dapat tertarik dengan sempurna tanpa notifikasi error palsu.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';
if (file_exists('engine/GlobalLogger.php')) { require_once 'engine/GlobalLogger.php'; }

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$uid = (int)$_SESSION['user_id'];
$role_id = (int)($_SESSION['role_id'] ?? 0);
$is_superadmin = ($role_id === 1);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 🛡️ THE AUTO-HEALER
try {
    $conn->query("CREATE TABLE IF NOT EXISTS keuangan_spm_header (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_spm VARCHAR(150) NOT NULL,
        tgl_mulai DATE NOT NULL,
        tgl_akhir DATE NOT NULL,
        is_tambahan TINYINT(1) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'DRAFT',
        total_nominal DOUBLE DEFAULT 0,
        created_by INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS keuangan_spm_detail (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spm_id INT NOT NULL,
        rincian VARCHAR(255) NOT NULL,
        kode_akun VARCHAR(50) NOT NULL,
        nominal DOUBLE DEFAULT 0,
        FOREIGN KEY (spm_id) REFERENCES keuangan_spm_header(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {}

function spm_done($type, $msg, $url_params = '') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: index.php?page=laporan_bendahara" . $url_params);
    exit;
}

if ($action == 'init_spm') {
    $nama = $conn->real_escape_string($_POST['nama_spm']);
    $tgl_mulai = $conn->real_escape_string($_POST['tgl_mulai']);
    $tgl_akhir = $conn->real_escape_string($_POST['tgl_akhir']);
    $is_tambahan = isset($_POST['is_tambahan']) ? 1 : 0;

    $conn->query("INSERT INTO keuangan_spm_header (nama_spm, tgl_mulai, tgl_akhir, is_tambahan, status, created_by) VALUES ('$nama', '$tgl_mulai', '$tgl_akhir', $is_tambahan, 'DRAFT', $uid)");
    $new_id = $conn->insert_id;

    if(class_exists('GlobalLogger')) { GlobalLogger::log($conn, $uid, 'Buat', 'Laporan Bendahara', 'keuangan_spm_header', $new_id, "Inisiasi SPM Baru: $nama", null, null); }
    header("Location: index.php?page=laporan_bendahara&view=builder&id=" . $new_id); exit;
}

if ($action == 'save_spm') {
    $spm_id = (int)$_POST['spm_id'];
    $status_target = $_POST['status_target']; 
    $rincian = $_POST['rincian'] ?? [];
    $kode_akun = $_POST['kode_akun'] ?? [];
    $nominal = $_POST['nominal'] ?? [];

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM keuangan_spm_detail WHERE spm_id = $spm_id");
        $total_spm = 0;
        $stmt = $conn->prepare("INSERT INTO keuangan_spm_detail (spm_id, rincian, kode_akun, nominal) VALUES (?, ?, ?, ?)");
        for ($i = 0; $i < count($rincian); $i++) {
            $rinc = trim($rincian[$i]); $kode = trim($kode_akun[$i]); $nom  = (double)preg_replace('/[^0-9]/', '', $nominal[$i]);
            if (!empty($rinc) && !empty($kode) && $nom > 0) {
                $stmt->bind_param("issd", $spm_id, $rinc, $kode, $nom); $stmt->execute(); $total_spm += $nom;
            }
        }
        $conn->query("UPDATE keuangan_spm_header SET status = '$status_target', total_nominal = $total_spm WHERE id = $spm_id");
        if(class_exists('GlobalLogger')) { GlobalLogger::log($conn, $uid, 'Perbarui', 'Laporan Bendahara', 'keuangan_spm_header', $spm_id, "Mengupdate SPM ID $spm_id menjadi status $status_target", null, null); }
        $conn->commit();
        $pesan = $status_target == 'GENERATED' ? "SPM berhasil digenerate." : "Draft SPM disimpan.";
        spm_done('success', $pesan);
    } catch (Exception $e) { $conn->rollback(); spm_done('danger', "Gagal menyimpan SPM: " . $e->getMessage()); }
}

if ($action == 'cancel_generate') {
    $spm_id = (int)$_GET['id'];
    if (!$is_superadmin && (!defined('RBAC_EDIT') || !RBAC_EDIT)) { spm_done('danger', "Akses Ditolak."); }
    $conn->query("UPDATE keuangan_spm_header SET status = 'DRAFT' WHERE id = $spm_id");
    spm_done('warning', "Status SPM dikembalikan menjadi DRAFT.", "&view=builder&id=$spm_id");
}

if ($action == 'delete_spm') {
    $spm_id = (int)$_GET['id'];
    $cek = $conn->query("SELECT status, nama_spm FROM keuangan_spm_header WHERE id = $spm_id")->fetch_assoc();
    if ($cek['status'] == 'GENERATED' && !$is_superadmin && (!defined('RBAC_DEL') || !RBAC_DEL)) { spm_done('danger', "Akses Ditolak."); }
    $conn->query("DELETE FROM keuangan_spm_header WHERE id = $spm_id"); 
    spm_done('success', "Data SPM berhasil dihapus secara permanen.");
}

// 🚀 4. API STERIL: MENDUKUNG TARIK ANGGARAN PERUBAHAN
if ($action == 'api_tarik_anggaran') {
    while(ob_get_level()) ob_end_clean(); 
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $spm_id = (int)$_GET['id'];
        $spm = $conn->query("SELECT tgl_mulai, tgl_akhir FROM keuangan_spm_header WHERE id = $spm_id")->fetch_assoc();
        if (!$spm) { echo json_encode(['status' => 'error', 'msg' => 'SPM tidak ditemukan.']); exit; }

        $bulan_pilihan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : date('m', strtotime($spm['tgl_mulai']));
        $tahun_pilihan = isset($_GET['tahun']) ? (int)$_GET['tahun'] : date('Y', strtotime($spm['tgl_mulai']));

        $data = [];
        $cek_tbl = $conn->query("SHOW TABLES LIKE 'syifa_budgets'");
        
        if($cek_tbl && $cek_tbl->num_rows > 0) {
            $has_ta = $conn->query("SHOW COLUMNS FROM syifa_budget_headers LIKE 'tahun_anggaran'")->num_rows > 0;
            $col_tahun = $has_ta ? 'tahun_anggaran' : 'tahun';
            
            // 🚀 THE FIX MUTLAK: Mengambil Worksheet TERBARU (ORDER BY id DESC LIMIT 1) 
            // sehingga Anggaran Perubahan akan terpilih menggantikan Anggaran Normal.
            $q_head = $conn->query("SELECT id FROM syifa_budget_headers WHERE $col_tahun = '$tahun_pilihan' AND kategori IN ('Beban', 'Belanja') AND status IN ('Approved', 'Disetujui', 'LOCKED') ORDER BY id DESC LIMIT 1");
            
            if ($q_head && $q_head->num_rows > 0) {
                $header_id = $q_head->fetch_assoc()['id'];
                
                $sql = "SELECT b.kode_akun, a.nama_akun, SUM(b.nominal_pagu) as nominal_pagu 
                        FROM syifa_budgets b 
                        JOIN syifa_akun a ON b.kode_akun = a.kode_akun 
                        WHERE b.header_id = $header_id AND b.is_category = 0 AND b.nominal_pagu > 0
                        GROUP BY b.kode_akun, a.nama_akun";
                
                $res = $conn->query($sql);
                $nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
                $nama_bln_pilih = $nama_bulan[$bulan_pilihan] ?? "Bulan Ini";

                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $pagu_bulanan = round($r['nominal_pagu'] / 12);
                        $data[] = [
                            'kode_akun' => $r['kode_akun'],
                            'rincian' => "Anggaran " . $r['nama_akun'] . " - " . $nama_bln_pilih . " " . $tahun_pilihan,
                            'nominal' => $pagu_bulanan
                        ];
                    }
                }
            } else {
                echo json_encode(['status' => 'error', 'msg' => "Tidak ada Anggaran Belanja (RAPB) yang disetujui (Approved) untuk tahun $tahun_pilihan. Pastikan Lembar Kerja telah disahkan."]);
                exit;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch(Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => 'Server Error: ' . $e->getMessage()]);
    }
    exit;
}
ob_end_flush();
?>