<?php
/**
 * AssetMigrationEngine.php - STRICT OPENING BALANCE MIGRATOR
 * Versi: 5.1 (The Diagnostic Scanner Edition)
 * Perbaikan:
 * - Mengubah pesan Error menjadi Papan Diagnostik. Sistem akan memberitahu 
 * secara presisi akun mana saja yang menyebabkan Neraca Saldo tidak seimbang.
 */

class AssetMigrationEngine {
    public static function generateAssetMigrationJournal($conn, $cutoff_date) {
        
        $stmt_lock = $conn->prepare("SELECT status FROM syifa_periode_laporan WHERE tgl_mulai <= ? AND tgl_akhir >= ? LIMIT 1");
        $stmt_lock->bind_param("ss", $cutoff_date, $cutoff_date);
        $stmt_lock->execute();
        $lock_res = $stmt_lock->get_result()->fetch_assoc();
        
        if ($lock_res && strtoupper($lock_res['status']) === 'DITUTUP') {
            throw new Exception("INTEGRITAS DITOLAK: Periode laporan pada tanggal cut-off ($cutoff_date) sudah DITUTUP. Jurnal migrasi tidak dapat diterbitkan.");
        }

        $conn->begin_transaction();
        try {
            // ===========================================================================
            // 1. THE SURGICAL PURGE (Hapus semua jejak Jurnal Migrasi Lama)
            // ===========================================================================
            $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id IN (SELECT id FROM syifa_jurnal WHERE jenis_jurnal = 'migrasi_aset' OR no_jurnal LIKE 'SA-AST-%')");
            $conn->query("DELETE FROM syifa_jurnal WHERE jenis_jurnal = 'migrasi_aset' OR no_jurnal LIKE 'SA-AST-%'");

            // ===========================================================================
            // 2. TRANSPARENT AUDIT GUARD DENGAN DIAGNOSTIC SCANNER
            // ===========================================================================
            $q_ob = $conn->query("
                SELECT 
                    SUM(CASE WHEN a.saldo_normal = 'D' THEN a.opening_balance ELSE 0 END) + COALESCE((SELECT SUM(debit) FROM syifa_jurnal_detail), 0) AS total_debit,
                    SUM(CASE WHEN a.saldo_normal = 'K' THEN a.opening_balance ELSE 0 END) + COALESCE((SELECT SUM(kredit) FROM syifa_jurnal_detail), 0) AS total_kredit
                FROM syifa_akun a WHERE a.is_group = 0
            ");
            
            if ($q_ob) {
                $ob_data = $q_ob->fetch_assoc();
                $diff = (double)$ob_data['total_debit'] - (double)$ob_data['total_kredit'];
                
                if (round(abs($diff), 2) > 0) {
                    $teks_selisih = number_format(abs($diff), 0, ',', '.');
                    
                    // SCANNER: Kumpulkan rincian akun yang memiliki Saldo Awal manual
                    $rincian_html = "<div style='text-align:left; background:#fff; padding:15px; border-radius:10px; border:1px solid #ccc; margin-top:15px; font-size:13px; color:#333;'>";
                    $rincian_html .= "<b style='color:#0d6efd;'>DIAGNOSTIK SALDO AWAL MANUAL ANDA SAAT INI:</b><ul style='margin-bottom:0;'>";
                    
                    $q_detail = $conn->query("SELECT kode_akun, nama_akun, saldo_normal, opening_balance FROM syifa_akun WHERE opening_balance > 0 AND is_group = 0 ORDER BY kode_akun ASC");
                    $tot_d = 0; $tot_k = 0;
                    if ($q_detail) {
                        while($rd = $q_detail->fetch_assoc()) {
                            $sn = $rd['saldo_normal'];
                            $val = $rd['opening_balance'];
                            if ($sn == 'D') $tot_d += $val; else $tot_k += $val;
                            $rincian_html .= "<li>[{$rd['kode_akun']}] {$rd['nama_akun']} - <b style='color:".($sn=='D'?'#10b981':'#ef4444')."'>".($sn=='D'?'DEBIT':'KREDIT')." Rp ".number_format($val, 0, ',', '.')."</b></li>";
                        }
                    }
                    $rincian_html .= "</ul><hr style='margin:10px 0;'>";
                    $rincian_html .= "<b>Total DEBIT: Rp ".number_format($tot_d, 0, ',', '.')."</b> <br> <b>Total KREDIT: Rp ".number_format($tot_k, 0, ',', '.')."</b>";
                    $rincian_html .= "</div>";

                    $conn->rollback(); // Batalkan proses
                    
                    if ($diff > 0) {
                        throw new Exception("AUDIT SALDO AWAL GAGAL: Terdapat kelebihan DEBIT sebesar Rp $teks_selisih. Untuk menyeimbangkannya, Anda harus menambahkan nilai KREDIT (Misal: ke Modal/Aset Neto) sebesar Rp $teks_selisih di menu Bagan Akun. $rincian_html");
                    } else {
                        throw new Exception("AUDIT SALDO AWAL GAGAL: Terdapat kelebihan KREDIT sebesar Rp $teks_selisih. Untuk menyeimbangkannya, perbaiki rincian berikut di menu Bagan Akun. $rincian_html");
                    }
                }
            }

            // ===========================================================================
            // 3. ABSOLUTE STRICT MIGRATION (Hanya Aset Masa Lalu / Saldo Awal)
            // ===========================================================================
            $tahun_cutoff = date('Y', strtotime($cutoff_date));
            $awal_tahun_ini = "$tahun_cutoff-01-01";

            $sql = "
                SELECT 
                    a.id, 
                    a.asset_name, 
                    a.purchase_date, 
                    a.purchase_value, 
                    COALESCE(a.residual_value, 0) as residual_value,
                    ac.asset_type,
                    ac.coa_asset_code,
                    ac.coa_depr_code,
                    COALESCE(ai.total_capex, 0) AS improvements,
                    COALESCE(ad.total_depr, 0) AS depreciation
                FROM assets a
                LEFT JOIN asset_categories ac ON a.category_id = ac.id
                LEFT JOIN (
                    SELECT asset_id, SUM(nilai_penambahan) as total_capex
                    FROM asset_improvements WHERE tanggal <= ? AND (journal_id IS NULL OR journal_id = 0) GROUP BY asset_id
                ) ai ON ai.asset_id = a.id
                LEFT JOIN (
                    SELECT asset_id, SUM(nilai_susut) as total_depr
                    FROM asset_depreciation 
                    WHERE STR_TO_DATE(CONCAT(periode_tahun, '-', LPAD(periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') <= ? 
                    AND (jurnal_id IS NULL OR jurnal_id = 0)
                    GROUP BY asset_id
                ) ad ON ad.asset_id = a.id
                WHERE a.status = 'Aktif' 
                AND a.purchase_date <= ?
                AND (a.purchase_mode = 'saldo_awal' OR a.purchase_mode IS NULL OR a.purchase_mode = '' OR a.purchase_date < '$awal_tahun_ini')
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sss", $cutoff_date, $cutoff_date, $cutoff_date);
            $stmt->execute();
            $res = $stmt->get_result();

            $q_eq = $conn->query("SELECT kode_akun FROM syifa_akun WHERE kategori IN ('Aset Neto', 'Ekuitas') AND is_group=0 LIMIT 1");
            $coa_eq = ($q_eq && $q_eq->num_rows > 0) ? $q_eq->fetch_assoc()['kode_akun'] : '3-3101';

            while ($a = $res->fetch_assoc()) {
                $type = strtolower($a['asset_type'] ?? 'tangible');
                $def_aset = ($type == 'intangible') ? '1-2201' : '1-2101';
                $def_akum = ($type == 'intangible') ? '1-2299' : '1-2199';

                $coa_aset = !empty($a['coa_asset_code']) ? explode(' ', trim($a['coa_asset_code']))[0] : $def_aset;
                $coa_akum = !empty($a['coa_depr_code']) ? explode(' ', trim($a['coa_depr_code']))[0] : $def_akum;
                
                $cost = (double)$a['purchase_value'] + (double)$a['improvements'];
                $dep = (double)$a['residual_value'] + (double)$a['depreciation'];
                $net = $cost - $dep;
                
                if ($net < 0) { $dep = $cost; $net = 0; }
                if ($cost <= 0) continue;

                $no_j_unique = "SA-AST-" . $a['id'] . "-" . rand(100,999);
                $ket = "Migrasi Subledger Aset: " . $a['asset_name'];
                
                $sql_insert_hdr = "
                    INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, keterangan, total_debet, total_kredit, created_by, jenis_jurnal, source_module, source_id, is_migration) 
                    VALUES (?, ?, ?, ?, ?, 1, 'migrasi_aset', 'asset', ?, 1)
                ";
                $stmt_hdr = $conn->prepare($sql_insert_hdr);
                $stmt_hdr->bind_param("sssddi", $no_j_unique, $a['purchase_date'], $ket, $cost, $cost, $a['id']);
                $stmt_hdr->execute();
                
                $jid = $conn->insert_id;

                $stmt_jd = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, aset_id) VALUES (?, ?, ?, ?, ?)");
                $z = 0;
                
                $stmt_jd->bind_param("isddi", $jid, $coa_aset, $cost, $z, $a['id']); $stmt_jd->execute();
                if ($dep > 0) { $stmt_jd->bind_param("isddi", $jid, $coa_akum, $z, $dep, $a['id']); $stmt_jd->execute(); }
                if ($net > 0) { $stmt_jd->bind_param("isddi", $jid, $coa_eq, $z, $net, $a['id']); $stmt_jd->execute(); }
            }
            
            $conn->commit();
            return true;
            
        } catch (Exception $e) {
            if(isset($conn) && $conn->connect_errno == 0) $conn->rollback();
            throw $e;
        }
    }
}
?>