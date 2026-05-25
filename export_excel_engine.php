<?php
/**
 * export_excel_engine.php - MESIN EKSPOR EXCEL TERPADU ERP SYIFA
 * Versi: 2.0 (Grand Master - Dynamic Signature & Auto Profile Edition)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak."); }

// Ambil Data
$judul_laporan  = $_POST['judul_laporan'] ?? 'Laporan Keuangan';
$nama_file      = $_POST['nama_file'] ?? 'Laporan_ERP_Syifa';
$html_content   = $_POST['html_content'] ?? ''; 
$periode_text   = $_POST['periode_text'] ?? '';

// ?? TARIK PROFIL DAN TTD
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$inst_name = strtoupper($profile['institution_name'] ?? 'INSTITUSI');

$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'EXCEL_GLOBAL' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

if (ob_get_length()) ob_clean();

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=" . $nama_file . "_" . date('Ymd_His') . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        body { font-family: 'Arial', sans-serif; }
        .header-inst { font-size: 16pt; font-weight: bold; text-align: center; }
        .report-title { font-size: 14pt; font-weight: bold; text-align: center; text-decoration: underline; color: #1a73e8; }
        .report-period { font-size: 11pt; text-align: center; font-style: italic; margin-bottom: 10px; }
        table { border-collapse: collapse; width: 100%; border: 1pt solid #000; }
        th { background-color: #1e293b !important; color: #ffffff !important; border: 1pt solid #000; font-size: 10pt; text-transform: uppercase; padding: 10px; }
        td { border: 0.5pt solid #cccccc; padding: 6px; font-size: 10pt; vertical-align: middle; }
        .num { mso-number-format:"\#\,\#\#0"; text-align: right; }
        .num-rp { mso-number-format:"\\\"Rp\\\"\ \#\,\#\#0"; text-align: right; font-weight: bold; }
        .text-mode { mso-number-format:"\@"; text-align: center; }
        .row-section { background-color: #f8fafc; font-weight: bold; }
        
        .sign-area { border: none !important; text-align: center; font-weight: bold; padding-top: 30px; }
        .sign-name { border: none !important; text-align: center; font-weight: bold; text-decoration: underline; padding-top: 80px; }
        .sign-pos { border: none !important; text-align: center; font-size: 9pt; }
    </style>
</head>
<body>
    <table>
        <tr><td colspan="6" class="header-inst"><?= $inst_name ?></td></tr>
        <tr><td colspan="6" class="report-title"><?= strtoupper($judul_laporan) ?></td></tr>
        <tr><td colspan="6" class="report-period"><?= $periode_text ?></td></tr>
        <tr><td colspan="6" style="height:10px;"></td></tr>
    </table>

    <?php 
        $content = $html_content;
        $content = str_replace(['&nbsp;', 'Rp ', 'Rp'], '', $content);
        $content = preg_replace('/ (onclick|id|style|data-[a-z-]+)="[^"]*"/i', '', $content);
        $content = preg_replace('/<i[^>]*>.*?<\/i>/is', '', $content);
        $content = preg_replace('/<button[^>]*>.*?<\/button>/is', '', $content);
        $content = preg_replace_callback('/<td>\s*([-]?\d+[\.\d+]*)\s*<\/td>/i', function($m) {
            $val = str_replace('.', '', trim($m[1]));
            return is_numeric($val) ? "<td class=\"num\">$val</td>" : "<td>$val</td>";
        }, $content);
        $content = preg_replace('/<td>(\d+-\d+)<\/td>/i', '<td class="text-mode">$1</td>', $content);
        $content = preg_replace_callback('/<tr>(.*?(TOTAL|JUMLAH|POSISI KEUANGAN).*?)<\/tr>/is', function($m) {
            return str_replace('class="num"', 'class="num-rp"', $m[0]);
        }, $content);
        echo $content; 
    ?>
    
    <!-- ?? DYNAMIC SIGNATURE UNTUK EXCEL GLOBAL -->
    <?php if(!empty($signatures)): ?>
    <table style="border: none; margin-top: 50px;">
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="2" class="sign-area"><?= htmlspecialchars($sig['sign_role']) ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="2" class="sign-name"><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="2" class="sign-pos"><?= htmlspecialchars($sig['sign_position']) ?></td>
            <?php endforeach; ?>
        </tr>
    </table>
    <?php endif; ?>
</body>
</html>