<?php
/**
 * honorarium_slip.php - TAB 4: SLIP HONOR & PEMBAYARAN
 * Perbaikan: Merubah pengiriman batch email menjadi JSON Payload
 * agar sistem di Backend bisa mengenali dosen dan melampirkan (attach)
 * kuitansi slip PDF/HTML masing-masing secara akurat.
 *
 * OPTIMASI QUERY (fix max_statement_time exceeded):
 * - Ganti LEFT JOIN honor_template + OR IS NULL → subquery IN list generate_id yang valid
 * - Tambah filter bulan/tahun dari GET parameter (default: bulan ini)
 * - Tambah LIMIT untuk paging ringan
 * - Tambah GROUP_CONCAT ORDER BY untuk hasil konsisten
 */

// ── Filter periode (default bulan berjalan) ──────────────────────────
$filter_bulan = (int)($_GET['fbl'] ?? date('n'));
$filter_tahun = (int)($_GET['fth'] ?? date('Y'));
if ($filter_bulan < 1 || $filter_bulan > 12) $filter_bulan = (int)date('n');
if ($filter_tahun < 2000 || $filter_tahun > 2100) $filter_tahun = (int)date('Y');

// ── PENDEKATAN BARU: Tampilkan slip dari generate yang pakai template PENGAJUAN
// (atau generate tanpa template). Template KUITANSI ter-link otomatis dari sana.
// Untuk cetak kuitansi: cari template kuitansi yang linked_pengajuan_template_id = template generate tersebut.

// Guard: pastikan kolom jenis_tujuan ada sebelum dipakai di WHERE
$_slip_cols_tpl = [];
$_rs_show = $conn->query("SHOW COLUMNS FROM honor_template");
if ($_rs_show) { while ($_rc = $_rs_show->fetch_assoc()) $_slip_cols_tpl[] = $_rc['Field']; }
$has_jenis_tujuan     = in_array('jenis_tujuan', $_slip_cols_tpl);
$has_linked_pengajuan = in_array('linked_pengajuan_template_id', $_slip_cols_tpl);

// Tambahkan kolom yang belum ada
if (!$has_jenis_tujuan) {
    $conn->query("ALTER TABLE honor_template ADD COLUMN jenis_tujuan ENUM('KUITANSI','PENGAJUAN') DEFAULT 'PENGAJUAN' AFTER nama_template");
    $has_jenis_tujuan = true;
}
if (!$has_linked_pengajuan) {
    $conn->query("ALTER TABLE honor_template ADD COLUMN linked_pengajuan_template_id INT NULL DEFAULT NULL AFTER custom_layout");
    $has_linked_pengajuan = true;
}

$valid_gen_ids = [];
if ($has_jenis_tujuan) {
    $res_gids = $conn->query(
        "SELECT g.id FROM honor_generate g
         LEFT JOIN honor_template t ON g.template_id = t.id
         WHERE g.status IN ('Final','Dibayarkan')
           AND g.periode_bulan = $filter_bulan
           AND g.periode_tahun = $filter_tahun
           AND (t.jenis_tujuan = 'PENGAJUAN' OR g.template_id IS NULL OR t.id IS NULL)"
    );
} else {
    // Fallback: ambil semua generate Final/Dibayarkan di periode ini
    $res_gids = $conn->query(
        "SELECT g.id FROM honor_generate g
         WHERE g.status IN ('Final','Dibayarkan')
           AND g.periode_bulan = $filter_bulan
           AND g.periode_tahun = $filter_tahun"
    );
}
if ($res_gids) {
    while ($row = $res_gids->fetch_assoc()) $valid_gen_ids[] = (int)$row['id'];
}

// Ambil template kuitansi yang tersedia per template pengajuan (untuk tombol cetak kuitansi)
$kuitansi_tpl_map = []; // [pengajuan_template_id => kuitansi_template_id]
// Guard: cek kolom dulu sebelum query agar tidak crash jika kolom belum ada
$_cols_honor_tpl = [];
$_rcols = $conn->query("SHOW COLUMNS FROM honor_template");
if ($_rcols) { while ($_rc = $_rcols->fetch_assoc()) $_cols_honor_tpl[] = $_rc['Field']; }
if (in_array('linked_pengajuan_template_id', $_cols_honor_tpl)) {
    $res_ktpl = $conn->query("SELECT id, linked_pengajuan_template_id FROM honor_template WHERE jenis_tujuan='KUITANSI' AND linked_pengajuan_template_id IS NOT NULL");
    if ($res_ktpl) {
        while ($rk = $res_ktpl->fetch_assoc()) {
            $kuitansi_tpl_map[(int)$rk['linked_pengajuan_template_id']] = (int)$rk['id'];
        }
    }
}

$slip_list = [];
if (!empty($valid_gen_ids)) {
    $gen_in = implode(',', $valid_gen_ids);
    $sql_slip = "SELECT 
                    d.dosen_id, 
                    g.periode_bulan, 
                    g.periode_tahun,
                    g.template_id AS gen_template_id,
                    ds.nama  AS dosen_nama, 
                    ds.email AS dosen_email,
                    ds.jabatan_fungsional AS jabatan,
                    SUM(d.total_honor)      AS total_bruto,
                    SUM(d.potongan_pajak)   AS total_pajak,
                    SUM(d.honor_diterima)   AS honor_diterima,
                    GROUP_CONCAT(DISTINCT g.nama_generate  ORDER BY g.id SEPARATOR ', ') AS nama_generate,
                    GROUP_CONCAT(DISTINCT g.kode_generate  ORDER BY g.id SEPARATOR ', ') AS kode_generate,
                    GROUP_CONCAT(d.id ORDER BY d.id)                                      AS detail_ids,
                    MIN(d.status_bayar)  AS status_bayar,
                    MIN(d.status_kirim)  AS status_kirim
                FROM honor_generate_detail d
                JOIN honor_generate g  ON d.generate_id = g.id
                JOIN dosen          ds ON d.dosen_id    = ds.id
                WHERE d.generate_id IN ($gen_in)
                GROUP BY d.dosen_id, g.periode_bulan, g.periode_tahun, g.template_id
                ORDER BY ds.nama ASC";
    $res_slip = $conn->query($sql_slip);
    if ($res_slip) while ($r = $res_slip->fetch_assoc()) $slip_list[] = $r;
}

// ── Ambil daftar semua periode yang tersedia (untuk dropdown filter) ─
$periode_list = [];
$res_per = $conn->query(
    "SELECT DISTINCT g.periode_bulan, g.periode_tahun
     FROM honor_generate g
     WHERE g.status IN ('Final','Dibayarkan')
     ORDER BY g.periode_tahun DESC, g.periode_bulan DESC
     LIMIT 24"
);
if ($res_per) while ($p = $res_per->fetch_assoc()) $periode_list[] = $p;

$akun_kas_res = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE (kategori IN ('Kas', 'Bank') OR kode_akun LIKE '1-11%' OR is_cash_account=1) AND is_group=0 AND is_active=1");
$akun_kas = $akun_kas_res ? $akun_kas_res->fetch_all(MYSQLI_ASSOC) : [];
?>

<style>
    .tbl-slip th { background-color: #f8fafc !important; color: #475569 !important; font-size: 11px; text-transform: uppercase; padding: 15px 10px; border-bottom: 2px solid #e2e8f0; text-align: center; }
    .tbl-slip td { font-size: 13px; vertical-align: middle; padding: 12px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; }
    .btn-action-slip { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; transition: 0.2s; font-size: 14px; }
    .btn-action-slip:hover { transform: scale(1.1); }
</style>

<div class="animate__animated animate__fadeIn">

    <!-- FILTER PERIODE -->
    <?php
    $nm_bln_list = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    ?>
    <div class="card border-0 rounded-4 shadow-sm bg-white mb-3 border">
        <div class="card-body p-3 d-flex align-items-center flex-wrap gap-3">
            <i class="fas fa-calendar-alt text-primary me-1"></i>
            <span class="fw-bold text-dark small">Filter Periode:</span>
            <form method="get" class="d-flex align-items-center gap-2 mb-0" id="formFilterPeriode">
                <input type="hidden" name="page" value="honorarium">
                <input type="hidden" name="tab" value="slip">
                <select name="fbl" class="form-select form-select-sm fw-bold border rounded-3 px-3" style="width:auto;" onchange="this.form.submit()">
                    <?php for($m=1; $m<=12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m==$filter_bulan?'selected':'' ?>><?= $nm_bln_list[$m] ?></option>
                    <?php endfor; ?>
                </select>
                <select name="fth" class="form-select form-select-sm fw-bold border rounded-3 px-3" style="width:auto;" onchange="this.form.submit()">
                    <?php
                    $years_shown = [];
                    foreach ($periode_list as $p) $years_shown[$p['periode_tahun']] = true;
                    $years_shown[date('Y')] = true;
                    krsort($years_shown);
                    foreach ($years_shown as $yr => $_):
                    ?>
                    <option value="<?= $yr ?>" <?= $yr==$filter_tahun?'selected':'' ?>><?= $yr ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <span class="badge bg-primary rounded-pill px-3"><?= count($slip_list) ?> slip ditemukan</span>
            <?php if (count($slip_list) === 0 && !empty($periode_list)): ?>
            <small class="text-muted fst-italic">
                Periode tersedia:
                <?php foreach (array_slice($periode_list, 0, 5) as $p): ?>
                <a href="?page=honorarium&tab=slip&fbl=<?= $p['periode_bulan'] ?>&fth=<?= $p['periode_tahun'] ?>" class="badge bg-light text-dark border text-decoration-none me-1">
                    <?= $nm_bln_list[$p['periode_bulan']] . ' ' . $p['periode_tahun'] ?>
                </a>
                <?php endforeach; ?>
            </small>
            <?php endif; ?>
        </div>
    </div>

    <!-- PANEL PEMBAYARAN & EMAIL MASSAL -->
    <div class="card border-0 rounded-4 shadow-sm bg-white mb-4 border-start border-primary border-4">
        <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="fw-bold text-dark mb-1"><i class="fas fa-credit-card me-2 text-primary"></i>Pembayaran Honor Dosen</h5>
                <p class="small text-muted mb-0">Hanya menampilkan dokumen yang ditujukan sebagai <b>KUITANSI</b> pembayaran kepada dosen.</p>
            </div>
            <div class="text-end">
                <div class="small fw-bold text-muted mb-2" id="bulkInfo">0 Slip dipilih | Total: Rp 0</div>
                
                <button class="btn btn-info fw-bold rounded-pill shadow-sm px-4 text-white me-2" id="btnBulkEmail" disabled onclick="modalEmailMassal()">
                    <i class="fas fa-envelope-open-text me-2"></i>Kirim Slip (Email)
                </button>
                <button class="btn btn-warning fw-bold rounded-pill shadow-sm px-4 text-dark" id="btnBulkPay" disabled onclick="modalBayarMassal()">
                    <i class="fas fa-credit-card me-2"></i>Bayarkan Honor
                </button>
            </div>
        </div>
    </div>

    <!-- TABEL SLIP -->
    <div class="card border-0 rounded-4 shadow-sm bg-white border">
        <div class="table-responsive p-3">
            <table class="table table-hover tbl-slip mb-0 text-center">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" class="form-check-input border-secondary shadow-sm" onchange="checkAll(this)" style="width:18px; height:18px;"></th>
                        <th class="text-start">Informasi Dosen</th>
                        <th class="text-start">Deskripsi Dokumen Slip</th>
                        <th>Periode Laporan</th>
                        <th class="text-end">Honor Bersih</th>
                        <th>Status Bayar</th>
                        <th width="170">Aksi Dokumen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($slip_list)): ?><tr><td colspan="7" class="text-center py-5 text-muted fst-italic">
                        <i class="fas fa-inbox fa-2x mb-2 d-block text-muted opacity-50"></i>
                        Belum ada slip honor (Kuitansi) untuk periode <b><?= $nm_bln_list[$filter_bulan] . ' ' . $filter_tahun ?></b>.<br>
                        <small>Silakan ubah filter bulan/tahun di atas, atau finalisasi Generate Honor terlebih dahulu.</small>
                    </td></tr><?php endif; ?>
                    <?php foreach($slip_list as $s): 
                        $byr_cls = $s['status_bayar'] == 'Sudah Dibayar' ? 'bg-success' : 'bg-danger';
                        $nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
                        $periode_str = $nm_bln[$s['periode_bulan']] . ' ' . $s['periode_tahun'];
                        // Cek apakah ada template kuitansi yang ter-link ke template pengajuan ini
                        $gen_tpl_id   = (int)($s['gen_template_id'] ?? 0);
                        $kuitansi_tpl_id = $gen_tpl_id > 0 ? ($kuitansi_tpl_map[$gen_tpl_id] ?? 0) : 0;
                        $has_kuitansi_tpl = $kuitansi_tpl_id > 0;
                    ?>
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="form-check-input chk-slip border-secondary shadow-sm" style="width:18px; height:18px;" 
                                   value="<?= $s['detail_ids'] ?>" 
                                   data-email="<?= $s['dosen_email'] ?>"
                                   data-netto="<?= $s['honor_diterima'] ?>" 
                                   <?= $s['status_bayar']=='Sudah Dibayar'?'disabled':'' ?> 
                                   onchange="updateBulk()">
                        </td>
                        <td class="text-start">
                            <div class="fw-bold text-primary"><?= $s['dosen_nama'] ?></div>
                            <div class="small text-muted"><?= $s['jabatan'] ?></div>
                        </td>
                        <td class="text-start fw-bold text-dark">
                            Slip Honor Pembayaran
                            <?php if ($has_kuitansi_tpl): ?>
                            <span class="badge bg-success text-white ms-1 rounded-pill" style="font-size:10px;">
                                <i class="fas fa-sync-alt me-1"></i>Kuitansi Sinkron
                            </span>
                            <?php else: ?>
                            <span class="badge bg-secondary text-white ms-1 rounded-pill" style="font-size:10px;">
                                <i class="fas fa-file-alt me-1"></i>Default
                            </span>
                            <?php endif; ?>
                            <br><code class="text-muted bg-light px-1 border"><?= $periode_str ?></code>
                            <br><span class="small text-muted"><?= htmlspecialchars($s['nama_generate']) ?></span>
                        </td>
                        <td class="text-center fw-bold text-muted"><?= $periode_str ?></td>
                        <td class="text-end fw-bold text-success fs-6">Rp <?= number_format($s['honor_diterima'],0,',','.') ?></td>
                        <td class="text-center"><span class="badge <?= $byr_cls ?> rounded-pill px-3 py-1 shadow-sm"><?= $s['status_bayar'] ?></span></td>
                        <td class="text-center">
                            <div class="d-flex justify-content-center gap-1 flex-wrap">
                                <?php if ($has_kuitansi_tpl): ?>
                                <!-- Cetak kuitansi dengan template sinkron (pakai layout kuitansi ter-link) -->
                                <button class="btn-action-slip bg-primary text-white border-0 shadow-sm"
                                        title="Cetak Kuitansi (Template Sinkron)"
                                        onclick="window.open('print_slip_honor.php?mode=slip&detail_ids=<?= $s['detail_ids'] ?>&kuitansi_tpl_id=<?= $kuitansi_tpl_id ?>', '_blank')">
                                    <i class="fas fa-print"></i>
                                </button>
                                <?php else: ?>
                                <!-- Cetak kuitansi default (tanpa template kustom) -->
                                <button class="btn-action-slip bg-light border text-primary shadow-sm"
                                        title="Cetak Kuitansi Slip (Default)"
                                        onclick="window.open('print_slip_honor.php?mode=slip&detail_ids=<?= $s['detail_ids'] ?>', '_blank')">
                                    <i class="fas fa-print"></i>
                                </button>
                                <?php endif; ?>
                                
                                <button class="btn-action-slip bg-light border <?= $s['status_kirim']=='Sudah Dikirim'?'text-success':'text-secondary' ?> shadow-sm"
                                        title="Kirim Slip ke Email"
                                        onclick="modalEmailMassal(1, '<?= $s['detail_ids'] ?>', '<?= $s['dosen_email'] ?>')">
                                    <i class="fas fa-envelope"></i>
                                </button>

                                <?php if($s['status_bayar'] == 'Belum Dibayar'): ?>
                                <button class="btn-action-slip bg-light border text-success shadow-sm"
                                        title="Bayar & Jurnalkan"
                                        onclick="modalBayarMassal(1, <?= $s['honor_diterima'] ?>, '<?= $s['detail_ids'] ?>')">
                                    <i class="fas fa-money-check-alt"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn-action-slip bg-danger text-white border-0 shadow-sm"
                                        title="Batal Pembayaran (Tarik Jurnal)"
                                        onclick="batalBayarKasir('<?= $s['detail_ids'] ?>')">
                                    <i class="fas fa-undo"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 🚀 MODAL KIRIM EMAIL DENGAN LAMPIRAN -->
<div class="modal fade" id="modalEmailBatch" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="javascript:void(0);" id="formEmailBatch" onsubmit="handleEmail(event)" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="kirim_email">
            <div id="hiddenEmailIds"></div>
            <div class="modal-header bg-info text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-envelope-open-text me-2"></i>Kirim Slip ke Email Dosen</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-info border-info border text-dark fw-bold text-center">
                    Akan mengirim pesan <span class="badge bg-danger rounded-pill"><i class="fas fa-paperclip"></i> + Lampiran Kuitansi</span> ke <span id="emailCount" class="fs-5 text-primary mx-1">0</span> dosen.
                </div>
                
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1">Subjek Email</label>
                    <input type="text" name="subject" class="form-control rounded-3 border shadow-sm px-3 py-2 fw-bold" value="Pemberitahuan Pencairan Honorarium - STIKes Yarsi" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1">Pesan Kustom (Body Email)</label>
                    <textarea name="pesan" class="form-control rounded-3 border shadow-sm px-3 py-2" rows="4" required>Yth. Dosen,

Bersama email ini kami informasikan bahwa honorarium Anda telah diproses dan dicairkan ke rekening yang terdaftar. Terlampir adalah Slip Kuitansi Rincian Honorarium Anda.

Terima kasih atas dedikasi dan kerja keras Anda.

Salam,
Bagian Keuangan STIKes Yarsi Pontianak</textarea>
                </div>
            </div>
            <div class="modal-footer p-3 bg-white border-0 d-flex flex-nowrap">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm w-50 border" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-info rounded-pill px-4 fw-bold shadow text-white w-50" id="btnSubmitEmail"><i class="fas fa-paper-plane me-2"></i>Kirim Sekarang</button>
            </div>
        </form>
    </div>
</div>

<!-- 🚀 MODAL PEMBAYARAN JURNAL AKUNTANSI -->
<div class="modal fade" id="modalBayar" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="javascript:void(0);" id="formBayar" onsubmit="handleBayar(event)" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="bayar_slip">
            <div id="hiddenSlipIds"></div>
            <div class="modal-header bg-warning text-dark p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-credit-card me-2"></i>Konfirmasi Pembayaran Jurnal</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-warning border-warning border text-dark fw-bold text-center">
                    Anda akan membayarkan <span id="payCount" class="fs-5 text-danger mx-1">0</span> Slip Honor dengan total pengeluaran kas <span id="payTotal" class="fs-5 text-success d-block mt-1">Rp 0</span>
                </div>
                
                <div class="row g-3">
                    <div class="col-12">
                        <label class="small fw-bold text-muted mb-1">Sumber Dana (Akun Kas/Bank) <span class="text-danger">*</span></label>
                        <select name="kas_akun" class="form-select fw-bold border shadow-sm rounded-3 px-3 py-2" required>
                            <option value="">-- Pilih Kas Pembayar --</option>
                            <?php foreach($akun_kas as $k) echo "<option value='{$k['kode_akun']}'>{$k['kode_akun']} - {$k['nama_akun']}</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1">Tanggal Bayar</label>
                        <input type="date" name="tgl_bayar" class="form-control rounded-3 border shadow-sm fw-bold px-3 py-2" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1">Nomor Referensi</label>
                        <input type="text" name="referensi" class="form-control rounded-3 border shadow-sm px-3 py-2" placeholder="Auto Generate">
                    </div>
                </div>
            </div>
            <div class="modal-footer p-3 bg-white border-0 d-flex flex-nowrap">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm w-50 border" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow text-white w-50" id="btnSubmitBayar"><i class="fas fa-check-double me-2"></i>Bayarkan Ke Kas</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        document.body.appendChild(document.getElementById('modalBayar'));
        document.body.appendChild(document.getElementById('modalEmailBatch'));
    });

    function checkAll(ele) { 
        document.querySelectorAll('.chk-slip:not(:disabled)').forEach(cb => cb.checked = ele.checked); 
        updateBulk(); 
    }
    
    function updateBulk() {
        let count = document.querySelectorAll('.chk-slip:checked').length;
        let total = 0;
        document.querySelectorAll('.chk-slip:checked').forEach(cb => { total += parseFloat(cb.getAttribute('data-netto')); });
        
        document.getElementById('bulkInfo').innerText = `${count} Slip dipilih | Total: Rp ${new Intl.NumberFormat('id-ID').format(total)}`;
        const btnPay = document.getElementById('btnBulkPay');
        const btnMail = document.getElementById('btnBulkEmail');
        
        if(count > 0) {
            btnPay.disabled = false; btnPay.innerHTML = `<i class="fas fa-credit-card me-2"></i>Bayarkan (${count}) Slip`;
            btnMail.disabled = false; btnMail.innerHTML = `<i class="fas fa-envelope-open-text me-2"></i>Kirim Slip (${count}) Dosen`;
        } else {
            btnPay.disabled = true; btnPay.innerHTML = `<i class="fas fa-credit-card me-2"></i>Bayarkan Honor Terpilih`;
            btnMail.disabled = true; btnMail.innerHTML = `<i class="fas fa-envelope-open-text me-2"></i>Kirim Slip (Email)`;
        }
    }

    function modalBayarMassal(isSingle = 0, nominal = 0, idsStr = '') {
        let hiddenHTML = '';
        if(isSingle === 1) {
            document.getElementById('payCount').innerText = '1'; 
            document.getElementById('payTotal').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(nominal);
            let idsArr = idsStr.split(','); idsArr.forEach(id => { hiddenHTML += `<input type='hidden' name='slip_ids[]' value='${id}'>`; });
        } else {
            let count = document.querySelectorAll('.chk-slip:checked').length; 
            let total = 0;
            document.querySelectorAll('.chk-slip:checked').forEach(cb => { 
                total += parseFloat(cb.getAttribute('data-netto')); 
                let idsArr = cb.value.split(','); idsArr.forEach(id => { hiddenHTML += `<input type='hidden' name='slip_ids[]' value='${id}'>`; });
            });
            document.getElementById('payCount').innerText = count; 
            document.getElementById('payTotal').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(total);
        }
        document.getElementById('hiddenSlipIds').innerHTML = hiddenHTML;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalBayar')).show();
    }

    function modalEmailMassal(isSingle = 0, idsStr = '', email = '') {
        let hiddenHTML = '';
        let mailData = [];

        if(isSingle === 1) {
            if(!email) { Swal.fire('Gagal', 'Dosen ini belum memiliki alamat email di database.', 'error'); return; }
            document.getElementById('emailCount').innerText = '1';
            mailData.push({ email: email, ids: idsStr });
        } else {
            let count = 0;
            document.querySelectorAll('.chk-slip:checked').forEach(cb => { 
                let mail = cb.getAttribute('data-email');
                if(mail) {
                    count++;
                    mailData.push({ email: mail, ids: cb.value });
                }
            });
            if(count === 0) { Swal.fire('Peringatan', 'Tidak ada satupun dosen yang dipilih memiliki alamat email.', 'warning'); return; }
            document.getElementById('emailCount').innerText = count;
        }
        
        // Parsing menjadi JSON Object agar PHP dapat mengenali mapping 1 Email -> Banyak ID Rincian
        hiddenHTML += `<input type='hidden' name='mail_data_json' value='${JSON.stringify(mailData)}'>`;
        document.getElementById('hiddenEmailIds').innerHTML = hiddenHTML;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEmailBatch')).show();
    }

    function handleBayar(e) {
        e.preventDefault();
        const form = e.target;
        let btn = document.getElementById('btnSubmitBayar'); let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...'; btn.disabled = true;
        
        fetch('honorarium_action.php', { method: 'POST', body: new FormData(form) })
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } 
            catch(err) { throw new Error("JSON Rusak/Server Error."); }
        })
        .then(res => {
            if(res.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalBayar')).hide();
                Swal.fire({ icon: 'success', title: 'Berhasil Dibayar!', text: res.message, timer: 3000, showConfirmButton: false }).then(() => { window.location.href = '?page=honorarium&tab=slip'; });
            } else { Swal.fire('Gagal', res.message, 'error'); btn.innerHTML = ori; btn.disabled = false; }
        }).catch(err => { Swal.fire('Error', 'Putus koneksi ke server', 'error'); btn.innerHTML = ori; btn.disabled = false; });
    }

    function handleEmail(e) {
        e.preventDefault();
        const form = e.target;
        let btn = document.getElementById('btnSubmitEmail'); let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Mengirim...'; btn.disabled = true;
        
        fetch('honorarium_action.php', { method: 'POST', body: new FormData(form) })
        .then(async r => {
            const text = await r.text();
            try { return JSON.parse(text); } 
            catch(err) { throw new Error(text.substring(0, 80) + '...'); }
        })
        .then(res => {
            if(res.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalEmailBatch')).hide();
                Swal.fire({ icon: 'success', title: 'Terkirim!', text: res.message, timer: 3000, showConfirmButton: false }).then(() => { window.location.reload(); });
            } else { Swal.fire('Gagal', res.message, 'error'); btn.innerHTML = ori; btn.disabled = false; }
        }).catch(err => { Swal.fire('Kesalahan SMTP', err.message, 'error'); btn.innerHTML = ori; btn.disabled = false; });
    }

    function batalBayarKasir(idsStr) {
        Swal.fire({
            title: 'Batalkan Pembayaran?', text: `Jurnal Kas Keluar akan dihapus secara otomatis dan status slip kembali Belum Dibayar. Lanjutkan?`, icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Ya, Batalkan Jurnal!'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData(); fd.append('action', 'batal_bayar'); fd.append('slip_ids', idsStr);
                fetch('honorarium_action.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => {
                    if(res.status == 'success') Swal.fire({ icon: 'success', title: 'Berhasil Dibatalkan!', text: res.message, timer: 2000, showConfirmButton: false }).then(() => window.location.reload());
                    else Swal.fire('Gagal', res.message, 'error');
                });
            }
        });
    }
</script>