<?php
/**
 * hr_action.php - PUSAT KENDALI HRIS & PAYROLL TERPADU
 * Versi: 54.0 (Sovereign Grand Master - True JSON Payload Edition)
 * STATUS: 100% FULL CODE (TIDAK ADA PEMOTONGAN)
 * Perbaikan Mutlak: 
 * Memastikan parameter $old dan $new pada fungsi omni_log_hr
 * benar-benar dikirimkan dalam bentuk array agar bisa diuraikan di 
 * Pop-up Detail Riwayat.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (file_exists('mailer_engine.php')) { require_once 'mailer_engine.php'; }
if (file_exists('engine/GlobalLogger.php')) { require_once 'engine/GlobalLogger.php'; }

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid = (int)$_SESSION['user_id'];

$ACC_UTANG_GAJI_NETO = function_exists('getAccountCode') ? getAccountCode($conn, 'HUTANG_GAJI') : '2-1101'; 
if(!$ACC_UTANG_GAJI_NETO) $ACC_UTANG_GAJI_NETO = '2-1101';

$ACC_BEBAN_GAJI = function_exists('getAccountCode') ? getAccountCode($conn, 'BEBAN_GAJI') : '5-1001';
if(!$ACC_BEBAN_GAJI) $ACC_BEBAN_GAJI = '5-1001';

// 🚀 THE OMNI LOGGER LOCAL HELPER (UPDATED UNTUK ARRAY JSON)
function omni_log_hr($conn, $uid, $action, $module, $desc, $old_data = null, $new_data = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO sys_activity_log (user_id, action_type, module, target_table, record_id, description, old_data, new_data, created_at) VALUES (?, ?, ?, '-', 0, ?, ?, ?, NOW())");
        $o = $old_data ? json_encode($old_data) : null;
        $n = $new_data ? json_encode($new_data) : null;
        if($stmt) { $stmt->bind_param("isssss", $uid, $action, $module, $desc, $o, $n); $stmt->execute(); }
    } catch(Exception $e){}
}

function done($type, $msg, $url = null) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    $redirect = $url ? $url : "index.php?page=penggajian";
    header("Location: $redirect");
    exit;
}

// =========================================================================
// 1. MANAJEMEN MASTER (PEGAWAI, JABATAN, KOMPONEN, SETUP)
// =========================================================================
if ($action == 'save_pegawai') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $nip = $conn->real_escape_string($_POST['nip']);
    $nama = $conn->real_escape_string($_POST['nama_lengkap']);
    $jabatan = $conn->real_escape_string($_POST['jabatan']);
    $unit = $conn->real_escape_string($_POST['unit_kerja']);
    $status = $conn->real_escape_string($_POST['status_pegawai']);
    $aktif = (int)$_POST['status_aktif'];
    $rek = $conn->real_escape_string($_POST['rekening_bank']);
    $hp = $conn->real_escape_string($_POST['no_hp']);
    $email = $conn->real_escape_string($_POST['email'] ?? '');

    $new_pegawai = ['nip' => $nip, 'nama_pegawai' => $nama, 'jabatan' => $jabatan, 'unit_kerja' => $unit];

    if($id) {
        $stmt = $conn->prepare("UPDATE hr_pegawai SET nip=?, nama_lengkap=?, jabatan=?, unit_kerja=?, status_pegawai=?, status_aktif=?, rekening_bank=?, no_hp=?, email=? WHERE id=?");
        $stmt->bind_param("sssssisssi", $nip, $nama, $jabatan, $unit, $status, $aktif, $rek, $hp, $email, $id);
        omni_log_hr($conn, $uid, 'Perbarui', 'Data Pegawai', "Memperbarui profil pegawai: $nama (NIP: $nip)", null, $new_pegawai);
    } else {
        $stmt = $conn->prepare("INSERT INTO hr_pegawai (nip, nama_lengkap, jabatan, unit_kerja, status_pegawai, status_aktif, rekening_bank, no_hp, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssisss", $nip, $nama, $jabatan, $unit, $status, $aktif, $rek, $hp, $email);
        omni_log_hr($conn, $uid, 'Buat', 'Data Pegawai', "Mendaftarkan pegawai baru: $nama (NIP: $nip)", null, $new_pegawai);
    }
    
    if($stmt->execute()) done('success', "Data pegawai berhasil disimpan.", "index.php?page=pegawai");
    else done('danger', "Error: " . $conn->error, "index.php?page=pegawai");
}

if ($action == 'delete_pegawai') {
    $id = (int)$_GET['id'];
    $q = $conn->query("SELECT nama_lengkap, nip, unit_kerja FROM hr_pegawai WHERE id=$id")->fetch_assoc();
    $info = $q ? "{$q['nama_lengkap']} (NIP: {$q['nip']})" : "ID $id";
    
    $old_pegawai = ['nip' => $q['nip'], 'nama_pegawai' => $q['nama_lengkap'], 'unit_kerja' => $q['unit_kerja']];

    $conn->query("DELETE FROM hr_pegawai WHERE id=$id");
    omni_log_hr($conn, $uid, 'Hapus', 'Data Pegawai', "Menghapus data pegawai: $info", $old_pegawai, null);
    done('success', "Data pegawai dihapus.", "index.php?page=pegawai");
}

if ($action == 'import_pegawai') {
    if (!isset($_FILES['file_import']) || $_FILES['file_import']['error'] != UPLOAD_ERR_OK) {
        done('danger', 'Gagal mengunggah file CSV.', 'index.php?page=pegawai');
    }

    $tmp_file = $_FILES['file_import']['tmp_name'];
    $file_name = strtolower($_FILES['file_import']['name']);

    if (!str_ends_with($file_name, '.csv')) {
        done('danger', 'Format file harus .csv!', 'index.php?page=pegawai');
    }

    $content = file_get_contents($tmp_file);
    $content = preg_replace('/^[\xef\xbb\xbf]/', '', $content);
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = explode("\n", $content);

    $delim = ',';
    if (isset($lines[0]) && strpos($lines[0], ';') !== false) { $delim = ';'; } 
    elseif (isset($lines[0]) && strpos($lines[0], "\t") !== false) { $delim = "\t"; }

    $success_count = 0; $fail_count = 0;
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO hr_pegawai (nip, nama_lengkap, jabatan, unit_kerja, status_pegawai, status_aktif, no_hp, email) VALUES (?, ?, ?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE nama_lengkap=VALUES(nama_lengkap), jabatan=VALUES(jabatan), unit_kerja=VALUES(unit_kerja), status_pegawai=VALUES(status_pegawai), no_hp=VALUES(no_hp), email=VALUES(email)");

        foreach ($lines as $index => $line) {
            if ($index == 0 || trim($line) == '') continue; 
            $data = str_getcsv($line, $delim);
            
            $nip     = trim($data[0] ?? '');
            $nama    = trim($data[1] ?? '');
            $jabatan = trim($data[2] ?? '');
            $unit    = trim($data[3] ?? '');
            $status  = trim($data[4] ?? 'Tetap');
            $hp      = trim($data[5] ?? '');
            $email   = trim($data[6] ?? '');

            if (empty($nip) || empty($nama)) continue;

            $stmt->bind_param("sssssss", $nip, $nama, $jabatan, $unit, $status, $hp, $email);
            if ($stmt->execute()) { $success_count++; } else { $fail_count++; }
        }
        $json_imp = ['total_pegawai_terimport' => $success_count];
        omni_log_hr($conn, $uid, 'Import', 'Data Pegawai', "Melakukan Import Massal CSV sebanyak $success_count data pegawai.", null, $json_imp);
        
        $conn->commit();
        done('success', "Import Data CSV Berhasil! <b>$success_count pegawai</b> sukses masuk/diperbarui.", 'index.php?page=pegawai');
    } catch(Exception $e) {
        $conn->rollback();
        done('danger', 'Sistem Gagal Membaca Baris CSV: ' . $e->getMessage(), 'index.php?page=pegawai');
    }
}

if ($action == 'download_template_pegawai') {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Template_Import_Pegawai.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
    fputcsv($output, ['NIP / ID', 'NAMA LENGKAP', 'JABATAN', 'UNIT KERJA', 'STATUS KERJA (Tetap/Kontrak)', 'NO HP', 'EMAIL']);
    fputcsv($output, ['123456', 'Ahmad Dahlan', 'Staff IT', 'BAUK', 'Tetap', '08123456789', 'ahmad@yarsi.ac.id']);
    fclose($output); exit;
}

if ($action == 'save_jabatan') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $nama = $conn->real_escape_string($_POST['nama_jabatan']);
    $gol = $conn->real_escape_string($_POST['golongan']);
    $lvl = (int)$_POST['level_jabatan'];
    if($id) {
        $stmt = $conn->prepare("UPDATE hr_jabatan SET nama_jabatan=?, golongan=?, level_jabatan=? WHERE id=?");
        $stmt->bind_param("ssii", $nama, $gol, $lvl, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO hr_jabatan (nama_jabatan, golongan, level_jabatan) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $nama, $gol, $lvl);
    }
    $stmt->execute();
    done('success', "Data jabatan disimpan.", "index.php?page=hr_payroll_setup&tab=jabatan");
}

if ($action == 'delete_jabatan') {
    $conn->query("DELETE FROM hr_jabatan WHERE id=".(int)$_GET['id']);
    done('success', "Jabatan dihapus.", "index.php?page=hr_payroll_setup&tab=jabatan");
}

if ($action == 'save_komponen') {
    try {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $nama = trim($_POST['nama_komponen']);
        $jenis = $_POST['jenis'];
        $sifat = $_POST['sifat'];
        $akun = $_POST['kode_akun'];
        $akun_tipe = $_POST['akun_tipe'] ?? 'Beban';
        $is_thr = isset($_POST['is_thr_component']) ? 1 : 0;

        if($id) {
            $cek = $conn->query("SELECT COUNT(*) as c FROM hr_pegawai_komponen pk JOIN hr_payroll_detail d ON pk.pegawai_id = d.pegawai_id WHERE pk.komponen_id = $id")->fetch_assoc();
            if ($cek['c'] > 0) throw new Exception("Komponen ini sudah digunakan dalam transaksi Payroll. Tidak boleh diubah.");

            $stmt = $conn->prepare("UPDATE hr_komponen SET nama_komponen=?, jenis=?, sifat=?, kode_akun=?, akun_tipe=?, is_thr_component=? WHERE id=?");
            $stmt->bind_param("sssssii", $nama, $jenis, $sifat, $akun, $akun_tipe, $is_thr, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO hr_komponen (nama_komponen, jenis, sifat, kode_akun, akun_tipe, is_thr_component) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $nama, $jenis, $sifat, $akun, $akun_tipe, $is_thr);
        }
        
        if(!$stmt->execute()) {
            if ($conn->errno == 1062) throw new Exception("Gagal: Nama komponen '<b>{$nama}</b>' sudah terdaftar.");
            throw new Exception("Database Error: " . $stmt->error);
        }
        done('success', "Komponen gaji berhasil disimpan.", "index.php?page=hr_payroll_setup&tab=komponen");
    } catch (Exception $e) { done('danger', $e->getMessage(), "index.php?page=hr_payroll_setup&tab=komponen"); }
}

if ($action == 'delete_komponen') {
    $id = (int)$_GET['id'];
    $cek = $conn->query("SELECT COUNT(*) as c FROM hr_pegawai_komponen pk JOIN hr_payroll_detail d ON pk.pegawai_id = d.pegawai_id WHERE pk.komponen_id = $id")->fetch_assoc();
    if ($cek['c'] > 0) done('danger', "Akses Ditolak: Komponen ini sudah memiliki riwayat transaksi.", "index.php?page=hr_payroll_setup&tab=komponen");
    else {
        $conn->query("DELETE FROM hr_komponen WHERE id=$id");
        done('success', "Komponen dihapus permanen.", "index.php?page=hr_payroll_setup&tab=komponen");
    }
}

if ($action == 'save_pegawai_setup' || $action == 'save_payroll_setup') {
    $peg_id = (int)$_POST['pegawai_id'];
    $conn->begin_transaction();
    try {
        if($peg_id <= 0) throw new Exception("Pegawai tidak valid.");

        $peg_info = $conn->query("SELECT nip, nama_lengkap FROM hr_pegawai WHERE id=$peg_id")->fetch_assoc();
        $nama_pegawai = $peg_info['nama_lengkap'] ?? 'Unknown';
        $nip_pegawai = $peg_info['nip'] ?? '';

        $conn->query("DELETE FROM hr_pegawai_komponen WHERE pegawai_id = $peg_id");
        $stmt = $conn->prepare("INSERT INTO hr_pegawai_komponen (pegawai_id, komponen_id, nominal) VALUES (?, ?, ?)");
        $inserted = 0;

        if(!empty($_POST['inc_id'])) {
            foreach($_POST['inc_id'] as $i => $cid) {
                $cid = (int)$cid; 
                $nom = (double)str_replace(['.', ','], '', $_POST['inc_nominal'][$i]);
                if($cid > 0 && $nom > 0) { $stmt->bind_param("iid", $peg_id, $cid, $nom); $stmt->execute(); $inserted++; }
            }
        }
        if(!empty($_POST['ded_id'])) {
            foreach($_POST['ded_id'] as $i => $cid) {
                $cid = (int)$cid;
                $nom = (double)str_replace(['.', ','], '', $_POST['ded_nominal'][$i]);
                if($cid > 0 && $nom > 0) { $stmt->bind_param("iid", $peg_id, $cid, $nom); $stmt->execute(); $inserted++; }
            }
        }
        
        $json_setup = ['nama_pegawai' => $nama_pegawai, 'nip_pegawai' => $nip_pegawai, 'total_komponen_aktif' => $inserted];
        omni_log_hr($conn, $uid, 'Perbarui', 'Penggajian (HR)', "Menyimpan konfigurasi/setup Gaji untuk pegawai: $nama_pegawai", null, $json_setup);
        
        $conn->commit();
        done('success', "Konfigurasi gaji berhasil disimpan! ($inserted komponen aktif)", "index.php?page=hr_payroll_setup&tab=setup");
    } catch(Exception $e) {
        $conn->rollback();
        done('danger', "Gagal menyimpan konfigurasi: " . $e->getMessage(), "index.php?page=hr_payroll_setup&tab=setup");
    }
}

if ($action == 'get_setup') {
    $id = (int)$_GET['id'];
    $res = $conn->query("SELECT pk.*, k.jenis FROM hr_pegawai_komponen pk JOIN hr_komponen k ON pk.komponen_id = k.id WHERE pk.pegawai_id = $id");
    $data = ['income'=>[], 'deduct'=>[]];
    while($r = $res->fetch_assoc()) { if($r['jenis'] == 'Pendapatan') $data['income'][] = $r; else $data['deduct'][] = $r; }
    echo json_encode($data); exit;
}

if ($action == 'delete_pegawai_setup' || $action == 'delete_payroll_setup') {
    $id = (int)($_GET['id'] ?? $_GET['pegawai_id'] ?? 0);
    $peg_info = $conn->query("SELECT nip, nama_lengkap FROM hr_pegawai WHERE id=$id")->fetch_assoc();
    $nama_pegawai = $peg_info['nama_lengkap'] ?? 'Unknown';

    $conn->query("DELETE FROM hr_pegawai_komponen WHERE pegawai_id=$id");
    
    $json_del = ['nip_pegawai' => $peg_info['nip'], 'nama_pegawai' => $nama_pegawai];
    omni_log_hr($conn, $uid, 'Hapus', 'Penggajian (HR)', "Mengosongkan konfigurasi Gaji untuk pegawai: $nama_pegawai", $json_del, null);
    
    done('success', "Konfigurasi gaji dikosongkan.", "index.php?page=hr_payroll_setup&tab=setup");
}


// =========================================================================
// 2. OPERASIONAL PENGGAJIAN (PAYROLL)
// =========================================================================

if ($action == 'process_payroll') {
    $bulan = (int)$_POST['bulan'];
    $tahun = (int)$_POST['tahun'];
    $tgl_slip = $conn->real_escape_string($_POST['tgl_slip']);

    $conn->begin_transaction();
    try {
        $conn->query("INSERT IGNORE INTO hr_payroll_header (periode_bulan, periode_tahun, tgl_slip, status) VALUES ($bulan, $tahun, '$tgl_slip', 'Draft')");
        $pay_id = $conn->insert_id;
        if (!$pay_id) throw new Exception("Payroll untuk periode $bulan/$tahun sudah pernah dibuat sebelumnya.");

        $tot_bruto = 0; $tot_pot = 0; $tot_net = 0;
        $pegawai = $conn->query("SELECT id FROM hr_pegawai WHERE status_aktif = 1");
        while($p = $pegawai->fetch_assoc()) {
            $pid = $p['id'];
            $gapok = 0; $tunj = 0; $pot = 0;
            $komp = $conn->query("SELECT pk.nominal, k.jenis, k.sifat FROM hr_pegawai_komponen pk JOIN hr_komponen k ON pk.komponen_id=k.id WHERE pk.pegawai_id=$pid");
            while($k = $komp->fetch_assoc()) {
                if($k['jenis'] == 'Pendapatan') { if($k['sifat'] == 'Tetap') $gapok += $k['nominal']; else $tunj += $k['nominal']; } 
                else { $pot += $k['nominal']; }
            }
            $net = ($gapok + $tunj) - $pot;
            if($net > 0) {
                $stmt_d = $conn->prepare("INSERT INTO hr_payroll_detail (payroll_id, pegawai_id, gapok, tunjangan, potongan, gaji_bersih) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_d->bind_param("iidddd", $pay_id, $pid, $gapok, $tunj, $pot, $net);
                $stmt_d->execute();
                $tot_bruto += ($gapok + $tunj); $tot_pot += $pot; $tot_net += $net;
            }
        }
        $conn->query("UPDATE hr_payroll_header SET total_gross=$tot_bruto, total_potongan=$tot_pot, total_netto=$tot_net WHERE id=$pay_id");
        
        if(class_exists('GlobalLogger')) {
            $h_data = $conn->query("SELECT * FROM hr_payroll_header WHERE id=$pay_id")->fetch_assoc();
            GlobalLogger::log($conn, $uid, 'Buat', 'Kepegawaian (HR)', 'hr_payroll_header', $pay_id, "Generate Gaji Pegawai Periode $bulan/$tahun (Draft)", null, $h_data);
        }

        $conn->commit();
        done('success', "Generate gaji selesai (DRAFT).", "index.php?page=penggajian");
    } catch (Exception $e) { $conn->rollback(); done('danger', $e->getMessage(), "index.php?page=penggajian"); }
}

if ($action == 'pay_payroll') {
    $pay_id = (int)$_POST['payroll_id'];
    $tgl_bayar = $conn->real_escape_string($_POST['tgl_bayar']);
    $akun_kas = $conn->real_escape_string($_POST['kode_akun_kas']);

    $conn->begin_transaction();
    try {
        $h = $conn->query("SELECT * FROM hr_payroll_header WHERE id=$pay_id FOR UPDATE")->fetch_assoc();
        if(strtoupper($h['status']) != 'FINAL') throw new Exception("Hanya payroll berstatus FINAL yang bisa dibayarkan.");

        $no_jurnal = function_exists('getNextNumber') ? getNextNumber($conn, 'kas_keluar') : 'BKK-PAY-'.time();
        $ket = "Pembayaran Gaji Pegawai Periode " . $h['periode_bulan'] . "/" . $h['periode_tahun'];
        $src_mod = 'PAYROLL';
        $pihak = 'Seluruh Pegawai';

        $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, pihak_nama, keterangan, total_debet, total_kredit, created_by, akun_utama_kode, source_module, source_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_j->bind_param("ssssddissi", $no_jurnal, $tgl_bayar, $pihak, $ket, $h['total_netto'], $h['total_netto'], $uid, $akun_kas, $src_mod, $pay_id);
        $stmt_j->execute();
        $jid = $conn->insert_id;

        $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit) VALUES ($jid, '$ACC_UTANG_GAJI_NETO', {$h['total_netto']}, 0)");
        $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit) VALUES ($jid, '$akun_kas', 0, {$h['total_netto']})");

        $conn->query("UPDATE hr_payroll_header SET status='PAID', pembayaran_jurnal_id=$jid WHERE id=$pay_id");

        if(class_exists('GlobalLogger')) {
            $j_data = $conn->query("SELECT * FROM syifa_jurnal WHERE id=$jid")->fetch_assoc();
            GlobalLogger::log($conn, $uid, 'Buat', 'Kepegawaian (HR)', 'syifa_jurnal', $jid, "Pelunasan Pembayaran Gaji Periode " . $h['periode_bulan'] . "/" . $h['periode_tahun'], null, $j_data);
        }

        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, ($tgl_bayar ?? date('Y-m-d')));
        done('success', "Pembayaran gaji berhasil diposting.", "index.php?page=penggajian&tab=bayar");
    } catch (Exception $e) { $conn->rollback(); done('danger', "Gagal bayar: " . $e->getMessage(), "index.php?page=penggajian&tab=bayar"); }
}

if (in_array($action, ['finalize_payroll', 'cancel_payroll', 'delete_payroll', 'cancel_payment'])) {
    $id = (int)$_POST['id'];
    $conn->begin_transaction();
    try {
        $h = $conn->query("SELECT * FROM hr_payroll_header WHERE id = $id FOR UPDATE")->fetch_assoc();
        $tgl_trigger = $h['tgl_slip'] ?? date('Y-m-d'); 
        
        if ($action == 'finalize_payroll') {
            if(strtoupper($h['status']) != 'DRAFT') throw new Exception("Hanya Draft yang bisa di-Finalize.");
            
            $periode_text = $h['periode_bulan'] . "/" . $h['periode_tahun'];
            $sql = "SELECT k.kode_akun, k.jenis, SUM(pk.nominal) as total, k.akun_tipe, k.nama_komponen
                    FROM hr_payroll_detail d
                    JOIN hr_pegawai_komponen pk ON d.pegawai_id = pk.pegawai_id
                    JOIN hr_komponen k ON pk.komponen_id = k.id
                    WHERE d.payroll_id = ?
                    GROUP BY k.kode_akun, k.jenis, k.akun_tipe, k.nama_komponen
                    ORDER BY k.kode_akun";

            $stmt_komp = $conn->prepare($sql); $stmt_komp->bind_param("i", $id); $stmt_komp->execute(); $result = $stmt_komp->get_result();
            if ($result->num_rows == 0) throw new Exception("Payroll belum digenerate atau rincian komponen kosong.");

            $no_jurnal = function_exists('getNextNumber') ? getNextNumber($conn, 'auto_jurnal') : 'GJ-'.time();
            $tanggal = $h['tgl_slip']; $ket = "Jurnal Payroll Periode " . $periode_text;
            $pihak_global = 'Global Payroll'; $src_mod = 'PAYROLL';

            $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (tgl_jurnal, no_jurnal, pihak_nama, keterangan, created_by, created_at, source_module, source_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
            $stmt_j->bind_param("ssssisi", $tanggal, $no_jurnal, $pihak_global, $ket, $uid, $src_mod, $id);
            $stmt_j->execute();
            $jurnal_id = $conn->insert_id;

            $total_debit = 0; $total_kredit = 0;
            $stmt_detail = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, keterangan) VALUES (?, ?, ?, ?, ?)");

            while ($row = $result->fetch_assoc()) {
                $kode_akun = $row['kode_akun']; $nominal = (float)$row['total']; $tipe = $row['akun_tipe'];
                $debit = ($tipe == 'Beban') ? $nominal : 0;
                $kredit = ($tipe != 'Beban') ? $nominal : 0;
                $total_debit += $debit; $total_kredit += $kredit;

                if ($nominal > 0) {
                    $ket_item = $ket . " (" . $row['nama_komponen'] . ")";
                    $stmt_detail->bind_param("isdds", $jurnal_id, $kode_akun, $debit, $kredit, $ket_item);
                    $stmt_detail->execute();
                }
            }

            $selisih = $total_debit - $total_kredit;

            if ($selisih != 0) {
                $debit_h = ($selisih < 0) ? abs($selisih) : 0;
                $kredit_h = ($selisih > 0) ? $selisih : 0;
                $total_debit += $debit_h; $total_kredit += $kredit_h;
                
                $ket_hutang = "Hutang Gaji Nett Periode " . $periode_text;
                $stmt_detail->bind_param("isdds", $jurnal_id, $ACC_UTANG_GAJI_NETO, $debit_h, $kredit_h, $ket_hutang);
                $stmt_detail->execute();
            }

            $conn->query("UPDATE syifa_jurnal SET total_debet = $total_debit, total_kredit = $total_kredit WHERE id = $jurnal_id");
            $conn->query("UPDATE hr_payroll_detail SET link_jurnal_id = $jurnal_id WHERE payroll_id = $id");
            $conn->query("UPDATE hr_payroll_header SET status = 'Final' WHERE id = $id");
            
            if(class_exists('GlobalLogger')) {
                $j_data = $conn->query("SELECT * FROM syifa_jurnal WHERE id=$jurnal_id")->fetch_assoc();
                GlobalLogger::log($conn, $uid, 'Perbarui', 'Kepegawaian (HR)', 'syifa_jurnal', $jurnal_id, "Finalisasi Penggajian (Post Jurnal Beban & Hutang) Periode $periode_text", null, $j_data);
            }
        }
        
        if($action == 'cancel_payment') {
            $status_sekarang = strtoupper(trim($h['status']));
            if(!in_array($status_sekarang, ['PAID', 'LUNAS', 'DIBAYAR', ''])) throw new Exception("Status saat ini tidak sah untuk pembatalan.");
            if(!empty($h['pembayaran_jurnal_id'])) {
                $pjid = (int)$h['pembayaran_jurnal_id'];
                $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $pjid");
                $conn->query("DELETE FROM syifa_jurnal WHERE id = $pjid");
            }
            $conn->query("UPDATE hr_payroll_header SET status = 'Final', pembayaran_jurnal_id = NULL WHERE id = $id");
            
            $json_cancel = ['periode_bulan' => $h['periode_bulan'], 'periode_tahun' => $h['periode_tahun']];
            omni_log_hr($conn, $uid, 'Perbarui', 'Kepegawaian (HR)', "Membatalkan pelunasan kas gaji periode {$h['periode_bulan']}/{$h['periode_tahun']}", $json_cancel, null);
        }
        
        if($action == 'cancel_payroll' || $action == 'delete_payroll') {
            $res_j = $conn->query("SELECT DISTINCT link_jurnal_id FROM hr_payroll_detail WHERE payroll_id = $id AND link_jurnal_id IS NOT NULL");
            if ($res_j) { while($jr = $res_j->fetch_assoc()) { $ljid = (int)$jr['link_jurnal_id']; $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $ljid"); $conn->query("DELETE FROM syifa_jurnal WHERE id = $ljid"); } }
            $conn->query("UPDATE hr_payroll_detail SET link_jurnal_id = NULL WHERE payroll_id = $id");
            $conn->query("UPDATE hr_payroll_header SET status = 'Draft' WHERE id = $id");
        }
        
        if($action == 'delete_payroll') { 
            $conn->query("DELETE FROM hr_payroll_detail WHERE payroll_id = $id"); 
            $conn->query("DELETE FROM hr_payroll_header WHERE id = $id"); 
            
            $json_del_p = ['periode_bulan' => $h['periode_bulan'], 'periode_tahun' => $h['periode_tahun']];
            omni_log_hr($conn, $uid, 'Hapus', 'Kepegawaian (HR)', "Menghapus draf generate gaji periode {$h['periode_bulan']}/{$h['periode_tahun']}", $json_del_p, null);
        }

        $conn->commit();
        if (function_exists('triggerEventLedger')) triggerEventLedger($conn, $tgl_trigger);
        
        $target_tab = ($action == 'cancel_payment') ? 'bayar' : 'proses';
        done('success', "Prosedur [$action] berhasil.", "index.php?page=penggajian&tab=$target_tab");
    } catch (Exception $e) { $conn->rollback(); done('danger', "Sistem Menolak: " . $e->getMessage(), "index.php?page=penggajian"); }
}

// =========================================================================
// 🚀 3. FITUR KIRIM SLIP GAJI VIA EMAIL
// =========================================================================
if ($action == 'send_slip_email') {
    $ids = $_POST['slip_ids'] ?? [];
    if(empty($ids)) { done('danger', "Tidak ada slip gaji yang dipilih untuk dikirim.", "index.php?page=penggajian&tab=slip"); }
    
    if (!file_exists(__DIR__ . '/assets/phpmailer/src/PHPMailer.php') || !file_exists('mailer_engine.php')) {
        done('warning', "Mesin PHPMailer belum dikonfigurasi dengan sempurna. Harap unggah folder 'phpmailer' ke direktori 'assets/' Anda sesuai panduan.", "index.php?page=penggajian&tab=slip");
    }
    
    include_once 'mailer_engine.php';

    $profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
    $inst_name = $profile['institution_name'] ?? 'Institusi Kami';
    
    $custom_email_text_raw = $_POST['custom_email_text'] ?? '';
    
    $bulan_nama = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    $success = 0; $failed = 0;
    $sent_names = [];
    
    foreach($ids as $detail_id) {
        $detail_id = (int)$detail_id;
        $sql = "SELECT d.*, p.nama_lengkap, p.email, p.nip, h.periode_bulan, h.periode_tahun 
                FROM hr_payroll_detail d 
                JOIN hr_pegawai p ON d.pegawai_id = p.id 
                JOIN hr_payroll_header h ON d.payroll_id = h.id 
                WHERE d.id = $detail_id";
        $q_data = $conn->query($sql);
        $d = $q_data->fetch_assoc();
        
        if(!$d || empty($d['email'])) { $failed++; continue; }
        
        $periode = $bulan_nama[(int)$d['periode_bulan']] . " " . $d['periode_tahun'];
        $subject = "Pemberitahuan Slip Gaji $periode - " . strtoupper($inst_name);
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $current_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        $slip_link = $protocol . $domainName . $current_path . "/print_slip_gaji.php?id=" . $d['id'];

        $custom_text_parsed = str_ireplace('[PERIODE]', "<b>$periode</b>", htmlspecialchars($custom_email_text_raw));
        $custom_text_parsed = nl2br($custom_text_parsed);

        $body = "
        <div style='font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.6; padding: 20px; background-color: #f8fafc; border-top: 5px solid #10b981; border-radius: 8px;'>
            <div style='margin-bottom: 20px; color: #1e293b;'>$custom_text_parsed</div>
            <p>Silakan klik tautan aman di bawah ini untuk melihat dan mengunduh lampiran Slip Gaji (PDF) Anda:</p>
            <div style='margin: 25px 0;'><a href='$slip_link' target='_blank' style='background-color: #059669; color: white; padding: 12px 25px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>UNDUH LAMPIRAN SLIP GAJI (PDF)</a></div>
            <p style='font-size: 11px; color: #94a3b8; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 30px;'>Pesan ini dikirim secara otomatis oleh Sistem HRIS & Payroll $inst_name.<br>Harap tidak membalas email ini.</p>
        </div>";
        
        if (function_exists('kirim_email_smtp')) {
            $send = kirim_email_smtp($conn, $d['email'], $d['nama_lengkap'], $subject, $body);
            if($send) { 
                $success++; 
                $sent_names[] = $d['nama_lengkap']; 
            } else { 
                $failed++; 
            }
        } else {
            $failed++;
        }
    }
    
    if ($success > 0) {
        $nama_text = count($sent_names) <= 3 ? implode(', ', $sent_names) : $sent_names[0] . ", " . $sent_names[1] . ", dkk";
        $json_email = ['nama_pegawai' => $nama_text, 'jumlah_terkirim' => $success];
        omni_log_hr($conn, $uid, 'Buat', 'Kepegawaian (HR)', "Berhasil mengirim Slip Gaji via Email kepada $success pegawai ($nama_text)", null, $json_email);
    }
    
    $msg = "<b>Selesai!</b> $success Slip Gaji berhasil dikirim.";
    if($failed > 0) $msg .= "<br><small><i>Gagal mengirim $failed slip (Email pegawai kosong atau terjadi kesalahan pada SMTP/Sandi Aplikasi).</i></small>";
    
    done('success', $msg, "index.php?page=penggajian&tab=slip");
}

ob_end_flush();
?>