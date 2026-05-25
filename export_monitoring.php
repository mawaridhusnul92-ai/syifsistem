<?php
/**
 * export_monitoring.php - EXPORT DATA PIUTANG MAHASISWA KE EXCEL
 * Versi: 30.0 (Enterprise Excel HTML Format - Dynamic Signature Sync)
 * Fitur: Institutional Header, Auto-Calculation, dan Tanda Tangan Dinamis.
 */
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses ditolak. Silakan login terlebih dahulu."); }

// 1. Ambil Data Profil Institusi & Tanda Tangan Dinamis
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$inst_name = strtoupper($profile['institution_name'] ?? 'SYIFA ERP');
$inst_address = ($profile['address'] ?? '') . ', ' . ($profile['city'] ?? '');

$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'MONITORING_PIUTANG' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

// 2. Tangkap Parameter Filter
$f_tahun = $_GET['f_tahun'] ?? '';
$f_prodi = $_GET['f_prodi'] ?? '';
$f_angkatan = $_GET['f_angkatan'] ?? '';
$f_sistem = $_GET['f_sistem'] ?? '';
$f_status_bayar = $_GET['f_status_bayar'] ?? '';
$f_jenis = $_GET['f_jenis'] ?? '';
$filter_aging = $_GET['filter_aging'] ?? '';
$q = $_GET['q'] ?? '';

// 3. Bangun Query SQL dengan Filter
$where = "t.deleted_at IS NULL";
if($f_tahun) $where .= " AND t.kode_tahun = '" . $conn->real_escape_string($f_tahun) . "'";
if($f_prodi) $where .= " AND t.prodi_id = '" . $conn->real_escape_string($f_prodi) . "'";
if($f_angkatan) $where .= " AND m.angkatan = '" . $conn->real_escape_string($f_angkatan) . "'";
if($f_sistem) $where .= " AND m.sistem_kuliah = '" . $conn->real_escape_string($f_sistem) . "'";
if($f_jenis) $where .= " AND t.nama_tagihan = '" . $conn->real_escape_string($f_jenis) . "'";
if($f_status_bayar) $where .= " AND t.status_bayar = '" . $conn->real_escape_string($f_status_bayar) . "'";
if($q) {
    $q_esc = $conn->real_escape_string($q);
    $where .= " AND (m.nama LIKE '%$q_esc%' OR t.nim LIKE '%$q_esc%' OR t.no_jurnal LIKE '%$q_esc%')";
}

if($filter_aging) {
    $days = match($filter_aging) { 
        'lancar' => '>= DATE_SUB(CURDATE(), INTERVAL 90 DAY)', 
        'kurang' => '< DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)', 
        'ragu'   => '< DATE_SUB(CURDATE(), INTERVAL 180 DAY) AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 360 DAY)', 
        'macet'  => '< DATE_SUB(CURDATE(), INTERVAL 360 DAY)' 
    };
    $where .= " AND t.created_at $days AND t.status_bayar != 'Lunas'";
}

$sql = "SELECT t.*, m.nama, m.sistem_kuliah, m.angkatan, p.nama_prodi, jt.kode_tagihan 
        FROM keuangan_tagihan t 
        LEFT JOIN syifa_mahasiswa m ON t.nim = m.nim 
        LEFT JOIN mhs_prodi p ON m.prodi_id = p.id
        LEFT JOIN mhs_tarif mt ON t.tarif_id = mt.id
        LEFT JOIN mhs_jenis_tagihan jt ON mt.jenis_tagihan_id = jt.id
        WHERE $where 
        ORDER BY COALESCE(jt.kode_tagihan, 'ZZZ') ASC, t.nama_tagihan ASC, m.nama ASC";

$result = $conn->query($sql);

// 4. Header Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"Export_Piutang_Mahasiswa_" . date('Ymd_His') . ".xls\"");
header("Pragma: no-cache");
header("Expires: 0");

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        body { font-family: 'Arial', sans-serif; }
        table { border-collapse: collapse; width: 100%; border: 1pt solid #000; }
        th { background-color: #1e293b; color: #ffffff; border: 1pt solid #000; font-size: 11pt; padding: 10px; text-transform: uppercase; }
        td { border: 0.5pt solid #cccccc; padding: 6px; font-size: 10pt; vertical-align: middle; }
        .header-inst { font-size: 18pt; font-weight: bold; text-align: center; }
        .report-title { font-size: 14pt; font-weight: bold; text-align: center; text-decoration: underline; color: #1a73e8; }
        .report-subtitle { font-size: 11pt; text-align: center; font-style: italic; margin-bottom: 10px; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .num-format { mso-number-format:"\#\,\#\#0"; text-align: right; }
        .text-format { mso-number-format:"\@"; text-align: center; }
        .bg-light { background-color: #f1f5f9; font-weight: bold; }
        .sign-area { border: none !important; text-align: center; font-weight: bold; padding-top: 30px; }
        .sign-name { border: none !important; text-align: center; font-weight: bold; text-decoration: underline; padding-top: 80px; }
        .sign-pos { border: none !important; text-align: center; font-size: 10pt; }
    </style>
</head>
<body>
    <table style="border:none;">
        <tr><td colspan="12" class="header-inst" style="border:none;"><?= $inst_name ?></td></tr>
        <tr><td colspan="12" class="report-title" style="border:none;">LAPORAN MONITORING PIUTANG MAHASISWA</td></tr>
        <tr><td colspan="12" class="report-subtitle" style="border:none;">Tanggal Unduh: <?= date('d M Y H:i') ?></td></tr>
        <tr><td colspan="12" style="height:15px; border:none;"></td></tr>
    </table>

    <table>
        <thead>
            <tr>
                <th width="40">NO</th>
                <th width="120">NIM</th>
                <th width="250">NAMA MAHASISWA</th>
                <th width="180">PROGRAM STUDI</th>
                <th width="120">SISTEM KULIAH</th>
                <th width="100">ANGKATAN</th>
                <th width="200">JENIS TAGIHAN</th>
                <th width="120">PERIODE</th>
                <th width="130">NOMINAL (RP)</th>
                <th width="130">TERBAYAR (RP)</th>
                <th width="130">SISA (RP)</th>
                <th width="120">STATUS</th>
            </tr>
        </thead>
        <tbody>
        <?php 
        $no = 1;
        $tot_nom = 0; $tot_pay = 0; $tot_sisa = 0;

        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $nominal = (double)$row['nominal'];
                $terbayar = (double)$row['terbayar'];
                $sisa = $nominal - $terbayar;

                $tot_nom += $nominal;
                $tot_pay += $terbayar;
                $tot_sisa += $sisa;

                echo "<tr>";
                echo "<td class='text-center'>{$no}</td>";
                echo "<td class='text-format'>{$row['nim']}</td>";
                echo "<td class='text-left'>" . strtoupper($row['nama']) . "</td>";
                echo "<td class='text-left'>{$row['nama_prodi']}</td>";
                echo "<td class='text-center'>{$row['sistem_kuliah']}</td>";
                echo "<td class='text-format'>{$row['angkatan']}</td>";
                echo "<td class='text-left'>{$row['nama_tagihan']}</td>";
                echo "<td class='text-format'>{$row['kode_tahun']}</td>"; 
                echo "<td class='num-format'>{$nominal}</td>"; 
                echo "<td class='num-format'>{$terbayar}</td>";
                echo "<td class='num-format'>{$sisa}</td>";
                echo "<td class='text-center'>{$row['status_bayar']}</td>";
                echo "</tr>";
                $no++;
            }
            
            echo "<tr>";
            echo "<th colspan='8' class='text-right bg-light'>TOTAL AKUMULASI PADA FILTER INI</th>"; 
            echo "<th class='num-format bg-light'>{$tot_nom}</th>";
            echo "<th class='num-format bg-light'>{$tot_pay}</th>";
            echo "<th class='num-format bg-light'>{$tot_sisa}</th>";
            echo "<th class='bg-light'></th>";
            echo "</tr>";

        } else {
            echo "<tr><td colspan='12' class='text-center py-4'>Tidak ada data piutang berdasarkan kriteria filter saat ini.</td></tr>";
        }
        ?>
        </tbody>
    </table>

    <!-- ?? DYNAMIC SIGNATURE UNTUK EXCEL -->
    <?php if(!empty($signatures)): ?>
    <table style="border: none; margin-top: 50px;">
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="4" class="sign-area"><?= htmlspecialchars($sig['sign_role']) ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="4" class="sign-name"><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="4" class="sign-pos"><?= htmlspecialchars($sig['sign_position']) ?></td>
            <?php endforeach; ?>
        </tr>
    </table>
    <?php endif; ?>
</body>
</html>