<?php
/**
 * ajax_drilldown.php - TRUE LEDGER FORENSIC ENGINE
 * Versi: 3.1 (Sovereign Grand Master - True Saldo Awal Fix)
 * Deskripsi: Memperbaiki kesalahan syntax query (d.kode_akun menjadi a.kode_akun)
 * agar Saldo Awal (Migrasi) berhasil ditangkap 100% dan tidak bernilai nol.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['status' => 'error', 'msg' => 'Akses Ditolak: Sesi Anda telah berakhir.']); exit; 
}

$keyword = urldecode($_GET['keyword'] ?? '');
$keyword_lower = strtolower(trim($keyword));
$tahun = (int)($_GET['tahun'] ?? date('Y'));

if (!$keyword) { 
    echo json_encode(['status' => 'error', 'msg' => 'Parameter pencarian tidak valid.']); exit; 
}

try {
    // 🚀 1. OMNI-MAPPING: Menerjemahkan Nama Uraian Laporan Kembali ke Root KODE COA
    $where_akun = "1=0";
    $tipe_akun = 'DEBIT'; // Default

    // 🛡️ FIX MUTLAK: Menggunakan a.kode_akun agar query syifa_akun tidak crash!
    if (preg_match('/^[0-9]+(-|\.)[0-9]+/', $keyword) || is_numeric($keyword)) {
        $where_akun = "(a.kode_akun = '$keyword' OR a.parent_kode = '$keyword')";
    } else if (strpos($keyword_lower, 'kas') !== false && strpos($keyword_lower, 'arus') === false) {
        $where_akun = "(a.kategori IN ('Kas', 'Bank') OR a.is_cash_account = 1 OR a.kode_akun LIKE '1-11%')";
        $tipe_akun = 'DEBIT';
    } else if (strpos($keyword_lower, 'piutang') !== false) {
        $where_akun = "(a.kategori = 'Aset' AND (a.nama_akun LIKE '%Piutang%' OR a.kode_akun LIKE '1-12%'))";
        $tipe_akun = 'DEBIT';
    } else if (strpos($keyword_lower, 'persediaan') !== false) {
        $where_akun = "(a.kategori = 'Aset' AND (a.nama_akun LIKE '%Persediaan%' OR a.kode_akun LIKE '1-13%'))";
        $tipe_akun = 'DEBIT';
    } else if (strpos($keyword_lower, 'dimuka') !== false || strpos($keyword_lower, 'aset lancar lainnya') !== false) {
        $where_akun = "(a.kategori = 'Aset' AND (a.kode_akun LIKE '1-14%' OR a.kode_akun LIKE '1-15%' OR a.nama_akun LIKE '%Dimuka%'))";
        $tipe_akun = 'DEBIT';
    } else if (strpos($keyword_lower, 'harga perolehan') !== false || strpos($keyword_lower, 'aset tetap berwujud') !== false || strpos($keyword_lower, 'aset tidak berwujud') !== false) {
        $where_akun = "(a.kategori = 'Aset' AND a.kode_akun LIKE '1-2%' AND a.nama_akun NOT LIKE '%Akumulasi%' AND a.nama_akun NOT LIKE '%Penyusutan%' AND a.nama_akun NOT LIKE '%Amortisasi%')";
        $tipe_akun = 'DEBIT';
    } else if (strpos($keyword_lower, 'akumulasi') !== false || strpos($keyword_lower, 'penyusutan') !== false || strpos($keyword_lower, 'amortisasi') !== false) {
        $where_akun = "(a.kategori = 'Aset' AND (a.nama_akun LIKE '%Akumulasi%' OR a.nama_akun LIKE '%Penyusutan%' OR a.nama_akun LIKE '%Amortisasi%'))";
        $tipe_akun = 'KREDIT'; 
    } else if (strpos($keyword_lower, 'liabilitas') !== false || strpos($keyword_lower, 'utang') !== false || strpos($keyword_lower, 'kewajiban') !== false) {
        $where_akun = "(a.kategori IN ('Liabilitas', 'Kewajiban') OR a.kode_akun LIKE '2-%')";
        $tipe_akun = 'KREDIT';
    } else if (strpos($keyword_lower, 'aset neto') !== false || strpos($keyword_lower, 'ekuitas') !== false || strpos($keyword_lower, 'dana terikat') !== false) {
        $where_akun = "(a.kategori IN ('Aset Neto', 'Ekuitas') OR a.kode_akun LIKE '3-%')";
        $tipe_akun = 'KREDIT';
    } else if (strpos($keyword_lower, 'pendapatan') !== false) {
        $where_akun = "(a.kategori = 'Pendapatan' OR a.kode_akun LIKE '4-%')";
        $tipe_akun = 'KREDIT';
    } else if (strpos($keyword_lower, 'beban') !== false || strpos($keyword_lower, 'biaya') !== false || strpos($keyword_lower, 'anggaran') !== false) {
        $where_akun = "(a.kategori = 'Beban' OR a.kode_akun LIKE '5-%')";
        $tipe_akun = 'DEBIT';
    } else {
        $clean_name = preg_replace('/^[0-9\-\.]+\s*/', '', $keyword);
        $where_akun = "(a.nama_akun LIKE '%" . $conn->real_escape_string($clean_name) . "%')";
        $tipe_akun = 'DEBIT'; 
    }

    // 🚀 2. MENGHITUNG SALDO AWAL (Akumulasi dari awal mula hingga 31 Desember tahun lalu)
    $start_date = "$tahun-01-01 00:00:00";
    $end_date = "$tahun-12-31 23:59:59";

    $sql_akun = "SELECT kode_akun, nama_akun, saldo_normal, normal_balance, opening_balance, kategori FROM syifa_akun a WHERE $where_akun AND is_group=0 AND is_active=1";
    $res_akun = $conn->query($sql_akun);
    
    $saldo_awal_total = 0;
    $kode_in_array = [];

    if ($res_akun) {
        while ($a = $res_akun->fetch_assoc()) {
            $kode_in_array[] = "'" . $a['kode_akun'] . "'";
            
            $sn = strtoupper($a['saldo_normal'] ?? '');
            $nb = strtoupper($a['normal_balance'] ?? '');
            $is_kredit_akun = ($sn == 'K' || $nb == 'KREDIT' || in_array($a['kategori'], ['Pendapatan', 'Liabilitas', 'Kewajiban', 'Aset Neto', 'Ekuitas']));
            
            $ob = (double)$a['opening_balance'];
            
            // Tarik Mutasi SEBELUM tahun berjalan (Historical)
            $q_hist = $conn->query("SELECT SUM(jd.debit) as d, SUM(jd.kredit) as k FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '{$a['kode_akun']}' AND j.tgl_jurnal < '$start_date' AND j.is_deleted = 0");
            $hist = $q_hist->fetch_assoc();
            
            $hist_d = (double)($hist['d'] ?? 0);
            $hist_k = (double)($hist['k'] ?? 0);
            
            // Perhitungan Balance sesuai Sifat Akun
            if ($is_kredit_akun) {
                $saldo_awal_total += ($ob + $hist_k - $hist_d);
            } else {
                $saldo_awal_total += ($ob + $hist_d - $hist_k);
            }
        }
    }

    // 🚀 3. MENARIK MUTASI TAHUN BERJALAN (Tampil di Tabel)
    $data_mutasi = [];
    $mutasi_d = 0;
    $mutasi_k = 0;

    if (!empty($kode_in_array)) {
        $kode_in_str = implode(",", $kode_in_array);
        $sql_mutasi = "SELECT j.tgl_jurnal, j.no_jurnal, j.pihak_nama, j.keterangan, jd.debit, jd.kredit, a.nama_akun, a.kode_akun 
                       FROM syifa_jurnal_detail jd 
                       JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                       JOIN syifa_akun a ON jd.kode_akun = a.kode_akun
                       WHERE jd.kode_akun IN ($kode_in_str) AND j.tgl_jurnal BETWEEN '$start_date' AND '$end_date' AND j.is_deleted = 0 
                       ORDER BY j.tgl_jurnal ASC, j.id ASC LIMIT 1000";
                       
        $res_mutasi = $conn->query($sql_mutasi);
        if ($res_mutasi) {
            while ($r = $res_mutasi->fetch_assoc()) {
                $data_mutasi[] = $r;
                $mutasi_d += (double)$r['debit'];
                $mutasi_k += (double)$r['kredit'];
            }
        }
    }

    // 🚀 4. MENGHITUNG SALDO AKHIR (SINKRONISASI MUTLAK KE LAPORAN)
    if ($tipe_akun == 'KREDIT') {
        $saldo_akhir = $saldo_awal_total + $mutasi_k - $mutasi_d;
    } else {
        $saldo_akhir = $saldo_awal_total + $mutasi_d - $mutasi_k;
    }

    echo json_encode([
        'status' => 'success',
        'tipe_akun' => $tipe_akun,
        'saldo_awal' => $saldo_awal_total,
        'mutasi_d' => $mutasi_d,
        'mutasi_k' => $mutasi_k,
        'saldo_akhir' => $saldo_akhir,
        'data' => $data_mutasi
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>