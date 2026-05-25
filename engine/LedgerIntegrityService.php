<?php
/**
 * LedgerIntegrityService.php - SYSTEM AUDIT GUARD & PERIOD LOCK
 * Melindungi Ledger dari perubahan setelah periode ditutup (Ledger Freeze).
 * Versi: 3.2 (Sovereign Grand Master - Sync with syifa_periode_laporan)
 * Perbaikan: Menyelaraskan pengecekan Ledger Freeze dengan tabel utama 
 * `syifa_periode_laporan` agar sinkron dengan perintah Buka Akses dari Super Admin.
 */
class LedgerIntegrityService {
    
    // MENCEGAH JURNAL BARU / EDIT DI PERIODE TERKUNCI
    public static function checkPeriodLock(mysqli $conn, $tahun, $bulan) {
        // Ambil sampel tanggal (tanggal 15) untuk mendeteksi rentang periode di bulan & tahun tersebut
        $sample_date = sprintf("%04d-%02d-15", $tahun, $bulan);
        
        $stmt = $conn->prepare("SELECT status FROM syifa_periode_laporan WHERE ? BETWEEN tgl_mulai AND tgl_akhir LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $sample_date);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            
            // Cek apakah statusnya benar-benar DITUTUP
            if ($res && (strtoupper($res['status']) === 'DITUTUP' || strtoupper($res['status']) === 'CLOSED')) {
                throw new Exception("Periode $bulan/$tahun telah dikunci (Ledger Freeze). Modifikasi data/jurnal tidak diizinkan. Hubungi Super Admin untuk membuka akses periode.");
            }
        }
    }

    public static function validateSnapshotFreshness(mysqli $conn, $tgl_akhir) {
        $stmt = $conn->prepare("SELECT MAX(tgl_jurnal) as max_date, MAX(id) as max_id FROM syifa_jurnal WHERE tgl_jurnal <= ?");
        $stmt->bind_param("s", $tgl_akhir);
        $stmt->execute();
        $journal = $stmt->get_result()->fetch_assoc();
        
        $q_state = $conn->query("SELECT last_backdate_edit FROM system_state WHERE id=1");
        $sys_state = $q_state ? $q_state->fetch_assoc() : null;
        $last_journal_update = $sys_state['last_backdate_edit'] ?? '1970-01-01 00:00:00';

        $stmt2 = $conn->prepare("SELECT MAX(closed_at) as last_snapshot FROM syifa_closing_log");
        $stmt2->execute();
        $snap = $stmt2->get_result()->fetch_assoc();
        $last_snap_time = $snap['last_snapshot'] ?? '1970-01-01 00:00:00';

        if(strtotime($last_journal_update) > strtotime($last_snap_time) && $last_snap_time != '1970-01-01 00:00:00') {
            throw new Exception("INTEGRITY COMPROMISED: Ditemukan modifikasi jurnal (backdate) setelah snapshot terakhir. Silakan lakukan generate ulang Ledger Snapshot.");
        }
    }
}
?>