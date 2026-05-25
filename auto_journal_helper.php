<?php
/**
 * auto_journal_helper.php - AUTO JOURNAL HELPER v2 (SAFE VERSION)
 * Upgrade Enterprise:
 * - Database Transaction Safe (Commit/Rollback)
 * - Validasi Balance Akuntansi (Anti-Pincang)
 * - Penomoran Jurnal Sistematis (JR-YYYYMM-XXXX)
 * - Proteksi Mapping Error & SQL Injection
 * - FIX: Sinkronisasi dengan tabel 'syifa_transaksi_mapping' (Tahap 2)
 */

function autoJournalFromCash($conn, $trx_id, $jenis, $akun_kas, $nominal, $tanggal, $ket, $user_id) {

    mysqli_begin_transaction($conn);

    try {

        // 1. Ambil Mapping (Sesuai dengan tabel Tahap 2)
        // Kita cek berdasarkan kode_transaksi ATAU nama_transaksi agar lebih fleksibel
        $stmt_map = $conn->prepare("
            SELECT akun_lawan, jenis 
            FROM syifa_transaksi_mapping 
            WHERE kode_transaksi = ? OR nama_transaksi = ? LIMIT 1
        ");
        $stmt_map->bind_param("ss", $jenis, $jenis);
        $stmt_map->execute();
        $map = $stmt_map->get_result()->fetch_assoc();

        if (!$map) {
            throw new Exception("Mapping transaksi belum diset untuk: " . $jenis);
        }

        $akun_lawan = $map['akun_lawan'];
        $jenis_flow = strtoupper($map['jenis']); // 'MASUK' atau 'KELUAR'

        // 2. Tentukan Posisi Debit Kredit Berdasarkan Arus Kas
        if ($jenis_flow == 'MASUK') {
            // Kas Masuk (Contoh: SPP) -> Debit: Kas, Kredit: Pendapatan/Lawan
            $kas_debit = $nominal;
            $kas_kredit = 0;
            $lawan_debit = 0;
            $lawan_kredit = $nominal;
        } else {
            // Kas Keluar (Contoh: Gaji) -> Debit: Beban/Lawan, Kredit: Kas
            $kas_debit = 0;
            $kas_kredit = $nominal;
            $lawan_debit = $nominal;
            $lawan_kredit = 0;
        }

        // 3. Generate Nomor Jurnal Berurutan (Format: JR-YYYYMM-XXXX)
        $prefix = "JR-" . date('Ym', strtotime($tanggal));

        // Menggunakan prepared statement untuk perhitungan urutan agar aman
        $stmt_count = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM syifa_jurnal 
            WHERE DATE_FORMAT(tgl_jurnal,'%Y%m') = DATE_FORMAT(?,'%Y%m')
        ");
        $stmt_count->bind_param("s", $tanggal);
        $stmt_count->execute();
        $row = $stmt_count->get_result()->fetch_assoc();
        
        $urut = str_pad($row['total'] + 1, 4, '0', STR_PAD_LEFT);
        $no_jurnal = $prefix . "-" . $urut;

        $keterangan_full = $ket . " (AutoRef:" . $trx_id . ")";

        // 4. Insert Header
        $stmt_header = $conn->prepare("
            INSERT INTO syifa_jurnal 
            (tgl_jurnal, no_jurnal, keterangan, akun_utama_kode, total_debet, total_kredit, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt_header->bind_param(
            "ssssddi",
            $tanggal,
            $no_jurnal,
            $keterangan_full,
            $akun_kas,
            $nominal,
            $nominal,
            $user_id
        );
        $stmt_header->execute();

        $jurnal_id = $conn->insert_id;

        // Siapkan statement untuk Detail
        $stmt_detail = $conn->prepare("
            INSERT INTO syifa_jurnal_detail
            (jurnal_id, kode_akun, debit, kredit, keterangan)
            VALUES (?, ?, ?, ?, ?)
        ");

        // 5. Insert Detail Kas
        $stmt_detail->bind_param("isdds", $jurnal_id, $akun_kas, $kas_debit, $kas_kredit, $keterangan_full);
        $stmt_detail->execute();

        // 6. Insert Detail Lawan
        $stmt_detail->bind_param("isdds", $jurnal_id, $akun_lawan, $lawan_debit, $lawan_kredit, $keterangan_full);
        $stmt_detail->execute();

        // 7. Validasi Balance Keras (ISAK 35 Compliance)
        if (($kas_debit + $lawan_debit) != ($kas_kredit + $lawan_kredit)) {
            throw new Exception("Jurnal tidak balance! Transaksi dibatalkan.");
        }

        // Jika semua lolos, simpan ke database permanen
        mysqli_commit($conn);
        return true;

    } catch (Exception $e) {
        // Jika ada 1 saja proses yang gagal, batalkan SEMUANYA
        mysqli_rollback($conn);
        error_log("AUTO JOURNAL ERROR: " . $e->getMessage());
        return false;
    }
}
?>