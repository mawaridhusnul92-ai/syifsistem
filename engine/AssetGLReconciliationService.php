<?php
/**
 * AssetGLReconciliationService.php - SUBLEDGER VS GL RECONCILIATION
 * Memastikan nilai buku modul aset (Subledger) 100% sama dengan GL Aset Tetap.
 */
class AssetGLReconciliationService {
    public static function reconcile($conn, $date, $prefix_cache) {
        // 1. Ambil NBV dari Modul Aset (Subledger)
        $sql_mod = "SELECT SUM((a.purchase_value + COALESCE(imp.tot, 0)) - (COALESCE(a.residual_value, 0) + COALESCE(dep.tot, 0))) as nbv
                    FROM assets a
                    LEFT JOIN (SELECT asset_id, SUM(nilai_penambahan) as tot FROM asset_improvements WHERE tanggal <= '$date') imp ON imp.asset_id = a.id
                    LEFT JOIN (SELECT asset_id, SUM(nilai_susut) as tot FROM asset_depreciation WHERE STR_TO_DATE(CONCAT(periode_tahun, '-', LPAD(periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') <= '$date') dep ON dep.asset_id = a.id
                    WHERE a.status = 'Aktif' AND a.purchase_date <= '$date'";
        $res = $conn->query($sql_mod);
        $nbv_module = $res ? (double)($res->fetch_assoc()['nbv'] ?? 0) : 0;

        // 2. Ambil NBV dari General Ledger (Sudah terkoreksi tanda Kredit/Debit)
        // Aset Tetap = Prefix '1-2'
        $nbv_gl = (double)($prefix_cache['1-2'] ?? 0);

        $diff = abs($nbv_module - $nbv_gl);
        return [
            'is_match' => $diff <= 1,
            'diff' => $diff,
            'module_val' => $nbv_module,
            'gl_val' => $nbv_gl
        ];
    }
}
?>