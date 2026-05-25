<?php
/**
 * coa_auto_classifier.php - SOVEREIGN COA SELF-HEAL ENGINE (V2)
 * Fungsi: Mengklasifikasikan akun secara otomatis dengan kecerdasan 
 * membedakan mana akun transaksional dan mana akun pembangun (Builder).
 */

if (!function_exists('autoClassifyCOA')) {
    function autoClassifyCOA($conn) {
        // PERUBAHAN STRATEGIS:
        // Kita hanya mengklasifikasikan akun yang:
        // 1. Belum punya tipe laporan (akun baru).
        // 2. Adalah akun transaksional (is_group = 0).
        // 3. TIDAK di-set ke 'NONE' (karena 'NONE' adalah keputusan arsitek).
        
        $sql = "SELECT kode_akun, kategori 
                FROM syifa_akun 
                WHERE akun_tipe_laporan IS NULL 
                AND is_group = 0 
                AND (report_group IS NOT NULL AND report_group != 'NONE')";
        
        $res = $conn->query($sql);

        if ($res && $res->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE syifa_akun SET akun_tipe_laporan = ? WHERE kode_akun = ?");
            
            while($r = $res->fetch_assoc()){
                // Logika mapping:
                // Aset, Liabilitas, Aset Neto -> NERACA
                // Pendapatan, Beban          -> OPERASIONAL
                $tipe = in_array($r['kategori'], ['Pendapatan', 'Beban']) ? 'OPERASIONAL' : 'NERACA';

                $stmt->bind_param("ss", $tipe, $r['kode_akun']);
                $stmt->execute();
            }
        }
    }
}
?>