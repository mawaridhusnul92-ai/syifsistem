<?php
/**
 * period_helper.php - ACCOUNTING PERIOD LOCK SYSTEM
 * Deskripsi: Mencegah posting jurnal di bulan/tahun yang sudah di-Close (Tutup Buku).
 * Versi: 2.0 (Sync dengan syifa_periode_laporan)
 */

function isPeriodOpen($conn, $tanggal) {
    $q = $conn->prepare("
        SELECT status 
        FROM syifa_periode_laporan 
        WHERE ? BETWEEN tgl_mulai AND tgl_akhir
        LIMIT 1
    ");
    
    if (!$q) return true; // Default OPEN jika query gagal
    
    $q->bind_param("s", $tanggal);
    $q->execute();
    $res = $q->get_result();
    $r = $res->fetch_assoc();

    // Jika belum ada data di tabel periode, default = OPEN (Aman)
    if(!$r) return true; 

    // Return true selama statusnya BUKAN 'Ditutup'
    return $r['status'] !== 'Ditutup';
}
?>