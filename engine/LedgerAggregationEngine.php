<?php
/**
 * LedgerAggregationEngine.php - FINANCIAL REPORT MAPPING ENGINE
 * Versi: 12.0 (The Absolute Standardizer Edition)
 * Perbaikan:
 * Mengubah taktik pembersihan dari TRIM() menjadi Whitelist Exclusion (NOT IN).
 * Semua karakter gaib (Ghost Characters) dijamin rata tanah dan distandarisasi 
 * secara mutlak tanpa celah sedikitpun!
 */

require_once 'TrialBalanceCacheEngine.php';

class LedgerAggregationEngine {
    
    const NERACA_TOLERANCE = 100;

    private static $valid_report_groups = [
        'cash', 'receivable', 'prepaid', 'inventory', 'asset_other',
        'fixed_asset_cost', 'fixed_asset_accum', 
        'intangible_asset_cost', 'intangible_asset_accum',
        'liability_short', 'liability_long', 'liability_other',
        'equity_unrestricted', 'equity_restricted',
        'revenue', 'expense', 'retained_earnings', 'current_year_earnings'
    ];

    // =========================================================================
    // ?? THE ABSOLUTE HEALER (Penyapu Karakter Gaib Tanpa Ampun)
    // =========================================================================
    public static function autoHealDatabase($conn) {
        $valid_list = "'" . implode("','", self::$valid_report_groups) . "'";
        
        // KONDISI MUTLAK: Jika BUKAN kelompok yang sah, maka itu adalah Sampah!
        $cond = "report_group IS NULL OR report_group NOT IN ($valid_list)";
        
        $queries = [
            "UPDATE syifa_akun SET report_group = 'cash' WHERE kode_akun LIKE '1-11%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'receivable' WHERE kode_akun LIKE '1-12%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'prepaid' WHERE kode_akun LIKE '1-13%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'inventory' WHERE kode_akun LIKE '1-14%' AND ($cond)",
            
            "UPDATE syifa_akun SET report_group = 'fixed_asset_accum' WHERE (kode_akun LIKE '1-21%99' OR nama_akun LIKE '%Akumulasi%') AND kode_akun LIKE '1-21%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'fixed_asset_cost' WHERE kode_akun LIKE '1-21%' AND report_group != 'fixed_asset_accum' AND ($cond)",
            
            "UPDATE syifa_akun SET report_group = 'intangible_asset_accum' WHERE (kode_akun LIKE '1-22%99' OR nama_akun LIKE '%Amortisasi%') AND kode_akun LIKE '1-22%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'intangible_asset_cost' WHERE kode_akun LIKE '1-22%' AND report_group != 'intangible_asset_accum' AND ($cond)",
            
            "UPDATE syifa_akun SET report_group = 'asset_other' WHERE kode_akun LIKE '1-%' AND report_group NOT IN ('cash','receivable','prepaid','inventory','fixed_asset_accum','fixed_asset_cost','intangible_asset_accum','intangible_asset_cost') AND ($cond)",
            
            "UPDATE syifa_akun SET report_group = 'liability_short' WHERE kode_akun LIKE '2-1%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'liability_long' WHERE kode_akun LIKE '2-2%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'liability_other' WHERE kode_akun LIKE '2-%' AND report_group NOT IN ('liability_short','liability_long') AND ($cond)",
            
            "UPDATE syifa_akun SET report_group = 'equity_restricted' WHERE kode_akun LIKE '3-2%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'equity_unrestricted' WHERE kode_akun LIKE '3-%' AND report_group != 'equity_restricted' AND ($cond)",
            
            "UPDATE syifa_akun SET report_group = 'revenue' WHERE kode_akun LIKE '4-%' AND ($cond)",
            "UPDATE syifa_akun SET report_group = 'expense' WHERE kode_akun REGEXP '^[56789]-' AND ($cond)"
        ];

        foreach ($queries as $sql) { $conn->query($sql); }
    }

    public static function getNeracaData($conn, $cut_off) {
        // Eksekusi Penyapu Jagat sebelum data ditarik
        self::autoHealDatabase($conn);

        $tb = TrialBalanceCacheEngine::getBalances($conn, $cut_off);

        $rd = [
            'kas' => 0, 'piutang' => 0, 'dimuka' => 0, 'persediaan' => 0, 'aset_lancar_lain' => 0,
            'aset_tetap_berwujud_cost' => 0, 'aset_tetap_berwujud_akum' => 0,
            'aset_tetap_tak_berwujud_cost' => 0, 'aset_tetap_tak_berwujud_akum' => 0,
            'liab_pendek' => 0, 'liab_panjang' => 0, 'liab_lain' => 0,
            'eq_unrest' => 0, 'ekuitas_restricted' => 0,
            'total_aset' => 0, 'total_liab' => 0
        ];

        if (empty($tb)) return $rd;

        $pendapatan = 0; $beban = 0; $laba_tahun_berjalan_exist = false; 

        foreach ($tb as $r) {
            $val = (double)$r['signed_balance']; 
            $rg = trim(strtolower($r['report_group'] ?? ''));

            if (round(abs($val), 2) > 0) {
                if (empty($rg) || !in_array($rg, self::$valid_report_groups)) {
                    throw new Exception("STRICT MAPPING ERROR: Akun [{$r['kode_akun']}] {$r['nama_akun']} memiliki saldo tetapi report_group tidak valid. Silakan klik tombol 'Reload Pure GL'.");
                }
            }

            if (!in_array($rg, self::$valid_report_groups)) continue;

            switch($rg) {
                case 'cash': $rd['kas'] += $val; break;
                case 'receivable': $rd['piutang'] += $val; break;
                case 'prepaid': $rd['dimuka'] += $val; break;
                case 'inventory': $rd['persediaan'] += $val; break;
                case 'asset_other': $rd['aset_lancar_lain'] += $val; break;
                case 'fixed_asset_cost': $rd['aset_tetap_berwujud_cost'] += $val; break;
                case 'fixed_asset_accum': $rd['aset_tetap_berwujud_akum'] += $val; break;
                case 'intangible_asset_cost': $rd['aset_tetap_tak_berwujud_cost'] += $val; break;
                case 'intangible_asset_accum': $rd['aset_tetap_tak_berwujud_akum'] += $val; break;
                case 'liability_short': $rd['liab_pendek'] += $val; break;
                case 'liability_long': $rd['liab_panjang'] += $val; break;
                case 'liability_other': $rd['liab_lain'] += $val; break;
                case 'equity_unrestricted': $rd['eq_unrest'] += $val; break;
                case 'equity_restricted': $rd['ekuitas_restricted'] += $val; break;
                case 'current_year_earnings':
                case 'retained_earnings': $rd['eq_unrest'] += $val; $laba_tahun_berjalan_exist = true; break;
                case 'revenue': $pendapatan += $val; break;
                case 'expense': $beban += $val; break;
            }

            if (in_array($rg, ['cash', 'receivable', 'prepaid', 'inventory', 'asset_other', 'fixed_asset_cost', 'fixed_asset_accum', 'intangible_asset_cost', 'intangible_asset_accum'])) {
                $rd['total_aset'] += $val;
            }
            if (in_array($rg, ['liability_short', 'liability_long', 'liability_other'])) {
                $rd['total_liab'] += $val;
            }
        } 

        $surplus_defisit = $pendapatan + $beban; 
        if (!$laba_tahun_berjalan_exist) { $rd['eq_unrest'] += $surplus_defisit; }

        $total_pasiva = $rd['total_liab'] + $rd['eq_unrest'] + $rd['ekuitas_restricted'];
        if (abs($rd['total_aset'] + $total_pasiva) > self::NERACA_TOLERANCE) {
            throw new Exception("NERACA TIDAK BALANCE: Total Aset (".number_format($rd['total_aset'],0).") != Total Kewajiban & Ekuitas (".number_format(abs($total_pasiva),0).").");
        }

        $rd['surplus_defisit_ui'] = $surplus_defisit;
        return $rd;
    }
}
?>