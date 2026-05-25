<?php
/**
 * autonum_engine.php - CORE AUTONOMOUS NUMBERING SYSTEM
 * Versi: 2.0 (Grand Master Edition)
 * Deskripsi: Satu-satunya sumber kebenaran untuk penomoran dokumen ERP.
 */

if (!function_exists('getNextNumber')) {
    /**
     * getNextNumber - Menghasilkan nomor dokumen berdasarkan konfigurasi database
     * @param mysqli $conn Koneksi database
     * @param string $module_key Key modul dari tabel sys_auto_number
     * @return string Nomor dokumen yang sudah terformat
     */
    function getNextNumber($conn, $module_key) {
        // 1. Ambil Konfigurasi dan Lock baris untuk mencegah double number (Concurrency Safety)
        $sql = "SELECT * FROM sys_auto_number WHERE module_key = '$module_key' AND is_active = 1 FOR UPDATE";
        $res = $conn->query($sql);
        $conf = $res->fetch_assoc();

        if (!$conf) {
            // Fallback jika konfigurasi belum ada di database
            return strtoupper(substr($module_key, 0, 3)) . "-" . date('YmdHis');
        }

        $now = date('Y-m-d');
        $current_month = date('m');
        $current_year = date('Y');
        
        $last_reset_date = $conf['last_reset_date'];
        $last_month = $last_reset_date ? date('m', strtotime($last_reset_date)) : '';
        $last_year = $last_reset_date ? date('Y', strtotime($last_reset_date)) : '';

        $new_sequence = (int)$conf['last_number'] + 1;

        // 2. Logika Reset Otomatis (Monthly / Yearly / Never)
        if ($conf['reset_type'] == 'Monthly' && $current_month != $last_month) {
            $new_sequence = 1;
        } elseif ($conf['reset_type'] == 'Yearly' && $current_year != $last_year) {
            $new_sequence = 1;
        }

        // 3. Update Status ke Database
        $update_sql = "UPDATE sys_auto_number 
                       SET last_number = $new_sequence, last_reset_date = '$now' 
                       WHERE id = {$conf['id']}";
        $conn->query($update_sql);

        // 4. Konstruksi String Nomor Dokumen
        // Format Builder: {PREFIX}/{YEAR}/{MONTH}/{SEQ}
        $seq_padded = str_pad($new_sequence, $conf['seq_length'], '0', STR_PAD_LEFT);
        
        $output = $conf['format'];
        $output = str_replace('{PREFIX}', $conf['prefix'], $output);
        $output = str_replace('{YEAR}', $current_year, $output);
        $output = str_replace('{MONTH}', $current_month, $output);
        $output = str_replace('{SEQ}', $seq_padded, $output);

        return $output;
    }
}