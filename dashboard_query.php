<?php
/**
 * dashboard_query.php - DATA AGGREGATION ENGINE (EXECUTIVE LEVEL)
 * Versi: 3.3 (Prodi Filter & Advanced Student Payment Status Analytics)
 * Rule: READ ONLY (SUM, COUNT, GROUP BY).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

// =========================================================================
// 1. PARAMETER PERIODE DINAMIS (GLOBAL FILTERS)
// =========================================================================
$filter_tahun = $_GET['tahun'] ?? date('Y');
$filter_bulan = $_GET['bulan'] ?? '';
$filter_tgl_awal = $_GET['tanggal_awal'] ?? '';
$filter_tgl_akhir = $_GET['tanggal_akhir'] ?? '';
$filter_prodi = $_GET['prodi'] ?? '';

// Kalkulasi Bulan Berjalan
$bulan_berjalan = ($filter_tahun == date('Y')) ? (int)date('n') : 12;
if (!empty($filter_bulan)) $bulan_berjalan = (int)$filter_bulan;
if ($bulan_berjalan == 0) $bulan_berjalan = 1;

// Build SQL Constraints
$sql_date_jurnal = " YEAR(j.tgl_jurnal) = '$filter_tahun' ";
$sql_date_tagihan = " YEAR(t.created_at) = '$filter_tahun' ";

if (!empty($filter_bulan)) {
    $sql_date_jurnal .= " AND MONTH(j.tgl_jurnal) = '$filter_bulan' ";
    $sql_date_tagihan .= " AND MONTH(t.created_at) = '$filter_bulan' ";
}
if (!empty($filter_tgl_awal) && !empty($filter_tgl_akhir)) {
    $sql_date_jurnal = " DATE(j.tgl_jurnal) BETWEEN '$filter_tgl_awal' AND '$filter_tgl_akhir' ";
    $sql_date_tagihan = " DATE(t.created_at) BETWEEN '$filter_tgl_awal' AND '$filter_tgl_akhir' ";
}

// =========================================================================
// 2. RESOLUSI HAK AKSES & FILTER UNIT/PRODI
// =========================================================================
$uid = (int)($_SESSION['user_id'] ?? 0);
$sql_role = "SELECT r.is_ka_unit, r.unit_id, r.role_name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = '$uid'";
$u_role = $conn->query($sql_role)->fetch_assoc();

$is_global = ($_SESSION['role_id'] == 1 || in_array(strtoupper($u_role['role_name'] ?? ''), ['PIMPINAN', 'REKTOR', 'SUPERADMIN', 'YAYASAN', 'ADMIN']));
$unit_id_user = (int)($u_role['unit_id'] ?? 0);

$filter_unit_pengajuan = $is_global ? "" : " AND unit_id = $unit_id_user ";
$filter_prodi_sql = !empty($filter_prodi) ? " AND m.prodi_id = '$filter_prodi' " : "";

// Data Container
$data = [
    'pagu_belanja' => 0, 'realisasi_belanja' => 0,
    'pagu_pendapatan' => 0, 'realisasi_pendapatan' => 0,
    'saldo_kas' => 0, 'piutang_total' => 0, 'piutang_dibayar' => 0,
    'aset_total' => 0, 'aset_aktif' => 0, 'aset_nonaktif' => 0,
    'mhs_total' => 0,
    'mhs_status' => ['lunas' => 0, 'mencicil' => 0, 'belum' => 0],
    'komposisi' => ['ops_pagu' => 0, 'dev_pagu' => 0, 'ops_real' => 0, 'dev_real' => 0],
    'top_expense' => [], 'piutang_prodi' => [], 'trend' => []
];

// Ambil Master Prodi untuk Dropdown Filter
$master_prodi = [];
$res_mp = $conn->query("SELECT id, nama_prodi FROM mhs_prodi ORDER BY nama_prodi ASC");
if ($res_mp) {
    while($mp = $res_mp->fetch_assoc()) $master_prodi[] = $mp;
}

try {
    // A. AGREGASI ANGGARAN (Pagu Disetujui)
    $q_pagu = $conn->query("SELECT 
        SUM(CASE WHEN kategori='Pengeluaran' THEN nominal_pagu ELSE 0 END) as belanja,
        SUM(CASE WHEN kategori='Pendapatan' THEN nominal_pagu ELSE 0 END) as pendapatan
        FROM syifa_budgets WHERE tahun_anggaran='$filter_tahun' AND status='Disetujui'");
    if($r = $q_pagu->fetch_assoc()) {
        $data['pagu_belanja'] = (double)$r['belanja'];
        $data['pagu_pendapatan'] = (double)$r['pendapatan'];
    }

    // B. AGREGASI REALISASI (Jurnal Umum)
    $q_real = $conn->query("SELECT 
        SUM(CASE WHEN a.kategori='Beban' THEN jd.debit - jd.kredit ELSE 0 END) as real_belanja,
        SUM(CASE WHEN a.kategori='Pendapatan' THEN jd.kredit - jd.debit ELSE 0 END) as real_pendapatan
        FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
        WHERE $sql_date_jurnal");
    if($r = $q_real->fetch_assoc()) {
        $data['realisasi_belanja'] = (double)$r['real_belanja'];
        $data['realisasi_pendapatan'] = (double)$r['real_pendapatan'];
    }

    // C. SALDO KAS GLOBAL SAAT INI
    $q_kas = $conn->query("SELECT SUM(a.opening_balance + COALESCE(mut.net, 0)) as saldo_kas
        FROM syifa_akun a LEFT JOIN (SELECT kode_akun, SUM(debit-kredit) as net FROM syifa_jurnal_detail GROUP BY kode_akun) mut ON a.kode_akun = mut.kode_akun 
        WHERE a.kode_akun LIKE '1-11%' AND a.is_active=1");
    if($r = $q_kas->fetch_assoc()) $data['saldo_kas'] = (double)$r['saldo_kas'];

    // D. PIUTANG MAHASISWA & PRODI FILTER
    $q_piutang = $conn->query("SELECT SUM(t.nominal) as tot_tagihan, SUM(t.terbayar) as tot_bayar 
                               FROM keuangan_tagihan t LEFT JOIN syifa_mahasiswa m ON t.nim = m.nim 
                               WHERE $sql_date_tagihan $filter_prodi_sql");
    if($r = $q_piutang->fetch_assoc()) {
        $data['piutang_total'] = (double)$r['tot_tagihan'];
        $data['piutang_dibayar'] = (double)$r['tot_bayar'];
    }

    // E. FIX KOMPOSISI 70/30
    $q_komp_pagu = $conn->query("SELECT jenis_belanja, SUM(nominal_pagu) as pagu FROM syifa_budgets WHERE tahun_anggaran='$filter_tahun' AND status='Disetujui' AND kategori='Pengeluaran' GROUP BY jenis_belanja");
    if($q_komp_pagu) {
        while($r = $q_komp_pagu->fetch_assoc()) {
            if($r['jenis_belanja'] == 'Operasional') $data['komposisi']['ops_pagu'] = (double)$r['pagu'];
            if($r['jenis_belanja'] == 'Pengembangan') $data['komposisi']['dev_pagu'] = (double)$r['pagu'];
        }
    }
    
    $q_komp_real = $conn->query("SELECT b.jenis_belanja, SUM(jd.debit - jd.kredit) AS realisasi 
        FROM syifa_jurnal_detail jd 
        JOIN syifa_jurnal j ON j.id = jd.jurnal_id 
        JOIN syifa_budgets b ON b.kode_akun = jd.kode_akun 
        WHERE $sql_date_jurnal AND b.tahun_anggaran='$filter_tahun' AND b.status='Disetujui'
        GROUP BY b.jenis_belanja");
    if($q_komp_real) {
        while($r = $q_komp_real->fetch_assoc()) {
            if($r['jenis_belanja'] == 'Operasional') $data['komposisi']['ops_real'] = (double)$r['realisasi'];
            if($r['jenis_belanja'] == 'Pengembangan') $data['komposisi']['dev_real'] = (double)$r['realisasi'];
        }
    }

    // F. TOP 5 PENGELUARAN
    $q_top = $conn->query("SELECT a.nama_akun as nama, SUM(jd.debit - jd.kredit) as total
        FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
        WHERE $sql_date_jurnal AND a.kategori='Beban' GROUP BY a.kode_akun ORDER BY total DESC LIMIT 5");
    if($q_top) { while($r = $q_top->fetch_assoc()) { $data['top_expense'][] = $r; } }
    
    if(empty($data['top_expense'])) {
        $data['top_expense'] = [['nama' => 'Belum ada data biaya', 'total' => 0]];
    }

    // G. PIUTANG PER PRODI
    $q_prodi = $conn->query("SELECT p.nama_prodi, SUM(t.nominal) as target, SUM(t.terbayar) as realisasi 
        FROM keuangan_tagihan t JOIN syifa_mahasiswa m ON t.nim = m.nim JOIN mhs_prodi p ON m.prodi_id = p.id 
        WHERE $sql_date_tagihan $filter_prodi_sql GROUP BY p.id LIMIT 5");
    if($q_prodi) { while($r = $q_prodi->fetch_assoc()) { $data['piutang_prodi'][] = $r; } }

    // H. TREND BULANAN & FORECAST
    for($i=1; $i<=12; $i++) $data['trend'][$i] = ['pendapatan' => 0, 'belanja' => 0];
    $q_trend = $conn->query("SELECT MONTH(j.tgl_jurnal) as bln, 
        SUM(CASE WHEN a.kategori='Pendapatan' THEN jd.kredit - jd.debit ELSE 0 END) as pend,
        SUM(CASE WHEN a.kategori='Beban' THEN jd.debit - jd.kredit ELSE 0 END) as bel
        FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
        WHERE YEAR(j.tgl_jurnal)='$filter_tahun' GROUP BY bln");
    if($q_trend) {
        while($r = $q_trend->fetch_assoc()) {
            $data['trend'][(int)$r['bln']]['pendapatan'] = (double)$r['pend'];
            $data['trend'][(int)$r['bln']]['belanja'] = (double)$r['bel'];
        }
    }

    // I. STATUS ASET (ISAK 35)
    $check_aset_tb = $conn->query("SHOW TABLES LIKE 'assets'");
    if($check_aset_tb && $check_aset_tb->num_rows > 0) {
        $q_aset_stats = $conn->query("SELECT COUNT(id) as total_qty, SUM(current_book_value) as total_nbv FROM assets WHERE status='Aktif'");
        if ($r_aset = $q_aset_stats->fetch_assoc()) {
            $data['aset_total'] = (double)$r_aset['total_nbv'];
            $data['aset_aktif'] = (int)$r_aset['total_qty'];
        }
        $q_aset_non = $conn->query("SELECT COUNT(id) FROM assets WHERE status!='Aktif'");
        $data['aset_nonaktif'] = $q_aset_non ? (int)$q_aset_non->fetch_row()[0] : 0;
    }

    // ?? J. ANALISA STATUS PEMBAYARAN MAHASISWA & FILTER PRODI
    $check_mhs_tb = $conn->query("SHOW TABLES LIKE 'syifa_mahasiswa'");
    if($check_mhs_tb && $check_mhs_tb->num_rows > 0) {
        // Total Mahasiswa Aktif
        $q_mhs = $conn->query("SELECT COUNT(*) FROM syifa_mahasiswa m WHERE 1=1 $filter_prodi_sql");
        $data['mhs_total'] = $q_mhs ? (int)$q_mhs->fetch_row()[0] : 0;

        // Sub-query tingkat tinggi untuk menghitung status bayar tiap mahasiswa (Grouping by NIM)
        $sql_mhs_status = "
            SELECT 
                SUM(CASE WHEN sisa <= 0 THEN 1 ELSE 0 END) as lunas,
                SUM(CASE WHEN terbayar > 0 AND sisa > 0 THEN 1 ELSE 0 END) as mencicil,
                SUM(CASE WHEN terbayar = 0 AND sisa > 0 THEN 1 ELSE 0 END) as belum
            FROM (
                SELECT t.nim, SUM(t.nominal) as total_tagihan, SUM(t.terbayar) as terbayar, (SUM(t.nominal) - SUM(t.terbayar)) as sisa
                FROM keuangan_tagihan t JOIN syifa_mahasiswa m ON t.nim = m.nim
                WHERE $sql_date_tagihan $filter_prodi_sql
                GROUP BY t.nim
            ) as rekapan_mhs
        ";
        $res_mhs_status = $conn->query($sql_mhs_status);
        if ($res_mhs_status && $row_st = $res_mhs_status->fetch_assoc()) {
            $data['mhs_status']['lunas'] = (int)$row_st['lunas'];
            $data['mhs_status']['mencicil'] = (int)$row_st['mencicil'];
            $data['mhs_status']['belum'] = (int)$row_st['belum'];
        }
    }

    // K. ADVANCED FINANCIAL METRICS
    $data['sisa_anggaran'] = max(0, $data['pagu_belanja'] - $data['realisasi_belanja']);
    $data['sisa_pendapatan'] = max(0, $data['pagu_pendapatan'] - $data['realisasi_pendapatan']); 
    $data['absorption_rate'] = ($data['pagu_belanja'] > 0) ? ($data['realisasi_belanja'] / $data['pagu_belanja']) * 100 : 0;
    $data['burn_rate'] = $data['realisasi_belanja'] / $bulan_berjalan;
    $data['forecast_year_end'] = $data['burn_rate'] * 12;

} catch (Exception $e) {
    // Silent fail for executive dashboard
}
?>