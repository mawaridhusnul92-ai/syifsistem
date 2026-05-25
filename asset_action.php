<?php
/**
 * asset_action.php - PUSAT KENDALI ASSET LIFE CYCLE
 * Versi: 11.6 (Sovereign Grand Master - Ultimate 500 Failsafe Edition)
 * STATUS: FULL CODE - NO TRUNCATION
 * Perbaikan Mutlak:
 * 1. Menerapkan 'catch (Throwable $e)' untuk menangkap segala jenis Fatal Error (Error 500) 
 * dan mengubahnya menjadi Notifikasi Layar Merah yang aman.
 * 2. Menyuntikkan Auto-Healer untuk kolom 'aset_id' pada jurnal detail.
 * 3. Menambahkan proteksi pada prepare statement agar tidak crash saat kueri gagal.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';
require_once 'engine/LedgerIntegrityService.php';

if (file_exists('engine/GlobalLogger.php')) { require_once 'engine/GlobalLogger.php'; }

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// 🛡️ ENGINE TERAPI DATABASE (AUTO-HEAL POISONED ACCOUNTS & MISSING COLUMNS)
// =========================================================================
try { 
    $chk_aset = $conn->query("SHOW COLUMNS FROM syifa_jurnal_detail LIKE 'aset_id'");
    if($chk_aset && $chk_aset->num_rows == 0) {
        $conn->query("ALTER TABLE syifa_jurnal_detail ADD COLUMN aset_id INT(11) NULL DEFAULT NULL");
    }
    
    $chk_mode = $conn->query("SHOW COLUMNS FROM assets LIKE 'purchase_mode'");
    if($chk_mode && $chk_mode->num_rows == 0) {
        $conn->query("ALTER TABLE assets ADD COLUMN purchase_mode VARCHAR(50) DEFAULT 'pembelian'");
    }
    
    $conn->query("UPDATE syifa_akun SET nama_akun = 'Aset Neto Migrasi (Auto)', kategori = 'Aset Neto', saldo_normal = 'K' WHERE nama_akun = 'Aset Tetap (Auto)' AND (kode_akun LIKE '3-%' OR kode_akun = '3-3101')");
    $conn->query("UPDATE syifa_akun SET nama_akun = 'Akumulasi Penyusutan (Auto)', kategori = 'Aset', saldo_normal = 'K' WHERE nama_akun = 'Aset Tetap (Auto)' AND (kode_akun LIKE '1-2%99' OR kode_akun = '1-2199')");
} catch(Throwable $e) {}

function done($type, $msg, $tab = 'dashboard') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: index.php?page=aset_manajemen&tab=$tab");
    exit;
}

function cleanNum($val) { return (double)str_replace(['.', ','], '', $val ?: '0'); }

function getAndHealCOA($conn, $raw_input, $tipe_akun) {
    if (empty($raw_input)) return null;
    $clean_kode = preg_replace('/[^a-zA-Z0-9\-\.]/', '', explode(" ", trim($raw_input))[0]);
    if (empty($clean_kode)) return null;
    
    $sql = "SELECT kode_akun, saldo_normal FROM syifa_akun WHERE TRIM(kode_akun) = '$clean_kode' LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $acc = $res->fetch_assoc();
        if (($tipe_akun == 'EKUITAS' || $tipe_akun == 'AKUMULASI') && $acc['saldo_normal'] == 'D') {
            $conn->query("UPDATE syifa_akun SET saldo_normal = 'K' WHERE kode_akun = '$clean_kode' AND nama_akun LIKE '%(Auto)%'");
        }
        return $acc['kode_akun'];
    }
    
    if ($tipe_akun == 'EXPENSE') { $nama = "Beban Penyusutan Aset (Auto)"; $kat = "Beban"; $sn = "D"; } 
    elseif ($tipe_akun == 'AKUMULASI') { $nama = "Akumulasi Penyusutan Aset (Auto)"; $kat = "Aset"; $sn = "K"; } 
    elseif ($tipe_akun == 'KAS') { $nama = "Kas/Bank (Auto)"; $kat = "Kas"; $sn = "D"; } 
    elseif ($tipe_akun == 'EKUITAS') { $nama = "Aset Neto Migrasi (Auto)"; $kat = "Aset Neto"; $sn = "K"; } 
    else { $nama = "Aset Tetap (Auto)"; $kat = "Aset"; $sn = "D"; }

    $ins = $conn->prepare("INSERT IGNORE INTO syifa_akun (kode_akun, nama_akun, kategori, saldo_normal, is_group, opening_balance, is_active, laporan_aktivitas, allow_posting) VALUES (?, ?, ?, ?, 0, 0, 1, 1, 1)");
    if ($ins) { $ins->bind_param("ssss", $clean_kode, $nama, $kat, $sn); $ins->execute(); }
    return $clean_kode;
}

// =========================================================================
// 1. HANDLER SAVE ASSET (Penyusutan Otomatis & Pembelian & MIGRATION GL)
// =========================================================================
if ($action == 'save_asset') {
    $id      = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $val     = cleanNum($_POST['purchase_value']); 
    $book    = cleanNum($_POST['current_book_value']); 
    $cat_id  = (int)$_POST['category_id']; 
    $type_id = (int)$_POST['type_id'];
    $mode    = $_POST['input_mode'] ?? 'edit'; 
    $src_acc = $_POST['source_account'] ?? null;
    $date    = $_POST['purchase_date']; 
    $name    = trim($_POST['asset_name']);
    $years   = (int)$_POST['useful_life']; 
    $months  = $years * 12; 
    $uid     = (int)$_SESSION['user_id'];

    try {
        $thn = (int)date('Y', strtotime($date));
        $bln = (int)date('m', strtotime($date));
        LedgerIntegrityService::checkPeriodLock($conn, $thn, $bln);

        $conn->begin_transaction();
        $target_id = $id;

        if ($id) {
            $old = $conn->query("SELECT purchase_value, residual_value, current_book_value, purchase_mode FROM assets WHERE id = $id")->fetch_assoc();
            $q_hist = $conn->query("SELECT IFNULL((SELECT SUM(nilai_penambahan) FROM asset_improvements WHERE asset_id = $id), 0) as tot_capex, IFNULL((SELECT SUM(nilai_susut) FROM asset_depreciation WHERE asset_id = $id), 0) as tot_depr")->fetch_assoc();
            $final_nbv = ($val + $q_hist['tot_capex']) - ($old['residual_value'] + $q_hist['tot_depr']);
            
            // 🛡️ FIX MUTLAK: FORCE MIGRATION BILA GHOST ASSET (Tidak ada Jurnal)
            $cek_jurnal = $conn->query("SELECT id FROM syifa_jurnal_detail WHERE aset_id = $id LIMIT 1")->num_rows;
            if ($cek_jurnal == 0) {
                $mode = 'saldo_awal';
            } else {
                $mode = !empty($old['purchase_mode']) ? $old['purchase_mode'] : $mode;
            }

            $stmt = $conn->prepare("UPDATE assets SET category_id=?, type_id=?, asset_name=?, purchase_date=?, purchase_value=?, current_book_value=?, useful_life=?, purchase_mode=? WHERE id=?");
            $stmt->bind_param("iissddisi", $cat_id, $type_id, $name, $date, $val, $final_nbv, $months, $mode, $id);
            $stmt->execute();
        } else {
            $code = "AST-" . date('ymd') . "-" . rand(100, 999);
            $akum_migrasi = ($mode == 'saldo_awal') ? ($val - $book) : 0;
            $initial_book = $book; 
            
            $stmt = $conn->prepare("INSERT INTO assets (asset_code, category_id, type_id, asset_name, purchase_date, purchase_value, residual_value, current_book_value, useful_life, status, purchase_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aktif', ?)");
            $stmt->bind_param("siissdddis", $code, $cat_id, $type_id, $name, $date, $val, $akum_migrasi, $initial_book, $months, $mode);
            $stmt->execute();
            $target_id = $conn->insert_id;
        }

        // =========================================================================
        // 🚀 THE SOVEREIGN GL INJECTION (MEMASUKKAN KE JURNAL)
        // =========================================================================
        if ($mode == 'saldo_awal') {
            $q_del = $conn->query("SELECT DISTINCT jurnal_id FROM syifa_jurnal_detail WHERE aset_id = $target_id AND jurnal_id IN (SELECT id FROM syifa_jurnal WHERE jenis_jurnal IN ('migrasi', 'migrasi_aset'))");
            if ($q_del) {
                while($rd = $q_del->fetch_assoc()) {
                    $jid_del = $rd['jurnal_id'];
                    $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $jid_del");
                    $conn->query("DELETE FROM syifa_jurnal WHERE id = $jid_del");
                }
            }

            $cat_data = $conn->query("SELECT coa_asset_code, coa_depr_code FROM asset_categories WHERE id = $cat_id")->fetch_assoc();
            $coa_aset = getAndHealCOA($conn, explode(' ', $cat_data['coa_asset_code'] ?? '')[0], 'ASET') ?? getAndHealCOA($conn, '1-2101', 'ASET');
            $coa_akum = getAndHealCOA($conn, explode(' ', $cat_data['coa_depr_code'] ?? '')[0], 'AKUMULASI') ?? getAndHealCOA($conn, '1-2199', 'AKUMULASI');
            
            $q_eq = $conn->query("SELECT kode_akun FROM syifa_akun WHERE kategori IN ('Aset Neto', 'Ekuitas') AND is_group=0 LIMIT 1");
            $coa_eq = ($q_eq && $q_eq->num_rows > 0) ? $q_eq->fetch_assoc()['kode_akun'] : getAndHealCOA($conn, '3-3101', 'EKUITAS');

            $no_j = "SA-AST-" . date('ymd', strtotime($date)) . "-" . str_pad($target_id, 4, '0', STR_PAD_LEFT);
            $ket = "Migrasi Saldo Awal Aset: " . $name;
            
            $akum_susut = $val - $book;
            if ($akum_susut < 0) $akum_susut = 0;
            $aset_neto = $val - $akum_susut;

            $stmt_jh = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, keterangan, total_debet, total_kredit, created_by, jenis_jurnal) VALUES (?, ?, ?, ?, ?, ?, 'migrasi_aset')");
            $stmt_jh->bind_param("sssddi", $no_j, $date, $ket, $val, $val, $uid);
            $stmt_jh->execute(); $jid = $conn->insert_id;

            $stmt_jd = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, aset_id) VALUES (?, ?, ?, ?, ?)");
            $z = 0;
            
            $stmt_jd->bind_param("isddi", $jid, $coa_aset, $val, $z, $target_id); $stmt_jd->execute();
            if ($akum_susut > 0) { $stmt_jd->bind_param("isddi", $jid, $coa_akum, $z, $akum_susut, $target_id); $stmt_jd->execute(); }
            if ($aset_neto > 0)  { $stmt_jd->bind_param("isddi", $jid, $coa_eq, $z, $aset_neto, $target_id); $stmt_jd->execute(); }

        } else if ($mode == 'pembelian' && $src_acc && !$id) {
            $cat_data = $conn->query("SELECT coa_asset_code FROM asset_categories WHERE id = $cat_id")->fetch_assoc();
            $coa_aset = getAndHealCOA($conn, explode(' ', $cat_data['coa_asset_code'] ?? '')[0], 'ASET') ?? getAndHealCOA($conn, '1-2101', 'ASET');
            $coa_kas = getAndHealCOA($conn, explode(' ', $src_acc)[0], 'KAS');
            
            $no_bkk = function_exists('getNextNumber') ? getNextNumber($conn, 'kas_keluar') : 'BKK-'.time();
            $kj = "Beli Aset: " . $name; $jns = "otomatis";

            $stmt_jh = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, keterangan, total_debet, total_kredit, created_by, jenis_jurnal, akun_utama_kode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_jh->bind_param("sssddiss", $no_bkk, $date, $kj, $val, $val, $uid, $jns, $coa_kas);
            $stmt_jh->execute(); $jid = $conn->insert_id;

            $stmt_jd = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, aset_id) VALUES (?, ?, ?, ?, ?)");
            $z = 0; 
            $stmt_jd->bind_param("isddi", $jid, $coa_aset, $val, $z, $target_id); $stmt_jd->execute();
            $stmt_jd->bind_param("isddi", $jid, $coa_kas, $z, $val, $target_id); $stmt_jd->execute();
        }

        if(class_exists('GlobalLogger') && isset($jid)) {
            $j_data = $conn->query("SELECT * FROM syifa_jurnal WHERE id=$jid")->fetch_assoc();
            GlobalLogger::log($conn, $uid, 'Buat', 'Aset Tetap', 'syifa_jurnal', $jid, ($mode == 'saldo_awal' ? 'Migrasi Aset Baru: ' : 'Pembelian Aset: ') . $name, null, $j_data);
        }

        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, ($date ?? date('Y-m-d')));
        done('success', "Aset berhasil diamankan ke dalam Neraca Mutlak Buku Besar (GL).", 'master');
        
    } catch (Throwable $e) { 
        $err = $e->getMessage();
        if(strpos($err, 'Pelanggaran Normal Balance') !== false) {
            $err .= "<br><br><b>💡 Sistem Auto-Diagnosis:</b> Sistem menangkap penyimpangan konfigurasi. Periksa Master Kategori Aset.";
        }
        if(isset($conn) && $conn->connect_errno == 0) $conn->rollback(); 
        done('danger', "Gagal: " . $err, 'master'); 
    }
}

// =========================================================================
// 2. HANDLER RUN DEPRECIATION (THE ANTI-CRASH EDITION)
// =========================================================================
if ($action == 'run_depreciation') {
    $req_month = (int)$_POST['bulan']; 
    $req_year = (int)$_POST['tahun']; 
    $uid = (int)$_SESSION['user_id'];
    
    try {
        LedgerIntegrityService::checkPeriodLock($conn, $req_year, $req_month);

        $conn->begin_transaction();
        
        $categories_res = $conn->query("SELECT * FROM asset_categories WHERE coa_depr_code IS NOT NULL AND coa_depr_code != '' AND coa_expense_code IS NOT NULL AND coa_expense_code != ''");
        if (!$categories_res) throw new Exception("Gagal memuat kategori aset dari database: " . $conn->error);
        
        $categories = $categories_res->fetch_all(MYSQLI_ASSOC);
        $total_processed = 0;
        
        foreach($categories as $cat) {
            $real_coa_exp = getAndHealCOA($conn, $cat['coa_expense_code'], 'EXPENSE');
            $real_coa_dep = getAndHealCOA($conn, $cat['coa_depr_code'], 'AKUMULASI');
            
            $sql_ast = "
                SELECT a.*, IFNULL((SELECT SUM(nilai_penambahan) FROM asset_improvements WHERE asset_id = a.id), 0) as tot_capex 
                FROM assets a 
                WHERE a.category_id = {$cat['id']} AND a.status = 'Aktif' AND a.current_book_value > 0 
                AND NOT EXISTS (
                    SELECT 1 FROM asset_depreciation ad 
                    WHERE ad.asset_id = a.id AND ad.periode_bulan = $req_month AND ad.periode_tahun = $req_year
                )
            ";
            
            $assets = $conn->query($sql_ast);
            if (!$assets) throw new Exception("Gagal memuat rincian aset: " . $conn->error);
            
            while($a = $assets->fetch_assoc()) {
                $rem_life = (int)$a['useful_life']; 
                if($rem_life <= 0) $rem_life = 1;
                $basis = (double)$a['purchase_value'] + (double)$a['tot_capex'];
                
                $monthly = ($basis - (double)$a['residual_value']) / $rem_life;
                if($monthly > $a['current_book_value']) $monthly = $a['current_book_value'];
                if($monthly <= 0) continue;

                $no_j = "DEP-" . $req_year . sprintf("%02d", $req_month) . "-" . str_pad($a['id'], 5, '0', STR_PAD_LEFT); 
                $ket = "Penyusutan Aset: ".$a['asset_name'] . " (" . sprintf("%02d", $req_month) . "/$req_year)";
                $tgl_now = "$req_year-" . sprintf("%02d", $req_month) . "-28"; 
                $jns = "otomatis";
                
                $stmt_jh = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, keterangan, total_debet, total_kredit, created_by, jenis_jurnal) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_jh) throw new Exception("Gagal Prepare Jurnal: " . $conn->error);
                
                $stmt_jh->bind_param("sssddis", $no_j, $tgl_now, $ket, $monthly, $monthly, $uid, $jns); 
                $stmt_jh->execute(); $jid = $conn->insert_id;
                
                $stmt_jd = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, aset_id) VALUES (?, ?, ?, ?, ?)");
                if (!$stmt_jd) throw new Exception("Gagal Prepare Detail Jurnal: " . $conn->error);
                
                $z = 0; 
                $stmt_jd->bind_param("isddi", $jid, $real_coa_exp, $monthly, $z, $a['id']); $stmt_jd->execute();
                $stmt_jd->bind_param("isddi", $jid, $real_coa_dep, $z, $monthly, $a['id']); $stmt_jd->execute();

                $new_book = $a['current_book_value'] - $monthly;
                $conn->query("UPDATE assets SET current_book_value = $new_book, last_depr_date = '$tgl_now' WHERE id = {$a['id']}");
                
                $stmt_log = $conn->prepare("INSERT INTO asset_depreciation (asset_id, periode_bulan, periode_tahun, nilai_susut, nilai_buku_akhir, jurnal_id) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt_log) throw new Exception("Gagal Prepare Log Depresiasi: " . $conn->error);
                
                $stmt_log->bind_param("iiiddi", $a['id'], $req_month, $req_year, $monthly, $new_book, $jid); 
                $stmt_log->execute();
                
                if(class_exists('GlobalLogger')) {
                    $j_data = $conn->query("SELECT * FROM syifa_jurnal WHERE id=$jid")->fetch_assoc();
                    GlobalLogger::log($conn, $uid, 'Buat', 'Aset Tetap', 'syifa_jurnal', $jid, "Amortisasi/Penyusutan Otomatis: " . $a['asset_name'], null, $j_data);
                }
                
                $total_processed++;
            }
        }
        $conn->commit(); 
        $tgl_trigger = $req_year . '-' . sprintf("%02d", $req_month) . '-28';
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl_trigger);
        
        if ($total_processed > 0) { done('success', "$total_processed aset berhasil disusutkan.", 'engine'); } 
        else { done('warning', "Semua aset pada periode $req_month/$req_year sudah disusutkan.", 'engine'); }
    } catch (Throwable $e) { 
        if(isset($conn) && $conn->connect_errno == 0) $conn->rollback(); 
        done('danger', "Gagal Sistem (Error Terlindungi): " . $e->getMessage(), 'engine'); 
    }
}

// =========================================================================
// 3. HANDLER PEMBATALAN PENYUSUTAN & HAPUS ASET
// =========================================================================
if ($action == 'reset_period_depreciation') {
    $bulan = (int)$_POST['bulan']; $tahun = (int)$_POST['tahun'];
    try {
        LedgerIntegrityService::checkPeriodLock($conn, $tahun, $bulan);

        $conn->begin_transaction();
        $res = $conn->query("SELECT * FROM asset_depreciation WHERE periode_bulan=$bulan AND periode_tahun=$tahun");
        if (!$res || $res->num_rows == 0) throw new Exception("Data riwayat tidak ditemukan untuk periode tersebut.");

        while($l = $res->fetch_assoc()) {
            $conn->query("UPDATE assets SET current_book_value = current_book_value + {$l['nilai_susut']}, last_depr_date = NULL WHERE id = {$l['asset_id']}");
            if($l['jurnal_id']) {
                $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = {$l['jurnal_id']}");
                $conn->query("DELETE FROM syifa_jurnal WHERE id = {$l['jurnal_id']}");
            }
        }
        $conn->query("DELETE FROM asset_depreciation WHERE periode_bulan=$bulan AND periode_tahun=$tahun");
        $conn->commit(); 
        
        $tgl_trigger = $tahun . '-' . sprintf("%02d", $bulan) . '-01';
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl_trigger);
        done('success', "Penyusutan berhasil dibatalkan dan saldo dipulihkan.", 'engine');
    } catch (Throwable $e) { 
        if(isset($conn) && $conn->connect_errno == 0) $conn->rollback(); 
        done('danger', "Gagal: " . $e->getMessage(), 'engine'); 
    }
}

// =========================================================================
// 4. HANDLER PENAMBAHAN NILAI (CAPEX)
// =========================================================================
if ($action == 'save_improvement') {
    $aid  = (int)($_POST['asset_id'] ?? 0); 
    $tgl  = $_POST['tanggal'] ?? date('Y-m-d'); 
    $amt  = cleanNum($_POST['amount'] ?? $_POST['nilai_penambahan'] ?? 0);
    $src  = $_POST['source_account'] ?? ''; 
    $desc = $conn->real_escape_string($_POST['desc'] ?? $_POST['keterangan'] ?? ''); 
    $jns  = $_POST['jenis'] ?? $_POST['jenis_penambahan'] ?? 'Lainnya'; 
    $uid  = (int)$_SESSION['user_id'];
    
    try {
        $thn = (int)date('Y', strtotime($tgl));
        $bln = (int)date('m', strtotime($tgl));
        LedgerIntegrityService::checkPeriodLock($conn, $thn, $bln);

        $conn->begin_transaction();
        if ($amt <= 0) throw new Exception("Nominal kapitalisasi tidak valid.");
        if (empty($src)) throw new Exception("Akun sumber Kas/Bank wajib dipilih.");

        $a = $conn->query("SELECT a.asset_name, c.coa_asset_code FROM assets a JOIN asset_categories c ON a.category_id = c.id WHERE a.id = $aid")->fetch_assoc();
        if (!$a) throw new Exception("Data Master Aset tidak ditemukan.");
        
        $coa_aset = getAndHealCOA($conn, $a['coa_asset_code'], 'ASET');
        $real_src = getAndHealCOA($conn, $src, 'KAS');
        
        $no_jurnal = function_exists('getNextNumber') ? getNextNumber($conn, 'kas_keluar') : 'CAP-'.time();
        $ket = "Kapitalisasi ($jns): {$a['asset_name']}" . ($desc ? " - $desc" : "");
        
        $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, pihak_nama, keterangan, total_debet, total_kredit, created_by, akun_utama_kode, jenis_jurnal) VALUES (?, ?, 'Sistem Aset', ?, ?, ?, ?, ?, 'otomatis')");
        if (!$stmt_j) throw new Exception("Prepare Jurnal Gagal: " . $conn->error);
        
        $stmt_j->bind_param("sssddis", $no_jurnal, $tgl, $ket, $amt, $amt, $uid, $real_src);
        $stmt_j->execute(); 
        $jid = $conn->insert_id;

        $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, aset_id) VALUES ($jid, '$coa_aset', $amt, 0, $aid)");
        $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, aset_id) VALUES ($jid, '$real_src', 0, $amt, $aid)");
        $conn->query("UPDATE assets SET current_book_value = current_book_value + $amt WHERE id = $aid");
        
        $stmt_i = $conn->prepare("INSERT INTO asset_improvements (asset_id, tanggal, jenis_penambahan, nilai_penambahan, keterangan, journal_id) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt_i) throw new Exception("Prepare Log Gagal: " . $conn->error);
        
        $stmt_i->bind_param("issdsi", $aid, $tgl, $jns, $amt, $desc, $jid); 
        $stmt_i->execute();
        
        if(class_exists('GlobalLogger')) {
            $j_data = $conn->query("SELECT * FROM syifa_jurnal WHERE id=$jid")->fetch_assoc();
            GlobalLogger::log($conn, $uid, 'Buat', 'Aset Tetap', 'syifa_jurnal', $jid, "Kapitalisasi/Penambahan Nilai: " . $a['asset_name'], null, $j_data);
        }
        
        $conn->commit(); 
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl);
        done('success', "Kapitalisasi senilai Rp ".number_format($amt, 0, ',', '.')." berhasil diposting.", 'improvement');
    } catch (Throwable $e) { 
        if(isset($conn) && $conn->connect_errno == 0) $conn->rollback(); 
        done('danger', "Gagal Kapitalisasi: " . $e->getMessage(), 'improvement'); 
    }
}
ob_end_flush();
?>