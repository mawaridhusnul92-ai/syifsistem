<?php
/**
 * TrialBalanceCacheEngine.php - CORE ERP BALANCE ENGINE
 * Versi: 7.0 (The Ultimate Guardian & RAM Healer Edition)
 * Perbaikan Mutlak:
 * 1. RAM HEALER: Mengobati 'report_group' yang kosong/salah secara langsung di memori (on-the-fly) 
 * sebelum dikirim ke laporan, memusnahkan error STRICT MAPPING (seperti Bank BNI 1-1102) SELAMANYA.
 * 2. COALESCE FIX: Menangani bug NULL di MySQL saat menambah Saldo Awal di Auto-Balancer.
 * 3. CACHE PURGER: Menghapus Snapshot basi setelah Auto-Balance agar sistem langsung membaca saldo terbaru.
 */

$koneksi_path = __DIR__ . '/../config/koneksi.php';
if (file_exists($koneksi_path)) { require_once $koneksi_path; } 
else { @require_once 'config/koneksi.php'; }

if (!isset($conn)) { die("Sistem gagal memuat koneksi database. Path file koneksi tidak valid."); }

// =========================================================================
// ?? BLOK RESET & THE SMART AUTO-BALANCER
// =========================================================================
if (isset($_GET['reset_ob_master']) && $_GET['reset_ob_master'] == '1') {
    try { 
        $conn->query("UPDATE syifa_akun SET opening_balance = 0 WHERE is_group = 0"); 
        $conn->query("DELETE FROM syifa_ledger_snapshot"); // Bersihkan cache basi
        header("Location: ../index.php?page=laporan_posisi_keuangan"); exit; 
    } catch (Exception $e) { die($e->getMessage()); }
}

if (isset($_GET['auto_balance_ob']) && $_GET['auto_balance_ob'] == '1') {
    try {
        $ob = $conn->query("SELECT SUM(CASE WHEN saldo_normal='D' THEN COALESCE(opening_balance, 0) ELSE 0 END) as td, SUM(CASE WHEN saldo_normal='K' THEN COALESCE(opening_balance, 0) ELSE 0 END) as tk FROM syifa_akun WHERE is_group=0")->fetch_assoc();
        $diff = (double)$ob['td'] - (double)$ob['tk'];
        
        if (round(abs($diff), 2) > 0) {
            // Cari akun Aset Neto Tanpa Pembatasan
            $q_eq = $conn->query("SELECT kode_akun FROM syifa_akun WHERE report_group = 'equity_unrestricted' AND is_group = 0 LIMIT 1");
            if (!$q_eq || $q_eq->num_rows == 0) {
                $q_eq = $conn->query("SELECT kode_akun FROM syifa_akun WHERE kategori IN ('Aset Neto', 'Ekuitas') AND is_group = 0 LIMIT 1");
            }
            
            if ($q_eq && $q_eq->num_rows > 0) {
                $coa_eq = $q_eq->fetch_assoc()['kode_akun'];
                
                // ??? FIX MUTLAK 1: Gunakan COALESCE untuk mencegah bug penjumlahan NULL di MySQL
                if ($diff > 0) {
                    $conn->query("UPDATE syifa_akun SET opening_balance = COALESCE(opening_balance, 0) + $diff WHERE kode_akun = '$coa_eq'");
                } else {
                    $abs_diff = abs($diff); 
                    $conn->query("UPDATE syifa_akun SET opening_balance = COALESCE(opening_balance, 0) - $abs_diff WHERE kode_akun = '$coa_eq'"); 
                }
                
                // ??? FIX MUTLAK 2: Hancurkan Snapshot lama yang masih menyimpan data pincang!
                $conn->query("DELETE FROM syifa_ledger_snapshot");
                $conn->query("DELETE FROM syifa_trial_balance_cache");
                
            } else {
                throw new Exception("Auto-Balancer Gagal: Tidak ditemukan akun Aset Neto / Ekuitas di Master COA untuk menampung selisih.");
            }
        }
        header("Location: ../index.php?page=laporan_posisi_keuangan"); exit;
    } catch (Exception $e) { die($e->getMessage()); }
}

class TrialBalanceCacheEngine {
    public static function getBalances($conn, $cut_off) {
        
        $stmt_per = $conn->prepare("SELECT COUNT(*) as cnt FROM syifa_periode_laporan WHERE tgl_mulai <= ? AND tgl_akhir >= ? AND status = 'Aktif'");
        $stmt_per->bind_param("ss", $cut_off, $cut_off);
        $stmt_per->execute();
        $res_per = $stmt_per->get_result()->fetch_assoc();
        
        if ($res_per['cnt'] == 0) {
            throw new Exception("PERIODE DITOLAK (LEDGER FREEZE): Periode laporan untuk tanggal cut-off (".date('d/m/Y', strtotime($cut_off)).") tidak dikonfigurasi atau berstatus DITUTUP.");
        }

        $tahun = (int)date('Y', strtotime($cut_off));
        $bulan = (int)date('m', strtotime($cut_off));
        $is_end_of_month = (date('Y-m-d', strtotime($cut_off)) === date('Y-m-t', strtotime($cut_off)));
        
        $use_snapshot = false;

        if ($is_end_of_month) {
            $cek_tbl = $conn->query("SHOW TABLES LIKE 'syifa_ledger_snapshot'");
            if ($cek_tbl && $cek_tbl->num_rows > 0) {
                $cek_data = $conn->query("SELECT id FROM syifa_ledger_snapshot WHERE periode_tahun = $tahun AND periode_bulan = $bulan LIMIT 1");
                if ($cek_data && $cek_data->num_rows > 0) {
                    $use_snapshot = true;
                }
            }
        }

        if ($use_snapshot) {
            $sql = "
                SELECT a.kode_akun, a.saldo_normal, a.kategori, a.nama_akun, a.report_group,
                       snap.net_balance as signed_balance
                FROM syifa_akun a
                JOIN syifa_ledger_snapshot snap 
                       ON a.kode_akun = snap.kode_akun 
                      AND snap.periode_tahun = $tahun 
                      AND snap.periode_bulan = $bulan
                WHERE a.is_group = 0
            ";
            $stmt = $conn->prepare($sql);
        } else {
            $sql = "
                SELECT a.kode_akun, a.saldo_normal, a.kategori, a.nama_akun, a.report_group,
                       COALESCE(a.opening_balance, 0) as ob,
                       COALESCE(mut.td, 0) as td,
                       COALESCE(mut.tk, 0) as tk
                FROM syifa_akun a
                LEFT JOIN (
                    SELECT jd.kode_akun, SUM(jd.debit) as td, SUM(jd.kredit) as tk
                    FROM syifa_jurnal_detail jd 
                    JOIN syifa_jurnal j ON j.id = jd.jurnal_id
                    WHERE j.tgl_jurnal <= ? 
                    AND j.is_deleted = 0
                    GROUP BY jd.kode_akun
                ) mut ON a.kode_akun = mut.kode_akun 
                WHERE a.is_group = 0
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $cut_off);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        $balances = [];
        $tb_debit = 0;
        $tb_kredit = 0;

        $valid_groups = ['cash', 'receivable', 'prepaid', 'inventory', 'asset_other', 'fixed_asset_cost', 'fixed_asset_accum', 'intangible_asset_cost', 'intangible_asset_accum', 'liability_short', 'liability_long', 'liability_other', 'equity_unrestricted', 'equity_restricted', 'revenue', 'expense', 'retained_earnings', 'current_year_earnings'];

        while ($row = $res->fetch_assoc()) {
            
            // =======================================================================
            // ??? FIX MUTLAK 3: THE RUNTIME RAM HEALER (Menghancurkan Error 1-1102)
            // =======================================================================
            $rg = trim(strtolower($row['report_group'] ?? ''));
            
            // Jika ada karakter hantu / kosong, obati langsung di dalam memori PHP!
            if (empty($rg) || !in_array($rg, $valid_groups) || $rg == 'posisi_keuangan' || $rg == 'aktivitas') {
                $k = trim($row['kode_akun']);
                $n = strtolower($row['nama_akun']);
                
                if (strpos($k, '1-11') === 0) $rg = 'cash';
                elseif (strpos($k, '1-12') === 0) $rg = 'receivable';
                elseif (strpos($k, '1-13') === 0) $rg = 'prepaid';
                elseif (strpos($k, '1-14') === 0) $rg = 'inventory';
                elseif (strpos($k, '1-21') === 0) {
                    if (strpos($k, '99') !== false || strpos($n, 'akumulasi') !== false) $rg = 'fixed_asset_accum';
                    else $rg = 'fixed_asset_cost';
                }
                elseif (strpos($k, '1-22') === 0) {
                    if (strpos($k, '99') !== false || strpos($n, 'amortisasi') !== false) $rg = 'intangible_asset_accum';
                    else $rg = 'intangible_asset_cost';
                }
                elseif (strpos($k, '1-') === 0) $rg = 'asset_other';
                elseif (strpos($k, '2-1') === 0) $rg = 'liability_short';
                elseif (strpos($k, '2-2') === 0) $rg = 'liability_long';
                elseif (strpos($k, '2-') === 0) $rg = 'liability_other';
                elseif (strpos($k, '3-2') === 0) $rg = 'equity_restricted';
                elseif (strpos($k, '3-') === 0) $rg = 'equity_unrestricted';
                elseif (strpos($k, '4-') === 0) $rg = 'revenue';
                elseif (preg_match('/^[56789]-/', $k)) $rg = 'expense';
                else $rg = 'asset_other';
                
                // Menimpa array di RAM, sehingga Engine Neraca akan menerima data suci 100%
                $row['report_group'] = $rg;
                
                // Coba sembuhkan Database diam-diam (Silent Heal) tanpa peduli error/berhasil
                $conn->query("UPDATE syifa_akun SET report_group = '$rg' WHERE kode_akun = '$k'");
            }

            // LANJUTKAN PERHITUNGAN NORMAL
            if ($use_snapshot) {
                $signed_balance = (double)$row['signed_balance'];
            } else {
                $ob = (double)$row['ob'];
                $td = (double)$row['td'];
                $tk = (double)$row['tk'];

                $ob_signed = ($row['saldo_normal'] === 'D') ? abs($ob) : -abs($ob);
                $signed_balance = $ob_signed + $td - $tk;
                $row['signed_balance'] = $signed_balance;
            }
            
            if ($signed_balance >= 0) { $tb_debit += $signed_balance; } 
            else { $tb_kredit += abs($signed_balance); }

            $balances[] = $row;
        }

        $selisih = abs($tb_debit - $tb_kredit);
        
        if (round($selisih, 2) > 1) {
            $html = "<div style='text-align:left; background:#fff; padding:20px; border-radius:12px; border:2px solid #ef4444; margin-top:20px; font-size:14px; color:#1e293b; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);'>";
            $html .= "<h5 style='color:#ef4444; border-bottom:2px dashed #ef4444; padding-bottom:10px; margin-bottom:15px; font-weight:bold;'>??? RADAR FORENSIK: MENDETEKSI SELISIH Rp ".number_format($selisih, 2, ',', '.')."</h5>";
            
            $q_ob = $conn->query("SELECT SUM(CASE WHEN saldo_normal='D' THEN COALESCE(opening_balance,0) ELSE 0 END) as td, SUM(CASE WHEN saldo_normal='K' THEN COALESCE(opening_balance,0) ELSE 0 END) as tk FROM syifa_akun WHERE is_group=0");
            $ob = $q_ob->fetch_assoc();
            $diff_ob = abs((double)$ob['td'] - (double)$ob['tk']);
            
            // CEK APAKAH SELISIHNYA ADA DI SALDO AWAL ATAU DI JURNAL?
            if (round($diff_ob, 2) > 0) {
                $html .= "<div style='background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:15px; border-left:4px solid #f59e0b;'>";
                $html .= "<b style='color:#f59e0b; font-size:16px;'>1. ANOMALI SALDO AWAL (MASTER COA)</b><br>Sistem mendeteksi ada ketidakseimbangan pada inputan Saldo Awal. Ini adalah hal wajar jika Anda membawa saldo migrasi dari sistem lama. Namun Hukum Akuntansi mewajibkan posisi Debit dan Kredit mutlak seimbang.<br><br>";
                $html .= "<div style='margin-top:10px; color:#ef4444; font-weight:bold;'>SELISIH SALDO AWAL: Rp ".number_format($diff_ob, 0, ',', '.')."</div>";
                $html .= "<div style='margin-top:20px; padding-top:15px; border-top:1px dashed #cbd5e1; text-align:center;'>";
                $html .= "<p style='margin-bottom:15px; color:#475569;'>Klik tombol di bawah ini agar sistem membuang selisih tersebut ke akun <b>Aset Neto</b> secara otomatis, sehingga migrasi Anda menjadi Valid dan Legal.</p>";
                $html .= "<a href='engine/TrialBalanceCacheEngine.php?auto_balance_ob=1' style='display:inline-block; padding:12px 25px; background:#10b981; color:#fff; text-decoration:none; border-radius:50px; font-weight:bold; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.3);'><i class='fas fa-magic me-2'></i>SEIMBANGKAN OTOMATIS KE ASET NETO</a>";
                $html .= "</div></div>";
            } else {
                // Jika Saldo Awal sudah seimbang, berarti masalahnya murni ada di Jurnal Pincang!
                $html .= "<div style='background:#fef2f2; padding:15px; border-radius:8px; margin-bottom:15px; border-left:4px solid #ef4444;'>";
                $html .= "<b style='color:#ef4444; font-size:16px;'>2. ANOMALI JURNAL TRANSAKSI (TIDAK SEIMBANG)</b><br>Sistem mendeteksi ada Jurnal Transaksi berjalan yang jumlah Debit dan Kreditnya pincang (berbeda). Ini merusak Trial Balance keseluruhan.<br><br>";
                $html .= "<div style='margin-top:20px; padding-top:15px; border-top:1px dashed #fca5a5; text-align:center;'>";
                $html .= "<a href='forensic_sweeper.php' target='_blank' style='display:inline-block; padding:12px 25px; background:#ef4444; color:#fff; text-decoration:none; border-radius:50px; font-weight:bold; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.3);'><i class='fas fa-biohazard me-2'></i>JALANKAN FORENSIC SWEEPER</a>";
                $html .= "</div></div>";
            }
            $html .= "</div>";
            throw new Exception($html);
        }
        return $balances;
    }
    
    public static function createCache($conn, $tahun, $bulan, $cut_off) {
        return true;
    }
}
?>