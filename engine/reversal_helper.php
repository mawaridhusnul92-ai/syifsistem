<?php
/**
 * reversal_helper.php - REVERSAL ENGINE
 * Deskripsi: Membuat Jurnal Pembalik otomatis (Voiding) tanpa menghapus fisik data lama.
 */

require_once __DIR__ . '/auto_journal_helper.php';

function createReversal($conn, $jurnal_id, $uid) {
    $jurnal_id = (int)$jurnal_id;
    $jurnal = $conn->query("SELECT * FROM syifa_jurnal WHERE id=$jurnal_id")->fetch_assoc();
    
    if(!$jurnal) throw new Exception("Reversal Error: Jurnal tidak ditemukan.");

    $details = $conn->query("SELECT * FROM syifa_jurnal_detail WHERE jurnal_id=$jurnal_id");
    $reverse = [];

    while($d = $details->fetch_assoc()){
        // Tukar posisi Debit dan Kredit mutlak
        $reverse[] = [
            'kode_akun' => $d['kode_akun'],
            'debit'     => $d['kredit'],
            'kredit'    => $d['debit']
        ];
    }

    // Tembak ke Universal Engine
    return createAutoJournal(
        $conn,
        date('Y-m-d'),
        'REVERSAL ' . $jurnal['keterangan'],
        $reverse,
        $uid,
        $jurnal['akun_utama_kode'],
        'SYSTEM',
        'auto_jurnal',
        $jurnal_id,    // source_ref
        'REVERSAL'     // module_source
    );
}
?>