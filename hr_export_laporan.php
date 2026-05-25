<?php
/**
 * hr_export_laporan.php - EXPORT ENGINE KHUSUS LAPORAN GAJI (EXCEL & PDF)
 * Versi: 2.0 (Sovereign Grand Master - Dynamic Profile & Signature Sync)
 */
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses ditolak."); }

$report_id = (int)($_GET['id'] ?? 0);
$mode_export = $_GET['mode'] ?? 'excel';

// ?? DATA MASTER & TANDA TANGAN DINAMIS
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'LAPORAN_GAJI' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $report_id")->fetch_assoc();
if (!$conf) die("Data laporan tidak ditemukan.");

$s = $conf['tgl_mulai']; $e = $conf['tgl_akhir'];
$params = !empty($conf['deskripsi']) ? json_decode($conf['deskripsi'], true) : [];
$mode_display = $params['display_mode'] ?? 'summary';
$target_peg_id = (int)($params['pegawai_id'] ?? 0);
$nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

if ($mode_export == 'excel') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"Laporan_Gaji_" . date('YmdHis') . ".xls\"");
    header("Pragma: no-cache"); header("Expires: 0");
}
?>
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<title><?= strtoupper($conf['judul_laporan']) ?></title>
<style>
    body { font-family: Arial, sans-serif; font-size: 11px; -webkit-print-color-adjust: exact; }
    .title { font-size: 14px; font-weight: bold; text-align: center; margin-bottom: 2px; text-transform: uppercase; }
    .subtitle { font-size: 11px; text-align: center; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th { background-color: #f0f0f0; border: 1px solid #000; padding: 6px; text-align: center; font-weight: bold; font-size: 10px; }
    td { border: 1px solid #000; padding: 5px; vertical-align: middle; font-size: 10px; }
    .text-center { text-align: center; } .text-right { text-align: right; } .text-bold { font-weight: bold; }
    .bg-group { background-color: #e0f2fe; font-weight: bold; }
    .sign-table { width: 100%; border: none; margin-top: 40px; text-align: center; }
    .sign-table td { border: none; padding: 5px; }
    .sign-line { border-bottom: 1px solid #000; margin: 60px auto 5px auto; width: 60%; }
    
    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        body { margin: 0; } .no-print { display: none; } th { background-color: #f0f0f0 !important; }
    }
</style>
</head>
<body <?= ($mode_export == 'print') ? 'onload="window.print()"' : '' ?>>

<?php if($mode_display == 'rekap_individu' && $target_peg_id > 0): 
    $sql = "SELECT d.*, p.nip, p.nama_lengkap, p.jabatan, p.unit_kerja, h.tgl_slip, h.status as status_bayar, h.periode_bulan, h.periode_tahun, h.pembayaran_jurnal_id FROM hr_payroll_detail d JOIN hr_pegawai p ON d.pegawai_id = p.id JOIN hr_payroll_header h ON d.payroll_id = h.id WHERE d.pegawai_id = $target_peg_id AND h.tgl_slip BETWEEN '$s' AND '$e' AND UPPER(h.status) != 'DRAFT' ORDER BY h.tgl_slip ASC";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $peg = $conn->query("SELECT * FROM hr_pegawai WHERE id = $target_peg_id")->fetch_assoc();
    $komponen_res = $conn->query("SELECT pk.nominal, k.nama_komponen, k.jenis FROM hr_pegawai_komponen pk JOIN hr_komponen k ON pk.komponen_id = k.id WHERE pk.pegawai_id = $target_peg_id ORDER BY k.jenis DESC, k.nama_komponen ASC")->fetch_all(MYSQLI_ASSOC);
    $total_p = 0; $total_m = 0;
?>
    <div class="title"><?= strtoupper($profile['institution_name'] ?? 'INSTITUSI') ?></div>
    <div class="title">REKAPITULASI GAJI PEGAWAI (INDIVIDU)</div>
    <div class="subtitle">Periode: <?= date('d/m/Y', strtotime($s)) ?> - <?= date('d/m/Y', strtotime($e)) ?></div>
    <br>
    <table style="width:100%; border:none; margin-bottom:10px;">
        <tr><td style="border:none; width:15%;"><b>Nama</b></td><td style="border:none; width:35%;">: <?= $peg['nama_lengkap'] ?></td><td style="border:none; width:15%;"><b>NIP</b></td><td style="border:none; width:35%;">: <?= $peg['nip'] ?></td></tr>
        <tr><td style="border:none;"><b>Jabatan</b></td><td style="border:none;">: <?= $peg['jabatan'] ?></td><td style="border:none;"><b>Unit Kerja</b></td><td style="border:none;">: <?= $peg['unit_kerja'] ?></td></tr>
    </table>
    <table>
        <tr><th colspan="2" width="50%">PENDAPATAN</th><th colspan="2" width="50%">POTONGAN</th></tr>
        <tr>
            <td valign="top" colspan="2" style="padding:0; border:1px solid #000;">
                <table style="width:100%; margin:0; border:none;">
                <?php foreach($komponen_res as $k): if($k['jenis'] == 'Pendapatan'): $total_p += $k['nominal']; ?>
                    <tr><td style="border:none; border-bottom:1px dashed #ccc;"><?= $k['nama_komponen'] ?></td><td style="border:none; border-bottom:1px dashed #ccc;" class="text-right"><?= number_format($k['nominal']) ?></td></tr>
                <?php endif; endforeach; ?>
                </table>
            </td>
            <td valign="top" colspan="2" style="padding:0; border:1px solid #000;">
                <table style="width:100%; margin:0; border:none;">
                <?php foreach($komponen_res as $k): if($k['jenis'] == 'Potongan'): $total_m += $k['nominal']; ?>
                    <tr><td style="border:none; border-bottom:1px dashed #ccc;"><?= $k['nama_komponen'] ?></td><td style="border:none; border-bottom:1px dashed #ccc;" class="text-right"><?= number_format($k['nominal']) ?></td></tr>
                <?php endif; endforeach; ?>
                </table>
            </td>
        </tr>
        <tr><th class="text-right">TOTAL PENDAPATAN</th><th class="text-right"><?= number_format($total_p) ?></th><th class="text-right">TOTAL POTONGAN</th><th class="text-right"><?= number_format($total_m) ?></th></tr>
        <tr style="background-color: #eee;"><th colspan="3" class="text-right" style="font-size:12px;">GAJI BERSIH (TAKE HOME PAY)</th><th class="text-right" style="font-size:12px;"><?= number_format($total_p - $total_m) ?></th></tr>
    </table>

<?php else: 
    $sql = "SELECT d.*, p.nip, p.nama_lengkap, p.jabatan, p.unit_kerja, h.tgl_slip, h.status as status_bayar, h.periode_bulan, h.periode_tahun, h.pembayaran_jurnal_id FROM hr_payroll_detail d JOIN hr_pegawai p ON d.pegawai_id = p.id JOIN hr_payroll_header h ON d.payroll_id = h.id WHERE h.tgl_slip BETWEEN '$s' AND '$e' AND UPPER(h.status) != 'DRAFT'";
    if(!empty($params['unit'])) $sql .= " AND p.unit_kerja = '".$conn->real_escape_string($params['unit'])."'";
    $sql .= " ORDER BY p.unit_kerja ASC, p.nama_lengkap ASC";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
    <div class="title"><?= strtoupper($profile['institution_name'] ?? 'INSTITUSI') ?></div>
    <div class="title">LAPORAN REKAPITULASI GAJI PEGAWAI</div>
    <div class="subtitle">Periode: <?= date('d/m/Y', strtotime($s)) ?> - <?= date('d/m/Y', strtotime($e)) ?></div>
    <br>
    <table>
        <thead><tr><th width="30">NO</th><th>PERIODE</th><th>NIP</th><th>NAMA PEGAWAI</th><th>JABATAN</th><th>UNIT KERJA</th><th>PENDAPATAN</th><th>POTONGAN</th><th>THP (BERSIH)</th><th>STATUS</th></tr></thead>
        <tbody>
            <?php 
            $no=1; $cur_u=''; $gt=['p'=>0, 'm'=>0, 'net'=>0];
            foreach($rows as $r): 
                if($cur_u != $r['unit_kerja']) { $cur_u = $r['unit_kerja']; echo "<tr><td colspan='10' class='bg-group' style='background-color:#e0f2fe; font-weight:bold; text-align:left;'>UNIT KERJA: ".strtoupper($cur_u)."</td></tr>"; }
                $bruto = $r['gapok'] + $r['tunjangan'];
                $gt['p'] += $bruto; $gt['m'] += $r['potongan']; $gt['net'] += $r['gaji_bersih'];
                $st_up = strtoupper(trim($r['status_bayar']));
                $is_paid = ($st_up == 'PAID' || $st_up == 'DIBAYAR' || $st_up == 'LUNAS' || !empty($r['pembayaran_jurnal_id']));
            ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td><td class="text-center"><?= $nama_bulan[$r['periode_bulan']] ?> <?= $r['periode_tahun'] ?></td><td class="text-center" style="mso-number-format:'@';"><?= $r['nip'] ?></td>
                <td><?= strtoupper($r['nama_lengkap']) ?></td><td><?= $r['jabatan'] ?></td><td><?= $r['unit_kerja'] ?></td>
                <td class="text-right"><?= number_format($bruto) ?></td><td class="text-right"><?= number_format($r['potongan']) ?></td>
                <td class="text-right text-bold"><?= number_format($r['gaji_bersih']) ?></td><td class="text-center"><?= $is_paid ? 'LUNAS' : 'PENDING' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="6" class="text-right">TOTAL KESELURUHAN</th><th class="text-right"><?= number_format($gt['p']) ?></th><th class="text-right"><?= number_format($gt['m']) ?></th><th class="text-right" style="font-size:11px;"><?= number_format($gt['net']) ?></th><th></th></tr></tfoot>
    </table>
<?php endif; ?>

    <!-- ?? DYNAMIC SIGNATURE -->
    <?php if(!empty($signatures)): ?>
    <table class="sign-table">
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="2" class="text-center"><b><?= htmlspecialchars($sig['sign_role']) ?></b></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="2" class="sign-name"><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach($signatures as $sig): ?>
                <td colspan="2" class="text-center" style="font-size: 10px; color: #64748b;"><?= htmlspecialchars($sig['sign_position']) ?></td>
            <?php endforeach; ?>
        </tr>
    </table>
    <?php endif; ?>

</body>
</html>