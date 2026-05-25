<?php
/**
 * honorarium_database_dosen.php - TAB 1: MANAJEMEN DATABASE DOSEN
 * Perbaikan: Mengambil list Prodi langsung dari mhs_prodi agar Sinkron.
 * Menerapkan Vanilla JS Fetch API untuk mencegah Redirect Hang!
 */

// Tarik Data Real dari Database
$dosen_data = [];
$res_dosen = $conn->query("SELECT * FROM dosen ORDER BY nama ASC");
if ($res_dosen) { while($r = $res_dosen->fetch_assoc()) $dosen_data[] = $r; }

// 🚀 SINKRONISASI PROGRAM STUDI DARI DATABASE MAHASISWA
$prodi_list = [];
$res_prodi = $conn->query("SELECT id, nama_prodi FROM mhs_prodi ORDER BY nama_prodi ASC");
if($res_prodi) { while($r = $res_prodi->fetch_assoc()) $prodi_list[] = $r['nama_prodi']; }

// Kalkulasi Metrik
$tot_aktif = $conn->query("SELECT COUNT(id) FROM dosen WHERE status='Aktif'")->fetch_row()[0] ?? 0;
$tot_non = $conn->query("SELECT COUNT(id) FROM dosen WHERE status='Non Aktif'")->fetch_row()[0] ?? 0;
$tot_cuti = $conn->query("SELECT COUNT(id) FROM dosen WHERE status='Cuti'")->fetch_row()[0] ?? 0;
$tot_tb = $conn->query("SELECT COUNT(id) FROM dosen WHERE status='Tugas Belajar'")->fetch_row()[0] ?? 0;
?>

<style>
    .avatar-circle { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #fff; font-size: 14px; }
    .table-dosen th { background-color: #f8fafc !important; color: #475569 !important; font-size: 11px; text-transform: uppercase; font-weight: 800; padding: 15px 10px; border-bottom: 2px solid #e2e8f0; text-align: center; }
    .table-dosen td { font-size: 13px; vertical-align: middle; padding: 12px 10px; color: #334155; border-bottom: 1px solid #f1f5f9; }
</style>

<div class="animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-1 text-dark">Database Dosen</h5>
            <p class="text-muted small mb-0">Kelola data master dosen sebagai sumber perhitungan honorarium.</p>
        </div>
        <button class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold" onclick="openModalDosen()"><i class="fas fa-plus me-2"></i>Tambah Dosen</button>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background: #dcfce7; color: #166534;"><i class="fas fa-user-check"></i></div><div><div class="metric-title">Dosen Aktif</div><div class="metric-value text-primary"><?= $tot_aktif ?></div></div></div></div>
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background: #fee2e2; color: #991b1b;"><i class="fas fa-user-times"></i></div><div><div class="metric-title">Non Aktif</div><div class="metric-value text-primary"><?= $tot_non ?></div></div></div></div>
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background: #fef9c3; color: #854d0e;"><i class="fas fa-user-clock"></i></div><div><div class="metric-title">Sedang Cuti</div><div class="metric-value text-primary"><?= $tot_cuti ?></div></div></div></div>
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background: #e0f2fe; color: #1e40af;"><i class="fas fa-user-graduate"></i></div><div><div class="metric-title">Tugas Belajar</div><div class="metric-value text-primary"><?= $tot_tb ?></div></div></div></div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
        <div class="row g-3 mb-4 p-3 bg-light rounded-3 border align-items-end">
            <div class="col-md-3">
                <label class="small fw-bold text-muted mb-1">Filter Status</label>
                <select class="form-select shadow-sm border-0 fw-bold text-dark rounded-pill px-3" id="filterStatus" onchange="filterDataDosen()">
                    <option value="">Semua Status</option>
                    <option value="Aktif">Aktif</option><option value="Non Aktif">Non Aktif</option><option value="Cuti">Cuti</option><option value="Tugas Belajar">Tugas Belajar</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-muted mb-1">Cari Nama/NIP</label>
                <input type="text" id="searchDosen" class="form-control shadow-sm border-0 fw-bold text-dark rounded-pill px-3" placeholder="Ketik kata kunci..." onkeyup="filterDataDosen()">
            </div>
            <div class="col-md-3 text-end">
                <button type="button" class="btn btn-outline-secondary rounded-pill w-100 fw-bold shadow-sm" onclick="resetFilter()"><i class="fas fa-sync-alt me-2"></i>Reset Filter</button>
            </div>
        </div>

        <div class="table-responsive">
            <table id="tableDosen" class="table table-hover align-middle w-100 table-dosen">
                <thead class="table-light text-uppercase small text-muted fw-bold text-center">
                    <tr><th width="5%">No</th><th class="text-start">Profil Dosen</th><th>NIP / NIDN</th><th class="text-start">Jabatan & Golongan</th><th>Program Studi</th><th>Status</th><th width="120">Aksi</th></tr>
                </thead>
                <tbody class="text-center" id="tbodyDosen">
                    <?php 
                    $no = 1; $colors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#0dcaf0'];
                    foreach($dosen_data as $d): 
                        $badge_class = 'badge-aktif';
                        if($d['status'] == 'Non Aktif') $badge_class = 'badge-nonaktif';
                        if($d['status'] == 'Cuti') $badge_class = 'badge-cuti';
                        if($d['status'] == 'Tugas Belajar') $badge_class = 'badge-tugas';

                        $words = explode(" ", preg_replace('/[^a-zA-Z\s]/', '', $d['nama']));
                        $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                        $color = $colors[array_rand($colors)];
                    ?>
                    <tr class="row-dosen" data-status="<?= $d['status'] ?>" data-search="<?= strtolower($d['nama'].' '.$d['nip']) ?>">
                        <td class="fw-bold"><?= $no++ ?></td>
                        <td class="text-start">
                            <div class="d-flex align-items-center">
                                <div class="avatar-circle me-3 shadow-sm" style="background-color: <?= $color ?>;"><?= $initials ?></div>
                                <div><div class="fw-bold text-primary"><?= $d['nama'] ?></div><div class="small text-muted"><?= $d['email'] ?></div></div>
                            </div>
                        </td>
                        <td class="text-center"><code class="text-dark bg-light px-2 py-1 rounded border fw-bold"><?= $d['nip'] ?></code></td>
                        <td class="text-start"><div class="fw-bold text-dark"><?= $d['jabatan_fungsional'] ?></div><div class="small text-muted">Gol: <span class="fw-bold"><?= $d['golongan'] ?></span> | <?= $d['pendidikan_terakhir'] ?></div></td>
                        <td class="fw-bold text-secondary text-center"><?= $d['program_studi'] ?></td>
                        <td class="text-center"><span class="badge <?= $badge_class ?> rounded-pill px-3 py-1 fw-bold"><?= $d['status'] ?></span></td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm rounded-pill overflow-hidden border shadow-sm bg-white">
                                <button type="button" class="btn btn-light text-warning border-end" onclick='editDosen(<?= htmlspecialchars(json_encode($d), JSON_HEX_APOS) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                                <button type="button" class="btn btn-light text-danger" onclick="deleteDosen(<?= $d['id'] ?>, '<?= addslashes($d['nama']) ?>')" title="Hapus"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 🚀 MODAL CEGAH REDIRECT DENGAN ACTION JAVASCRIPT:VOID(0) -->
<div class="modal fade" id="modalDosen" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form action="javascript:void(0);" onsubmit="handleSaveDosen(event)" id="formDosen" class="modal-content text-dark border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_dosen">
            <input type="hidden" name="id" id="dosenId">
            <div class="modal-header p-4 bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="modalDosenTitle"><i class="fas fa-user-plus me-2 text-warning"></i>Tambah Dosen Baru</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-3">
                    <div class="col-12"><h6 class="fw-bold border-bottom pb-2 mb-2 text-primary">A. Data Personal</h6></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">NIP / NIDN <span class="text-danger">*</span></label><input type="text" name="nip" id="inpNip" class="form-control rounded-3 border shadow-sm px-3" required placeholder="Ketik NIP..."></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">Nama Lengkap & Gelar <span class="text-danger">*</span></label><input type="text" name="nama" id="inpNama" class="form-control rounded-3 border shadow-sm px-3" required placeholder="Contoh: Dr. Budi, M.Kep"></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">Email</label><input type="email" name="email" id="inpEmail" class="form-control rounded-3 border shadow-sm px-3" placeholder="email@yarsi.ac.id"></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">No. WhatsApp / HP</label><input type="text" name="no_hp" id="inpNoHp" class="form-control rounded-3 border shadow-sm px-3" placeholder="0812..."></div>

                    <div class="col-12 mt-4"><h6 class="fw-bold border-bottom pb-2 mb-2 text-primary">B. Data Akademik & Fungsional</h6></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Pendidikan Terakhir <span class="text-danger">*</span></label><select name="pendidikan_terakhir" id="inpPend" class="form-select rounded-3 border shadow-sm px-3" required><option value="">-- Pilih --</option><option value="S1">S1</option><option value="S2">S2</option><option value="S3">S3</option><option value="Spesialis">Spesialis</option></select></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Jabatan Fungsional <span class="text-danger">*</span></label><select name="jabatan_fungsional" id="inpJab" class="form-select rounded-3 border shadow-sm px-3" required><option value="">-- Pilih --</option><option value="Tenaga Pengajar">Tenaga Pengajar</option><option value="Asisten Ahli">Asisten Ahli</option><option value="Lektor">Lektor</option><option value="Lektor Kepala">Lektor Kepala</option><option value="Profesor">Profesor</option></select></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Golongan <span class="text-danger">*</span></label><select name="golongan" id="inpGol" class="form-select rounded-3 border shadow-sm px-3" required><option value="">-- Pilih --</option><option value="III/a">III/a</option><option value="III/b">III/b</option><option value="III/c">III/c</option><option value="III/d">III/d</option><option value="IV/a">IV/a</option><option value="IV/b">IV/b</option><option value="IV/c">IV/c</option><option value="IV/d">IV/d</option></select></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">Program Studi <span class="text-danger">*</span></label><select name="program_studi" id="inpProdi" class="form-select rounded-3 border shadow-sm px-3" required><option value="">-- Pilih Prodi --</option><?php foreach($prodi_list as $p) echo "<option value='$p'>$p</option>"; ?></select></div>
                    <div class="col-md-6"><label class="form-label small fw-bold">Status Keaktifan <span class="text-danger">*</span></label><select name="status" id="inpStatusDos" class="form-select rounded-3 border shadow-sm px-3 fw-bold text-primary" required><option value="Aktif">Aktif</option><option value="Non Aktif">Non Aktif</option><option value="Cuti">Cuti</option><option value="Tugas Belajar">Tugas Belajar</option></select></div>

                    <div class="col-12 mt-4"><h6 class="fw-bold border-bottom pb-2 mb-2 text-primary">C. Informasi Rekening Bank</h6></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Nama Bank</label><select name="nama_bank" id="inpBank" class="form-select rounded-3 border shadow-sm px-3"><option value="">-- Pilih Bank --</option><option value="BSI">BSI (Bank Syariah Indonesia)</option><option value="Bank Kalbar">Bank Kalbar</option><option value="BRI">BRI</option><option value="BCA">BCA</option><option value="Mandiri">Mandiri</option><option value="BNI">BNI</option></select></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">No. Rekening</label><input type="text" name="no_rekening" id="inpRek" class="form-control rounded-3 border shadow-sm px-3"></div>
                    <div class="col-md-4"><label class="form-label small fw-bold">Atas Nama</label><input type="text" name="pemilik_rekening" id="inpPemilik" class="form-control rounded-3 border shadow-sm px-3"></div>
                </div>
            </div>
            <div class="modal-footer p-4 bg-white border-0 text-center d-block">
                <div class="row g-2">
                    <div class="col-6"><button type="button" class="btn btn-light w-100 rounded-pill py-3 fw-bold border shadow-sm" data-bs-dismiss="modal">BATAL</button></div>
                    <div class="col-6"><button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow" id="btnSubmitDosen"><i class="fas fa-save me-2"></i>SIMPAN DATA</button></div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        document.body.appendChild(document.getElementById('modalDosen'));
    });

    function filterDataDosen() {
        let stat = document.getElementById('filterStatus').value;
        let q = document.getElementById('searchDosen').value.toLowerCase();
        let rows = document.querySelectorAll('.row-dosen');
        
        rows.forEach(row => {
            let rowStat = row.getAttribute('data-status');
            let rowSearch = row.getAttribute('data-search');
            let matchStat = stat === '' || rowStat === stat;
            let matchSearch = q === '' || rowSearch.includes(q);
            
            if (matchStat && matchSearch) { row.style.display = ''; } 
            else { row.style.display = 'none'; }
        });
    }

    function resetFilter() {
        document.getElementById('filterStatus').value = '';
        document.getElementById('searchDosen').value = '';
        filterDataDosen();
    }

    function openModalDosen() {
        document.getElementById('formDosen').reset();
        document.getElementById('dosenId').value = '';
        document.getElementById('modalDosenTitle').innerHTML = '<i class="fas fa-user-plus me-2 text-warning"></i>Tambah Dosen Baru';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDosen')).show();
    }

    function editDosen(d) {
        document.getElementById('dosenId').value = d.id;
        document.getElementById('inpNip').value = d.nip;
        document.getElementById('inpNama').value = d.nama;
        document.getElementById('inpEmail').value = d.email;
        document.getElementById('inpNoHp').value = d.no_hp;
        document.getElementById('inpPend').value = d.pendidikan_terakhir;
        document.getElementById('inpJab').value = d.jabatan_fungsional;
        document.getElementById('inpGol').value = d.golongan;
        document.getElementById('inpProdi').value = d.program_studi;
        document.getElementById('inpStatusDos').value = d.status;
        document.getElementById('inpBank').value = d.nama_bank;
        document.getElementById('inpRek').value = d.no_rekening;
        document.getElementById('inpPemilik').value = d.pemilik_rekening;
        
        document.getElementById('modalDosenTitle').innerHTML = '<i class="fas fa-user-edit me-2 text-warning"></i>Ubah Data Dosen';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDosen')).show();
    }

    // 🚀 VANILLA FETCH: Mencegah Form Submit Normal yang bikin terlempar ke Dashboard!
    function handleSaveDosen(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('btnSubmitDosen');
        const oriText = btn.innerHTML;
        
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
        btn.disabled = true;

        fetch('honorarium_action.php', { method: 'POST', body: new FormData(form) })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                Swal.fire({ icon: 'success', title: 'Berhasil!', text: res.message, timer: 1500, showConfirmButton: false }).then(() => {
                    window.location.href = '?page=honorarium&tab=database'; 
                });
            } else {
                Swal.fire('Gagal', res.message, 'error');
                btn.innerHTML = oriText; btn.disabled = false;
            }
        }).catch(err => {
            Swal.fire('Error', 'Putus koneksi ke server', 'error');
            btn.innerHTML = oriText; btn.disabled = false;
        });
    }

    function deleteDosen(id, nama) {
        Swal.fire({
            title: 'Hapus Data Dosen?', text: "Yakin ingin menghapus data dosen " + nama + "?",
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#6c757d', confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData(); fd.append('action', 'delete_dosen'); fd.append('id', id);
                fetch('honorarium_action.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => {
                    if (res.status == 'success') Swal.fire({ icon: 'success', title: 'Terhapus!', text: res.message, timer: 1500, showConfirmButton: false }).then(() => { window.location.href = '?page=honorarium&tab=database'; });
                });
            }
        });
    }
</script>