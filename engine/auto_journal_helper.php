<?php
/**
 * auto_journal_helper.php - UNIVERSAL ACCOUNTING ENGINE
 * Versi: 9.0 (Sovereign Grand Master - Foundation Hardening)
 * Deskripsi: Mesin pusat tata kelola jurnal. Terintegrasi dengan module_source.
 */

// Panggil Firewall COA
require_once __DIR__ . '/../core/coa_validator.php';

/**
 * ==============================================================================
 * 1. CORE ENGINE: PEMROSES JURNAL MULTI-ROW (ARRAY-BASED)
 * ==============================================================================
 */
function createAutoJournal($conn, $tanggal, $keterangan, $details, $user_id, $akun_kas = null, $pihak_nama = 'Umum', $seq_module = 'auto_jurnal', $source_ref = null, $module_source = 'KAS', $existing_id = null) {
    try {
        if (empty($details) || !is_array($details)) {
            throw new Exception("Engine Error: Detail jurnal tidak valid atau kosong.");
        }

        $total_debet = 0;
        $total_kredit = 0;
        
        foreach ($details as $row) {
            $total_debet += (float)$row['debit'];
            $total_kredit += (float)$row['kredit'];
            validateAccountForPosting($conn, $row['kode_akun']);
        }

        if (round($total_debet, 2) !== round($total_kredit, 2)) {
            throw new Exception("Engine Error: Jurnal Array tidak balance (Debit: $total_debet, Kredit: $total_kredit).");
        }

        $jurnal_id = null;

        if ($existing_id) {
            $jurnal_id = $existing_id;
            $q_nj = $conn->query("SELECT no_jurnal FROM syifa_jurnal WHERE id = $jurnal_id")->fetch_assoc();
            $no_jurnal = $q_nj['no_jurnal'] ?? getNextNumber($conn, $seq_module);
            
            $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $jurnal_id");
            
            $stmt_header = $conn->prepare("
                UPDATE syifa_jurnal SET 
                    tgl_jurnal = ?, no_jurnal = ?, pihak_nama = ?, keterangan = ?, akun_utama_kode = ?,
                    total_debet = ?, total_kredit = ?, 
                    created_by = ?, source = 'AUTO', source_ref = ?, module_source = ? 
                WHERE id = ?
            ");
            $stmt_header->bind_param("sssssddissi", $tanggal, $no_jurnal, $pihak_nama, $keterangan, $akun_kas, $total_debet, $total_kredit, $user_id, $source_ref, $module_source, $jurnal_id);
            $stmt_header->execute();
            
        } else {
            $no_jurnal = getNextNumber($conn, $seq_module);
            $stmt_header = $conn->prepare("
                INSERT INTO syifa_jurnal (
                    tgl_jurnal, no_jurnal, pihak_nama, keterangan, akun_utama_kode,
                    total_debet, total_kredit,
                    created_by, created_at,
                    source, source_ref, module_source
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'AUTO', ?, ?)
            ");
            $stmt_header->bind_param("sssssddiss", $tanggal, $no_jurnal, $pihak_nama, $keterangan, $akun_kas, $total_debet, $total_kredit, $user_id, $source_ref, $module_source);
            $stmt_header->execute();
            $jurnal_id = $conn->insert_id;
        }

        $stmt_detail = $conn->prepare("
            INSERT INTO syifa_jurnal_detail
            (jurnal_id, kode_akun, debit, kredit, keterangan, mahasiswa_id, tagihan_id_ref, aset_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($details as $row) {
            $kode = $row['kode_akun'];
            $d = (float)$row['debit'];
            $k = (float)$row['kredit'];
            $ket_row = $row['keterangan'] ?? $keterangan;
            $m_id = !empty($row['mahasiswa_id']) ? (int)$row['mahasiswa_id'] : null;
            $t_ref = !empty($row['tagihan_id_ref']) ? (int)$row['tagihan_id_ref'] : null;
            $a_id = !empty($row['aset_id']) ? (int)$row['aset_id'] : null;

            $stmt_detail->bind_param("isddsiii", $jurnal_id, $kode, $d, $k, $ket_row, $m_id, $t_ref, $a_id);
            $stmt_detail->execute();
        }

        $stmt_val = $conn->prepare("SELECT SUM(debit) as d, SUM(kredit) as k FROM syifa_jurnal_detail WHERE jurnal_id = ?");
        $stmt_val->bind_param("i", $jurnal_id);
        $stmt_val->execute();
        $cek_db = $stmt_val->get_result()->fetch_assoc();

        if (round((float)$cek_db['d'], 2) !== round((float)$cek_db['k'], 2)) {
            throw new Exception("DB Validation Failed: Jurnal cacat. Transaksi dibatalkan secara sistematis.");
        }

        return $jurnal_id;

    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * ==============================================================================
 * 2. TRANSLATOR ENGINE: SINGLE ENTRY TO DOUBLE ENTRY (MAPPING)
 * ==============================================================================
 */
function autoJournalFromCash($conn, $trx_id, $jenis, $akun_kas, $nominal, $tanggal, $ket, $user_id) {
    try {
        $stmt_map = $conn->prepare("SELECT akun_lawan, jenis FROM syifa_transaksi_mapping WHERE kode_transaksi = ? OR nama_transaksi = ? LIMIT 1");
        $stmt_map->bind_param("ss", $jenis, $jenis);
        $stmt_map->execute();
        $map = $stmt_map->get_result()->fetch_assoc();

        if (!$map) throw new Exception("Mapping transaksi belum diset untuk: " . $jenis);

        $akun_lawan = $map['akun_lawan'];
        $jenis_flow = strtoupper($map['jenis']);

        if ($akun_kas === $akun_lawan) throw new Exception("Mapping Error: Akun Kas dan Akun Lawan tidak boleh identik ($akun_kas).");

        if ($jenis_flow == 'MASUK') {
            $details = [
                ['kode_akun' => $akun_kas, 'debit' => $nominal, 'kredit' => 0],
                ['kode_akun' => $akun_lawan, 'debit' => 0, 'kredit' => $nominal]
            ];
        } else {
            $details = [
                ['kode_akun' => $akun_lawan, 'debit' => $nominal, 'kredit' => 0],
                ['kode_akun' => $akun_kas, 'debit' => 0, 'kredit' => $nominal]
            ];
        }

        $akunLawanData = validateAccountForPosting($conn, $akun_lawan);
        $lawan_kredit = ($jenis_flow == 'MASUK') ? $nominal : 0;
        $lawan_debit = ($jenis_flow != 'MASUK') ? $nominal : 0;
        
        if ($akunLawanData['normal_balance'] == 'DEBIT' && $lawan_kredit > 0) throw new Exception("Pelanggaran Normal Balance: Akun [{$akunLawanData['nama_akun']}] bersaldo normal DEBIT, tidak sah menempati posisi KREDIT.");
        if ($akunLawanData['normal_balance'] == 'KREDIT' && $lawan_debit > 0) throw new Exception("Pelanggaran Normal Balance: Akun [{$akunLawanData['nama_akun']}] bersaldo normal KREDIT, tidak sah menempati posisi DEBIT.");

        $keterangan_full = $ket . " (Auto-mapped)";

        // ?? TEMBAK KE UNIVERSAL ARRAY ENGINE DENGAN module_source
        return createAutoJournal(
            $conn, 
            $tanggal, 
            $keterangan_full, 
            $details, 
            $user_id, 
            $akun_kas, 
            'Umum', 
            'auto_jurnal', 
            $trx_id, 
            'KAS' // -> $module_source
        );

    } catch (Exception $e) {
        throw $e;
    }
}
?>