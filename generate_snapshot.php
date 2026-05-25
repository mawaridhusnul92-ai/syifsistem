<?php
/**
 * generate_snapshot.php - USER INTERFACE UNTUK MENARIK SNAPSHOT ERP
 * Buka file ini di browser: localhost/syifa/generate_snapshot.php
 */
require_once 'config/koneksi.php';
require_once 'engine/LedgerSnapshotEngine.php';

$tahun = $_GET['tahun'] ?? date('Y');
$bulan = $_GET['bulan'] ?? date('m');
$msg = "";

if (isset($_GET['action']) && $_GET['action'] == 'run') {
    try {
        LedgerSnapshotEngine::buildSnapshot($conn, $tahun, $bulan);
        $msg = "<div style='color:green; font-weight:bold; margin-bottom:20px;'>? SUKSES! Snapshot untuk Periode $bulan / $tahun berhasil dikunci di Database. Laporan Neraca kini berjalan dalam kecepatan O(1)!</div>";
    } catch (Exception $e) {
        $msg = "<div style='color:red; font-weight:bold; margin-bottom:20px;'>? GAGAL: " . $e->getMessage() . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Enterprise Ledger Snapshot Generator</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8fafc; padding: 40px; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 600px; margin: auto; }
        select, button { padding: 10px; margin: 5px 0; border-radius: 6px; font-size: 16px; }
        button { background: #0d6efd; color: white; border: none; cursor: pointer; font-weight: bold; width: 100%; margin-top: 15px;}
        button:hover { background: #0b5ed7; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color:#0d6efd; margin-top:0;">? Enterprise Snapshot Generator</h2>
        <p style="color:#64748b;">Meringkas jutaan baris jurnal menjadi saldo statis O(1) untuk performa laporan kilat.</p>
        
        <?= $msg ?>

        <form method="GET">
            <input type="hidden" name="action" value="run">
            <label><b>Tahun:</b></label><br>
            <select name="tahun" style="width: 100%;">
                <?php for($i=date('Y')-5; $i<=date('Y')+1; $i++) echo "<option value='$i' ".($i==$tahun?'selected':'').">$i</option>"; ?>
            </select><br><br>
            
            <label><b>Bulan:</b></label><br>
            <select name="bulan" style="width: 100%;">
                <?php for($i=1; $i<=12; $i++) echo "<option value='".sprintf("%02d", $i)."' ".($i==$bulan?'selected':'').">Bulan $i</option>"; ?>
            </select><br>

            <button type="submit">JALANKAN SNAPSHOT SEKARANG</button>
        </form>
    </div>
</body>
</html>