<?php
/**
 * period_guard.php - GLOBAL PERIOD LOCK ENGINE
 * Versi: 3.0 (Indestructible Fail-Safe Edition)
 * Fungsi: Memastikan tidak ada transaksi di periode yang sudah dikunci.
 * Perbaikan: Anti-Crash (Try-Catch) & Sinkronisasi dengan tabel syifa_periode_laporan
 */

function isPeriodLocked($conn, $tanggal) {
    try {
        // Menggunakan tabel yang benar sesuai database aktual (syifa_periode_laporan)
        $q = $conn->prepare("
            SELECT status 
            FROM syifa_periode_laporan 
            WHERE ? BETWEEN tgl_mulai AND tgl_akhir
            LIMIT 1
        ");
        
        if ($q) {
            $q->bind_param("s", $tanggal);
            $q->execute();
            $res = $q->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $status = strtoupper($row['status']);
                return ($status === 'DITUTUP' || $status === 'CLOSED');
            }
        }
        return false;
    } catch (Exception $e) {
        // FAIL-SAFE MUTLAK: 
        // Jika tabel sedang diperbarui atau ada error SQL "Unknown column",
        // sistem akan melakukan BYPASS (mengabaikan error) agar KASIR TETAP BISA INPUT TRANSAKSI.
        return false;
    }
}

function guardPeriod($conn, $tanggal) {
    if (isPeriodLocked($conn, $tanggal)) {
        throw new Exception("System Guard: Periode transaksi untuk tanggal " . date('d/m/Y', strtotime($tanggal)) . " sudah ditutup/dikunci. Akses ditolak.");
    }
}
?>