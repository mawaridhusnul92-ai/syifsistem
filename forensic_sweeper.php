<?php
/**
 * forensic_sweeper.php - THE SHADOW DATA ANNIHILATOR
 * Skrip ini akan menghapus semua sampah data (Orphan) dan Jurnal Pincang (Unbalanced)
 * yang tertinggal di dalam database akibat bug migrasi di masa lalu.
 */
require_once 'config/koneksi.php';

echo "<div style='font-family: sans-serif; padding: 40px; background-color: #f1f5f9; min-height: 100vh;'>";
echo "<div style='max-width: 800px; margin: auto; background: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);'>";
echo "<h2 style='color:#ef4444; border-bottom: 2px solid #ef4444; padding-bottom: 10px;'><i class='fas fa-biohazard'></i> Forensic Sweeper: The Data Annihilator</h2>";

$conn->begin_transaction();
try {
    // 1. MENGHAPUS SHADOW DATA (DETAIL TANPA INDUK)
    $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id NOT IN (SELECT id FROM syifa_jurnal)");
    $affected_orphan = $conn->affected_rows;
    echo "<p style='color:#10b981; font-size:16px;'>?? <b>Shadow Data Cleared:</b> Berhasil menghapus <b>$affected_orphan</b> baris detail jurnal sampah (Orphan).</p>";

    // 2. MENDETEKSI & MENGHAPUS JURNAL PINCANG (YANG DEBIT != KREDIT)
    // Seringkali ini adalah jurnal migrasi masa lalu yang kaki kreditnya terhapus sebagian
    $q_pincang = $conn->query("SELECT jd.jurnal_id FROM syifa_jurnal_detail jd GROUP BY jd.jurnal_id HAVING ABS(SUM(jd.debit) - SUM(jd.kredit)) > 0.01");
    $affected_pincang = 0;
    
    if ($q_pincang && $q_pincang->num_rows > 0) {
        while($p = $q_pincang->fetch_assoc()) {
            $jid = $p['jurnal_id'];
            $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $jid");
            $conn->query("DELETE FROM syifa_jurnal WHERE id = $jid");
            $affected_pincang++;
        }
    }
    echo "<p style='color:#10b981; font-size:16px;'>?? <b>Unbalanced Journals Annihilated:</b> Berhasil menghapus <b>$affected_pincang</b> jurnal pincang yang merusak Trial Balance.</p>";

    $conn->commit();
    echo "<div style='background:#dcfce7; color:#065f46; padding:20px; border-radius:10px; margin-top:30px;'>";
    echo "<h3 style='margin:0 0 10px 0;'>? OPERASI PEMBERSIHAN SELESAI!</h3>";
    echo "Database Anda kini bersih dari Shadow Data. Silakan kembali ke Laporan Neraca Anda.";
    echo "</div>";

} catch (Exception $e) {
    $conn->rollback();
    echo "<h3 style='color:#dc3545;'>? GAGAL: " . $e->getMessage() . "</h3>";
}

echo "</div></div>";
?>