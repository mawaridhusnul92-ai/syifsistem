<?php
/**
 * FinancialSnapshotEngine.php - FINANCIAL PERIOD ORCHESTRATOR
 * Versi: 300.0 (Enterprise Automation Control)
 * Fungsi: Mengendalikan siklus pembuatan Trial Balance Cache dan Equity Rollforward.
 */
require_once __DIR__ . '/TrialBalanceCacheEngine.php';

class FinancialSnapshotEngine {
    
    public static function generateSnapshot($conn, $tahun, $bulan, $custom_date_cutoff = null) {
        try {
            // Tentukan tanggal akhir bulan secara absolut
            if (!$custom_date_cutoff) {
                $custom_date_cutoff = date('Y-m-t', strtotime("$tahun-" . sprintf("%02d", $bulan) . "-01"));
            }
            
            // 1. Eksekusi Pembentukan Cache Trial Balance
            // Proses ini akan mengeksekusi LedgerAggregation dan menyimpannya sebagai cache versioned.
            // Jika jurnal tidak balance, mesin akan langsung melempar Exception di sini.
            TrialBalanceCacheEngine::createCache($conn, $tahun, $bulan, $custom_date_cutoff);

            // 2. Eksekusi Pembentukan Ekuitas (Rollforward) jika ada
            if (file_exists(__DIR__ . '/EquityRollforwardGenerator.php')) {
                require_once __DIR__ . '/EquityRollforwardGenerator.php';
                if (class_exists('EquityRollforwardGenerator')) {
                    EquityRollforwardGenerator::generate($conn, $tahun, $bulan);
                }
            }
            
            return true;
        } catch (Exception $e) {
            throw new Exception("Snapshot Engine Error: " . $e->getMessage());
        }
    }
}
?>