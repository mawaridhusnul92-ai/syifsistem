<?php
/**
 * print_konsolidasi.php - ISOLATED PRINT ENGINE
 * Versi: 9.4 (Enterprise Absolute Alignment Print Edition)
 * Perbaikan Mutlak: 
 * Menyelaraskan script cetak murni menggunakan width tetap pada elemen 
 * "Rp" agar saat diprint (PDF), posisinya sejajar absolut di semua laporan.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak."); }

$arsip_id = isset($_GET['arsip_id']) ? (int)$_GET['arsip_id'] : 0;

if ($arsip_id > 0) {
    $arsip_data = $conn->query("SELECT html_content FROM arsip_laporan_konsolidasi WHERE id = $arsip_id")->fetch_assoc();
    if ($arsip_data) {
        $html_content = $arsip_data['html_content'];
    } else {
        die("<h3 style='text-align:center; padding:50px; font-family:sans-serif;'>Arsip laporan tidak ditemukan.</h3>");
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Cetak Arsip Konsolidasi</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Times+New+Roman&display=swap');
            body, .a4-paper, table, td, th, div, span, p, h1, h2, h3, h4, h5, h6, strong, b, code { font-family: 'Times New Roman', Times, serif !important; }
            body { background: #525659; margin: 0; padding: 20px; color: #000; }
            .a4-paper { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto; padding: 8mm 12mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); position: relative; margin-bottom: 20px; box-sizing: border-box; }
            .page-break { page-break-after: always; }
            .cover-title { font-size: 20pt; font-weight: bold; text-align: center; margin-top: 50mm; line-height: 1.4; text-transform: uppercase; }
            .cover-subtitle { font-size: 11pt; text-align: center; margin-top: 8mm; }
            .h1-report { font-size: 11pt; font-weight: bold; text-align: center; margin-bottom: 2mm; text-transform: uppercase; line-height: 1.2; }
            .h2-report { font-size: 10pt; font-weight: bold; margin-bottom: 2mm; margin-top: 4mm;}
            .calk-text { text-align: justify; line-height: 1.4; font-size: 9.5pt; margin-bottom: 2mm; color: #000;}
            .calk-text p { margin-bottom: 5px; }
            .calk-text table { width: 100% !important; border-collapse: collapse !important; margin: 10px 0; }
            .calk-text table td, .calk-text table th { border: 1px solid #000 !important; padding: 5px !important; color: #000 !important; }
            .calk-text hr { border-top: 1px solid #000 !important; opacity: 1 !important; margin: 10px 0; }
            
            .injected-table-wrapper table { width: 100% !important; border-collapse: collapse !important; font-size: 8.5pt !important; table-layout: fixed !important; word-wrap: break-word !important; margin-bottom: 2mm !important; color: #000 !important; background: transparent !important; }
            .injected-table-wrapper table * { color: #000000 !important; text-decoration: none !important; }
            .injected-table-wrapper table, .injected-table-wrapper th, .injected-table-wrapper td { border-color: #000 !important; }
            .injected-table-wrapper th { background-color: #fff !important; color: #000 !important; padding: 2px 4px !important; border-top: 2px solid #000 !important; border-bottom: 2px solid #000 !important; text-align: center !important; font-weight: bold !important; border-left: none !important; border-right: none !important; }
            .injected-table-wrapper th:first-child { text-align: left !important; padding-left: 10px !important; }
            .injected-table-wrapper td { padding: 2px 4px !important; vertical-align: middle !important; border: none !important; line-height: 1.1 !important; }
            .injected-table-wrapper td:not(:first-child), .injected-table-wrapper th:not(:first-child) { text-align: right !important; padding-right: 10px !important; }
            .injected-table-wrapper td:first-child { text-align: left !important; padding-left: 10px !important; }
            .injected-table-wrapper td.calk-indent { padding-left: 5mm !important; }
            .injected-table-wrapper tr { border-bottom: 1px dashed #e2e8f0 !important; }
            .injected-table-wrapper tr:last-child { border-bottom: none !important; }
            .injected-table-wrapper tr.border-top-thick td { border-top: 2px solid #000 !important; }
            .injected-table-wrapper tr.border-bottom-thick td { border-bottom: 2px solid #000 !important; }
            .injected-table-wrapper tr.double-underline td { border-bottom: 3px double #000 !important; }
            .injected-table-wrapper tr[class*="total"] td, .injected-table-wrapper tr[class*="subtotal"] td, .injected-table-wrapper td b, .injected-table-wrapper td strong { font-weight: bold !important; color: #000 !important; }
            .injected-table-wrapper tr[class*="grand"] td, .injected-table-wrapper tr.row-grand-total td { font-weight: bold !important; border-top: 2px solid #000 !important; border-bottom: 3px double #000 !important; background-color: #f1f5f9 !important; color: #000 !important; }
            .injected-table-wrapper table.tbl-asset th { background: #fff !important; color: #000 !important; border: 1px solid #000 !important; font-size: 7pt !important; }
            .injected-table-wrapper table.tbl-asset td { border: 1px solid #000 !important; color: #000 !important; font-size: 7pt !important; padding: 2px !important; }
            .injected-table-wrapper table.tbl-asset tr.row-cat-header td { background: #fff !important; font-size: 7.5pt !important; font-weight: bold !important; border-left: none !important;}
            .injected-table-wrapper table.tbl-asset tr.row-type-header td { background: #fff !important; font-style: italic !important; }
            .injected-table-wrapper table.tbl-asset tr.row-grand-total td { background: #f1f5f9 !important; color: #000 !important; font-weight: bold !important; font-size: 7pt !important; border-top: 2px solid #000 !important; border-bottom: 3px double #000 !important;}

            @media print {
                @page { size: A4 portrait; margin: 0; }
                body { background: #fff; padding: 0; margin: 0; }
                .report-preview-box { padding: 0; background: #fff; }
                .a4-paper { box-shadow: none; margin: 0 auto; padding: 5mm 10mm !important; width: 100%; height: auto !important; min-height: auto !important; page-break-after: always; }
                .a4-paper:last-child, .a4-paper:last-of-type { page-break-after: auto !important; }
                * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="report-preview-box text-dark" id="master-report-area">
            <?= $html_content ?>
        </div>
        <script>
            setTimeout(() => { window.print(); }, 1000);
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================================
// KODE NORMAL (MENCETAK DARI GENERATE LAYAR LANGSUNG)
// ==========================================================

$q_app = $conn->query("SELECT * FROM system_profile WHERE id=1");
$app = $q_app ? $q_app->fetch_assoc() : null;

$logo_filename = $app['logo'] ?? '';
$logo_path = (!empty($logo_filename)) ? "assets/img/" . $logo_filename : "";
$inst_name = $_GET['inst_name'] ?? ($app['institution_name'] ?? 'STIKes YARSI PONTIANAK');
$alamat_db = $app['address'] ?? 'Jl. Letjen Sutoyo, Kota Pontianak, Kalimantan Barat';
$telp_db = $app['phone'] ?? '(0561) 123456';
$email_db = $app['email'] ?? 'info@stikesyarsi.ac.id';
$web_db = $app['website'] ?? 'www.stikesyarsi.ac.id';
$kota_db = $app['city'] ?? 'Pontianak';

$report_title = $_GET['report_title'] ?? 'LAPORAN KEUANGAN KONSOLIDASIAN';
$toc_text = $_GET['toc_text'] ?? '';

$id_neraca = (int)($_GET['id_neraca'] ?? 0);
$id_lr     = (int)($_GET['id_lr'] ?? 0);
$id_neto   = (int)($_GET['id_neto'] ?? 0);
$id_kas    = (int)($_GET['id_kas'] ?? 0);

if (!$id_neraca || !$id_lr || !$id_neto || !$id_kas) {
    die("<h3 style='text-align:center; padding:50px;'>Data laporan sumber tidak lengkap.</h3>");
}

$conf_n = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=$id_neraca")->fetch_assoc();
$conf_lr = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=$id_lr")->fetch_assoc();
$conf_neto = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=$id_neto")->fetch_assoc();
$conf_kas = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id=$id_kas")->fetch_assoc();

$plab_n = (is_array($conf_n) && !empty($conf_n['tgl_akhir'])) ? date('d M Y', strtotime($conf_n['tgl_akhir'])) : date('d M Y');
$plab_lr = (is_array($conf_lr) && !empty($conf_lr['tgl_akhir'])) ? date('d M Y', strtotime($conf_lr['tgl_akhir'])) : date('d M Y');
$plab_neto = (is_array($conf_neto) && !empty($conf_neto['tgl_akhir'])) ? date('d M Y', strtotime($conf_neto['tgl_akhir'])) : date('d M Y');
$plab_kas = (is_array($conf_kas) && !empty($conf_kas['tgl_akhir'])) ? date('d M Y', strtotime($conf_kas['tgl_akhir'])) : date('d M Y');

$tgl_akhir_n = isset($conf_n['tgl_akhir']) ? $conf_n['tgl_akhir'] : date('Y-m-d');
$calk_snapshot_file = 'config/calk_snapshot.json';
$calk_fetch_url = "index.php?page=laporan_calk&tab=detail&tahun=" . date('Y', strtotime($tgl_akhir_n)); 

if (file_exists($calk_snapshot_file)) {
    $calk_snap = json_decode(file_get_contents($calk_snapshot_file), true);
    if ($calk_snap && is_array($calk_snap)) {
        $calk_fetch_url = "index.php?page=laporan_calk&tab=detail&" . http_build_query($calk_snap);
    }
}

function renderKopSurat($logo, $nama, $alamat, $telp, $email, $web) {
    $logoHtml = ($logo && file_exists($logo)) ? "<img src='$logo' style='max-height:75px; width:auto;'>" : "<div style='width:70px;height:70px;border:1px solid #000;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:bold;'>LOGO</div>";
    return "
    <table width='100%' style='border-bottom: 3px solid #000; margin-bottom: 2px; page-break-inside: avoid;'>
        <tr>
            <td width='15%' style='text-align:left; padding-bottom:5px; vertical-align:middle;'>$logoHtml</td>
            <td width='70%' style='text-align:center; padding-bottom:5px; vertical-align:middle;'>
                <div style='font-size: 14pt; font-weight: bold; text-transform: uppercase; color: #000; letter-spacing: 1px;'>$nama</div>
                <div style='font-size: 8.5pt; color: #000; margin-top: 2px;'>$alamat</div>
                <div style='font-size: 8.5pt; color: #000; margin-top: 1px;'>Telp: $telp | Email: $email | Web: $web</div>
            </td>
            <td width='15%'></td>
        </tr>
    </table>
    <div style='border-top: 1px solid #000; width: 100%; margin-bottom: 4mm;'></div>";
}

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
                $html .= "<td><div style='border-bottom: 1px solid #000; margin: 30px auto 3px auto; width: 80%;'></div><b>$name</b><br><span>$pos</span></td>";
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Laporan Konsolidasi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Times+New+Roman&display=swap');
        
        body, .a4-paper, table, td, th, div, span, p, h1, h2, h3, h4, h5, h6, strong, b, code { 
            font-family: 'Times New Roman', Times, serif !important; 
        }

        body { background: #525659; margin: 0; padding: 20px; color: #000; }
        .a4-paper { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto; padding: 8mm 12mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); position: relative; margin-bottom: 20px; box-sizing: border-box; }
        .page-break { page-break-after: always; }
        
        .cover-title { font-size: 20pt; font-weight: bold; text-align: center; margin-top: 50mm; line-height: 1.4; text-transform: uppercase; }
        .cover-subtitle { font-size: 11pt; text-align: center; margin-top: 8mm; }
        
        .h1-report { font-size: 11pt; font-weight: bold; text-align: center; margin-bottom: 2mm; text-transform: uppercase; line-height: 1.2; }
        .h2-report { font-size: 10pt; font-weight: bold; margin-bottom: 2mm; margin-top: 4mm;}
        
        .calk-text { text-align: justify; line-height: 1.4; font-size: 9.5pt; margin-bottom: 2mm; color: #000;}
        .calk-text p { margin-bottom: 5px; }
        .calk-text table { width: 100% !important; border-collapse: collapse !important; margin: 10px 0; }
        .calk-text table td, .calk-text table th { border: 1px solid #000 !important; padding: 5px !important; color: #000 !important; }
        .calk-text hr { border-top: 1px solid #000 !important; opacity: 1 !important; margin: 10px 0; }

        .injected-table-wrapper table { width: 100% !important; border-collapse: collapse !important; font-size: 8.5pt !important; table-layout: fixed !important; word-wrap: break-word !important; margin-bottom: 2mm !important; color: #000 !important; background: transparent !important; }
        .injected-table-wrapper table * { color: #000000 !important; text-decoration: none !important; }
        .injected-table-wrapper table, .injected-table-wrapper th, .injected-table-wrapper td { border-color: #000 !important; }

        .injected-table-wrapper th { background-color: #fff !important; color: #000 !important; padding: 2px 4px !important; border-top: 2px solid #000 !important; border-bottom: 2px solid #000 !important; text-align: center !important; font-weight: bold !important; border-left: none !important; border-right: none !important; }
        .injected-table-wrapper th:first-child { text-align: left !important; padding-left: 10px !important; }
        
        .injected-table-wrapper td { padding: 2px 4px !important; vertical-align: middle !important; border: none !important; line-height: 1.1 !important; }
        .injected-table-wrapper td:not(:first-child), .injected-table-wrapper th:not(:first-child) { text-align: right !important; padding-right: 10px !important; }
        .injected-table-wrapper td:first-child { text-align: left !important; padding-left: 10px !important; }
        .injected-table-wrapper td.calk-indent { padding-left: 5mm !important; }
        
        .injected-table-wrapper tr { border-bottom: 1px dashed #e2e8f0 !important; }
        .injected-table-wrapper tr:last-child { border-bottom: none !important; }
        .injected-table-wrapper tr.border-top-thick td { border-top: 2px solid #000 !important; }
        .injected-table-wrapper tr.border-bottom-thick td { border-bottom: 2px solid #000 !important; }
        .injected-table-wrapper tr.double-underline td { border-bottom: 3px double #000 !important; }
        
        .injected-table-wrapper tr[class*="total"] td, .injected-table-wrapper tr[class*="subtotal"] td, .injected-table-wrapper td b, .injected-table-wrapper td strong { font-weight: bold !important; color: #000 !important; }
        
        .injected-table-wrapper tr[class*="grand"] td, .injected-table-wrapper tr.row-grand-total td { font-weight: bold !important; border-top: 2px solid #000 !important; border-bottom: 3px double #000 !important; background-color: #f1f5f9 !important; color: #000 !important; }

        .injected-table-wrapper table.tbl-asset th { background: #fff !important; color: #000 !important; border: 1px solid #000 !important; font-size: 7pt !important; }
        .injected-table-wrapper table.tbl-asset td { border: 1px solid #000 !important; color: #000 !important; font-size: 7pt !important; padding: 2px !important; }
        .injected-table-wrapper table.tbl-asset tr.row-cat-header td { background: #fff !important; font-size: 7.5pt !important; font-weight: bold !important; border-left: none !important;}
        .injected-table-wrapper table.tbl-asset tr.row-type-header td { background: #fff !important; font-style: italic !important; }
        .injected-table-wrapper table.tbl-asset tr.row-grand-total td { background: #f1f5f9 !important; color: #000 !important; font-weight: bold !important; font-size: 7pt !important; border-top: 2px solid #000 !important; border-bottom: 3px double #000 !important;}

        .clone-spinner { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 150px; color: #525659; font-family: sans-serif;}

        @media print {
            @page {
                size: A4 portrait;
                margin: 0; 
            }
            body { background: #fff; padding: 0; margin: 0; }
            .report-preview-box { padding: 0; background: #fff; }
            
            .a4-paper { 
                box-shadow: none; 
                margin: 0 auto; 
                padding: 5mm 10mm !important; 
                width: 100%; 
                height: auto !important; 
                min-height: auto !important; 
                page-break-after: always;
            }
            
            .a4-paper:last-child, .a4-paper:last-of-type {
                page-break-after: auto !important;
            }
            
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

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
                if(trim($line)) echo "<tr><td>".htmlspecialchars(trim($line))."</td><td style='text-align: right; border-bottom:1px dotted #000;'>Hal. ".$page_num++."</td></tr>";
            }
            ?>
        </table>
    </div>

    <!-- WRAPPER HALAMAN 3: POSISI KEUANGAN -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN POSISI KEUANGAN<br><span style="font-size:9pt; font-weight:normal;">Periode: <?= $plab_n ?></span></div>
        <div class="injected-table-wrapper" id="inj_neraca"><div class="clone-spinner"><b>Memproses Tabel...</b></div></div>
        <?= renderDynamicSignature($conf_n, $kota_db) ?>
    </div>

    <!-- WRAPPER HALAMAN 4: AKTIVITAS -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN AKTIVITAS<br><span style="font-size:9pt; font-weight:normal;">Periode: <?= $plab_lr ?></span></div>
        <div class="injected-table-wrapper" id="inj_lr"><div class="clone-spinner"><b>Memproses Tabel...</b></div></div>
        <?= renderDynamicSignature($conf_lr, $kota_db) ?>
    </div>

    <!-- WRAPPER HALAMAN 5: ASET NETO -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN PERUBAHAN ASET NETO<br><span style="font-size:9pt; font-weight:normal;">Periode: <?= $plab_neto ?></span></div>
        <div class="injected-table-wrapper" id="inj_neto"><div class="clone-spinner"><b>Memproses Tabel...</b></div></div>
        <?= renderDynamicSignature($conf_neto, $kota_db) ?>
    </div>

    <!-- WRAPPER HALAMAN 6: ARUS KAS -->
    <div class="a4-paper page-break">
        <?= renderKopSurat($logo_path, $inst_name, $alamat_db, $telp_db, $email_db, $web_db) ?>
        <div class="h1-report">LAPORAN ARUS KAS<br><span style="font-size:9pt; font-weight:normal;">Periode: <?= $plab_kas ?></span></div>
        <div class="injected-table-wrapper" id="inj_kas"><div class="clone-spinner"><b>Memproses Tabel...</b></div></div>
        <?= renderDynamicSignature($conf_kas, $kota_db) ?>
    </div>

    <!-- 🚀 CALK HALAMAN 1 (NARATIF) DISEDOT OLEH JS KE SINI -->
    <div class="a4-paper page-break text-dark">
        <div style='text-align:center; margin-bottom:8mm; page-break-inside: avoid; line-height:1.4;'>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'><?= $inst_name ?></div>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'>CATATAN ATAS LAPORAN KEUANGAN</div>
            <div style='font-size: 11pt; color:#000;'>Untuk Periode Berakhir <?= $plab_n ?></div>
        </div>
        
        <div class="injected-table-wrapper" id="inj_calk_naratif"><div class="clone-spinner"><i class="fas fa-spinner fa-spin fa-2x mb-2 text-danger"></i><b>Mengekstrak Dokumen CALK...</b></div></div>
    </div>

    <!-- 🚀 CALK HALAMAN 2 (TABEL NOMINAL & TANDA TANGAN) -->
    <div class="a4-paper text-dark">
        <div style='text-align:center; margin-bottom:8mm; page-break-inside: avoid; line-height:1.4;'>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'><?= $inst_name ?></div>
            <div style='font-size: 13pt; font-weight: bold; text-transform: uppercase; color:#000;'>CATATAN ATAS LAPORAN KEUANGAN</div>
            <div style='font-size: 11pt; color:#000;'>Untuk Periode Berakhir <?= $plab_n ?></div>
        </div>
        
        <div class="injected-table-wrapper" id="inj_calk_tabel"><div class="clone-spinner"><i class="fas fa-spinner fa-spin fa-2x mb-2 text-danger"></i><b>Mengekstrak Tabel CALK...</b></div></div>
        
        <?= renderDynamicSignature($conf_n, $kota_db) ?>
    </div>
</div>

<script>
    async function runExtraction() {
        const id_n = <?= $id_neraca ?>;
        const id_lr = <?= $id_lr ?>;
        const id_nt = <?= $id_neto ?>;
        const id_ks = <?= $id_kas ?>;
        const calkUrl = "<?= $calk_fetch_url ?>";

        try {
            if(id_n) await fetchAndExtract('laporan_posisi_keuangan', id_n, 'inj_neraca');
            if(id_lr) await fetchAndExtract('laporan_aktivitas', id_lr, 'inj_lr');
            if(id_nt) await fetchAndExtract('laporan_perubahan_aset_neto', id_nt, 'inj_neto');
            if(id_ks) await fetchAndExtract('laporan_kas_detail', id_ks, 'inj_kas');
            await fetchAndExtractCalk(calkUrl, 'inj_calk_naratif', 'inj_calk_tabel');
            
            setTimeout(() => { window.print(); }, 1500);

        } catch (e) {
            console.error("Extraction Error: ", e);
            alert("Terjadi kesalahan saat memproses laporan untuk dicetak. " + e.message);
        }
    }

    async function fetchAndExtract(modulePage, targetId, containerId) {
        const target = document.getElementById(containerId);
        try {
            let reportHtml = '';
            let fetchSuccess = false;

            // 🚀 PERCOBAAN 1: DIRECT BYPASS FETCH (ANTI-HTTP-500)
            try {
                const directUrl = `index.php?page=${modulePage}&view=detail&id=${targetId}`;
                const resDirect = await fetch(directUrl, { credentials: 'same-origin', cache: 'no-store' });
                if(resDirect.ok) {
                    reportHtml = await resDirect.text();
                    fetchSuccess = true;
                }
            } catch(e) {}

            // 🚀 PERCOBAAN 2: DIRECT BYPASS (Without 'view' parameter)
            if(!fetchSuccess) {
                try {
                    const directUrl2 = `index.php?page=${modulePage}&id=${targetId}`;
                    const resDirect2 = await fetch(directUrl2, { credentials: 'same-origin', cache: 'no-store' });
                    if(resDirect2.ok) {
                        reportHtml = await resDirect2.text();
                        fetchSuccess = true;
                    }
                } catch(e) {}
            }

            // 🚀 PERCOBAAN 3: METODE LAMA (Scrape List Page) - Fallback
            if(!fetchSuccess) {
                const listRes = await fetch(`index.php?page=${modulePage}`, { credentials: 'same-origin', cache: 'no-store' });
                if(!listRes.ok) throw new Error("HTTP " + listRes.status);
                const listHtml = await listRes.text();
                const parser = new DOMParser();
                let listDoc = parser.parseFromString(listHtml, 'text/html');
                
                let viewUrl = null;
                const links = listDoc.querySelectorAll('a[href]');
                for (let a of links) {
                    const href = a.getAttribute('href');
                    if ((href.includes(`id=${targetId}`) || href.includes(`view_report=${targetId}`)) && 
                        !href.toLowerCase().includes('delete') && !href.toLowerCase().includes('hapus') && !href.toLowerCase().includes('batal')) {
                        viewUrl = href;
                        if (href.includes('view') || href.includes('detail') || href.includes('cetak') || href.includes('render')) break; 
                    }
                }
                
                if (viewUrl) {
                    const actualUrl = viewUrl.startsWith('http') || viewUrl.startsWith('index.php') ? viewUrl : `index.php${viewUrl.startsWith('?') ? '' : '?'}${viewUrl}`;
                    const reportRes = await fetch(actualUrl, { credentials: 'same-origin', cache: 'no-store' });
                    if(!reportRes.ok) throw new Error("HTTP " + reportRes.status);
                    reportHtml = await reportRes.text();
                } else {
                    throw new Error("Link detail tidak ditemukan di halaman tabel.");
                }
            }
            
            const parser = new DOMParser();
            let doc = parser.parseFromString(reportHtml, 'text/html');
            
            let validTable = null;
            const tables = doc.querySelectorAll('table');
            for(let t of tables) {
                const text = t.innerText.toLowerCase();
                if(text.includes('judul laporan') || text.includes('aksi') || text.includes('eksekusi')) continue;
                if(text.includes('hal.')) continue;
                if(text.includes('keterangan') || text.includes('uraian') || text.includes('saldo') || text.includes('aset') || text.includes('pendapatan')) { validTable = t; break; }
            }
            
            if(validTable) {
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
                
                const rows = validTable.querySelectorAll('tr');
                rows.forEach((tr, index) => {
                    const rowText = tr.innerText.toUpperCase().trim();
                    if (rowText.includes('SURPLUS DARI TANPA PEMBATASAN') || rowText.includes('SURPLUS DARI DENGAN PEMBATASAN') || rowText.includes('B. DENGAN PEMBATASAN')) {
                        tr.classList.add('border-top-thick'); tr.style.fontWeight = 'bold'; 
                    }
                    if (rowText.includes('KENAIKAN ASET NETO') || rowText.includes('PENURUNAN ASET NETO')) {
                        tr.classList.add('row-grand-total');
                        tr.innerHTML = tr.innerHTML.replace(/<br\s*[\/]?>/gi, '');
                        if (index > 0) {
                            let prev1 = rows[index - 1]; if(prev1 && (prev1.innerText.trim() === '' || prev1.innerHTML.includes('&nbsp;'))) prev1.remove();
                            let prev2 = rows[index - 2]; if(prev2 && (prev2.innerText.trim() === '' || prev2.innerHTML.includes('&nbsp;'))) prev2.remove();
                        }
                    }

                    // 🚀 OMNI-FORMATTER: Merapatkan Spasi Rp, Auto-Inject, dan Auto-Align
                    const tds = tr.querySelectorAll('td');
                    tds.forEach((td, colIndex) => {
                        if (colIndex > 0) {
                            let txt = td.innerText.trim();
                            let isRightAligned = td.classList.contains('text-end') || td.classList.contains('text-right') || td.style.textAlign === 'right' || td.getAttribute('align') === 'right';
                            
                            let isNumberFormat = /^[\(\-]?\s*\d{1,3}(?:\.\d{3})*(?:,\d+)?\s*[\)]?$/.test(txt.replace(/^(Rp|IDR)\s*/i, '').trim());

                            if (txt !== '-' && txt !== '' && (isRightAligned || isNumberFormat || txt.includes('Rp'))) {
                                let cleanNum = txt.replace(/^(Rp|IDR)\s*/i, '').trim();
                                
                                td.innerHTML = ''; 
                                td.className = 'text-end pe-3'; 
                                td.style.textAlign = 'right';
                                td.style.verticalAlign = 'middle';
                                
                                // Memaksa lebar container 125px agar simbol Rp sejajar vertikal lurus!
                                td.innerHTML = `<div style="display: inline-flex; justify-content: space-between; width: 125px; margin-left: auto; text-align: right;">
                                    <span style="text-align: left;">Rp</span>
                                    <span style="text-align: right;">${cleanNum}</span>
                                </div>`;
                            }
                        }
                    });
                });

                validTable.classList.add('tbl-report'); validTable.removeAttribute('style'); target.innerHTML = validTable.outerHTML;
            } else { throw new Error("Tabel tidak ditemukan."); }
        } catch(e) { target.innerHTML = `<div style='text-align:center;color:red;padding:20px;'><b>Gagal Memuat: ${e.message}</b></div>`; }
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

    document.addEventListener("DOMContentLoaded", function() { runExtraction(); });
</script>
</body>
</html>