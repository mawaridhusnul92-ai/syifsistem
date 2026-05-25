<?php
/**
 * AccountTreeCache.php - PURE O(1) DELIMITER PREFIX ENGINE
 * Versi: 210.0 (Sovereign Grand Master - Flawless Hierarchy)
 * Perbaikan:
 * 1. Menggunakan explode('-') untuk mencegah 'Undefined array key' & double count.
 * 2. Mengintegrasikan logika Contra-Account secara langsung agar Akumulasi Penyusutan (Kredit) otomatis mengurangi Induk Asetnya.
 */
class AccountTreeCache {
    public static function buildDelimiterPrefixCache($all_accounts, $map_data, $idx) {
        $prefix_cache = [];
        
        foreach ($all_accounts as $acc) {
            // HANYA memproses leaf node (is_group = 0) untuk di-roll up ke atas
            if ((int)$acc['is_group'] === 0) {
                $kode = trim($acc['kode_akun']);
                $saldo_raw = (double)($map_data[$kode]['saldo_'.$idx] ?? 0);
                
                if (abs($saldo_raw) > 0.001) {
                    // KOREKSI TANDA UNTUK AGREGASI PARENT
                    $impact = $saldo_raw;
                    $is_contra = false;

                    // Deteksi Contra Account
                    if ($acc['kategori'] == 'Aset' && $acc['saldo_normal'] == 'K') $is_contra = true; // Akumulasi Penyusutan
                    if (in_array($acc['kategori'], ['Liabilitas', 'Aset Neto', 'Pendapatan']) && $acc['saldo_normal'] == 'D') $is_contra = true;
                    if ($acc['kategori'] == 'Beban' && $acc['saldo_normal'] == 'K') $is_contra = true;

                    if ($is_contra) {
                        $impact = -$saldo_raw;
                    }

                    // 1. Simpan Saldo Asli (Raw) ke kode persisnya
                    if (!isset($prefix_cache[$kode])) $prefix_cache[$kode] = 0;
                    $prefix_cache[$kode] += $saldo_raw; // Tampilan detail tetap pakai saldo asli

                    // 2. Roll Up ke Parent menggunakan Delimiter '-'
                    $parts = explode('-', $kode);
                    $curr_prefix = '';
                    
                    // Bangun struktur hirarki (Contoh: 1, 1-2, 1-21)
                    foreach ($parts as $i => $part) {
                        $curr_prefix .= ($i === 0) ? $part : '-' . $part;
                        
                        // Jangan tambahkan impact ke node daun (karena sudah pakai saldo raw di atas)
                        if ($curr_prefix !== $kode) {
                            if (!isset($prefix_cache[$curr_prefix])) {
                                $prefix_cache[$curr_prefix] = 0;
                            }
                            $prefix_cache[$curr_prefix] += $impact;
                        }
                    }
                }
            }
        }
        return $prefix_cache;
    }

    public static function getFastBalance($prefix, $prefix_cache) {
        return (double)($prefix_cache[$prefix] ?? 0);
    }
}
?>