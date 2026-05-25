<?php
/**
 * laporan_piutang_mhs.php - LAPORAN TAGIHAN & PIUTANG MAHASISWA (SUPREME SYNC)
 * Versi: 2.6 (Grand Master - Dedicated Search Engine)
 * Perbaikan: 
 * 1. Menggunakan 'fetch_suggestions.php' sebagai mesin pencari yang stabil.
 * 2. Memperbaiki respon pencarian agar saran nama muncul saat diketik 2-3 huruf.
 * 3. Menghapus handler AJAX internal yang berpotensi konflik.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$view = $_GET['view'] ?? 'list';
$nim_target = $_GET['nim'] ?? '';

// --- PARAMETER FILTER ---
$f_start   = $_GET['f_start'] ?? date('Y-01-01');
$f_end     = $_GET['f_end'] ?? date('Y-m-d');
$f_prodi   = $_GET['f_prodi'] ?? '';
$f_angkatan= $_GET['f_angkatan'] ?? '';
$f_search  = $conn->real_escape_string($_GET['q'] ?? '');

// Resolver Akun Piutang
$CODE_PIUTANG = getAccountCode($conn, 'PIUTANG_MHS') ?: '1-1201';

// --- DATA MASTER ---
$prodi_list = $conn->query("SELECT * FROM mhs_prodi ORDER BY nama_prodi ASC")->fetch_all(MYSQLI_ASSOC);
$angkatan_list = $conn->query("SELECT DISTINCT angkatan FROM syifa_mahasiswa WHERE angkatan IS NOT NULL AND angkatan != '' ORDER BY angkatan DESC")->fetch_all(MYSQLI_ASSOC);

?>

<style>
    .table-monitoring thead th { background: #1e293b !important; color: #fff !important; font-size: 9px; text-transform: uppercase; text-align: center !important; vertical-align: middle !important; padding: 12px 5px; border: 1px solid #334155; }
    .table-monitoring tbody td { font-size: 11.5px; border-bottom: 1px solid #f1f5f9; padding: 12px 10px; vertical-align: middle; color: #334155; }
    .badge-status { font-size: 10px; font-weight: 800; padding: 5px 12px; border-radius: 50px; }
    .status-lunas { background: #dcfce7; color: #166534; }
    .status-sebagian { background: #fef9c3; color: #854d0e; }
    .status-belum { background: #fee2e2; color: #991b1b; }
    
    /* SUGGESTION BOX UI */
    .suggest-wrapper { position: relative; width: 100%; }
    .suggest-box { 
        position: absolute; 
        top: 100%; 
        left: 0; 
        right: 0; 
        background: #ffffff; 
        border: 1px solid #cbd5e1; 
        border-radius: 8px; 
        box-shadow: 0 10px 25px rgba(0,0,0,0.15); 
        z-index: 9999 !important; 
        display: none; 
        max-height: 300px; 
        overflow-y: auto;
        margin-top: 5px;
    }
    .suggest-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: 0.2s; font-size: 13px; color: #1e293b; display: flex; align-items: center; }
    .suggest-item:hover { background: #f1f5f9; color: #0d6efd !important; font-weight: 600; }
    .suggest-item code { font-family: 'Consolas', monospace; font-size: 11px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; margin-right: 10px; color: #0f172a; font-weight: bold; }

    .btn-oval { border-radius: 50px !important; padding-left: 20px !important; padding-right: 20px !important; font-weight: 700; text-transform: uppercase; font-size: 11px; }
    .card_detail_header { background: #f8fafc; border-left: 5px solid #0d6efd; padding: 20px; border-radius: 12px; }
    
    .search-group { display: flex; gap: 5px; position: relative; }
</style>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">

    <?php if ($view == 'list'): ?>
        <!-- HEADER & FILTER -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white no-print">
            <div class="card-header bg-white p-4 border-0 d-flex justify-content-between align-items-center text-dark">
                <div>
                    <h5 class="fw-bold mb-0"><i class="fas fa-user-clock me-2 text-primary"></i>Laporan Tagihan & Piutang Mahasiswa</h5>
                </div>
                <a href="index.php?page=laporan_keuangan&tab=transaksi" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase">Kembali</a>
            </div>
            <div class="card-body px-4 pb-4 pt-0">
                <form method="GET" class="row g-3" id="formFilter">
                    <input type="hidden" name="page" value="laporan_piutang_mhs">
                    <div class="col-md-2">
                        <label class="small fw-bold text-muted mb-1 uppercase">Mulai</label>
                        <input type="date" name="f_start" class="form-control rounded-pill border-0 bg-light px-3 shadow-none text-dark" value="<?= $f_start ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold text-muted mb-1 uppercase">Sampai</label>
                        <input type="date" name="f_end" class="form-control rounded-pill border-0 bg-light px-3 shadow-none text-dark" value="<?= $f_end ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-muted mb-1 uppercase">Program Studi</label>
                        <select name="f_prodi" class="form-select rounded-pill border-0 bg-light px-3 shadow-none text-dark">
                            <option value="">Semua Prodi</option>
                            <?php foreach($prodi_list as $p) echo "<option value='{$p['id']}' ".($f_prodi==$p['id']?'selected':'').">{$p['nama_prodi']}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold text-muted mb-1 uppercase">Angkatan</label>
                        <select name="f_angkatan" class="form-select rounded-pill border-0 bg-light px-3 shadow-none text-dark">
                            <option value="">Semua</option>
                            <?php foreach($angkatan_list as $a) echo "<option value='{$a['angkatan']}' ".($f_angkatan==$a['angkatan']?'selected':'').">{$a['angkatan']}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-muted mb-1 uppercase">Cari Mahasiswa</label>
                        <div class="suggest-wrapper">
                            <div class="search-group">
                                <input type="text" name="q" id="searchMhs" class="form-control rounded-pill border-0 bg-light px-4 shadow-none fw-bold text-dark" placeholder="Ketik NIM / Nama..." value="<?= htmlspecialchars($f_search) ?>" autocomplete="off">
                                <button type="button" class="btn btn-light rounded-circle text-danger shadow-sm position-absolute end-0 me-1" style="top:2px;" title="Reset Filter" onclick="resetAllFilters()">
                                    <i class="fas fa-redo-alt"></i>
                                </button>
                            </div>
                            <div id="suggestBox" class="suggest-box"></div>
                        </div>
                    </div>
                    <div class="col-12 text-end mt-4">
                        <button type="submit" class="btn btn-primary rounded-pill px-5 py-2 fw-bold shadow small text-uppercase">TAMPILKAN LAPORAN</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- TABEL AUDIT SINKRON -->
        <div class="card border-0 shadow-lg rounded-4 overflow-hidden bg-white text-dark">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center table-monitoring" id="monTable">
                    <thead>
                        <tr>
                            <th width="50">NO</th>
                            <th width="120">NIM</th>
                            <th class="text-start ps-4">NAMA MAHASISWA</th>
                            <th>PRODI</th>
                            <th class="text-end" width="150">TOTAL TAGIHAN (+)</th>
                            <th class="text-end" width="150">TOTAL DIBAYAR (-)</th>
                            <th class="text-end" width="150">SALDO PIUTANG</th>
                            <th>STATUS</th>
                            <th class="no-print" width="80">AKSI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no=1; $gt = ['tag'=>0, 'bayar'=>0, 'sisa'=>0];
                        $where = "WHERE 1=1";
                        if($f_prodi) $where .= " AND m.prodi_id = '$f_prodi'";
                        if($f_angkatan) $where .= " AND m.angkatan = '$f_angkatan'";
                        if($f_search) $where .= " AND (m.nama LIKE '%$f_search%' OR m.nim LIKE '%$f_search%')";

                        // SQL SOVEREIGN SYNC: Memisahkan Tagihan (Billing) vs Pembayaran (GL)
                        $sql = "SELECT m.id, m.nim, m.nama, p.nama_prodi,
                                (SELECT SUM(nominal) FROM keuangan_tagihan WHERE nim = m.nim AND created_at <= '$f_end 23:59:59') as tot_tagihan,
                                (SELECT SUM(jd.kredit - jd.debit) 
                                 FROM syifa_jurnal_detail jd 
                                 JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                                 WHERE jd.mahasiswa_id = m.id 
                                 AND jd.kode_akun = '$CODE_PIUTANG' 
                                 AND j.tgl_jurnal <= '$f_end'
                                 AND jd.jurnal_id NOT IN (SELECT link_jurnal_id FROM keuangan_tagihan WHERE nim = m.nim AND link_jurnal_id IS NOT NULL)
                                ) as tot_bayar
                                FROM syifa_mahasiswa m 
                                JOIN mhs_prodi p ON m.prodi_id = p.id
                                $where ORDER BY m.nama ASC";
                        
                        $res = $conn->query($sql);
                        if($res && $res->num_rows > 0):
                            while($r = $res->fetch_assoc()):
                                $tagihan = (double)($r['tot_tagihan'] ?? 0);
                                $pembayaran = (double)($r['tot_bayar'] ?? 0);
                                $sisa = $tagihan - $pembayaran;

                                if($tagihan == 0 && $pembayaran == 0) continue; 

                                $gt['tag'] += $tagihan; $gt['bayar'] += $pembayaran; $gt['sisa'] += $sisa;
                                
                                $status_label = "Belum Bayar"; $status_cls = "status-belum";
                                if($sisa <= 100) { $status_label = "Lunas"; $status_cls = "status-lunas"; }
                                elseif($pembayaran > 100) { $status_label = "Sebagian"; $status_cls = "status-sebagian"; }
                        ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td class="text-center"><code><?= $r['nim'] ?></code></td>
                            <td class="text-start ps-4 fw-bold text-dark"><?= strtoupper($r['nama']) ?></td>
                            <td><?= $r['nama_prodi'] ?></td>
                            <td class="text-end">Rp <?= number_format($tagihan) ?></td>
                            <td class="text-end text-success fw-bold"><?= $pembayaran > 0 ? 'Rp '.number_format($pembayaran) : '-' ?></td>
                            <td class="text-end fw-bold text-danger">Rp <?= number_format($sisa) ?></td>
                            <td><span class="badge-status <?= $status_cls ?>"><?= strtoupper($status_label) ?></span></td>
                            <td class="text-center no-print">
                                <a href="?page=laporan_piutang_mhs&view=history&nim=<?= $r['nim'] ?>&f_start=<?= $f_start ?>&f_end=<?= $f_end ?>" class="btn btn-sm btn-white border rounded-circle shadow-sm text-primary" title="Drill Audit Kartu"><i class="fas fa-search-plus"></i></a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="9" class="py-5 text-muted small italic text-center">Tidak ada data ditemukan dalam parameter filter ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="fw-bold" style="background: #f1f5f9; border-top: 2.5px solid #000;">
                        <tr>
                            <td colspan="4" class="ps-4 py-3 text-start">GRAND TOTAL PIUTANG REKONSILIASI</td>
                            <td class="text-end">Rp <?= number_format($gt['tag']) ?></td>
                            <td class="text-end text-success">Rp <?= number_format($gt['bayar']) ?></td>
                            <td class="text-end text-danger">Rp <?= number_format($gt['sisa']) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    <?php elseif ($view == 'history'): ?>
        <!-- VIEW DETAIL: AUDIT KARTU PIUTANG MAHASISWA -->
        <?php 
            $sql_mhs = "SELECT m.*, p.nama_prodi FROM syifa_mahasiswa m JOIN mhs_prodi p ON m.prodi_id = p.id WHERE m.nim = '$nim_target'";
            $m = $conn->query($sql_mhs)->fetch_assoc();
            
            $tagihans = $conn->query("SELECT * FROM keuangan_tagihan WHERE nim='$nim_target' AND created_at <= '$f_end 23:59:59' ORDER BY id ASC");
            
            $bayars = $conn->query("SELECT j.tgl_jurnal, j.no_jurnal, j.akun_utama_kode, (jd.kredit - jd.debit) as netto_bayar, j.id as jid 
                                    FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id
                                    WHERE jd.mahasiswa_id = {$m['id']} AND jd.kode_akun = '$CODE_PIUTANG' 
                                    AND (jd.kredit > 0 OR jd.debit > 0) AND j.tgl_jurnal <= '$f_end'
                                    AND jd.jurnal_id NOT IN (SELECT link_jurnal_id FROM keuangan_tagihan WHERE nim = '$nim_target' AND link_jurnal_id IS NOT NULL)
                                    ORDER BY j.tgl_jurnal ASC");
            
            $sum_tag = 0; $sum_bay = 0;
        ?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print text-dark">
            <a href="index.php?page=laporan_piutang_mhs&f_start=<?= $f_start ?>&f_end=<?= $f_end ?>" class="btn btn-outline-dark rounded-pill px-4 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-2"></i>Kembali ke Daftar</a>
            <h5 class="fw-bold mb-0">Audit Kartu Piutang Mahasiswa</h5>
            <a href="print_kartu_piutang.php?nim=<?= $nim_target ?>&f_end=<?= $f_end ?>" target="_blank" class="btn btn-primary rounded-pill px-5 fw-bold shadow small text-uppercase">
                <i class="fas fa-print me-2"></i>CETAK KARTU (PDF)
            </a>
        </div>

        <div class="card_detail_header mb-4 bg-white border shadow-sm p-4 rounded-4 text-dark border-start border-primary border-4">
            <div class="row text-center text-md-start">
                <div class="col-md-3 border-end"><small class="text-muted fw-bold d-block mb-1 uppercase" style="font-size:10px;">Nomor Induk</small><h5 class="fw-bold mb-0"><?= $m['nim'] ?></h5></div>
                <div class="col-md-5 border-end"><small class="text-muted fw-bold d-block mb-1 uppercase" style="font-size:10px;">Nama Mahasiswa</small><h5 class="fw-bold mb-0"><?= strtoupper($m['nama']) ?></h5></div>
                <div class="col-md-4"><small class="text-muted fw-bold d-block mb-1 uppercase" style="font-size:10px;">Prodi & Angkatan</small><h5 class="fw-bold mb-0"><?= $m['nama_prodi'] ?> (<?= $m['angkatan'] ?>)</h5></div>
            </div>
        </div>

        <div class="row g-4 text-dark">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100 bg-white text-dark">
                    <div class="card-header bg-dark text-white p-3"><h6 class="mb-0 fw-bold small text-uppercase"><i class="fas fa-file-invoice me-2"></i>I. Rincian Kewajiban (Tagihan)</h6></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 text-dark">
                            <thead class="table-light small"><tr><th class="ps-3">TGL</th><th>ITEM TAGIHAN</th><th class="text-end pe-3">NOMINAL</th></tr></thead>
                            <tbody>
                                <?php while($t = $tagihans->fetch_assoc()): $sum_tag += $t['nominal']; ?>
                                <tr><td class="ps-3 small text-muted"><?= date('d/m/y', strtotime($t['created_at'])) ?></td><td class="fw-bold"><?= $t['nama_tagihan'] ?></td><td class="text-end pe-3 fw-bold"><?= number_format($t['nominal']) ?></td></tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot class="fw-bold table-light"><tr><td colspan="2" class="ps-3 py-2">TOTAL TAGIHAN</td><td class="text-end pe-3 text-primary">Rp <?= number_format($sum_tag) ?></td></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100 bg-white text-dark">
                    <div class="card-header bg-success text-white p-3"><h6 class="mb-0 fw-bold small text-uppercase"><i class="fas fa-check-double me-2"></i>II. Realisasi Pembayaran (Kas)</h6></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 text-dark">
                            <thead class="table-light small"><tr><th class="ps-3">TGL</th><th>NO BUKTI</th><th class="text-end pe-3">JUMLAH</th></tr></thead>
                            <tbody>
                                <?php if($bayars->num_rows == 0): echo "<tr><td colspan='3' class='text-center py-4 text-muted small italic'>Belum ada realisasi pembayaran masuk.</td></tr>"; endif; ?>
                                <?php while($b = $bayars->fetch_assoc()): $sum_bay += $b['netto_bayar']; ?>
                                <tr><td class="ps-3 small text-muted"><?= date('d/m/y', strtotime($b['tgl_jurnal'])) ?></td><td><code><?= $b['no_jurnal'] ?></code></td><td class="text-end pe-3 fw-bold text-success"><?= number_format($b['netto_bayar']) ?></td></tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot class="fw-bold table-light"><tr><td colspan="2" class="ps-3 py-2">TOTAL DIBAYAR</td><td class="text-end pe-3 text-success">Rp <?= number_format($sum_bay) ?></td></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12 mb-5">
                <div class="card border-0 bg-dark text-white rounded-4 p-4 shadow-lg border-start border-warning border-5">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="fw-bold mb-1">Saldo Akhir Piutang Mahasiswa</h5>
                            <p class="mb-0 opacity-75 small">Posisi Piutang Hasil Rekonsiliasi Modul Billing dan Buku Besar Kas per <?= date('d/m/Y', strtotime($f_end)) ?></p>
                        </div>
                        <div class="col-md-4 text-end">
                            <h2 class="fw-bold mb-0 text-warning">Rp <?= number_format($sum_tag - $sum_bay) ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
/** ENGINE SUGGESTION v2.6 - FETCH SUGGESTIONS INTEGRATION */
$(document).ready(function() {
    const sInput = $('#searchMhs');
    const sBox = $('#suggestBox');

    sInput.on('input focus', function() {
        const q = $(this).val();
        
        // PENCARIAN AKTIF SEJAK 2 KARAKTER
        if (q.length >= 2) { 
            $.ajax({
                url: 'fetch_suggestions.php', // MENGGUNAKAN FILE DEDICATED (LEBIH STABIL)
                method: 'GET',
                data: { q: q },
                dataType: 'json',
                success: function(data) {
                    sBox.empty();
                    if (data && data.length > 0) {
                        data.forEach(i => {
                            // Mapping data dari fetch_suggestions.php (value=NIM, name=Nama)
                            sBox.append(`<div class="suggest-item" data-nim="${i.value}" data-nama="${i.name}"><code>${i.value}</code> ${i.name}</div>`);
                        });
                        sBox.show(); 
                    } else {
                        sBox.hide();
                    }
                },
                error: function() { sBox.hide(); }
            });
        } else {
            sBox.hide();
        }
    });

    $(document).on('click', '.suggest-item', function() {
        const nim = $(this).data('nim');
        const nama = $(this).data('nama');
        sInput.val(nama); 
        sBox.hide();
        $('#formFilter').submit(); 
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.suggest-wrapper').length) {
            sBox.hide();
        }
    });
});

function resetAllFilters() {
    const url = new URL(window.location.href);
    url.searchParams.set('q', '');
    url.searchParams.set('f_prodi', '');
    url.searchParams.set('f_angkatan', '');
    window.location.href = url.pathname + '?' + url.searchParams.toString();
}
</script>