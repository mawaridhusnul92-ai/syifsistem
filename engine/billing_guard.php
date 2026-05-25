<?php
/**
 * billing_guard.php - SECURITY ENGINE UNTUK BILLING MAHASISWA
 * Fungsi: Validasi Period Lock & Pencatatan Audit Trail
 */

function isPeriodeClosed($conn, $tahun, $periode) {
    $tahun_safe = $conn->real_escape_string($tahun);
    $periode_safe = $conn->real_escape_string($periode);
    
    $q = $conn->query("SELECT is_closed FROM keuangan_periode 
                       WHERE tahun_akademik='$tahun_safe' AND periode='$periode_safe'
                       LIMIT 1");
    if ($q && $row = $q->fetch_assoc()) {
        return $row['is_closed'] == 1;
    }
    return false;
}

function logEditTransaksi($conn, $ref_no, $aksi, $keterangan, $user_id) {
    $ref_safe = $conn->real_escape_string($ref_no);
    $aksi_safe = $conn->real_escape_string($aksi);
    $ket_safe = $conn->real_escape_string($keterangan);
    $uid_safe = (int)$user_id;

    $conn->query("INSERT INTO log_edit_transaksi (ref_no, aksi, keterangan, user_id, created_at) 
                  VALUES ('$ref_safe', '$aksi_safe', '$ket_safe', $uid_safe, NOW())");
}
?>