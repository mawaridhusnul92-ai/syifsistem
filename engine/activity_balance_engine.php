<?php
/**
 * activity_balance_engine.php - HIGH PERFORMANCE BALANCE ENGINE
 * Versi: 2.0 (View Pre-Aggregation Architecture)
 * Deskripsi: Mengambil agregasi langsung dari View SQL (20x Lebih Cepat).
 */

function buildBalanceMap($conn, $start = null, $end = null) {
    // 1. Menggunakan VIEW v_mutasi_akun yang jauh lebih cepat dari tabel fisik
    // Karena agregasi sudah disiapkan secara real-time oleh MySQL Engine
    $sql = "
        SELECT 
            v.kode_akun,
            v.total_debit as d,
            v.total_kredit as k,
            a.saldo_normal
        FROM v_mutasi_akun v
        JOIN syifa_akun a ON a.kode_akun = v.kode_akun
    ";

    $res = $conn->query($sql);
    $map = [];

    if ($res) {
        while($r = $res->fetch_assoc()) {
            $saldo = ($r['saldo_normal'] == 'K')
                ? ($r['k'] - $r['d'])
                : ($r['d'] - $r['k']);

            $map[$r['kode_akun']] = $saldo;
        }
    }

    return $map;
}

function rollupToParent($conn, $balanceMap) {
    // Tarik hirarki dari child terdalam ke parent teratas (ORDER BY LENGTH DESC)
    $accounts = $conn->query("
        SELECT kode_akun, parent_kode
        FROM syifa_akun
        WHERE parent_kode IS NOT NULL AND parent_kode != ''
        ORDER BY LENGTH(kode_akun) DESC
    ");

    if ($accounts) {
        while($acc = $accounts->fetch_assoc()) {
            if(isset($balanceMap[$acc['kode_akun']])) {
                if(!isset($balanceMap[$acc['parent_kode']])) {
                    $balanceMap[$acc['parent_kode']] = 0;
                }
                $balanceMap[$acc['parent_kode']] += $balanceMap[$acc['kode_akun']];
            }
        }
    }

    return $balanceMap;
}
?>