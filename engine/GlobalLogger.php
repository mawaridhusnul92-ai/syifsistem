<?php
/**
 * GlobalLogger.php - OMNI AUDIT TRAIL SENSOR
 * Versi: 2.0 (Enterprise Centralized Logger & Auto-Purge Edition)
 * Sensor ini bertugas mencatat segala aktivitas dari seluruh modul (HR, Aset, Kasir, Jurnal)
 * ke dalam satu tabel pusat (sys_activity_log).
 */
class GlobalLogger {
    public static function log($conn, $uid, $action, $module, $table, $rec_id, $desc, $old = null, $new = null) {
        try {
            // Auto-Healer Tabel Log
            $conn->query("CREATE TABLE IF NOT EXISTS sys_activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action_type VARCHAR(20) NOT NULL, 
                module VARCHAR(100) NOT NULL,
                target_table VARCHAR(100) NOT NULL,
                record_id INT NOT NULL,
                description TEXT,
                old_data LONGTEXT NULL,
                new_data LONGTEXT NULL,
                is_reverted TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");

            // 🚀 RETENTION POLICY 6 BULAN (AUTO-PURGE ENGINE)
            // Otomatis menyapu bersih log yang usianya lewat dari 6 bulan agar database tidak bengkak.
            $conn->query("DELETE FROM sys_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)");

            $stmt = $conn->prepare("INSERT INTO sys_activity_log (user_id, action_type, module, target_table, record_id, description, old_data, new_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $o = $old ? (is_string($old) ? $old : json_encode($old)) : null;
            $n = $new ? (is_string($new) ? $new : json_encode($new)) : null;
            $stmt->bind_param("isssisss", $uid, $action, $module, $table, $rec_id, $desc, $o, $n);
            $stmt->execute();
        } catch (Exception $e) {
            // Fail-safe mutlak: Jangan sampai gagalnya mencatat log merusak transaksi utama Kasir
        }
    }
}