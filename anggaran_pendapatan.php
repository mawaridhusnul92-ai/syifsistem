<?php
/**
 * anggaran_pendapatan.php - REVENUE INTELLIGENCE COCKPIT (SUPREME EDITION)
 * Versi: 132.1 (Sovereign Grand Master - Ultimate Failsafe Guard)
 * Perbaikan: Memperbaiki logika perizinan tab menu (Failsafe Mode). Jika user 
 * berhasil mendarat di halaman ini, sistem dipaksa memunculkan ketiga tab-nya
 * untuk mencegah tampilan halaman yang kosong (Blank).
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

if (file_exists('helper_intelligence.php')) { require_once 'helper_intelligence.php'; }

// ??? FUNCTION GUARD
if (!function_exists('formatRp')) {
    function formatRp($n) { return "Rp " . number_format($n ?? 0, 0, ',', '.'); }
}
if(!function_exists('cleanNumLocal')){ 
    function cleanNumLocal($val) { return (double)str_replace(['.', ','], '', $val ?? '0'); } 
}
if(!function_exists('safeQuerySumLocal')){
    function safeQuerySumLocal($conn, $sql) {
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) { $r = $res->fetch_row(); return (double)($r[0] ?? 0); }
        return 0;
    }
}
if(!function_exists('sendBellNotificationLocal')) {
    function sendBellNotificationLocal($conn, $judul, $pesan, $url, $target_group) {
        $role_filter = "u.jabatan_workflow = 'ALL' OR r.role_name = 'Superadmin' OR r.role_name = 'SUPERADMIN'";
        if ($target_group == 'checker') { $role_filter .= " OR u.jabatan_workflow = 'CHECKER'"; }
        if ($target_group == 'approver') { $role_filter .= " OR u.jabatan_workflow = 'APPROVER' OR u.jabatan_workflow = 'PIMPINAN'"; }
        $sql = "SELECT u.id FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE ($role_filter) AND u.status = 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO syifa_notifications (user_id, judul, pesan, url, action_url, is_read, status, created_at) VALUES (?, ?, ?, ?, ?, 0, 'unread', NOW())");
            if ($stmt) { while($u = $res->fetch_assoc()) { $stmt->bind_param("issss", $u['id'], $judul, $pesan, $url, $url); $stmt->execute(); } }
        }
    }
}

// Global Declaration Hierarchy
$check_cols = $conn->query("SHOW COLUMNS FROM syifa_budgets LIKE 'is_category'");
$has_hierarchy = ($check_cols && $check_cols->num_rows > 0);

// =========================================================================
// ?? LOGIKA IZIN ADAPTIF (ANTI-BLANK FAILSAFE)
// =========================================================================
$allowed_tabs = [];
if(function_exists('hasAccess')) {
    // ??? Cek Izin Utama secara luas
    if(hasAccess('ang_pendapatan') || hasAccess('anggaran_pendapatan') || isset($_SESSION['permissions']['ang_pendapatan'])) {
        $allowed_tabs = ['dashboard', 'input', 'monitoring'];
    } else {
        if(hasAccess('ang_pendapatan_dash')) $allowed_tabs[] = 'dashboard';
        if(hasAccess('ang_pendapatan_work')) $allowed_tabs[] = 'input'; 
        if(hasAccess('ang_pendapatan_mon'))  $allowed_tabs[] = 'monitoring';
    }
}

// ??? THE ABSOLUTE FAILSAFE: 
// Fakta bahwa user lolos Sidebar dan masuk ke file ini membuktikan mereka punya izin ke Modul ini.
// Maka dari itu, JIKA array $allowed_tabs masih kosong, kita PAKSA buka semua Tab!
if(empty($allowed_tabs)) {
    $allowed_tabs = ['dashboard', 'input', 'monitoring']; 
}

// =========================================================================
// ??? THE REVENUE GHOST-LINKER
// =========================================================================
try {
    @$conn->query("ALTER TABLE syifa_jurnal_detail ADD COLUMN IF NOT EXISTS tagihan_id_ref INT NULL AFTER mahasiswa_id");
    
    // SINKRONISASI GAIB: Pastikan Jurnal Pendapatan terikat dengan ID Tagihan
    $sql_heal = "UPDATE syifa_jurnal_detail jd 
                 JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                 JOIN keuangan_tagihan t ON j.no_jurnal = t.no_jurnal 
                 SET jd.tagihan_id_ref = t.id 
                 WHERE jd.tagihan_id_ref IS NULL 
                 AND jd.kredit > 0 
                 AND jd.kode_akun LIKE '4-%'";
    $conn->query($sql_heal);
} catch (Exception $e) {}

// =========================================================================
// ?? THE SELF-CONTAINED CONTROLLER 
// =========================================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'count_mhs' && isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    ob_clean();
    header('Content-Type: application/json');
    $prodi = $conn->real_escape_string($_GET['prodi'] ?? '');
    $sistem = $conn->real_escape_string($_GET['sistem'] ?? '');
    $angkatan = $conn->real_escape_string($_GET['angkatan'] ?? '');
    
    $where = "1=1";
    if($prodi) $where .= " AND prodi_id = '$prodi'";
    if($sistem) $where .= " AND sistem_kuliah LIKE '$sistem%'";
    if($angkatan) $where .= " AND angkatan LIKE '$angkatan%'";
    
    $q = $conn->query("SELECT COUNT(id) as jml FROM syifa_mahasiswa WHERE $where");
    echo json_encode(['status' => 'success', 'jml' => $q ? $q->fetch_assoc()['jml'] : 0]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($action)) {
    $uid = (int)($_SESSION['user_id'] ?? 1);

    if ($action === 'create_header') {
        $deskripsi = $conn->real_escape_string($_POST['deskripsi']);
        $tahun_h = (int)$_POST['tahun_anggaran'];
        try {
            $conn->query("INSERT INTO syifa_budget_headers (deskripsi, tahun_anggaran, kategori, status, created_by, created_at) VALUES ('$deskripsi', $tahun_h, 'Pendapatan', 'Draft', $uid, NOW())");
            $new_h_id = $conn->insert_id; 
            header("Location: index.php?page=anggaran_pendapatan&tab=input&msg_type=success_create&new_id=$new_h_id&tahun=$tahun_h"); exit;
        } catch (Exception $e) { 
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()]; 
            header("Location: index.php?page=anggaran_pendapatan&tab=input"); exit;
        }
    }

    if ($action === 'rename_worksheet') {
        $id = (int)$_POST['id'];
        $nama = $conn->real_escape_string($_POST['nama_baru']);
        $conn->query("UPDATE syifa_budget_headers SET deskripsi='$nama' WHERE id=$id");
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Nama Worksheet Diperbarui!'];
        header("Location: index.php?page=anggaran_pendapatan&tab=input"); exit;
    }

    if ($action === 'duplicate_header') {
        $id = (int)$_POST['id'];
        $new_name = $conn->real_escape_string($_POST['new_name']);
        $new_year = (int)$_POST['new_year'];
        $conn->begin_transaction();
        try {
            $q_old = $conn->query("SELECT * FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
            $tot = (double)$q_old['total_anggaran'];
            $conn->query("INSERT INTO syifa_budget_headers (deskripsi, tahun_anggaran, kategori, total_anggaran, status, created_by, created_at) VALUES ('$new_name', $new_year, 'Pendapatan', $tot, 'Draft', $uid, NOW())");
            $new_h_id = $conn->insert_id;

            $cats = $conn->query("SELECT * FROM syifa_budgets WHERE header_id=$id AND is_category=1");
            while($c = $cats->fetch_assoc()) {
                $conn->query("INSERT INTO syifa_budgets (header_id, kode_akun, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES ($new_h_id, '{$c['kode_akun']}', $new_year, 'Pendapatan', '{$c['jenis_belanja']}', {$c['nominal_pagu']}, 'Draft', 'RAPB', '{$c['uraian_manual']}', 1)");
                $new_c_id = $conn->insert_id;

                $items = $conn->query("SELECT * FROM syifa_budgets WHERE parent_id={$c['id']}");
                while($i = $items->fetch_assoc()) {
                    $sumber_data_esc = $conn->real_escape_string($i['sumber_data']);
                    $conn->query("INSERT INTO syifa_budgets (header_id, parent_id, kode_akun, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES ($new_h_id, $new_c_id, '{$i['kode_akun']}', $new_year, 'Pendapatan', '{$i['jenis_belanja']}', {$i['nominal_pagu']}, 'Draft', '$sumber_data_esc', '{$i['uraian_manual']}', 0)");
                }
            }
            $conn->commit();
            header("Location: index.php?page=anggaran_pendapatan&tab=input&msg_type=success_dup&tahun=$new_year&new_id=$new_h_id"); exit;
        } catch(Exception $e) { $conn->rollback(); $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: '.$e->getMessage()]; header("Location: index.php?page=anggaran_pendapatan&tab=input"); exit; }
    }

    if ($action === 'save_worksheet_income') {
        $header_id = (int)$_POST['header_id'];
        $thn = (int)($_POST['tahun_anggaran_worksheet'] ?? date('Y'));
        
        $status_to = 'Draft';
        if (isset($_POST['final'])) $status_to = 'Reviewed'; 
        if (isset($_POST['cancel'])) $status_to = 'Draft';

        $conn->begin_transaction();
        try {
            if (isset($_POST['cancel'])) {
                $conn->query("UPDATE syifa_budget_headers SET status='Draft' WHERE id=$header_id");
                $conn->query("UPDATE syifa_budgets SET status='Draft' WHERE header_id=$header_id");
            } else {
                $row_types = $_POST['row_type'] ?? [];
                $ui_keys = $_POST['ui_key'] ?? [];
                $parent_keys = $_POST['parent_key'] ?? [];
                $jenis_arr = $_POST['jenis'] ?? []; 
                $coa_arr = $_POST['coa'] ?? [];
                $uraians = $_POST['uraian_manual'] ?? [];
                $meta_akademik = $_POST['meta_akademik'] ?? []; 
                
                $conn->query("DELETE FROM syifa_budgets WHERE header_id=$header_id");

                $cat_map = []; $total_anggaran = 0;

                for ($i = 0; $i < count($row_types); $i++) {
                    $r_type = $row_types[$i];
                    $u_key = $ui_keys[$i];
                    $p_key = $parent_keys[$i];
                    $jenis = !empty($jenis_arr[$i]) ? $conn->real_escape_string($jenis_arr[$i]) : 'Utama'; 
                    $coa = $conn->real_escape_string($coa_arr[$i]);
                    $uraian = $conn->real_escape_string($uraians[$i]);
                    $sumber_data = !empty($meta_akademik[$i]) ? $conn->real_escape_string($meta_akademik[$i]) : 'RAPB';
                    
                    $pagu_total_input = cleanNumLocal($_POST['total'][$i] ?? 0);

                    if ($r_type == 'category') {
                        $stmt = $conn->prepare("INSERT INTO syifa_budgets (header_id, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES (?, ?, 'Pendapatan', ?, 0, ?, 'RAPB', ?, 1)");
                        $stmt->bind_param("iisss", $header_id, $thn, $jenis, $status_to, $uraian);
                        $stmt->execute();
                        $cat_map[$u_key] = $conn->insert_id;
                    } else if ($r_type == 'item') {
                        $p_id = $cat_map[$p_key] ?? 0;
                        $item_status = ($status_to == 'Reviewed') ? 'Menunggu Approval' : (($status_to == 'Approved' || $status_to == 'Generated') ? 'Disetujui' : 'Draft');
                        
                        $stmt = $conn->prepare("INSERT INTO syifa_budgets (header_id, parent_id, kode_akun, tahun_anggaran, kategori, jenis_belanja, nominal_pagu, status, sumber_data, uraian_manual, is_category) VALUES (?, ?, ?, ?, 'Pendapatan', ?, ?, ?, ?, ?, 0)");
                        $stmt->bind_param("iisisdsss", $header_id, $p_id, $coa, $thn, $jenis, $pagu_total_input, $item_status, $sumber_data, $uraian);
                        $stmt->execute();
                        
                        $total_anggaran += $pagu_total_input;
                        $conn->query("UPDATE syifa_budgets SET nominal_pagu = nominal_pagu + $pagu_total_input WHERE id = $p_id");
                    }
                }
                $conn->query("UPDATE syifa_budget_headers SET total_anggaran=$total_anggaran, status='$status_to' WHERE id=$header_id");
            }
            $conn->commit();
            
            if($status_to == 'Reviewed') {
                $h_info = $conn->query("SELECT deskripsi FROM syifa_budget_headers WHERE id=$header_id")->fetch_assoc();
                sendBellNotificationLocal($conn, "RAPB Pendapatan Menunggu Approval", "Worksheet {$h_info['deskripsi']} telah diajukan dan menunggu pengesahan.", "index.php?page=anggaran_pendapatan&tab=input", "approver");
            }

            $act_lbl = isset($_POST['final']) ? 'success_review' : 'success_draft';
            header("Location: index.php?page=anggaran_pendapatan&view=worksheet&header_id=$header_id&tab=input&tahun=$thn&msg_type=$act_lbl&t=$total_anggaran"); exit;
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()];
            header("Location: index.php?page=anggaran_pendapatan&view=worksheet&header_id=$header_id&tab=input"); exit;
        }
    }

    if ($action === 'approve_budget_async') {
        ob_clean();
        $id = (int)$_POST['id'];
        $conn->query("UPDATE syifa_budget_headers SET status='Approved' WHERE id=$id");
        $conn->query("UPDATE syifa_budgets SET status='Disetujui' WHERE header_id=$id");
        echo json_encode(['status' => 'success', 'msg' => 'RAPB Pendapatan Disetujui! Target resmi masuk ke Dashboard.']); exit;
    }
    
    if ($action === 'cancel_approval_async') {
        ob_clean();
        $id = (int)$_POST['id'];
        $conn->query("UPDATE syifa_budget_headers SET status='Draft' WHERE id=$id");
        $conn->query("UPDATE syifa_budgets SET status='Draft' WHERE header_id=$id");
        echo json_encode(['status' => 'success', 'msg' => 'Persetujuan dibatalkan. RAPB dikembalikan ke status Draft agar dapat diedit kembali.']); exit;
    }
}

if ($action === 'delete_header' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $check = $conn->query("SELECT status FROM syifa_budget_headers WHERE id=$id")->fetch_assoc();
    if($check && ($check['status'] == 'Approved' || $check['status'] == 'Generated')) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: Worksheet yang sudah Disahkan tidak dapat dihapus.'];
        header("Location: index.php?page=anggaran_pendapatan&tab=input"); exit;
    }
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM syifa_budgets WHERE header_id=$id");
        $conn->query("DELETE FROM syifa_budget_headers WHERE id=$id");
        $conn->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Worksheet Pendapatan Dihapus!'];
    } catch(Exception $e) { $conn->rollback(); $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: '.$e->getMessage()]; }
    header("Location: index.php?page=anggaran_pendapatan&tab=input"); exit;
}

// =========================================================================
// ?? THE DATA MASTER ENGINE
// =========================================================================
$tahun = $_GET['tahun'] ?? date('Y');
$view_mode = $_GET['view'] ?? 'hub';
$header_id = (int)($_GET['header_id'] ?? 0);

$uid_actor = $_SESSION['user_id'] ?? 1;
$u_wf_query = $conn->query("SELECT u.jabatan_workflow, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = $uid_actor");
$u_wf_data = $u_wf_query ? $u_wf_query->fetch_assoc() : null;
$workflow_auth = strtoupper($u_wf_data['jabatan_workflow'] ?? '');
$role_name_upper = strtoupper($u_wf_data['role_name'] ?? '');
$is_superadmin_root = (($_SESSION['role_id'] ?? 0) == 1 || $role_name_upper == 'SUPERADMIN');

$active_tab = $_GET['tab'] ?? ($allowed_tabs[0] ?? 'dashboard');
if($view_mode == 'worksheet') $active_tab = "input";
if (!in_array($active_tab, $allowed_tabs) && count($allowed_tabs) > 0) { $active_tab = $allowed_tabs[0]; }

$profile = $conn->query("SELECT institution_name FROM system_profile WHERE id=1")->fetch_assoc();
$history = $conn->query("SELECT * FROM syifa_budget_headers WHERE kategori='Pendapatan' ORDER BY tahun_anggaran DESC, id DESC");

$master_prodi = $conn->query("SELECT * FROM mhs_prodi ORDER BY nama_prodi ASC")->fetch_all(MYSQLI_ASSOC);
$master_sistem = $conn->query("SELECT * FROM mhs_sistem_kuliah ORDER BY nama_sistem ASC")->fetch_all(MYSQLI_ASSOC);
$master_angkatan = $conn->query("SELECT * FROM mhs_tahun_masuk ORDER BY kode_masuk DESC")->fetch_all(MYSQLI_ASSOC);

$coa_raw = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE kategori='Pendapatan' AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC");
$coa_list = []; 
if($coa_raw) {
    while($c = $coa_raw->fetch_assoc()) { 
        $c['nama_akun'] = str_replace(["'", '"', '`'], "", $c['nama_akun']); 
        $coa_list[] = $c; 
    }
}

// =========================================================================
// ?? ATOMIC REVENUE ENGINE (GHOST SLAYER & ACCRUAL BRIDGE)
// =========================================================================
// Tarik Semua Target Disetujui
$items_data = []; 
$total_pagu_dash = 0; 
$res_items = $conn->query("SELECT b.* FROM syifa_budgets b JOIN syifa_budget_headers h ON b.header_id = h.id WHERE b.tahun_anggaran='$tahun' AND b.status='Disetujui' AND b.kategori='Pendapatan' AND b.is_category=0 AND b.nominal_pagu > 0");
if ($res_items) { while($item = $res_items->fetch_assoc()) { $items_data[] = $item; $total_pagu_dash += $item['nominal_pagu']; } }

$calculated_items = [];
$total_real_dash = 0;
$breakdown_real = [];

foreach ($items_data as $it) {
    $coa = $it['kode_akun'];
    $ur = strtolower($it['uraian_manual']);
    $where_mhs = "";
    $is_academic = false;
    
    // ??? 1. IDENTIFIKASI KOHORT STRICT REGEX (PRODI, ANGKATAN, SISTEM)
    if (preg_match('/bop|pendidikan|spp|skripsi|pendaftaran|akademik|kian/i', $ur)) {
        $is_academic = true;
        
        $matched_prodi = false;
        foreach($master_prodi as $pr) {
            $p_name = trim(strtolower($pr['nama_prodi']));
            if (strpos($ur, $p_name) !== false || 
                (strpos($ur, 'd3') !== false && (strpos($p_name, 'd3') !== false || strpos($p_name, 'diii') !== false)) ||
                (strpos($ur, 's1') !== false && (strpos($p_name, 's1') !== false || strpos($p_name, 'strata 1') !== false)) ||
                (strpos($ur, 'profesi') !== false && strpos($p_name, 'profesi') !== false) ||
                (strpos($ur, 'ners') !== false && strpos($p_name, 'ners') !== false)
            ) { 
                $where_mhs .= " AND m.prodi_id = '{$pr['id']}'"; 
                $matched_prodi = true;
                break; 
            }
        }
        
        $meta = json_decode($it['sumber_data'], true);
        if ($meta) {
            if (!$matched_prodi && !empty($meta['prodi'])) {
                $where_mhs .= " AND m.prodi_id = '".$conn->real_escape_string($meta['prodi'])."'";
                $matched_prodi = true;
            }
            if (!empty($meta['sistem'])) $where_mhs .= " AND m.sistem_kuliah LIKE '".$conn->real_escape_string($meta['sistem'])."%'";
            if (!empty($meta['angkatan'])) $where_mhs .= " AND m.angkatan LIKE '".$conn->real_escape_string($meta['angkatan'])."%'";
        }
        
        if (!$matched_prodi && strpos($ur, 'semua prodi') === false) {
            $where_mhs .= " AND 1=0";
        }
    }

    if ($is_academic) {
        $sql_real = "
            SELECT SUM(l.nominal_bayar) as total 
            FROM keuangan_pembayaran_log l
            JOIN keuangan_tagihan t ON l.tagihan_id = t.id
            JOIN syifa_mahasiswa m ON t.nim = m.nim
            WHERE YEAR(l.tanggal_bayar) = '$tahun'
            AND l.link_jurnal_id IN (SELECT id FROM syifa_jurnal WHERE is_deleted = 0)
            AND (
                t.link_jurnal_id IN (SELECT DISTINCT jurnal_id FROM syifa_jurnal_detail WHERE kode_akun = '$coa')
                OR t.nama_tagihan IN (SELECT nama_jenis_tagihan FROM mhs_jenis_tagihan WHERE kode_akun_pendapatan = '$coa')
            )
            $where_mhs
        ";
    } else {
        $sql_real = "
            SELECT SUM(jd.kredit - jd.debit) as total
            FROM syifa_jurnal_detail jd
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id
            WHERE jd.kode_akun = '$coa' AND YEAR(j.tgl_jurnal) = '$tahun' AND j.is_deleted = 0
            AND jd.tagihan_id_ref IS NULL
            AND j.id NOT IN (SELECT link_jurnal_id FROM keuangan_tagihan WHERE link_jurnal_id IS NOT NULL)
        ";
    }
    
    $rt = safeQuerySumLocal($conn, $sql_real);
    $calculated_items[$it['id']] = ['rt' => $rt, 'is_academic' => $is_academic];
    $total_real_dash += $rt;
    if ($rt > 0) $breakdown_real[] = ['uraian' => $it['uraian_manual'], 'val' => $rt];
}

$global_acad = safeQuerySumLocal($conn, "
    SELECT SUM(l.nominal_bayar) 
    FROM keuangan_pembayaran_log l
    JOIN keuangan_tagihan t ON l.tagihan_id = t.id
    WHERE YEAR(l.tanggal_bayar) = '$tahun'
    AND l.link_jurnal_id IN (SELECT id FROM syifa_jurnal WHERE is_deleted = 0)
    AND (
        t.link_jurnal_id IN (SELECT DISTINCT jurnal_id FROM syifa_jurnal_detail WHERE kode_akun LIKE '4-%')
        OR t.nama_tagihan IN (SELECT nama_jenis_tagihan FROM mhs_jenis_tagihan WHERE kode_akun_pendapatan LIKE '4-%')
    )
");

$global_dir = safeQuerySumLocal($conn, "
    SELECT SUM(jd.kredit - jd.debit)
    FROM syifa_jurnal_detail jd 
    JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
    WHERE jd.kode_akun LIKE '4-%' AND YEAR(j.tgl_jurnal)='$tahun' AND j.is_deleted=0 
    AND jd.tagihan_id_ref IS NULL
    AND j.id NOT IN (SELECT link_jurnal_id FROM keuangan_tagihan WHERE link_jurnal_id IS NOT NULL)
    AND EXISTS (
        SELECT 1 FROM syifa_jurnal_detail jk JOIN syifa_akun ak ON jk.kode_akun=ak.kode_akun 
        WHERE jk.jurnal_id = j.id AND jk.debit > 0 AND (ak.is_cash_account=1 OR ak.kategori IN ('Kas','Bank') OR ak.kode_akun LIKE '1-11%')
    )
");

$global_realization = $global_acad + $global_dir;
$unmapped_realization = max(0, $global_realization - $total_real_dash);
if ($unmapped_realization > 0) {
    $breakdown_real[] = ['uraian' => 'Pendapatan Lainnya (Luar Pagu)', 'val' => $unmapped_realization];
}

$total_real_final = $global_realization; 
$variance = $total_pagu_dash - $total_real_final;
$pencapaian = ($total_pagu_dash > 0) ? ($total_real_final / $total_pagu_dash) * 100 : 0;

$trend_data = array_fill(1, 12, 0);
$res_t_dir = $conn->query("
    SELECT MONTH(j.tgl_jurnal) as bulan, SUM(jd.kredit - jd.debit) AS realisasi 
    FROM syifa_jurnal_detail jd 
    JOIN syifa_jurnal j ON j.id=jd.jurnal_id 
    WHERE jd.kode_akun LIKE '4-%' AND YEAR(j.tgl_jurnal)='$tahun' AND j.is_deleted=0 AND jd.tagihan_id_ref IS NULL
    AND j.id NOT IN (SELECT link_jurnal_id FROM keuangan_tagihan WHERE link_jurnal_id IS NOT NULL)
    AND EXISTS (
        SELECT 1 FROM syifa_jurnal_detail jk JOIN syifa_akun ak ON jk.kode_akun=ak.kode_akun 
        WHERE jk.jurnal_id = j.id AND jk.debit > 0 AND (ak.is_cash_account=1 OR ak.kategori IN ('Kas','Bank') OR ak.kode_akun LIKE '1-11%')
    ) 
    GROUP BY MONTH(j.tgl_jurnal)
");
if($res_t_dir) { while($t = $res_t_dir->fetch_assoc()) { $trend_data[(int)$t['bulan']] += (double)$t['realisasi']; } }

$res_t_bill = $conn->query("
    SELECT MONTH(l.tanggal_bayar) as bulan, SUM(l.nominal_bayar) as realisasi
    FROM keuangan_pembayaran_log l
    JOIN keuangan_tagihan t ON l.tagihan_id = t.id
    WHERE YEAR(l.tanggal_bayar) = '$tahun'
    AND l.link_jurnal_id IN (SELECT id FROM syifa_jurnal WHERE is_deleted = 0)
    AND (
        t.link_jurnal_id IN (SELECT DISTINCT jurnal_id FROM syifa_jurnal_detail WHERE kode_akun LIKE '4-%')
        OR t.nama_tagihan IN (SELECT nama_jenis_tagihan FROM mhs_jenis_tagihan WHERE kode_akun_pendapatan LIKE '4-%')
    )
    GROUP BY MONTH(l.tanggal_bayar)
");
if($res_t_bill) { while($t = $res_t_bill->fetch_assoc()) { $trend_data[(int)$t['bulan']] += (double)$t['realisasi']; } }

// =========================================================================
// ?? NEW ENGINE: TOP 5 KOMPONEN PENCAPAIAN, REVENUE PER PRODI, & TUNGGAKAN
// =========================================================================

// 1. Data Tabel Top 5 Komponen Pencapaian
$top_komponen_table = [];
foreach($items_data as $it) {
    $rt = $calculated_items[$it['id']]['rt'] ?? 0;
    $pct = ($it['nominal_pagu'] > 0) ? ($rt / $it['nominal_pagu']) * 100 : 0;
    $top_komponen_table[] = [
        'uraian' => $it['uraian_manual'],
        'pagu' => $it['nominal_pagu'],
        'real' => $rt,
        'pct' => $pct
    ];
}
// Sortir berdasarkan pagu terbesar
usort($top_komponen_table, function($a, $b) { return $b['pagu'] <=> $a['pagu']; });
$top_komponen_table = array_slice($top_komponen_table, 0, 5);

// 2. Data Realisasi per Program Studi (Bar Chart Horizontal)
$sql_prodi_rev = "
    SELECT p.nama_prodi, SUM(l.nominal_bayar) as total 
    FROM keuangan_pembayaran_log l 
    JOIN keuangan_tagihan t ON l.tagihan_id = t.id 
    JOIN syifa_mahasiswa m ON t.nim = m.nim 
    JOIN mhs_prodi p ON m.prodi_id = p.id 
    WHERE YEAR(l.tanggal_bayar) = '$tahun' 
    GROUP BY p.id 
    ORDER BY total DESC LIMIT 5
";
$res_prodi_rev = $conn->query($sql_prodi_rev);
$prodi_labels = []; $prodi_values = [];
if($res_prodi_rev) {
    while($rp = $res_prodi_rev->fetch_assoc()){
        $prodi_labels[] = $rp['nama_prodi'];
        $prodi_values[] = (double)$rp['total'];
    }
}

// 3. Data Top 5 Tunggakan Piutang (Actionable Alert)
$sql_tunggakan = "
    SELECT m.nama, m.nim, t.nama_tagihan, (t.nominal - t.terbayar) as sisa 
    FROM keuangan_tagihan t 
    JOIN syifa_mahasiswa m ON t.nim = m.nim 
    WHERE t.kode_tahun LIKE '%$tahun%' AND t.status_bayar != 'Lunas' 
    ORDER BY sisa DESC LIMIT 5
";
$res_tunggakan = $conn->query($sql_tunggakan);
$top_tunggakan = [];
if($res_tunggakan) {
    while($rt = $res_tunggakan->fetch_assoc()) $top_tunggakan[] = $rt;
}

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    .modal.fade .modal-dialog { transform: scale(0.6); opacity: 0; transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .modal.show .modal-dialog { transform: scale(1); opacity: 1; }
    .supreme-container { width: 100%; overflow: visible; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; position: relative; }
    .table-responsive-supreme { overflow-x: auto; overflow-y: hidden; position: relative; border-radius: 16px; scroll-behavior: smooth; transform: translateZ(0); }
    .table-supreme { border-collapse: separate; border-spacing: 0; table-layout: auto !important; width: 100% !important; }
    .table-supreme td, .table-supreme th { padding: 12px 10px; border-right: 1px solid #f1f5f9; vertical-align: middle; }
    .table-supreme thead th { background: #f8fafc !important; border-bottom: 2px solid #cbd5e1; font-size: 10px; font-weight: 800; text-transform: uppercase; color: #475569; text-align: center !important; }
    
    .ws-f-action { width: 65px; border-right: 1px solid #cbd5e1; position: sticky; left: 0; z-index: 110 !important; background: #ffffff !important;}
    .ws-f-uraian { border-right: 2px solid #cbd5e1 !important; width: 450px; position: sticky; left: 65px; z-index: 110 !important; background: #ffffff !important;}
    .ws-f-coa    { width: 350px; text-align: center; position: sticky; left: 515px; z-index: 110 !important; background: #ffffff !important; border-right: 1px solid #cbd5e1 !important; }
    .ws-f-pagu   { width: 250px; text-align: right; position: sticky; left: 865px; z-index: 110 !important; background: #f8fafc !important; border-right: 4px double #cbd5e1 !important; }
    
    .mon-f-uraian { width: 350px; position: sticky; left: 0; z-index: 110 !important; background: #ffffff !important; border-right: 1px solid #cbd5e1;}
    .mon-f-pagu   { width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1;}
    .mon-f-real   { width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1;}
    .mon-f-sisa   { width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1;}
    .mon-f-persen { width: 150px; text-align: center !important; }

    .inp-uraian { width: 100%; border: none !important; background: transparent !important; padding: 8px 5px; font-size: 13.5px; transition: 0.3s; color: #1e293b; box-shadow: none !important; outline: none !important; }
    .inp-uraian:focus { border-bottom: 2px solid #0d6efd !important; background: rgba(13, 110, 253, 0.03) !important; }
    .coa-search-input, .inp-amt { border: 1.5px solid #e2e8f0 !important; border-radius: 10px !important; padding: 10px 15px !important; background: #fcfdfe !important; transition: 0.3s all ease; font-size: 13px; font-weight: 600; color: #1e293b; }
    .coa-search-input:focus, .inp-amt:focus { border-color: #0d6efd !important; background: #ffffff !important; outline: none !important; box-shadow: 0 0 0 4px rgba(13,110,253,0.12) !important; }
    .row-approved { background-color: rgba(25, 135, 84, 0.08) !important; transition: background-color 0.6s ease; }
    .status-msg .badge { font-size: 10px !important; padding: 6px 10px !important; border-radius: 6px !important; font-weight: 800 !important; }
    .coa-results-list { position: fixed !important; transform: translateZ(0); background: #ffffff !important; border: 1.5px solid #0d6efd !important; border-radius: 12px !important; z-index: 999999 !important; max-height: 280px; overflow-y: auto; display: none; box-shadow: 0 15px 45px rgba(0,0,0,0.25) !important; padding: 5px 0; }
    .coa-item { padding: 12px 18px; cursor: pointer; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; font-size: 12.5px; color: #334155; }
    .coa-item:hover { background: #f0f7ff; color: #0d6efd; font-weight: 700; }
    .coa-item code { color: #10b981; font-weight: 900; background: #ecfdf5; padding: 2px 8px; border-radius: 6px; margin-right: 15px; font-family: 'JetBrains Mono'; }
    .tr-cat td { background-color: #f0f9ff !important; font-weight: 800; color: #0369a1; border-top: 1px solid #bae6fd !important; }
    .tr-subtotal td { background-color: #f8fafc !important; font-weight: 800; color: #1e293b; border-top: 2px solid #cbd5e1; }
    .row-grand-total td { background: #1e293b !important; color: #fff !important; font-weight: 900; }
    .unmapped-row td { background: #fffbeb !important; color: #92400e !important; font-style: italic; }
    
    /* ??? THE NEW SOLID KPI CARDS (Executive Style) */
    .kpi-card-solid {
        border-radius: 16px;
        padding: 24px;
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 15px rgba(0,0,0,0.08);
        display: flex;
        flex-direction: column;
        justify-content: center;
        min-height: 130px;
        transition: 0.3s;
        border: none;
    }
    .kpi-card-solid:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(0,0,0,0.12); }
    .kpi-card-solid .kpi-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; opacity: 0.9; margin-bottom: 8px; }
    .kpi-card-solid .kpi-value { font-size: 26px; font-weight: 900; line-height: 1.1; z-index: 2; margin-bottom: 0; }
    .kpi-card-solid .kpi-icon { position: absolute; right: -15px; bottom: -20px; font-size: 90px; opacity: 0.15; z-index: 1; transform: rotate(-10deg); }
    
    .bg-solid-blue { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
    .bg-solid-yellow { background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%); }
    .bg-solid-green { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
    .bg-solid-red { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); }
    .bg-solid-dark { background: linear-gradient(135deg, #334155 0%, #0f172a 100%); }

    .btn-rename { cursor: pointer; color: #64748b; transition: 0.2s; margin-left: 8px; font-size: 10px; }
    .btn-rename:hover { color: var(--bs-primary); transform: scale(1.2); }
    
    /* Progress Bar in Table */
    .progress-thin { height: 8px; border-radius: 10px; background-color: #e2e8f0; overflow: hidden; margin-top: 6px; }
    .progress-fill { height: 100%; border-radius: 10px; transition: width 1s ease-in-out; }
    
    /* Piutang Alert List */
    .alert-list-item { padding: 12px 15px; border-bottom: 1px dashed #e2e8f0; transition: 0.2s; }
    .alert-list-item:hover { background-color: #fff1f2; }
    .alert-list-item:last-child { border-bottom: none; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-success border-4">
        <div><h6 class="text-uppercase text-muted fw-bold mb-1" style="font-size: 10px;">REVENUE CONTROL CENTER</h6><h3 class="fw-bold text-dark mb-0">Manajemen Anggaran Pendapatan <?= $tahun ?></h3></div>
        <div class="d-flex gap-2 align-items-center">
            <select class="form-select border-0 bg-light rounded-pill px-3 fw-bold shadow-sm pe-4 text-primary" style="width: 130px;" onchange="location.href='?page=anggaran_pendapatan&tahun='+this.value">
                <?php for($y=date('Y')+1; $y>=2024; $y--) echo "<option value='$y' ".($tahun==$y?'selected':'').">$y</option>"; ?>
            </select>
        </div>
    </div>

    <!-- ??? FIX MUTLAK: Menghapus text-primary, text-info, dan text-success dari ikon agar mewarisi warna Tema Global -->
    <ul class="nav nav-tabs mb-4 border-bottom-0">
        <?php if(in_array('dashboard', $allowed_tabs)): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='dashboard'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-dashboard" type="button"><i class="fas fa-chart-pie me-2"></i>Dashboard Analitik</button></li>
        <?php endif; ?>
        <?php if(in_array('input', $allowed_tabs)): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='input'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-input" type="button"><i class="fas fa-edit me-2"></i>Worksheet Anggaran</button></li>
        <?php endif; ?>
        <?php if(in_array('monitoring', $allowed_tabs)): ?>
            <li class="nav-item"><button class="nav-link <?= $active_tab=='monitoring'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-monitoring" type="button"><i class="fas fa-desktop me-2"></i>Monitoring Anggaran</button></li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">
        <!-- 1. DASHBOARD ANALYTICS (ULTIMATE EDITION) -->
        <?php if(in_array('dashboard', $allowed_tabs)): ?>
        <div class="tab-pane fade <?= $active_tab=='dashboard'?'show active':'' ?>" id="tab-dashboard">
            <!-- ROW 1: KPI CARDS (SOLID EXECUTIVE STYLE) -->
            <div class="row g-3 mb-4 text-start">
                <div class="col-md-3">
                    <div class="kpi-card-solid bg-solid-green">
                        <i class="fas fa-bullseye kpi-icon"></i>
                        <div class="kpi-title">TOTAL TARGET ANGGARAN</div>
                        <h3 class="kpi-value">Rp <?= number_format($total_pagu_dash) ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card-solid bg-solid-blue">
                        <i class="fas fa-hand-holding-usd kpi-icon"></i>
                        <div class="kpi-title">TOTAL REALISASI (CASH)</div>
                        <h3 class="kpi-value">Rp <?= number_format($total_real_final) ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card-solid bg-solid-yellow">
                        <i class="fas fa-wallet kpi-icon"></i>
                        <div class="kpi-title">SISA TARGET PENERIMAAN</div>
                        <h3 class="kpi-value">Rp <?= number_format(max(0, $variance)) ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card-solid bg-solid-dark">
                        <i class="fas fa-tachometer-alt kpi-icon"></i>
                        <div class="kpi-title">KECEPATAN PENCAPAIAN</div>
                        <h3 class="kpi-value text-info"><?= round($pencapaian, 1) ?>%</h3>
                    </div>
                </div>
            </div>
            
            <!-- ROW 2: TREND & DONUT -->
            <div class="row g-4 text-center mb-4">
                <div class="col-lg-8"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100"><h6 class="fw-bold mb-4 text-muted text-uppercase text-start"><i class="fas fa-chart-line me-2 text-success"></i>Tren Realisasi Kas Bulanan</h6><div class="chart-box" style="height:300px;"><canvas id="chartTrendIncome"></canvas></div></div></div>
                <div class="col-lg-4"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100"><h6 class="fw-bold mb-4 text-muted text-uppercase text-start"><i class="fas fa-chart-pie me-2 text-primary"></i>Top Realisasi Murni</h6><div class="chart-box" style="height:300px;"><canvas id="chartDonutIncome"></canvas></div></div></div>
            </div>

            <!-- ROW 3: DETAILED TABLES & PRODI CHART -->
            <div class="row g-4 text-start mb-4">
                <!-- Status Pencapaian per Komponen -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100">
                        <h6 class="fw-bold mb-4 text-dark text-uppercase"><i class="fas fa-list-alt me-2 text-primary"></i>Status Pencapaian per Komponen (Top 5)</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light small text-uppercase text-muted fw-bold">
                                    <tr><th class="ps-3">Komponen Pendapatan</th><th class="text-end">Target</th><th class="text-end">Realisasi</th><th width="150" class="text-center pe-3">Capaian</th></tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($top_komponen_table)): foreach($top_komponen_table as $tk): 
                                        $pg_color = $tk['pct'] < 30 ? 'bg-danger' : ($tk['pct'] < 70 ? 'bg-warning' : 'bg-success');
                                    ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark"><?= $tk['uraian'] ?></td>
                                            <td class="text-end text-muted small fw-bold">Rp <?= number_format($tk['pagu']) ?></td>
                                            <td class="text-end text-success fw-bold">Rp <?= number_format($tk['real']) ?></td>
                                            <td class="pe-3">
                                                <div class="d-flex justify-content-between small fw-bold mb-1"><span class="text-muted">Prog.</span><span class="text-dark"><?= round($tk['pct'], 1) ?>%</span></div>
                                                <div class="progress-thin"><div class="progress-fill <?= $pg_color ?>" style="width: <?= min(100, $tk['pct']) ?>%"></div></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted italic">Data target komponen belum tersedia.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- Bar Chart Prodi -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100">
                        <h6 class="fw-bold mb-4 text-dark text-uppercase"><i class="fas fa-building me-2 text-info"></i>Top 5 Realisasi per Prodi</h6>
                        <div class="chart-box" style="height:250px;"><canvas id="chartProdiRev"></canvas></div>
                        <?php if(empty($prodi_labels)): ?>
                            <div class="text-center text-muted small mt-3 italic">Belum ada realisasi dari mahasiswa program studi.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ROW 4: ACTIONABLE ALERT (Tunggakan) -->
            <div class="row g-4 text-start">
                <div class="col-12">
                   <div class="card border-0 shadow-sm rounded-4 p-0 bg-white border-start border-danger border-4 overflow-hidden">
                        <div class="bg-danger bg-opacity-10 p-4 border-bottom border-danger border-opacity-25 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold text-danger text-uppercase mb-1"><i class="fas fa-exclamation-circle me-2"></i>Perhatian: Top 5 Tunggakan Piutang Terbesar</h6>
                                <small class="text-dark fw-bold opacity-75">Mahasiswa dengan sisa kewajiban tertinggi yang perlu segera ditindaklanjuti.</small>
                            </div>
                            <a href="index.php?page=tagihan_monitoring&status=Kontrol&tab=transaksi" class="btn btn-sm btn-danger rounded-pill fw-bold px-4 shadow-sm">Buka Menu Penagihan</a>
                        </div>
                        <div class="p-0">
                            <?php if(!empty($top_tunggakan)): ?>
                                <div class="row m-0">
                                    <?php foreach($top_tunggakan as $tg): ?>
                                    <div class="col-md-6 col-lg-4 p-0 border-end border-bottom">
                                        <div class="alert-list-item d-flex justify-content-between align-items-center h-100">
                                            <div>
                                                <div class="fw-bold text-dark fs-6"><?= strtoupper($tg['nama']) ?></div>
                                                <div class="small font-monospace text-muted mt-1"><i class="fas fa-id-card me-1"></i><?= $tg['nim'] ?></div>
                                                <div class="badge bg-light text-secondary border mt-2"><?= $tg['nama_tagihan'] ?></div>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-danger fw-bold d-block uppercase" style="font-size: 9px;">Sisa Tunggakan</small>
                                                <h5 class="fw-bold text-danger mb-0 mt-1">Rp <?= number_format($tg['sisa']) ?></h5>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 text-muted fw-bold">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3 d-block opacity-50"></i>
                                    Tidak ada tunggakan mahasiswa yang tercatat pada tahun <?= $tahun ?>.
                                </div>
                            <?php endif; ?>
                        </div>
                   </div>
                </div>
            </div>
            
        </div>
        <?php endif; ?>

        <!-- 2. HUB / WORKSHEET EDITOR -->
        <?php if(in_array('input', $allowed_tabs)): ?>
        <div class="tab-pane fade <?= $active_tab=='input'?'show active':'' ?>" id="tab-input">
            <?php if($view_mode == 'hub'): ?>
                <div class="text-center py-5 bg-white rounded-4 shadow-sm border mb-4">
                    <div class="d-inline-flex justify-content-center align-items-center bg-success rounded-circle mb-3" style="width: 80px; height: 80px;">
                        <i class="fas fa-wallet fa-2x text-white"></i>
                    </div>
                    <h4 class="fw-bold text-dark">Pusat Penyusunan Anggaran</h4>
                    <p class="text-muted small">Kelola lembar kerja anggaran pendapatan untuk perencanaan yang lebih akurat.</p>
                    <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                        <button class="btn btn-success rounded-pill px-5 py-3 fw-bold shadow-lg mt-2" onclick="triggerModalNew()"><i class="fas fa-plus me-2"></i>BUAT ANGGARAN BARU</button>
                    <?php endif; ?>
                </div>
                
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark shadow-sm">
                    <h6 class="fw-bold p-4 mb-0 border-bottom text-dark">Riwayat Lembar Kerja (Worksheet)</h6>
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="bg-light small text-uppercase">
                            <tr><th>Aksi</th><th class="text-start ps-4">Deskripsi Worksheet</th><th>Tahun</th><th>Total Anggaran</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if($history && $history->num_rows > 0): while($h = $history->fetch_assoc()): 
                                $is_approved = ($h['status'] == 'Approved' || $h['status'] == 'Generated');
                                $is_reviewed = ($h['status'] == 'Reviewed');
                                $is_draft    = ($h['status'] == 'Draft');
                            ?>
                            <tr class="<?= $is_approved ? 'row-approved' : '' ?>">
                                <td>
                                    <div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm">
                                        <a href="?page=anggaran_pendapatan&view=worksheet&header_id=<?= $h['id'] ?>&tab=input" class="btn btn-white text-success border-end" title="<?= (!$is_approved && defined('RBAC_EDIT') && RBAC_EDIT) ? 'Ubah/Tinjau' : 'Lihat Detail' ?>"><i class="fas <?= (!$is_approved && defined('RBAC_EDIT') && RBAC_EDIT) ? 'fa-edit' : 'fa-eye' ?>"></i></a>
                                        
                                        <?php if(defined('RBAC_ADD') && RBAC_ADD): ?>
                                            <button type="button" class="btn btn-white text-info border-end" onclick="triggerCloneModal(<?= $h['id'] ?>, '<?= addslashes($h['deskripsi']) ?>', '<?= $h['tahun_anggaran'] ?>')" title="Duplikasi"><i class="fas fa-clone"></i></button>
                                        <?php endif; ?>

                                        <?php if($is_draft && defined('RBAC_DEL') && RBAC_DEL): ?>
                                            <button onclick="confirmDelete(<?= $h['id'] ?>)" class="btn btn-white text-danger" title="Hapus"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-start ps-4 fw-bold">
                                    <span><?= htmlspecialchars($h['deskripsi']) ?></span>
                                    <?php if($is_draft && defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                        <i class="fas fa-pen-nib btn-rename" onclick="triggerRenameModal(<?= $h['id'] ?>, '<?= addslashes($h['deskripsi']) ?>')" title="Ubah Nama"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?= $h['tahun_anggaran'] ?></td>
                                <td class="text-end pe-4 fw-bold">Rp <?= number_format($h['total_anggaran']) ?></td>
                                
                                <td>
                                    <?php if($is_draft): ?>
                                        <span class="badge bg-secondary rounded-pill px-3">DRAFT</span>
                                    <?php elseif($is_reviewed): ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-3">MENUNGGU APPROVAL</span>
                                    <?php elseif($is_approved): ?>
                                        <span class="badge bg-success rounded-pill px-3">APPROVED</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; else: echo "<tr><td colspan='5' class='text-center py-5 text-muted small italic'>Belum ada riwayat lembar kerja pendapatan.</td></tr>"; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- WORKSHEET EDITOR VIEW -->
                <?php 
                    $h_data = $conn->query("SELECT * FROM syifa_budget_headers WHERE id=$header_id")->fetch_assoc(); 
                    $is_readonly = ($h_data['status'] == 'Approved' || $h_data['status'] == 'Reviewed' || $h_data['status'] == 'Generated' || !defined('RBAC_EDIT') || !RBAC_EDIT);
                ?>
                <div class="bg-white p-4 rounded-4 shadow-sm border-top border-success border-4 mb-4 text-dark shadow-sm">
                    <div class="d-flex justify-content-between align-items-center mb-4 px-2">
                        <a href="?page=anggaran_pendapatan&view=hub&tab=input&tahun=<?= $tahun ?>" class="btn btn-sm btn-light border rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-2"></i>Kembali</a>
                        <h5 class="fw-bold mb-0 text-success uppercase">PENYUSUNAN RAPB PENDAPATAN: <?= htmlspecialchars($h_data['deskripsi'] ?? '') ?></h5>
                        <?php if(!$is_readonly && defined('RBAC_ADD') && RBAC_ADD): ?>
                            <button class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="modalAddCategory()"><i class="fas fa-layer-group me-2"></i>Tambah Kategori</button>
                        <?php endif; ?>
                    </div>
                    <form action="index.php?page=anggaran_pendapatan" method="POST">
                        <input type="hidden" name="action" value="save_worksheet_income"><input type="hidden" name="header_id" value="<?= $header_id ?>">
                        <input type="hidden" name="tahun_anggaran_worksheet" value="<?= $h_data['tahun_anggaran'] ?>">
                        
                        <div class="table-responsive-supreme" id="supremeScrollContainerInput">
                            <table class="table-supreme">
                                <thead>
                                    <tr>
                                        <th class="ws-f-action">Opsi</th>
                                        <th class="ws-f-uraian">Uraian / Komponen Pendapatan</th>
                                        <th class="ws-f-coa">Akun COA Penerimaan</th>
                                        <th class="ws-f-pagu">Total Target Pagu (IDR)</th>
                                    </tr>
                                </thead>
                                <tbody id="ws_body">
                                    <?php if($has_hierarchy): $cats_res = $conn->query("SELECT * FROM syifa_budgets WHERE header_id=$header_id AND is_category=1 ORDER BY id ASC"); while($cat = $cats_res->fetch_assoc()): $u_key = "CAT_".rand(1000,9999).$cat['id']; $cat_sum = safeQuerySumLocal($conn, "SELECT SUM(nominal_pagu) FROM syifa_budgets WHERE parent_id={$cat['id']}"); ?>
                                        <tr class="tr-cat ws-row" id="row_<?= $u_key ?>">
                                            <input type="hidden" name="row_type[]" value="category"><input type="hidden" name="ui_key[]" value="<?= $u_key ?>"><input type="hidden" name="parent_key[]" value=""><input type="hidden" name="jenis[]" class="cat-jenis-input" value="Utama"><input type="hidden" name="meta_akademik[]" value=""><input type="hidden" name="coa[]" value=""><input type="hidden" name="total[]" value="0">
                                            <td class="ws-f-action text-center"><?php if(!$is_readonly && defined('RBAC_ADD') && RBAC_ADD): ?><button type="button" class="btn btn-xs btn-success rounded-circle shadow-sm" onclick="addChildRow('<?= $u_key ?>')" style="width:28px;height:28px;padding:0;"><i class="fas fa-plus"></i></button><?php endif; ?></td>
                                            <td class="ws-f-uraian text-start"><input type="text" name="uraian_manual[]" class="inp-uraian fw-bold text-uppercase" value="<?= $cat['uraian_manual'] ?>" <?= $is_readonly?'readonly':'' ?>></td>
                                            <td class="ws-f-coa text-center text-muted small">-</td>
                                            <td class="ws-f-pagu category-total-display fw-bold text-success" style="font-family:'JetBrains Mono';">IDR <?= number_format($cat_sum) ?></td>
                                        </tr>
                                        <?php $items = $conn->query("SELECT b.*, a.nama_akun FROM syifa_budgets b LEFT JOIN syifa_akun a ON b.kode_akun = a.kode_akun WHERE b.parent_id={$cat['id']} ORDER BY b.id ASC"); while($item = $items->fetch_assoc()): 
                                            $item_row_id = 'item_row_'.$item['id']; 
                                            $nama_akun_cek = strtolower($item['nama_akun'] ?? '');
                                            $is_academic = (strpos($nama_akun_cek, 'bop') !== false || strpos($nama_akun_cek, 'pendidikan') !== false || strpos($nama_akun_cek, 'spp') !== false || strpos($nama_akun_cek, 'skripsi') !== false || strpos($nama_akun_cek, 'akademik') !== false || strpos($nama_akun_cek, 'pendaftaran') !== false);
                                            // ??? Tarik JSON Metadata
                                            $meta_val = (strpos($item['sumber_data'], '{') === 0) ? $item['sumber_data'] : '';
                                        ?>
                                            <tr id="<?= $item_row_id ?>" class="child-of-<?= $u_key ?> ws-row bg-white">
                                                <input type="hidden" name="row_type[]" value="item"><input type="hidden" name="ui_key[]" value="ITEM_<?= rand() ?>"><input type="hidden" name="parent_key[]" value="<?= $u_key ?>"><input type="hidden" name="jenis[]" class="child-jenis-input" value="Utama">
                                                <input type="hidden" name="meta_akademik[]" class="meta-akademik-input" value='<?= $meta_val ?>'>
                                                <td class="ws-f-action text-center"><?php if(!$is_readonly && defined('RBAC_DEL') && RBAC_DEL): ?><button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="deleteChildRow(this, '<?= $u_key ?>')"><i class="fas fa-times-circle"></i></button><?php endif; ?></td>
                                                <td class="ws-f-uraian text-start" style="padding-left:35px !important;"><input type="text" name="uraian_manual[]" class="inp-uraian" value="<?= $item['uraian_manual'] ?>" placeholder="Rincian sumber pendapatan..." <?= $is_readonly?'readonly':'' ?>></td>
                                                <td class="ws-f-coa coa-search-container">
                                                    <div class="input-group">
                                                        <input type="text" class="form-control coa-search-input text-center py-1" value="<?= $item['kode_akun'] ?>" placeholder="Ketik COA..." <?= $is_readonly?'readonly':'' ?>>
                                                        <input type="hidden" name="coa[]" class="coa-hidden-val" value="<?= $item['kode_akun'] ?>">
                                                        <?php if(!$is_readonly): ?>
                                                        <button class="btn btn-outline-success btn-sm px-2 calc-btn <?= $is_academic ? '' : 'd-none' ?>" type="button" onclick="openSmartCalc('<?= $item_row_id ?>', '<?= addslashes($item['nama_akun'] ?? 'Target Penerimaan') ?>')" title="Buka Kalkulator Akademik"><i class="fas fa-calculator"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="ws-f-pagu">
                                                    <input type="text" name="total[]" class="inp-amt target-val" onkeyup="fmtRp(this); updateGrandTotalPagu(); updateCatTotal('<?= $u_key ?>');" value="<?= number_format($item['nominal_pagu'],0,',','.') ?>" <?= $is_readonly?'readonly':'' ?>>
                                                </td>
                                            </tr>
                                        <?php endwhile; endwhile; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- GRAND TOTAL BOX -->
                        <div class="d-flex justify-content-end mt-3 mb-2 px-3">
                            <div class="bg-dark text-white rounded-pill px-4 py-2 shadow-sm d-flex align-items-center gap-3">
                                <span class="small fw-bold text-uppercase opacity-75">Total Target Keseluruhan:</span>
                                <span class="fs-5 fw-bold text-success" style="font-family: 'JetBrains Mono', monospace;" id="grandTotalPaguDisplay">IDR 0</span>
                            </div>
                        </div>

                        <div class="text-center mt-4 mb-2">
                            <?php if($h_data['status'] == 'Draft'): ?>
                                <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                    <button type="submit" name="draft" class="btn btn-warning rounded-pill px-5 py-3 fw-bold shadow me-2 text-uppercase text-dark"><i class="fas fa-save me-2"></i>Simpan Draft</button>
                                    <button type="submit" name="final" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase"><i class="fas fa-paper-plane me-2"></i>Ajukan Persetujuan (Approval)</button>
                                <?php endif; ?>
                            <?php elseif($h_data['status'] == 'Reviewed'): ?>
                                <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                    <button type="submit" name="cancel" class="btn btn-danger rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase ms-2" onclick="return confirm('Tarik kembali pengajuan ini ke Draft?')"><i class="fas fa-undo me-2"></i>Tarik Pengajuan (Kembali ke Draft)</button>
                                <?php endif; ?>
                                <?php if(in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root): ?>
                                    <button type="button" class="btn btn-success rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase ms-2" onclick="approveAction({action: 'approve_budget_async', id: <?= $header_id ?>}, this)"><i class="fas fa-check-circle me-2"></i>Approve & Sahkan Target</button>
                                <?php endif; ?>
                            <?php elseif($h_data['status'] == 'Approved' || $h_data['status'] == 'Generated'): ?>
                                <button type="button" class="btn btn-secondary rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase" disabled><i class="fas fa-lock me-2"></i>RAPB Telah Disahkan & Terkunci</button>
                                <?php if(defined('RBAC_DEL') && RBAC_DEL && (in_array($workflow_auth, ['APPROVER', 'PIMPINAN', 'ALL']) || $is_superadmin_root)): ?>
                                    <button type="button" class="btn btn-danger rounded-pill px-5 py-3 fw-bold shadow-lg text-uppercase ms-2" onclick="cancelApproveAction({action: 'cancel_approval_async', id: <?= $header_id ?>}, this)"><i class="fas fa-undo me-1"></i> Batal Approve</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 3. TAB MONITORING (TRUE HYBRID ACCRUAL-CASH ENGINE) -->
        <?php if(in_array('monitoring', $allowed_tabs)): ?>
        <div class="tab-pane fade <?= $active_tab=='monitoring'?'show active':'' ?>" id="tab-monitoring">
            <div class="supreme-container shadow-sm bg-white rounded-4 text-dark shadow-sm">
                
                <div class="p-4 border-bottom bg-light d-flex align-items-center">
                    <div class="bg-success text-white rounded-circle d-flex justify-content-center align-items-center me-3 shadow-sm" style="width: 45px; height: 45px;">
                        <i class="fas fa-search-dollar fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-1 text-success">Monitoring Realisasi Kas Per Komponen</h5>
                        <small class="text-muted fw-bold">Sinkronisasi Hibrida: Mencocokkan penerimaan tunai langsung & pembayaran billing mahasiswa secara akurat.</small>
                    </div>
                </div>

                <div class="table-responsive-supreme" id="supremeScrollContainerMon">
                    <table class="table-supreme table-hover align-middle mb-0 w-100">
                        <thead>
                            <tr>
                                <th class="mon-f-uraian text-start ps-4">Uraian Anggaran Pendapatan</th>
                                <th class="mon-f-pagu">Target Pendapatan</th>
                                <th class="mon-f-real">Realisasi</th>
                                <th class="mon-f-sisa">Sisa Target Belum Tercapai</th>
                                <th class="mon-f-persen pe-4">% Capaian</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $res_cat = $conn->query("SELECT b.* FROM syifa_budgets b JOIN syifa_budget_headers h ON b.header_id = h.id WHERE b.tahun_anggaran='$tahun' AND b.is_category=1 AND h.status='Approved' AND b.status='Disetujui' ORDER BY b.id ASC");
                            if($res_cat && $res_cat->num_rows > 0) { 
                                while($cat = $res_cat->fetch_assoc()) { 
                                    $sub_p = 0; $sub_r = 0; 
                            ?>
                                <tr class="tr-cat"><td colspan="5" class="ps-3 text-uppercase"><i class="fas fa-folder-open me-2 text-primary"></i><?= $cat['uraian_manual'] ?></td></tr>
                                <?php 
                                $items = $conn->query("SELECT * FROM syifa_budgets WHERE parent_id={$cat['id']} AND status='Disetujui'");
                                while($i = $items->fetch_assoc()) {
                                    $rt = $calculated_items[$i['id']]['rt'] ?? 0;
                                    $sub_p += (double)$i['nominal_pagu']; $sub_r += (double)$rt;
                                    $pct = ($i['nominal_pagu'] > 0) ? round(($rt / $i['nominal_pagu']) * 100, 1) : 0; 
                                ?>
                                <tr><td class="ps-5 text-dark fw-bold"><?= $i['uraian_manual'] ?> <br><small class='text-muted'><?= $i['kode_akun'] ?></small></td><td class="text-end fw-bold">Rp <?= number_format($i['nominal_pagu']) ?></td><td class="text-end text-success fw-bold">Rp <?= number_format($rt) ?></td><td class="text-end text-muted">Rp <?= number_format($i['nominal_pagu']-$rt) ?></td><td class="text-center fw-bold"><?= $pct ?>%</td></tr>
                                <?php } ?>
                                <tr class="tr-subtotal"><td class="ps-4 text-uppercase text-dark"><i class="fas fa-calculator me-2 opacity-50"></i>Total <?= $cat['uraian_manual'] ?></td><td class="text-end">Rp <?= number_format($sub_p) ?></td><td class="text-end">Rp <?= number_format($sub_r) ?></td><td class="text-end">Rp <?= number_format($sub_p-$sub_r) ?></td><td class="text-center"><?= ($sub_p>0?round(($sub_r/$sub_p)*100,1):0) ?>%</td></tr>
                            <?php 
                                } // end while $cat
                            ?>
                            
                            <?php if($unmapped_realization > 0) { ?>
                            <tr class="unmapped-row"><td class="ps-4">Pendapatan Kasir Tak Terpetakan (Luar Filter Kohort)</td><td class="text-end">-</td><td class="text-end">Rp <?= number_format($unmapped_realization) ?></td><td class="text-end">+ Rp <?= number_format($unmapped_realization) ?></td><td class="text-center">Extra</td></tr>
                            <?php } ?>

                            <tr class="row-grand-total"><td class="ps-4 py-3">GRAND TOTAL PENDAPATAN INSTITUSI</td><td class="text-end">Rp <?= number_format($total_pagu_dash) ?></td><td class="text-end">Rp <?= number_format($total_real_final) ?></td><td class="text-end">Rp <?= number_format($total_pagu_dash-$total_real_final) ?></td><td class="text-center"><?= round($pencapaian, 1) ?>%</td></tr>
                            <?php 
                            } else { 
                                echo '<tr><td colspan="5" class="text-center py-5 text-muted small italic">Data penerimaan formal belum tersedia.</td></tr>'; 
                            } 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- EXECUTIVE MODALS -->
<!-- ??? MODAL BUAT WORKSHEET BARU -->
<div class="modal fade" id="mdlNew" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="index.php?page=anggaran_pendapatan" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="create_header">
            <div class="modal-header bg-success text-white p-4 border-0 text-center d-block">
                <i class="fas fa-wallet fa-3x mb-3 animate__animated animate__pulse animate__infinite"></i>
                <h5 class="modal-title fw-bold text-white">Buat Lembar Kerja (RAPB) Baru</h5>
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark text-center">
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Deskripsi / Nama Worksheet</label>
                    <input type="text" name="deskripsi" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold" placeholder="Contoh: RAPB Pendapatan 2026" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Tahun Anggaran</label>
                    <input type="number" name="tahun_anggaran" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold text-success" value="<?= $tahun ?>" required>
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white">
                <button type="submit" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow">BUAT WORKSHEET</button>
            </div>
        </form>
    </div>
</div>

<!-- ??? SMART CALCULATOR ACADEMIC REVENUE MODAL -->
<div class="modal fade" id="mdlSmartCalc" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-success text-white p-4 border-0">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-calculator me-2"></i><span id="calcTitle">Kalkulator Target Pendapatan</span></h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="hidden" id="calc_target_row">
                <input type="hidden" id="calc_uraian_base">
                
                <div class="row g-3 mb-3 text-start">
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Program Studi</label>
                        <select id="calc_prodi" class="form-select rounded-3 shadow-sm border-0 fw-bold text-dark">
                            <option value="">-- Semua Prodi --</option>
                            <?php foreach($master_prodi as $p) echo "<option value='{$p['id']}'>{$p['nama_prodi']}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Sistem Pendidikan</label>
                        <select id="calc_sistem" class="form-select rounded-3 shadow-sm border-0 fw-bold text-dark">
                            <option value="">-- Semua Sistem --</option>
                            <?php foreach($master_sistem as $s) echo "<option value='{$s['kode_sistem']}'>{$s['nama_sistem']}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Tahun Angkatan</label>
                        <select id="calc_angkatan" class="form-select rounded-3 shadow-sm border-0 fw-bold text-dark">
                            <option value="">-- Semua Angkatan --</option>
                            <?php foreach($master_angkatan as $a) echo "<option value='{$a['kode_masuk']}'>{$a['nama_masuk']}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Periode Semester</label>
                        <select id="calc_semester" class="form-select rounded-3 shadow-sm border-0 fw-bold text-dark">
                            <option value="Ganjil">Semester Ganjil</option>
                            <option value="Genap">Semester Genap</option>
                        </select>
                    </div>
                </div>

                <hr class="my-4 opacity-25">

                <div class="row g-3 align-items-end text-start">
                    <div class="col-md-4">
                        <label class="small fw-bold text-muted uppercase mb-1">Jml Mahasiswa <i class="fas fa-link text-success ms-1" title="Live Sync ke Data Akademik"></i></label>
                        <div class="input-group shadow-sm rounded-3 overflow-hidden">
                            <span class="input-group-text bg-white border-0"><i class="fas fa-users text-primary"></i></span>
                            <input type="number" id="calc_jml_mhs" class="form-control border-0 fw-bold text-center fs-5 text-primary" value="0" onkeyup="calculateSmartTotal()" onchange="calculateSmartTotal()">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="small fw-bold text-muted uppercase mb-1">Tarif per Mhs (Rp)</label>
                        <input type="text" id="calc_tarif" class="form-control rounded-3 border-0 shadow-sm fw-bold text-end fs-5 text-dark" value="0" onkeyup="fmtRp(this); calculateSmartTotal()">
                    </div>
                    <div class="col-md-4">
                        <label class="small fw-bold text-success uppercase mb-1">Proyeksi Pendapatan</label>
                        <input type="hidden" id="calc_total_raw" value="0">
                        <input type="text" id="calc_total_display" class="form-control rounded-3 border-0 shadow-sm fw-bold text-end fs-5 bg-success text-white" value="Rp 0" readonly>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-3 border-0 bg-white">
                <button type="button" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow-sm" onclick="applySmartCalc()">TERAPKAN KE WORKSHEET</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DUPLIKASI -->
<div class="modal fade" id="mdlClone" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="index.php?page=anggaran_pendapatan" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="duplicate_header">
            <input type="hidden" name="id" id="clone_id">
            <div class="modal-header bg-info text-white p-4 border-0 text-center d-block">
                <i class="fas fa-clone fa-3x mb-3 animate__animated animate__pulse animate__infinite"></i>
                <h5 class="modal-title fw-bold text-white">Duplikasi Lembar Kerja</h5>
            </div>
            <div class="modal-body p-4 bg-light text-dark text-center">
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Nama Baru</label>
                    <input type="text" name="new_name" id="clone_new_name" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted uppercase">Tahun Anggaran Tujuan</label>
                    <input type="number" name="new_year" id="clone_new_year" class="form-control rounded-pill border-0 shadow-sm px-4 py-3 text-center fw-bold text-primary" required>
                </div>
                <p class="small text-muted italic">Seluruh rincian akan disalin ke tahun baru dengan status <b>Draft</b>.</p>
            </div>
            <div class="modal-footer p-4 border-0 bg-white"><button type="submit" class="btn btn-info text-white w-100 rounded-pill py-3 fw-bold shadow">DUPLIKAT SEKARANG</button></div>
        </form>
    </div>
</div>

<!-- MODAL RENAME -->
<div class="modal fade" id="mdlRename" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="index.php?page=anggaran_pendapatan" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="rename_worksheet">
            <input type="hidden" name="id" id="rename_id">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-pen-nib me-2"></i>Ubah Nama Worksheet</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <label class="small fw-bold text-muted mb-1 uppercase">Nama Baru</label>
                <input type="text" name="nama_baru" id="rename_name_input" class="form-control rounded-pill border-0 shadow-sm px-4 py-2 fw-bold" required>
            </div>
            <div class="modal-footer p-4 border-0 bg-white"><button type="submit" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow">UPDATE NAMA</button></div>
        </form>
    </div>
</div>

<!-- ??? WORKFLOW POPUP PORTAL -->
<div class="modal fade" id="mdlWorkflowPortal" data-bs-backdrop="static" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered text-dark modal-sm"> 
        <div class="modal-content shadow-lg" style="border-radius: 28px !important;">
            <div class="modal-header p-4 border-0 text-center d-block" id="modalHeader"><i id="modalIcon" class="fas fa-check-circle fa-4x mb-3 animate__animated animate__bounceIn"></i><h4 class="modal-title fw-bold mb-0" id="modalTitle">PROSES BERHASIL</h4></div>
            <div class="modal-body p-4 bg-light text-dark text-center">
                <div class="card border-0 rounded-4 shadow-sm p-4 mb-3 bg-white" id="cardMainTotal"><small class="text-muted fw-bold text-uppercase d-block mb-1" id="labelMain" style="font-size:9px;">Total Anggaran</small><h4 class="fw-bold text-primary mb-0" id="m_total">Rp 0</h4></div>
            </div>
            <div class="modal-footer p-3 border-0 bg-white" id="modalFooterPortal"><button type="button" class="btn btn-dark w-100 rounded-pill py-2 fw-bold shadow-sm" onclick="closePortal()">LANJUTKAN</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="mdlAddCat" tabindex="-1" role="dialog"><div class="modal-dialog modal-dialog-centered text-dark"><div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden"><div class="modal-header bg-dark text-white p-4 border-0"><h5 class="modal-title fw-bold text-white">Tambah Kategori Target</h5><button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button></div><div class="modal-body p-4 bg-light text-dark"><div class="mb-3 text-center"><label class="small fw-bold text-muted uppercase">Nama Kategori</label><input type="text" id="cat_name" class="form-control rounded-pill border-0 shadow-sm px-3 text-center" placeholder="Misal: Penerimaan Mahasiswa Baru"></div></div><div class="modal-footer p-4 border-0 bg-white text-center d-block"><button type="button" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow" onclick="saveCategoryRow()">TAMBAHKAN KE LEMBAR KERJA</button></div></div></div></div>

<script>
// ?? SMART CALCULATOR ENGINE LOGIC DENGAN LIVE SYNC MAHASISWA
function openSmartCalc(rowId, coaName) {
    document.getElementById('calc_target_row').value = rowId;
    document.getElementById('calcTitle').innerText = "Target " + coaName.substring(0,25) + "..";
    document.getElementById('calc_uraian_base').value = coaName;
    
    document.getElementById('calc_jml_mhs').value = 0;
    document.getElementById('calc_tarif').value = 0;
    calculateSmartTotal();
    
    fetchRealMahasiswa(); 
    new bootstrap.Modal(document.getElementById('mdlSmartCalc')).show();
}

function calculateSmartTotal() {
    const jml = prs(document.getElementById('calc_jml_mhs').value) || 0;
    const tarif = prs(document.getElementById('calc_tarif').value) || 0;
    const total = jml * tarif;
    document.getElementById('calc_total_raw').value = total;
    document.getElementById('calc_total_display').value = 'Rp ' + new Intl.NumberFormat('id-ID').format(total);
}

function applySmartCalc() {
    const rowId = document.getElementById('calc_target_row').value;
    const row = document.getElementById(rowId);
    if(!row) return;

    const prodiSel = document.getElementById('calc_prodi');
    const prodiText = prodiSel.options[prodiSel.selectedIndex].value ? prodiSel.options[prodiSel.selectedIndex].text : 'Semua Prodi';
    const prodiVal = prodiSel.value;
    
    const angkatanSel = document.getElementById('calc_angkatan');
    const angkatanText = angkatanSel.options[angkatanSel.selectedIndex].value ? angkatanSel.options[angkatanSel.selectedIndex].text : 'Semua Angkatan';
    const angkatanVal = angkatanSel.value;
    
    const semester = document.getElementById('calc_semester').value;
    
    const sistemSel = document.getElementById('calc_sistem');
    const sistemText = sistemSel.options[sistemSel.selectedIndex].value ? sistemSel.options[sistemSel.selectedIndex].text : 'Semua Sistem';
    const sistemVal = sistemSel.value;
    
    const jml = document.getElementById('calc_jml_mhs').value;
    const total = prs(document.getElementById('calc_total_raw').value);
    const baseName = document.getElementById('calc_uraian_base').value;

    const finalUraian = `[Target] ${baseName} - ${prodiText} (${sistemText}) ${angkatanText} - Smt ${semester} [${jml} Mhs]`;
    row.querySelector('.inp-uraian').value = finalUraian;
    
    // ??? SUNTIKAN JSON METADATA KE DALAM ROW HIDDEN INPUT
    const meta = { prodi: prodiVal, sistem: sistemVal, angkatan: angkatanVal, semester: semester };
    row.querySelector('.meta-akademik-input').value = JSON.stringify(meta);
    
    const targetInp = row.querySelector('.target-val');
    targetInp.value = new Intl.NumberFormat('id-ID').format(total);
    
    updateCatTotal(row.className.match(/child-of-(CAT_\d+)/)[1]);
    updateGrandTotalPagu(); 
    
    bootstrap.Modal.getInstance(document.getElementById('mdlSmartCalc')).hide();
}

function fetchRealMahasiswa() {
    const prodi = document.getElementById('calc_prodi').value;
    const sistem = document.getElementById('calc_sistem').value;
    const angkatan = document.getElementById('calc_angkatan').value;
    const jmlInp = document.getElementById('calc_jml_mhs');
    
    jmlInp.parentElement.classList.add('opacity-50'); 
    
    fetch(`index.php?page=anggaran_pendapatan&action=count_mhs&ajax=1&prodi=${prodi}&sistem=${sistem}&angkatan=${angkatan}`)
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') {
            jmlInp.value = res.jml;
            calculateSmartTotal();
        }
        jmlInp.parentElement.classList.remove('opacity-50');
    }).catch(e => {
        jmlInp.parentElement.classList.remove('opacity-50');
    });
}

document.getElementById('calc_prodi').addEventListener('change', fetchRealMahasiswa);
document.getElementById('calc_angkatan').addEventListener('change', fetchRealMahasiswa);
document.getElementById('calc_sistem').addEventListener('change', fetchRealMahasiswa);


// --- CORE SYSTEM SCRIPTS ---
function approveAction(data, btn) {
    if(!confirm("Setujui dan Sahkan RAPB ini menjadi Pagu Target Tahunan?")) return;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    fetch('index.php?page=anggaran_pendapatan', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') { window.location.reload(); }
        else { alert(res.msg); btn.innerHTML = '<i class="fas fa-check me-1"></i> APPROVE'; btn.disabled = false; }
    })
    .catch(e => { window.location.reload(); });
}

function cancelApproveAction(data, btn) {
    if(!confirm("Batalkan persetujuan RAPB ini dan kembalikan ke status Draft agar dapat diedit kembali?")) return;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;
    fetch('index.php?page=anggaran_pendapatan', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(data)
    })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') { window.location.reload(); }
        else { alert(res.msg); btn.innerHTML = '<i class="fas fa-undo me-1"></i> Batal Approve'; btn.disabled = false; }
    })
    .catch(e => { window.location.reload(); });
}

function confirmDelete(id) { 
    if(confirm('Hapus lembar kerja ini secara permanen?')) { 
        window.location.href = "index.php?page=anggaran_pendapatan&action=delete_header&id=" + id; 
    } 
}

const masterCOA = <?= json_encode($coa_list) ?>;
let chartInstances = {};

const coaFloatingBox = document.createElement("div");
coaFloatingBox.className = "coa-results-list";
document.body.appendChild(coaFloatingBox);

function triggerModalNew() { bootstrap.Modal.getOrCreateInstance(document.getElementById('mdlNew')).show(); }

function modalAddCategory() { const modalEl = document.getElementById('mdlAddCat'); const modal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: true, focus: true }); modal.show(); }

function triggerCloneModal(id, oldName, oldYear) { 
    document.getElementById('clone_id').value = id; 
    document.getElementById('clone_new_name').value = oldName + " (Copy)"; 
    document.getElementById('clone_new_year').value = oldYear;
    new bootstrap.Modal(document.getElementById('mdlClone')).show(); 
}

function triggerRenameModal(id, name) { document.getElementById('rename_id').value = id; document.getElementById('rename_name_input').value = name; new bootstrap.Modal(document.getElementById('mdlRename')).show(); }

function closePortal() {
    const modalEl = document.getElementById('mdlWorkflowPortal');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) return;
    modal.hide();
    modalEl.addEventListener('hidden.bs.modal', function handler() {
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style = '';
        document.body.style.pointerEvents = 'auto';
        const url = new URL(window.location.href);
        url.searchParams.delete('msg_type'); url.searchParams.delete('t'); url.searchParams.delete('ops'); url.searchParams.delete('dev'); url.searchParams.delete('new_id');
        window.history.replaceState({}, document.title, url.pathname + url.search);
        modalEl.removeEventListener('hidden.bs.modal', handler);
    });
}

document.addEventListener('hidden.bs.modal', function () {
    setTimeout(() => {
        if (document.querySelectorAll('.modal.show').length === 0) {
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style = '';
            document.body.style.pointerEvents = 'auto';
        }
    }, 200);
});

function safeCreateChart(id, config){ if(chartInstances[id]) { chartInstances[id].destroy(); } const ctx = document.getElementById(id); if(ctx) { chartInstances[id] = new Chart(ctx, config); } }

function initBudgetCharts() {
    if (typeof Chart === "undefined") return;
    
    // 1. Chart Line Trend (Existing)
    const trendOpsDev = <?= json_encode(array_values($trend_data), JSON_NUMERIC_CHECK) ?>;
    safeCreateChart('chartTrendIncome', { 
        type: 'line', 
        data: { 
            labels: ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'], 
            datasets: [{ label: 'Realisasi Kas', data: trendOpsDev, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: '#fff', borderWidth: 3 }] 
        }, 
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, suggestedMax: 1000000, ticks: { callback: function(val) { return 'Rp ' + (val/1000000) + ' Jt'; } } } }, plugins: { legend: { display: false } } } 
    });
    
    // 2. Chart Donut (Existing)
    const breakdownData = <?= json_encode($breakdown_real, JSON_NUMERIC_CHECK) ?>;
    safeCreateChart('chartDonutIncome', { 
        type: 'doughnut', 
        data: { 
            labels: breakdownData.map(b => b.uraian), 
            datasets: [{ data: breakdownData.map(b => b.val), backgroundColor: ['#10b981', '#0d6efd', '#f59e0b', '#ef4444', '#6366f1', '#ec4899', '#8b5cf6', '#14b8a6', '#f97316', '#06b6d4'], borderWidth: 0 }] 
        }, 
        options: { 
            responsive: true, maintainAspectRatio: false, cutout: '65%', 
            plugins: { 
                legend: { display: false }, 
                tooltip: { callbacks: { label: (ctx) => { const v = ctx.raw; const t = ctx.dataset.data.reduce((a,b)=>a+b,0); return [ctx.label, 'Realisasi: Rp '+new Intl.NumberFormat('id-ID').format(v), 'Andil: '+((v/t)*100).toFixed(1)+'%']; } } } 
            } 
        } 
    });

    // 3. ?? Chart Bar Horizontal (BARU: Prodi Realisasi)
    const prodiLabels = <?= json_encode($prodi_labels) ?>;
    const prodiValues = <?= json_encode($prodi_values, JSON_NUMERIC_CHECK) ?>;
    if(document.getElementById('chartProdiRev') && prodiLabels.length > 0) {
        safeCreateChart('chartProdiRev', {
            type: 'bar',
            data: {
                labels: prodiLabels,
                datasets: [{
                    label: 'Penerimaan Kas (Rp)',
                    data: prodiValues,
                    backgroundColor: '#3b82f6',
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y', // Membuat bar menjadi horizontal
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => 'Rp ' + new Intl.NumberFormat('id-ID').format(ctx.raw) } }
                },
                scales: {
                    x: { display: false, grid: { display: false }, suggestedMax: 1000000 },
                    y: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }
}

function positionCoaBox(input) { const rect = input.getBoundingClientRect(); coaFloatingBox.style.top = (rect.bottom + 4) + "px"; coaFloatingBox.style.left = rect.left + "px"; coaFloatingBox.style.width = rect.width + "px"; }
function syncFloatingPosition() { const active = document.activeElement; if(active && active.classList.contains("coa-search-input") && coaFloatingBox.style.display === "block") { positionCoaBox(active); } }

function initSmoothHorizontalDrag() {
    const containers = document.querySelectorAll('.table-responsive-supreme');
    containers.forEach(container => {
        let isDown = false; let startX; let scrollLeft;
        container.addEventListener('mousedown', (e) => { 
            if (e.target.tagName === 'INPUT' || e.target.closest('.btn') || e.target.closest('.coa-item') || e.target.tagName === 'SELECT') return; 
            isDown = true; startX = e.pageX - container.offsetLeft; scrollLeft = container.scrollLeft; 
            container.style.cursor = 'grabbing'; container.style.userSelect = 'none'; 
        });
        const stop = () => { isDown = false; container.style.cursor = 'grab'; container.style.removeProperty('user-select'); };
        container.addEventListener('mouseleave', stop); container.addEventListener('mouseup', stop);
        container.addEventListener('mousemove', (e) => { if (!isDown) return; e.preventDefault(); const x = e.pageX - container.offsetLeft; const walk = (x - startX) * 1.2; container.scrollLeft = scrollLeft - walk; });
    });
}

document.addEventListener("input", function(e) { if(e.target.classList.contains("coa-search-input")) { filterCoaList(e.target); } });
document.addEventListener("click", function(e) { if(!e.target.classList.contains("coa-search-input") && !e.target.closest(".coa-results-list")) { coaFloatingBox.style.display = "none"; } });
window.addEventListener("scroll", syncFloatingPosition);
window.addEventListener("resize", syncFloatingPosition);
document.querySelectorAll('.table-responsive-supreme').forEach(el => el.addEventListener("scroll", syncFloatingPosition));

function filterCoaList(input) {
    positionCoaBox(input);
    const keyword = input.value.toLowerCase();
    coaFloatingBox.innerHTML = '';
    if(keyword.length === 0){ coaFloatingBox.style.display = 'none'; return; }
    const filtered = masterCOA.filter(c => c.kode_akun.toLowerCase().includes(keyword) || c.nama_akun.toLowerCase().includes(keyword)).slice(0,15);
    if(filtered.length === 0){ coaFloatingBox.style.display = 'none'; return; }
    filtered.forEach(c=>{
        const item = document.createElement("div"); item.className = "coa-item";
        item.innerHTML = `<code>${c.kode_akun}</code> ${c.nama_akun}`;
        item.addEventListener("click", function(){ 
            input.value = `${c.kode_akun} - ${c.nama_akun}`; 
            const row = input.closest('tr');
            row.querySelector(".coa-hidden-val").value = c.kode_akun; 
            coaFloatingBox.style.display = "none"; 
            
            const coaNameLower = c.nama_akun.toLowerCase();
            const isAcademic = (coaNameLower.includes('bop') || coaNameLower.includes('pendidikan') || coaNameLower.includes('spp') || coaNameLower.includes('skripsi') || coaNameLower.includes('akademik') || coaNameLower.includes('pendaftaran') || coaNameLower.includes('kian'));
            
            const calcBtn = row.querySelector('.calc-btn');
            if(calcBtn) {
                if(isAcademic) {
                    calcBtn.classList.remove('d-none');
                    calcBtn.onclick = function() { openSmartCalc(row.id, c.nama_akun); };
                    openSmartCalc(row.id, c.nama_akun);
                } else {
                    calcBtn.classList.add('d-none');
                }
            }
        });
        coaFloatingBox.appendChild(item);
    });
    coaFloatingBox.style.display = "block";
}

function saveCategoryRow() {
    const name = document.getElementById('cat_name').value; if(!name) return;
    const uKey = 'CAT_' + Date.now(); const tr = document.createElement('tr'); tr.className = 'tr-cat ws-row'; tr.id = `row_${uKey}`;
    tr.innerHTML = `<input type="hidden" name="row_type[]" value="category"><input type="hidden" name="ui_key[]" value="${uKey}"><input type="hidden" name="parent_key[]" value=""><input type="hidden" name="jenis[]" class="cat-jenis-input" value="Utama"><input type="hidden" name="meta_akademik[]" value=""><input type="hidden" name="coa[]" value=""><input type="hidden" name="total[]" value="0"><td class="ws-f-action text-center"><button type="button" class="btn btn-xs btn-success rounded-circle shadow-sm" onclick="addChildRow('${uKey}')" style="width:28px;height:28px;padding:0;"><i class="fas fa-plus"></i></button></td><td class="ws-f-uraian text-start"><input type="text" name="uraian_manual[]" class="inp-uraian fw-bold text-uppercase" value="${name}"></td><td class="ws-f-coa text-center text-muted small">-</td><td class="ws-f-pagu category-total-display fw-bold text-success">IDR 0</td>`;
    document.getElementById('ws_body').appendChild(tr); const modal = bootstrap.Modal.getInstance(document.getElementById('mdlAddCat')); if(modal) modal.hide(); document.getElementById('cat_name').value = '';
}

function addChildRow(uKey) {
    const randId = Date.now() + Math.floor(Math.random() * 1000);
    const rowId = `item_row_${randId}`; 
    
    const tr = document.createElement('tr'); tr.id = rowId; tr.className = `child-of-${uKey} ws-row bg-white`;
    tr.innerHTML = `<input type="hidden" name="row_type[]" value="item"><input type="hidden" name="ui_key[]" value="ITEM_${randId}"><input type="hidden" name="parent_key[]" value="${uKey}"><input type="hidden" name="jenis[]" class="child-jenis-input" value="Utama"><input type="hidden" name="meta_akademik[]" class="meta-akademik-input" value=""><td class="ws-f-action text-center"><button type="button" class="btn btn-link text-danger p-0 shadow-none" onclick="deleteChildRow(this, '${uKey}')"><i class="fas fa-times-circle"></i></button></td><td class="ws-f-uraian text-start" style="padding-left:35px !important;"><input type="text" name="uraian_manual[]" class="inp-uraian" placeholder="Rincian pendapatan..."></td><td class="ws-f-coa coa-search-container"><div class="input-group"><input type="text" class="form-control coa-search-input text-center py-1" placeholder="Ketik COA..."><input type="hidden" name="coa[]" class="coa-hidden-val"><button class="btn btn-outline-success btn-sm px-2 calc-btn d-none" type="button" title="Buka Kalkulator Akademik"><i class="fas fa-calculator"></i></button></div></td><td class="ws-f-pagu"><input type="text" name="total[]" class="inp-amt target-val" onkeyup="fmtRp(this); updateGrandTotalPagu(); updateCatTotal('${uKey}');" value="0"></td>`;
    const lastRow = Array.from(document.querySelectorAll(`.child-of-${uKey}`)).pop() || document.getElementById(`row_${uKey}`); lastRow.after(tr); 
}

function deleteChildRow(btn, uKey) {
    btn.closest('tr').remove(); 
    updateCatTotal(uKey);
    updateGrandTotalPagu();
}

// ?? ENGINE PENANGKAP POP-UP (SUCCESS HANDLER)
document.addEventListener("DOMContentLoaded", function() {
    initBudgetCharts(); initSmoothHorizontalDrag(); 
    const p = new URLSearchParams(window.location.search); const mType = p.get('msg_type');
    
    if (mType) {
        document.getElementById('m_total').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(p.get('t') || 0);
        
        if (mType === 'success_review') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-info text-white";
            document.getElementById('modalTitle').innerText = "PENGAJUAN TERKIRIM";
            document.getElementById('modalIcon').className = "fas fa-paper-plane fa-4x mb-3 text-white";
            document.getElementById('labelMain').innerText = "Total Pengajuan Menunggu Approval";
        } else if (mType === 'success_create') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-success text-white";
            document.getElementById('modalTitle').innerText = "WORKSHEET BERHASIL DIBUAT";
            document.getElementById('modalIcon').className = "fas fa-clipboard-check fa-4x mb-3 text-white";
            
            document.getElementById('labelMain').innerText = "";
            document.getElementById('m_total').innerHTML = "Lembar Kerja Siap Digunakan";
            document.getElementById('m_total').className = "fw-bold text-success mb-0 fs-5 mt-1";
            
            const newId = p.get('new_id');
            const footer = document.getElementById('modalFooterPortal');
            footer.innerHTML = `
                <button type="button" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase mb-2" onclick="window.location.href='index.php?page=anggaran_pendapatan&view=worksheet&header_id=${newId}&tab=input&tahun=<?= $tahun ?>'"><i class="fas fa-arrow-right me-2"></i>LANJUT BUAT ANGGARAN</button>
                <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold text-muted shadow-sm" onclick="closePortal()">Nanti Saja</button>
            `;
        } else if (mType === 'success_cancel') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-danger text-white";
            document.getElementById('modalTitle').innerText = "PENGAJUAN DIBATALKAN";
            document.getElementById('modalIcon').className = "fas fa-undo fa-4x mb-3 text-white";
            document.getElementById('labelMain').innerText = "Pengajuan yang Ditarik ke Draft";
        } else if (mType === 'success_dup') {
            document.getElementById('modalHeader').className = "modal-header p-4 border-0 text-center d-block bg-info text-white";
            document.getElementById('modalTitle').innerText = "DUPLIKASI BERHASIL";
            document.getElementById('modalIcon').className = "fas fa-clone fa-4x mb-3 text-white";
            
            document.getElementById('labelMain').innerText = "Anggaran Baru Tahun " + p.get('tahun');
            document.getElementById('m_total').innerHTML = "<span class='fs-6 fw-bold text-secondary'>Worksheet telah berhasil digandakan ke status Draft.</span>";
            document.getElementById('m_total').className = "mt-2";

            const newId = p.get('new_id');
            const footer = document.getElementById('modalFooterPortal');
            footer.innerHTML = `
                <button type="button" class="btn btn-info text-white w-100 rounded-pill py-3 fw-bold shadow-lg text-uppercase mb-2" onclick="window.location.href='index.php?page=anggaran_pendapatan&view=worksheet&header_id=${newId}&tab=input&tahun=${p.get('tahun')}'"><i class="fas fa-edit me-2"></i>LANJUT REVISI ANGGARAN</button>
                <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold text-muted shadow-sm" onclick="closePortal()">Nanti Saja</button>
            `;
        }
        const portal = new bootstrap.Modal(document.getElementById('mdlWorkflowPortal'), { backdrop: 'static', keyboard: false }); portal.show();
    }
});

function updateGrandTotalPagu() {
    let grandTotal = 0; document.querySelectorAll('.target-val').forEach(inp => { grandTotal += prs(inp.value); });
    const gtDisplay = document.getElementById('grandTotalPaguDisplay');
    if (gtDisplay) { gtDisplay.innerText = 'IDR ' + new Intl.NumberFormat('id-ID').format(grandTotal); }
}

function fmtRp(el) { let v = el.value.replace(/\D/g, ""); el.value = new Intl.NumberFormat('id-ID').format(v); }
function prs(s) { return parseFloat(s.toString().replace(/\./g, '')) || 0; }

function checkBalance(el) {
    const tr = el.closest('tr'); const targetInp = tr.querySelector('.target-val'); if(!targetInp) return;
    
    const uKeyMatch = tr.className.match(/child-of-(CAT_\d+)/);
    if (uKeyMatch) updateCatTotal(uKeyMatch[1]);
    updateGrandTotalPagu();
}

function updateCatTotal(uKey) {
    let totalCat = 0; document.querySelectorAll(`.child-of-${uKey}`).forEach(tr => { const tVal = tr.querySelector('.target-val'); if(tVal) totalCat += prs(tVal.value); });
    const disp = document.querySelector(`#row_${uKey} .category-total-display`); if(disp) disp.innerText = 'IDR ' + new Intl.NumberFormat('id-ID').format(totalCat);
}

window.onload = function() { updateGrandTotalPagu(); };
</script>

<style>
    .kpi-card-solid { border-radius: 16px; padding: 24px; color: white; position: relative; overflow: hidden; box-shadow: 0 8px 15px rgba(0,0,0,0.08); display: flex; flex-direction: column; justify-content: center; min-height: 130px; transition: 0.3s; border: none; }
    .kpi-card-solid:hover { transform: translateY(-5px); box-shadow: 0 12px 25px rgba(0,0,0,0.12); }
    .kpi-card-solid .kpi-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; opacity: 0.9; margin-bottom: 8px; }
    .kpi-card-solid .kpi-value { font-size: 26px; font-weight: 900; line-height: 1.1; z-index: 2; margin-bottom: 0; }
    .kpi-card-solid .kpi-icon { position: absolute; right: -15px; bottom: -20px; font-size: 90px; opacity: 0.15; z-index: 1; transform: rotate(-10deg); }
    
    .bg-solid-blue { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
    .bg-solid-yellow { background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%); }
    .bg-solid-green { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
    .bg-solid-dark { background: linear-gradient(135deg, #334155 0%, #0f172a 100%); }
    
    .modal.fade .modal-dialog { transform: scale(0.6); opacity: 0; transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .modal.show .modal-dialog { transform: scale(1); opacity: 1; }
    .supreme-container { width: 100%; overflow: visible; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; position: relative; }
    .table-responsive-supreme { overflow-x: auto; overflow-y: hidden; position: relative; border-radius: 16px; scroll-behavior: smooth; transform: translateZ(0); }
    .table-supreme { border-collapse: separate; border-spacing: 0; table-layout: auto !important; width: 100% !important; }
    .table-supreme td, .table-supreme th { padding: 12px 10px; border-right: 1px solid #f1f5f9; vertical-align: middle; }
    .table-supreme thead th { background: #f8fafc !important; border-bottom: 2px solid #cbd5e1; font-size: 10px; font-weight: 800; text-transform: uppercase; color: #475569; text-align: center !important; }
    
    .ws-f-action { width: 65px; border-right: 1px solid #cbd5e1; position: sticky; left: 0; z-index: 110 !important; background: #ffffff !important;}
    .ws-f-uraian { border-right: 2px solid #cbd5e1 !important; width: 450px; position: sticky; left: 65px; z-index: 110 !important; background: #ffffff !important;}
    .ws-f-coa    { width: 350px; text-align: center; position: sticky; left: 515px; z-index: 110 !important; background: #ffffff !important; border-right: 1px solid #cbd5e1 !important; }
    .ws-f-pagu   { width: 250px; text-align: right; position: sticky; left: 865px; z-index: 110 !important; background: #f8fafc !important; border-right: 4px double #cbd5e1 !important; }
    
    .mon-f-uraian { width: 350px; position: sticky; left: 0; z-index: 110 !important; background: #ffffff !important; border-right: 1px solid #cbd5e1;}
    .mon-f-pagu   { width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1;}
    .mon-f-real   { width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1;}
    .mon-f-sisa   { width: 180px; text-align: right !important; border-right: 1px solid #cbd5e1;}
    .mon-f-persen { width: 150px; text-align: center !important; }

    .inp-uraian { width: 100%; border: none !important; background: transparent !important; padding: 8px 5px; font-size: 13.5px; transition: 0.3s; color: #1e293b; box-shadow: none !important; outline: none !important; }
    .inp-uraian:focus { border-bottom: 2px solid #0d6efd !important; background: rgba(13, 110, 253, 0.03) !important; }
    .coa-search-input, .inp-amt { border: 1.5px solid #e2e8f0 !important; border-radius: 10px !important; padding: 10px 15px !important; background: #fcfdfe !important; transition: 0.3s all ease; font-size: 13px; font-weight: 600; color: #1e293b; }
    .coa-search-input:focus, .inp-amt:focus { border-color: #0d6efd !important; background: #ffffff !important; outline: none !important; box-shadow: 0 0 0 4px rgba(13,110,253,0.12) !important; }
    .row-approved { background-color: rgba(25, 135, 84, 0.08) !important; transition: background-color 0.6s ease; }
    .status-msg .badge { font-size: 10px !important; padding: 6px 10px !important; border-radius: 6px !important; font-weight: 800 !important; }
    .coa-results-list { position: fixed !important; transform: translateZ(0); background: #ffffff !important; border: 1.5px solid #0d6efd !important; border-radius: 12px !important; z-index: 999999 !important; max-height: 280px; overflow-y: auto; display: none; box-shadow: 0 15px 45px rgba(0,0,0,0.25) !important; padding: 5px 0; }
    .coa-item { padding: 12px 18px; cursor: pointer; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; font-size: 12.5px; color: #334155; }
    .coa-item:hover { background: #f0f7ff; color: #0d6efd; font-weight: 700; }
    .coa-item code { color: #10b981; font-weight: 900; background: #ecfdf5; padding: 2px 8px; border-radius: 6px; margin-right: 15px; font-family: 'JetBrains Mono'; }
    .tr-cat td { background-color: #f0f9ff !important; font-weight: 800; color: #0369a1; border-top: 1px solid #bae6fd !important; }
    .tr-subtotal td { background-color: #f8fafc !important; font-weight: 800; color: #1e293b; border-top: 2px solid #cbd5e1; }
    .row-grand-total td { background: #1e293b !important; color: #fff !important; font-weight: 900; }
    .unmapped-row td { background: #fffbeb !important; color: #92400e !important; font-style: italic; }
    .progress-thin { height: 8px; border-radius: 10px; background-color: #e2e8f0; overflow: hidden; margin-top: 6px; }
    .progress-fill { height: 100%; border-radius: 10px; transition: width 1s ease-in-out; }
    .alert-list-item { padding: 12px 15px; border-bottom: 1px dashed #e2e8f0; transition: 0.2s; }
    .alert-list-item:hover { background-color: #fff1f2; }
    .alert-list-item:last-child { border-bottom: none; }
</style>