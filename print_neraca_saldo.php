<?php
/**
 * print_neraca_saldo.php - SUPREME PRINT ENGINE (NERACA SALDO)
 * Versi: 2.0 (Grand Master - Dynamic Signature Edition)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("ID Laporan tidak valid.");

// ?? DATA MASTER & TANDA TANGAN DINAMIS
$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
$q_ttd = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'NERACA_SALDO' ORDER BY id ASC");
$signatures = []; if($q_ttd) while($r = $q_ttd->fetch_assoc()) $signatures[] = $r;

$conf = $conn->query("SELECT * FROM laporan_keuangan_setting WHERE id = $id")->fetch_assoc();
if (!$conf) die("Laporan tidak ditemukan.");

$start_date = $conf['tgl_mulai']; $end_date = $conf['tgl_akhir'];
$all_accounts = $conn->query("SELECT * FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC")->fetch_all(MYSQLI_ASSOC);

function getAccountSummaryPrint($kode, $s_date, $e_date, $conn) {
    $acc = $conn->query("SELECT opening_balance, saldo_normal FROM syifa_akun WHERE kode_akun='$kode'")->fetch_assoc();
    $q_awal = $conn->query("SELECT SUM(debit - kredit) as net FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id=j.id WHERE jd.kode_akun='$kode' AND j.tgl_jurnal < '$s_date'")->fetch_assoc();
    $saldo_awal = (double)$acc['opening_balance'] + (($acc['saldo_normal'] == 'D') ? (double)$q_awal['net'] : -(double)$q_awal['net']);
    
    $q_mutasi = $conn->query("SELECT SUM(debit) as d, SUM(kredit) as k FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE jd.kode_akun='$kode' AND j.tgl_jurnal BETWEEN '$s_date' AND '$e_date'")->fetch_assoc();
    $debet = (double)($q_mutasi['d'] ?? 0); $kredit = (double)($q_mutasi['k'] ?? 0);
    $saldo_akhir = $saldo_awal + (($acc['saldo_normal'] == 'D') ? ($debet - $kredit) : ($kredit - $debet));
    return ['awal' => $saldo_awal, 'debet' => $debet, 'kredit' => $kredit, 'akhir' => $saldo_akhir];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Cetak_Neraca_Saldo_<?= $id ?></title>
    <style>
        @page { size: A4 portrait; margin: 15mm; }
        body { font-family: 'Times New Roman', serif; font-size: 10pt; color: #000; line-height: 1.4; }
        .kop-table { width: 100%; border-bottom: 3px double #000; margin-bottom: 20px; }
        .inst-name { font-family: Arial, sans-serif; font-size: 14pt; font-weight: 800; text-transform: uppercase; margin: 0; }
        .report-title { font-family: Arial, sans-serif; font-size: 12pt; font-weight: bold; text-decoration: underline; margin-top: 5px; text-transform: uppercase; text-align: center; }
        .table-data { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table-data th { background: #f2f2f2 !important; border: 1px solid #000; padding: 8px; font-size: 8pt; text-transform: uppercase; text-align: center; }
        .table-data td { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; }
        .row-group { background: #f9f9f9 !important; font-weight: bold; }
        .total-row { font-weight: bold; background: #eee !important; }
        
        .signature-container { margin-top: 40px; width: 100%; page-break-inside: avoid; }
        .sign-table { width: 100%; text-align: center; font-family: Arial, sans-serif; font-size: 10pt; }
        .sign-line { border-bottom: 1px solid #000; margin: 60px auto 5px auto; width: 80%; }
    </style>
</head>
<body onload="window.print()">
    <table class="kop-table">
        <tr>
            <td width="15%"><?php if(!empty($profile['logo'])): ?><img src="assets/img/<?= $profile['logo'] ?>" style="max-height:70px;"><?php endif; ?></td>
            <td width="85%" style="text-align:center; padding-right: 15%;">
                <h1 class="inst-name"><?= strtoupper($profile['institution_name']) ?></h1>
                <div style="font-size: 8pt;"><?= $profile['address'] ?> | Telp: <?= $profile['phone'] ?></div>
            </td>
        </tr>
    </table>

    <h2 class="report-title">Neraca Saldo & Percobaan (Trial Balance)</h2>
    <div style="text-align:center; font-size: 9pt; font-weight: bold;">Periode: <?= date('d M Y', strtotime($start_date)) ?> s.d <?= date('d M Y', strtotime($end_date)) ?></div>

    <table class="table-data">
        <thead><tr><th width="30">NO</th><th width="80">KODE</th><th style="text-align:left;">NAMA AKUN PERKIRAAN</th><th width="100">SALDO AWAL</th><th width="90">DEBET (+)</th><th width="90">KREDIT (-)</th><th width="100">SALDO AKHIR</th></tr></thead>
        <tbody>
            <?php 
            $no=1; $gt = ['aw'=>0, 'd'=>0, 'k'=>0, 'ak'=>0];
            foreach(['Aset', 'Liabilitas', 'Aset Neto', 'Pendapatan', 'Beban'] as $kat):
                echo "<tr class='row-group'><td colspan='7'>KELOMPOK: ".strtoupper($kat)."</td></tr>";
                foreach($all_accounts as $acc):
                    if($acc['kategori'] == $kat):
                        $res = getAccountSummaryPrint($acc['kode_akun'], $start_date, $end_date, $conn);
                        if(abs($res['awal']) < 0.01 && abs($res['debet']) < 0.01 && abs($res['kredit']) < 0.01) continue;
                        $gt['aw']+=$res['awal']; $gt['d']+=$res['debet']; $gt['k']+=$res['kredit']; $gt['ak']+=$res['akhir'];
            ?>
                <tr><td align="center"><?= $no++ ?></td><td align="center"><?= $acc['kode_akun'] ?></td><td><?= $acc['nama_akun'] ?></td><td align="right"><?= number_format($res['awal'], 0, ',', '.') ?></td><td align="right"><?= number_format($res['debet'], 0, ',', '.') ?></td><td align="right"><?= number_format($res['kredit'], 0, ',', '.') ?></td><td align="right" style="font-weight:bold;"><?= number_format($res['akhir'], 0, ',', '.') ?></td></tr>
            <?php endif; endforeach; endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row"><td colspan="3" align="right">TOTAL BALANCE CHECK</td><td align="right"><?= number_format($gt['aw'], 0, ',', '.') ?></td><td align="right"><?= number_format($gt['d'], 0, ',', '.') ?></td><td align="right"><?= number_format($gt['k'], 0, ',', '.') ?></td><td align="right">Rp <?= number_format($gt['ak'], 0, ',', '.') ?></td></tr>
        </tfoot>
    </table>

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