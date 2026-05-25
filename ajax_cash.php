<?php
/**
 * ajax_cash.php - PUSAT DATA JURNAL & KAS SYIFA (JSON ENGINE)
 * Versi: 61.0 (Sovereign Grand Master - Date Formatting & Clean Data)
 * STATUS: FULL CODE - NO TRUNCATION
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { 
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Unauthorized'])); 
}

$action = $_GET['action'] ?? '';
header('Content-Type: application/json');

// --- 1. AMBIL DETAIL JURNAL (DENGAN NAMA PEMBUAT) ---
if ($action == 'get_trx_detail') {
    $id = (int)$_GET['id'];
    
    // Tarik header termasuk nama akun dan nama user (Pembuat Jurnal)
    $h = $conn->query("SELECT j.*, a.nama_akun as nama_akun_utama, a2.kode_akun as akun_tujuan_kode, u.name as nama_pembuat 
                       FROM syifa_jurnal j 
                       LEFT JOIN syifa_akun a ON j.akun_utama_kode = a.kode_akun 
                       LEFT JOIN syifa_akun a2 ON j.akun_tujuan_kode = a2.kode_akun 
                       LEFT JOIN users u ON j.created_by = u.id
                       WHERE j.id = $id")->fetch_assoc();
    
    if(!$h) exit(json_encode(['error' => 'Data tidak ditemukan']));

    // Fallback Cerdas: Jika transaksi lawas tidak memiliki akun_utama_kode di Header
    if (empty($h['akun_utama_kode'])) {
        $fallback = $conn->query("SELECT d.kode_akun, a.nama_akun 
                                  FROM syifa_jurnal_detail d 
                                  JOIN syifa_akun a ON d.kode_akun = a.kode_akun 
                                  WHERE d.jurnal_id = $id AND (a.kategori IN ('Kas', 'Bank') OR a.is_cash_account=1 OR d.kode_akun LIKE '1-11%') LIMIT 1")->fetch_assoc();
        if ($fallback) {
            $h['akun_utama_kode'] = $fallback['kode_akun'];
            $h['nama_akun_utama'] = $fallback['nama_akun'];
        }
    }

    $d = $conn->query("SELECT d.*, a.nama_akun, m.nama as nama_mhs, m.nim as nim_mhs
                       FROM syifa_jurnal_detail d
                       LEFT JOIN syifa_akun a ON d.kode_akun = a.kode_akun
                       LEFT JOIN syifa_mahasiswa m ON d.mahasiswa_id = m.id
                       WHERE d.jurnal_id = $id");

    $details = [];
    $full_journal = []; // Khusus untuk modal View Detail (Menampilkan kas juga)

    if ($d) {
        while($row = $d->fetch_assoc()) {
            $debit_val = (float)$row['debit'];
            $kredit_val = (float)$row['kredit'];

            // Array Full Journal (Tidak difilter, untuk mata detail)
            $full_journal[] = [
                'kode_akun' => $row['kode_akun'],
                'nama_akun' => $row['nama_akun'],
                'debit' => $debit_val,
                'kredit' => $kredit_val
            ];

            // Filter Kas Utama agar tidak muncul di form inputan lawan
            if ($row['kode_akun'] == $h['akun_utama_kode']) continue;

            $key = $row['kode_akun'] . '_' . $row['mahasiswa_id'] . '_' . $row['tagihan_id_ref'] . '_' . $row['aset_id'];
            
            if (!isset($details[$key])) {
                $row['debit'] = $debit_val;
                $row['kredit'] = $kredit_val;
                $details[$key] = $row;
            } else {
                $details[$key]['debit'] += $debit_val;
                $details[$key]['kredit'] += $kredit_val;
                
                if (!empty($row['keterangan']) && strpos($details[$key]['keterangan'], $row['keterangan']) === false) {
                    $details[$key]['keterangan'] .= " | " . $row['keterangan'];
                }
            }
        }
    }

    echo json_encode([
        'header' => $h,
        'details' => array_values($details),
        'full_journal' => $full_journal
    ]);
    exit;
}

// --- 2. GET MAHASISWA LIST ---
if ($action == 'get_mhs_list') {
    $sql = "SELECT id, nim, nama FROM syifa_mahasiswa ORDER BY nama ASC";
    $q = $conn->query($sql);
    echo json_encode($q ? $q->fetch_all(MYSQLI_ASSOC) : []);
    exit;
}

// --- 3. GET TAGIHAN MAHASISWA (FIX: Format Tanggal & Filter Sisa) ---
if ($action == 'get_mhs_tagihan') {
    $mid = (int)$_GET['mhs_id'];
    $mhs = $conn->query("SELECT nim FROM syifa_mahasiswa WHERE id = $mid")->fetch_assoc();
    $nim = $mhs['nim'] ?? '';
    
    // FIX: Menggunakan DATE_FORMAT agar tampilan elegan, dan memfilter hanya yang memiliki sisa > 0
    $sql = "SELECT id, nama_tagihan, DATE_FORMAT(created_at, '%d/%m/%Y') as tgl_tagihan, (nominal - terbayar) as sisa, status_bayar 
            FROM keuangan_tagihan 
            WHERE nim = '$nim' AND (nominal - terbayar) > 0
            ORDER BY created_at ASC";
            
    $q = $conn->query($sql);
    echo json_encode($q ? $q->fetch_all(MYSQLI_ASSOC) : []);
    exit;
}

// --- 4. GET ASSET LIST ---
if ($action == 'get_asset_list') {
    $sql = "SELECT id, asset_code as kode, asset_name as nama FROM assets ORDER BY asset_name ASC";
    $q = $conn->query($sql);
    echo json_encode($q ? $q->fetch_all(MYSQLI_ASSOC) : []);
    exit;
}

// --- 5. AUTO-GENERATE REF ---
if ($action == 'gen_ref') {
    $type = $_GET['type'] ?? 'income';
    $prefix = match($type) { 'income','receipt'=>'BKM', 'expense','disbursement'=>'BKK', 'transfer'=>'TRF', default=>'BKM' };
    $num = function_exists('getNextNumber') ? getNextNumber($conn, match($type){'income','receipt'=>'kas_masuk','transfer'=>'kas_transfer','expense','disbursement'=>'kas_keluar',default=>'auto_jurnal'}) : $prefix.'-'.date('YmdHis');
    echo json_encode(['ref' => $num]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);