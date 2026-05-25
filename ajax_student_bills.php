<?php
/**
 * ajax_student_bills.php - API HANDLER UNTUK IDENTIFIKASI PIUTANG
 * Perbaikan: Menampilkan semua tagihan (termasuk yang lunas) untuk kebutuhan koreksi audit.
 * Versi: 2.0 (Audit Friendly)
 */
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { exit(json_encode(['error' => 'Unauthorized'])); }

$action = $_GET['action'] ?? '';

// 1. Ambil Daftar Mahasiswa Aktif untuk Autocomplete Dropdown
if ($action == 'get_mhs_list') {
    $res = $conn->query("SELECT id, nim, nama FROM syifa_mahasiswa ORDER BY nama ASC");
    echo json_encode($res->fetch_all(MYSQLI_ASSOC));
    exit;
}

// 2. Ambil Seluruh Riwayat Tagihan per Mahasiswa (Tanpa Filter Lunas)
if ($action == 'get_bills') {
    $mhs_id = (int)$_GET['mhs_id'];
    
    // Cari NIM berdasarkan ID
    $m_res = $conn->query("SELECT nim FROM syifa_mahasiswa WHERE id = $mhs_id");
    $mhs = $m_res->fetch_assoc();
    
    if (!$mhs) { echo json_encode([]); exit; }
    
    $nim = $mhs['nim'];

    // Query menampilkan semua tagihan agar bisa dikoreksi meski sudah lunas
    // Menambahkan created_at untuk menampilkan tanggal terbit tagihan
    $res = $conn->query("SELECT id, nama_tagihan, kode_tahun, nominal, terbayar, status_bayar, created_at 
                         FROM keuangan_tagihan 
                         WHERE nim = '$nim' 
                         ORDER BY created_at DESC, id DESC");
    
    $data = [];
    while($row = $res->fetch_assoc()) {
        $sisa = (double)$row['nominal'] - (double)$row['terbayar'];
        $tgl_tagihan = date('d/m/Y', strtotime($row['created_at']));
        
        // Label diperjelas: [Tanggal] Nama Tagihan (Sisa: Rp xxx) [STATUS]
        $row['display_label'] = "[{$tgl_tagihan}] {$row['nama_tagihan']} - Sisa: Rp " . number_format($sisa, 0, ',', '.') . " [{$row['status_bayar']}]";
        $row['sisa_val'] = $sisa;
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}