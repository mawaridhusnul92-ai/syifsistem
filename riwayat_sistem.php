<?php
/**
 * riwayat_sistem.php - OMNI ACTIVITY LOG & AUDIT TRAIL (FULL PAGE)
 * Versi: 11.6 (Enterprise Analytics - UI Navigation Fix)
 * STATUS: 100% FULL CODE (TIDAK ADA PEMOTONGAN)
 * Perbaikan Mutlak:
 * Menambahkan tombol "Kembali" di sisi kiri atas sejajar dengan judul,
 * mensinkronkan UI agar sama persis dengan Laporan Keuangan lainnya.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

if(function_exists('guardPage')) { guardPage('riwayat_sistem'); }

// =========================================================================
// 🛡️ THE AUTO-HEALER: INJEKSI MENU OTOMATIS KE SIDEBAR
// =========================================================================
try {
    $cek_menu = $conn->query("SELECT id FROM menus WHERE menu_key = 'riwayat_sistem' LIMIT 1");
    if ($cek_menu && $cek_menu->num_rows == 0) {
        $parent_q = $conn->query("SELECT menu_key FROM menus WHERE menu_key = 'set_system' OR menu_name LIKE '%Pengaturan%' LIMIT 1");
        $parent_key = ($parent_q && $parent_q->num_rows > 0) ? $parent_q->fetch_assoc()['menu_key'] : '';
        
        $conn->query("INSERT INTO menus (menu_name, icon, menu_key, menu_level, parent_key, urutan) 
                      VALUES ('Riwayat Aktivitas', 'fas fa-history', 'riwayat_sistem', 'Sub', '$parent_key', 98)");
        $new_menu_id = $conn->insert_id;
        
        $conn->query("INSERT IGNORE INTO role_permissions (role_id, menu_id, can_view) VALUES (1, $new_menu_id, 1)");
    }
} catch (Exception $e) {}

// =========================================================================
// 🚀 ENGINE FILTER, PAGINATION & DYNAMIC LIMITER (O(1) SCALING)
// =========================================================================
$limit_options = [10, 20, 50, 100];
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limit_options) ? (int)$_GET['limit'] : 50;

$page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if($page_num < 1) $page_num = 1;
$offset = ($page_num - 1) * $limit;

$f_modul = $conn->real_escape_string($_GET['f_modul'] ?? '');
$f_tgl_mulai = $conn->real_escape_string($_GET['f_tgl_mulai'] ?? '');
$f_tgl_akhir = $conn->real_escape_string($_GET['f_tgl_akhir'] ?? '');
$search = $conn->real_escape_string($_GET['q'] ?? '');

$where = "1=1";
if ($f_modul) $where .= " AND l.module = '$f_modul'";

if ($f_tgl_mulai && $f_tgl_akhir) {
    $where .= " AND DATE(l.created_at) BETWEEN '$f_tgl_mulai' AND '$f_tgl_akhir'";
} elseif ($f_tgl_mulai) {
    $where .= " AND DATE(l.created_at) >= '$f_tgl_mulai'";
} elseif ($f_tgl_akhir) {
    $where .= " AND DATE(l.created_at) <= '$f_tgl_akhir'";
}

if ($search) $where .= " AND (l.description LIKE '%$search%' OR u.name LIKE '%$search%' OR l.action_type LIKE '%$search%')";

$q_total = $conn->query("SELECT COUNT(l.id) as tot FROM sys_activity_log l LEFT JOIN users u ON l.user_id = u.id WHERE $where");
$total_data = $q_total ? $q_total->fetch_assoc()['tot'] : 0;
$total_page = ceil($total_data / $limit);

$sql = "SELECT l.*, u.name as user_name 
        FROM sys_activity_log l 
        LEFT JOIN users u ON l.user_id = u.id 
        WHERE $where 
        ORDER BY l.created_at DESC, l.id DESC 
        LIMIT $limit OFFSET $offset";
$logs = $conn->query($sql);

$q_mods = $conn->query("SELECT DISTINCT module FROM sys_activity_log WHERE module IS NOT NULL AND module != '' ORDER BY module ASC");
$modules_list = [];
if($q_mods) { while($rm = $q_mods->fetch_assoc()) $modules_list[] = $rm['module']; }
?>

<div class="animate__animated animate__fadeIn">
    <!-- 1. EXECUTIVE HEADER DENGAN TOMBOL KEMBALI -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border-start border-primary border-4 text-dark text-center">
        <div class="d-flex align-items-center gap-3 text-start">
            <a href="javascript:history.back()" class="btn btn-outline-dark rounded-pill px-3 fw-bold shadow-sm small text-uppercase"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
            <div>
                <h4 class="fw-bold text-primary mb-1"><i class="fas fa-history me-2"></i>Riwayat Aktivitas Sistem</h4>
                <p class="text-muted small mb-0 fw-bold">Omni Audit Trail: Melacak setiap perubahan, penambahan, dan penghapusan data secara transparan.</p>
            </div>
        </div>
        <div class="text-end d-none d-md-block">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-4 py-2 rounded-pill shadow-sm fs-6">
                <i class="fas fa-database me-1"></i> Total Record: <?= number_format($total_data) ?>
            </span>
        </div>
    </div>

    <!-- 2. FILTER & SEARCH PANEL -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="index.php" id="formFilterHistory" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="riwayat_sistem">
                <input type="hidden" name="limit" id="hiddenLimit" value="<?= $limit ?>">
                
                <div class="col-lg-3 col-md-6">
                    <label class="small fw-bold text-muted mb-1 text-uppercase">Pencarian</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control border-0 bg-light shadow-none fw-bold" placeholder="Ketik kata kunci..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-6">
                    <label class="small fw-bold text-muted mb-1 text-uppercase">Pilih Modul</label>
                    <select name="f_modul" class="form-select form-select-sm border-0 bg-light shadow-none fw-bold text-dark">
                        <option value="">Semua Modul</option>
                        <?php foreach($modules_list as $mod): ?>
                            <option value="<?= htmlspecialchars($mod) ?>" <?= ($f_modul == $mod) ? 'selected' : '' ?>><?= htmlspecialchars($mod) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-lg-5 col-md-8">
                    <label class="small fw-bold text-muted mb-1 text-uppercase">Rentang Tanggal Riwayat</label>
                    <div class="input-group input-group-sm shadow-sm rounded-pill overflow-hidden border border-light">
                        <span class="input-group-text bg-white border-0 fw-bold text-primary px-3"><i class="fas fa-calendar-alt me-1"></i> Dari</span>
                        <input type="date" name="f_tgl_mulai" class="form-control border-0 bg-white shadow-none fw-bold text-dark text-center" value="<?= htmlspecialchars($f_tgl_mulai) ?>">
                        <span class="input-group-text bg-white border-0 text-muted px-2">s/d</span>
                        <input type="date" name="f_tgl_akhir" class="form-control border-0 bg-white shadow-none fw-bold text-dark text-center" value="<?= htmlspecialchars($f_tgl_akhir) ?>">
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 text-end d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow-sm w-100"><i class="fas fa-filter me-1"></i> FILTER</button>
                    <a href="index.php?page=riwayat_sistem" class="btn btn-light btn-sm rounded-pill px-3 shadow-sm text-danger border" title="Reset Filter"><i class="fas fa-sync-alt"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. MAIN AUDIT TABLE -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-dark">
                <thead class="table-light text-uppercase small text-muted">
                    <tr>
                        <th class="ps-4" width="140">Tgl & Waktu</th>
                        <th width="180">Pengguna</th>
                        <th width="180">Modul & Aksi</th>
                        <th>Deskripsi Aktivitas</th>
                        <th class="text-center pe-4" width="160">Opsi Audit & Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($logs && $logs->num_rows > 0): ?>
                        <?php while($l = $logs->fetch_assoc()): 
                            // 🚀 KONTROL WARNA MUTLAK (HIJAU SOLID UNTUK BUAT)
                            $act = strtolower($l['action_type']);
                            $badge_class = 'bg-secondary text-white';
                            
                            if (strpos($act, 'buat') !== false || strpos($act, 'tambah') !== false || strpos($act, 'generate') !== false || strpos($act, 'import') !== false) {
                                $badge_class = 'bg-success text-white shadow-sm border-0'; // HIJAU SOLID
                            } elseif (strpos($act, 'ubah') !== false || strpos($act, 'edit') !== false || strpos($act, 'perbarui') !== false) {
                                $badge_class = 'bg-primary text-white shadow-sm border-0'; // BIRU SOLID
                            } elseif (strpos($act, 'hapus') !== false || strpos($act, 'delete') !== false || strpos($act, 'dibatalkan') !== false) {
                                $badge_class = 'bg-danger text-white shadow-sm border-0'; // MERAH SOLID
                            } elseif (strpos($act, 'dipulihkan') !== false || strpos($act, 'setujui') !== false || strpos($act, 'verifikasi') !== false) {
                                $badge_class = 'bg-info text-white shadow-sm border-0'; // INFO SOLID
                            }
                            
                            $is_disabled = ($l['is_reverted'] == 1 || $act == 'dibatalkan' || $act == 'dipulihkan') ? true : false;
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark"><?= date('d/m/Y', strtotime($l['created_at'])) ?></div>
                                <div class="small text-muted"><i class="far fa-clock me-1"></i><?= date('H:i:s', strtotime($l['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2 border" style="width:30px; height:30px;">
                                        <i class="fas fa-user-tie text-primary" style="font-size:12px;"></i>
                                    </div>
                                    <span class="fw-bold text-dark small"><?= htmlspecialchars($l['user_name'] ?? 'Sistem') ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="mb-1"><span class="badge bg-light text-dark border px-2 py-1 shadow-sm"><i class="fas fa-cube me-1 text-muted"></i> <?= htmlspecialchars($l['module']) ?></span></div>
                                <div><span class="badge <?= $badge_class ?> px-3 py-1 w-100 text-uppercase fw-bold" style="letter-spacing: 0.5px;"><?= $l['action_type'] ?></span></div>
                            </td>
                            <td>
                                <div class="text-dark small fw-bold" style="line-height:1.4;">
                                    <?= htmlspecialchars($l['description']) ?>
                                </div>
                                <?php if($is_disabled): ?>
                                    <div class="mt-1"><span class="badge bg-secondary bg-opacity-25 text-dark border border-secondary border-opacity-25" style="font-size:9px;">SUDAH DIBATALKAN / DIPULIHKAN</span></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-4">
                                <div class="d-flex flex-column gap-1">
                                    <button class="btn btn-sm btn-outline-primary rounded-pill fw-bold w-100 bg-light" onclick="viewDetail(<?= $l['id'] ?>)" title="Lihat JSON / Rincian Data">
                                        <i class="fas fa-search-plus me-1"></i> Lihat Detail
                                    </button>
                                    
                                    <?php if(!$is_disabled): ?>
                                        <button class="btn btn-sm btn-danger rounded-pill fw-bold w-100 shadow-sm" onclick="undoActivity(<?= $l['id'] ?>)">
                                            <i class="fas fa-undo me-1"></i> Batalkan
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-light rounded-pill fw-bold w-100 text-muted border" disabled>
                                            <i class="fas fa-lock me-1"></i> Locked
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted fw-bold">
                            <i class="fas fa-box-open fa-2x mb-3 d-block opacity-50"></i>Belum ada rekam jejak aktivitas yang sesuai dengan filter pencarian.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 4. PAGINATION & LIMITER (FOOTER/BAWAH) -->
        <?php if($total_data > 0): ?>
        <div class="card-footer bg-white border-0 p-3 d-flex flex-column flex-md-row justify-content-between align-items-center gap-3 border-top">
            <div class="d-flex align-items-center gap-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="small fw-bold text-muted">Tampilkan</span>
                    <select class="form-select form-select-sm border bg-light shadow-none fw-bold text-primary" style="width: 75px;" onchange="document.getElementById('hiddenLimit').value = this.value; document.getElementById('formFilterHistory').submit();">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    <span class="small fw-bold text-muted">Data</span>
                </div>
                <small class="text-muted fw-bold border-start ps-3 d-none d-md-block">Halaman <?= $page_num ?> dari <?= $total_page ?></small>
            </div>

            <?php if($total_page > 1): ?>
            <nav>
                <ul class="pagination pagination-sm mb-0 shadow-sm rounded-pill overflow-hidden">
                    <li class="page-item <?= ($page_num <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="index.php?page=riwayat_sistem&p=<?= $page_num-1 ?>&limit=<?= $limit ?>&q=<?= urlencode($search) ?>&f_modul=<?= urlencode($f_modul) ?>&f_tgl_mulai=<?= urlencode($f_tgl_mulai) ?>&f_tgl_akhir=<?= urlencode($f_tgl_akhir) ?>"><i class="fas fa-chevron-left me-1"></i> Prev</a>
                    </li>
                    <?php for($i=1; $i<=$total_page; $i++): 
                        if($i >= $page_num - 2 && $i <= $page_num + 2):
                    ?>
                        <li class="page-item <?= ($i == $page_num) ? 'active' : '' ?>">
                            <a class="page-link" href="index.php?page=riwayat_sistem&p=<?= $i ?>&limit=<?= $limit ?>&q=<?= urlencode($search) ?>&f_modul=<?= urlencode($f_modul) ?>&f_tgl_mulai=<?= urlencode($f_tgl_mulai) ?>&f_tgl_akhir=<?= urlencode($f_tgl_akhir) ?>"><?= $i ?></a>
                        </li>
                    <?php endif; endfor; ?>
                    <li class="page-item <?= ($page_num >= $total_page) ? 'disabled' : '' ?>">
                        <a class="page-link" href="index.php?page=riwayat_sistem&p=<?= $page_num+1 ?>&limit=<?= $limit ?>&q=<?= urlencode($search) ?>&f_modul=<?= urlencode($f_modul) ?>&f_tgl_mulai=<?= urlencode($f_tgl_mulai) ?>&f_tgl_akhir=<?= urlencode($f_tgl_akhir) ?>">Next <i class="fas fa-chevron-right ms-1"></i></a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalDetailActivity" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" id="mdlSizeControl">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 p-4" id="mdlHeaderColor">
                <h5 class="fw-bold mb-0 text-white" id="mdlTitle"><i class="fas fa-info-circle me-2"></i>Rincian Aktivitas</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light text-dark">
                <div id="detailLoading" class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                    <h6 class="text-muted fw-bold">Membedah Riwayat Data JSON...</h6>
                </div>
                
                <div id="detailContent" class="d-none">
                    <div class="alert bg-white border shadow-sm rounded-4 mb-4 text-center p-3">
                        <div class="fw-bold text-primary text-uppercase" style="font-size: 11px;" id="dtlModule">MODUL</div>
                        <h6 class="fw-bold text-dark mt-1 mb-0" id="dtlDesc">Deskripsi</h6>
                    </div>
                    <div id="dtlDataContainer"></div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top p-3 justify-content-center d-block text-center">
                <button type="button" class="btn btn-secondary rounded-pill px-5 py-2 fw-bold shadow-sm w-100" data-bs-dismiss="modal">TUTUP RINCIAN</button>
            </div>
        </div>
    </div>
</div>

<script>
function undoActivity(id) {
    if(!confirm("PENTING: Anda yakin ingin membatalkan aktivitas ini?\n\nSistem akan secara otomatis membalikkan/memulihkan data dan merekonstruksi Jurnal Akuntansi yang terdampak secara mutlak (Rollback).")) return;

    const formData = new FormData();
    formData.append('action', 'undo');
    formData.append('id', id);

    fetch('history_action.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') {
            alert("Berhasil: " + res.msg);
            setTimeout(() => window.location.reload(), 500);
        } else {
            alert("Gagal Membatalkan: " + res.msg);
        }
    }).catch(e => {
        alert('Gagal menghubungi server. Periksa koneksi jaringan Anda.');
    });
}

function formatNiceData(jsonStr) {
    if (!jsonStr || jsonStr === "null") return "<div class='text-center text-muted fst-italic py-4 bg-white border rounded-4 shadow-sm'>Tidak ada data spesifik (rincian JSON) yang terekam.</div>";
    try {
        let parsed = JSON.parse(jsonStr);
        if (parsed.header) parsed = parsed.header; 

        if (Array.isArray(parsed)) {
            return `<div class='text-center py-4 bg-white border rounded-4 shadow-sm text-muted small fw-bold'>
                        <i class="fas fa-list-ul fa-2x mb-2 d-block text-primary"></i> Berisi multi-baris data operasional.
                    </div>`;
        }

        let html = '<ul class="list-group list-group-flush mb-0 border rounded-4 shadow-sm overflow-hidden">';
        const hiddenKeys = ['id', 'created_at', 'updated_at', 'link_jurnal_id', 'no_jurnal', 'created_by', 'user_id', 'mahasiswa_id', 'aset_id', 'tagihan_id_ref', 'pegawai_id', 'password', 'reset_token', 'reset_expires', 'is_deleted', 'is_migration', 'status_bayar', 'terbayar', 'pembayaran_jurnal_id', 'payroll_id', 'komponen_id', 'jenis_tagihan_id', 'tarif_id', 'prodi_id', 'pihak_nama'];
        
        const mapKata = {
            'nama_mahasiswa': 'Nama Mahasiswa', 'nama_pegawai': 'Nama Pegawai', 'nip_pegawai': 'NIP / NIK',
            'pihak_terkait_jurnal': 'Penyetor / Penerima', 'nama_tagihan': 'Nama Tagihan',
            'nominal': 'Nominal Tagihan', 'nominal_bayar': 'Nom. Pembayaran', 'nim': 'NIM',
            'kode_tahun': 'Periode Akademik', 'tanggal_bayar': 'Tgl. Pembayaran', 'no_kuitansi': 'No. Kuitansi',
            'kode_akun_kas': 'Akun Kas', 'keterangan': 'Keterangan', 'tgl_jurnal': 'Tgl. Transaksi',
            'total_debet': 'Total Debit', 'total_kredit': 'Total Kredit', 'nama_lengkap': 'Nama Pegawai',
            'jabatan': 'Jabatan', 'unit_kerja': 'Unit Bertugas', 'periode_bulan': 'Bulan',
            'periode_tahun': 'Tahun', 'total_gross': 'Gaji Kotor', 'total_potongan': 'Potongan',
            'total_netto': 'Gaji Bersih (THP)', 'gapok': 'Gaji Pokok', 'tunjangan': 'Tunjangan',
            'jenis_transaksi': 'Jenis Transaksi', 'akun_utama_kode': 'Kode Akun Utama',
            'angkatan': 'Tahun Angkatan', 'sistem_kuliah': 'Sistem Kuliah', 'program': 'Program / Usulan'
        };

        let hasData = false;
        for (let key in parsed) {
            if (hiddenKeys.includes(key)) continue;
            let val = parsed[key];
            if (val === null || val === '') continue;

            let cleanKey = mapKata[key] || key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            
            if (!isNaN(val) && val > 100 && (key.includes('nominal') || key.includes('debet') || key.includes('debit') || key.includes('kredit') || key.includes('saldo') || key.includes('harga') || key.includes('nilai') || key.includes('gross') || key.includes('potongan') || key.includes('netto') || key.includes('gapok') || key.includes('tunjangan'))) {
                val = "Rp " + new Intl.NumberFormat('id-ID').format(val);
            }

            if((key.includes('tanggal') || key.includes('tgl') || key.includes('date')) && typeof val === 'string' && val.includes('-')) {
                const pDate = val.split(' ')[0].split('-');
                if(pDate.length === 3) val = `${pDate[2]}/${pDate[1]}/${pDate[0]}`;
            }

            let valStyle = "font-size: 13px; max-width:60%; word-wrap:break-word;";
            if (key === 'nama_mahasiswa' || key === 'nama_pegawai' || key === 'pihak_terkait_jurnal' || key === 'program') {
                valStyle += " color: #0d6efd; font-weight: 900 !important;";
            }

            html += `<li class="list-group-item px-4 py-3 bg-white d-flex justify-content-between align-items-center border-bottom">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 10px;">${cleanKey}</span>
                        <span class="fw-bold text-dark text-end" style="${valStyle}">${val}</span>
                     </li>`;
            hasData = true;
        }
        html += '</ul>';
        return hasData ? html : "<div class='text-center text-muted fst-italic py-4 bg-white border rounded-4 shadow-sm'>Hanya pembaruan status sistem (Logikal).</div>";
    } catch (e) {
        return "<div class='text-center text-muted fst-italic py-4 bg-white border rounded-4 shadow-sm'>Rincian format JSON tidak didukung.</div>";
    }
}

function viewDetail(id) {
    const modal = new bootstrap.Modal(document.getElementById('modalDetailActivity'));
    modal.show();
    
    document.getElementById('detailLoading').classList.remove('d-none');
    document.getElementById('detailContent').classList.add('d-none');
    
    fetch('history_action.php?action=get_detail&id=' + id)
    .then(r => r.json())
    .then(res => {
        document.getElementById('detailLoading').classList.add('d-none');
        document.getElementById('detailContent').classList.remove('d-none');
        
        if (res.status === 'success') {
            const act = res.data.action_type.toLowerCase();
            const hdr = document.getElementById('mdlHeaderColor');
            const mdlSize = document.getElementById('mdlSizeControl');
            
            hdr.className = 'modal-header text-white border-0 p-4'; 
            mdlSize.className = 'modal-dialog modal-dialog-centered modal-dialog-scrollable'; 
            
            let iconTitle = '';
            
            if(act.includes('buat') || act.includes('tambah') || act.includes('generate') || act.includes('import')) { 
                hdr.classList.add('bg-success'); 
                iconTitle = '<i class="fas fa-plus-circle me-2"></i> Pembuatan / Entri Baru';
                mdlSize.classList.add('modal-md'); 
            } else if(act.includes('perbarui') || act.includes('ubah') || act.includes('edit')) { 
                hdr.classList.add('bg-primary'); 
                iconTitle = '<i class="fas fa-edit me-2"></i> Perubahan Data (Update)';
                mdlSize.classList.add('modal-lg'); 
            } else if(act.includes('hapus') || act.includes('delete') || act.includes('dibatalkan')) { 
                hdr.classList.add('bg-danger'); 
                iconTitle = '<i class="fas fa-trash-alt me-2"></i> Penghapusan Data / Dibatalkan';
                mdlSize.classList.add('modal-md'); 
            } else { 
                hdr.classList.add('bg-secondary'); 
                iconTitle = '<i class="fas fa-info-circle me-2"></i> Rincian Sistem';
                mdlSize.classList.add('modal-md');
            }
            
            document.getElementById('mdlTitle').innerHTML = iconTitle;
            document.getElementById('dtlModule').innerText = res.data.module;
            document.getElementById('dtlDesc').innerText = res.data.description;
            
            const container = document.getElementById('dtlDataContainer');
            container.innerHTML = '';
            
            if (act.includes('perbarui') || act.includes('ubah') || act.includes('edit')) {
                container.innerHTML = `
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="fw-bold text-muted small text-center mb-2 bg-white rounded-pill py-2 border shadow-sm"><i class="fas fa-history me-1"></i> SEBELUMNYA</div>
                            ${formatNiceData(res.data.old_data)}
                        </div>
                        <div class="col-md-6">
                            <div class="fw-bold text-primary small text-center mb-2 bg-primary bg-opacity-10 rounded-pill py-2 border border-primary border-opacity-25"><i class="fas fa-magic me-1"></i> DIPERBARUI MENJADI</div>
                            ${formatNiceData(res.data.new_data)}
                        </div>
                    </div>
                `;
            } else if (act.includes('hapus') || act.includes('delete') || act.includes('dibatalkan')) {
                 container.innerHTML = `
                    <div class="mt-2">
                        <div class="fw-bold text-danger small text-center mb-2 bg-danger bg-opacity-10 rounded-pill py-2 border border-danger border-opacity-25"><i class="fas fa-trash me-1"></i> DATA YANG DIHAPUS</div>
                        ${formatNiceData(res.data.old_data)}
                    </div>
                `;
            } else {
                 container.innerHTML = `
                    <div class="mt-2">
                        <div class="fw-bold text-success small text-center mb-2 bg-success bg-opacity-10 rounded-pill py-2 border border-success border-opacity-25"><i class="fas fa-check me-1"></i> DATA YANG DITAMBAHKAN</div>
                        ${formatNiceData(res.data.new_data)}
                    </div>
                `;
            }
        } else {
            document.getElementById('dtlDesc').innerHTML = `<span class="text-danger fw-bold"><i class="fas fa-times-circle me-1"></i>${res.msg}</span>`;
            document.getElementById('dtlDataContainer').innerHTML = '';
        }
    }).catch(e => {
        document.getElementById('detailLoading').classList.add('d-none');
        alert("Gagal menarik rincian data forensik dari server.");
    });
}
</script>