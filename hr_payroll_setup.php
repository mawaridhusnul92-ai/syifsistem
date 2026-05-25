<?php
/**
 * hr_payroll_setup.php - PUSAT PENGATURAN GAJI & MASTER SDM
 * Versi: 43.0 (Grand Master HRIS - Executive Layout Edition)
 * Perbaikan: Merapikan tata letak (layout) tab menu agar tidak berada di dalam card,
 * melainkan berada tepat di bawah Box Header utama agar seragam dengan modul ERP lainnya.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

// Proteksi Halaman
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if(function_exists('guardPage')) { guardPage('hr_payroll_setup'); }

$active_tab = $_GET['tab'] ?? 'setup';
$peg_id = (int)($_GET['peg_id'] ?? 0);

// --- 1. DATA AGGREGATION ---
$pegawai_list = $conn->query("SELECT id, nip, nama_lengkap, jabatan FROM hr_pegawai WHERE status_aktif=1 ORDER BY nama_lengkap ASC")->fetch_all(MYSQLI_ASSOC);
$komp_income = $conn->query("SELECT * FROM hr_komponen WHERE jenis='Pendapatan' ORDER BY nama_komponen ASC")->fetch_all(MYSQLI_ASSOC);
$komp_deduct = $conn->query("SELECT * FROM hr_komponen WHERE jenis='Potongan' ORDER BY nama_komponen ASC")->fetch_all(MYSQLI_ASSOC);
$jabatan_list = $conn->query("SELECT * FROM hr_jabatan ORDER BY level_jabatan ASC, nama_jabatan ASC")->fetch_all(MYSQLI_ASSOC);

// --- 2. LOGIKA EDIT SETUP (PAGE-BASED) ---
$existing_setup = [];
$peg_data = null;
if ($peg_id > 0) {
    $peg_data = $conn->query("SELECT * FROM hr_pegawai WHERE id = $peg_id")->fetch_assoc();
    $q_setup = $conn->query("SELECT sc.*, k.jenis FROM hr_pegawai_komponen sc JOIN hr_komponen k ON sc.komponen_id = k.id WHERE sc.pegawai_id = $peg_id");
    while($row = $q_setup->fetch_assoc()) {
        $existing_setup[$row['jenis']][] = $row;
    }
}
?>

<div class="container-fluid py-4 animate__animated animate__fadeIn text-dark">
    
    <!-- ?? HEADER BOX (STANDARD ERP) -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4">
        <div>
            <h4 class="fw-bold text-primary mb-0"><i class="fas fa-users-cog me-2"></i>Manajemen Pengaturan Gaji</h4>
            <small class="text-muted fw-bold">Konfigurasi Komponen Gaji & Master SDM v43.0</small>
        </div>
        <div class="d-flex gap-2 no-print">
            <?php if($active_tab == 'setup' && $peg_id == 0): ?>
                <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="showAddModalSetup()"><i class="fas fa-plus-circle me-2"></i>BARU</button>
            <?php elseif($active_tab == 'komponen'): ?>
                <button class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" onclick="showModalKomponen()"><i class="fas fa-plus-circle me-2"></i>TAMBAH KOMPONEN</button>
            <?php elseif($active_tab == 'jabatan'): ?>
                <button class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm" onclick="showModalJabatan()"><i class="fas fa-id-badge me-2"></i>TAMBAH JABATAN</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ?? NAVIGATION TAB MENU BERSIH (DI BAWAH HEADER) -->
    <?php if($peg_id == 0): ?>
    <ul class="nav nav-tabs mb-4 border-0" id="payrollTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'setup' ? 'active' : '' ?>" href="?page=hr_payroll_setup&tab=setup"><i class="fas fa-sliders-h me-2"></i>Konfigurasi Gaji Pegawai</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'komponen' ? 'active' : '' ?>" href="?page=hr_payroll_setup&tab=komponen"><i class="fas fa-layer-group me-2"></i>Master Komponen</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab == 'jabatan' ? 'active' : '' ?>" href="?page=hr_payroll_setup&tab=jabatan"><i class="fas fa-id-badge me-2"></i>Data Jabatan</a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2 fa-lg"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="tab-content">
        <?php if($active_tab == 'setup'): ?>
            <?php if($peg_id == 0): ?>
                <!-- A. LIST VIEW: DAFTAR SETUP GAJI -->
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white p-4">
                    <div class="table-responsive rounded-4 border">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase text-muted fw-bold">
                                <tr>
                                    <th class="ps-4 py-3 text-start">Nama Pegawai</th>
                                    <th>Jabatan</th>
                                    <th class="text-end">Total Bruto</th>
                                    <th class="text-end">Total Potongan</th>
                                    <th class="text-end pe-4">Take Home Pay (THP)</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sql_list = "SELECT p.id, p.nip, p.nama_lengkap, p.jabatan,
                                            (SELECT SUM(nominal) FROM hr_pegawai_komponen pk JOIN hr_komponen k ON pk.komponen_id=k.id WHERE pk.pegawai_id=p.id AND k.jenis='Pendapatan') as tot_inc,
                                            (SELECT SUM(nominal) FROM hr_pegawai_komponen pk JOIN hr_komponen k ON pk.komponen_id=k.id WHERE pk.pegawai_id=p.id AND k.jenis='Potongan') as tot_ded
                                            FROM hr_pegawai p WHERE p.status_aktif=1 HAVING (tot_inc > 0 OR tot_ded > 0) ORDER BY p.nama_lengkap ASC";
                                $res_list = $conn->query($sql_list);
                                if($res_list && $res_list->num_rows > 0):
                                    while($row = $res_list->fetch_assoc()):
                                        $net = $row['tot_inc'] - $row['tot_ded'];
                                ?>
                                <tr>
                                    <td class="ps-4 text-start">
                                        <div class="fw-bold"><?= $row['nama_lengkap'] ?></div>
                                        <small class="text-muted"><?= $row['nip'] ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= $row['jabatan'] ?></span></td>
                                    <td class="text-end text-success fw-bold"><?= number_format($row['tot_inc']) ?></td>
                                    <td class="text-end text-danger fw-bold"><?= number_format($row['tot_ded']) ?></td>
                                    <td class="text-end pe-4 fw-bold text-primary">Rp <?= number_format($net) ?></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden">
                                            <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                                            <a href="?page=hr_payroll_setup&peg_id=<?= $row['id'] ?>" class="btn btn-white text-warning border-end" title="Ubah Konfigurasi"><i class="fas fa-edit"></i></a>
                                            <?php endif; ?>
                                            <?php if(defined('RBAC_DEL') && RBAC_DEL): ?>
                                            <button class="btn btn-white text-danger" onclick="deleteSetup(<?= $row['id'] ?>, '<?= $row['nama_lengkap'] ?>')" title="Kosongkan Setup"><i class="fas fa-trash-alt"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted italic">Belum ada pegawai yang dikonfigurasi gajinya. Klik tombol <b>+ BARU</b> di pojok kanan atas.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <!-- B. FORM VIEW: FULL PAGE DUAL COLUMN SETUP -->
                <div class="animate__animated animate__fadeIn">
                    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0">Konfigurasi Gaji: <span class="text-primary"><?= strtoupper($peg_data['nama_lengkap']) ?></span></h5>
                            <a href="?page=hr_payroll_setup&tab=setup" class="btn btn-light btn-sm rounded-pill px-3 border"><i class="fas fa-times me-1"></i> Kembali ke Daftar</a>
                        </div>

                        <!-- ??? SMART VALIDATOR DITAMBAHKAN DI ONSUBMIT -->
                        <form action="hr_action.php" method="POST" id="formSetupGaji" onsubmit="return validateSetupGaji(event)">
                            <input type="hidden" name="action" value="save_pegawai_setup">
                            <input type="hidden" name="pegawai_id" value="<?= $peg_id ?>">

                            <div class="row g-4">
                                <!-- KOLOM KIRI: PENDAPATAN (INCOME) -->
                                <div class="col-md-6">
                                    <div class="p-3 rounded-4 border border-success border-opacity-25 h-100" style="background: #f8fff9;">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="fw-bold text-success mb-0 uppercase"><i class="fas fa-plus-circle me-2"></i>Komponen Pendapatan</h6>
                                            <button type="button" class="btn btn-sm btn-success rounded-circle" onclick="addRow('income')"><i class="fas fa-plus"></i></button>
                                        </div>
                                        <div id="container-income">
                                            <?php if(isset($existing_setup['Pendapatan'])): foreach($existing_setup['Pendapatan'] as $row): ?>
                                                <div class="row g-2 mb-2 item-row">
                                                    <div class="col-7"><select name="inc_id[]" class="form-select border-0 shadow-sm rounded-3 fw-bold text-dark val-select" required><?php foreach($komp_income as $k) echo "<option value='{$k['id']}' ".($k['id']==$row['komponen_id']?'selected':'').">{$k['nama_komponen']}</option>"; ?></select></div>
                                                    <div class="col-4"><input type="text" name="inc_nominal[]" class="form-control border-0 shadow-sm rounded-3 text-end fw-bold text-success val-nominal" value="<?= number_format($row['nominal'], 0, ',', '.') ?>" onkeyup="fmt(this)" required></div>
                                                    <div class="col-1 text-center"><button type="button" class="btn btn-link text-danger p-1" onclick="this.closest('.item-row').remove(); calcTotal();"><i class="fas fa-times-circle"></i></button></div>
                                                </div>
                                            <?php endforeach; else: ?>
                                                <p class="text-muted small text-center py-3 empty-msg border rounded-3 border-dashed bg-white">Klik tombol (+) untuk tambah pendapatan.</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-3 text-end fw-bold text-success fs-5" id="total_income_disp">Total: Rp 0</div>
                                    </div>
                                </div>

                                <!-- KOLOM KANAN: POTONGAN (DEDUCTION) -->
                                <div class="col-md-6">
                                    <div class="p-3 rounded-4 border border-danger border-opacity-25 h-100" style="background: #fff9f9;">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h6 class="fw-bold text-danger mb-0 uppercase"><i class="fas fa-minus-circle me-2"></i>Komponen Potongan</h6>
                                            <button type="button" class="btn btn-sm btn-danger rounded-circle" onclick="addRow('deduct')"><i class="fas fa-plus"></i></button>
                                        </div>
                                        <div id="container-deduct">
                                            <?php if(isset($existing_setup['Potongan'])): foreach($existing_setup['Potongan'] as $row): ?>
                                                <div class="row g-2 mb-2 item-row">
                                                    <div class="col-7"><select name="ded_id[]" class="form-select border-0 shadow-sm rounded-3 fw-bold text-danger val-select" required><?php foreach($komp_deduct as $k) echo "<option value='{$k['id']}' ".($k['id']==$row['komponen_id']?'selected':'').">{$k['nama_komponen']}</option>"; ?></select></div>
                                                    <div class="col-4"><input type="text" name="ded_nominal[]" class="form-control border-0 shadow-sm rounded-3 text-end fw-bold text-danger val-nominal" value="<?= number_format($row['nominal'], 0, ',', '.') ?>" onkeyup="fmt(this)" required></div>
                                                    <div class="col-1 text-center"><button type="button" class="btn btn-link text-danger p-1" onclick="this.closest('.item-row').remove(); calcTotal();"><i class="fas fa-times-circle"></i></button></div>
                                                </div>
                                            <?php endforeach; else: ?>
                                                <p class="text-muted small text-center py-3 empty-msg border rounded-3 border-dashed bg-white">Klik tombol (+) untuk tambah potongan.</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-3 text-end fw-bold text-danger fs-5" id="total_deduct_disp">Total: Rp 0</div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 p-4 bg-light rounded-4 d-flex justify-content-between align-items-center border shadow-sm">
                                <div class="p-3 bg-white rounded-pill shadow-sm border px-4">
                                    <span class="text-muted small fw-bold uppercase me-2">Estimasi THP:</span>
                                    <span class="fw-bold fs-4 text-primary" id="thp_disp">Rp 0</span>
                                </div>
                                <button type="submit" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow text-uppercase">SIMPAN KONFIGURASI GAJI</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif($active_tab == 'komponen'): ?>
            <!-- C. MASTER KOMPONEN VIEW (DIBUNGKUS CARD) -->
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <?php include 'hr_komponen.php'; ?>
            </div>

        <?php elseif($active_tab == 'jabatan'): ?>
            <!-- D. MASTER JABATAN VIEW (DIBUNGKUS CARD) -->
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <?php include 'hr_jabatan.php'; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL ADD BARU UNTUK SETUP (REDIRECT STYLE) -->
<div class="modal fade" id="modalAddSetup" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white p-4">
                <h6 class="modal-title fw-bold">Pilih Pegawai untuk Setup Baru</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <select id="sel_peg_new" class="form-select form-select-lg rounded-pill fw-bold border-0 bg-white shadow-sm">
                    <option value="">-- Pilih Pegawai --</option>
                    <?php foreach($pegawai_list as $p) echo "<option value='{$p['id']}'>{$p['nama_lengkap']} - {$p['jabatan']}</option>"; ?>
                </select>
            </div>
            <div class="modal-footer p-3 bg-white border-0">
                <button type="button" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow" onclick="goToSetup()">MULAI KONFIGURASI</button>
            </div>
        </div>
    </div>
</div>

<?php include 'hr_payroll_modals_shared.php'; ?>

<script>
const m_inc = <?= json_encode($komp_income) ?>;
const m_ded = <?= json_encode($komp_deduct) ?>;

function fmt(el) { 
    el.style.border = '';
    el.value = new Intl.NumberFormat('id-ID').format(el.value.replace(/\D/g, "")); 
    calcTotal(); 
}

function showAddModalSetup() { new bootstrap.Modal(document.getElementById('modalAddSetup')).show(); }
function goToSetup() { 
    const pid = document.getElementById('sel_peg_new').value;
    if(pid) window.location.href = `?page=hr_payroll_setup&peg_id=${pid}`;
}

function addRow(type) {
    const container = document.getElementById(type === 'income' ? 'container-income' : 'container-deduct');
    const list = type === 'income' ? m_inc : m_ded;
    const name = type === 'income' ? 'inc' : 'ded';
    const empty = container.querySelector('.empty-msg'); if(empty) empty.remove();
    const txtColor = type === 'income' ? 'text-dark' : 'text-danger';

    let opts = '<option value="">-- Pilih Komponen --</option>';
    list.forEach(k => { opts += `<option value="${k.id}">${k.nama_komponen}</option>`; });

    const div = document.createElement('div');
    div.className = 'row g-2 mb-2 item-row animate__animated animate__fadeInDown';
    div.innerHTML = `
        <div class="col-7"><select name="${name}_id[]" class="form-select border-0 shadow-sm rounded-3 small fw-bold ${txtColor} val-select" required onchange="this.style.border=''">${opts}</select></div>
        <div class="col-4"><input type="text" name="${name}_nominal[]" class="form-control border-0 shadow-sm rounded-3 text-end fw-bold val-nominal" value="" placeholder="Rp 0" onkeyup="fmt(this)" required></div>
        <div class="col-1 text-center"><button type="button" class="btn btn-link text-danger p-1" onclick="this.closest('.item-row').remove(); calcTotal();"><i class="fas fa-times-circle"></i></button></div>
    `;
    container.appendChild(div);
}

function calcTotal() {
    let tI = 0, tD = 0;
    document.getElementsByName('inc_nominal[]').forEach(el => tI += parseFloat(el.value.replace(/\./g, '')) || 0);
    document.getElementsByName('ded_nominal[]').forEach(el => tD += parseFloat(el.value.replace(/\./g, '')) || 0);
    document.getElementById('total_income_disp').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(tI);
    document.getElementById('total_deduct_disp').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(tD);
    document.getElementById('thp_disp').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(tI - tD);
}

function deleteSetup(id, nama) {
    if(confirm(`Kosongkan konfigurasi gaji [${nama}]?`)) window.location.href = `hr_action.php?action=delete_pegawai_setup&id=${id}`;
}

function validateSetupGaji(e) {
    let hasError = false;
    document.querySelectorAll('.val-select').forEach(sel => {
        if (sel.value === '') { hasError = true; sel.style.border = '2px solid #ef4444'; } else { sel.style.border = ''; }
    });
    document.querySelectorAll('.val-nominal').forEach(inp => {
        let val = parseFloat(inp.value.replace(/\./g, '')) || 0;
        if (val <= 0) { hasError = true; inp.style.border = '2px solid #ef4444'; } else { inp.style.border = ''; }
    });
    if (hasError) {
        alert("?? PERHATIAN!\n\nSistem mendeteksi ada Komponen Gaji yang belum dipilih, atau Nominal masih bernilai Rp 0.\n\nSilakan isi dengan benar, atau HAPUS baris tersebut (klik tanda X merah) jika tidak digunakan agar data valid.");
        if (e) e.preventDefault();
        return false;
    }
    return true;
}

window.onload = calcTotal;
</script>

<style>
    .btn-white { background: #fff; border: none; transition: 0.2s;} 
    .btn-white:hover { background: #f8f9fa; color: var(--bs-primary) !important; }
    .border-dashed { border-style: dashed !important; border-width: 2px !important; }
</style>