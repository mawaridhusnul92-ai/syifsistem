<?php
/**
 * print_laporan_gaji.php - SUPREME PRINT ENGINE (PAYROLL AUDIT)
 * Versi: 2.1 (Grand Master - Dynamic Profile & Signatures)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("ID Laporan tidak valid.");

$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'LAPORAN_GAJI' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $id")->fetch_assoc();
if (!$conf) die("Laporan tidak ditemukan.");

$params = !empty($conf['deskripsi']) ? json_decode($conf['deskripsi'], true) : [];
$mode = $params['display_mode'] ?? 'summary';
$s = $conf['tgl_mulai']; $e = $conf['tgl_akhir'];

if($mode == 'rekap_individu') {
    $peg_id = (int)$params['pegawai_id'];
    $peg = $conn->query("SELECT * FROM hr_pegawai WHERE id = $peg_id")->fetch_assoc();
    $komponens = $conn->query("SELECT pk.nominal, k.nama_komponen, k.jenis FROM hr_pegawai_komponen pk JOIN hr_komponen k ON pk.komponen_id = k.id WHERE pk.pegawai_id = $peg_id ORDER BY k.jenis DESC, k.nama_komponen ASC")->fetch_all(MYSQLI_ASSOC);
} else {
    $sql = "SELECT d.*, p.nip, p.nama_lengkap, p.jabatan, p.unit_kerja, h.tgl_slip, h.status as status_bayar, h.periode_bulan, h.periode_tahun, h.pembayaran_jurnal_id FROM hr_payroll_detail d JOIN hr_pegawai p ON d.pegawai_id = p.id JOIN hr_payroll_header h ON d.payroll_id = h.id WHERE h.tgl_slip BETWEEN '$s' AND '$e' AND UPPER(h.status) != 'DRAFT'";
    if(!empty($params['unit'])) $sql .= " AND p.unit_kerja = '".$conn->real_escape_string($params['unit'])."'";
    $sql .= " ORDER BY p.unit_kerja ASC, p.nama_lengkap ASC";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak_Laporan_Gaji_<?= $id ?></title>
    <style>
        body { font-family: 'Times New Roman', serif; font-size: 10pt; color: #000; line-height: 1.4; }
        .kop-table { width: 100%; border-bottom: 3px double #000; margin-bottom: 20px; }
        .inst-name { font-family: Arial, sans-serif; font-size: 14pt; font-weight: 800; text-transform: uppercase; margin: 0; }
        .report-title { font-family: Arial, sans-serif; font-size: 12pt; font-weight: bold; text-decoration: underline; margin-top: 5px; text-transform: uppercase; text-align: center; }
        .table-data { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table-data th { background: #f2f2f2 !important; border: 1px solid #000; padding: 8px; font-size: 8pt; text-transform: uppercase; text-align: center; }
        .table-data td { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; }
        
        .badge-paid { color: #059669; font-weight: bold; }
        .badge-pending { color: #d97706; font-weight: bold; }
        .bg-group { background-color: #f8fafc !important; font-weight: bold; }

        .signature-container { margin-top: 40px; width: 100%; page-break-inside: avoid; }
        .sign-table { width: 100%; text-align: center; font-family: Arial, sans-serif; font-size: 10pt; border: none; }
        .sign-table td { border: none; padding: 5px; }
        .sign-line { border-bottom: 1px solid #000; margin: 60px auto 5px auto; width: 80%; }
        @media print { @page { size: A4 landscape; margin: 15mm; } .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <table class="kop-table">
        <tr>
            <td width="10%"><?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height:70px;"><?php endif; ?></td>
            <td width="90%" style="text-align:center; padding-right: 10%;">
                <h1 class="inst-name"><?= strtoupper($profile['institution_name'] ?? 'INSTITUSI') ?></h1>
                <div style="font-size: 8pt;"><?= $profile['address'] ?? '' ?> | Telp: <?= $profile['phone'] ?? '' ?></div>
            </td>
        </tr>
    </table>

    <?php if($mode == 'rekap_individu'): ?>
        <h2 class="report-title">REKAPITULASI GAJI PEGAWAI (INDIVIDU)</h2>
        <div style="text-align:center; font-size: 9pt; font-weight: bold; margin-bottom: 20px;">Periode: <?= date('d/m/Y', strtotime($s)) ?> - <?= date('d/m/Y', strtotime($e)) ?></div>
        
        <table style="width: 100%; margin-bottom: 15px; font-weight:bold;">
            <tr><td width="15%">Nama Pegawai</td><td width="35%">: <?= strtoupper($peg['nama_lengkap']) ?></td><td width="15%">Unit Kerja</td><td width="35%">: <?= $peg['unit_kerja'] ?></td></tr>
            <tr><td>NIP</td><td>: <?= $peg['nip'] ?></td><td>Jabatan</td><td>: <?= $peg['jabatan'] ?></td></tr>
        </table>
        
        <table class="table-data">
            <tr><th colspan="2" width="50%">PENDAPATAN (BRUTO)</th><th colspan="2" width="50%">POTONGAN (DEDUCTION)</th></tr>
            <tr>
                <td colspan="2" valign="top" style="padding:0;">
                    <table style="width:100%; border:none;">
                    <?php $tot_p=0; foreach($komponens as $k): if($k['jenis']=='Pendapatan'): $tot_p+=$k['nominal']; ?>
                        <tr><td style="border:none; border-bottom:1px dashed #ccc; padding:6px;"><?= $k['nama_komponen'] ?></td><td style="border:none; border-bottom:1px dashed #ccc; text-align:right; padding:6px;"><?= number_format($k['nominal']) ?></td></tr>
                    <?php endif; endforeach; ?>
                    </table>
                </td>
                <td colspan="2" valign="top" style="padding:0;">
                    <table style="width:100%; border:none;">
                    <?php $tot_m=0; foreach($komponens as $k): if($k['jenis']=='Potongan'): $tot_m+=$k['nominal']; ?>
                        <tr><td style="border:none; border-bottom:1px dashed #ccc; padding:6px;"><?= $k['nama_komponen'] ?></td><td style="border:none; border-bottom:1px dashed #ccc; text-align:right; padding:6px;"><?= number_format($k['nominal']) ?></td></tr>
                    <?php endif; endforeach; ?>
                    </table>
                </td>
            </tr>
            <tr style="font-weight:bold; background:#f2f2f2;">
                <td style="text-align:right;">TOTAL PENDAPATAN</td><td style="text-align:right;"><?= number_format($tot_p) ?></td>
                <td style="text-align:right;">TOTAL POTONGAN</td><td style="text-align:right;"><?= number_format($tot_m) ?></td>
            </tr>
            <tr style="font-weight:bold; background:#e2e8f0; font-size:12pt;">
                <td colspan="3" style="text-align:right;">GAJI BERSIH (TAKE HOME PAY)</td><td style="text-align:right;">Rp <?= number_format($tot_p - $tot_m) ?></td>
            </tr>
        </table>
    <?php else: ?>
        <h2 class="report-title">LAPORAN REKAPITULASI GAJI PEGAWAI</h2>
        <div style="text-align:center; font-size: 9pt; font-weight: bold;">Periode: <?= date('d/m/Y', strtotime($s)) ?> - <?= date('d/m/Y', strtotime($e)) ?></div>
        
        <table class="table-data">
            <thead>
                <tr>
                    <th width="30">NO</th><th width="90">NIP</th><th>NAMA PEGAWAI</th><th width="100">PENDAPATAN</th><th width="100">POTONGAN</th><th width="110">THP (BERSIH)</th><th width="80">STATUS</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no=1; $cur_u=''; $tt=['p'=>0,'m'=>0,'n'=>0];
                foreach($rows as $r): 
                    if($cur_u != $r['unit_kerja']) { 
                        $cur_u = $r['unit_kerja']; 
                        echo "<tr class='bg-group'><td colspan='7' style='text-align:left; padding-left:15px;'>UNIT KERJA: ".strtoupper($cur_u)."</td></tr>"; 
                    }
                    $st_up = strtoupper(trim($r['status_bayar']));
                    $paid = ($st_up == 'PAID' || $st_up == 'DIBAYAR' || $st_up == 'LUNAS' || !empty($r['pembayaran_jurnal_id']));
                    
                    if($paid){ $tt['p'] += ($r['gapok']+$r['tunjangan']); $tt['m'] += $r['potongan']; $tt['n'] += $r['gaji_bersih']; }
                ?>
                <tr>
                    <td align="center"><?= $no++ ?></td><td align="center"><?= $r['nip'] ?></td>
                    <td><?= strtoupper($r['nama_lengkap']) ?></td>
                    <td align="right"><?= $paid?number_format($r['gapok']+$r['tunjangan']):'-' ?></td>
                    <td align="right"><?= $paid?number_format($r['potongan']):'-' ?></td>
                    <td align="right" style="font-weight:bold;"><?= $paid?number_format($r['gaji_bersih']):'-' ?></td>
                    <td align="center"><span class="<?= $paid?'badge-paid':'badge-pending' ?>"><?= $paid ? 'LUNAS' : 'PENDING' ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tr style="background:#eee; font-weight:bold;">
                <td colspan="3" align="right">TOTAL REALISASI PENGGAJIAN INSTITUSI</td>
                <td align="right"><?= number_format($tt['p']) ?></td>
                <td align="right"><?= number_format($tt['m']) ?></td>
                <td align="right">Rp <?= number_format($tt['n']) ?></td>
                <td></td>
            </tr>
        </table>
    <?php endif; ?>

    <div class="signature-container">
        <?php if(!empty($signatures)): 
            $width = floor(100 / count($signatures)) . '%';
        ?>
        <table class="sign-table">
            <tr>
                <?php foreach($signatures as $sig): ?>
                    <td width="<?= $width ?>"><?= htmlspecialchars($sig['sign_role']) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach($signatures as $sig): ?>
                    <td>
                        <div class="sign-line"></div>
                        <b><?= htmlspecialchars($sig['sign_name']) ?: '( ____________________ )' ?></b><br>
                        <span><?= htmlspecialchars($sig['sign_position']) ?></span>
                    </td>
                <?php endforeach; ?>
            </tr>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>