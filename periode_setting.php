<?php
/**
 * periode_setting.php - UI MASTER PERIODE PELAPORAN
 * Versi: 3.0 (Sovereign God-Mode & Auto-Healer Edition)
 * Perbaikan: 
 * 1. Mengganti Dropdown Tahun dengan Input Manual (YYYY).
 * 2. AUTO-HEALER: Mengembalikan Menu ke Sidebar secara otomatis jika terhapus.
 * 3. GOD-MODE UI: Menampilkan tombol "Buka Akses" KHUSUS untuk Super Admin (Role 1).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$role_id_aktif = (int)($_SESSION['role_id'] ?? 0);

// =========================================================================
// ?? THE SOVEREIGN AUTO-HEALER (MEMULIHKAN MENU YANG HILANG)
// =========================================================================
try {
    // 1. Cek apakah menu 'periode_setting' ada di database
    $cek_menu = $conn->query("SELECT id FROM menus WHERE menu_key = 'periode_setting' LIMIT 1");
    if ($cek_menu && $cek_menu->num_rows == 0) {
        // Cari parent 'Pengaturan Sistem'
        $parent_id = 0;
        $cek_parent = $conn->query("SELECT id FROM menus WHERE menu_name LIKE '%Pengaturan%' OR menu_key = 'set_system' LIMIT 1");
        if ($cek_parent && $cek_parent->num_rows > 0) {
            $parent_id = $cek_parent->fetch_assoc()['id'];
        }
        
        // Suntikkan kembali ke tabel menus
        $conn->query("INSERT INTO menus (menu_name, menu_link, menu_icon, menu_key, menu_level, parent_id, urutan) 
                      VALUES ('Periode Laporan', '?page=periode_setting', 'fas fa-calendar-check', 'periode_setting', 'Sub', $parent_id, 99)");
        $new_menu_id = $conn->insert_id;

        // Berikan akses mutlak ke Super Admin (Role 1)
        $conn->query("INSERT INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) 
                      VALUES (1, $new_menu_id, 1, 1, 1, 1)");
    } else {
        // Jika menu ada tapi akses Super Admin hilang, pulihkan aksesnya
        $menu_id = $cek_menu->fetch_assoc()['id'];
        $cek_akses = $conn->query("SELECT id FROM role_permissions WHERE role_id = 1 AND menu_id = $menu_id");
        if ($cek_akses && $cek_akses->num_rows == 0) {
            $conn->query("INSERT INTO role_permissions (role_id, menu_id, can_view, can_add, can_edit, can_delete) 
                          VALUES (1, $menu_id, 1, 1, 1, 1)");
        }
    }
} catch (Exception $e) { /* Lanjutkan dengan tenang jika ada error database minor */ }

// Guard Page (Hanya aktif jika bukan Super Admin)
if($role_id_aktif !== 1 && function_exists('guardPage')) { guardPage('periode_setting'); }

// Filter Tahun (Default Tahun Ini jika tidak ada input)
$f_tahun = $_GET['tahun'] ?? date('Y');

// Query Data
$rows = $conn->query("SELECT p.*, u.nama_lengkap as pembuat 
                      FROM syifa_periode_laporan p 
                      LEFT JOIN users u ON p.created_by = u.id 
                      WHERE YEAR(p.tgl_mulai) = '$f_tahun' 
                      ORDER BY p.tgl_mulai ASC");
?>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4">
        <div>
            <h4 class="fw-bold text-dark mb-0"><i class="fas fa-calendar-alt me-2 text-primary"></i>Manajemen Periode (Tutup Buku)</h4>
            <small class="text-muted fw-bold">Kunci transaksi keuangan per periode untuk mengamankan integritas laporan (Ledger Freeze).</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-dark rounded-pill shadow-sm fw-bold px-4" onclick="modalGenerate()">
                <i class="fas fa-magic me-2"></i>GENERATE 1 TAHUN
            </button>
            <button class="btn btn-primary rounded-pill shadow-sm fw-bold px-4" onclick="modalPeriode()">
                <i class="fas fa-plus-circle me-2"></i>BUAT MANUAL
            </button>
        </div>
    </div>

    <!-- Filter & Table Card -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
            <form method="GET" action="index.php" class="d-flex align-items-center gap-2">
                <input type="hidden" name="page" value="periode_setting">
                <label class="fw-bold text-muted small uppercase mb-0">TAHUN BUKU:</label>
                <input type="number" name="tahun" class="form-control rounded-pill text-center fw-bold" style="width: 100px;" value="<?= $f_tahun ?>">
                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3"><i class="fas fa-search"></i></button>
            </form>
            <span class="badge bg-light text-dark border px-3 py-2"><i class="fas fa-info-circle text-primary me-1"></i> Mode: Audit Compliance (ISAK 35)</span>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light fw-bold text-uppercase small text-muted">
                    <tr>
                        <th class="ps-4">Nama Periode</th>
                        <th>Rentang Tanggal</th>
                        <th>Jenis</th>
                        <th>Keterangan</th>
                        <th class="text-center">Status</th>
                        <th class="text-center pe-4">Aksi / Kontrol</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($rows && $rows->num_rows > 0): while($r = $rows->fetch_assoc()): 
                        $is_closed = (strtoupper($r['status']) == 'DITUTUP' || strtoupper($r['status']) == 'CLOSED');
                    ?>
                    <tr>
                        <td class="ps-4 fw-bold text-dark">
                            <?= $r['nama_periode'] ?>
                            <?php if($r['is_audit'] == 1): ?><br><span class="badge bg-warning text-dark mt-1" style="font-size: 0.65rem;">AUDITED</span><?php endif; ?>
                        </td>
                        <td><code class="text-dark bg-light px-2 py-1 rounded-3"><?= date('d/m/Y', strtotime($r['tgl_mulai'])) ?> - <?= date('d/m/Y', strtotime($r['tgl_akhir'])) ?></code></td>
                        <td><?= $r['jenis_periode'] ?></td>
                        <td class="small text-muted"><?= $r['keterangan'] ?: '-' ?></td>
                        <td class="text-center">
                            <?php if($is_closed): ?>
                                <span class="badge bg-danger rounded-pill px-3"><i class="fas fa-lock me-1"></i> DITUTUP</span>
                                <div class="small text-muted mt-1" style="font-size: 10px;">Oleh: <?= $r['pembuat'] ?: 'Sistem' ?></div>
                            <?php else: ?>
                                <span class="badge bg-success rounded-pill px-3"><i class="fas fa-lock-open me-1"></i> AKTIF</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center pe-4">
                            <?php if(!$is_closed): ?>
                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold shadow-sm" onclick="toggleStatus(<?= $r['id'] ?>, 'Ditutup', 'Kunci seluruh transaksi pada periode ini?')">
                                    TUTUP BUKU
                                </button>
                            <?php else: ?>
                                <?php if($role_id_aktif === 1): // HANYA SUPER ADMIN YANG BISA MELIHAT TOMBOL INI ?>
                                    <button class="btn btn-sm btn-warning text-dark rounded-pill px-3 fw-bold shadow-sm" onclick="toggleStatus(<?= $r['id'] ?>, 'Aktif', 'SUPER ADMIN: Anda yakin ingin merekonstruksi/membuka kembali periode yang sudah ditutup?')">
                                        <i class="fas fa-key me-1"></i> BUKA AKSES
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small italic"><i class="fas fa-shield-alt"></i> Terkunci</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">Belum ada pengaturan periode untuk tahun <?= $f_tahun ?>. Silakan Generate.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah/Edit Manual -->
<div class="modal fade" id="mdlPeriode" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form action="periode_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_periode">
            <input type="hidden" name="id" id="inpId">
            <div class="modal-header bg-primary text-white border-0 p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-calendar-plus me-2"></i>Pengaturan Periode</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1">Nama Periode</label>
                    <input type="text" name="nama" id="inpNama" class="form-control rounded-pill border-0 shadow-sm px-3" placeholder="Contoh: Januari 2026" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1">Jenis Laporan</label>
                    <select name="jenis" id="inpJenis" class="form-select rounded-pill border-0 shadow-sm px-3" required>
                        <option value="Bulanan">Bulanan</option>
                        <option value="Triwulan">Triwulanan</option>
                        <option value="Semester">Semester</option>
                        <option value="Tahunan">Tahunan</option>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="small fw-bold text-muted mb-1">Mulai</label>
                        <input type="date" name="start" id="inpStart" class="form-control rounded-pill border-0 shadow-sm px-3" required>
                    </div>
                    <div class="col-6">
                        <label class="small fw-bold text-muted mb-1">Berakhir</label>
                        <input type="date" name="end" id="inpEnd" class="form-control rounded-pill border-0 shadow-sm px-3" required>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="small fw-bold text-muted mb-1">Keterangan (Opsional)</label>
                    <textarea name="keterangan" id="inpKet" class="form-control border-0 shadow-sm" rows="2" style="border-radius:15px;"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white p-3">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Generate Bulk -->
<div class="modal fade" id="mdlGenerate" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form action="periode_action.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="generate_bulk">
            <div class="modal-header bg-dark text-white border-0 p-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-magic me-2 text-warning"></i>Generate Periode 1 Tahun</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-center">
                <i class="fas fa-layer-group fa-3x text-muted mb-3 opacity-50"></i>
                <p class="text-dark fw-bold mb-4">Sistem akan secara otomatis membuat 12 periode bulanan secara berurutan sesuai tahun yang Anda pilih.</p>
                
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <label class="small fw-bold text-muted mb-1">Tahun Target</label>
                        <input type="number" name="tahun_target" class="form-control form-control-lg rounded-pill border-0 shadow-sm text-center fw-bold" value="<?= $f_tahun ?>" required>
                    </div>
                </div>
                
                <div class="form-check text-start mt-4 ms-4">
                    <input class="form-check-input" type="checkbox" name="include_semester" id="incSem" checked>
                    <label class="form-check-label small fw-bold text-dark" for="incSem">
                        Sertakan juga Pembuatan Periode Semester (Ganjil & Genap)
                    </label>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white p-3">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold shadow">Eksekusi Generate</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleStatus(id, setStatus, msg) {
    if(confirm(msg)) {
        window.location.href = `periode_action.php?action=toggle_status&id=${id}&set=${setStatus}`;
    }
}
function modalPeriode(d = null) {
    const m = new bootstrap.Modal(document.getElementById('mdlPeriode'));
    const curYear = '<?= $f_tahun ?>';
    
    document.getElementById('inpId').value = d ? d.id : '';
    document.getElementById('inpNama').value = d ? d.nama_periode : '';
    document.getElementById('inpJenis').value = d ? d.jenis_periode : 'Bulanan';
    document.getElementById('inpStart').value = d ? d.tgl_mulai : curYear + '-01-01';
    document.getElementById('inpEnd').value = d ? d.tgl_akhir : curYear + '-01-31';
    document.getElementById('inpKet').value = d ? d.keterangan : '';
    m.show();
}
function modalGenerate() {
    new bootstrap.Modal(document.getElementById('mdlGenerate')).show();
}
</script>