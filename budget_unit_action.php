<?php
/**
 * budget_unit_action.php - UNIT & MAPPING CONTROLLER SYIFA ERP
 * Versi: 156.3 (Sovereign Grand Master - Notification Fix Edition)
 * Perbaikan Mutlak: 
 * Menambahkan trigger sendBellNotificationLocal() pada aksi 'submit_report_to_arsip' 
 * agar Checker langsung mendapatkan notifikasi lonceng saat unit mengirim laporan.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$uid = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ??? AUTO-HEALER MUTLAK: Bebaskan status dari jeratan ENUM database lama
@$conn->query("ALTER TABLE anggaran_unit_pengajuan MODIFY COLUMN status VARCHAR(50) DEFAULT 'DRAFT'");
@$conn->query("ALTER TABLE anggaran_unit_reports MODIFY COLUMN status VARCHAR(50) DEFAULT 'DRAFT'");
@$conn->query("ALTER TABLE anggaran_unit_reports ADD COLUMN IF NOT EXISTS file_bukti VARCHAR(255) NULL");

// ??? GHOST SWEEPER: Memperbaiki status pengajuan yang sempat blank/kosong akibat error DB
@$conn->query("UPDATE anggaran_unit_pengajuan SET status = 'SUBMITTED' WHERE status = ''");

function unit_done($type, $msg, $tab = 'monitoring', $extra = '') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    $page = $_POST['return_page'] ?? $_GET['return_page'] ?? 'anggaran_unit';
    header("Location: index.php?page=$page&tab=$tab" . $extra);
    exit;
}

if(!function_exists('safeQuerySum')){
    function safeQuerySum($conn, $sql) {
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) { $r = $res->fetch_row(); return (double)($r[0] ?? 0); }
        return 0;
    }
}

if(!function_exists('cleanNum')){ function cleanNum($val) { return (double)str_replace(['.', ','], '', $val ?? '0'); } }

function sendBellNotificationLocal($conn, $judul, $pesan, $url, $target_group = 'checker', $specific_user_id = null) {
    if ($specific_user_id !== null) {
        $sid = (int)$specific_user_id;
        $stmt = $conn->prepare("INSERT INTO syifa_notifications (user_id, judul, pesan, url, action_url, is_read, status, created_at) VALUES (?, ?, ?, ?, ?, 0, 'unread', NOW())");
        if ($stmt) {
            $stmt->bind_param("issss", $sid, $judul, $pesan, $url, $url);
            $stmt->execute();
        }
        return;
    }

    $role_filter = "u.jabatan_workflow = 'ALL' OR r.role_name = 'Superadmin' OR r.role_name = 'SUPERADMIN'";
    if ($target_group == 'checker') {
        $role_filter .= " OR u.jabatan_workflow = 'CHECKER'";
    }
    if ($target_group == 'approver') {
        $role_filter .= " OR u.jabatan_workflow = 'APPROVER' OR u.jabatan_workflow = 'PIMPINAN'";
    }
    
    $sql = "SELECT u.id FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE ($role_filter) AND u.status = 1";
    $res = $conn->query($sql);
    
    if ($res && $res->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO syifa_notifications (user_id, judul, pesan, url, action_url, is_read, status, created_at) VALUES (?, ?, ?, ?, ?, 0, 'unread', NOW())");
        if ($stmt) {
            while($u = $res->fetch_assoc()) {
                $stmt->bind_param("issss", $u['id'], $judul, $pesan, $url, $url);
                $stmt->execute();
            }
        }
    }
}

switch ($action) {
    case 'save_unit_mapping':
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $nama = $conn->real_escape_string($_POST['nama_unit']);
        $kas_bank = $_POST['kas_bank_akun'];
        $kode = $conn->real_escape_string($_POST['kode_unit']);
        $status = isset($_POST['status']) ? (int)$_POST['status'] : 1;
        $mapped = json_decode($_POST['coa_real_json'] ?? '[]', true);
        
        if($id) {
            $conn->query("UPDATE m_unit SET nama_unit='$nama', kode_unit='$kode', kas_bank_akun='$kas_bank', status=$status WHERE id=$id");
            $conn->query("DELETE FROM unit_coa_map WHERE unit_id=$id");
        } else {
            $conn->query("INSERT INTO m_unit (kode_unit, nama_unit, kas_bank_akun, status) VALUES ('$kode', '$nama', '$kas_bank', $status)");
            $id = $conn->insert_id;
        }
        
        if(is_array($mapped)) {
            foreach($mapped as $c) {
                $c_esc = $conn->real_escape_string($c);
                $conn->query("INSERT INTO unit_coa_map (unit_id, kode_akun) VALUES ($id, '$c_esc')");
            }
        }
        unit_done('success', 'Data unit dan mapping COA berhasil disimpan.', 'manajemen');
        break;

    case 'delete_unit':
        $id = (int)$_GET['id'];
        $conn->query("DELETE FROM unit_coa_map WHERE unit_id=$id");
        $conn->query("DELETE FROM m_unit WHERE id=$id");
        unit_done('success', 'Unit berhasil dihapus secara permanen.', 'manajemen');
        break;

    case 'save_pengajuan':
        $tahun_p = (int)$_POST['tahun_pengajuan'];
        $u_id = (int)$_POST['unit_id'];
        $master_prog = $conn->real_escape_string($_POST['master_program']);
        
        $status_target = 'DRAFT';
        $kode_trx = "RPU-".$tahun_p."-".str_pad($u_id, 2, '0', STR_PAD_LEFT)."-".time();
        $jenis_pengajuan = 'TAMBAHAN_PAGU';

        if(!empty($_POST['uraian'])) {
            $stmt = $conn->prepare("
                INSERT INTO anggaran_unit_pengajuan
                (tahun, unit_id, kode_transaksi, program, kegiatan, coa_kode, jumlah_pengajuan, jenis_pengajuan, status, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
            ");

            foreach($_POST['uraian'] as $k => $ur) {
                $uraian = trim($ur);
                $coa = $_POST['coa_kode'][$k] ?? '';
                $jml = cleanNum($_POST['nominal'][$k] ?? 0);
                if(empty($uraian) || $jml <= 0) continue;
                
                $stmt->bind_param("iissssdsii", $tahun_p, $u_id, $kode_trx, $master_prog, $uraian, $coa, $jml, $jenis_pengajuan, $status_target, $uid);
                $stmt->execute();
            }
        }
        unit_done('success', 'Draf usulan pengajuan dana unit berhasil disimpan.', 'input');
        break;

    case 'save_proposal_bulk':
        $tahun_p = (int)$_POST['tahun'];
        $u_id = (int)$_POST['unit_id'];
        
        $status_target = $_POST['status_to'] ?? 'DRAFT'; 
        
        $master_prog = $conn->real_escape_string($_POST['master_program']);
        $jenis_pengajuan = $_POST['jenis_pengajuan'] ?? 'TAMBAHAN_PAGU';
        $edit_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        
        $u = $conn->query("SELECT nama_unit FROM m_unit WHERE id = $u_id")->fetch_assoc();
        $nama_unit = $u['nama_unit'] ?? 'Unit';

        if ($edit_id) {
            if(!empty($_POST['coa_kode'])) {
                $coa = $conn->real_escape_string($_POST['coa_kode'][0]);
                $uraian = $conn->real_escape_string($_POST['uraian'][0]);
                $jml = cleanNum($_POST['jumlah'][0]);
                
                $conn->query("UPDATE anggaran_unit_pengajuan SET tahun=$tahun_p, unit_id=$u_id, program='$master_prog', kegiatan='$uraian', coa_kode='$coa', jumlah_pengajuan=$jml, jenis_pengajuan='$jenis_pengajuan', status='$status_target', updated_at=NOW() WHERE id=$edit_id");
            }
        } else {
            if(!empty($_POST['coa_kode'])) {
                $stmt = $conn->prepare("
                    INSERT INTO anggaran_unit_pengajuan
                    (tahun, unit_id, kode_transaksi, program, kegiatan, coa_kode, jumlah_pengajuan, jenis_pengajuan, status, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
                ");

                foreach($_POST['coa_kode'] as $i => $coa) {
                    if(empty($coa)) continue;
                    $uraian = trim($_POST['uraian'][$i]);
                    $jml = cleanNum($_POST['jumlah'][$i]);
                    $kode_trx = "BU-" . $tahun_p . "-" . sprintf("%04d", rand(0, 9999));
                    
                    $stmt->bind_param("iissssdsii", $tahun_p, $u_id, $kode_trx, $master_prog, $uraian, $coa, $jml, $jenis_pengajuan, $status_target, $uid);
                    $stmt->execute();
                }
            }
        }

        if ($status_target == 'SUBMITTED') {
            sendBellNotificationLocal($conn, "Pengajuan Anggaran Baru", "Unit $nama_unit mengajukan usulan anggaran: $master_prog", "index.php?page=anggaran_unit&tab=approval", "checker");
            unit_done('success', 'Usulan pengajuan telah dikirim ke Pusat untuk divalidasi.', 'input');
        } else {
            unit_done('success', 'Draf usulan pengajuan berhasil disimpan.', 'input');
        }
        break;

    case 'submit_pengajuan':
        $id = (int)$_GET['id'];
        
        $conn->query("
            UPDATE anggaran_unit_pengajuan 
            SET status='SUBMITTED',
            submitted_at = NOW(),
            updated_at = NOW()
            WHERE id=$id
        ");
        
        $q = $conn->query("SELECT * FROM anggaran_unit_pengajuan WHERE id=$id")->fetch_assoc();
        if ($q) {
            $u = $conn->query("SELECT nama_unit FROM m_unit WHERE id={$q['unit_id']}")->fetch_assoc();
            $nama_unit = $u['nama_unit'] ?? 'Unit';
            sendBellNotificationLocal($conn, "Pengajuan Anggaran Baru", "Unit $nama_unit mengajukan usulan anggaran: {$q['program']}", "index.php?page=anggaran_unit&tab=approval", "checker");
        }
        unit_done('success', 'Pengajuan berhasil dikirim untuk divalidasi Checker.', 'input');
        break;

    case 'delete_pengajuan':
        $id = (int)$_GET['id'];
        $conn->query("DELETE FROM anggaran_unit_pengajuan WHERE id=$id");
        unit_done('success', 'Draf pengajuan dana dihapus permanen.', 'input');
        break;

    case 'workflow_action':
        $id = (int)$_POST['id'];
        $decision = $_POST['decision']; 
        $jml_disetujui = cleanNum($_POST['jumlah_disetujui'] ?? 0);
        
        $catatan = $conn->real_escape_string($_POST['catatan_approval'] ?? '');
        $uid_actor = $_SESSION['user_id'];

        $u_wf_query = $conn->query("SELECT u.jabatan_workflow, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = $uid_actor");
        $u_wf_data = $u_wf_query->fetch_assoc();
        $wf_auth = strtoupper($u_wf_data['jabatan_workflow'] ?? '');
        $role_name_upper = strtoupper($u_wf_data['role_name'] ?? '');
        $is_superadmin_root = ($_SESSION['role_id'] == 1 || $role_name_upper == 'SUPERADMIN');

        $q = $conn->query("SELECT * FROM anggaran_unit_pengajuan WHERE id=$id")->fetch_assoc();
        if ($q) {
            $curr_status = strtoupper($q['status']);
            if ($curr_status == '') { $curr_status = 'SUBMITTED'; }
            
            $catatan_msg = $catatan ? " Catatan: " . $catatan : "";

            $is_allowed = false;
            if ($curr_status == 'SUBMITTED' && (in_array($wf_auth, ['CHECKER', 'ALL']) || $is_superadmin_root)) $is_allowed = true;
            if ($curr_status == 'CHECKED' && (in_array($wf_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root)) $is_allowed = true;

            if (!$is_allowed) {
                unit_done('danger', "Akses Ditolak: Otoritas Workflow Anda ($wf_auth) tidak diizinkan mengeksekusi tahapan ini.", 'approval');
            }
            
            if ($decision == 'REJECT') {
                $conn->query("UPDATE anggaran_unit_pengajuan SET status='REJECTED', catatan_approval='$catatan', updated_at=NOW() WHERE id=$id");
                sendBellNotificationLocal($conn, "Pengajuan Ditolak", "Usulan {$q['program']} ditolak." . $catatan_msg, "index.php?page=anggaran_unit&tab=input", "unit_maker", $q['created_by']);
                unit_done('info', "Pengajuan ditolak dan dikembalikan ke Unit.", 'approval');
            } else {
                if ($curr_status == 'SUBMITTED') {
                    $conn->query("UPDATE anggaran_unit_pengajuan SET status='CHECKED', jumlah_disetujui=$jml_disetujui, catatan_approval='$catatan', updated_at=NOW() WHERE id=$id");
                    sendBellNotificationLocal($conn, "Menunggu Otorisasi Final", "Usulan {$q['program']} telah divalidasi Checker dan menunggu ketuk palu Pimpinan.", "index.php?page=anggaran_unit&tab=approval", "approver");
                    unit_done('success', "Pengajuan divalidasi Checker (Masuk Status CHECKED). Diteruskan ke Antrean Pimpinan.", 'approval');
                } else if ($curr_status == 'CHECKED') {
                    $conn->begin_transaction();
                    try {
                        $uraian = "Tambahan Pagu: " . $q['program'] . " - " . $q['kegiatan'];
                        $stmt_pagu = $conn->prepare("INSERT INTO syifa_budgets (kode_akun, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, ref_pengajuan_id, uraian_manual, created_at, created_by, is_category) VALUES (?, ?, 'Pengeluaran', 'Operasional', ?, 'Disetujui', 'UNIT_APPROVED', ?, ?, NOW(), ?, 0)");
                        $stmt_pagu->bind_param("ssdiss", $q['coa_kode'], $q['tahun'], $jml_disetujui, $id, $uraian, $uid_actor);
                        $stmt_pagu->execute();
                        
                        $conn->query("UPDATE anggaran_unit_pengajuan SET status='APPROVED', jumlah_disetujui=$jml_disetujui, catatan_approval='$catatan', approved_at=NOW(), updated_at=NOW() WHERE id=$id");
                        $conn->commit();
                        
                        sendBellNotificationLocal($conn, "Anggaran Disetujui", "Usulan {$q['program']} disetujui Pimpinan." . $catatan_msg, "index.php?page=anggaran_unit&tab=dashboard", "unit_maker", $q['created_by']);
                        unit_done('success', "Pengajuan final disetujui Pimpinan (APPROVED) dan masuk ke Pagu Anggaran.", 'approval');
                    } catch (Exception $e) {
                        $conn->rollback();
                        unit_done('danger', "Terjadi kesalahan saat memposting ke Buku Pagu.", 'approval');
                    }
                }
            }
        }
        break;

    case 'cancel_workflow_decision':
        $id = (int)$_GET['id'];
        $tahun = $_GET['tahun'] ?? date('Y');
        $uid_actor = $_SESSION['user_id'];
        
        $u_wf_query = $conn->query("SELECT u.jabatan_workflow, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = $uid_actor");
        $u_wf_data = $u_wf_query->fetch_assoc();
        $wf_auth = strtoupper($u_wf_data['jabatan_workflow'] ?? '');
        $role_name_upper = strtoupper($u_wf_data['role_name'] ?? '');
        $is_superadmin_root = ($_SESSION['role_id'] == 1 || $role_name_upper == 'SUPERADMIN');

        $conn->begin_transaction();
        try {
            $q = $conn->query("SELECT status, program FROM anggaran_unit_pengajuan WHERE id=$id FOR UPDATE")->fetch_assoc();
            if (!$q) throw new Exception("Data pengajuan tidak ditemukan.");
            
            $status_lama = strtoupper($q['status']);
            
            if (!in_array($status_lama, ['CHECKED', 'APPROVED'])) {
                throw new Exception("Hanya keputusan CHECKED / APPROVED yang dapat dibatalkan.");
            }

            if ($status_lama == 'APPROVED' && !in_array($wf_auth, ['APPROVER', 'PIMPINAN', 'ALL']) && !$is_superadmin_root) {
                throw new Exception("Akses Ditolak: Hanya Otoritas Pimpinan/Approver yang berhak membatalkan palu final (APPROVED).");
            }
            if ($status_lama == 'CHECKED' && !in_array($wf_auth, ['CHECKER', 'ALL']) && !$is_superadmin_root) {
                throw new Exception("Akses Ditolak: Hanya Otoritas Checker yang berhak membatalkan hasil validasinya (CHECKED).");
            }

            if ($status_lama == 'APPROVED') {
                $status_baru = 'CHECKED'; 
                $conn->query("DELETE FROM syifa_budgets WHERE ref_pengajuan_id = $id AND sumber_data = 'UNIT_APPROVED'");
                $conn->query("UPDATE anggaran_unit_pengajuan SET status='$status_baru', approved_at=NULL, updated_at=NOW() WHERE id=$id");
            } else {
                $status_baru = 'SUBMITTED';
                $conn->query("UPDATE anggaran_unit_pengajuan SET status='$status_baru', jumlah_disetujui=NULL, updated_at=NOW() WHERE id=$id");
            }
            
            try {
                $ket_audit = "Keputusan dibatalkan, dikembalikan ke proses sebelumnya.";
                $conn->query("INSERT INTO anggaran_unit_history (pengajuan_id, status_lama, status_baru, action_by, action_at, keterangan) VALUES ($id, '$status_lama', '$status_baru', $uid_actor, NOW(), '$ket_audit')");
            } catch(Exception $e) {}

            $conn->commit();
            unit_done('warning', "Keputusan dibatalkan secara hierarkis. Pengajuan {$q['program']} dikembalikan ke status $status_baru.", "approval", "&tahun=$tahun");
        } catch (Exception $e) {
            $conn->rollback();
            unit_done('danger', "Gagal membatalkan keputusan: " . $e->getMessage(), "approval");
        }
        break;

    // =========================================================================
    // 3. MONITORING LAPORAN MUTASI KAS (DENGAN EARLY UPLOAD)
    // =========================================================================
    case 'save_new_report':
        $target_unit_id = (int)$_POST['target_unit_id'];
        $nama_laporan   = $conn->real_escape_string($_POST['nama_laporan']);
        $tgl_mulai      = $conn->real_escape_string($_POST['tgl_mulai']);
        $tgl_selesai    = $conn->real_escape_string($_POST['tgl_selesai']);
        $tahun_r        = date('Y', strtotime($tgl_mulai));

        $unit = $conn->query("SELECT * FROM m_unit WHERE id=$target_unit_id")->fetch_assoc();
        if(!$unit) unit_done('danger', 'Unit tidak ditemukan.', 'monitoring');

        $akun_kas = $unit['kas_bank_akun'];
        
        $q_awal = $conn->query("SELECT SUM(debit - kredit) as bal FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$akun_kas' AND j.tgl_jurnal < '$tgl_mulai 00:00:00'")->fetch_assoc();
        $saldo_awal = (double)($q_awal['bal'] ?? 0);

        $q_mutasi = $conn->query("SELECT SUM(debit) as deb, SUM(kredit) as kre FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$akun_kas' AND j.tgl_jurnal BETWEEN '$tgl_mulai 00:00:00' AND '$tgl_selesai 23:59:59'")->fetch_assoc();
        $tot_in = (double)($q_mutasi['deb'] ?? 0);
        $tot_out = (double)($q_mutasi['kre'] ?? 0);
        $saldo_akhir = $saldo_awal + $tot_in - $tot_out;

        // ??? EARLY UPLOAD ENGINE: Langsung proses file bukti saat buat Draf
        $file_name = null;
        if(isset($_FILES['file_bukti']) && $_FILES['file_bukti']['error'] == UPLOAD_ERR_OK){
            $ext = pathinfo($_FILES['file_bukti']['name'], PATHINFO_EXTENSION);
            $file_name = 'LAPORAN-DRAF-'.time().'-'.rand(1000,9999).'.'.$ext;
            $path_dir = 'uploads/laporan_unit/';
            if(!is_dir($path_dir)) mkdir($path_dir, 0777, true);
            move_uploaded_file($_FILES['file_bukti']['tmp_name'], $path_dir.$file_name);
        }

        $stmt = $conn->prepare("INSERT INTO anggaran_unit_reports (unit_id, nama_laporan, tahun, tgl_mulai, tgl_selesai, saldo_awal, total_debet, total_kredit, saldo_akhir, status, workflow_status, created_by, file_bukti) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'DRAFT', 'DRAFT', ?, ?)");
        
        if (!$stmt) unit_done('danger', 'Kesalahan Database Insert: ' . $conn->error, 'monitoring');

        $stmt->bind_param("isissddddis", $target_unit_id, $nama_laporan, $tahun_r, $tgl_mulai, $tgl_selesai, $saldo_awal, $tot_in, $tot_out, $saldo_akhir, $uid, $file_name);
        
        if($stmt->execute()) {
            unit_done('success', 'Laporan berhasil di-generate dan tersimpan sebagai Draf.', 'monitoring', '&sub=arsip&unit_id='.$target_unit_id);
        } else {
            unit_done('danger', 'Gagal menyimpan draf laporan.', 'monitoring', '&sub=arsip');
        }
        break;

    case 'update_report':
        $id = (int)$_POST['report_id'];
        $nama_laporan   = $conn->real_escape_string($_POST['nama_laporan']);
        $tgl_mulai      = $conn->real_escape_string($_POST['tgl_mulai']);
        $tgl_selesai    = $conn->real_escape_string($_POST['tgl_selesai']);
        $tahun_r        = date('Y', strtotime($tgl_mulai));

        $rep = $conn->query("SELECT unit_id, file_bukti FROM anggaran_unit_reports WHERE id=$id")->fetch_assoc();
        $unit = $conn->query("SELECT * FROM m_unit WHERE id={$rep['unit_id']}")->fetch_assoc();
        $akun_kas = $unit['kas_bank_akun'];
        
        $q_awal = $conn->query("SELECT SUM(debit - kredit) as bal FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$akun_kas' AND j.tgl_jurnal < '$tgl_mulai 00:00:00'")->fetch_assoc();
        $saldo_awal = (double)($q_awal['bal'] ?? 0);

        $q_mutasi = $conn->query("SELECT SUM(debit) as deb, SUM(kredit) as kre FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '$akun_kas' AND j.tgl_jurnal BETWEEN '$tgl_mulai 00:00:00' AND '$tgl_selesai 23:59:59'")->fetch_assoc();
        $tot_in = (double)($q_mutasi['deb'] ?? 0);
        $tot_out = (double)($q_mutasi['kre'] ?? 0);
        $saldo_akhir = $saldo_awal + $tot_in - $tot_out;
        
        $file_name = $rep['file_bukti'];
        if(isset($_FILES['file_bukti']) && $_FILES['file_bukti']['error'] == UPLOAD_ERR_OK){
            $ext = pathinfo($_FILES['file_bukti']['name'], PATHINFO_EXTENSION);
            $file_name = 'LAPORAN-DRAF-'.time().'-'.rand(1000,9999).'.'.$ext;
            $path_dir = 'uploads/laporan_unit/';
            if(!is_dir($path_dir)) mkdir($path_dir, 0777, true);
            move_uploaded_file($_FILES['file_bukti']['tmp_name'], $path_dir.$file_name);
        }

        $stmt = $conn->prepare("UPDATE anggaran_unit_reports SET nama_laporan=?, tahun=?, tgl_mulai=?, tgl_selesai=?, saldo_awal=?, total_debet=?, total_kredit=?, saldo_akhir=?, status='DRAFT', workflow_status='DRAFT', file_bukti=? WHERE id=?");
        
        if (!$stmt) unit_done('danger', 'Kesalahan Database Update: ' . $conn->error, 'monitoring');

        $stmt->bind_param("sissddddsi", $nama_laporan, $tahun_r, $tgl_mulai, $tgl_selesai, $saldo_awal, $tot_in, $tot_out, $saldo_akhir, $file_name, $id);
        
        if($stmt->execute()) {
            unit_done('success', 'Draf Laporan telah diperbarui dan mutasi berhasil direkalkulasi.', 'monitoring', '&sub=arsip&unit_id='.$rep['unit_id']);
        } else {
            unit_done('danger', 'Gagal memperbarui draf.', 'monitoring', '&sub=arsip');
        }
        break;

    case 'delete_report':
        $id = (int)$_GET['id'];
        $conn->query("DELETE FROM anggaran_unit_reports WHERE id=$id AND status IN ('DRAFT', 'REVISI')");
        unit_done('success', 'Laporan dihapus permanen dari Draf.', 'monitoring', '&sub=arsip');
        break;

    case 'submit_report_to_arsip':
    case 'kirim_laporan_unit':
        $report_id  = (int)$_POST['report_id'];
        $user_id    = $_SESSION['user_id'];
        $q = $conn->query("SELECT * FROM anggaran_unit_reports WHERE id = $report_id")->fetch_assoc();
        
        $file_name = null;
        
        $file_input_name = isset($_FILES['file']['name']) && !empty($_FILES['file']['name']) ? 'file' : 
                          (isset($_FILES['bukti']['name']) && !empty($_FILES['bukti']['name']) ? 'bukti' : null);

        if($file_input_name && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK){
            $ext = pathinfo($_FILES[$file_input_name]['name'], PATHINFO_EXTENSION);
            $file_name = 'LAPORAN-'.$report_id.'-'.time().'.'.$ext;
            $path_dir = 'uploads/laporan_unit/';
            if(!is_dir($path_dir)) mkdir($path_dir, 0777, true);
            move_uploaded_file($_FILES[$file_input_name]['tmp_name'], $path_dir.$file_name);
        }

        $save_file = $file_name ? $file_name : ($q['file_bukti'] ?? null);

        if(empty($save_file)) {
            unit_done('danger', 'Laporan gagal dikirim! File bukti transaksi (PDF/ZIP) belum diunggah. Silakan upload terlebih dahulu.', 'monitoring');
        }

        $stmt = $conn->prepare("UPDATE anggaran_unit_reports SET status = 'MENUNGGU', submitted_at = NOW(), file_bukti = ?, dikirim_oleh = ?, dikirim_pada = NOW(), locked = 1 WHERE id = ?");
        $stmt->bind_param("sii", $save_file, $user_id, $report_id);
        $stmt->execute();
        
        // ?? THE NOTIFICATION INJECTION! (Otomatis membunyikan bel di akun Checker)
        $u_nm = $conn->query("SELECT nama_unit FROM m_unit WHERE id = " . (int)$q['unit_id'])->fetch_assoc();
        $nama_unit = $u_nm ? $u_nm['nama_unit'] : 'Unit';
        sendBellNotificationLocal($conn, "Validasi Laporan Unit", "Laporan {$q['nama_laporan']} dari $nama_unit telah masuk antrean dan menunggu validasi.", "index.php?page=anggaran_unit&tab=validasi", "checker");
        
        unit_done('success', 'Laporan berhasil dikirim ke meja VALIDASI Pusat.', 'monitoring');
        break;

    case 'approve_validasi':
        $report_id = (int)$_POST['report_id'];
        $judul_arsip = $conn->real_escape_string($_POST['judul_arsip'] ?? 'Arsip Laporan Unit');
        $user_id = $_SESSION['user_id'];
        
        $conn->begin_transaction();
        try {
            $q_rep = $conn->query("SELECT * FROM anggaran_unit_reports WHERE id = $report_id FOR UPDATE")->fetch_assoc();
            if (!$q_rep) throw new Exception("Data laporan tidak ditemukan.");
            
            $conn->query("UPDATE anggaran_unit_reports SET status = 'DISETUJUI', catatan_revisi = NULL WHERE id = $report_id");
            
            $unit_id = $q_rep['unit_id'];
            $file_path = $q_rep['file_bukti'] ? "uploads/laporan_unit/" . $q_rep['file_bukti'] : '';
            $desc = "Periode: " . $q_rep['tgl_mulai'] . " s/d " . $q_rep['tgl_selesai'];
            
            $stmt_arsip = $conn->prepare("INSERT INTO arsip_dokumen (ref_id, ref_type, unit_id, judul, deskripsi, file_path, status, uploaded_by, uploaded_at) VALUES (?, 'LAPORAN_UNIT', ?, ?, ?, ?, 'DISETUJUI', ?, NOW())");
            
            if ($stmt_arsip) {
                $stmt_arsip->bind_param("iisssi", $report_id, $unit_id, $judul_arsip, $desc, $file_path, $user_id);
                $stmt_arsip->execute();
            }

            $conn->commit();
            sendBellNotificationLocal($conn, "Laporan Disetujui", "Laporan [{$q_rep['nama_laporan']}] telah diverifikasi.", "index.php?page=anggaran_unit&tab=monitoring", "unit_maker", $q_rep['created_by']);
            
            unit_done('success', "Laporan disetujui dan dimasukkan ke Arsip Pusat.", 'validasi');
        } catch (Exception $e) {
            $conn->rollback();
            unit_done('danger', "Terjadi kesalahan saat memvalidasi: " . $e->getMessage(), 'validasi');
        }
        break;

    case 'revisi_validasi':
        $report_id = (int)$_POST['report_id'];
        $catatan = $conn->real_escape_string($_POST['catatan'] ?? '');
        
        $conn->begin_transaction();
        try {
            $q_rep = $conn->query("SELECT * FROM anggaran_unit_reports WHERE id = $report_id")->fetch_assoc();
            if (!$q_rep) throw new Exception("Data laporan tidak ditemukan.");

            $conn->query("UPDATE anggaran_unit_reports SET status = 'REVISI', catatan_revisi = '$catatan', locked = 0 WHERE id = $report_id");
            
            $conn->commit();
            sendBellNotificationLocal($conn, "Laporan Perlu Revisi", "Laporan [{$q_rep['nama_laporan']}] dikembalikan: $catatan", "index.php?page=anggaran_unit&tab=monitoring", "unit_maker", $q_rep['created_by']);
            
            unit_done('warning', "Laporan dikembalikan ke Unit untuk diperbaiki.", 'validasi');
        } catch (Exception $e) {
            $conn->rollback();
            unit_done('danger', "Terjadi kesalahan: " . $e->getMessage(), 'validasi');
        }
        break;

    case 'delete_arsip_laporan':
        $report_id = (int)$_GET['id'];
        $conn->begin_transaction();
        try {
            $q_rep = $conn->query("SELECT * FROM anggaran_unit_reports WHERE id = $report_id FOR UPDATE")->fetch_assoc();
            if (!$q_rep) throw new Exception("Data laporan tidak ditemukan.");

            // Kembalikan status laporan ke DRAFT dan buka kuncinya
            $conn->query("UPDATE anggaran_unit_reports SET status = 'DRAFT', locked = 0 WHERE id = $report_id");
            
            // Hapus fisik file jika ada
            $q_arsip = $conn->query("SELECT file_path FROM arsip_dokumen WHERE ref_id = $report_id AND ref_type = 'LAPORAN_UNIT'")->fetch_assoc();
            if ($q_arsip && !empty($q_arsip['file_path']) && file_exists($q_arsip['file_path'])) {
                @unlink($q_arsip['file_path']);
            }

            // Hapus dari tabel arsip
            $conn->query("DELETE FROM arsip_dokumen WHERE ref_id = $report_id AND ref_type = 'LAPORAN_UNIT'");
            
            $conn->commit();
            
            // Redirect kembali ke halaman arsip
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Laporan Unit dihapus dari Arsip Pusat dan dikembalikan ke Draf Unit.'];
            header("Location: index.php?page=arsip_dokumen");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal menghapus arsip: ' . $e->getMessage()];
            header("Location: index.php?page=arsip_dokumen");
            exit;
        }
        break;

    default:
        unit_done('danger', "Aksi sistem tidak dikenali ($action). Silakan ulangi.", 'monitoring');
        break;
}
ob_end_flush();
?>