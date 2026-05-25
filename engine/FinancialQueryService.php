<?php
/**
 * FinancialQueryService.php - ISOLATION QUERY LAYER
 * Menarik logika SQL keluar dari antarmuka (UI) untuk mencegah spageti code.
 */
class FinancialQueryService {

    public static function getSystemProfile($conn) {
        return $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
    }

    public static function getReportHistory($conn) {
        return $conn->query("
            SELECT s.*, u.nama_lengkap as creator
            FROM laporan_keuangan_setting s
            LEFT JOIN users u ON s.created_by=u.id
            WHERE s.jenis_laporan='neraca'
            ORDER BY s.created_at DESC
        ");
    }

    public static function getAllAccounts($conn) {
        return $conn->query("SELECT * FROM syifa_akun ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);
    }

    // ?? FUNGSI INI DIPINDAHKAN DARI UI AGAR TERHINDAR DARI UNDEFINED ERROR
    public static function getSafeBalanceFlat($prefix, $all_accounts, $map_data, $idx) {
        $total = 0;
        foreach ($all_accounts as $acc) {
            if ((int)$acc['is_group'] === 0 && strpos($acc['kode_akun'], $prefix) === 0) {
                $total += (double)($map_data[$acc['kode_akun']]['saldo_'.$idx] ?? 0);
            }
        }
        return $total;
    }
}
?>