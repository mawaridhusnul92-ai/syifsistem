<?php
/**
 * journal_delete.php - HANDLER DELETE JURNAL UMUM
 * Keamanan: Validasi status posting & Integrity Check
 */
session_start();
require_once 'config/koneksi.php';

// Pastikan hanya bisa diakses via POST untuk keamanan data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];

    // 1. Ambil data status posting sebelum menghapus
    // Menggunakan Prepared Statement untuk mencegah SQL Injection
    $stmt_check = $conn->prepare("SELECT is_posted, ref_no FROM journals WHERE id = ?");
    $stmt_check->bind_param("i", $id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    $data = $result->fetch_assoc();

    if ($data) {
        // 2. Cek apakah jurnal sudah diposting (Standard Accounting Rule)
        if ($data['is_posted'] == 0) {
            
            // 3. Eksekusi Penghapusan
            // Karena tabel journal_items menggunakan ON DELETE CASCADE, 
            // maka detail jurnal akan otomatis terhapus saat header dihapus.
            $stmt_del = $conn->prepare("DELETE FROM journals WHERE id = ?");
            $stmt_del->bind_param("i", $id);
            
            if ($stmt_del->execute()) {
                $_SESSION['flash'] = [
                    'type' => 'success', 
                    'msg' => 'Jurnal <strong>' . $data['ref_no'] . '</strong> berhasil dihapus secara permanen.'
                ];
            } else {
                $_SESSION['flash'] = [
                    'type' => 'danger', 
                    'msg' => 'Gagal menghapus jurnal dari database: ' . $conn->error
                ];
            }
        } else {
            // Jurnal sudah Posted tidak boleh dihapus (Audit Trail Protection)
            $_SESSION['flash'] = [
                'type' => 'warning', 
                'msg' => 'Jurnal yang sudah diposting tidak dapat dihapus demi integritas data keuangan.'
            ];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Data jurnal tidak ditemukan.'];
    }
} else {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Akses tidak sah (Invalid Request Method).'];
}

// Redirect kembali ke halaman utama Jurnal (jurnal.php)
header("Location: jurnal.php");
exit;