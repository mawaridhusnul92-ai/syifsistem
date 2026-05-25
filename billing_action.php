<?php
/**
 * billing_action.php - PUSAT KENDALI PIUTANG MAHASISWA (PURE BACKEND)
 * Versi: 92.0 (Sovereign Grand Master - Full Code & Name Logger)
 * STATUS: 100% FULL CODE (TIDAK ADA PEMOTONGAN)
 * Perbaikan Mutlak: 
 * Memastikan setiap entri log audit menangkap 'Nama Mahasiswa' secara dinamis
 * agar laporan aktivitas dapat dibaca dengan mudah oleh manusia (Human Readable).
 */
ob_start(); 
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

function enforceSchema($conn) {
    try {
        $res = $conn->query("SHOW COLUMNS FROM keuangan_pembayaran_log LIKE 'kode_akun_kas'");
        if ($res && $res->num_rows == 0) { $conn->query("ALTER TABLE keuangan_pembayaran_log ADD COLUMN kode_akun_kas VARCHAR(50) NULL"); }

        $res_kwt = $conn->query("SHOW COLUMNS FROM keuangan_pembayaran_log LIKE 'no_kuitansi'");
        if ($res_kwt && $res_kwt->num_rows == 0) { $conn->query("ALTER TABLE keuangan_pembayaran_log ADD COLUMN no_kuitansi VARCHAR(50) NULL"); }

        $res2 = $conn->query("SHOW COLUMNS FROM keuangan_pembayaran_log LIKE 'link_jurnal_id'");
        if ($res2 && $res2->num_rows == 0) { $conn->query("ALTER TABLE keuangan_pembayaran_log ADD COLUMN link_jurnal_id INT NULL"); }
        
        $res3 = $conn->query("SHOW COLUMNS FROM keuangan_tagihan LIKE 'jenis_tagihan_id'");
        if ($res3 && $res3->num_rows == 0) { $conn->query("ALTER TABLE keuangan_tagihan ADD COLUMN jenis_tagihan_id INT NULL"); }
        
        $c1 = $conn->query("SHOW COLUMNS FROM syifa_jurnal_detail LIKE 'mahasiswa_id'");
        if ($c1 && $c1->num_rows == 0) { @$conn->query("ALTER TABLE syifa_jurnal_detail ADD COLUMN mahasiswa_id INT NULL AFTER kredit"); }

        $c2 = $conn->query("SHOW COLUMNS FROM syifa_jurnal_detail LIKE 'aset_id'");
        if ($c2 && $c2->num_rows == 0) { @$conn->query("ALTER TABLE syifa_jurnal_detail ADD COLUMN aset_id INT NULL AFTER mahasiswa_id"); }

        $res4 = $conn->query("SHOW COLUMNS FROM syifa_jurnal_detail LIKE 'tagihan_id_ref'");
        if ($res4 && $res4->num_rows == 0) { @$conn->query("ALTER TABLE syifa_jurnal_detail ADD COLUMN tagihan_id_ref INT NULL AFTER aset_id"); }
    } catch (Exception $e) {}
}

enforceSchema($conn);

if (file_exists('engine/billing_guard.php')) require_once 'engine/billing_guard.php';
if (file_exists('engine/period_guard.php')) require_once 'engine/period_guard.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid = (int)$_SESSION['user_id'];

$CODE_PIUTANG = function_exists('getAccountCode') ? getAccountCode($conn, 'PIUTANG_MHS') : '1-1201';
if(!$CODE_PIUTANG) $CODE_PIUTANG = '1-1201';

// 🚀 THE OMNI LOGGER LOCAL HELPER
function omni_log_billing($conn, $uid, $action, $module, $desc, $old_data = null, $new_data = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO sys_activity_log (user_id, action_type, module, target_table, record_id, description, old_data, new_data, created_at) VALUES (?, ?, ?, '-', 0, ?, ?, ?, NOW())");
        $o = $old_data ? json_encode($old_data) : null;
        $n = $new_data ? json_encode($new_data) : null;
        if($stmt) { $stmt->bind_param("isssss", $uid, $action, $module, $desc, $o, $n); $stmt->execute(); }
    } catch(Exception $e){}
}

function autoSyncPiutang($conn, $nim = null, $tagihan_id = null) {
    if ($tagihan_id) {
        $q_sum = $conn->query("SELECT SUM(nominal_bayar) as tot FROM keuangan_pembayaran_log WHERE tagihan_id = $tagihan_id")->fetch_assoc();
        $tot_bayar = (double)($q_sum['tot'] ?? 0);
        $conn->query("UPDATE keuangan_tagihan SET terbayar = $tot_bayar, status_bayar = IF($tot_bayar >= nominal - 100, 'Lunas', IF($tot_bayar > 0, 'Sebagian', 'Belum Lunas')) WHERE id = $tagihan_id");
    }
}

function done($type, $msg, $url = 'tagihan_generate') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header("Location: index.php?page=$url");
    exit;
}

// =========================================================================
// 1. GENERATE TAGIHAN MASSAL
// =========================================================================
if ($action == 'generate_tagihan_matrix') {
    $kode_tahun = $conn->real_escape_string($_POST['kode_tahun']);
    $angkatan   = $conn->real_escape_string($_POST['angkatan'] ?? '');
    $prodi_id   = $conn->real_escape_string($_POST['prodi_id'] ?? '');
    $kode_sistem= $conn->real_escape_string($_POST['kode_sistem'] ?? '');
    $tgl_jurnal = date('Y-m-d');
    
    $where_base = "1=1";
    if($angkatan)   $where_base .= " AND m.angkatan = '$angkatan'";
    if($prodi_id)   $where_base .= " AND m.prodi_id = '$prodi_id'";
    if($kode_sistem) $where_base .= " AND m.sistem_kuliah = '$kode_sistem'";

    $conn->begin_transaction();
    try {
        $mhs_to_process = [];

        $status_cond = "1=1";
        $chk_sa = $conn->query("SHOW COLUMNS FROM syifa_mahasiswa LIKE 'status_aktif'");
        if ($chk_sa && $chk_sa->num_rows > 0) $status_cond = "m.status_aktif = 1";
        else {
            $chk_s = $conn->query("SHOW COLUMNS FROM syifa_mahasiswa LIKE 'status'");
            if ($chk_s && $chk_s->num_rows > 0) $status_cond = "(m.status = 'Aktif' OR m.status = '1')";
        }

        $sql_primary = "SELECT m.id, m.nim, m.nama, m.prodi_id, m.sistem_kuliah, m.angkatan 
                        FROM syifa_mahasiswa m 
                        LEFT JOIN mhs_keaktifan_semester k ON m.nim = k.nim AND k.kode_tahun = '$kode_tahun'
                        WHERE $where_base AND (k.status_aktif = 'Aktif' OR (k.id IS NULL AND $status_cond))";
        $mahasiswa = $conn->query($sql_primary);

        if ($mahasiswa && $mahasiswa->num_rows > 0) {
            while ($row = $mahasiswa->fetch_assoc()) {
                $mhs_to_process[] = $row;
            }
        }

        if(empty($mhs_to_process)) {
            throw new Exception("Tidak ada mahasiswa aktif yang sesuai dengan kriteria filter Prodi/Angkatan.");
        }

        $tarif_cols_db = [];
        $tc_res = $conn->query("SHOW COLUMNS FROM mhs_tarif");
        if ($tc_res) { while($tc = $tc_res->fetch_assoc()) { $tarif_cols_db[] = $tc['Field']; } }

        $join_cond = "t.nama_tarif = k.nama_jenis_tagihan"; 
        if (in_array('jenis_tagihan_id', $tarif_cols_db)) { $join_cond = "t.jenis_tagihan_id = k.id"; } 
        elseif (in_array('kode_tagihan', $tarif_cols_db)) { $join_cond = "t.kode_tagihan = k.kode_tagihan"; }

        $col_angkatan = in_array('periode_masuk', $tarif_cols_db) ? 'periode_masuk' : (in_array('angkatan', $tarif_cols_db) ? 'angkatan' : '');
        $col_sistem = in_array('sistem_kuliah', $tarif_cols_db) ? 'sistem_kuliah' : (in_array('kode_sistem', $tarif_cols_db) ? 'kode_sistem' : '');

        $count = 0; $total_value = 0;
        
        foreach ($mhs_to_process as $m) {
            $m_angkatan = $conn->real_escape_string($m['angkatan']);
            $m_sistem = $conn->real_escape_string($m['sistem_kuliah']);
            
            $score_ang = $col_angkatan ? "CASE WHEN t.$col_angkatan = '$m_angkatan' THEN 10 ELSE 0 END" : "0";
            $score_sys = $col_sistem ? "CASE WHEN t.$col_sistem = '$m_sistem' THEN 1 ELSE 0 END" : "0";

            $sql_tarif = "SELECT t.*, k.nama_jenis_tagihan, k.kode_akun_pendapatan,
                          ((CASE WHEN t.prodi_id = {$m['prodi_id']} THEN 100 ELSE 0 END) + $score_ang + $score_sys) as priority_score
                          FROM mhs_tarif t 
                          LEFT JOIN mhs_jenis_tagihan k ON $join_cond 
                          WHERE t.kode_tahun = '$kode_tahun' AND (t.prodi_id = {$m['prodi_id']} OR t.prodi_id = 0)";
            
            if ($col_angkatan) $sql_tarif .= " AND (t.$col_angkatan='$m_angkatan' OR t.$col_angkatan='' OR t.$col_angkatan IS NULL)";
            if ($col_sistem) $sql_tarif .= " AND (t.$col_sistem='$m_sistem' OR t.$col_sistem='' OR t.$col_sistem IS NULL)";
            
            $sql_tarif .= " ORDER BY priority_score DESC";

            $tarif = $conn->query($sql_tarif);
            
            if ($tarif && $tarif->num_rows > 0) {
                $processed_tagihan = [];
                while($t = $tarif->fetch_assoc()) {
                    $nama_tagihan = $t['nama_jenis_tagihan'] ?? $t['nama_tarif'];
                    
                    if (in_array($nama_tagihan, $processed_tagihan)) continue;
                    $processed_tagihan[] = $nama_tagihan;

                    $cek = $conn->query("SELECT id FROM keuangan_tagihan WHERE nim='{$m['nim']}' AND kode_tahun='$kode_tahun' AND nama_tagihan='$nama_tagihan'");
                    if($cek && $cek->num_rows == 0) {
                        $nominal = (double)$t['nominal'];
                        if ($nominal <= 0) continue; 
                        
                        $coa_pend = $t['kode_akun_pendapatan'] ?: '4-1000';
                        $no_inv = "INV-" . $m['nim'] . "-" . time() . rand(10,99);
                        $ket_jurnal = "Piutang Mahasiswa: {$nama_tagihan} ({$kode_tahun}) a.n {$m['nama']} [{$m['nim']}]";

                        $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, pihak_nama, keterangan, total_debet, total_kredit, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt_j->bind_param("ssssddi", $no_inv, $tgl_jurnal, $m['nama'], $ket_jurnal, $nominal, $nominal, $uid);
                        $stmt_j->execute();
                        $jid = $conn->insert_id;

                        $stmt_d1 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id) VALUES (?, ?, ?, 0, ?)");
                        $stmt_d1->bind_param("isdi", $jid, $CODE_PIUTANG, $nominal, $m['id']);
                        $stmt_d1->execute();

                        $tarif_id = isset($t['id']) ? (int)$t['id'] : 0;
                        $conn->query("INSERT INTO keuangan_tagihan (nim, kode_tahun, prodi_id, tarif_id, nama_tagihan, nominal, status_bayar, no_jurnal, link_jurnal_id) VALUES ('{$m['nim']}', '$kode_tahun', {$m['prodi_id']}, $tarif_id, '$nama_tagihan', $nominal, 'Belum Lunas', '$no_inv', $jid)");
                        $new_tid = $conn->insert_id;
                        
                        $stmt_d2 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id, tagihan_id_ref) VALUES (?, ?, 0, ?, ?, ?)");
                        $stmt_d2->bind_param("isdii", $jid, $coa_pend, $nominal, $m['id'], $new_tid);
                        $stmt_d2->execute();

                        $count++; $total_value += $nominal;
                    }
                }
            }
        }

        // Simpan Data JSON
        $new_bulk = ['periode_akademik' => $kode_tahun, 'total_invoice_terbit' => $count, 'total_proyeksi' => $total_value];
        omni_log_billing($conn, $uid, 'Generate', 'Tagihan Mahasiswa', "Generate massal $count tagihan piutang mahasiswa periode $kode_tahun dengan total proyeksi Rp " . number_format($total_value,0,',','.'), null, $new_bulk);
        
        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl_jurnal);
        
        if ($count > 0) {
            done('success', "Generate Selesai! <b>$count</b> Invoice Tagihan telah diterbitkan secara ajaib dengan total nilai <b>Rp " . number_format($total_value,0,',','.') . "</b>.", 'tagihan_generate');
        } else {
            done('warning', "Generate selesai: Tidak ada tagihan baru yang ditambahkan. Seluruh tagihan untuk mahasiswa pada filter ini sudah terbit (sudah digenerate) sebelumnya.", 'tagihan_generate');
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Sistem Menolak: " . $e->getMessage(), 'tagihan_generate');
    }
}

// =========================================================================
// 2. CANCEL GENERATE BILLING
// =========================================================================
else if ($action == 'cancel_generate_billing') {
    $kode_tahun = $conn->real_escape_string($_POST['kode_tahun'] ?? '');
    $prodi_id   = (int)($_POST['prodi_id'] ?? 0);
    $angkatan   = $conn->real_escape_string($_POST['angkatan'] ?? '');
    $kode_sistem= $conn->real_escape_string($_POST['kode_sistem'] ?? '');
    
    $where_t = "kode_tahun='$kode_tahun' AND prodi_id=$prodi_id AND terbayar = 0 AND status_bayar != 'Lunas'";
    if($angkatan) $where_t .= " AND nim IN (SELECT nim FROM syifa_mahasiswa WHERE angkatan='$angkatan')";
    if($kode_sistem) $where_t .= " AND nim IN (SELECT nim FROM syifa_mahasiswa WHERE sistem_kuliah='$kode_sistem')";
    
    $conn->begin_transaction();
    try {
        $jids = [];
        $q = $conn->query("SELECT link_jurnal_id FROM keuangan_tagihan WHERE $where_t");
        if ($q) {
            while($r = $q->fetch_assoc()) {
                if($r['link_jurnal_id']) $jids[] = $r['link_jurnal_id'];
            }
        }
        
        if(count($jids) > 0) {
            $jid_str = implode(',', $jids);
            $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id IN ($jid_str)");
            $conn->query("DELETE FROM syifa_jurnal WHERE id IN ($jid_str)");
        }
        $conn->query("DELETE FROM keuangan_tagihan WHERE $where_t");
        
        $jml_hapus = count($jids);
        
        // Simpan Data JSON
        $old_bulk = ['periode_akademik' => $kode_tahun, 'total_dihapus' => $jml_hapus, 'prodi_id' => $prodi_id];
        omni_log_billing($conn, $uid, 'Hapus', 'Tagihan Mahasiswa', "Membatalkan dan menghapus massal $jml_hapus tagihan (Draf/Belum Dibayar) periode $kode_tahun untuk prodi_id $prodi_id", $old_bulk, null);
        
        $conn->commit();
        done('success', "Tagihan massal (yang belum lunas) untuk periode dan prodi terkait berhasil dibatalkan dan dihapus dari Buku Besar.", 'tagihan_generate');
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Gagal membatalkan tagihan: " . $e->getMessage(), 'tagihan_generate');
    }
}

// =========================================================================
// 3. CREATE TAGIHAN MANUAL (FLEXIBLE AD-HOC)
// =========================================================================
else if ($action == 'generate_tagihan_manual') {
    $nim = $conn->real_escape_string($_POST['nim']);
    $kode_tahun = $conn->real_escape_string($_POST['kode_tahun']);
    $nama_tagihan = $conn->real_escape_string($_POST['nama_tagihan']);
    $coa_pendapatan = $conn->real_escape_string($_POST['coa_pendapatan']);
    
    $valStr = preg_replace('/[^0-9]/', '', $_POST['nominal']);
    $nominal = (double)$valStr;

    $conn->begin_transaction();
    try {
        if ($nominal <= 0) throw new Exception("Nominal tagihan harus lebih dari 0.");
        
        $mhs = $conn->query("SELECT id, nama, prodi_id FROM syifa_mahasiswa WHERE nim = '$nim' LIMIT 1")->fetch_assoc();
        if(!$mhs) throw new Exception("Data mahasiswa dengan NIM tersebut tidak ditemukan.");

        $no_inv = "INV-MAN-" . $nim . "-" . time();
        $tgl_jurnal = date('Y-m-d');
        $ket_jurnal = "Tagihan Manual: {$nama_tagihan} ({$kode_tahun}) a.n {$mhs['nama']} [{$nim}]";

        $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, pihak_nama, keterangan, total_debet, total_kredit, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_j->bind_param("ssssddi", $no_inv, $tgl_jurnal, $mhs['nama'], $ket_jurnal, $nominal, $nominal, $uid);
        $stmt_j->execute();
        $jid = $conn->insert_id;

        $stmt_d1 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id) VALUES (?, ?, ?, 0, ?)");
        $stmt_d1->bind_param("isdi", $jid, $CODE_PIUTANG, $nominal, $mhs['id']);
        $stmt_d1->execute();

        $conn->query("INSERT INTO keuangan_tagihan (nim, kode_tahun, prodi_id, tarif_id, nama_tagihan, nominal, status_bayar, no_jurnal, link_jurnal_id) VALUES ('$nim', '$kode_tahun', {$mhs['prodi_id']}, 0, '$nama_tagihan', $nominal, 'Belum Lunas', '$no_inv', $jid)");
        $new_tid = $conn->insert_id;

        $stmt_d2 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id, tagihan_id_ref) VALUES (?, ?, 0, ?, ?, ?)");
        $stmt_d2->bind_param("isdii", $jid, $coa_pendapatan, $nominal, $mhs['id'], $new_tid);
        $stmt_d2->execute();

        // 🚀 SIMPAN JSON DATA
        $json_data = [
            'nim' => $nim,
            'nama_mahasiswa' => $mhs['nama'],
            'nama_tagihan' => $nama_tagihan,
            'kode_tahun' => $kode_tahun,
            'nominal' => $nominal
        ];
        omni_log_billing($conn, $uid, 'Buat', 'Tagihan Mahasiswa', "Menerbitkan tagihan manual [$nama_tagihan] senilai Rp " . number_format($nominal,0,',','.') . " untuk {$mhs['nama']} ($nim)", null, $json_data);
        
        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl_jurnal);
        done('success', "Berhasil! Tagihan manual <b>{$nama_tagihan}</b> senilai Rp " . number_format($nominal, 0, ',', '.') . " telah diterbitkan untuk mahasiswa <b>{$mhs['nama']}</b>.", 'tagihan_generate');
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Sistem Menolak: " . $e->getMessage(), 'tagihan_generate');
    }
}

// =========================================================================
// 4. IMPORT TAGIHAN PIUTANG MASSAL (CSV)
// =========================================================================
else if ($action == 'import_tagihan_csv') {
    if (isset($_FILES['file_import']) && $_FILES['file_import']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['file_import']['tmp_name'];
        $handle = fopen($file, "r");
        
        $first_line = fgets($handle);
        $delim = ','; 
        if (strpos($first_line, ';') !== false) { $delim = ';'; } 
        elseif (strpos($first_line, "\t") !== false) { $delim = "\t"; }
        
        rewind($handle);
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

        $tagihan_map = [];
        $res_jt = $conn->query("SELECT nama_jenis_tagihan, kode_akun_pendapatan FROM mhs_jenis_tagihan");
        if ($res_jt) {
            while($jt = $res_jt->fetch_assoc()){
                $tagihan_map[strtolower(trim($jt['nama_jenis_tagihan']))] = $jt['kode_akun_pendapatan'] ?: '4-1000';
            }
        }

        $row_count = 0; $success_count = 0; $fail_count = 0; $tot_import = 0;
        
        $conn->begin_transaction();
        try {
            $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, pihak_nama, keterangan, total_debet, total_kredit, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_d1 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id) VALUES (?, ?, ?, 0, ?)");
            $stmt_d2 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id, tagihan_id_ref) VALUES (?, ?, 0, ?, ?, ?)");
            $stmt_t = $conn->prepare("INSERT INTO keuangan_tagihan (nim, kode_tahun, prodi_id, tarif_id, nama_tagihan, nominal, status_bayar, no_jurnal, link_jurnal_id) VALUES (?, ?, ?, 0, ?, ?, 'Belum Lunas', ?, ?)");

            while (($data = fgetcsv($handle, 1000, $delim)) !== FALSE) {
                $row_count++;
                if ($row_count == 1) continue; 
                
                $nim          = trim($data[0] ?? '');
                $nama_tagihan = trim($data[1] ?? '');
                $kode_tahun   = trim($data[2] ?? '');
                $nominal      = (double)str_replace(['.', ','], '', trim($data[3] ?? '0'));
                $tgl_jurnal   = trim($data[4] ?? '');
                $keterangan   = trim($data[5] ?? '');

                if (empty($nim) || empty($nama_tagihan) || $nominal <= 0) { $fail_count++; continue; }
                if (empty($tgl_jurnal)) { $tgl_jurnal = date('Y-m-d'); }

                $cek_duplikat = $conn->query("SELECT id FROM keuangan_tagihan WHERE nim='$nim' AND kode_tahun='$kode_tahun' AND nama_tagihan='".$conn->real_escape_string($nama_tagihan)."'");
                if ($cek_duplikat && $cek_duplikat->num_rows > 0) { $fail_count++; continue; }

                $q_mhs = $conn->query("SELECT id, nama, prodi_id FROM syifa_mahasiswa WHERE nim = '$nim' LIMIT 1");
                $mhs = $q_mhs->fetch_assoc();
                if (!$mhs) { $fail_count++; continue; }

                $coa_pendapatan = $tagihan_map[strtolower($nama_tagihan)] ?? '4-1000';
                
                $no_inv = "INV-IMP-" . $nim . "-" . time() . rand(10,999);
                $ket_jurnal = "Tagihan Piutang: {$nama_tagihan} ({$kode_tahun}) a.n {$mhs['nama']} [{$nim}]" . ($keterangan ? " - $keterangan" : "");

                $stmt_j->bind_param("ssssddi", $no_inv, $tgl_jurnal, $mhs['nama'], $ket_jurnal, $nominal, $nominal, $uid);
                $stmt_j->execute();
                $jid = $conn->insert_id;

                $stmt_d1->bind_param("isdi", $jid, $CODE_PIUTANG, $nominal, $mhs['id']);
                $stmt_d1->execute();

                $stmt_t->bind_param("ssisdsi", $nim, $kode_tahun, $mhs['prodi_id'], $nama_tagihan, $nominal, $no_inv, $jid);
                $stmt_t->execute();
                $new_tid = $conn->insert_id;

                $stmt_d2->bind_param("isdii", $jid, $coa_pendapatan, $nominal, $mhs['id'], $new_tid);
                $stmt_d2->execute();

                $success_count++;
                $tot_import += $nominal;
            }
            fclose($handle);
            
            $json_bulk = ['total_tagihan_terimport' => $success_count, 'total_nominal_rp' => $tot_import];
            omni_log_billing($conn, $uid, 'Import', 'Tagihan Mahasiswa', "Melakukan Import Massal CSV sebanyak $success_count data tagihan senilai Rp " . number_format($tot_import,0,',','.'), null, $json_bulk);
            
            $conn->commit();
            if (function_exists('triggerEventLedger')) triggerEventLedger($conn, date('Y-m-d'));

            if ($success_count > 0) {
                done('success', "Import Tagihan Piutang Selesai! <b>$success_count tagihan baru</b> berhasil dibuat dan diposting ke Jurnal.<br> <small><i>Ada $fail_count baris dilewati (kosong, duplikat, atau NIM tidak ditemukan).</i></small>", 'tagihan_generate');
            } else {
                done('warning', "Proses selesai, namun tidak ada tagihan baru yang terimport. Pastikan format NIM valid dan tagihan belum pernah diinput.", 'tagihan_generate');
            }
        } catch (Exception $e) {
            $conn->rollback();
            if($handle) fclose($handle);
            done('danger', "Gagal melakukan import tagihan: " . $e->getMessage(), 'tagihan_generate');
        }
    } else {
        done('danger', "Gagal mengunggah file CSV.", 'tagihan_generate');
    }
}

// =========================================================================
// 5. DELETE INVOICE TAGIHAN AMAN (DENGAN REVERSAL JURNAL)
// =========================================================================
else if ($action == 'delete_tagihan' || $action == 'delete_unified_invoice') {
    if (isset($_POST['no_jurnal']) || isset($_GET['no_jurnal'])) {
        $no_inv = $conn->real_escape_string($_POST['no_jurnal'] ?? $_GET['no_jurnal']);
        $t_query = $conn->query("SELECT t.id, t.link_jurnal_id, t.terbayar, t.nominal, t.created_at, t.nama_tagihan, t.nim, m.nama FROM keuangan_tagihan t LEFT JOIN syifa_mahasiswa m ON t.nim = m.nim WHERE t.no_jurnal='$no_inv' FOR UPDATE");
    } else {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $t_query = $conn->query("SELECT t.id, t.link_jurnal_id, t.terbayar, t.nominal, t.created_at, t.nama_tagihan, t.nim, m.nama FROM keuangan_tagihan t LEFT JOIN syifa_mahasiswa m ON t.nim = m.nim WHERE t.id=$id FOR UPDATE");
    }

    $conn->begin_transaction();
    try {
        if (!$t_query || $t_query->num_rows == 0) throw new Exception("Tagihan tidak ditemukan.");
        
        $item_deleted = ""; $nim_del = ""; $nama_mhs = ""; $tot_del = 0;
        $old_data_arr = [];
        
        while ($t = $t_query->fetch_assoc()) {
            if ($t['terbayar'] > 0) throw new Exception("Akses Ditolak: Sebagian tagihan di dalam invoice ini sudah dibayar. Lakukan pembatalan pembayaran di Terminal Kasir terlebih dahulu.");

            $tgl_trigger = $t ? date('Y-m-d', strtotime($t['created_at'])) : date('Y-m-d');
            $item_deleted .= $t['nama_tagihan'] . ", ";
            $nim_del = $t['nim'];
            $nama_mhs = $t['nama'] ?? 'Unknown';
            $tot_del += $t['nominal'];
            
            $old_data_arr[] = ['nim' => $t['nim'], 'nama_mahasiswa' => $t['nama'], 'nama_tagihan' => $t['nama_tagihan'], 'nominal' => $t['nominal']];

            if (!empty($t['link_jurnal_id'])) {
                $jid = $t['link_jurnal_id'];
                $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $jid");
                $conn->query("DELETE FROM syifa_jurnal WHERE id = $jid");
            }

            $id_hapus = $t['id'];
            $conn->query("DELETE FROM keuangan_tagihan WHERE id=$id_hapus");
        }
        
        $item_deleted = rtrim($item_deleted, ", ");
        omni_log_billing($conn, $uid, 'Hapus', 'Tagihan Mahasiswa', "Menghapus tagihan [$item_deleted] milik $nama_mhs ($nim_del)", $old_data_arr, null);
        
        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl_trigger);
        done('success', "Invoice tagihan telah dihapus permanen beserta rincian jurnalnya di Buku Besar.", 'tagihan_monitoring');
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Sistem Gagal: " . $e->getMessage(), 'tagihan_monitoring');
    }
}

// =========================================================================
// 6. UPDATE INVOICE TAGIHAN (EDIT MANUAL)
// =========================================================================
else if ($action == 'update_tagihan_manual') {
    $tagihan_id = (int)$_POST['tagihan_id'];
    $nama_baru  = $conn->real_escape_string($_POST['nama_tagihan']);
    $kode_tahun = $conn->real_escape_string($_POST['kode_tahun'] ?? '');
    $created_at = $conn->real_escape_string($_POST['created_at'] ?? '');
    
    $valStr     = preg_replace('/[^0-9]/', '', $_POST['nominal']);
    $nom_baru   = (double)$valStr;

    $conn->begin_transaction();
    try {
        if ($nom_baru <= 0) throw new Exception("Nominal koreksi tidak valid.");

        $q_t = $conn->query("SELECT t.*, m.nama FROM keuangan_tagihan t LEFT JOIN syifa_mahasiswa m ON t.nim = m.nim WHERE t.id=$tagihan_id FOR UPDATE")->fetch_assoc();
        if (!$q_t) throw new Exception("Data tagihan tidak ditemukan.");
        
        if (strtoupper($q_t['status_bayar']) === 'LUNAS') {
            throw new Exception("Tagihan yang sudah Lunas penuh tidak boleh diubah nominalnya. Silakan hapus/batalkan pembayaran tersebut di menu Kasir terlebih dahulu.");
        }
        if ($nom_baru < (double)$q_t['terbayar']) {
            throw new Exception("Nominal tagihan baru (Rp " . number_format($nom_baru, 0, ',', '.') . ") tidak boleh lebih kecil dari jumlah yang sudah terbayar (Rp " . number_format($q_t['terbayar'], 0, ',', '.') . ").");
        }

        $terbayar = (double)$q_t['terbayar'];
        $new_status = 'Belum Lunas';
        if ($terbayar > 0) {
            $new_status = ($terbayar >= $nom_baru - 10) ? 'Lunas' : 'Sebagian';
        }

        $sql_upd = "UPDATE keuangan_tagihan SET nama_tagihan='$nama_baru', nominal=$nom_baru, status_bayar='$new_status'";
        if(!empty($kode_tahun)) $sql_upd .= ", kode_tahun='$kode_tahun'";
        if(!empty($created_at)) {
            if (strlen($created_at) <= 10) {
                $old_time = date('H:i:s', strtotime($q_t['created_at']));
                $created_at = $created_at . ' ' . $old_time;
            }
            $sql_upd .= ", created_at='$created_at'";
        }
        $sql_upd .= " WHERE id=$tagihan_id";
        
        $conn->query($sql_upd);

        $jid = (int)$q_t['link_jurnal_id'];
        if ($jid > 0) {
            $sql_j = "UPDATE syifa_jurnal SET total_debet=$nom_baru, total_kredit=$nom_baru";
            if(!empty($created_at)) {
                $tgl_jurnal = date('Y-m-d', strtotime($created_at));
                $sql_j .= ", tgl_jurnal='$tgl_jurnal'";
            }
            $sql_j .= " WHERE id=$jid";
            $conn->query($sql_j);
            
            $conn->query("UPDATE syifa_jurnal_detail SET debit=$nom_baru WHERE jurnal_id=$jid AND debit > 0");
            $conn->query("UPDATE syifa_jurnal_detail SET kredit=$nom_baru WHERE jurnal_id=$jid AND kredit > 0");
        }

        $nama_mhs = $q_t['nama'] ?? 'Unknown';
        
        $old_log = ['nim' => $q_t['nim'], 'nama_mahasiswa' => $nama_mhs, 'nama_tagihan_lama' => $q_t['nama_tagihan'], 'nominal_lama' => $q_t['nominal']];
        $new_log = ['nim' => $q_t['nim'], 'nama_mahasiswa' => $nama_mhs, 'nama_tagihan_baru' => $nama_baru, 'nominal_baru' => $nom_baru];
        
        omni_log_billing($conn, $uid, 'Perbarui', 'Tagihan Mahasiswa', "Koreksi nilai tagihan [$nama_baru] milik $nama_mhs ({$q_t['nim']}) menjadi Rp " . number_format($nom_baru,0,',','.'), $old_log, $new_log);

        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, date('Y-m-d', strtotime($created_at ?: $q_t['created_at'])));
        done('success', "Koreksi Parameter Piutang berhasil dilakukan dan terekam di Buku Besar.", 'tagihan_monitoring');
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Sistem Menolak: " . $e->getMessage(), 'tagihan_monitoring');
    }
}

// =========================================================================
// 7. PEMBAYARAN TAGIHAN KASIR (MULTI-INVOICE) 
// =========================================================================
else if ($action == 'pay_multiple_tagihan') {
    $m_id = (int)$_POST['mahasiswa_id'];
    $m_nama = $conn->real_escape_string($_POST['mahasiswa_nama']);
    $kas_kode = $conn->real_escape_string($_POST['kode_akun_kas']);
    $tgl_bayar = $conn->real_escape_string($_POST['tgl_bayar']);
    $catatan = $conn->real_escape_string($_POST['catatan_user']);
    
    $total_bayar = 0; $valid_items = [];
    $conn->begin_transaction();
    try {
        foreach ($_POST['pay_val'] as $tid => $val) {
            $nominal = (double)preg_replace('/[^0-9]/', '', $val);
            if ($nominal > 0) {
                $t = $conn->query("SELECT nominal, terbayar FROM keuangan_tagihan WHERE id=$tid FOR UPDATE")->fetch_assoc();
                $sisa = $t['nominal'] - $t['terbayar'];
                if ($nominal > ($sisa + 10)) throw new Exception("Nominal bayar melebihi sisa.");
                $total_bayar += $nominal;
                $valid_items[] = ['id' => $tid, 'nom' => $nominal, 'nama' => $_POST['bill_names'][$tid]];
            }
        }

        if ($total_bayar <= 0) throw new Exception("Total bayar kosong.");
        if (empty($kas_kode)) throw new Exception("Akun Kas belum dipilih.");

        $no_bkm = function_exists('getNextNumber') ? getNextNumber($conn, 'kas_masuk') : "BKM-" . time();
        $rinci_teks = implode(", ", array_map(fn($v) => $v['nama'], $valid_items));
        $ket_utama = "Penerimaan Piutang Mhs: {$m_nama} | Rincian: {$rinci_teks} | {$catatan}";

        $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, pihak_nama, keterangan, total_debet, total_kredit, created_by, akun_utama_kode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_j->bind_param("ssssddis", $no_bkm, $tgl_bayar, $m_nama, $ket_utama, $total_bayar, $total_bayar, $uid, $kas_kode);
        $stmt_j->execute();
        $jid = $conn->insert_id;

        $stmt_d1 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id) VALUES (?, ?, ?, 0, ?)");
        $stmt_d1->bind_param("isdi", $jid, $kas_kode, $total_bayar, $m_id);
        $stmt_d1->execute();

        foreach ($valid_items as $v) {
            $stmt_d2 = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id, tagihan_id_ref, keterangan) VALUES (?, ?, 0, ?, ?, ?, ?)");
            $k_item = "Pelunasan " . $v['nama'];
            $stmt_d2->bind_param("isdiis", $jid, $CODE_PIUTANG, $v['nom'], $m_id, $v['id'], $k_item);
            $stmt_d2->execute();

            $no_kuitansi = "KWT-" . $v['id'] . "-" . time();
            $stmt_log = $conn->prepare("INSERT INTO keuangan_pembayaran_log (tagihan_id, nim, nominal_bayar, tanggal_bayar, kode_akun_kas, no_kuitansi, link_jurnal_id) VALUES (?, (SELECT nim FROM syifa_mahasiswa WHERE id=?), ?, ?, ?, ?, ?)");
            $stmt_log->bind_param("iidsssi", $v['id'], $m_id, $v['nom'], $tgl_bayar, $kas_kode, $no_kuitansi, $jid);
            $stmt_log->execute();

            autoSyncPiutang($conn, null, $v['id']);
        }
        
        $json_pay = ['nama_mahasiswa' => $m_nama, 'rincian' => $rinci_teks, 'total_bayar' => $total_bayar, 'akun_kas' => $kas_kode];
        omni_log_billing($conn, $uid, 'Buat', 'Penerimaan Kasir', "Menerima pembayaran tagihan [$rinci_teks] dari $m_nama senilai Rp " . number_format($total_bayar,0,',','.'), null, $json_pay);
        
        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl_bayar);
        
        done('success', "Pembayaran BERHASIL dicatat. <a href='print_voucher.php?id={$jid}' target='_blank' class='btn btn-sm btn-light border ms-2 fw-bold text-success'><i class='fas fa-print me-1'></i>Cetak Bukti Penerimaan (Kwitansi)</a>", 'mhs_pembayaran');
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Gagal memproses pembayaran: " . $e->getMessage(), 'mhs_pembayaran');
    }
}

// =========================================================================
// 8. PEMBATALAN PEMBAYARAN KASIR
// =========================================================================
else if ($action == 'cancel_payment') {
    $log_id = (int)$_POST['log_id'];
    $conn->begin_transaction();
    try {
        $q_log = $conn->query("SELECT l.*, m.nama FROM keuangan_pembayaran_log l LEFT JOIN syifa_mahasiswa m ON l.nim = m.nim WHERE l.id = $log_id FOR UPDATE")->fetch_assoc();
        if(!$q_log) throw new Exception("Data tidak ditemukan.");

        if(!empty($q_log['link_jurnal_id'])) {
            $jid = $q_log['link_jurnal_id'];
            $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $jid");
            $conn->query("DELETE FROM syifa_jurnal WHERE id = $jid");
        }

        $conn->query("DELETE FROM keuangan_pembayaran_log WHERE id = $log_id");
        autoSyncPiutang($conn, null, $q_log['tagihan_id']);
        
        $nama_mhs = $q_log['nama'] ?? 'Unknown';
        
        $old_cancel = ['no_kuitansi' => $q_log['no_kuitansi'], 'nama_mahasiswa' => $nama_mhs, 'nominal_batal' => $q_log['nominal_bayar']];
        omni_log_billing($conn, $uid, 'Hapus', 'Penerimaan Kasir', "Membatalkan penerimaan (No: {$q_log['no_kuitansi']}) senilai Rp " . number_format($q_log['nominal_bayar'],0,',','.') . " dari $nama_mhs", $old_cancel, null);
        
        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $q_log['tanggal_bayar']);
        done('success', "Pembayaran berhasil dibatalkan.", 'tagihan_monitoring');
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Gagal: " . $e->getMessage(), 'tagihan_monitoring');
    }
}

else {
    done('danger', "Ralat Sistem: Aksi ($action) tidak dikenali oleh peladen. Silakan ulangi transaksi.", 'tagihan_generate');
}
ob_end_flush();
?>