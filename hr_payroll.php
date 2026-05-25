<?php
/**
 * hr_payroll.php - PUSAT OPERASIONAL PAYROLL SYIFA ERP
 * Versi: 10.2 (Grand Master - Dynamic Email Prompter Edition)
 * Perbaikan Mutlak:
 * Menambahkan Pop-Up (Modal) untuk meminta Teks Pengantar Email kepada 
 * pengguna sebelum mengirim Slip Gaji, sehingga teks email menjadi dinamis 
 * dan bebas diubah kapan saja tanpa mengubah source code.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

// --- AJAX HANDLER: SEARCH SUGGESTIONS (PEGAWAI) ---
if (isset($_GET['ajax']) && $_GET['ajax'] == 'search_pegawai') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $q = $conn->real_escape_string($_GET['q'] ?? '');
    $res = $conn->query("SELECT nama_lengkap FROM hr_pegawai WHERE nama_lengkap LIKE '%$q%' LIMIT 10");
    $data = [];
    if($res) { while($row = $res->fetch_assoc()) $data[] = $row['nama_lengkap']; }
    echo json_encode($data);
    exit;
}

if(function_exists('guardPage')) { guardPage('penggajian'); }

$active_tab = $_GET['tab'] ?? 'proses';
$view = $_GET['view'] ?? 'main';
$id_payroll = (int)($_GET['id'] ?? 0);
$nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

$header = null; $details = [];
if ($id_payroll > 0) {
    $header = $conn->query("SELECT * FROM hr_payroll_header WHERE id = $id_payroll")->fetch_assoc();
    if($header) {
        $details = $conn->query("SELECT d.*, p.nama_lengkap, p.nip, p.jabatan, p.unit_kerja 
                                FROM hr_payroll_detail d 
                                JOIN hr_pegawai p ON d.pegawai_id = p.id 
                                WHERE d.payroll_id = $id_payroll ORDER BY p.nama_lengkap ASC")->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden bg-white">
        <div class="card-header bg-white pt-4 px-4 border-0 d-flex justify-content-between align-items-center">
            <div>
                <h4 class="fw-bold text-primary mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Manajemen Payroll</h4>
                <p class="text-muted small fw-bold mb-0 text-uppercase">Sistem Kontrol Gaji Terpadu.</p>
            </div>
            <?php if($view == 'detail'): ?>
                <a href="?page=penggajian&tab=<?= $active_tab ?>" class="btn btn-light rounded-pill px-4 fw-bold border shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-2"></i>Kembali</a>
            <?php endif; ?>
        </div>

        <div class="card-body p-4">
            <?php if($view == 'main'): ?>
            <ul class="nav nav-pills mb-4">
                <li class="nav-item"><a class="nav-link <?= $active_tab == 'proses' ? 'active' : '' ?>" href="?page=penggajian&tab=proses">Proses Gaji</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_tab == 'slip' ? 'active' : '' ?>" href="?page=penggajian&tab=slip">Slip Gaji</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_tab == 'bayar' ? 'active' : '' ?>" href="?page=penggajian&tab=bayar">Pembayaran</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_tab == 'laporan' ? 'active' : '' ?>" href="?page=penggajian&tab=laporan">Laporan Payroll</a></li>
            </ul>

            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
                    <i class="fas fa-info-circle me-2"></i><?= $_SESSION['flash']['msg'] ?><button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <div class="tab-content">
                <!-- TAB 1: PROSES PENGGAJIAN -->
                <?php if($active_tab == 'proses'): ?>
                <div class="row g-4 animate__animated animate__fadeIn">
                    <div class="col-lg-4">
                        <div class="card bg-light border-0 rounded-4 p-4 shadow-sm">
                            <h6 class="fw-bold mb-3 text-primary">Generate Periode Baru</h6>
                            <form action="hr_action.php" method="POST">
                                <input type="hidden" name="action" value="process_payroll">
                                <div class="mb-3"><label class="small fw-bold text-muted uppercase">Tanggal Slip</label><input type="date" name="tgl_slip" class="form-control border-0 shadow-sm" value="<?= date('Y-m-d') ?>" required></div>
                                <div class="row g-2 mb-4">
                                    <div class="col-7"><select name="bulan" class="form-select border-0 shadow-sm"><?php for($m=1;$m<=12;$m++) echo "<option value='$m' ".(date('m')==$m?'selected':'').">{$nama_bulan[$m]}</option>"; ?></select></div>
                                    <div class="col-5"><input type="number" name="tahun" class="form-control border-0 shadow-sm" value="<?= date('Y') ?>"></div>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">MULAI HITUNG GAJI</button>
                            </form>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="table-responsive rounded-4 border bg-white shadow-sm">
                            <table class="table table-hover align-middle mb-0 text-center">
                                <thead class="table-light small text-uppercase fw-bold text-muted">
                                    <tr><th width="80">Detail</th><th>Periode</th><th class="text-end">Total Gaji</th><th width="100">Status</th><th width="120">Aksi</th></tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $hist = $conn->query("SELECT * FROM hr_payroll_header ORDER BY id DESC LIMIT 15");
                                    while($h = $hist->fetch_assoc()): 
                                        $st = strtoupper(trim($h['status']));
                                        $is_paid = ($st != 'DRAFT' && $st != 'FINAL');
                                        
                                        $badge_clr = match($st){ 
                                            'DRAFT'=>'warning text-dark', 
                                            'FINAL'=>'success', 
                                            default => 'info' 
                                        };
                                    ?>
                                    <tr>
                                        <td class="text-center"><a href="?page=penggajian&view=detail&id=<?= $h['id'] ?>&tab=proses" class="btn btn-sm btn-light border rounded-pill px-3 text-primary shadow-sm"><i class="fas fa-search"></i></a></td>
                                        <td class="fw-bold text-dark ps-4"><?= $nama_bulan[$h['periode_bulan']] ?> <?= $h['periode_tahun'] ?></td>
                                        <td class="text-end fw-bold text-primary">Rp <?= number_format($h['total_netto']) ?></td>
                                        <td><span class="badge bg-<?= $badge_clr ?> rounded-pill px-3"><?= $h['status'] ?></span></td>
                                        <td>
                                            <?php if($st == 'DRAFT'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold" onclick="confirmAction('finalize_payroll', <?= $h['id'] ?>)">POST</button>
                                                <button type="button" class="btn btn-sm btn-link text-danger ms-1" onclick="confirmAction('delete_payroll', <?= $h['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                                            <?php elseif($st == 'FINAL'): ?>
                                                <button type="button" class="btn btn-sm btn-link text-muted" onclick="confirmAction('cancel_payroll', <?= $h['id'] ?>)" title="Batalkan Post (AJP)"><i class="fas fa-undo"></i></button>
                                            <?php else: ?>
                                                <span class="text-muted small italic"><i class="fas fa-lock me-1"></i>Terkunci</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TAB 2: SLIP GAJI -->
                <?php if($active_tab == 'slip'): ?>
                <div class="animate__animated animate__fadeIn">
                    <?php
                        $f_bulan  = $_GET['f_bulan'] ?? '';
                        $f_tahun  = $_GET['f_tahun'] ?? date('Y');
                        $f_search = $_GET['f_search'] ?? '';
                    ?>
                    <div class="card bg-light border-0 rounded-4 p-3 mb-4 shadow-sm">
                        <form action="" method="GET" class="row g-2 align-items-center">
                            <input type="hidden" name="page" value="penggajian">
                            <input type="hidden" name="tab" value="slip">
                            
                            <div class="col-md-2">
                                <select name="f_bulan" class="form-select border-0 shadow-sm rounded-pill px-3 fw-bold" onchange="this.form.submit()">
                                    <option value="">-- Semua Bulan --</option>
                                    <?php for($m=1;$m<=12;$m++) echo "<option value='$m' ".($f_bulan==$m?'selected':'').">{$nama_bulan[$m]}</option>"; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="f_tahun" class="form-select border-0 shadow-sm rounded-pill px-3 fw-bold text-primary" onchange="this.form.submit()">
                                    <option value="">-- Tahun --</option>
                                    <?php for($y=date('Y')+1; $y>=2020; $y--) echo "<option value='$y' ".($f_tahun==$y?'selected':'').">$y</option>"; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 position-relative">
                                <div class="position-relative shadow-sm rounded-pill overflow-hidden border bg-white d-flex align-items-center">
                                    <i class="fas fa-search text-muted ms-3"></i>
                                    <input type="text" name="f_search" id="inpSearchPegawai" class="form-control border-0 bg-transparent px-3 fw-bold shadow-none" placeholder="Cari Nama Pegawai..." value="<?= htmlspecialchars($f_search) ?>" autocomplete="off">
                                </div>
                                <div id="suggestContainer" class="list-group position-absolute w-100 shadow mt-1 d-none" style="z-index: 9999; top: 100%; max-height: 200px; overflow-y: auto;"></div>
                            </div>
                            
                            <div class="col-md-2 text-end">
                                <a href="?page=penggajian&tab=slip" class="btn btn-outline-secondary w-100 rounded-pill fw-bold border shadow-sm">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-dark mb-0"><i class="fas fa-file-invoice me-2"></i>Daftar Slip Gaji Pegawai</h6>
                        <!-- 🚀 TOMBOL KIRIM KOLEKTIF MENGGUNAKAN JS -->
                        <button type="button" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="prepareEmailSlip('bulk')">
                            <i class="fas fa-paper-plane me-2"></i>Kirim Email Kolektif
                        </button>
                    </div>

                    <div class="table-responsive rounded-4 border bg-white shadow-sm">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase fw-bold text-muted">
                                <tr>
                                    <!-- Checkbox -->
                                    <th class="ps-4 text-center" width="50">
                                        <input class="form-check-input border-secondary shadow-sm" type="checkbox" id="checkAllSlip" title="Pilih Semua">
                                    </th>
                                    <th width="50">No</th>
                                    <th>Nama Pegawai</th>
                                    <th>Identitas</th>
                                    <th>Periode</th>
                                    <th class="text-end">Netto (THP)</th>
                                    <th class="text-center pe-4" width="220">Aksi Cetak / Kirim</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $where_slip = "WHERE UPPER(h.status) != 'DRAFT'";
                                if($f_bulan)  $where_slip .= " AND h.periode_bulan = '$f_bulan'";
                                if($f_tahun)  $where_slip .= " AND h.periode_tahun = '$f_tahun'";
                                if($f_search) $where_slip .= " AND p.nama_lengkap LIKE '%$f_search%'";

                                $sql_slip = "SELECT d.*, p.nama_lengkap, p.nip, h.periode_bulan, h.periode_tahun 
                                             FROM hr_payroll_detail d 
                                             JOIN hr_pegawai p ON d.pegawai_id = p.id 
                                             JOIN hr_payroll_header h ON d.payroll_id = h.id 
                                             $where_slip
                                             ORDER BY h.id DESC, p.nama_lengkap ASC LIMIT 100";
                                $res_slip = $conn->query($sql_slip);
                                $no=1; 
                                if($res_slip && $res_slip->num_rows > 0):
                                    while($s = $res_slip->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4 text-center">
                                            <input class="form-check-input chk-slip border-secondary" type="checkbox" name="slip_ids[]" value="<?= $s['id'] ?>">
                                        </td>
                                        <td class="text-center text-muted"><?= $no++ ?></td>
                                        <td class="fw-bold text-dark"><?= strtoupper($s['nama_lengkap']) ?></td>
                                        <td><code><?= $s['nip'] ?></code></td>
                                        <td><span class="badge bg-light text-dark border"><?= $nama_bulan[$s['periode_bulan']] ?> <?= $s['periode_tahun'] ?></span></td>
                                        <td class="text-end fw-bold text-success">Rp <?= number_format($s['gaji_bersih']) ?></td>
                                        <td class="text-center pe-4">
                                            <div class="btn-group btn-group-sm rounded-pill shadow-sm border overflow-hidden">
                                                <a href="print_slip_gaji.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-light text-primary border-end border-0 px-3 fw-bold" title="Cetak/Lihat PDF"><i class="fas fa-print"></i> Cetak</a>
                                                <!-- 🚀 TOMBOL KIRIM INDIVIDUAL MENGGUNAKAN JS -->
                                                <button type="button" class="btn btn-light text-success border-0 px-3 fw-bold" onclick="prepareEmailSlip('single', <?= $s['id'] ?>)" title="Kirim via Email"><i class="fas fa-paper-plane me-1"></i> Kirim</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; 
                                else: echo '<tr><td colspan="7" class="text-center py-5 text-muted small italic">Tidak ada slip yang sesuai kriteria.</td></tr>'; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TAB 3: PEMBAYARAN GAJI -->
                <?php if($active_tab == 'bayar'): ?>
                <div class="row g-4 animate__animated animate__fadeIn">
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm rounded-4 bg-primary text-white p-4">
                            <h5 class="fw-bold mb-3 text-white"><i class="fas fa-money-check-alt me-2"></i>Otorisasi Kasir</h5>
                            <form action="hr_action.php" method="POST">
                                <input type="hidden" name="action" value="pay_payroll">
                                <div class="mb-3">
                                    <label class="small fw-bold opacity-75 uppercase">Antrean Payroll (Siap Bayar)</label>
                                    <select name="payroll_id" class="form-select border-0 shadow-sm" required>
                                        <option value="">-- Pilih Antrean --</option>
                                        <?php 
                                        $f_list = $conn->query("SELECT * FROM hr_payroll_header WHERE UPPER(TRIM(status)) = 'FINAL' ORDER BY id DESC");
                                        while($f = $f_list->fetch_assoc()) echo "<option value='{$f['id']}'>{$nama_bulan[$f['periode_bulan']]} {$f['periode_tahun']} (Rp ".number_format($f['total_netto']).")</option>"; ?>
                                    </select>
                                </div>
                                <div class="mb-3"><label class="small fw-bold opacity-75 uppercase">Tgl Bayar</label><input type="date" name="tgl_bayar" class="form-control border-0 shadow-sm" value="<?= date('Y-m-d') ?>" required></div>
                                <div class="mb-4">
                                    <label class="small fw-bold opacity-75 uppercase">Rekening Sumber</label>
                                    <select name="kode_akun_kas" class="form-select border-0 shadow-sm text-primary fw-bold" required>
                                        <option value="">-- Pilih Rekening --</option>
                                        <?php 
                                        $sql_kas = "SELECT kode_akun, nama_akun FROM syifa_akun WHERE (kategori IN ('Kas', 'Bank') OR kode_akun LIKE '1-11%' OR kode_akun LIKE '1.11%' OR sub_kategori LIKE '%Kas%' OR is_cash_account = 1) AND is_group=0 AND is_active=1 ORDER BY kode_akun ASC";
                                        foreach($conn->query($sql_kas) as $k) echo "<option value='{$k['kode_akun']}'>{$k['kode_akun']} - {$k['nama_akun']}</option>"; 
                                        ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-white text-primary w-100 rounded-pill py-3 fw-bold shadow">POSTING PELUNASAN</button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-lg-8">
                        <h6 class="fw-bold mb-3">Audit Status Pembayaran</h6>
                        <div class="table-responsive rounded-4 border bg-white shadow-sm">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase fw-bold text-muted text-center">
                                    <tr><th>Periode</th><th class="text-end">Total</th><th width="120">Status</th><th width="100">Aksi</th></tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $hist_pay = $conn->query("SELECT * FROM hr_payroll_header WHERE UPPER(status) != 'DRAFT' ORDER BY id DESC LIMIT 50");
                                    if($hist_pay && $hist_pay->num_rows > 0):
                                        while($hp = $hist_pay->fetch_assoc()): 
                                            $st_up = strtoupper(trim($hp['status']));
                                            $is_paid = ($st_up != 'FINAL' && $st_up != 'DRAFT'); 
                                    ?>
                                        <tr class="text-center">
                                            <td class="fw-bold text-dark text-start ps-4"><?= $nama_bulan[$hp['periode_bulan']] ?> <?= $hp['periode_tahun'] ?></td>
                                            <td class="text-end fw-bold <?= $is_paid ? 'text-success' : 'text-danger' ?>">Rp <?= number_format($hp['total_netto']) ?></td>
                                            <td><span class="badge bg-<?= $is_paid ? 'info' : 'warning text-dark' ?> rounded-pill px-3"><?= $hp['status'] ?></span></td>
                                            <td>
                                                <div class="btn-group btn-group-sm rounded-pill border overflow-hidden bg-white shadow-sm">
                                                    <a href="?page=penggajian&view=detail&id=<?= $hp['id'] ?>&tab=bayar" class="btn btn-white text-info border-end" title="Audit Detail"><i class="fas fa-eye"></i></a>
                                                    <?php if($is_paid): ?>
                                                        <button type="button" class="btn btn-white text-danger" onclick="confirmAction('cancel_payment', <?= $hp['id'] ?>)" title="BATALKAN PEMBAYARAN (Buka Kunci)"><i class="fas fa-undo"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; 
                                    else: echo '<tr><td colspan="4" class="py-5 text-center text-muted small italic">Tidak ada histori pembayaran.</td></tr>'; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TAB 4: LAPORAN PAYROLL -->
                <?php if($active_tab == 'laporan'): ?>
                <div class="table-responsive rounded-4 border animate__animated animate__fadeIn bg-white shadow-sm">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark small text-uppercase fw-bold text-center">
                            <tr><th>Periode Laporan</th><th>Staf Penerima</th><th class="text-end">Total Bruto</th><th class="text-end">Potongan</th><th class="text-end">Netto Terbayar</th><th width="120">Detail</th></tr>
                        </thead>
                        <tbody>
                            <?php 
                            $res_lap = $conn->query("SELECT * FROM hr_payroll_header WHERE UPPER(status) NOT IN ('DRAFT', 'FINAL') ORDER BY id DESC");
                            if($res_lap && $res_lap->num_rows > 0):
                                while($l = $res_lap->fetch_assoc()): 
                                    $cnt = $conn->query("SELECT COUNT(*) as t FROM hr_payroll_detail WHERE payroll_id={$l['id']}")->fetch_assoc()['t'] ?? 0;
                                ?>
                                <tr class="text-center">
                                    <td class="fw-bold ps-4 text-start text-primary"><?= $nama_bulan[$l['periode_bulan']] ?> <?= $l['periode_tahun'] ?></td>
                                    <td><?= $cnt ?> Orang</td>
                                    <td class="text-end fw-bold">Rp <?= number_format($l['total_gross']) ?></td>
                                    <td class="text-end text-danger">- Rp <?= number_format($l['total_potongan']) ?></td>
                                    <td class="text-end fw-bold text-success">Rp <?= number_format($l['total_netto']) ?></td>
                                    <td><a href="?page=penggajian&view=detail&id=<?= $l['id'] ?>&tab=laporan" class="btn btn-sm btn-primary rounded-pill px-4 fw-bold shadow-sm small">LIHAT</a></td>
                                </tr>
                                <?php endwhile; 
                            else: echo '<tr><td colspan="6" class="py-5 text-center text-muted small italic">Laporan akan muncul otomatis di sini setelah proses pembayaran kas selesai.</td></tr>'; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- VIEW: DETAIL REKAP GAJI INDIVIDU -->
            <?php elseif($view == 'detail' && $header): ?>
            <div class="animate__animated animate__fadeIn">
                <div class="bg-primary bg-opacity-10 p-4 rounded-4 border border-primary border-opacity-25 mb-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold text-dark mb-1">Rincian Payroll Pegawai</h5>
                        <p class="text-muted mb-0 small uppercase">Periode: <b><?= $nama_bulan[$header['periode_bulan']] ?> <?= $header['periode_tahun'] ?></b> | Status: <span class="badge bg-primary rounded-pill px-3"><?= strtoupper($header['status']) ?></span></p>
                    </div>
                    <div class="text-end">
                        <small class="text-muted text-uppercase d-block fw-bold" style="font-size:10px;">Total Terbayar</small>
                        <h3 class="fw-bold text-primary mb-0">Rp <?= number_format($header['total_netto']) ?></h3>
                    </div>
                </div>

                <div class="table-responsive rounded-4 border bg-white shadow-sm">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase fw-bold text-muted">
                            <tr><th class="ps-4">Nama & NIP Pegawai</th><th>Unit & Jabatan</th><th class="text-end">Total Bruto</th><th class="text-end text-danger">Potongan</th><th class="text-end text-primary pe-4">Netto (THP)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($details as $d): ?>
                            <tr>
                                <td class="ps-4"><div class="fw-bold text-dark"><?= strtoupper($d['nama_lengkap']) ?></div><small class="text-muted">NIP: <?= $d['nip'] ?></small></td>
                                <td><div class="small fw-bold text-muted"><?= $d['jabatan'] ?: 'Staff' ?></div><div class="text-muted small"><?= $d['unit_kerja'] ?></div></td>
                                <td class="text-end">Rp <?= number_format($d['gapok'] + $d['tunjangan']) ?></td>
                                <td class="text-end text-danger">Rp <?= number_format($d['potongan']) ?></td>
                                <td class="text-end fw-bold text-primary pe-4">Rp <?= number_format($d['gaji_bersih']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 🚀 MODAL PROMPTER TEKS PENGANTAR EMAIL (DINAMIS) -->
<div class="modal fade" id="modalCustomEmail" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-success text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-envelope-open-text me-2"></i>Personalisasi Teks Email</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-start">
                <div class="alert alert-success bg-success bg-opacity-10 border-success border-opacity-25 shadow-sm rounded-4 small mb-4 text-dark">
                    <i class="fas fa-lightbulb me-1"></i> Teks di bawah ini akan digunakan sebagai pengantar email Slip Gaji. Anda dapat mengedit nama bank, kalimat pembuka, maupun menambahkan pengumuman. Biarkan <b>[PERIODE]</b> tetap ada agar sistem mengisinya secara otomatis.
                </div>
                
                <label class="small fw-bold text-muted mb-2 uppercase">Isi Pesan / Body Email</label>
                <!-- 🚀 TEXTAREA DENGAN TEMPLATE DEFAULT (Siap Diedit User) -->
                <textarea id="custom_email_text_input" class="form-control shadow-sm border-0 rounded-4 p-3 text-dark" rows="7" style="line-height: 1.6; font-size: 14px;">Assalamu'alaikum warrahmatullahi wabarakatuh,

Berikut kami kirimkan Slip Pembayaran Gaji Bulan [PERIODE] yang sudah di transfer ke Rekening Payroll BSI masing-masing.
Atas perhatiannya kami ucapkan terimakasih.

Wassalamu'alaikum.</textarea>
            </div>
            <div class="modal-footer p-4 border-0 bg-white d-flex justify-content-end">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-muted shadow-sm" data-bs-dismiss="modal">BATAL</button>
                <button type="button" class="btn btn-success rounded-pill px-5 fw-bold shadow" onclick="executeSendEmail()"><i class="fas fa-paper-plane me-2"></i>KIRIM SEKARANG</button>
            </div>
        </div>
    </div>
</div>

<script>
// 🚀 THE EMAIL PROMPTER ENGINE
let pendingEmailIds = [];

function prepareEmailSlip(type, id = null) {
    pendingEmailIds = [];
    if (type === 'single') {
        pendingEmailIds.push(id);
    } else if (type === 'bulk') {
        document.querySelectorAll('.chk-slip:checked').forEach(cb => {
            pendingEmailIds.push(cb.value);
        });
        if (pendingEmailIds.length === 0) {
            alert("Tidak ada slip gaji yang dipilih dari daftar.");
            return;
        }
    }
    
    // Tampilkan Pop-Up Editor Email
    new bootstrap.Modal(document.getElementById('modalCustomEmail')).show();
}

function executeSendEmail() {
    const customText = document.getElementById('custom_email_text_input').value;
    if (customText.trim() === '') {
        alert('Teks pengantar email tidak boleh kosong.'); 
        return;
    }

    if (!confirm("Kirim pemberitahuan Slip Gaji ke email pegawai? Pastikan konfigurasi SMTP sudah benar di Pengaturan Sistem.")) {
        return;
    }
    
    // Tutup Modal
    const modalEl = document.getElementById('modalCustomEmail');
    const modalInst = bootstrap.Modal.getInstance(modalEl);
    if(modalInst) modalInst.hide();

    // Buat Form Dinamis secara Mutlak
    const f = document.createElement('form'); 
    f.method = 'POST'; 
    f.action = 'hr_action.php';
    
    const act = document.createElement('input'); act.type = 'hidden'; act.name = 'action'; act.value = 'send_slip_email';
    f.appendChild(act);
    
    const txtArea = document.createElement('textarea'); txtArea.name = 'custom_email_text'; txtArea.value = customText; txtArea.style.display = 'none';
    f.appendChild(txtArea);
    
    pendingEmailIds.forEach(slipId => {
        const sId = document.createElement('input'); sId.type = 'hidden'; sId.name = 'slip_ids[]'; sId.value = slipId;
        f.appendChild(sId);
    });
    
    document.body.appendChild(f); 
    f.submit();
}

// Checkbox Kolektif
document.getElementById('checkAllSlip')?.addEventListener('change', function() {
    const isChecked = this.checked;
    document.querySelectorAll('.chk-slip').forEach(cb => cb.checked = isChecked);
});

function confirmAction(action, id) {
    let msg = "Konfirmasi aksi sistem?";
    if(action == 'delete_payroll') msg = "Peringatan: Hapus data payroll? Ini akan menghapus slip gaji permanen.";
    if(action == 'finalize_payroll') msg = "Posting jurnal beban & hutang gaji? Status akan terkunci.";
    if(action == 'cancel_payroll') msg = "Batalkan posting jurnal? Status kembali ke Draft.";
    if(action == 'cancel_payment') msg = "PENTING: Batalkan pelunasan kas? Jurnal pembayaran akan dihapus, saldo kas kembali, dan kunci di menu Proses Gaji akan terbuka kembali.";
    
    if(confirm(msg)) {
        const f = document.createElement('form'); f.method='POST'; f.action='hr_action.php';
        f.innerHTML = `<input type="hidden" name="action" value="${action}"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f); f.submit();
    }
}

// LOGIKA FETCH SUGGESTIONS (AUTOCOMPLETE)
document.addEventListener('DOMContentLoaded', function() {
    const searchInp = document.getElementById('inpSearchPegawai');
    const suggestBox = document.getElementById('suggestContainer');

    if(searchInp) {
        searchInp.addEventListener('keyup', function() {
            const val = this.value;
            if(val.length < 2) {
                suggestBox.classList.add('d-none');
                return;
            }

            fetch(`index.php?page=penggajian&ajax=search_pegawai&q=${val}`)
                .then(r => r.json())
                .then(data => {
                    suggestBox.innerHTML = '';
                    if(data.length > 0) {
                        data.forEach(name => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'list-group-item list-group-item-action text-start small fw-bold text-dark';
                            btn.innerHTML = `<i class="fas fa-user-circle me-2 text-primary"></i>${name}`;
                            btn.onclick = function() {
                                searchInp.value = name;
                                suggestBox.classList.add('d-none');
                                searchInp.form.submit(); // Auto submit saat dipilih
                            };
                            suggestBox.appendChild(btn);
                        });
                        suggestBox.classList.remove('d-none');
                    } else {
                        suggestBox.classList.add('d-none');
                    }
                })
                .catch(err => console.error("Error fetching suggestions:", err));
        });

        document.addEventListener('click', function(e) {
            if (e.target !== searchInp && e.target !== suggestBox) {
                suggestBox.classList.add('d-none');
            }
        });
    }
});
</script>

<style>
    .bg-primary .text-primary, .bg-primary .text-dark, .bg-primary .text-muted, .bg-primary h1, .bg-primary h2, .bg-primary h3, .bg-primary h4, .bg-primary h5, .bg-primary h6 { color: #ffffff !important; opacity: 1 !important; }
    .btn-white { background: #fff; border: none; transition: 0.2s; }
    .btn-white:hover { background: #f8f9fa; color: #0d6efd !important; }
    .nav-pills .nav-link { color: #64748b; font-weight: 700; border-radius: 50px; padding: 10px 25px; margin-right: 5px; }
    .nav-pills .nav-link.active { background-color: var(--bs-primary); color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
</style>