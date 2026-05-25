<?php
// AMBIL DATA ANGGARAN
$budgets = $conn->query("
    SELECT b.*, c.nama_akun as nama_coa 
    FROM syifa_anggaran_akun b 
    LEFT JOIN syifa_akun c ON b.kode_akun_coa = c.kode_akun 
    ORDER BY b.is_group DESC, b.nama_anggaran ASC
");

$pendapatan_list = [];
$belanja_list = [];
$grup_pendapatan = []; // Untuk dropdown
$grup_belanja = [];    // Untuk dropdown

while($row = $budgets->fetch_assoc()) {
    if($row['jenis'] == 'PENDAPATAN') {
        $pendapatan_list[] = $row;
        if($row['is_group']) $grup_pendapatan[] = $row;
    } else {
        $belanja_list[] = $row;
        if($row['is_group']) $grup_belanja[] = $row;
    }
}

// AMBIL DATA COA UNTUK DROPDOWN
$coa_income = $conn->query("SELECT * FROM syifa_akun WHERE kategori='Pendapatan' AND is_group=0 ORDER BY kode_akun");
$coa_expense = $conn->query("SELECT * FROM syifa_akun WHERE kategori='Beban' AND is_group=0 ORDER BY kode_akun");
?>

<style>
    .scroll-box { height: 65vh; overflow-y: auto; border: 1px solid #eee; border-radius: 8px; background: white; }
    .row-group { background: #f8f9fa; font-weight: bold; color: #2c3e50; }
    .row-item { border-bottom: 1px solid #f0f0f0; }
    .indent-item { padding-left: 25px; }
    .badge-coa { font-size: 0.75rem; background: #eef2f7; color: #333; border: 1px solid #ddd; padding: 2px 6px; border-radius: 4px; }
    .edu-tag { font-size: 0.65rem; background: #e3f2fd; color: #0d47a1; padding: 2px 5px; border-radius: 4px; font-weight: bold; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="text-muted mb-0"><i class="fas fa-sitemap me-2"></i>Struktur Akun Anggaran & Realisasi</h6>
    <button class="btn btn-primary btn-sm shadow-sm" onclick="modalAddBudget()"><i class="fas fa-plus-circle me-2"></i>Tambah Akun Anggaran</button>
</div>

<div class="row g-4">
    
    <!-- KOLOM KIRI: ANGGARAN PENDAPATAN -->
    <div class="col-md-6">
        <div class="card h-100 shadow-sm border-top border-3 border-success">
            <div class="card-header bg-white fw-bold text-success py-3 d-flex justify-content-between">
                <span><i class="fas fa-hand-holding-usd me-2"></i> PENDAPATAN</span>
                <small class="text-muted fw-normal">Target Revenue</small>
            </div>
            <div class="card-body p-0 scroll-box">
                <table class="table table-hover mb-0 small">
                    <?php if(empty($pendapatan_list)): ?>
                        <tr><td class="text-center py-5 text-muted fst-italic">Belum ada akun anggaran pendapatan.</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($pendapatan_list as $row): 
                        $is_grp = $row['is_group'];
                        if(!$is_grp && $row['parent_id'] != 0) continue; // Skip child (rendered later)
                    ?>
                        <!-- Grup / Root Item -->
                        <tr class="<?= $is_grp ? 'row-group' : 'row-item' ?>">
                            <td class="ps-3">
                                <?= $is_grp ? '<i class="fas fa-folder me-2 text-warning"></i>' : '' ?>
                                <?= $row['nama_anggaran'] ?>
                            </td>
                            <td class="text-end pe-3">
                                <button class="btn btn-xs text-danger" onclick="hapusBudget(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>

                        <!-- Child Items -->
                        <?php if($is_grp): 
                            foreach($pendapatan_list as $child): 
                                if($child['parent_id'] == $row['id']): ?>
                                <tr class="row-item">
                                    <td class="indent-item text-muted">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-angle-right me-2 opacity-50"></i> 
                                            <div>
                                                <?= $child['nama_anggaran'] ?>
                                                <?php if($child['is_pendidikan']): ?>
                                                    <div class="mt-1"><span class="edu-tag"><i class="fas fa-graduation-cap me-1"></i><?= $child['program_pendidikan'] ?> (<?= $child['semester_target'] ?>)</span></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end pe-3">
                                        <?php if($child['kode_akun_coa']): ?>
                                            <span class="badge-coa" title="<?= $child['nama_coa'] ?>">COA: <?= $child['kode_akun_coa'] ?></span>
                                        <?php endif; ?>
                                        <button class="btn btn-xs text-danger ms-2" onclick="hapusBudget(<?= $child['id'] ?>)"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                        <?php endif; endforeach; endif; ?>

                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- KOLOM KANAN: ANGGARAN BELANJA -->
    <div class="col-md-6">
        <div class="card h-100 shadow-sm border-top border-3 border-danger">
            <div class="card-header bg-white fw-bold text-danger py-3 d-flex justify-content-between">
                <span><i class="fas fa-shopping-cart me-2"></i> BELANJA</span>
                <small class="text-muted fw-normal">Biaya Operasional</small>
            </div>
            <div class="card-body p-0 scroll-box">
                <table class="table table-hover mb-0 small">
                    <?php if(empty($belanja_list)): ?>
                        <tr><td class="text-center py-5 text-muted fst-italic">Belum ada akun anggaran belanja.</td></tr>
                    <?php endif; ?>

                    <?php foreach($belanja_list as $row): 
                        $is_grp = $row['is_group'];
                        if(!$is_grp && $row['parent_id'] != 0) continue; 
                    ?>
                        <tr class="<?= $is_grp ? 'row-group' : 'row-item' ?>">
                            <td class="ps-3">
                                <?= $is_grp ? '<i class="fas fa-folder me-2 text-warning"></i>' : '' ?>
                                <?= $row['nama_anggaran'] ?>
                            </td>
                            <td class="text-end pe-3">
                                <button class="btn btn-xs text-danger" onclick="hapusBudget(<?= $row['id'] ?>)"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>

                        <?php if($is_grp): 
                            foreach($belanja_list as $child): 
                                if($child['parent_id'] == $row['id']): ?>
                                <tr class="row-item">
                                    <td class="indent-item text-muted">
                                        <i class="fas fa-angle-right me-2 opacity-50"></i> <?= $child['nama_anggaran'] ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <?php if($child['kode_akun_coa']): ?>
                                            <span class="badge-coa" title="<?= $child['nama_coa'] ?>">COA: <?= $child['kode_akun_coa'] ?></span>
                                        <?php endif; ?>
                                        <button class="btn btn-xs text-danger ms-2" onclick="hapusBudget(<?= $child['id'] ?>)"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                        <?php endif; endforeach; endif; ?>

                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- MODAL TAMBAH AKUN ANGGARAN -->
<div class="modal fade" id="modalBudget" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="settings.php" class="modal-content rounded-4 shadow border-0">
            <input type="hidden" name="action" value="save_budget_account">
            
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Tambah Akun Anggaran</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="fw-bold small text-muted">URAIAN AKUN ANGGARAN</label>
                    <input type="text" name="nama" class="form-control" required placeholder="Cth: Belanja ATK / Pendapatan SPP">
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <label class="fw-bold small text-muted">JENIS ANGGARAN</label>
                        <select name="jenis" id="bdgJenis" class="form-select" onchange="toggleBudgetFields()">
                            <option value="PENDAPATAN">PENDAPATAN</option>
                            <option value="BELANJA">BELANJA</option>
                        </select>
                    </div>
                    <div class="col-6 pt-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_group" id="bdgIsGroup" onchange="toggleBudgetFields()">
                            <label class="form-check-label small fw-bold" for="bdgIsGroup">Ini Grup Akun?</label>
                        </div>
                    </div>
                </div>

                <!-- FIELD: PARENT GROUP (Hanya Muncul Jika Bukan Grup) -->
                <div class="mb-3" id="divParent">
                    <label class="fw-bold small text-muted">MASUK KE GRUP (PARENT)</label>
                    <select name="parent_id" id="bdgParent" class="form-select">
                        <option value="0">-- Pilih Grup --</option>
                        <!-- Options diisi via JS -->
                    </select>
                </div>

                <!-- FIELD: TARGET COA (Hanya Muncul Jika Bukan Grup) -->
                <div class="mb-3" id="divCoa">
                    <label class="fw-bold small text-muted">MAPPING KE AKUN COA (KEUANGAN)</label>
                    <select name="kode_akun_coa" id="bdgCoa" class="form-select select2 w-100">
                        <option value="">-- Pilih Akun COA --</option>
                        <!-- Options diisi via JS -->
                    </select>
                    <div class="form-text text-xs text-info"><i class="fas fa-info-circle me-1"></i> Transaksi anggaran ini akan dicatat ke akun COA di atas.</div>
                </div>

                <!-- FIELD KHUSUS PENDAPATAN PENDIDIKAN -->
                <div id="divPendidikan" class="bg-light p-3 rounded border border-primary border-opacity-25 mb-3" style="display:none;">
                    <h6 class="small fw-bold text-primary mb-2 border-bottom pb-1"><i class="fas fa-graduation-cap me-1"></i> Deteksi Pendapatan Pendidikan</h6>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="small text-muted">Semester (Kode)</label>
                            <input type="text" name="semester_target" class="form-control form-control-sm" placeholder="Ex: 20261">
                        </div>
                        <div class="col-6">
                            <label class="small text-muted">Program Studi</label>
                            <select name="program_pendidikan" class="form-select form-select-sm">
                                <option value="">-- Pilih --</option>
                                <option value="Reguler">Reguler</option>
                                <option value="Non Reguler">Non Reguler</option>
                                <option value="Hafidz">Hafidz</option>
                                <option value="RPL">RPL</option>
                                <option value="Profesi">Profesi</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-text text-xs mt-1">Digunakan untuk auto-generate tagihan mahasiswa.</div>
                </div>

            </div>
            <div class="modal-footer border-0 bg-light rounded-bottom-4">
                <button type="submit" class="btn btn-primary w-100 fw-bold rounded-pill">SIMPAN MAPPING</button>
            </div>
        </form>
    </div>
</div>

<form id="formHapusBudget" method="POST" action="settings.php">
    <input type="hidden" name="action" value="delete_budget_account">
    <input type="hidden" name="id" id="delBdgId">
</form>

<script>
    // DATA DARI PHP UNTUK JS
    const grupPend = <?= json_encode($grup_pendapatan) ?>;
    const grupBel = <?= json_encode($grup_belanja) ?>;
    
    // Siapkan options COA (agar tidak query ulang via AJAX)
    const coaIncomeOpts = `<?php while($c = $coa_income->fetch_assoc()) echo "<option value='{$c['kode_akun']}'>{$c['kode_akun']} - {$c['nama_akun']}</option>"; ?>`;
    const coaExpenseOpts = `<?php while($c = $coa_expense->fetch_assoc()) echo "<option value='{$c['kode_akun']}'>{$c['kode_akun']} - {$c['nama_akun']}</option>"; ?>`;

    function modalAddBudget() {
        document.querySelector('#modalBudget form').reset();
        new bootstrap.Modal(document.getElementById('modalBudget')).show();
        toggleBudgetFields(); // Reset state
    }

    function hapusBudget(id) {
        if(confirm('Hapus akun anggaran ini?')) {
            document.getElementById('delBdgId').value = id;
            document.getElementById('formHapusBudget').submit();
        }
    }

    function toggleBudgetFields() {
        const jenis = document.getElementById('bdgJenis').value;
        const isGroup = document.getElementById('bdgIsGroup').checked;
        
        const divParent = document.getElementById('divParent');
        const divCoa = document.getElementById('divCoa');
        const divPendidikan = document.getElementById('divPendidikan');
        
        const parentSelect = document.getElementById('bdgParent');
        const coaSelect = document.getElementById('bdgCoa');

        // 1. Logic Show/Hide Parent & COA
        if (isGroup) {
            divParent.style.display = 'none';
            divCoa.style.display = 'none';
            divPendidikan.style.display = 'none';
        } else {
            divParent.style.display = 'block';
            divCoa.style.display = 'block';
            
            // Populate Parent Dropdown based on Type
            parentSelect.innerHTML = '<option value="0">-- Pilih Grup --</option>';
            const source = (jenis === 'PENDAPATAN') ? grupPend : grupBel;
            source.forEach(g => {
                parentSelect.innerHTML += `<option value="${g.id}">${g.nama_anggaran}</option>`;
            });

            // Populate COA Dropdown based on Type
            coaSelect.innerHTML = '<option value="">-- Pilih Akun COA --</option>';
            coaSelect.innerHTML += (jenis === 'PENDAPATAN') ? coaIncomeOpts : coaExpenseOpts;
            
            // Logic Pendidikan (Hanya jika Pendapatan & Bukan Grup & Nama mengandung "pendidikan" opsional)
            if (jenis === 'PENDAPATAN') {
                divPendidikan.style.display = 'block';
            } else {
                divPendidikan.style.display = 'none';
            }
        }
    }

    // Init Select2 untuk COA Searchable
    $(document).ready(function() {
        $('.select2').select2({
            dropdownParent: $('#modalBudget'),
            width: '100%'
        });
    });
</script>