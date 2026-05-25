<?php
/**
 * generate_laporan.php - FULL REPORT CONSOLIDATOR (ISAK 35)
 * Versi: 58.0 (Enterprise Skeleton UI & True Ledger Forensics)
 * Perbaikan Mutlak: 
 * 1. LAZY LOAD & SKELETON UI: Menghapus spinner loading kuno dan menggantinya 
 * dengan animasi Skeleton (Shimmering) ala YouTube/Facebook untuk UX yang premium.
 * 2. MENDUKUNG SALDO AWAL (V57) & OMNI-GAP CLOSER (V56) dipertahankan 100%.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$conn->query("CREATE TABLE IF NOT EXISTS arsip_laporan_konsolidasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tanggal_generate DATETIME DEFAULT CURRENT_TIMESTAMP,
    nama_laporan VARCHAR(255),
    periode VARCHAR(100),
    html_content LONGTEXT NULL
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_arsip_konsolidasi') {
    $nama = $conn->real_escape_string($_POST['nama_laporan']);
    $periode = $conn->real_escape_string($_POST['periode']);
    $html = $conn->real_escape_string($_POST['html_content']);
    
    $conn->query("INSERT INTO arsip_laporan_konsolidasi (nama_laporan, periode, html_content) VALUES ('$nama', '$periode', '$html')");
    
    while(ob_get_level()) ob_end_clean(); 
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'delete_arsip') {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM arsip_laporan_konsolidasi WHERE id = $id");
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Satu arsip riwayat laporan berhasil dihapus selamanya.'];
    header("Location: index.php?page=generate_laporan");
    exit;
}

$view_arsip_id = isset($_GET['view_arsip']) ? (int)$_GET['view_arsip'] : 0;

$q_app = $conn->query("SELECT * FROM system_profile WHERE id=1");
$app = $q_app ? $q_app->fetch_assoc() : null;

$logo_filename = $app['logo'] ?? '';
$logo_path = (!empty($logo_filename)) ? "assets/img/" . $logo_filename : "";

$nama_kampus_db = $app['institution_name'] ?? 'STIKes YARSI PONTIANAK';
$alamat_db = $app['address'] ?? 'Jl. Letjen Sutoyo, Kota Pontianak, Kalimantan Barat';
$telp_db = $app['phone'] ?? '(0561) 123456';
$email_db = $app['email'] ?? 'info@stikesyarsi.ac.id';
$web_db = $app['website'] ?? 'www.stikesyarsi.ac.id';
$kota_db = $app['city'] ?? 'Pontianak';

$report_title = $_GET['report_title'] ?? 'LAPORAN KEUANGAN KONSOLIDASIAN';
$inst_name = $_GET['inst_name'] ?? $nama_kampus_db;

$toc_default = "1. Laporan Posisi Keuangan (Neraca)\n2. Laporan Aktivitas (Laba/Rugi Nirlaba)\n3. Laporan Perubahan Aset Neto\n4. Laporan Arus Kas\n5. Catatan Atas Laporan Keuangan (Naratif)\n6. Rincian Saldo CALK";
$toc_text = $_GET['toc_text'] ?? $toc_default;

$do_generate = isset($_GET['do_generate']) && $_GET['do_generate'] == '1';

$list_neraca = []; $list_lr = []; $list_neto = []; $list_kas = [];
$q_saved = $conn->query("SELECT * FROM laporan_keuangan_setting ORDER BY tgl_akhir DESC, id DESC");
if ($q_saved) {
    while($r = $q_saved->fetch_assoc()) {
        $jl = strtolower($r['jenis_laporan']);
        $label = $r['judul_laporan'] . " (" . date('d/m/Y', strtotime($r['tgl_akhir'])) . ")";
        $r['dropdown_label'] = $label;
        if (strpos($jl, 'posisi') !== false || strpos($jl, 'neraca') !== false) { $list_neraca[] = $r; }
        elseif (strpos($jl, 'aktivitas') !== false || strpos($jl, 'laba') !== false) { $list_lr[] = $r; }
        elseif (strpos($jl, 'aset_neto') !== false) { $list_neto[] = $r; }
        elseif (strpos($jl, 'kas') !== false) { $list_kas[] = $r; }
    }
}

$is_all_exist = (count($list_neraca)>0 && count($list_lr)>0 && count($list_neto)>0 && count($list_kas)>0);

$id_neraca = !empty($_GET['id_neraca']) ? $_GET['id_neraca'] : ($list_neraca[0]['id'] ?? '');
$id_lr     = !empty($_GET['id_lr']) ? $_GET['id_lr'] : ($list_lr[0]['id'] ?? '');
$id_neto   = !empty($_GET['id_neto']) ? $_GET['id_neto'] : ($list_neto[0]['id'] ?? '');
$id_kas    = !empty($_GET['id_kas']) ? $_GET['id_kas'] : ($list_kas[0]['id'] ?? '');

$conf_n = []; $conf_lr = []; $conf_neto = []; $conf_kas = [];
if ($do_generate && $is_all_exist) {
    $conf_n = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=".(int)$id_neraca)->fetch_assoc();
    $conf_lr = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=".(int)$id_lr)->fetch_assoc();
    $conf_neto = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=".(int)$id_neto)->fetch_assoc();
    $conf_kas = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=".(int)$id_kas)->fetch_assoc();
}

$tgl_akhir_n = isset($conf_n['tgl_akhir']) ? $conf_n['tgl_akhir'] : date('Y-m-d');
$calk_snapshot_file = 'config/calk_snapshot.json';
$calk_fetch_url = "index.php?page=laporan_calk&tab=detail&tahun=" . date('Y', strtotime($tgl_akhir_n)); 

if ($do_generate && file_exists($calk_snapshot_file)) {
    $calk_snap = json_decode(file_get_contents($calk_snapshot_file), true);
    if ($calk_snap && is_array($calk_snap)) {
        $calk_fetch_url = "index.php?page=laporan_calk&tab=detail&" . http_build_query($calk_snap);
    }
}

if (!function_exists('renderKopSurat')) {
    function renderKopSurat($logo, $nama, $alamat, $telp, $email, $web) {
        $logoHtml = ($logo && file_exists($logo)) ? "<img src='$logo' style='max-height:75px; width:auto;'>" : "<div style='width:70px;height:70px;border:1px solid #000;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;'>LOGO</div>";
        return "
        <table width='100%' style='border-bottom: 3px solid #000; margin-bottom: 2px; page-break-inside: avoid;'>
            <tr>
                <td width='15%' style='text-align:left; padding-bottom:5px; vertical-align:middle;'>$logoHtml</td>
                <td width='70%' style='text-align:center; padding-bottom:5px; vertical-align:middle;'>
                    <div style='font-size: 14pt; font-weight: bold; text-transform: uppercase; color: #000; letter-spacing: 1px;'>$nama</div>
                    <div style='font-size: 9pt; color: #000; margin-top: 2px;'>$alamat</div>
                    <div style='font-size: 9pt; color: #000; margin-top: 1px;'>Telp: $telp | Email: $email | Web: $web</div>
                </td>
                <td width='15%'></td>
            </tr>
        </table>
        <div style='border-top: 1px solid #000; width: 100%; margin-bottom: 4mm;'></div>";
    }
}

if (!function_exists('renderDynamicSignature')) {
    function renderDynamicSignature($conf, $default_kota) {
        if(!$conf) return "";
        global $conn;
        $jenis = $conf['jenis_laporan'] ?? 'posisi_keuangan';
        $doc_type = 'KEUANGAN';
        if(strpos($jenis, 'aktivitas') !== false || strpos($jenis, 'laba') !== false) $doc_type = 'AKTIVITAS';
        elseif(strpos($jenis, 'aset_neto') !== false) $doc_type = 'ASET_NETO';
        elseif(strpos($jenis, 'kas') !== false) $doc_type = 'ARUS_KAS';
        
        $check = $conn->query("SHOW TABLES LIKE 'system_signatures'");
        if($check && $check->num_rows > 0) {
            $q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = '$doc_type' ORDER BY id ASC");
            $signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;
            if(!empty($signatures)) {
                $width = floor(100 / count($signatures)) . '%';
                $html = "<table width='100%' style='margin-top: 8mm; font-size: 10pt; page-break-inside: avoid; text-align: center;'><tr>";
                foreach($signatures as $sig) { $html .= "<td width='$width'>".htmlspecialchars($sig['sign_role'])."</td>"; }
                $html .= "</tr><tr>";
                foreach($signatures as $sig) {
                    $name = htmlspecialchars($sig['sign_name']) ?: '( ____________________ )';
                    $pos = htmlspecialchars($sig['sign_position']);
                    $html .= "<td><div style='border-bottom: 1px solid #000; margin: 40px auto 5px auto; width: 80%;'></div><b>$name</b><br><span>$pos</span></td>";
                }
                $html .= "</tr></table>";
                return $html;
            }
        }
        $kota = $conf['ttd_kota'] ?? $default_kota;
        $tgl_raw = $conf['ttd_tanggal'] ?? $conf['tgl_akhir'] ?? date('Y-m-d');
        $tgl = date('d M Y', strtotime($tgl_raw));
        $jabatan = $conf['ttd_jabatan'] ?? 'Pimpinan Institusi';
        $nama = $conf['ttd_nama'] ?? '.......................................';
        $nip = $conf['ttd_nip'] ?? '';
        
        return "
        <table width='100%' style='margin-top: 4mm; font-size: 9pt; page-break-inside: avoid;'>
            <tr><td width='60%'></td><td width='40%' style='text-align: center;'>$kota, $tgl<br>Mengetahui/Menyetujui<br><b>$jabatan</b><br><br><br><br><b><u>$nama</u></b><br>" . ($nip ? "NIP/NIK. $nip" : "") . "</td></tr>
        </table>";
    }
}

$arsip_list = $conn->query("SELECT id, tanggal_generate, nama_laporan, periode FROM arsip_laporan_konsolidasi ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
?>

<style>
    .report-preview-box { background: #525659; padding: 40px; min-height: 100vh; }
    .a4-paper { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto; padding: 10mm 15mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); color: #000; font-family: 'Times New Roman', Times, serif; position: relative; margin-bottom: 30px; box-sizing: border-box; }
    .page-break { page-break-after: always; }
    
    .cover-title { font-size: 20pt; font-weight: bold; text-align: center; margin-top: 50mm; line-height: 1.4; text-transform: uppercase; }
    .cover-subtitle { font-size: 11pt; text-align: center; margin-top: 8mm; }
    .h1-report { font-size: 12pt; font-weight: bold; text-align: center; margin-bottom: 4mm; text-transform: uppercase; line-height: 1.3; }
    .h2-report { font-size: 11pt; font-weight: bold; margin-bottom: 2mm; margin-top: 6mm;}
    
    .calk-text { text-align: justify; line-height: 1.5; font-size: 10pt; margin-bottom: 3mm; color: #000;}
    .calk-text table { width: 100% !important; border-collapse: collapse !important; margin: 10px 0; }
    .calk-text table td, .calk-text table th { border: 1px solid #000 !important; padding: 5px !important; color: #000 !important; }
    .calk-text hr { border-top: 1px solid #000 !important; opacity: 1 !important; margin: 10px 0; }
    .calk-text p { margin-bottom: 5px; }

    .injected-table-wrapper table { width: 100% !important; border-collapse: collapse !important; font-size: 9pt !important; table-layout: fixed !important; word-wrap: break-word !important; margin-bottom: 5mm !important; color: #000 !important; background: transparent !important; }
    .injected-table-wrapper table * { color: #000000 !important; text-decoration: none !important; }
    .injected-table-wrapper th { background-color: #fff !important; color: #000 !important; padding: 4px 6px !important; border-top: 2px solid #000 !important; border-bottom: 2px solid #000 !important; text-align: center !important; font-weight: bold !important; border-left: none !important; border-right: none !important; }
    .injected-table-wrapper th:first-child { text-align: left !important; padding-left: 15px !important; }
    .injected-table-wrapper td { padding: 3px 6px !important; vertical-align: middle !important; border: none !important; }
    .injected-table-wrapper td:not(:first-child), .injected-table-wrapper th:not(:first-child) { text-align: right !important; padding-right: 15px !important; }
    .injected-table-wrapper td:first-child { text-align: left !important; padding-left: 15px !important; }
    .injected-table-wrapper td.calk-indent { padding-left: 5mm !important; }
    
    .injected-table-wrapper tr { border-bottom: 1px dashed #e2e8f0 !important; }
    .injected-table-wrapper tr:last-child { border-bottom: none !important; }
    .injected-table-wrapper tr.border-top-thick td { border-top: 2px solid #000 !important; }
    .injected-table-wrapper tr.border-bottom-thick td { border-bottom: 2px solid #000 !important; }
    .injected-table-wrapper tr.double-underline td { border-bottom: 3px double #000 !important; }
    
    .injected-table-wrapper tr.border-top-single td { border-top: 1px solid #000 !important; }
    .injected-table-wrapper tr.border-bottom-single td { border-bottom: 1px solid #000 !important; }
    
    .injected-table-wrapper tr[class*="total"] td, .injected-table-wrapper tr[class*="subtotal"] td, .injected-table-wrapper td b, .injected-table-wrapper td strong { font-weight: bold !important; color: #000 !important; }
    .injected-table-wrapper tr[class*="grand"] td, .injected-table-wrapper tr.row-grand-total td { font-weight: bold !important; border-top: 2px solid #000 !important; border-bottom: 3px double #000 !important; background-color: #f1f5f9 !important; color: #000 !important; }

    .injected-table-wrapper table.tbl-asset th { background: #fff !important; color: #000 !important; border: 1px solid #000 !important; font-size: 7.5pt !important; }
    .injected-table-wrapper table.tbl-asset td { border: 1px solid #000 !important; color: #000 !important; font-size: 7.5pt !important; padding: 3px !important; }
    .injected-table-wrapper table.tbl-asset tr.row-cat-header td { background: #fff !important; font-size: 8pt !important; font-weight: bold !important; border-left: none !important;}
    .injected-table-wrapper table.tbl-asset tr.row-type-header td { background: #fff !important; font-style: italic !important; }
    .injected-table-wrapper table.tbl-asset tr.row-grand-total td { background: #f1f5f9 !important; color: #000 !important; font-weight: bold !important; font-size: 7.5pt !important; border-top: 2px solid #000 !important; border-bottom: 3px double #000 !important;}

    /* 🚀 CSS FORENSIC DRILL-DOWN (HANYA TERLIHAT DI LAYAR, ILANG SAAT CETAK) */
    @media screen {
        .drill-cursor { cursor: pointer; transition: 0.2s; position: relative; border-bottom: 1px dashed transparent; }
        .drill-cursor:hover { background: rgba(13, 110, 253, 0.05); border-radius: 4px; border-bottom: 1px dashed #0d6efd; }
        .drill-cursor:hover span { color: #0d6efd !important; font-weight: 900; }
        .drill-icon { display: none; position: absolute; left: -22px; top: 50%; transform: translateY(-50%); font-size: 11px; color: #0d6efd; }
        .drill-cursor:hover .drill-icon { display: block; }
    }

    /* 🚀 SKELETON UI ANIMATION */
    .skeleton-wrapper { width: 100%; border-radius: 8px; overflow: hidden; background: #fff; border: 1px solid #e2e8f0; margin-bottom: 20px;}
    .skeleton-row { display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid #f1f5f9; gap: 15px; }
    .skeleton-box { background: #e2e8f0; height: 16px; border-radius: 4px; position: relative; overflow: hidden; }
    .skeleton-box::after {
        content: ""; position: absolute; top: 0; left: -100%; width: 50%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.7), transparent);
        animation: shimmer 1.2s infinite ease-in-out;
    }
    @keyframes shimmer { 100% { left: 100%; } }

    @media print {
        body * { visibility: hidden; }
        .report-preview-box, .report-preview-box * { visibility: visible; }
        .report-preview-box { background: #fff; padding: 0; }
        .a4-paper { box-shadow: none; margin: 0; padding: 10mm; width: 100%; height: auto; }
        .no-print { display: none !important; }
    }
</style>

<div class="container-fluid py-4 no-print animate__animated animate__fadeIn text-dark">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 text-dark">
        <div class="mb-3 mb-md-0">
            <h4 class="fw-bold text-dark mb-1"><i class="fas fa-file-pdf text-danger me-2"></i>Konsolidator Laporan Terpadu</h4>
            <p class="text-muted small mb-0 fw-bold">Pure Base Engine & Archiver. Hasilkan Laporan dan bekukan ke dalam Riwayat Snapshot.</p>
        </div>
        <a href="index.php?page=laporan_keuangan" class="btn btn-dark rounded-pill px-4 shadow-sm fw-bold border-2"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-info-circle me-2 fa-lg"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <?php if(!$is_all_exist): ?>
        <div class="alert alert-warning border-warning border-opacity-25 rounded-4 shadow-sm mb-4">
            <h6 class="fw-bold mb-1"><i class="fas fa-exclamation-triangle me-2"></i>Laporan Belum Dibuat Lengkap!</h6>
            <p class="small mb-0">Sistem Konsolidasi ini mewajibkan Anda sudah mengeklik tombol <b>"Simpan Laporan"</b> di modul Neraca, Aktivitas, Aset Neto, dan Arus Kas minimal 1 kali.</p>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
        <h5 class="fw-bold text-dark mb-3"><i class="fas fa-history text-primary me-2"></i>Riwayat Laporan Konsolidasi</h5>
        <p class="small text-muted mb-3">Laporan yang sudah di-generate dan disimpan akan terkunci murni wujudnya di sini. Tidak akan berubah meski ada perubahan transaksi di masa depan.</p>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-muted text-uppercase fw-bold">
                    <tr>
                        <th class="ps-3">Tanggal Generate</th>
                        <th>Judul Laporan</th>
                        <th>Periode</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($arsip_list as $ar): ?>
                    <tr>
                        <td class="ps-3 fw-bold text-dark"><?= date('d/m/Y H:i', strtotime($ar['tanggal_generate'])) ?></td>
                        <td class="text-primary fw-bold"><?= htmlspecialchars($ar['nama_laporan']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($ar['periode']) ?></span></td>
                        <td class="text-end pe-3">
                            <div class="btn-group btn-group-sm rounded-pill border shadow-sm bg-white overflow-hidden">
                                <a href="index.php?page=generate_laporan&view_arsip=<?= $ar['id'] ?>" class="btn btn-white text-primary border-end px-3" title="Pratinjau Arsip"><i class="fas fa-eye"></i> Lihat</a>
                                <a href="print_konsolidasi.php?arsip_id=<?= $ar['id'] ?>" target="_blank" class="btn btn-white text-success border-end px-3" title="Cetak / Download PDF"><i class="fas fa-print"></i> Cetak</a>
                                <?php if(defined('RBAC_DEL') && RBAC_DEL): ?>
                                <a href="index.php?page=generate_laporan&action=delete_arsip&id=<?= $ar['id'] ?>" onclick="return confirm('Hapus arsip ini selamanya?')" class="btn btn-white text-danger px-3" title="Hapus"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($arsip_list)): ?>
                    <tr><td colspan="4" class="text-center py-5 text-muted italic">Belum ada riwayat laporan yang disimpan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 bg-white p-4 mb-4">
        <h6 class="fw-bold text-dark mb-3 border-bottom pb-2"><i class="fas fa-cogs text-muted me-2"></i>Pengaturan Generate Laporan Baru</h6>
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="generate_laporan">
            <input type="hidden" name="do_generate" value="1">
            
            <div class="col-md-6">
                <label class="small fw-bold text-muted mb-1">Judul Utama Laporan</label>
                <input type="text" name="report_title" class="form-control fw-bold border-0 bg-light shadow-sm" value="<?= htmlspecialchars($report_title) ?>">
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-muted mb-1">Nama Institusi (Header Cover)</label>
                <input type="text" name="inst_name" class="form-control fw-bold border-0 bg-light shadow-sm" value="<?= htmlspecialchars($inst_name) ?>">
            </div>

            <div class="col-12 mt-4"><h6 class="fw-bold text-primary border-bottom pb-2">Pilih Sumber Laporan yang Akan Dikloning</h6></div>
            
            <div class="col-md-3">
                <label class="small fw-bold text-dark mb-1">Posisi Keuangan (Neraca)</label>
                <select id="s_neraca" name="id_neraca" class="form-select border-0 shadow-sm bg-light fw-bold">
                    <?php if(empty($list_neraca)) echo "<option disabled selected>Belum Ada Laporan Dibuat</option>"; ?>
                    <?php foreach($list_neraca as $n) echo "<option value='{$n['id']}' ".($id_neraca==$n['id']?'selected':'').">{$n['dropdown_label']}</option>"; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-dark mb-1">Aktivitas (Laba/Rugi)</label>
                <select id="s_lr" name="id_lr" class="form-select border-0 shadow-sm bg-light fw-bold">
                    <?php if(empty($list_lr)) echo "<option disabled selected>Belum Ada Laporan Dibuat</option>"; ?>
                    <?php foreach($list_lr as $n) echo "<option value='{$n['id']}' ".($id_lr==$n['id']?'selected':'').">{$n['dropdown_label']}</option>"; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-dark mb-1">Perubahan Aset Neto</label>
                <select id="s_neto" name="id_neto" class="form-select border-0 shadow-sm bg-light fw-bold">
                    <?php if(empty($list_neto)) echo "<option disabled selected>Belum Ada Laporan Dibuat</option>"; ?>
                    <?php foreach($list_neto as $n) echo "<option value='{$n['id']}' ".($id_neto==$n['id']?'selected':'').">{$n['dropdown_label']}</option>"; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small fw-bold text-dark mb-1">Arus Kas</label>
                <select id="s_kas" name="id_kas" class="form-select border-0 shadow-sm bg-light fw-bold">
                    <?php if(empty($list_kas)) echo "<option disabled selected>Belum Ada Laporan Dibuat</option>"; ?>
                    <?php foreach($list_kas as $n) echo "<option value='{$n['id']}' ".($id_kas==$n['id']?'selected':'').">{$n['dropdown_label']}</option>"; ?>
                </select>
            </div>

            <div class="col-md-12 mt-4 border-top pt-3">
                 <label class="small fw-bold text-muted mb-1"><i class="fas fa-list-ol me-2 text-primary"></i>Kustomisasi Daftar Isi Laporan</label>
                 <textarea name="toc_text" class="form-control fw-bold border-0 bg-light shadow-sm" rows="3"><?= htmlspecialchars($toc_text) ?></textarea>
            </div>
            
            <div class="col-12 text-end d-flex gap-2 justify-content-end mt-4">
                <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" <?= !$is_all_exist ? 'disabled' : '' ?>><i class="fas fa-sync me-2"></i>GENERATE KONSOLIDASI</button>
                
                <?php if($do_generate && $is_all_exist): ?>
                <button type="button" id="btnSimpanLaporan" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm d-none" onclick="simpanLaporanKonsolidasi()" title="Simpan Wujud Laporan ke Riwayat">
                    <i class="fas fa-save me-2"></i>SIMPAN LAPORAN
                </button>

                <a href="print_konsolidasi.php?<?= http_build_query($_GET) ?>" target="_blank" id="btnPrint" class="btn btn-danger rounded-pill px-5 fw-bold shadow-sm d-none" title="Cetak ke PDF"><i class="fas fa-print me-2"></i>CETAK / SAVE PDF</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

</div>

<?php 
if ($view_arsip_id > 0): 
    $arsip_data = $conn->query("SELECT html_content FROM arsip_laporan_konsolidasi WHERE id = $view_arsip_id")->fetch_assoc();
?>
    <div class="d-flex justify-content-between mb-3 align-items-center no-print">
        <h5 class="fw-bold text-success mb-0"><i class="fas fa-archive me-2"></i>Mode Pratinjau Arsip</h5>
        <a href="print_konsolidasi.php?arsip_id=<?= $view_arsip_id ?>" target="_blank" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm"><i class="fas fa-print me-2"></i>CETAK ARSIP</a>
    </div>
    <div class="report-preview-box text-dark" id="master-report-area">
        <?= $arsip_data['html_content'] ?>
    </div>

<?php 
elseif($do_generate && $is_all_exist): 
    $plab_n = isset($conf_n['tgl_akhir']) ? date('d M Y', strtotime($conf_n['tgl_akhir'])) : date('d M Y');
    $plab_lr = isset($conf_lr['tgl_akhir']) ? date('d M Y', strtotime($conf_lr['tgl_akhir'])) : date('d M Y');
    $plab_neto = isset($conf_neto['tgl_akhir']) ? date('d M Y', strtotime($conf_neto['tgl_akhir'])) : date('d M Y');
    $plab_kas = isset($conf_kas['tgl_akhir']) ? date('d M Y', strtotime($conf_kas['tgl_akhir'])) : date('d M Y');
?>
<div class="report-preview-box text-dark" id="master-report-area">
    
    <!-- HALAMAN 1: COVER -->
    <div class="a4-paper page-break">
        <div style="text-align: center; margin-top: 60mm;">
            <?php if($logo_path): ?>
                <img src="<?= $logo_path ?>" style="max-height: 120px; width: auto; object-fit: contain;">
            <?php else: ?>
                <div style="width: 120px; height: 120px; border: 2px solid #000; border-radius: 50%; display: flex; align-items:center; justify-content:center; margin: 0 auto;"><b>LOGO<br>INSTITUSI</b></div>
            <?php endif; ?>
        </div>
        <div class="cover-title">
            <?= htmlspecialchars($report_title) ?><br>
            <?= htmlspecialchars($inst_name) ?>
        </div>
        <div class="cover-subtitle">
            Berdasarkan Periode Laporan Utama: <?= $plab_n ?><br>
            (Disusun berdasarkan ISAK 35)
        </div>
    </div>

    <!-- HALAMAN 2: DAFTAR ISI -->
    <div class="a4-paper page-break">
        <div class="h1-report" style="text-align: left !important; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px;">DAFTAR ISI</div>
        <table style="width: 100%; font-size: 11pt; line-height: 2; color:#000;">
            <?php 
            $toc_lines = explode("\n", trim($toc_text));
            $page_num = 3;
            foreach($toc_lines as $line) {
                if(trim($line)) {
                    echo "<tr><td>".htmlspecialchars(trim($line))."</td><td style='text-align: right; border-bottom:1px dotted #000;'>Hal. ".$page_num++."</td></tr>";
                }
            }
            ?>
        </table>
    </div>

    <!-- WRAPPER HALAMAN 3: POSISI KEUANGAN -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN POSISI KEUANGAN<br><span style="font-size:10pt; font-weight:normal;">Periode: <?= $plab_n ?></span></div>
        <div class="injected-table-wrapper" id="inj_neraca"></div>
    </div>

    <!-- WRAPPER HALAMAN 4: AKTIVITAS -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN AKTIVITAS<br><span style="font-size:10pt; font-weight:normal;">Periode: <?= $plab_lr ?></span></div>
        <div class="injected-table-wrapper" id="inj_lr"></div>
    </div>

    <!-- WRAPPER HALAMAN 5: ASET NETO -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN PERUBAHAN ASET NETO<br><span style="font-size:10pt; font-weight:normal;">Periode: <?= $plab_neto ?></span></div>
        <div class="injected-table-wrapper" id="inj_neto"></div>
    </div>

    <!-- WRAPPER HALAMAN 6: ARUS KAS -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN ARUS KAS<br><span style="font-size:10pt; font-weight:normal;">Periode: <?= $plab_kas ?></span></div>
        <div class="injected-table-wrapper" id="inj_kas"></div>
    </div>

    <!-- 🚀 CALK HALAMAN 1 (NARATIF) DISEDOT OLEH JS KE SINI -->
    <div class="a4-paper page-break text-dark">
        <div style='text-align:center; margin-bottom:8mm; page-break-inside: avoid; line-height:1.4;'>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'><?= $inst_name ?></div>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'>CATATAN ATAS LAPORAN KEUANGAN</div>
            <div style='font-size: 11pt; color:#000;'>Untuk Periode Berakhir <?= $plab_n ?></div>
        </div>
        
        <div class="injected-table-wrapper" id="inj_calk_naratif"></div>
    </div>

    <!-- 🚀 CALK HALAMAN 2 (TABEL NOMINAL & TANDA TANGAN) -->
    <div class="a4-paper text-dark">
        <div style='text-align:center; margin-bottom:8mm; page-break-inside: avoid; line-height:1.4;'>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'><?= $inst_name ?></div>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'>CATATAN ATAS LAPORAN KEUANGAN</div>
            <div style='font-size: 11pt; color:#000;'>Untuk Periode Berakhir <?= $plab_n ?></div>
        </div>
        
        <div class="injected-table-wrapper" id="inj_calk_tabel"></div>
        <?= renderDynamicSignature($conf_n, $kota_db) ?>
    </div>
</div>

<!-- 🚀 MODAL FORENSIC DRILL-DOWN (HANYA TERLIHAT DI LAYAR) -->
<div class="modal fade no-print" id="modalDrillDown" tabindex="-1" style="z-index: 99999;">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 text-dark">
            <div class="modal-header bg-dark text-white p-4 border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="modal-title fw-bold text-white mb-1" id="drillAccName"><i class="fas fa-search me-2 text-warning"></i>Menganalisa...</h5>
                    <small class="opacity-75 fw-bold"><i class="fas fa-history me-1"></i> Penelusuran Otomatis Jurnal Buku Besar (True Ledger Sync)</small>
                </div>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 bg-light">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                        <thead class="table-light text-muted small text-uppercase fw-bold">
                            <tr>
                                <th>Tanggal</th><th>No. Referensi</th><th class="text-start">Akun COA</th><th class="text-start">Uraian / Pihak</th><th class="text-end">Debit (Rp)</th><th class="text-end pe-4">Kredit (Rp)</th>
                            </tr>
                        </thead>
                        <tbody id="drillTableBody">
                            <!-- Skeleton Default for Drilldown -->
                        </tbody>
                        <tfoot class="bg-white fw-bold border-top" id="drillTableFoot">
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer p-3 border-0 bg-white text-center d-block">
                <button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold shadow-sm" data-bs-dismiss="modal">TUTUP JENDELA FORENSIK</button>
            </div>
        </div>
    </div>
</div>

<script>
    // 🚀 LAZY LOAD SKELETON UI GENERATOR
    function getSkeletonUI(msg) {
        return `
        <div class="text-center mb-3 text-primary fw-bold small animate__animated animate__pulse animate__infinite">
            <i class="fas fa-bolt me-2 text-warning"></i> ${msg}
        </div>
        <div class="skeleton-wrapper">
            <div class="skeleton-row" style="background:#f8fafc;"><div class="skeleton-box" style="width: 40%;"></div><div class="skeleton-box ms-auto" style="width: 20%;"></div></div>
            <div class="skeleton-row"><div class="skeleton-box" style="width: 30%;"></div><div class="skeleton-box ms-auto" style="width: 15%;"></div></div>
            <div class="skeleton-row"><div class="skeleton-box" style="width: 50%;"></div><div class="skeleton-box ms-auto" style="width: 15%;"></div></div>
            <div class="skeleton-row"><div class="skeleton-box" style="width: 35%;"></div><div class="skeleton-box ms-auto" style="width: 15%;"></div></div>
            <div class="skeleton-row"><div class="skeleton-box" style="width: 60%;"></div><div class="skeleton-box ms-auto" style="width: 15%;"></div></div>
            <div class="skeleton-row" style="background:#f8fafc; border-top: 2px solid #e2e8f0;"><div class="skeleton-box" style="width: 25%;"></div><div class="skeleton-box ms-auto" style="width: 20%;"></div></div>
        </div>`;
    }

    function updateSpinner(targetId, msg) {
        const target = document.getElementById(targetId);
        if(target) { target.innerHTML = getSkeletonUI(msg); }
    }

    // Initialize Skeleton UI before fetching
    document.addEventListener("DOMContentLoaded", function() { 
        updateSpinner('inj_neraca', 'Menarik Saldo Neraca...');
        updateSpinner('inj_lr', 'Menghitung Aktivitas & Laba Rugi...');
        updateSpinner('inj_neto', 'Menyelaraskan Aset Neto...');
        updateSpinner('inj_kas', 'Menganalisa Arus Kas Berjalan...');
        updateSpinner('inj_calk_naratif', 'Membaca Standar CALK...');
        updateSpinner('inj_calk_tabel', 'Merender Matriks Aset...');
        runExtraction(); 
    });

    // 🚀 PHANTOM IFRAME FETCHER
    async function fetchHtmlPhantom(url) {
        return new Promise((resolve) => {
            const iframe = document.createElement('iframe');
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            iframe.style.position = 'absolute';
            iframe.style.left = '-9999px';
            document.body.appendChild(iframe);

            let isResolved = false;

            iframe.onload = () => {
                if(isResolved) return;
                setTimeout(() => {
                    try {
                        const doc = iframe.contentDocument || iframe.contentWindow.document;
                        const html = doc.documentElement.innerHTML;
                        isResolved = true;
                        document.body.removeChild(iframe);
                        resolve(html);
                    } catch (e) {
                        isResolved = true;
                        document.body.removeChild(iframe);
                        resolve('');
                    }
                }, 1000); 
            };
            
            iframe.onerror = () => {
                if(!isResolved) {
                    isResolved = true;
                    document.body.removeChild(iframe);
                    resolve('');
                }
            };

            setTimeout(() => {
                if(!isResolved) {
                    isResolved = true;
                    document.body.removeChild(iframe);
                    resolve('');
                }
            }, 6000);
            
            iframe.src = url;
        });
    }

    async function runExtraction() {
        const id_n = <?= $id_neraca ? (int)$id_neraca : 'null' ?>;
        const id_lr = <?= $id_lr ? (int)$id_lr : 'null' ?>;
        const id_nt = <?= $id_neto ? (int)$id_neto : 'null' ?>;
        const id_ks = <?= $id_kas ? (int)$id_kas : 'null' ?>;
        const calkUrl = "<?= $calk_fetch_url ?>";

        try {
            if(id_n) { await fetchAndExtract('laporan_posisi_keuangan', id_n, 'inj_neraca'); }
            if(id_lr) { await fetchAndExtract('laporan_aktivitas', id_lr, 'inj_lr'); }
            if(id_nt) { await fetchAndExtract('laporan_perubahan_aset_neto', id_nt, 'inj_neto'); }
            if(id_ks) { await fetchAndExtract('laporan_kas_detail', id_ks, 'inj_kas'); }

            await fetchAndExtractCalk(calkUrl, 'inj_calk_naratif', 'inj_calk_tabel');
            
            const btnPrint = document.getElementById('btnPrint');
            if(btnPrint) btnPrint.classList.remove('d-none');
            
            const btnSimpan = document.getElementById('btnSimpanLaporan');
            if(btnSimpan) btnSimpan.classList.remove('d-none');

            const finishMsg = document.createElement('div');
            finishMsg.className = "no-print text-center text-success fw-bold py-5 animate__animated animate__fadeInUp";
            finishMsg.innerHTML = '<i class="fas fa-check-circle fa-4x mb-3 d-block"></i>Konsolidasi Berhasil!<br>Seluruh Dokumen Siap Dicetak. Anda disarankan menekan tombol "SIMPAN LAPORAN" di atas agar wujud laporan ini terkunci secara permanen di riwayat.';
            document.getElementById('master-report-area').appendChild(finishMsg);

        } catch (e) {
            console.error("Extraction Error: ", e);
            alert("Terjadi kesalahan saat mengekstrak laporan. Pastikan koneksi internet stabil.");
        }
    }

    function simpanLaporanKonsolidasi() {
        const reportHtml = document.getElementById('master-report-area').innerHTML;
        const title = document.querySelector('input[name="report_title"]').value;
        const periode = "<?= $plab_n ?? '' ?>"; 
        
        const formData = new FormData();
        formData.append('action', 'save_arsip_konsolidasi');
        formData.append('nama_laporan', title);
        formData.append('periode', periode);
        formData.append('html_content', reportHtml);
        
        const btn = document.getElementById('btnSimpanLaporan');
        const oriText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Membekukan Laporan...';
        btn.disabled = true;
        
        fetch('index.php?page=generate_laporan', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if(res.status === 'success') {
                alert('Laporan berhasil dibekukan dan disimpan secara utuh ke riwayat!');
                window.location.href = 'index.php?page=generate_laporan';
            }
        }).catch(e => {
            alert("Gagal menyimpan laporan.");
            btn.innerHTML = oriText;
            btn.disabled = false;
        });
    }

    // 🚀 OMNI FORENSIC DRILL-DOWN JAVASCRIPT (True Ledger Resolver)
    function openDrillDown(encodedKeyword) {
        const keyword = decodeURIComponent(encodedKeyword);
        const modal = new bootstrap.Modal(document.getElementById('modalDrillDown'));
        
        document.getElementById('drillAccName').innerHTML = `<i class="fas fa-search me-2 text-warning"></i>Menganalisa: <b>${keyword}</b>...`;
        
        // SKELETON UI FOR DRILL-DOWN
        document.getElementById('drillTableBody').innerHTML = `
            <tr><td colspan="6" class="p-0 border-0">
                <div class="skeleton-wrapper border-0 m-0 rounded-0">
                    <div class="skeleton-row"><div class="skeleton-box" style="width: 15%;"></div><div class="skeleton-box" style="width: 30%;"></div><div class="skeleton-box ms-auto" style="width: 20%;"></div></div>
                    <div class="skeleton-row"><div class="skeleton-box" style="width: 15%;"></div><div class="skeleton-box" style="width: 40%;"></div><div class="skeleton-box ms-auto" style="width: 20%;"></div></div>
                    <div class="skeleton-row"><div class="skeleton-box" style="width: 15%;"></div><div class="skeleton-box" style="width: 25%;"></div><div class="skeleton-box ms-auto" style="width: 20%;"></div></div>
                </div>
            </td></tr>`;
        document.getElementById('drillTableFoot').innerHTML = '';
        modal.show();

        const year = <?= isset($tgl_akhir_n) ? date('Y', strtotime($tgl_akhir_n)) : date('Y') ?>;
        
        fetch(`ajax_drilldown.php?keyword=${encodeURIComponent(keyword)}&tahun=${year}`)
        .then(r => r.json())
        .then(res => {
            if(res.status !== 'success') {
                document.getElementById('drillTableBody').innerHTML = `<tr><td colspan="6" class="text-center py-5 text-danger fw-bold"><i class="fas fa-exclamation-triangle me-2"></i>${res.msg}</td></tr>`;
                return;
            }
            
            document.getElementById('drillAccName').innerHTML = `<i class="fas fa-file-invoice-dollar me-2 text-warning"></i>Hasil Analisis: <b>${keyword}</b>`;
            
            let html = `
                <tr class="bg-light fw-bold text-primary">
                    <td colspan="4" class="text-start ps-3 text-uppercase">SALDO AWAL (KUMULATIF SEBELUM ${year})</td>
                    <td colspan="2" class="text-end pe-4 fs-6">Rp ${new Intl.NumberFormat('id-ID').format(Math.abs(res.saldo_awal))}</td>
                </tr>
            `;

            if(res.data.length === 0) {
                html += '<tr><td colspan="6" class="text-center py-5 text-muted fst-italic">Tidak ada mutasi buku besar pada tahun ini. Angka berasal murni dari akumulasi Saldo Awal.</td></tr>';
            } else {
                res.data.forEach(d => {
                    let dStr = d.debit > 0 ? new Intl.NumberFormat('id-ID').format(d.debit) : '-';
                    let kStr = d.kredit > 0 ? new Intl.NumberFormat('id-ID').format(d.kredit) : '-';
                    
                    let dateObj = new Date(d.tgl_jurnal);
                    let formattedDate = dateObj.toLocaleDateString('id-ID', {day: '2-digit', month: 'short', year: 'numeric'});
                    
                    html += `<tr>
                        <td class="text-muted small">${formattedDate}</td>
                        <td><code class="text-dark bg-light px-2 py-1 rounded border">${d.no_jurnal}</code></td>
                        <td class="text-start"><div class="fw-bold text-primary small">${d.nama_akun}</div><code style="font-size:10px;">${d.kode_akun}</code></td>
                        <td class="text-start">
                            <div class="fw-bold text-dark">${d.pihak_nama || 'Umum'}</div>
                            <div class="small text-muted text-truncate" style="max-width:250px;" title="${d.keterangan}">${d.keterangan}</div>
                        </td>
                        <td class="text-end text-success fw-bold">${dStr}</td>
                        <td class="text-end pe-4 text-danger fw-bold">${kStr}</td>
                    </tr>`;
                });
            }
            document.getElementById('drillTableBody').innerHTML = html;
            
            let totD = new Intl.NumberFormat('id-ID').format(res.mutasi_d);
            let totK = new Intl.NumberFormat('id-ID').format(res.mutasi_k);
            let netStr = new Intl.NumberFormat('id-ID').format(Math.abs(res.saldo_akhir));
            
            document.getElementById('drillTableFoot').innerHTML = `
                <tr class="bg-white border-top">
                    <td colspan="4" class="text-end text-uppercase text-muted small py-3">Total Mutasi Transaksi Tahun ${year}</td>
                    <td class="text-end text-success">${totD}</td>
                    <td class="text-end pe-4 text-danger">${totK}</td>
                </tr>
                <tr class="bg-dark text-white">
                    <td colspan="4" class="text-end text-uppercase py-3">SALDO AKHIR BUKU BESAR (SINKRON LAPORAN)</td>
                    <td colspan="2" class="text-end pe-4 fs-5 text-white">Rp ${netStr}</td>
                </tr>
            `;
        })
        .catch(e => {
            document.getElementById('drillTableBody').innerHTML = `<tr><td colspan="6" class="text-center py-5 text-danger fw-bold"><i class="fas fa-wifi me-2"></i> Gagal terhubung ke server Forensik.</td></tr>`;
        });
    }

    // 🚀 THE PHANTOM EXTRACTOR ENGINE
    async function fetchAndExtract(modulePage, targetId, containerId) {
        const target = document.getElementById(containerId);
        try {
            if (!targetId) throw new Error("ID Laporan kosong.");

            let validTable = null;
            let maxRowsFound = 0;

            const extractTableFromHtml = (html) => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const tables = doc.querySelectorAll('table');
                let bestTable = null;
                let maxScore = -99999;

                tables.forEach(t => {
                    let score = t.innerText.length + (t.rows.length * 100);
                    const txt = t.innerText.toLowerCase();

                    if (txt.includes('aksi') && (txt.includes('judul laporan') || txt.includes('periode'))) score -= 100000;
                    if (txt.includes('tambah data') || txt.includes('tambah laporan')) score -= 100000;
                    if (txt.includes('menampilkan') && txt.includes('data')) score -= 10000; 

                    if (txt.includes('saldo') || txt.includes('arus kas') || txt.includes('aset neto') || txt.includes('pendapatan') || txt.includes('rp')) {
                        score += 5000;
                    }

                    if (score > maxScore && t.rows.length >= 2) {
                        maxScore = score;
                        bestTable = t.cloneNode(true); 
                    }
                });
                
                return { table: (maxScore > 0 ? bestTable : null), rows: (bestTable ? bestTable.rows.length : 0) };
            };

            let viewUrl = null;
            const listHtml = await fetchHtmlPhantom(`index.php?page=${modulePage}`);
            if (listHtml) {
                const listDoc = new DOMParser().parseFromString(listHtml, 'text/html');
                const elements = listDoc.querySelectorAll('a[href], button[onclick], a[onclick]');

                for (let el of elements) {
                    let href = el.getAttribute('href');
                    if (!href) {
                        const onclick = el.getAttribute('onclick');
                        if (onclick) {
                            const match = onclick.match(/['"]([^'"]+)['"]/);
                            if (match) href = match[1];
                        }
                    }
                    if (!href || href === '#' || href.includes('javascript:')) continue;
                    
                    const hLow = href.toLowerCase();
                    const regex = new RegExp(`[?&][a-zA-Z0-9_]*id[a-zA-Z0-9_]*=${targetId}(&|$)`, 'i');
                    
                    if (regex.test(href) && !hLow.includes('delete') && !hLow.includes('hapus') && !hLow.includes('edit')) {
                        viewUrl = href;
                        if (hLow.includes('view') || hLow.includes('detail') || hLow.includes('cetak') || hLow.includes('print') || hLow.includes('render')) break;
                    }
                }
            }

            let reportHtml = '';
            
            if (viewUrl) {
                let actualUrl = viewUrl;
                if (!actualUrl.startsWith('http')) {
                    if (actualUrl.startsWith('?')) actualUrl = 'index.php' + actualUrl;
                    else if (!actualUrl.includes('.php')) actualUrl = 'index.php?' + actualUrl;
                }
                reportHtml = await fetchHtmlPhantom(actualUrl);
            } else {
                reportHtml = await fetchHtmlPhantom(`index.php?page=${modulePage}&view=detail&id=${targetId}`);
            }

            const ext = extractTableFromHtml(reportHtml);
            if (ext.table && ext.rows >= 2) {
                validTable = ext.table;
                maxRowsFound = ext.rows;
            }

            if (!validTable || maxRowsFound < 2) {
                throw new Error("Gagal mengambil data laporan dari server. Pastikan data sudah disimpan di menu sumber.");
            }

            // --- PROSES PEMBERSIHAN & FORMATTING UI ---
            validTable.classList.remove('table-bordered', 'table-striped', 'table-hover', 'table', 'table-light', 'table-dark');

            validTable.querySelectorAll('.shadow-sm, .rounded-4, .border, button, a.btn, .no-print, form, input, select, textarea').forEach(el => {
                el.classList.remove('shadow-sm', 'rounded-4', 'border');
                if(['BUTTON', 'FORM', 'INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName) || el.classList.contains('no-print') || el.classList.contains('btn')) el.remove();
            });
            
            validTable.querySelectorAll('a').forEach(a => {
                const textNode = document.createTextNode(a.textContent.trim());
                a.parentNode.replaceChild(textNode, a);
            });
            validTable.querySelectorAll('i').forEach(i => i.remove());
            
            validTable.querySelectorAll('*').forEach(el => {
                el.style.color = '#000000';
                el.style.backgroundColor = ''; 
                el.classList.remove('text-primary', 'text-danger', 'text-success', 'text-info', 'text-warning', 'bg-light', 'table-light', 'bg-white', 'bg-primary', 'bg-dark', 'text-white');
            });
            
            let catatanIdx = -1;
            const firstRowThs = validTable.querySelectorAll('th');
            firstRowThs.forEach((th, idx) => {
                if (th.innerText.toLowerCase().trim() === 'catatan') {
                    catatanIdx = idx;
                }
            });

            const rows = validTable.querySelectorAll('tr');
            rows.forEach((tr, index) => {
                const rowText = tr.innerText.toUpperCase().trim();
                
                let firstTd = tr.querySelector('td:first-child');
                let rowName = firstTd ? firstTd.innerText.trim().replace(/\n/g, ' ').replace(/\s+/g, ' ') : '';
                
                if (rowText === '') { tr.style.display = 'none'; return; }

                if (rowText.includes('KENAIKAN ASET NETO') || 
                    rowText.includes('PENURUNAN ASET NETO') || 
                    rowText.includes('KAS DAN SETARA KAS AKHIR') || 
                    rowText.includes('TOTAL ASET NETO AKHIR') ||
                    rowText === 'TOTAL ASET') {
                    
                    tr.classList.add('row-grand-total');
                    tr.innerHTML = tr.innerHTML.replace(/<br\s*[\/]?>/gi, '');
                    
                    let prevRow = tr.previousElementSibling;
                    while (prevRow && prevRow.innerText.trim() === '') {
                        prevRow.style.display = 'none';
                        prevRow = prevRow.previousElementSibling;
                    }
                }

                const separatorKeywords = [
                    'ARUS KAS BERSIH DARI AKTIVITAS OPERASI',
                    'ARUS KAS BERSIH DARI AKTIVITAS INVESTASI',
                    'ARUS KAS BERSIH DARI AKTIVITAS PENDANAAN',
                    'PENYESUAIAN SELISIH KAS',
                    'KAS PADA AWAL PERIODE',
                    'JUMLAH ASET LANCAR',
                    'JUMLAH ASET TIDAK LANCAR',
                    'TOTAL LIABILITAS',
                    'TOTAL ASET NETO',
                    'TOTAL PENDAPATAN',
                    'TOTAL BEBAN',
                    'ASET NETO TANPA PEMBATASAN AKHIR',
                    'ASET NETO DENGAN PEMBATASAN AKHIR'
                ];

                let needSeparatorTop = false;
                for (let kw of separatorKeywords) {
                    if (rowText.includes(kw) && !rowText.includes('TOTAL MUTASI & SALDO AKHIR') && !rowText.includes('TOTAL ASET NETO AKHIR')) {
                        needSeparatorTop = true; break;
                    }
                }

                if (needSeparatorTop) { tr.classList.add('border-top-single'); }

                if (rowText.includes('TOTAL PENDAPATAN')) {
                    tr.classList.add('border-top-single');
                    tr.classList.add('border-bottom-single');
                    tr.style.fontWeight = 'bold';
                }

                tr.querySelectorAll('td').forEach(td => {
                    let txtC = td.innerText.toUpperCase().trim();
                    if (txtC === 'TOTAL LIABILITAS') { td.innerText = 'Total Liabilitas'; td.style.fontWeight = 'bold'; }
                    if (txtC === 'TOTAL ASET NETO') { td.innerText = 'Total Aset Neto'; td.style.fontWeight = 'bold'; }
                });

                if (rowText.includes('SURPLUS DARI TANPA PEMBATASAN') || rowText.includes('SURPLUS DARI DENGAN PEMBATASAN') || rowText.includes('B. DENGAN PEMBATASAN') || rowText.includes('ARUS KAS DARI AKTIVITAS') || rowText.includes('KENAIKAN (PENURUNAN) BERSIH')) {
                    tr.classList.add('border-top-thick'); tr.style.fontWeight = 'bold'; 
                }

                const tds = tr.querySelectorAll('td');
                tds.forEach((td, colIndex) => {
                    if (colIndex > 0 && colIndex !== catatanIdx && !td.classList.contains('col-catatan')) {
                        let txt = td.innerText.trim();
                        let isRightAligned = td.classList.contains('text-end') || td.classList.contains('text-right') || td.style.textAlign === 'right' || td.getAttribute('align') === 'right';
                        let isNumberFormat = /^[\(\-]?\s*\d{1,3}(?:\.\d{3})*(?:,\d+)?\s*[\)]?$/.test(txt.replace(/^(Rp|IDR)\s*/i, '').trim());

                        if (txt !== '-' && txt !== '' && (isRightAligned || isNumberFormat || txt.includes('Rp'))) {
                            if (txt.match(/^\d+\-\d+$/)) return;
                            
                            let cleanNum = txt.replace(/^(Rp|IDR)\.?\s*/i, '').trim();
                            td.innerHTML = ''; td.className = 'text-end pe-3'; td.style.textAlign = 'right'; td.style.verticalAlign = 'middle';
                            
                            let drillAttr = rowName ? `onclick="openDrillDown('${encodeURIComponent(rowName)}')" title="Klik untuk Rincian Forensik: ${rowName}"` : '';
                            let drillClass = rowName ? 'drill-cursor' : '';
                            let drillIcon = rowName ? `<i class="fas fa-search-plus drill-icon no-print"></i>` : '';
                            
                            td.innerHTML = `<div class="${drillClass}" ${drillAttr} style="display: inline-flex; justify-content: space-between; width: 125px; margin-left: auto; text-align: right;">
                                ${drillIcon}
                                <span style="text-align: left; pointer-events: none;">Rp</span>
                                <span style="text-align: right; pointer-events: none;">${cleanNum}</span>
                            </div>`;
                        }
                    }
                });
            });

            validTable.classList.add('tbl-report'); validTable.removeAttribute('style'); target.innerHTML = validTable.outerHTML;

        } catch(e) { 
            target.innerHTML = `<div style='text-align:center;color:red;padding:20px;'>
                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br>
                <b>Gagal Memuat Laporan</b><br><small>${e.message}</small>
            </div>`; 
        }
    }

    async function fetchAndExtractCalk(url, targetNarId, targetTabId) {
        const targetNar = document.getElementById(targetNarId);
        const targetTab = document.getElementById(targetTabId);
        try {
            const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if(!res.ok) throw new Error("HTTP " + res.status);
            const html = await res.text();
            
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            const calkNar = doc.getElementById('calk-naratif-container');
            const calkTab = doc.getElementById('calk-tabel-container');
            
            if (calkNar && calkTab) {
                const cleanUp = (node) => {
                    node.querySelectorAll('.shadow-sm, .rounded-4, .border, button, a.btn, .no-print').forEach(el => {
                        el.classList.remove('shadow-sm', 'rounded-4', 'border');
                        if(['BUTTON', 'FORM'].includes(el.tagName) || el.classList.contains('no-print') || el.classList.contains('btn')) el.remove();
                    });
                    node.querySelectorAll('a').forEach(a => {
                        const textNode = document.createTextNode(a.textContent.trim());
                        a.parentNode.replaceChild(textNode, a);
                    });
                    
                    node.querySelectorAll('i.fas, i.fa, i.far').forEach(i => i.remove());
                    
                    node.querySelectorAll('*').forEach(el => {
                        el.style.color = '#000000'; el.style.backgroundColor = '';
                        el.classList.remove('text-primary', 'text-danger', 'text-success', 'text-info', 'text-warning', 'bg-light', 'table-light', 'bg-white', 'bg-primary', 'bg-dark', 'text-white', 'd-none');
                    });
                    return node.innerHTML;
                };
                
                targetNar.innerHTML = cleanUp(calkNar);
                targetTab.innerHTML = cleanUp(calkTab);
                
                targetTab.querySelectorAll('h6').forEach(h6 => {
                    if(h6.innerText.toLowerCase().includes('kategori baru') || h6.innerText.toLowerCase().includes('kategori calk baru') || h6.innerText.trim() === '') {
                        h6.remove();
                    }
                });

            } else { throw new Error("Format kontainer CALK tidak terdeteksi dari sumber."); }
        } catch(e) { 
            const errHtml = `<div style='text-align:center;color:red;padding:20px;'><b>Gagal Memuat: ${e.message}</b></div>`;
            targetNar.innerHTML = errHtml; targetTab.innerHTML = errHtml; 
        }
    }
</script>
<?php endif; ?>