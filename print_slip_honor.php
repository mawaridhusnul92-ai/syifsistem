<?php
/**
 * print_slip_honor.php — CETAK KUITANSI SLIP HONOR DENGAN MULTI TABEL
 * STIKes Yarsi Pontianak — SYIFA System
 *
 * FITUR UTAMA:
 * - Menarik seluruh slip honor yang diceklis user dan MENGGABUNGKANNYA per dosen.
 * - Kop Surat dan identitas dosen hanya dicetak 1 kali di atas.
 * - Tabel Rincian akan dilooping dan disusun lurus ke bawah untuk masing-masing "Generate/Batch"
 * - URUTAN KOLOM DIPERBAIKI: Rincian -> Qty -> Tarif -> Jumlah -> (Akhir baris: Bruto -> Pajak -> Netto)
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    die("<p style='padding:40px;color:red;font-family:sans-serif'>Akses Ditolak — Silakan login kembali.</p>");
}

if (!isset($conn)) {
    if (file_exists('config/koneksi.php'))  require_once 'config/koneksi.php';
    elseif (file_exists('koneksi.php'))     require_once 'koneksi.php';
    else die("File koneksi.php tidak ditemukan.");
}

$mode    = $_GET['mode'] ?? 'slip';
$ids_raw = $_GET['detail_ids'] ?? '';

// Sanitasi ID
$ids_clean = [];
foreach (explode(',', $ids_raw) as $v) { $v = (int)trim($v); if ($v > 0) $ids_clean[] = $v; }
$ids_str = implode(',', $ids_clean);

if (empty($ids_str)) {
    die("<p style='padding:40px;color:red;font-family:sans-serif'>Parameter tidak lengkap.</p>");
}

$sql = "SELECT d.id, d.dosen_id, d.generate_id, d.mata_kuliah, d.prodi, d.rincian_komponen_id,
               d.qty, d.tarif, d.total_honor, d.persen_pajak, d.potongan_pajak, d.honor_diterima,
               g.nama_generate, g.periode_bulan, g.periode_tahun, g.template_id,
               ds.nama AS dosen_nama, ds.nip, ds.jabatan_fungsional, ds.golongan,
               ds.nama_bank, ds.no_rekening, ds.pemilik_rekening, ds.program_studi,
               t.custom_layout, t.jenis_tujuan
        FROM honor_generate_detail d
        JOIN honor_generate g ON d.generate_id = g.id
        JOIN dosen ds ON d.dosen_id = ds.id
        LEFT JOIN honor_template t ON g.template_id = t.id
        WHERE d.id IN ($ids_str)
        ORDER BY d.dosen_id ASC, g.id ASC, d.id ASC";

$res = $conn->query($sql);
if (!$res || $res->num_rows === 0) {
    die("<p style='padding:40px;color:red;font-family:sans-serif'>Data tidak ditemukan.</p>");
}

$nm_bln = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

// 🚀 STRUKTUR DATA MULTI-TABEL PER DOSEN
$slips = [];
while ($r = $res->fetch_assoc()) {
    $did = (int)$r['dosen_id'];
    $gid = (int)$r['generate_id'];
    $row_key = md5($r['mata_kuliah'] . $r['prodi']);

    if (!isset($slips[$did])) {
        $slips[$did] = [
            'info' => $r, 
            'grand_bruto' => 0,
            'grand_pajak' => 0,
            'grand_netto' => 0,
            'generations' => []
        ];
    }

    if (!isset($slips[$did]['generations'][$gid])) {
        $slips[$did]['generations'][$gid] = [
            'nama_generate' => $r['nama_generate'],
            'layout_json' => $r['custom_layout'],
            'sub_bruto' => 0,
            'sub_pajak' => 0,
            'sub_netto' => 0,
            'rows' => []
        ];
    }

    if (!isset($slips[$did]['generations'][$gid]['rows'][$row_key])) {
        $slips[$did]['generations'][$gid]['rows'][$row_key] = [
            'prodi' => $r['prodi'] ?: $r['program_studi'],
            'mata_kuliah' => $r['mata_kuliah'],
            'jabatan' => $r['jabatan_fungsional'],
            'dosen_nama' => $r['dosen_nama'],
            'pajak_pct' => (float)$r['persen_pajak'],
            'row_bruto' => 0,
            'row_pajak' => 0,
            'row_netto' => 0,
            'komponen' => []
        ];
    }

    $rid = (int)$r['rincian_komponen_id'];
    $slips[$did]['generations'][$gid]['rows'][$row_key]['komponen'][$rid] = [
        'qty' => (float)$r['qty'],
        'tarif' => (float)$r['tarif'],
        'jml' => (float)$r['total_honor']
    ];

    // Penjumlahan ke Row (Baris M/K)
    $slips[$did]['generations'][$gid]['rows'][$row_key]['row_bruto'] += (float)$r['total_honor'];
    $slips[$did]['generations'][$gid]['rows'][$row_key]['row_pajak'] += (float)$r['potongan_pajak'];
    $slips[$did]['generations'][$gid]['rows'][$row_key]['row_netto'] += (float)$r['honor_diterima'];

    // Penjumlahan ke Group (Generate Tabel)
    $slips[$did]['generations'][$gid]['sub_bruto'] += (float)$r['total_honor'];
    $slips[$did]['generations'][$gid]['sub_pajak'] += (float)$r['potongan_pajak'];
    $slips[$did]['generations'][$gid]['sub_netto'] += (float)$r['honor_diterima'];

    // Penjumlahan ke Keseluruhan Dosen (Grand Total)
    $slips[$did]['grand_bruto'] += (float)$r['total_honor'];
    $slips[$did]['grand_pajak'] += (float)$r['potongan_pajak'];
    $slips[$did]['grand_netto'] += (float)$r['honor_diterima'];
}

$master_tarif = [];
$res_t = $conn->query("SELECT id, besaran, rincian, satuan, jabatan_fungsional FROM honor_komponen_detail");
if ($res_t) { while($rt = $res_t->fetch_assoc()) $master_tarif[$rt['id']] = $rt; }

// Helper: ubah "Per Mahasiswa" → "Mhs", "Per SKS" → "SKS", dst
function satuanLabel($satuan) {
    $map = [
        'Per Mahasiswa' => 'Mhs',
        'Per SKS'       => 'SKS',
        'Per Pertemuan' => 'Pertemuan',
        'Per Kegiatan'  => 'Kegiatan',
        'Per Jam'       => 'Jam',
        'Per Soal'      => 'Soal',
        'Lump Sum'      => 'Ls',
    ];
    return $map[$satuan] ?? ($satuan ?: 'Qty');
}
function satuanTarifLabel($satuan) {
    $s = satuanLabel($satuan);
    return "Rp/$s";
}

// ==========================================
// KOP SURAT (DARI PROFILE)
// ==========================================
$profile   = @$conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$inst_name = $profile['institution_name'] ?? 'STIKES YARSI PONTIANAK';
$inst_addr = $profile['address'] ?? 'Jl. Panglima A\'im No. 2, Pontianak, Kalimantan Barat';
$logo_img = '';
if (!empty($profile['logo']) && file_exists('assets/img/' . $profile['logo'])) {
    $logo_img = "<img src='assets/img/" . $profile['logo'] . "' style='width:65px; height:auto;'>";
} else {
    $logo_img = '
    <svg width="60" height="60" viewBox="0 0 55 55">
        <circle cx="27.5" cy="27.5" r="26" fill="#1B4F72" stroke="#E8B84B" stroke-width="3"/>
        <text x="27.5" y="22" text-anchor="middle" fill="#fff" font-size="8.5" font-weight="bold" font-family="Arial">STIKes</text>
        <text x="27.5" y="32" text-anchor="middle" fill="#E8B84B" font-size="8" font-weight="bold" font-family="Arial">YARSI</text>
        <text x="27.5" y="42" text-anchor="middle" fill="#fff" font-size="7" font-family="Arial">PONTIANAK</text>
    </svg>';
}

// ==========================================
// TANDA TANGAN DINAMIS DARI DB
// ==========================================
$res_sig = $conn->query("SELECT * FROM system_signatures WHERE doc_type = 'SLIP_HONOR' ORDER BY id ASC");
$signatures = [];
if ($res_sig) {
    while($r = $res_sig->fetch_assoc()) $signatures[] = $r;
}
if (empty($signatures)) {
    $signatures = [
        ['sign_role' => 'Menyetujui,', 'sign_position' => 'Wakil Ketua II', 'sign_name' => 'Ns. Masmuri, M.Kep'],
        ['sign_role' => 'Mengetahui,', 'sign_position' => 'Ketua Pengelola Non Reguler', 'sign_name' => 'Ns. Nurpratiwi, M.Kep'],
        ['sign_role' => 'Pontianak, '.date('d M Y').'<br>Penerima,', 'sign_position' => '', 'sign_name' => '[NAMA_DOSEN]'],
        ['sign_role' => 'Dibuat Oleh,', 'sign_position' => 'Bendahara Non Reguler', 'sign_name' => 'Husnul Mawarid, M.Ak'],
    ];
}

// ==========================================
// HELPER FUNGSI
// ==========================================
function rp($n) { return number_format((float)$n, 0, ',', '.'); }

function terbilang($angka) {
    $angka = abs((int)$angka);
    $bil   = ['','Satu','Dua','Tiga','Empat','Lima','Enam','Tujuh','Delapan','Sembilan','Sepuluh','Sebelas'];
    if ($angka < 12)         return $bil[$angka];
    if ($angka < 20)         return terbilang($angka-10) . ' Belas';
    if ($angka < 100)        return terbilang((int)($angka/10)) . ' Puluh' . ($angka%10 ? ' '.terbilang($angka%10) : '');
    if ($angka < 200)        return 'Seratus' . ($angka-100 ? ' '.terbilang($angka-100) : '');
    if ($angka < 1000)       return terbilang((int)($angka/100)) . ' Ratus' . ($angka%100 ? ' '.terbilang($angka%100) : '');
    if ($angka < 2000)       return 'Seribu' . ($angka-1000 ? ' '.terbilang($angka-1000) : '');
    if ($angka < 1000000)    return terbilang((int)($angka/1000)) . ' Ribu' . ($angka%1000 ? ' '.terbilang($angka%1000) : '');
    if ($angka < 1000000000) return terbilang((int)($angka/1000000)) . ' Juta' . ($angka%1000000 ? ' '.terbilang($angka%1000000) : '');
    return terbilang((int)($angka/1000000000)) . ' Miliar' . ($angka%1000000000 ? ' '.terbilang($angka%1000000000) : '');
}

$tanggal_cetak = date('d') . ' ' . $nm_bln[(int)date('n')] . ' ' . date('Y');

// FUNGSI RENDER DINAMIS TABEL
function buildTableHeader($teks_cols, $horiz_groups, $vert_info, $master_tarif = []) {
    $row1 = ""; $row2 = ""; $need_row2 = false;
    
    foreach ($teks_cols as $t) {
        $row1 .= "<th rowspan='2' style='min-width:100px'>".strtoupper($t['label'])."</th>";
    }
    
    foreach ($horiz_groups as $gName => $items) {
        $firstItem  = $items[0] ?? null;
        $gSingleCol = !empty($firstItem['single_jafung_col']);
        $gIsJafung  = !empty($firstItem['is_jafung']);
        $need_row2  = true;
        
        if ($gSingleCol) {
            // Mode 1 kolom: ambil satuan dari rincian pertama
            $first_rid = (int)($firstItem['id_rincian'] ?? 0);
            $satuan    = $master_tarif[$first_rid]['satuan'] ?? '';
            $qtyLbl    = strtoupper(satuanLabel($satuan));
            $tarifLbl  = satuanTarifLabel($satuan);
            
            $row1 .= "<th colspan='3' class='th-grup'>".strtoupper($gName)."</th>";
            $row2 .= "<th class='th-sub'>$qtyLbl</th><th class='th-sub'>$tarifLbl</th><th class='th-sub'>JUMLAH</th>";
            
        } elseif ($gIsJafung) {
            // Mode jafung non-single: tampilkan per jabatan dengan satuan
            $cs = count($items) * 3;
            $row1 .= "<th colspan='$cs' class='th-grup'>".strtoupper($gName)."</th>";
            foreach ($items as $it) {
                $rid      = (int)($it['id_rincian'] ?? 0);
                $satuan   = $master_tarif[$rid]['satuan'] ?? '';
                $qtyLbl   = strtoupper(satuanLabel($satuan));
                $tarifLbl = satuanTarifLabel($satuan);
                $lbl      = strtoupper($it['label']);
                $row2 .= "<th class='th-sub'>$lbl ($qtyLbl)</th><th class='th-sub'>$tarifLbl</th><th class='th-sub'>JUMLAH</th>";
            }
            
        } else {
            // Mode normal: tampilkan semua item dengan satuan dari masing-masing rincian
            $cs = count($items) * 3;
            // Cek jika ada group_header (kolom uraian)
            $gHeader = trim($firstItem['group_header'] ?? '');
            if (!empty($gHeader)) {
                $cs += 1;
                $row1 .= "<th colspan='$cs' class='th-grup'>".strtoupper($gName)."</th>";
                $row2 .= "<th class='th-sub'>".strtoupper($gHeader)."</th>";
            } else {
                $row1 .= "<th colspan='$cs' class='th-grup'>".strtoupper($gName)."</th>";
            }
            foreach ($items as $it) {
                $rid      = (int)($it['id_rincian'] ?? 0);
                $satuan   = $master_tarif[$rid]['satuan'] ?? '';
                $qtyLbl   = strtoupper(satuanLabel($satuan));
                $tarifLbl = satuanTarifLabel($satuan);
                $lbl      = strtoupper($it['label']);
                $row2 .= "<th class='th-sub'>$lbl<br><small>($qtyLbl)</small></th><th class='th-sub'>$tarifLbl</th><th class='th-sub'>JUMLAH</th>";
            }
        }
    }
    
    if (!empty($vert_info['items'])) {
        $need_row2 = true;
        // Ambil satuan dari rincian pertama vertikal
        $first_vrid = (int)($vert_info['items'][0]['id_rincian'] ?? 0);
        $v_satuan   = $master_tarif[$first_vrid]['satuan'] ?? '';
        $vQtyLbl    = strtoupper(satuanLabel($v_satuan));
        $vTarifLbl  = satuanTarifLabel($v_satuan);
        
        $vHeader = isset($vert_info['header']) ? trim(strtoupper($vert_info['header'])) : '';
        $row1 .= "<th rowspan='2'>" . ($vHeader ?: 'URAIAN') . "</th>";
        $row1 .= "<th colspan='3' class='th-grup'>".strtoupper($vert_info['name'])."</th>";
        $row2 .= "<th class='th-sub'>$vQtyLbl</th><th class='th-sub'>$vTarifLbl</th><th class='th-sub'>JUMLAH</th>";
    }
    
    $row1 .= "<th rowspan='2' style='min-width:90px'>TOTAL<br>BRUTO</th>";
    $row1 .= "<th rowspan='2' style='min-width:80px'>POT. PAJAK<br>5%</th>";
    $row1 .= "<th rowspan='2' class='th-netto'>HONOR<br>DITERIMA</th>";

    return ["<tr class='thead-main'>$row1</tr>", $need_row2 ? "<tr class='thead-sub'>$row2</tr>" : ""];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Kuitansi Honorarium Gabungan</title>
<style>
/* ─── RESET & BASE ─── */
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; background: #e8e8e8; color: #000; }

/* ─── PAGE WRAPPER ─── */
.page {
    background: #fff; width: 297mm; min-height: 210mm;
    margin: 8mm auto; padding: 12mm 14mm; box-shadow: 0 2px 15px rgba(0,0,0,.25);
}

/* ─── KOP HEADER KUITANSI (Gambar 3) ─── */
.kop-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 2px solid #ccc; padding-bottom: 10px;}
.kop-kiri { display: flex; align-items: center; }
.kop-teks { margin-left: 15px; font-size: 12px; line-height: 1.6; }
.info-tbl { font-size: 12px; }
.info-tbl td { padding: 1px 4px 1px 0; }
.badge-kuitansi { background-color: #72C05B; color: white; font-weight: bold; padding: 6px 30px; font-size: 15px; letter-spacing: 1px; }

/* ─── TABEL DATA ─── */
table.tbl { width:100%; border-collapse:collapse; margin-bottom:15px; font-size:10px; }
table.tbl th { background:#D6E4F0; color:#000; font-weight:900; padding:6px; border:1px solid #000; text-align:center; line-height:1.3; }
table.tbl .th-grup { background:#FFC107 !important; color:#000; border:1px solid #000; }
table.tbl .th-sub { background:#FFF9C4; color:#000; font-size:9px; font-weight:700; border:1px solid #000; }
table.tbl td { border:1px solid #000; padding:4px 6px; vertical-align:middle; }

/* Kolom tipe */
.td-no     { text-align:center; font-weight:700; }
.td-teks   { text-align:center; }
.td-num    { text-align:right; white-space:nowrap; }
.td-jml    { color:#0d47a1; }
.td-bruto  { font-weight:700; background: #e3f2fd !important; }
.td-vert-label { font-weight:700; background:#f9f9e8; }
.td-subtotal { background-color: #EAF2FB !important; font-weight: bold; text-align: right; }

/* ─── TOTAL BOX BAWAH TABEL ─── */
.grand-total-box { background-color: #00B0F0; border: 2px solid #000; padding: 8px 15px; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; margin-bottom: 25px; }

/* Pemisah halaman */
.page-break { border-bottom:2px dashed #aaa; margin:15px 0 10px; page-break-after:always; }

/* ─── TANDA TANGAN ─── */
.ttd-section { display:flex; justify-content:space-between; margin-top:20px; padding: 0 30px;}
.ttd-box { text-align:center; width: 23%; }
.ttd-box .ttd-lbl { font-weight:700; font-size:11px; margin-bottom:2px; line-height:1.4; height: 30px; }
.ttd-box .ttd-name { font-weight:900; font-size:11px; margin-top: 70px; text-decoration: underline;}

/* ─── TOOLBAR LAYAR ─── */
.toolbar { text-align:center; padding:10px; margin-bottom:8mm; }
.btn-print { background:#1B4F72; color:#fff; border:none; padding:9px 28px; border-radius:5px; font-size:14px; font-weight:700; cursor:pointer; margin-right:6px; }
.btn-close-win { background:#6c757d; color:#fff; border:none; padding:9px 28px; border-radius:5px; font-size:14px; font-weight:700; cursor:pointer; }

/* ─── PRINT ─── */
@media print {
    body  { background:#fff; }
    .toolbar { display:none !important; }
    .page { margin:0; box-shadow:none; width:100%; padding:5mm 10mm; }
    @page { size: A4 landscape; margin:5mm 10mm; }
    .page-break { page-break-after:always; border:none; }
}
</style>
</head>
<body>

<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ Cetak Kuitansi Gabungan (Landscape)</button>
    <button class="btn-close-win" onclick="window.close()">✕ Tutup</button>
</div>

<?php
$total_slips = count($slips);
$slip_idx    = 0;
foreach ($slips as $did => $dosen_data):
    $slip_idx++;
    $is_last = ($slip_idx === $total_slips);
    $info = $dosen_data['info'];
?>
<div class="page">
    
    <!-- 🚀 KOP HEADER MULTI (Gaya Kuitansi Biru) -->
    <div class="kop-header">
        <div class="kop-kiri">
            <?= $logo_img ?>
            <div class="kop-teks">
                Pada hari ini <b><?= date('l') ?></b>, tanggal <b><?= $tanggal_cetak ?></b>, telah ditransfer honorarium.<br>
                <table class="info-tbl">
                    <tr><td width="70">Dari</td><td>: Bendahara Pengelolaan <?= $inst_name ?></td></tr>
                    <tr><td>Kepada</td><td>: <b><?= htmlspecialchars($info['dosen_nama']) ?></b></td></tr>
                    <tr><td>Nominal</td><td>: <b>Rp <?= rp($dosen_data['grand_netto']) ?></b></td></tr>
                    <tr><td>Sejumlah</td><td>: <i><?= terbilang($dosen_data['grand_netto']) ?> Rupiah</i></td></tr>
                </table>
                Dengan rincian sbb :
            </div>
        </div>
        <div>
            <div class="badge-kuitansi">KUITANSI PEMBAYARAN</div>
        </div>
    </div>

    <!-- 🚀 LOOPING TABEL UNTUK SETIAP GENERATE YANG TERPAUT -->
    <?php foreach ($dosen_data['generations'] as $gid => $gen): ?>
        
        <?php
        // Parser Layout
        $layout_cols  = json_decode($gen['layout_json'], true) ?: [];
        $teks_cols    = []; $horiz_groups = []; $vert_info = ['name'=>'', 'header'=>'URAIAN', 'items'=>[]];
        foreach ($layout_cols as $c) {
            if ($c['type'] === 'teks') {
                $teks_cols[] = $c;
            } elseif (($c['group_type'] ?? '') === 'group_vertical') {
                $vert_info['name']   = $c['group'];
                $vert_info['header'] = isset($c['group_header']) ? $c['group_header'] : 'URAIAN';
                $vert_info['items'][] = $c;
            } else {
                $grp = $c['group'] ?? 'RINCIAN';
                $horiz_groups[$grp][] = $c;
            }
        }

        // Hitung Colspan untuk Footer (Subtotal) — TANPA kolom No
        $col_count = 0;
        $col_count += count($teks_cols);
        foreach ($horiz_groups as $gName => $items) {
            $firstItem = $items[0] ?? null;
            $gSingleCol = !empty($firstItem['single_jafung_col']);
            $col_count += $gSingleCol ? 3 : (count($items) * 3);
        }
        if (!empty($vert_info['items'])) $col_count += 4; // label + qty + tarif + jml
        
        [$thead1, $thead2] = buildTableHeader($teks_cols, $horiz_groups, $vert_info, $master_tarif);
        ?>

        <!-- NAMA GENERATE DI ATAS TABEL -->
        <div style="font-size: 11px; font-weight: bold; margin-bottom: 6px; margin-top: 15px; text-transform: uppercase; background: #D6E4F0; padding: 4px 8px; border-left: 4px solid #1B4F72; display: inline-block;">
            RINCIAN: <?= htmlspecialchars($gen['nama_generate']) ?>
        </div>

        <!-- RENDER TABEL UNTUK GENERATE INI -->
        <table class="tbl">
            <thead>
                <?= $thead1 ?>
                <?= $thead2 ?>
            </thead>
            <tbody>
                <?php
                $vItems = $vert_info['items'] ?? [];
                $has_vert = count($vItems) > 0;
                
                // Looping Per Baris Item (Berdasarkan Mata Kuliah/Prodi)
                foreach ($gen['rows'] as $r_idx => $row_data) {
                    
                    // Untuk layout vertikal: hitung hanya item yang benar-benar punya data (qty > 0)
                    $active_vItems = [];
                    if ($has_vert) {
                        foreach ($vItems as $v) {
                            $vrid = (int)$v['id_rincian'];
                            $vk   = $row_data['komponen'][$vrid] ?? null;
                            if ($vk && (float)$vk['qty'] > 0) {
                                $active_vItems[] = $v;
                            }
                        }
                    }
                    $rs = $has_vert ? max(count($active_vItems), 1) : 1;
                    
                    for ($vi = 0; $vi < $rs; $vi++) {
                        echo "<tr class='data-row" . ($vi > 0 ? ' vert-extra' : '') . "'>";
                        
                        // Render Kolom Teks dan Horizontal di baris PERTAMA (vi == 0)
                        if ($vi === 0) {
                            // Kolom Teks
                            foreach ($teks_cols as $t) {
                                $val = '';
                                if ($t['source'] === 'prodi')       $val = htmlspecialchars($row_data['prodi']);
                                if ($t['source'] === 'mata_kuliah') $val = htmlspecialchars($row_data['mata_kuliah']);
                                if ($t['source'] === 'dosen_nama')  $val = htmlspecialchars($row_data['dosen_nama']);
                                if ($t['source'] === 'jabatan')     $val = htmlspecialchars($row_data['jabatan']);
                                echo "<td rowspan='".($has_vert ? ($rs + 1) : $rs)."' class='td-teks fw-bold'>$val</td>";
                            }
                            
                            // Kolom Horizontal
                            foreach ($horiz_groups as $gName => $items) {
                                $firstItem = $items[0] ?? null;
                                $gSingleCol = !empty($firstItem['single_jafung_col']);
                                
                                if ($gSingleCol) {
                                    // Mode 1 Kolom: cari data komponen yang ada (qty > 0)
                                    $found_qty = 0; $found_tarif = 0; $found_jml = 0;
                                    foreach ($items as $it) {
                                        $rid = (int)$it['id_rincian'];
                                        $k   = $row_data['komponen'][$rid] ?? null;
                                        if ($k && (float)$k['qty'] > 0) {
                                            $found_qty   = (float)$k['qty'];
                                            $found_tarif = (float)$k['tarif'];
                                            $found_jml   = $found_qty * $found_tarif;
                                            break;
                                        }
                                    }
                                    $rs_td = $has_vert ? ($rs + 1) : $rs;
                                    echo "<td rowspan='$rs_td' class='td-num'>".($found_qty > 0 ? rp($found_qty) : '-')."</td>";
                                    echo "<td rowspan='$rs_td' class='td-num'>".($found_tarif > 0 ? rp($found_tarif) : '-')."</td>";
                                    echo "<td rowspan='$rs_td' class='td-num td-jml'>".($found_jml > 0 ? rp($found_jml) : '-')."</td>";
                                } else {
                                    // FIX: Tampilkan SEMUA item dari layout (qty=0 → '-')
                                    // Ini memastikan semua kolom komponen honor muncul di slip
                                    $rs_td = $has_vert ? ($rs + 1) : $rs;
                                    foreach ($items as $it) {
                                        $rid   = (int)$it['id_rincian'];
                                        $k     = $row_data['komponen'][$rid] ?? null;
                                        $tarif = (float)($k ? $k['tarif'] : ($master_tarif[$rid]['besaran'] ?? 0));
                                        $qty   = (float)($k ? $k['qty'] : 0);
                                        $jml   = $qty * $tarif;

                                        echo "<td rowspan='$rs_td' class='td-num'>".($qty > 0 ? rp($qty) : '-')."</td>";
                                        echo "<td rowspan='$rs_td' class='td-num'>".($tarif > 0 ? rp($tarif) : '-')."</td>";
                                        echo "<td rowspan='$rs_td' class='td-num td-jml'>".($jml > 0 ? rp($jml) : '-')."</td>";
                                    }
                                }
                            }
                        }
                        
                        // Render Kolom Vertikal (Hanya item dengan data)
                        if ($has_vert && isset($active_vItems[$vi])) {
                            $v    = $active_vItems[$vi];
                            $rid  = (int)$v['id_rincian'];
                            $k    = $row_data['komponen'][$rid] ?? null;
                            $tarif = $k ? $k['tarif'] : ($master_tarif[$rid]['besaran'] ?? 0);
                            $qty  = $k ? $k['qty'] : 0;
                            $jml  = $qty * $tarif;
                            
                            echo "<td class='td-vert-label'>".htmlspecialchars($v['label'])."</td>";
                            echo "<td class='td-num'>".($qty > 0 ? rp($qty) : '-')."</td>";
                            echo "<td class='td-num'>".($tarif > 0 ? rp($tarif) : '-')."</td>";
                            echo "<td class='td-num td-jml'>".($jml > 0 ? rp($jml) : '-')."</td>";

                            // Untuk layout vertikal: tampilkan bruto, pajak, netto per baris uraian
                            $row_pajak_pct = (float)$row_data['pajak_pct'];
                            $item_bruto    = $jml;
                            $item_pajak    = round($item_bruto * $row_pajak_pct / 100);
                            $item_netto    = $item_bruto - $item_pajak;
                            echo "<td class='td-num td-bruto text-dark'>".($item_bruto > 0 ? rp($item_bruto) : '-')."</td>";
                            echo "<td class='td-num text-danger fw-bold'>".($item_pajak > 0 ? rp($item_pajak) : '-')."</td>";
                            echo "<td class='td-num fw-bold' style='background: #ccffcc !important;'>".($item_netto > 0 ? rp($item_netto) : '-')."</td>";
                        } else if (!$has_vert) {
                            // Kosong jika tidak ada grup vertikal sama sekali
                        } else {
                             // Filler jika kolom vertikal habis
                             echo "<td></td><td></td><td></td><td></td>";
                             echo "<td></td><td></td><td></td>";
                        }
                        
                        // Untuk layout NON-vertikal: render Total Bruto, Pajak, Netto di baris pertama (merge)
                        if (!$has_vert && $vi === 0) {
                            echo "<td rowspan='$rs' class='td-num td-bruto text-dark'>".rp($row_data['row_bruto'])."</td>";
                            echo "<td rowspan='$rs' class='td-num text-danger fw-bold'>".rp($row_data['row_pajak'])."</td>";
                            echo "<td rowspan='$rs' class='td-num fw-bold' style='background: #ccffcc !important;'>".rp($row_data['row_netto'])."</td>";
                        }
                        
                        echo "</tr>";
                    }

                    // Untuk layout vertikal: tambahkan baris TOTAL di bawah semua item vertikal
                    if ($has_vert) {
                        echo "<tr class='data-row' style='background:#e3f2fd !important;'>";
                        // Kolom uraian label = TOTAL
                        echo "<td class='td-vert-label fw-bold text-primary' style='text-align:center;'>TOTAL</td>";
                        // Kosongkan kolom qty, tarif, jml vertikal
                        echo "<td class='td-num fw-bold'>-</td>";
                        echo "<td class='td-num fw-bold'>-</td>";
                        echo "<td class='td-num fw-bold'>-</td>";
                        // Total bruto, pajak, netto (dijumlahkan)
                        echo "<td class='td-num td-bruto text-dark fw-bold' style='background:#e3f2fd !important;'>".rp($row_data['row_bruto'])."</td>";
                        echo "<td class='td-num text-danger fw-bold'>".rp($row_data['row_pajak'])."</td>";
                        echo "<td class='td-num fw-bold' style='background: #b2f5b2 !important; font-size:11px;'>".rp($row_data['row_netto'])."</td>";
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
            
            <!-- SUBTOTAL PER GENERATE (Sesuai Gambar 3) -->
            <tfoot>
                <tr class="td-subtotal">
                    <td colspan="<?= $col_count ?>" style="border: 1px solid #000;">Jumlah Honor <?= htmlspecialchars($gen['nama_generate']) ?></td>
                    <td colspan="3" style="text-align: center; border: 1px solid #000; color: #1b5e20;">Rp <?= rp($gen['sub_netto']) ?></td>
                </tr>
            </tfoot>
        </table>

    <?php endforeach; ?>

    <!-- GRAND TOTAL BIRU (Sesuai Gambar 3) -->
    <div class="grand-total-box">
        <span>Total Honor yang diterima</span>
        <span>Rp <?= rp($dosen_data['grand_netto']) ?></span>
    </div>

    <!-- TANDA TANGAN (DYNAMIC) -->
    <div class="ttd-section">
        <?php foreach($signatures as $sig): 
            $nam = $sig['sign_name'];
            if(stripos($sig['sign_role'], 'Penerima') !== false || stripos($sig['sign_position'], 'Penerima') !== false || strpos($nam, '[NAMA_DOSEN]') !== false) {
                $nam = $info['dosen_nama'];
            }
        ?>
        <div class="ttd-box">
            <div class="ttd-lbl"><?= htmlspecialchars_decode($sig['sign_role']) ?><br><?= htmlspecialchars($sig['sign_position']) ?></div>
            <div class="ttd-name"><?= htmlspecialchars($nam) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

</div>
<?php if (!$is_last) echo '<div class="page-break"></div>'; ?>
<?php endforeach; ?>

</body>
</html>
