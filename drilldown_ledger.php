<?php
/**
 * drilldown_ledger.php - OMNI FORENSIC DRILL-DOWN ENGINE
 * Versi: 5.0 (Sovereign Grand Master - Dynamic Inheritance Sync)
 * STATUS: 100% FULL CODE - NO TRUNCATION
 * Perbaikan: Mengintegrasikan Smart Inheritance Resolver agar X-Ray 
 * hanya menampilkan transaksi yang 100% sesuai dengan mapping Laporan Aktivitas.
 */
require_once 'config/koneksi.php';

$kode_kategori = $conn->real_escape_string($_GET['kode'] ?? '');
$grup_aktivitas = $conn->real_escape_string($_GET['grup_aktivitas'] ?? '');
$res_filter = $conn->real_escape_string($_GET['res'] ?? '');
$start = $conn->real_escape_string($_GET['s'] ?? date('Y-01-01'));
$end = $conn->real_escape_string($_GET['e'] ?? date('Y-m-d'));

$start_datetime = "$start 00:00:00";
$end_datetime = "$end 23:59:59";

$where_in_str = "1=0";
$nama_induk = "";
$info_lintas_laporan = "";

// 🚀 1. OMNI-MAPPING RESOLVER: JIKA BERASAL DARI LAPORAN AKTIVITAS (DYNAMIC MAPPING)
if (!empty($grup_aktivitas)) {
    $accs = $conn->query("SELECT kode_akun, nama_akun, parent_kode, is_aktivitas_group, grup_aktivitas, is_restricted FROM syifa_akun WHERE is_active=1")->fetch_all(MYSQLI_ASSOC);
    $map = []; foreach($accs as $a) { $map[$a['kode_akun']] = $a; }
    
    $target_kodes = [];
    foreach($accs as $a) {
        if ($res_filter !== '' && $a['is_restricted'] != $res_filter) continue;
        
        // Pelacak Warisan (Inheritance Resolver)
        $curr = $a['kode_akun']; $eff = 'TIDAK_MASUK'; $visited=[];
        while($curr != null && !in_array($curr, $visited)) {
            $visited[] = $curr;
            $node = $map[$curr] ?? null;
            if(!$node) break;
            if((int)$node['is_aktivitas_group']===1) { $eff = $node['kode_akun']; break; }
            if(!empty($node['grup_aktivitas']) && $node['grup_aktivitas']!=='TIDAK_MASUK') { $eff = $node['grup_aktivitas']; break; }
            $curr = $node['parent_kode'];
        }
        
        // Jika hasil pelacakan cocok dengan grup yang di-klik
        if ($eff === $grup_aktivitas) {
            $target_kodes[] = "'" . $a['kode_akun'] . "'";
        }
    }
    
    if(!empty($target_kodes)) {
        $where_in_str = "d.kode_akun IN (" . implode(',', $target_kodes) . ")";
    }
    $nama_induk = isset($map[$grup_aktivitas]) ? $map[$grup_aktivitas]['nama_akun'] : "Grup: " . $grup_aktivitas;
    $info_lintas_laporan = "<b>Informasi Mapping Aktif:</b> Transaksi di bawah ini merupakan gabungan dari seluruh anak akun yang ditautkan ke grup <b>[$grup_aktivitas] $nama_induk</b>.";
} 
// 🚀 2. JIKA BERASAL DARI LAPORAN LAIN (NERACA / ARUS KAS)
else {
    $keyword_lower = strtolower(trim($kode_kategori));
    if (preg_match('/^[0-9]+(-|\.)[0-9]+/', $kode_kategori) || is_numeric($kode_kategori)) {
        $where_akun = "(a.kode_akun = '$kode_kategori' OR a.parent_kode = '$kode_kategori')";
    } else {
        if (strpos($keyword_lower, 'kas') !== false && strpos($keyword_lower, 'arus') === false) {
            $where_akun = "(a.kategori IN ('Kas', 'Bank') OR a.is_cash_account = 1 OR a.kode_akun LIKE '1-11%')";
        } else if (strpos($keyword_lower, 'piutang') !== false) {
            $where_akun = "(a.kategori = 'Aset' AND (a.nama_akun LIKE '%Piutang%' OR a.kode_akun LIKE '1-12%'))";
        } else if (strpos($keyword_lower, 'persediaan') !== false) {
            $where_akun = "(a.kategori = 'Aset' AND (a.nama_akun LIKE '%Persediaan%' OR a.kode_akun LIKE '1-13%'))";
        } else if (strpos($keyword_lower, 'dimuka') !== false || strpos($keyword_lower, 'aset lancar lainnya') !== false) {
            $where_akun = "(a.kategori = 'Aset' AND (a.kode_akun LIKE '1-14%' OR a.kode_akun LIKE '1-15%' OR a.nama_akun LIKE '%Dimuka%'))";
        } else if (strpos($keyword_lower, 'harga perolehan') !== false || strpos($keyword_lower, 'aset tetap berwujud') !== false) {
            $where_akun = "(a.kategori = 'Aset' AND a.kode_akun LIKE '1-2%' AND a.nama_akun NOT LIKE '%Akumulasi%' AND a.nama_akun NOT LIKE '%Penyusutan%' AND a.nama_akun NOT LIKE '%Amortisasi%')";
        } else if (strpos($keyword_lower, 'penyusutan') !== false) {
            $where_akun = "(a.kategori = 'Aset' AND (a.nama_akun LIKE '%Akumulasi Penyusutan%' OR a.nama_akun LIKE '%Penyusutan%'))";
        } else if (strpos($keyword_lower, 'tidak berwujud') !== false) {
            $where_akun = "(a.kategori = 'Aset' AND a.kode_akun LIKE '1-3%' AND a.nama_akun NOT LIKE '%Amortisasi%')";
        } else if (strpos($keyword_lower, 'amortisasi') !== false) {
            $where_akun = "(a.kategori = 'Aset' AND a.nama_akun LIKE '%Amortisasi%')";
        } else if (strpos($keyword_lower, 'liabilitas pendek') !== false) {
            $where_akun = "a.kode_akun LIKE '2-1%'";
        } else if (strpos($keyword_lower, 'liabilitas panjang') !== false) {
            $where_akun = "a.kode_akun LIKE '2-2%'";
        } else if (strpos($keyword_lower, 'liabilitas lain') !== false) {
            $where_akun = "a.kode_akun LIKE '2-3%'";
        } else if (strpos($keyword_lower, 'modal pokok') !== false) {
            $where_akun = "(a.kategori IN ('Aset Neto', 'Ekuitas') AND a.is_restricted = 0)";
        } else if (strpos($keyword_lower, 'surplus ditahan') !== false || strpos($keyword_lower, 'saldo awal') !== false) {
            $where_akun = "(a.kategori IN ('Pendapatan', 'Beban'))";
            $start_datetime = "1970-01-01 00:00:00"; 
            $info_lintas_laporan = "<b>Informasi Lintas Laporan:</b> Nilai ini adalah akumulasi <b>Laporan Aktivitas masa lalu</b> yang digulung ke Ekuitas.";
        } else if (strpos($keyword_lower, 'surplus berjalan') !== false) {
            $where_akun = "(a.kategori IN ('Pendapatan', 'Beban'))";
            $info_lintas_laporan = "<b>Informasi Lintas Laporan:</b> Nilai ini adalah hasil kalkulasi dari <b>Laporan Aktivitas Tahun Berjalan</b>.";
        } else if (strpos($keyword_lower, 'tanpa pembatasan') !== false) {
            $where_akun = "(a.kategori IN ('Aset Neto', 'Ekuitas') AND a.is_restricted = 0)";
        } else if (strpos($keyword_lower, 'dengan pembatasan') !== false) {
            $where_akun = "(a.kategori IN ('Aset Neto', 'Ekuitas') AND a.is_restricted = 1)";
        } else {
            $where_akun = "(a.nama_akun LIKE '%" . $conn->real_escape_string($kode_kategori) . "%')";
        }
    }
    
    $target_kodes = [];
    $res_akun = $conn->query("SELECT kode_akun FROM syifa_akun a WHERE $where_akun AND is_group=0 AND is_active=1");
    if($res_akun) { while($r = $res_akun->fetch_assoc()) $target_kodes[] = "'" . $r['kode_akun'] . "'"; }
    if(!empty($target_kodes)) $where_in_str = "d.kode_akun IN (" . implode(',', $target_kodes) . ")";
    
    $nama_induk = $kode_kategori;
    if (preg_match('/^[0-9]+(-|\.)[0-9]+/', $kode_kategori)) {
        $q_n = $conn->query("SELECT nama_akun FROM syifa_akun WHERE kode_akun = '$kode_kategori'");
        if ($q_n && $q_n->num_rows > 0) { $nama_induk = $q_n->fetch_assoc()['nama_akun']; }
    } else {
        $nama_induk = strtoupper(str_replace('_', ' ', $kode_kategori));
    }
}

// 🚀 3. MENGKALKULASI SALDO MIGRASI (OPENING BALANCE) + KUMULATIF HISTORIS
$saldo_awal_total = 0;
$tipe_akun_global = 'DEBIT'; // Default

$sql_akun_meta = "SELECT kode_akun, saldo_normal, normal_balance, opening_balance, kategori FROM syifa_akun WHERE is_group=0 AND kode_akun IN (SELECT d.kode_akun FROM syifa_jurnal_detail d WHERE $where_in_str)";
// Gunakan bypass sederhana karena limitasi subquery mysql
$sql_akun_meta = "SELECT kode_akun, saldo_normal, normal_balance, opening_balance, kategori FROM syifa_akun a WHERE a.kode_akun IN (" . (empty($target_kodes) ? "''" : implode(',', $target_kodes)) . ")";

$res_meta = $conn->query($sql_akun_meta);
if ($res_meta) {
    while ($a = $res_meta->fetch_assoc()) {
        $sn = strtoupper($a['saldo_normal'] ?? '');
        $nb = strtoupper($a['normal_balance'] ?? '');
        $is_kredit = ($sn == 'K' || $nb == 'KREDIT' || in_array($a['kategori'], ['Pendapatan', 'Liabilitas', 'Kewajiban', 'Aset Neto', 'Ekuitas']));
        if ($is_kredit) $tipe_akun_global = 'KREDIT';

        $ob = (double)$a['opening_balance']; 
        $q_hist = $conn->query("SELECT SUM(jd.debit) as d, SUM(jd.kredit) as k FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun = '{$a['kode_akun']}' AND j.tgl_jurnal < '$start_datetime' AND j.is_deleted = 0");
        $hist = $q_hist->fetch_assoc();
        
        $hist_d = (double)($hist['d'] ?? 0);
        $hist_k = (double)($hist['k'] ?? 0);
        
        if ($is_kredit) { $saldo_awal_total += ($ob + $hist_k - $hist_d); } 
        else { $saldo_awal_total += ($ob + $hist_d - $hist_k); }
    }
}

// 🚀 4. MENARIK MUTASI TAHUN BERJALAN
$sql = "SELECT j.tgl_jurnal, j.no_jurnal, j.keterangan, j.pihak_nama, d.kode_akun, a.nama_akun, d.debit, d.kredit 
        FROM syifa_jurnal_detail d
        JOIN syifa_jurnal j ON d.jurnal_id = j.id
        JOIN syifa_akun a ON d.kode_akun = a.kode_akun
        WHERE $where_in_str 
        AND j.tgl_jurnal BETWEEN '$start_datetime' AND '$end_datetime' 
        AND j.is_deleted = 0
        ORDER BY j.tgl_jurnal ASC, j.id ASC";
$res = $conn->query($sql);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>X-Ray Drilldown - <?= htmlspecialchars($nama_induk) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .table th { background-color: #1e293b !important; color: #fff !important; font-size: 11px; text-transform: uppercase; padding: 12px; }
        .table td { font-size: 13px; vertical-align: middle; }
    </style>
</head>
<body class="p-4">
    <div class="container-fluid">
        <div class="card p-4 border-top border-primary border-5">
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                <div>
                    <h4 class="fw-bold text-dark mb-1"><i class="fas fa-search-dollar me-2 text-warning"></i>Audit Forensik: <?= htmlspecialchars($nama_induk) ?></h4>
                    <span class="badge bg-primary px-3 rounded-pill">PARAMETER: <?= htmlspecialchars($grup_aktivitas ?: $kode_kategori) ?></span>
                    <span class="badge bg-light text-dark border px-3 rounded-pill">PERIODE: <?= date('d M Y', strtotime($start_datetime)) ?> s/d <?= date('d M Y', strtotime($end_datetime)) ?></span>
                </div>
                <button class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm" onclick="window.close()"><i class="fas fa-times me-2"></i>TUTUP</button>
            </div>
            
            <?php if($info_lintas_laporan != ""): ?>
            <div class="alert alert-warning border-warning shadow-sm rounded-3 fw-bold d-flex align-items-center">
                <i class="fas fa-info-circle fa-2x me-3 text-warning"></i>
                <div style="font-size: 13px;"><?= $info_lintas_laporan ?></div>
            </div>
            <?php endif; ?>
            
            <div class="table-responsive rounded-3 border">
                <table class="table table-hover mb-0 text-dark">
                    <thead>
                        <tr>
                            <th width="90" class="text-center">Tanggal</th>
                            <th width="120">No. Referensi</th>
                            <th width="160">Akun Detail (COA)</th>
                            <th class="text-start">Uraian Transaksi</th>
                            <th class="text-end" width="120">Debit (Rp)</th>
                            <th class="text-end" width="120">Kredit (Rp)</th>
                            <th class="text-end" width="140">Saldo (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- BARIS SALDO MIGRASI / AWAL -->
                        <tr class="bg-light fw-bold text-primary border-bottom border-primary">
                            <td colspan="4" class="text-start ps-3 text-uppercase">SALDO AWAL (Migrasi & Kumulatif s.d <?= date('d M Y', strtotime('-1 day', strtotime($start_datetime))) ?>)</td>
                            <td colspan="2"></td>
                            <td class="text-end pe-3 fs-6"><?= number_format($saldo_awal_total, 0, ',', '.') ?></td>
                        </tr>

                        <?php 
                        $t_d = 0; $t_k = 0;
                        $running_balance = $saldo_awal_total;

                        if($res && $res->num_rows > 0): 
                            while($row = $res->fetch_assoc()): 
                                $t_d += $row['debit']; 
                                $t_k += $row['kredit'];

                                if ($tipe_akun_global == 'KREDIT') {
                                    $running_balance += ($row['kredit'] - $row['debit']);
                                } else {
                                    $running_balance += ($row['debit'] - $row['kredit']);
                                }
                        ?>
                        <tr>
                            <td class="text-center text-muted small"><?= date('d/m/y', strtotime($row['tgl_jurnal'])) ?></td>
                            <td><code class="text-dark bg-light px-2 py-1 rounded border"><?= $row['no_jurnal'] ?></code></td>
                            <td><div class="fw-bold text-primary" style="font-size:10.5px;"><?= $row['nama_akun'] ?></div><code style="font-size:9.5px;"><?= $row['kode_akun'] ?></code></td>
                            <td>
                                <div class="fw-bold text-dark" style="font-size:11.5px;"><?= $row['pihak_nama'] ?: 'Umum' ?></div>
                                <div class="text-muted" style="font-size: 11px;"><?= $row['keterangan'] ?></div>
                            </td>
                            <td class="text-end text-success fw-bold"><?= $row['debit']>0 ? number_format($row['debit'],0,',','.') : '-' ?></td>
                            <td class="text-end text-danger fw-bold"><?= $row['kredit']>0 ? number_format($row['kredit'],0,',','.') : '-' ?></td>
                            <td class="text-end text-primary fw-bold pe-3"><?= number_format($running_balance, 0, ',', '.') ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted fst-italic">Tidak ada transaksi mutasi pada periode ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold border-top">
                        <tr>
                            <td colspan="4" class="text-end py-3 text-uppercase">Total Mutasi Transaksi Terhimpun</td>
                            <td class="text-end text-success fs-6"><?= number_format($t_d, 0, ',', '.') ?></td>
                            <td class="text-end text-danger fs-6"><?= number_format($t_k, 0, ',', '.') ?></td>
                            <td></td>
                        </tr>
                        <tr class="bg-dark text-white">
                            <td colspan="6" class="text-end py-3 text-uppercase border-top border-secondary">SALDO AKHIR (SINKRON LAPORAN)</td>
                            <td class="text-end fs-5 text-warning pe-3 border-top border-secondary">Rp <?= number_format($running_balance, 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <div class="mt-3 text-center small text-muted fw-bold">
                <i class="fas fa-bolt text-warning me-1"></i> Sovereign Parent Roll-Up Tracker Active
            </div>
        </div>
    </div>
</body>
</html>