<?php
/**
 * asset_management.php - DASHBOARD ASET & PUSAT KENDALI SIKLUS HIDUP (ERP)
 * Versi: 10.2 (Grand Master - Point-In-Time Time Machine Edition)
 * Perbaikan Mutlak: 
 * 1. Menambahkan filter "Bulan" pendamping filter Tahun di tabel Master.
 * 2. Menerapkan Point-In-Time Calculation (Time Machine): Saat bulan dan tahun 
 * dipilih, sistem akan memotong kalkulasi Akumulasi dan CAPEX persis di akhir 
 * bulan tersebut, sehingga Nilai Buku yang tampil adalah nilai audit pada masa itu!
 * 3. Filter Jenis Aset dan Modal X-Ray Detail dipertahankan mutlak.
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }
if (!function_exists('formatRp')) { function formatRp($n) { return "Rp " . number_format($n ?? 0, 0, ',', '.'); } }

$active_tab = $_GET['tab'] ?? 'dashboard';
$cur_month = (int)date('m'); $cur_year = (int)date('Y');

// 🚀 PARAMETER FILTER MASTER ASET
$f_year_master = (int)($_GET['f_year_master'] ?? $cur_year);
$f_month_master = $_GET['f_month_master'] ?? ''; 
$f_jenis_aset = $_GET['f_jenis_aset'] ?? ''; 

// =========================================================================
// 🛡️ THE AUTO-HEALER: SINKRONISASI KAS & PEMBERSIHAN BUG PENAMBAHAN
// =========================================================================
try {
    // 1. Sapu Bersih: Hapus penambahan palsu yang disebabkan oleh jurnal penyusutan
    $conn->query("DELETE FROM asset_improvements WHERE journal_id IN (SELECT id FROM syifa_jurnal WHERE no_jurnal LIKE 'DEP-%')");
    $conn->query("DELETE FROM asset_improvements WHERE keterangan LIKE '%Penyusutan%'");

    // 2. Sinkronisasi: Tangkap transaksi Kas Keluar yang membeli aset (Aset Bertambah)
    $untracked = $conn->query("
        SELECT jd.aset_id, jd.debit, j.keterangan, j.tgl_jurnal, j.id as jid, j.no_jurnal 
        FROM syifa_jurnal_detail jd 
        JOIN syifa_jurnal j ON jd.jurnal_id = j.id
        WHERE jd.aset_id IS NOT NULL AND jd.aset_id > 0 AND jd.debit > 0
        AND j.no_jurnal NOT LIKE 'DEP-%' 
        AND j.jenis_jurnal != 'migrasi_aset'
        AND NOT EXISTS (SELECT 1 FROM asset_improvements ai WHERE ai.journal_id = j.id)
    ");
    
    if ($untracked && $untracked->num_rows > 0) {
        while ($row = $untracked->fetch_assoc()) {
            $aid = (int)$row['aset_id']; $amt = (double)$row['debit']; 
            $tgl = $row['tgl_jurnal']; $ket = $conn->real_escape_string($row['keterangan'] ?: 'Pembelian via: ' . $row['no_jurnal']); 
            $jid = (int)$row['jid'];
            
            // Pastikan ini bukan jurnal perolehan awal (saldo awal)
            $cek_awal = $conn->query("SELECT id FROM assets WHERE id=$aid AND purchase_value=$amt AND purchase_date='$tgl'");
            if ($cek_awal && $cek_awal->num_rows == 0) {
                $conn->query("INSERT INTO asset_improvements (asset_id, tanggal, jenis_penambahan, nilai_penambahan, keterangan, journal_id) VALUES ($aid, '$tgl', 'Penambahan Kas', $amt, '$ket', $jid)");
            } else {
                $conn->query("INSERT INTO asset_improvements (asset_id, tanggal, jenis_penambahan, nilai_penambahan, keterangan, journal_id) VALUES ($aid, '$tgl', 'Perolehan Awal', 0, 'Jurnal Perolehan Awal', $jid)");
            }
        }
    }

    // 3. Rekalkulasi Mutlak: Perbaiki Current Book Value sesuai Rumus Sejati
    $conn->query("
        UPDATE assets a SET current_book_value = (
            a.purchase_value 
            + COALESCE((SELECT SUM(nilai_penambahan) FROM asset_improvements WHERE asset_id = a.id AND jenis_penambahan != 'Perolehan Awal'), 0)
            - CASE WHEN a.purchase_mode = 'saldo_awal' THEN a.residual_value ELSE 0 END
            - COALESCE((SELECT SUM(nilai_susut) FROM asset_depreciation WHERE asset_id = a.id), 0)
        ) WHERE a.status = 'Aktif'
    ");
} catch(Exception $e) {}
// =========================================================================

// 1. STATS DASHBOARD
$stats = $conn->query("SELECT COUNT(id) as total_qty, SUM(purchase_value) as total_perolehan, SUM(current_book_value) as total_nilai_buku FROM assets WHERE status='Aktif'")->fetch_assoc();

// 2. CEK ANTREAN
$check_depr = $conn->query("
    SELECT COUNT(id) as jml 
    FROM assets a
    WHERE a.status='Aktif' AND a.current_book_value > 0 
    AND NOT EXISTS (
        SELECT 1 FROM asset_depreciation ad WHERE ad.asset_id = a.id AND ad.periode_bulan = $cur_month AND ad.periode_tahun = $cur_year
    )
")->fetch_assoc();

$nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
$master_cats = $conn->query("SELECT * FROM asset_categories ORDER BY category_name ASC")->fetch_all(MYSQLI_ASSOC);
$master_types = $conn->query("SELECT * FROM asset_types ORDER BY type_name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<style>
    .card-asset-top { border: none; border-radius: 20px; padding: 25px; color: #fff; position: relative; overflow: hidden; transition: 0.3s; }
    .card-asset-top i { position: absolute; right: -10px; bottom: -10px; font-size: 5rem; opacity: 0.15; transform: rotate(-10deg); }
    .table-audit thead th { background: #1e293b !important; color: #f8fafc !important; font-size: 8.2px; text-transform: uppercase; letter-spacing: 0.8px; padding: 14px 8px; border: 1px solid #334155; text-align: center; }
    .table-audit tbody td { font-size: 11px; padding: 12px 8px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #334155; font-weight: 500; }
    .table-hover tbody tr:hover { background-color: #f8fafc; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white overflow-hidden">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-white">
            <div>
                <h4 class="fw-bold mb-0 text-dark">Manajemen Aset & Inventaris</h4>
                <small class="text-muted">Kontrol siklus hidup aset tetap Institusi.</small>
            </div>
        </div>
        <div class="p-3">
            <ul class="nav nav-pills no-print mb-0">
                <li class="nav-item"><a class="nav-link <?= $active_tab=='dashboard'?'active':'' ?>" href="?page=aset_manajemen&tab=dashboard">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_tab=='master'?'active':'' ?>" href="?page=aset_manajemen&tab=master">Master</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_tab=='improvement'?'active':'' ?>" href="?page=aset_manajemen&tab=improvement">Penambahan</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_tab=='engine'?'active':'' ?>" href="?page=aset_manajemen&tab=engine">Engine</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_tab=='audit'?'active':'' ?>" href="?page=aset_manajemen&tab=audit">Audit</a></li>
            </ul>
        </div>
    </div>

    <?php if(isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center text-dark">
                <i class="fas fa-info-circle me-2 fa-lg"></i>
                <div class="fw-bold"><?= $_SESSION['flash']['msg'] ?></div>
            </div>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
    <?php unset($_SESSION['flash']); endif; ?>

    <?php if($active_tab == 'dashboard'): ?>
        <div class="row g-4 mb-4 text-center">
            <div class="col-md-4"><div class="card-asset-top bg-primary shadow"><h6>TOTAL PEROLEHAN</h6><h2 class="fw-bold"><?= formatRp($stats['total_perolehan']) ?></h2><i class="fas fa-tags"></i></div></div>
            <div class="col-md-4"><div class="card-asset-top bg-success shadow"><h6>TOTAL NILAI BUKU</h6><h2 class="fw-bold"><?= formatRp($stats['total_nilai_buku']) ?></h2><i class="fas fa-book"></i></div></div>
            <div class="col-md-4"><div class="card-asset-top bg-dark shadow"><h6>ITEM AKTIF</h6><h2 class="fw-bold"><?= $stats['total_qty'] ?> Item</h2><i class="fas fa-cubes"></i></div></div>
        </div>
        <div class="row g-4 text-dark">
            <div class="col-md-8"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100"><h6 class="fw-bold border-bottom pb-2 mb-4">Kekayaan per Kategori (NBV)</h6><?php foreach($master_cats as $c){ $cv = $conn->query("SELECT SUM(current_book_value) as val FROM assets WHERE category_id={$c['id']} AND status='Aktif'")->fetch_assoc()['val'] ?? 0; $per = ($stats['total_nilai_buku'] > 0) ? ($cv / $stats['total_nilai_buku'] * 100) : 0; ?><div class="mb-3"><div class="d-flex justify-content-between small fw-bold mb-1"><span><?= $c['category_name'] ?></span><span class="text-primary"><?= formatRp($cv) ?></span></div><div class="progress rounded-pill" style="height: 10px; background: #f1f5f9;"><div class="progress-bar bg-primary" style="width: <?= $per ?>%"></div></div></div><?php } ?></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white text-center border-top border-warning border-4 h-100"><h6 class="fw-bold text-muted small mb-3">Status Penyusutan Bulanan</h6><div class="py-4 bg-light rounded-4 mb-3 border"><h3 class="fw-bold mb-0 text-dark"><?= $nama_bulan[$cur_month] ?></h3><span class="badge bg-dark px-3 mt-1"><?= $cur_year ?></span></div><?php if($check_depr['jml'] > 0){ ?><div class="alert alert-danger border-0 small py-3 fw-bold mb-3"><?= $check_depr['jml'] ?> Aset Belum Disusutkan</div><a href="?page=aset_manajemen&tab=engine" class="btn btn-primary w-100 rounded-pill fw-bold py-3 shadow">BUKA ENGINE PENYUSUTAN</a><?php } else { ?><div class="alert alert-success border-0 small py-3 fw-bold">Penyusutan Bulan Ini Selesai</div><?php } ?></div></div>
        </div>

    <?php elseif($active_tab == 'master'): ?>
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0 text-dark">Daftar Inventaris Institusi</h6>
            <div class="d-flex gap-2">
                <form method="GET" class="d-flex gap-2 align-items-center bg-white px-3 py-1 rounded-pill border no-print">
                    <input type="hidden" name="page" value="aset_manajemen"><input type="hidden" name="tab" value="master">
                    <label class="small fw-bold text-muted mb-0">Audit Thn:</label>
                    <select name="f_year_master" class="form-select form-select-sm border-0 bg-transparent fw-bold text-primary shadow-none" onchange="this.form.submit()"><?php for($y=$cur_year; $y>=$cur_year-10; $y--) echo "<option value='$y' ".($f_year_master==$y?'selected':'').">$y</option>"; ?></select>
                    
                    <!-- 🚀 INJEKSI MUTLAK: Filter Dropdown Bulan -->
                    <label class="small fw-bold text-muted mb-0 ms-2 border-start ps-2">Bln:</label>
                    <select name="f_month_master" class="form-select form-select-sm border-0 bg-transparent fw-bold text-primary shadow-none" onchange="this.form.submit()">
                        <option value="">Semua</option>
                        <?php for($m=1; $m<=12; $m++) echo "<option value='$m' ".($f_month_master==$m?'selected':'').">".substr($nama_bulan[$m],0,3)."</option>"; ?>
                    </select>

                    <!-- 🚀 INJEKSI MUTLAK: Filter Dropdown Jenis Aset -->
                    <label class="small fw-bold text-muted mb-0 ms-2 border-start ps-2">Jenis:</label>
                    <select name="f_jenis_aset" class="form-select form-select-sm border-0 bg-transparent fw-bold text-primary shadow-none" onchange="this.form.submit()">
                        <option value="">Semua Aset</option>
                        <option value="berwujud" <?= ($f_jenis_aset=='berwujud')?'selected':'' ?>>Berwujud</option>
                        <option value="tidak_berwujud" <?= ($f_jenis_aset=='tidak_berwujud')?'selected':'' ?>>Tidak Berwujud</option>
                    </select>
                </form>
                <button class="btn btn-outline-dark rounded-pill btn-sm px-3 fw-bold shadow-sm" onclick="showModalSetupType()">Setup Jenis</button>
                <button class="btn btn-primary rounded-pill btn-sm px-4 shadow fw-bold" onclick="showModalAsset()">+ Registrasi Baru</button>
            </div>
        </div>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-audit text-center">
                    <thead>
                        <tr>
                            <th class="ps-4 text-start">Kode Asset</th>
                            <th class="text-start">Nama Asset</th>
                            <th>Umur</th>
                            <th>% / Thn</th>
                            <th>Susut/Bln</th>
                            <th class="text-end">Akuisisi</th>
                            <th class="text-end text-primary">Penambahan</th>
                            <!-- 🚀 DYNAMIC HEADER POINT-IN-TIME -->
                            <th class="text-end text-warning">Akum s.d <?= $f_month_master ? substr($nama_bulan[(int)$f_month_master],0,3).' ' : '' ?><?= $f_year_master ?></th>
                            <th class="text-end fw-bold">Total Akumulasi</th>
                            <th class="text-end pe-4 text-success fw-bold">Nilai Buku</th>
                            <th width="100" class="no-print pe-4 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    // INIT VARIABEL PENJUMLAHAN (TOTAL KESELURUHAN)
                    $t_akuisisi = 0; $t_penambahan = 0; $t_akum_periodik = 0; $t_total_akum = 0; $t_nilai_buku = 0;

                    // QUERY FILTER JENIS ASET
                    $filter_jenis_sql = "";
                    if ($f_jenis_aset == 'berwujud') {
                        $filter_jenis_sql = " AND (c.category_name NOT LIKE '%Tidak Berwujud%' AND c.category_name NOT LIKE '%Intangible%' AND c.category_name NOT LIKE '%Amortisasi%') ";
                    } elseif ($f_jenis_aset == 'tidak_berwujud') {
                        $filter_jenis_sql = " AND (c.category_name LIKE '%Tidak Berwujud%' OR c.category_name LIKE '%Intangible%' OR c.category_name LIKE '%Amortisasi%') ";
                    }

                    // 🚀 ENGINE POINT-IN-TIME CALCULATION
                    $month_filter_depr_periodik = $f_month_master ? "AND periode_bulan <= " . (int)$f_month_master : "";
                    $month_filter_capex = $f_month_master ? sprintf("%02d", (int)$f_month_master) : "12";
                    $limit_month = $f_month_master ? (int)$f_month_master : 12;

                    // Menggunakan LAST_DAY untuk memotong CAPEX di akhir bulan terpilih
                    $sql_master = "SELECT a.*, t.type_name, c.category_name, 
                        IFNULL((SELECT SUM(nilai_susut) FROM asset_depreciation WHERE asset_id = a.id AND periode_tahun = $f_year_master $month_filter_depr_periodik), 0) as akum_periodik, 
                        IFNULL((SELECT SUM(nilai_penambahan) FROM asset_improvements WHERE asset_id = a.id AND jenis_penambahan != 'Perolehan Awal' AND tanggal <= LAST_DAY('$f_year_master-$month_filter_capex-01')), 0) as total_capex,
                        IFNULL((SELECT SUM(nilai_susut) FROM asset_depreciation WHERE asset_id = a.id AND ((periode_tahun < $f_year_master) OR (periode_tahun = $f_year_master AND periode_bulan <= $limit_month))), 0) as akum_sistem
                        FROM assets a 
                        LEFT JOIN asset_types t ON a.type_id = t.id 
                        LEFT JOIN asset_categories c ON a.category_id = c.id
                        WHERE a.status='Aktif' $filter_jenis_sql ORDER BY a.id DESC";
                    
                    $res_assets = $conn->query($sql_master);
                    
                    while($r = $res_assets->fetch_assoc()) { 
                        $years = round($r['useful_life'] / 12, 1); 
                        $basis = (double)$r['purchase_value'] + (double)$r['total_capex'];
                        
                        // 🚀 RUMUS SEJATI (THE TRUE MATH) DENGAN CUT-OFF BULAN/TAHUN
                        $akum_migrasi = ($r['purchase_mode'] == 'saldo_awal') ? (double)$r['residual_value'] : 0;
                        $total_akum = $akum_migrasi + (double)$r['akum_sistem'];
                        $nilai_buku = $basis - $total_akum;
                        
                        $beban_bln = $r['useful_life'] > 0 ? ($basis - $akum_migrasi) / $r['useful_life'] : 0;
                        
                        // MENAMBAHKAN KE VARIABEL TOTAL
                        $t_akuisisi += $r['purchase_value'];
                        $t_penambahan += $r['total_capex'];
                        $t_akum_periodik += $r['akum_periodik'];
                        $t_total_akum += $total_akum;
                        $t_nilai_buku += $nilai_buku;

                        // 🚀 Injeksi kalkulasi ke dalam JSON untuk modal X-Ray View
                        $r['calc_total_akum'] = $total_akum;
                        $r['calc_nilai_buku'] = $nilai_buku;
                        $r['calc_basis'] = $basis;
                        $json_data = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td class="ps-4 text-start"><code><?= $r['asset_code'] ?></code></td>
                        <td class="text-start fw-bold text-dark"><?= $r['asset_name'] ?></td>
                        <td><?= $years ?> Thn</td>
                        <td class="text-primary fw-bold"><?= ($years>0?round(100/$years,1):0) ?>%</td>
                        <td class="text-end text-danger fw-bold"><?= number_format($beban_bln) ?></td>
                        <td class="text-end"><?= number_format($r['purchase_value']) ?></td>
                        <td class="text-end text-primary fw-bold"><?= number_format($r['total_capex']) ?></td>
                        <td class="text-end fw-bold"><?= number_format($r['akum_periodik']) ?></td>
                        <td class="text-end fw-bold" style="background:#f8fafc;"><?= number_format($total_akum) ?></td>
                        <td class="text-end fw-bold text-success pe-4"><?= number_format($nilai_buku) ?></td>
                        <td class="text-center no-print pe-4">
                            <div class="btn-group btn-group-sm rounded-pill border bg-white overflow-hidden shadow-sm">
                                <!-- 🚀 INJEKSI MUTLAK: Tombol Detail (X-Ray View) -->
                                <button class="btn btn-white text-info border-end" onclick='showDetailAsset(<?= $json_data ?>)' title="Lihat Detail Aset"><i class="fas fa-eye"></i></button>
                                
                                <button class="btn btn-white text-warning border-end" onclick='editAsset(<?= $json_data ?>)' title="Ubah Data"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-white text-danger" onclick="deleteAsset(<?= $r['id'] ?>)" title="Hapus Aset"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php } ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end py-3 text-uppercase text-dark pe-3">Total Keseluruhan Aset</td>
                            <td class="text-end text-dark"><?= number_format($t_akuisisi) ?></td>
                            <td class="text-end text-primary"><?= number_format($t_penambahan) ?></td>
                            <td class="text-end text-warning"><?= number_format($t_akum_periodik) ?></td>
                            <td class="text-end text-dark"><?= number_format($t_total_akum) ?></td>
                            <td class="text-end text-success pe-4"><?= number_format($t_nilai_buku) ?></td>
                            <td class="no-print pe-4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    <?php elseif($active_tab == 'improvement'): ?>
        <div class="row g-4 text-dark">
            <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4 p-4 bg-white border-top border-primary border-4 sticky-top" style="top:20px;"><h6 class="fw-bold mb-3">Input Penambahan Nilai (CAPEX)</h6><form action="asset_action.php" method="POST"><input type="hidden" name="action" value="save_improvement"><div class="mb-3"><label class="form-label small fw-bold">Pilih Aset</label><select name="asset_id" class="form-select border-0 bg-light rounded-pill fw-bold shadow-none" required><option value="">-- Pilih --</option><?php foreach($conn->query("SELECT id, asset_name FROM assets WHERE status='Aktif' ORDER BY asset_name ASC") as $aa) echo "<option value='{$aa['id']}'>{$aa['asset_name']}</option>"; ?></select></div><div class="row g-2 mb-3"><div class="col-6"><label class="form-label small fw-bold">Tgl Aksi</label><input type="date" name="tanggal" class="form-control border-0 bg-light rounded-pill" value="<?= date('Y-m-d') ?>" required></div><div class="col-6"><label class="form-label small fw-bold">Jenis</label><select name="jenis" class="form-select border-0 bg-light rounded-pill shadow-none" required><option value="Renovasi">Renovasi</option><option value="Upgrade">Upgrade</option></select></div></div><div class="mb-3"><label class="form-label small fw-bold">Nominal (Rp)</label><input type="text" name="amount" class="form-control border-0 bg-light rounded-pill text-end fw-bold text-primary fs-5 shadow-none" placeholder="0" onkeyup="fmtRp(this)" required></div><div class="mb-3"><label class="form-label small fw-bold text-muted">Sumber Dana</label><select name="source_account" class="form-select border-0 bg-light rounded-pill shadow-none fw-bold text-danger" required><option value="">-- Kas/Bank --</option><?php foreach($conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE (kategori IN ('Kas','Bank') OR is_cash_account=1) AND is_group=0 AND is_active=1") as $k) echo "<option value='{$k['kode_akun']}'>{$k['nama_akun']}</option>"; ?></select></div><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">POSTING KAPITALISASI</button></form></div></div>
            <div class="col-md-8"><div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white h-100"><div class="card-header bg-white p-4 border-bottom"><h6 class="fw-bold mb-0 text-dark">Riwayat Penambahan Nilai Aset</h6></div><div class="table-responsive"><table class="table table-hover align-middle mb-0 text-center"><thead class="table-light small"><tr><th class="ps-4">Tgl</th><th>Asset</th><th class="text-end">Nominal</th><th class="text-center">Jurnal</th><th class="text-center pe-4">Aksi</th></tr></thead><tbody>
            <?php foreach($conn->query("SELECT i.*, a.asset_name, j.no_jurnal FROM asset_improvements i JOIN assets a ON i.asset_id = a.id LEFT JOIN syifa_jurnal j ON i.journal_id = j.id ORDER BY i.tanggal DESC") as $m): ?>
            <tr><td class="ps-4 small"><?= date('d/m/y', strtotime($m['tanggal'])) ?></td><td class="text-start"><b><?= $m['asset_name'] ?></b></td><td class="text-end fw-bold text-success">+<?= number_format($m['nilai_penambahan']) ?></td><td class="text-center"><span class="badge bg-dark rounded-pill px-3"><?= $m['no_jurnal'] ?></span></td><td class="text-center pe-4"><div class="btn-group btn-group-sm rounded-pill border overflow-hidden"><button class="btn btn-white text-warning border-end" onclick="editCashFromAsset(<?= $m['journal_id'] ?>)"><i class="fas fa-edit"></i></button><a href="asset_action.php?action=delete_improvement&id=<?= $m['id'] ?>" class="btn btn-white text-danger" onclick="return confirm('Hapus riwayat penambahan & pulihkan saldo aset?')"><i class="fas fa-trash"></i></a></div></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div></div>
        </div>

    <?php elseif($active_tab == 'engine'): ?>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white shadow-sm">
            <div class="card-header bg-dark text-white p-4 d-flex justify-content-between align-items-center text-white">
                <div>
                    <h5 class="fw-bold mb-0 text-white">Mesin Penyusutan Otomatis</h5>
                    <small class="opacity-50 text-white">Garis Lurus Berdasarkan Total Investasi (Akuisisi + CAPEX).</small>
                </div>
                <div class="d-flex gap-2 no-print">
                    <button class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold shadow-sm" onclick="showResetModal()"><i class="fas fa-undo me-2"></i>BATALKAN PERIODE</button>
                    <button class="btn btn-warning rounded-pill px-4 btn-sm fw-bold shadow text-dark" onclick="showRunDeprModal()"><i class="fas fa-play me-2"></i>JALANKAN PENYUSUTAN</button>
                </div>
            </div>
            <div class="table-responsive text-dark">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-light small fw-bold">
                        <tr><th class="ps-4 text-start">Aset (Antrean Bulan Ini)</th><th class="text-end">Basis Susut</th><th class="text-end text-success">Neto</th><th class="text-center">Sisa (Bln)</th><th class="text-end pe-4 text-danger">Beban Susut</th></tr>
                    </thead>
                    <tbody>
                    <?php 
                    $q_q = $conn->query("SELECT a.*, (SELECT IFNULL(SUM(nilai_penambahan), 0) FROM asset_improvements WHERE asset_id = a.id AND jenis_penambahan != 'Perolehan Awal') as tot_capex FROM assets a WHERE a.status='Aktif' AND a.current_book_value > 0 AND NOT EXISTS (SELECT 1 FROM asset_depreciation ad WHERE ad.asset_id = a.id AND ad.periode_bulan = $cur_month AND ad.periode_tahun = $cur_year)"); 
                    if(!$q_q || $q_q->num_rows == 0) echo "<tr><td colspan='5' class='py-5 text-muted fw-bold italic text-center'><i class='fas fa-check-circle text-success fa-2x mb-2 d-block'></i>Seluruh aset aktif telah disusutkan untuk bulan ini ($cur_month/$cur_year).</td></tr>";
                    else { while($q = $q_q->fetch_assoc()){ 
                        $d1 = new DateTime($q['purchase_date']); $d2 = new DateTime(date('Y-m-01'));
                        $rem = (int)$q['useful_life'] - (($d1->diff($d2)->y * 12) + $d1->diff($d2)->m);
                        
                        $akum_migrasi = ($q['purchase_mode'] == 'saldo_awal') ? (double)$q['residual_value'] : 0;
                        $basis = (double)$q['purchase_value'] + (double)$q['tot_capex'];
                        $est = $q['useful_life'] > 0 ? ($basis - $akum_migrasi) / $q['useful_life'] : 0;
                    ?>
                    <tr><td class="ps-4 text-start"><b><?= $q['asset_name'] ?></b></td><td class="text-end"><?= number_format($basis) ?></td><td class="text-end text-success fw-bold"><?= number_format($q['current_book_value']) ?></td><td class="text-center"><?= ($rem<=0?1:$rem) ?> bln</td><td class="text-end pe-4 fw-bold text-danger"><?= number_format($est) ?></td></tr>
                    <?php } } ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif($active_tab == 'audit'): ?>
        <?php 
            $f_cat = $_GET['f_cat'] ?? ''; $f_type = $_GET['f_type'] ?? '';
            $f_start = $_GET['f_start'] ?? date('Y-m-01'); $f_end = $_GET['f_end'] ?? date('Y-m-d');
            $where = "WHERE DATE(d.created_at) BETWEEN '$f_start' AND '$f_end'";
            if($f_cat) $where .= " AND c.id = '$f_cat'";
            if($f_type) $where .= " AND a.type_id = '$f_type'";
            
            $sql_audit = "SELECT d.*, a.asset_name, t.type_name, (SELECT SUM(nilai_penambahan) FROM asset_improvements WHERE asset_id = a.id AND MONTH(tanggal) = d.periode_bulan AND YEAR(tanggal) = d.periode_tahun AND jenis_penambahan != 'Perolehan Awal') as pen_bln FROM asset_depreciation d JOIN assets a ON d.asset_id = a.id JOIN asset_categories c ON a.category_id = c.id LEFT JOIN asset_types t ON a.type_id = t.id $where ORDER BY d.id DESC";
            $res_audit = $conn->query($sql_audit);
        ?>
        <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4 no-print text-dark"><form method="GET" class="row g-3 align-items-end"><input type="hidden" name="page" value="aset_manajemen"><input type="hidden" name="tab" value="audit"><div class="col-md-2"><label class="form-label small fw-bold">Kategori</label><select name="f_cat" class="form-select border-0 shadow-sm rounded-pill small"><?php echo "<option value=''>Semua</option>"; foreach($master_cats as $mc) echo "<option value='{$mc['id']}' ".($f_cat==$mc['id']?'selected':'').">{$mc['category_name']}</option>"; ?></select></div><div class="col-md-3"><label class="form-label small fw-bold">Jenis Klasifikasi</label><select name="f_type" class="form-select border-0 shadow-sm rounded-pill small"><?php echo "<option value=''>Semua</option>"; foreach($master_types as $mt) echo "<option value='{$mt['id']}' ".($f_type==$mt['id']?'selected':'').">{$mt['type_name']}</option>"; ?></select></div><div class="col-md-2"><label class="form-label small fw-bold">Mulai</label><input type="date" name="f_start" class="form-control border-0 shadow-sm rounded-pill small" value="<?= $f_start ?>"></div><div class="col-md-2"><label class="form-label small fw-bold">Sampai</label><input type="date" name="f_end" class="form-control border-0 shadow-sm rounded-pill small" value="<?= $f_end ?>"></div><div class="col-md-3"><button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm">FILTER AUDIT</button></div></form></div>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white text-dark"><div class="table-responsive"><table class="table table-hover align-middle mb-0 text-center"><thead><tr><th class="ps-4">Eksekusi</th><th class="text-start">Asset</th><th>Periode</th><th class="text-end text-success">Penambahan</th><th class="text-end text-danger">Susut</th><th class="text-end pe-4 text-primary">Buku Akhir</th></tr></thead><tbody>
        <?php $t_p=0; $t_s=0; if($res_audit && $res_audit->num_rows > 0): while($ad = $res_audit->fetch_assoc()){ $t_p+=(double)$ad['pen_bln']; $t_s+=(double)$ad['nilai_susut']; ?>
        <tr><td class="ps-4 text-muted small"><?= date('d/m/y H:i', strtotime($ad['created_at'])) ?></td><td class="text-start"><b><?= $ad['asset_name'] ?></b><br><small class="text-muted"><?= $ad['type_name'] ?></small></td><td class="fw-bold"><?= $nama_bulan[$ad['periode_bulan']] ?> <?= $ad['periode_tahun'] ?></td><td class="text-end fw-bold text-success"><?= $ad['pen_bln']>0?number_format($ad['pen_bln']):'-' ?></td><td class="text-end text-danger fw-bold"><?= number_format($ad['nilai_susut']) ?></td><td class="text-end fw-bold text-primary pe-4"><?= number_format($ad['nilai_buku_akhir']) ?></td></tr>
        <?php } endif; ?>
        </tbody><tfoot class="bg-light fw-bold"><tr><td colspan="3" class="ps-4 py-3 text-start uppercase">Total Ringkasan Audit Trail</td><td class="text-end text-success">Rp <?= number_format($t_p) ?></td><td class="text-end text-danger">Rp <?= number_format($t_s) ?></td><td></td></tr></tfoot></table></div></div>
    <?php endif; ?>
</div>

<!-- ======================================================================= -->
<!-- 🚀 MODAL X-RAY DETAIL ASET (DIINJEKSIKAN MUTLAK SESUAI INSTRUKSI)       -->
<!-- ======================================================================= -->
<div class="modal fade" id="modalDetailAsset" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-info text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-eye me-2"></i>Rincian Detail Aset</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="text-center mb-4">
                    <div class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-1 rounded-pill mb-2" id="det_kategori"></div>
                    <h5 class="fw-bold text-dark mb-0" id="det_nama_aset"></h5>
                    <code class="text-muted" id="det_kode_aset"></code>
                </div>
                <div class="bg-white p-3 rounded-4 shadow-sm border">
                    <table class="table table-borderless table-sm mb-0">
                        <tbody>
                            <tr><td class="text-muted fw-bold small" width="45%">Tanggal Perolehan</td><td width="5%">:</td><td class="fw-bold text-dark text-end" id="det_tgl_perolehan"></td></tr>
                            <tr><td class="text-muted fw-bold small">Harga Perolehan</td><td>:</td><td class="fw-bold text-primary text-end" id="det_harga_perolehan"></td></tr>
                            <tr><td class="text-muted fw-bold small">Akumulasi Penyusutan</td><td>:</td><td class="fw-bold text-danger text-end" id="det_akum_penyusutan"></td></tr>
                            <tr><td colspan="3"><hr class="my-2 border-secondary opacity-25"></td></tr>
                            <tr><td class="text-muted fw-bold small">Nilai Buku Saat Ini</td><td>:</td><td class="fw-bold text-success fs-5 text-end" id="det_nilai_buku"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer p-3 border-0 bg-white">
                <button type="button" class="btn btn-secondary w-100 rounded-pill py-2 fw-bold shadow-sm" data-bs-dismiss="modal">TUTUP PENGAMATAN</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalRunDepr" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form action="asset_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 text-dark">
            <input type="hidden" name="action" value="run_depreciation">
            <div class="modal-header bg-warning text-dark border-0 p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-play-circle me-2"></i>Jalankan Penyusutan Aset</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-info border-info shadow-sm rounded-4 small fw-bold mb-4">
                    <i class="fas fa-info-circle me-2"></i>Sistem hanya akan menyusutkan aset yang BELUM memiliki riwayat penyusutan pada bulan yang Anda pilih di bawah ini.
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Pilih Bulan</label>
                        <select name="bulan" class="form-select border-0 shadow-sm rounded-pill px-3 fw-bold text-primary" required>
                            <?php for($m=1; $m<=12; $m++) echo "<option value='$m' ".($cur_month==$m?'selected':'').">{$nama_bulan[$m]}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Tahun Berjalan</label>
                        <input type="number" name="tahun" class="form-control border-0 shadow-sm rounded-pill px-3 fw-bold text-center" value="<?= $cur_year ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-3 border-0 bg-white">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning rounded-pill px-5 fw-bold shadow text-dark text-uppercase">Proses Sekarang</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalResetDepr" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form action="asset_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 text-dark">
            <input type="hidden" name="action" value="reset_period_depreciation">
            <div class="modal-header bg-danger text-white border-0 p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-undo me-2"></i>Batalkan Penyusutan</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-warning border-warning shadow-sm rounded-4 small fw-bold mb-4">
                    <i class="fas fa-exclamation-triangle me-2"></i>Peringatan: Membatalkan penyusutan akan menghapus jurnal beban susut yang telah terbentuk dan memulihkan nilai buku aset seperti semula.
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Pilih Bulan</label>
                        <select name="bulan" class="form-select border-0 shadow-sm rounded-pill px-3 fw-bold text-danger" required>
                            <?php for($m=1; $m<=12; $m++) echo "<option value='$m' ".($cur_month==$m?'selected':'').">{$nama_bulan[$m]}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="small fw-bold text-muted uppercase mb-1">Tahun Berjalan</label>
                        <input type="number" name="tahun" class="form-control border-0 shadow-sm rounded-pill px-3 fw-bold text-center" value="<?= $cur_year ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-3 border-0 bg-white">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger rounded-pill px-5 fw-bold shadow text-uppercase">Proses Pembatalan</button>
            </div>
        </form>
    </div>
</div>

<?php include 'cash_modals_shared.php'; ?>
<?php include 'asset_modals_shared.php'; ?>

<script>
// 🚀 FUNGSI OMNI X-RAY (DETAIL ASET)
function showDetailAsset(data) {
    document.getElementById('det_nama_aset').innerText = data.asset_name;
    document.getElementById('det_kode_aset').innerText = data.asset_code;
    document.getElementById('det_kategori').innerText = data.category_name || data.type_name || 'Kategori Umum';
    
    // Format Tanggal (d M Y)
    let dateObj = new Date(data.purchase_date);
    let options = { day: 'numeric', month: 'long', year: 'numeric' };
    document.getElementById('det_tgl_perolehan').innerText = dateObj.toLocaleDateString('id-ID', options);
    
    document.getElementById('det_harga_perolehan').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(data.purchase_value);
    document.getElementById('det_akum_penyusutan').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(data.calc_total_akum);
    document.getElementById('det_nilai_buku').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(data.calc_nilai_buku);
    
    new bootstrap.Modal(document.getElementById('modalDetailAsset')).show();
}

function fmtRp(el){ el.value = new Intl.NumberFormat('id-ID').format(el.value.replace(/\D/g, "")); }

function showRunDeprModal() { new bootstrap.Modal(document.getElementById('modalRunDepr')).show(); }

function showResetModal(){ new bootstrap.Modal(document.getElementById('modalResetDepr')).show(); }
function deleteAsset(id) { if(confirm('Hapus aset ini secara permanen? Seluruh riwayat akan hilang.')) { window.location.href = `asset_action.php?action=delete_asset&id=${id}`; } }

function editCashFromAsset(jid){ 
    if(jid > 0 && typeof openTrxModal === 'function') { 
        openTrxModal('expense', jid); 
        setTimeout(() => {
            const retInp = document.getElementById('inpReturnPage');
            if (retInp) retInp.value = 'aset_manajemen&tab=improvement';
        }, 100);
    } else { 
        alert("Data jurnal tidak ditemukan atau Modul Kas gagal dimuat."); 
    } 
}
</script>