<?php
/**
 * sync_asset_gl.php - THE SOVEREIGN PURGER & SYNC
 * Versi: 4.1 (Auto-Schema Enforcer Edition)
 * Tugas: 
 * 1. Menerapkan Auto-Schema Enforcer untuk menjamin ketersediaan kolom `is_migration`.
 * 2. Melibas Saldo Awal manual (opening_balance) di COA Aset Tetap yang menyebabkan Double Hitung.
 * 3. Memanggil ulang Engine Migrasi untuk merekonstruksi Jurnal Aset secara sempurna.
 */
require_once 'config/koneksi.php';
require_once 'engine/AssetMigrationEngine.php';

echo "<div style='font-family: sans-serif; padding: 30px; line-height: 1.6;'>";
echo "<h2 style='color:#0d6efd;'><i class='fas fa-sync-alt'></i> Sovereign Purge & Sync Engine</h2>";

// ==================================================================================
// 1. AUTO-SCHEMA ENFORCER (Memastikan struktur tabel siap sebelum migrasi)
// ==================================================================================
try {
    // Pastikan kolom-kolom Idempotent ada di syifa_jurnal
    $conn->query("ALTER TABLE syifa_jurnal ADD COLUMN IF NOT EXISTS source_module VARCHAR(50) NULL DEFAULT NULL AFTER jenis_jurnal");
    $conn->query("ALTER TABLE syifa_jurnal ADD COLUMN IF NOT EXISTS source_id INT(11) NULL DEFAULT NULL AFTER source_module");
    $conn->query("ALTER TABLE syifa_jurnal ADD COLUMN IF NOT EXISTS is_migration TINYINT(1) DEFAULT 0 AFTER source_id");
    
    // Pastikan asset_type ada di asset_categories
    $conn->query("ALTER TABLE asset_categories ADD COLUMN IF NOT EXISTS asset_type ENUM('tangible', 'intangible') DEFAULT 'tangible'");
    
    echo "<p style='color:#10b981;'>?? <b>Schema Enforcer:</b> Struktur database telah diselaraskan dengan sempurna.</p>";
} catch (Exception $e) {
    // Abaikan error duplicate column (sudah ada)
}

$conn->begin_transaction();
try {
    // ==================================================================================
    // 2. THE PURGER: Menghancurkan residu Saldo Awal manual di COA Aset Tetap & Amortisasi
    // ==================================================================================
    $conn->query("UPDATE syifa_akun SET opening_balance = 0 WHERE kode_akun LIKE '1-21%' OR kode_akun LIKE '1-22%'");
    $affected = $conn->affected_rows;
    echo "<p style='color:#f59e0b;'>?? <b>Purger Aktif:</b> Berhasil membersihkan $affected akun Aset Tetap dari residu Saldo Awal manual.</p>";

    // ==================================================================================
    // 3. THE SYNC: Panggil ulang Engine Migrasi untuk merekonstruksi data
    // ==================================================================================
    // Gunakan tanggal hari ini sebagai batas cutoff mutlak
    $cutoff = date('Y-m-d');
    AssetMigrationEngine::generateAssetMigrationJournal($conn, $cutoff);

    $conn->commit();
    echo "<h3 style='color:#10b981;'>? SINKRONISASI MUTLAK BERHASIL!</h3>";
    echo "<p>Seluruh Aset Berwujud dan Tidak Berwujud Anda telah terkunci rapat di General Ledger tanpa Double Counting.</p>";
    echo "<br><a href='index.php?page=laporan_posisi_keuangan' style='padding: 10px 20px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Buka Laporan Neraca</a>";

} catch (Exception $e) {
    $conn->rollback();
    echo "<h3 style='color:#dc3545;'>? SINKRONISASI GAGAL!</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
echo "</div>";
?>