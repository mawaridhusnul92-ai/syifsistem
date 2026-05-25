<?php
// ENGINE PERIODE & KOMPARASI
$period_type = $_GET['period_type'] ?? 'Tahunan';
$tahun = $_GET['tahun'] ?? date('Y');
$bulan = $_GET['bulan'] ?? date('m');
$triwulan = $_GET['triwulan'] ?? '1';
$semester = $_GET['semester'] ?? '1';
$is_compare = isset($_GET['is_compare']) && $_GET['is_compare'] == '1';

$comp_period_type = $_GET['comp_period_type'] ?? 'Tahunan';
$comp_tahun = $_GET['comp_tahun'] ?? ($tahun - 1);
$comp_bulan = $_GET['comp_bulan'] ?? $bulan;
$comp_triwulan = $_GET['comp_triwulan'] ?? $triwulan;
$comp_semester = $_GET['comp_semester'] ?? $semester;

$start_date = "$tahun-01-01"; $end_date = "$tahun-12-31";
$start_comp = "$comp_tahun-01-01"; $end_comp = "$comp_tahun-12-31";
$period_label = "Tahun $tahun"; $comp_label = "Tahun $comp_tahun";

$nama_bulan = ["", "Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];

if ($period_type == 'Bulanan') {
    $start_date = "$tahun-$bulan-01"; $end_date = date('Y-m-t', strtotime($start_date));
    $period_label = "Bulan " . $nama_bulan[(int)$bulan] . " $tahun";
} elseif ($period_type == 'Triwulan') {
    if ($triwulan == '1') { $start_date = "$tahun-01-01"; $end_date = "$tahun-03-31"; }
    elseif ($triwulan == '2') { $start_date = "$tahun-04-01"; $end_date = "$tahun-06-30"; }
    elseif ($triwulan == '3') { $start_date = "$tahun-07-01"; $end_date = "$tahun-09-30"; }
    elseif ($triwulan == '4') { $start_date = "$tahun-10-01"; $end_date = "$tahun-12-31"; }
    $period_label = "Triwulan $triwulan Tahun $tahun"; 
} elseif ($period_type == 'Semester') {
    if ($semester == '1') { $start_date = "$tahun-01-01"; $end_date = "$tahun-06-30"; }
    elseif ($semester == '2') { $start_date = "$tahun-07-01"; $end_date = "$tahun-12-31"; }
    $period_label = "Semester $semester Tahun $tahun"; 
}

if ($is_compare) {
    if ($comp_period_type == 'Bulanan') {
        $start_comp = "$comp_tahun-$comp_bulan-01"; $end_comp = date('Y-m-t', strtotime($start_comp));
        $comp_label = "Bulan " . $nama_bulan[(int)$comp_bulan] . " $comp_tahun";
    } elseif ($comp_period_type == 'Triwulan') {
        if ($comp_triwulan == '1') { $start_comp = "$comp_tahun-01-01"; $end_comp = "$comp_tahun-03-31"; }
        elseif ($comp_triwulan == '2') { $start_comp = "$comp_tahun-04-01"; $end_comp = "$comp_tahun-06-30"; }
        elseif ($comp_triwulan == '3') { $start_comp = "$comp_tahun-07-01"; $end_comp = "$comp_tahun-09-30"; }
        elseif ($comp_triwulan == '4') { $start_comp = "$comp_tahun-10-01"; $end_comp = "$comp_tahun-12-31"; }
        $comp_label = "Triwulan $comp_triwulan Tahun $comp_tahun";
    } elseif ($comp_period_type == 'Semester') {
        if ($comp_semester == '1') { $start_comp = "$comp_tahun-01-01"; $end_comp = "$comp_tahun-06-30"; }
        elseif ($comp_semester == '2') { $start_comp = "$comp_tahun-07-01"; $end_comp = "$comp_tahun-12-31"; }
        $comp_label = "Semester $comp_semester Tahun $comp_tahun";
    }
}

// LOGIKA SALDO AKUN
function getLedgerBalances($conn, $start, $end) {
    $bals = [];
    $q = $conn->query("SELECT kode_akun, normal_balance, opening_balance FROM syifa_akun WHERE is_group=0 AND is_active=1");
    while($r = $q->fetch_assoc()) { $bals[$r['kode_akun']] = (double)$r['opening_balance']; }
    
    $mut_n = []; 
    $q_n = $conn->query("SELECT jd.kode_akun, SUM(jd.debit) as deb, SUM(jd.kredit) as kre FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE j.tgl_jurnal <= '$end' AND j.is_deleted = 0 GROUP BY jd.kode_akun");
    if($q_n) { while($r=$q_n->fetch_assoc()) $mut_n[$r['kode_akun']] = $r; }
    
    $mut_lr = []; 
    $q_lr = $conn->query("SELECT jd.kode_akun, SUM(jd.debit) as deb, SUM(jd.kredit) as kre FROM syifa_jurnal_detail jd JOIN syifa_jurnal j ON jd.jurnal_id = j.id WHERE j.tgl_jurnal BETWEEN '$start' AND '$end' AND j.is_deleted = 0 GROUP BY jd.kode_akun");
    if($q_lr) { while($r=$q_lr->fetch_assoc()) $mut_lr[$r['kode_akun']] = $r; }

    $final = [];
    $q->data_seek(0);
    while($r = $q->fetch_assoc()) {
        $k = $r['kode_akun']; $nb = strtoupper($r['normal_balance']);
        $deb = (double)($mut_n[$k]['deb']??0); $kre = (double)($mut_n[$k]['kre']??0);
        $final[$k] = $bals[$k] + (($nb=='KREDIT'||$nb=='K') ? ($kre - $deb) : ($deb - $kre));
    }
    return $final;
}

$curr_bal = getLedgerBalances($conn, $start_date, $end_date);
$prev_bal = $is_compare ? getLedgerBalances($conn, $start_comp, $end_comp) : null;

$all_accounts_json = [];
$q_all = $conn->query("SELECT kode_akun, nama_akun, kategori FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun ASC");
if($q_all) { while($row = $q_all->fetch_assoc()) { $all_accounts_json[] = $row; } }

function buildAssetMatrix($conn, $start, $end) {
    if(empty($start) || empty($end)) return null;

    $cats = [['label'=>'ASET TETAP BERWUJUD', 'type'=>'Tetap'], ['label'=>'ASET TETAP TIDAK BERWUJUD', 'type'=>'Tidak Terwujud']];
    $result = ['cats' => [], 'grand' => array_fill(0, 7, 0)];

    foreach($cats as $c) {
        $cat_data = ['label' => $c['label'], 'types' => [], 'subtotal' => array_fill(0, 7, 0)];
        $sql_types = "SELECT t.id, t.type_name FROM asset_types t JOIN asset_categories ac ON t.category_id = ac.id WHERE ac.asset_type = '{$c['type']}' ORDER BY t.type_name ASC";
        $res_types = $conn->query($sql_types);
        
        while($t = $res_types->fetch_assoc()) {
            $type_data = ['type_name' => $t['type_name'], 'items' => [], 'type_total' => array_fill(0, 7, 0)];
            $sql_items = "SELECT * FROM assets WHERE type_id = {$t['id']} AND status='Aktif' AND purchase_date <= '$end' ORDER BY asset_name ASC";
            $res_items = $conn->query($sql_items);
            
            while($item = $res_items->fetch_assoc()) {
                $id_ast = $item['id'];
                
                $prev_date = date('Y-m-d', strtotime($start . ' -1 day'));
                $awal_p = (strtotime($item['purchase_date']) <= strtotime($prev_date)) ? (double)$item['purchase_value'] : 0;
                $tambah_p_awal = $conn->query("SELECT SUM(nilai_penambahan) as v FROM asset_improvements WHERE asset_id=$id_ast AND tanggal <= '$prev_date' AND jenis_penambahan != 'Perolehan Awal'")->fetch_assoc()['v'] ?? 0;
                $bruto = $awal_p + (double)$tambah_p_awal;
                
                $awal_s = ($item['purchase_mode'] == 'saldo_awal') ? (double)$item['residual_value'] : 0;
                $tambah_s_awal = $conn->query("SELECT SUM(nilai_susut) as v FROM asset_depreciation ad WHERE ad.asset_id = $id_ast AND STR_TO_DATE(CONCAT(ad.periode_tahun, '-', LPAD(ad.periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') <= '$prev_date'")->fetch_assoc()['v'] ?? 0;
                $akum = $awal_s + (double)$tambah_s_awal;

                $mut_p = (strtotime($item['purchase_date']) >= strtotime($start) && strtotime($item['purchase_date']) <= strtotime($end)) ? (double)$item['purchase_value'] : 0;
                $tambah_p_mut = $conn->query("SELECT SUM(nilai_penambahan) as v FROM asset_improvements WHERE asset_id=$id_ast AND tanggal BETWEEN '$start' AND '$end' AND jenis_penambahan != 'Perolehan Awal'")->fetch_assoc()['v'] ?? 0;
                $add = $mut_p + (double)$tambah_p_mut;
                
                $depr = $conn->query("SELECT SUM(nilai_susut) as v FROM asset_depreciation WHERE asset_id = $id_ast AND STR_TO_DATE(CONCAT(periode_tahun, '-', LPAD(periode_bulan, 2, '0'), '-01'), '%Y-%m-%d') BETWEEN '$start' AND '$end'")->fetch_assoc()['v'] ?? 0;
                
                $akhir_bruto = $bruto + $add; $akhir_akum  = $akum + $depr; $nbv = $akhir_bruto - $akhir_akum;
                if($akhir_bruto == 0) continue;

                $type_data['items'][] = [ 'name' => $item['asset_name'], 'code' => $item['asset_code'], 'awal_bruto' => $bruto, 'awal_akum' => $akum, 'add' => $add, 'depr' => $depr, 'akhir_bruto' => $akhir_bruto, 'akhir_akum' => $akhir_akum, 'nbv' => $nbv ];
                $type_data['type_total'][0] += $bruto; $type_data['type_total'][1] += $akum; $type_data['type_total'][2] += $add; $type_data['type_total'][3] += $depr; $type_data['type_total'][4] += $akhir_bruto; $type_data['type_total'][5] += $akhir_akum; $type_data['type_total'][6] += $nbv;
            }
            if(count($type_data['items']) > 0) { $cat_data['types'][] = $type_data; for($i=0; $i<7; $i++) $cat_data['subtotal'][$i] += $type_data['type_total'][$i]; }
        }
        if(count($cat_data['types']) > 0) { $result['cats'][] = $cat_data; for($i=0; $i<7; $i++) $result['grand'][$i] += $cat_data['subtotal'][$i]; }
    }
    return $result;
}

$matrix_curr = buildAssetMatrix($conn, $start_date, $end_date);
$matrix_prev = $is_compare ? buildAssetMatrix($conn, $start_comp, $end_comp) : null;

// BUILD DATA VIEW
$calk_config_raw = @file_get_contents($calk_config_file);
$calk_config = $calk_config_raw ? json_decode($calk_config_raw, true) : [];
$calk_view = [];

if(is_array($calk_config) && isset($calk_config['categories'])) {
    foreach ($calk_config['categories'] as $cat) {
        $cat_data = ['name' => $cat['name'], 'type' => $cat['type'], 'items' => [], 'subtotal' => 0, 'subtotal_prev' => 0, 'text_content' => $cat['text_content'] ?? ''];
        if ($cat['type'] == 'normal') {
            foreach ($cat['accounts'] as $acct_code) {
                $s_curr = $curr_bal[$acct_code] ?? 0;
                $s_prev = $is_compare ? ($prev_bal[$acct_code] ?? 0) : 0;
                if ($s_curr != 0 || $s_prev != 0) {
                    $nama = 'Akun Terhapus';
                    foreach($all_accounts_json as $a) {
                        if($a['kode_akun'] == $acct_code) { $nama = $a['nama_akun']; break; }
                    }
                    $cat_data['items'][] = ['name' => $nama, 'kode' => $acct_code, 's_curr' => $s_curr, 's_prev' => $s_prev];
                    $cat_data['subtotal'] += $s_curr; $cat_data['subtotal_prev'] += $s_prev;
                }
            }
        }
        $calk_view[] = $cat_data;
    }
}

function renderAssetMatrixHTML($data, $label) {
    if(empty($data['cats'])) return "<div class='text-center py-3 text-muted fst-italic'>Tidak ada pergerakan aset pada $label.</div>";
    
    $html = "<div class='mb-2 fw-bold text-dark'><i class='fas fa-calendar-alt me-1 text-dark no-print'></i> $label</div>";
    $html .= "<div class='table-responsive border rounded-3 p-0 mb-4'><table class='tbl-asset' style='table-layout: fixed; width: 100%; word-wrap: break-word;'>";
    $html .= "<thead><tr><th style='width: 32%; text-align:center; background:#fff; color:#000; border: 1px solid #000;'>KETERANGAN</th><th style='width: 17%; text-align:center; background:#fff; color:#000; border: 1px solid #000;'>SALDO AWAL</th><th style='width: 17%; text-align:center; background:#fff; color:#000; border: 1px solid #000;'>PENAMBAHAN</th><th style='width: 17%; text-align:center; background:#fff; color:#000; border: 1px solid #000;'>PENGURANGAN</th><th style='width: 17%; text-align:center; background:#fff; color:#000; border: 1px solid #000;'>SALDO AKHIR</th></tr></thead><tbody>";
    
    foreach($data['cats'] as $c) {
        $html .= "<tr class='row-cat-header'><td colspan='5' class='calk-indent fw-bold text-dark' style='background:#fff; font-size:8pt;'>{$c['label']}</td></tr>";
        $html .= "<tr class='sub-header'><td colspan='5' class='calk-indent text-dark fw-bold'>A. NILAI PEROLEHAN</td></tr>";
        $sub_p = [0,0,0,0];
        foreach($c['types'] as $t) {
            $html .= "<tr class='row-type-header'><td colspan='5' class='indent-item' style='background:#fff; font-style:italic; color:#000;'>[ {$t['type_name']} ]</td></tr>";
            foreach($t['items'] as $item) {
                $html .= "<tr><td class='indent-item' style='padding-left:30px; overflow-wrap: break-word; color:#000;'>{$item['name']} <br><small class='text-dark' style='font-size:7pt;'>{$item['code']}</small></td>";
                $html .= "<td style='font-size:7pt; color:#000;'>".fmtAudAsset($item['awal_bruto'])."</td><td style='font-size:7pt; color:#000;'>".fmtAudAsset($item['add'])."</td><td style='font-size:7pt; color:#000;'>".fmtAudAsset(0)."</td><td style='font-size:7pt; color:#000;'><b>".fmtAudAsset($item['akhir_bruto'])."</b></td></tr>";
            }
            $sub_p[0] += $t['type_total'][0]; $sub_p[1] += $t['type_total'][2]; $sub_p[2] += 0; $sub_p[3] += $t['type_total'][4];
        }
        $html .= "<tr class='row-subtotal'><td class='indent-item fw-bold' style='padding-left:30px; font-size:7pt !important; color:#000;'>Subtotal Nilai Perolehan</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_p[0],true)."</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_p[1],true)."</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_p[2],true)."</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_p[3],true)."</td></tr>";
        
        $html .= "<tr><td colspan='5' style='border:none; height:10px;'></td></tr>"; 
        $html .= "<tr class='sub-header'><td colspan='5' class='calk-indent text-dark fw-bold'>B. AKUMULASI PENYUSUTAN</td></tr>";
        $sub_s = [0,0,0,0];
        foreach($c['types'] as $t) {
            $html .= "<tr class='row-type-header'><td colspan='5' class='indent-item' style='background:#fff; font-style:italic; color:#000;'>[ {$t['type_name']} ]</td></tr>";
            foreach($t['items'] as $item) {
                $html .= "<tr><td class='indent-item' style='padding-left:30px; overflow-wrap: break-word; color:#000;'>{$item['name']}</td>";
                $html .= "<td style='font-size:7pt; color:#000;'>".fmtAudAsset($item['awal_akum'])."</td><td style='font-size:7pt; color:#000;'>".fmtAudAsset($item['depr'])."</td><td style='font-size:7pt; color:#000;'>".fmtAudAsset(0)."</td><td style='font-size:7pt; color:#000;'><b>".fmtAudAsset($item['akhir_akum'])."</b></td></tr>";
            }
            $sub_s[0] += $t['type_total'][1]; $sub_s[1] += $t['type_total'][3]; $sub_s[2] += 0; $sub_s[3] += $t['type_total'][5];
        }
        $html .= "<tr class='row-subtotal'><td class='indent-item fw-bold' style='padding-left:30px; font-size:7pt !important; color:#000;'>Subtotal Akum. Penyusutan</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_s[0],true)."</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_s[1],true)."</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_s[2],true)."</td><td style='font-size:7pt !important; color:#000;'>".fmtAudAsset($sub_s[3],true)."</td></tr>";
        
        $nbv_awal = $sub_p[0] - $sub_s[0]; $nbv_akhir = $sub_p[3] - $sub_s[3];
        $html .= "<tr class='row-grand-total' style='background:#f1f5f9; color:#000;'><td class='text-end pe-3 fw-bold' style='font-size:7.5pt !important;'>C. NILAI BUKU NETO (A - B)</td><td style='font-size:7.5pt !important; color:#000;'>".fmtAudAsset($nbv_awal,true)."</td><td></td><td></td><td style='font-size:7.5pt !important; color:#000;'>".fmtAudAsset($nbv_akhir,true)."</td></tr>";
        $html .= "<tr><td colspan='5' style='border:none; height:15px;'></td></tr>";
    }
    $html .= "</tbody></table></div>";
    return $html;
}
?>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-center mb-4 gap-3 border-bottom pb-4">
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center bg-light p-2 border rounded-pill shadow-sm">
        <input type="hidden" name="page" value="laporan_calk"><input type="hidden" name="tab" value="detail">
        
        <div class="form-check form-switch ms-2 me-1">
            <input class="form-check-input" type="checkbox" name="is_compare" value="1" id="chkCompare" <?= $is_compare?'checked':'' ?> onchange="toggleComparePanel()">
            <label class="form-check-label small fw-bold text-primary" for="chkCompare">Komparasi</label>
        </div>
        <div class="border-start border-end px-2 mx-1 d-flex gap-2 align-items-center">
            <span class="small fw-bold text-muted">Periode:</span>
            <select name="period_type" id="period_type" class="form-select form-select-sm border-0 bg-transparent fw-bold text-dark shadow-none" onchange="togglePeriodOpts()" style="width:100px;">
                <option value="Bulanan" <?= $period_type=='Bulanan'?'selected':'' ?>>Bulanan</option><option value="Triwulan" <?= $period_type=='Triwulan'?'selected':'' ?>>Triwulan</option><option value="Semester" <?= $period_type=='Semester'?'selected':'' ?>>Semester</option><option value="Tahunan" <?= $period_type=='Tahunan'?'selected':'' ?>>Tahunan</option>
            </select>
            <select name="bulan" id="opt_bulan" class="form-select form-select-sm border-0 bg-white rounded-pill fw-bold shadow-sm" style="width:110px;"><?php for($m=1; $m<=12; $m++) echo "<option value='".sprintf("%02d", $m)."' ".($bulan==$m?'selected':'').">".$nama_bulan[$m]."</option>"; ?></select>
            <select name="triwulan" id="opt_triwulan" class="form-select form-select-sm border-0 bg-white rounded-pill fw-bold shadow-sm" style="width:90px;"><option value="1" <?= $triwulan=='1'?'selected':'' ?>>Q1</option><option value="2" <?= $triwulan=='2'?'selected':'' ?>>Q2</option><option value="3" <?= $triwulan=='3'?'selected':'' ?>>Q3</option><option value="4" <?= $triwulan=='4'?'selected':'' ?>>Q4</option></select>
            <select name="semester" id="opt_semester" class="form-select form-select-sm border-0 bg-white rounded-pill fw-bold shadow-sm" style="width:100px;"><option value="1" <?= $semester=='1'?'selected':'' ?>>Sem 1</option><option value="2" <?= $semester=='2'?'selected':'' ?>>Sem 2</option></select>
            <select name="tahun" class="form-select form-select-sm border-0 bg-white rounded-pill fw-bold shadow-sm text-primary" style="width:90px;"><?php for($y=date('Y'); $y>=2020; $y--) echo "<option value='$y' ".($tahun==$y?'selected':'').">$y</option>"; ?></select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold shadow-sm px-4">Terapkan</button>
    </form>
</div>

<form method="GET" id="comparePanel" class="bg-primary bg-opacity-10 p-3 rounded-4 border border-primary border-opacity-25 mb-4" style="display: <?= $is_compare?'block':'none' ?>;">
    <input type="hidden" name="page" value="laporan_calk"><input type="hidden" name="tab" value="detail"><input type="hidden" name="is_compare" value="1">
    <input type="hidden" name="period_type" value="<?= $period_type ?>"><input type="hidden" name="bulan" value="<?= $bulan ?>"><input type="hidden" name="triwulan" value="<?= $triwulan ?>"><input type="hidden" name="semester" value="<?= $semester ?>"><input type="hidden" name="tahun" value="<?= $tahun ?>">
    
    <label class="small fw-bold text-dark mb-2"><i class="fas fa-exchange-alt me-2 text-primary"></i>Pilih Periode Pembanding (Untuk Kolom Kanan / Tabel Bawah)</label>
    <div class="input-group input-group-sm shadow-sm rounded-pill overflow-hidden border bg-white" style="max-width: 500px;">
        <select name="comp_period_type" id="comp_period_type" class="form-select border-0 fw-bold" onchange="toggleCompPeriodOpts()">
            <option value="Bulanan" <?= $comp_period_type=='Bulanan'?'selected':'' ?>>Bulanan</option><option value="Triwulan" <?= $comp_period_type=='Triwulan'?'selected':'' ?>>Triwulan</option><option value="Semester" <?= $comp_period_type=='Semester'?'selected':'' ?>>Semester</option><option value="Tahunan" <?= $comp_period_type=='Tahunan'?'selected':'' ?>>Tahunan</option>
        </select>
        <select name="comp_bulan" id="comp_opt_bulan" class="form-select border-0 bg-light fw-bold"><?php for($m=1; $m<=12; $m++) echo "<option value='".sprintf("%02d", $m)."' ".($comp_bulan==$m?'selected':'').">".$nama_bulan[$m]."</option>"; ?></select>
        <select name="comp_triwulan" id="comp_opt_triwulan" class="form-select border-0 bg-light fw-bold"><option value="1" <?= $comp_triwulan=='1'?'selected':'' ?>>Q1</option><option value="2" <?= $comp_triwulan=='2'?'selected':'' ?>>Q2</option><option value="3" <?= $comp_triwulan=='3'?'selected':'' ?>>Q3</option><option value="4" <?= $comp_triwulan=='4'?'selected':'' ?>>Q4</option></select>
        <select name="comp_semester" id="comp_opt_semester" class="form-select border-0 bg-light fw-bold"><option value="1" <?= $comp_semester=='1'?'selected':'' ?>>Sem 1</option><option value="2" <?= $comp_semester=='2'?'selected':'' ?>>Sem 2</option></select>
        <select name="comp_tahun" class="form-select border-0 bg-light fw-bold" style="color: inherit;"><?php for($y=date('Y'); $y>=2020; $y--) echo "<option value='$y' ".($comp_tahun==$y?'selected':'').">$y</option>"; ?></select>
        <button type="submit" class="btn btn-primary fw-bold px-3">Sync Komparasi</button>
    </div>
</form>

<div id="calk-viewer">
    
    <!-- THE PHANTOM CONTAINER -->
    <div id="calk-naratif-container" style="display: none !important; position: absolute; left: -9999px; visibility: hidden;">
        <?php if(!empty($calk_data)): ?>
            <?php foreach($calk_data as $nar): ?>
                <div class="h2-report" style="color:#000; font-weight:bold; font-size:11pt; margin-bottom:5px; margin-top:15px; text-transform:uppercase;"><?= htmlspecialchars($nar['title'] ?? '') ?></div>
                <div class="calk-text text-dark" style="color:#000; font-size:10pt; line-height:1.5; text-align:justify; margin-bottom:15px;">
                    <?= $nar['content'] ?? '' ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- KONTEN TABEL NOMINAL -->
    <div id="calk-tabel-container">
        <?php if(empty($calk_view)): ?>
            <div class="text-center py-5 border rounded-4 bg-light mb-4 no-print">
                <i class="fas fa-folder-open fa-3x text-muted opacity-25 mb-3"></i>
                <h6 class="fw-bold text-muted">Format Tabel CALK Kosong</h6>
                <p class="small text-muted">Silakan klik "Mode Setup CALK" untuk menyusun struktur laporan Anda secara dinamis.</p>
            </div>
        <?php endif; ?>

        <?php foreach($calk_view as $kat): ?>
            <?php if($kat['type'] == 'text'): ?>
                <div class="mb-4 bg-white p-4 rounded-4 border shadow-sm">
                    <?php if($kat['name'] !== '' && stripos($kat['name'], 'Kategori Baru') === false && $kat['name'] !== 'Kategori CALK Baru'): ?>
                        <h6 class="fw-bold text-dark text-uppercase mb-2 border-bottom pb-2"><?= htmlspecialchars($kat['name']) ?></h6>
                    <?php endif; ?>
                    <p class="text-dark mb-0" style="line-height: 1.6; text-align: justify;"><?= nl2br(htmlspecialchars($kat['text_content'])) ?></p>
                </div>
            <?php else: ?>
                <div class="mb-4">
                    <?php if($kat['type'] == 'fixed_asset'): ?>
                        <div class="p-3 border rounded-3">
                            <?= renderAssetMatrixHTML($matrix_curr, "Periode Utama: " . $period_label) ?>
                            <?php if($is_compare): ?>
                                <div class="mt-4 pt-3 border-top">
                                    <?= renderAssetMatrixHTML($matrix_prev, "Periode Pembanding: " . $comp_label) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="mb-2 fw-bold text-dark text-uppercase"><?= htmlspecialchars($kat['name']) ?></div>
                        <table class="table table-bordered align-middle mb-0 bg-white" style="color: #000;">
                            <thead>
                                <tr>
                                    <th class="ps-3 text-dark" style="border-bottom: 2px solid #000; font-weight: bold;">Nama Akun (COA)</th>
                                    <th class="text-end pe-3 text-dark" style="border-bottom: 2px solid #000; font-weight: bold;" width="<?= $is_compare?'200':'300' ?>"><?= $period_label ?> (Rp)</th>
                                    <?php if($is_compare): ?>
                                    <th class="text-end pe-3 text-dark" style="border-bottom: 2px solid #000; font-weight: bold;" width="200"><?= $comp_label ?> (Rp)</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($kat['items'] as $a): ?>
                                    <tr>
                                        <td class="ps-4"><code><?= $a['kode'] ?></code> <?= $a['name'] ?></td>
                                        <td class="text-end pe-3 text-dark"><div style="display:inline-flex; justify-content:space-between; min-width:110px; width:100%; text-align:right;"><span>Rp</span><span><?= formatAkuntansi($a['s_curr']) ?></span></div></td>
                                        <?php if($is_compare): ?>
                                        <td class="text-end pe-3 text-dark"><div style="display:inline-flex; justify-content:space-between; min-width:110px; width:100%; text-align:right;"><span>Rp</span><span><?= formatAkuntansi($a['s_prev']) ?></span></div></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td class="text-end fw-bold text-dark">Subtotal:</td>
                                    <td class="text-end fw-bold pe-3 text-dark"><div style="display:inline-flex; justify-content:space-between; min-width:110px; width:100%; text-align:right;"><span>Rp</span><span><?= formatAkuntansi($kat['subtotal']) ?></span></div></td>
                                    <?php if($is_compare): ?>
                                    <td class="text-end fw-bold pe-3 text-dark"><div style="display:inline-flex; justify-content:space-between; min-width:110px; width:100%; text-align:right;"><span>Rp</span><span><?= formatAkuntansi($kat['subtotal_prev']) ?></span></div></td>
                                    <?php endif; ?>
                                </tr>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div id="calk-builder" style="display:none;" class="no-print">
    <div class="alert bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25 rounded-4 mb-4 small fw-bold">
        <i class="fas fa-hammer me-2 text-warning"></i><b>CALK DYNAMIC BUILDER:</b> Anda bisa menyusun format sesuka hati. Tambahkan Kategori Normal, Matriks Aset, atau Teks Paragraf Bebas.
    </div>
    
    <form method="POST" id="formSaveBuilder">
        <input type="hidden" name="save_calk_config" value="1">
        <input type="hidden" name="calk_json_data" id="calk_json_data">
        <?php foreach($_GET as $k => $v): if(!is_array($v) && !in_array($k, ['page','tab','ajax'])) echo "<input type='hidden' name='".htmlspecialchars($k)."' value='".htmlspecialchars($v)."'>"; endforeach; ?>
        
        <div id="builder-container"></div>
        
        <div class="d-flex justify-content-between align-items-center border-top pt-4 mt-4">
            <button type="button" class="btn btn-outline-dark rounded-pill fw-bold border-2 shadow-sm px-4" onclick="addCategory()"><i class="fas fa-plus me-2"></i>TAMBAH ELEMEN (BARIS BARU)</button>
            <button type="submit" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow"><i class="fas fa-save me-2"></i>SIMPAN KONFIGURASI</button>
        </div>
    </form>
</div>

<div class="modal fade" id="modalSelectAccounts" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h6 class="fw-bold mb-0 text-white"><i class="fas fa-link me-2"></i>Hubungkan Akun ke Kategori</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <input type="text" class="form-control rounded-pill border-0 shadow-sm px-3 mb-3 fw-bold" placeholder="Cari kode atau nama akun..." id="searchAccBuilder" onkeyup="filterAccBuilder()">
                <div id="accountListContainer" class="bg-white border rounded-4 p-3 shadow-sm" style="max-height: 400px; overflow-y: auto;"></div>
            </div>
            <div class="modal-footer border-0 p-3 bg-white">
                <button type="button" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-sm" onclick="saveSelectedAccounts()">SIMPAN PILIHAN AKUN</button>
            </div>
        </div>
    </div>
</div>

<script>
function togglePeriodOpts() {
    let t = document.getElementById('period_type'); if(!t) return;
    document.getElementById('opt_bulan').style.display = t.value == 'Bulanan' ? 'block' : 'none';
    document.getElementById('opt_triwulan').style.display = t.value == 'Triwulan' ? 'block' : 'none';
    document.getElementById('opt_semester').style.display = t.value == 'Semester' ? 'block' : 'none';
}
function toggleCompPeriodOpts() {
    let t = document.getElementById('comp_period_type'); if(!t) return;
    document.getElementById('comp_opt_bulan').style.display = t.value == 'Bulanan' ? 'block' : 'none';
    document.getElementById('comp_opt_triwulan').style.display = t.value == 'Triwulan' ? 'block' : 'none';
    document.getElementById('comp_opt_semester').style.display = t.value == 'Semester' ? 'block' : 'none';
}
function toggleComparePanel() {
    let cp = document.getElementById('comparePanel');
    let chk = document.getElementById('chkCompare');
    if(cp && chk) cp.style.display = chk.checked ? 'block' : 'none';
}
if(document.getElementById('period_type')) { togglePeriodOpts(); toggleCompPeriodOpts(); }

let calkCategories = <?= isset($calk_config['categories']) ? json_encode($calk_config['categories']) : '[]' ?>;
let allAccounts = <?= json_encode($all_accounts_json ?? []) ?>;

function toggleBuilder() {
    const v = document.getElementById('calk-viewer');
    const b = document.getElementById('calk-builder');
    const btn = document.getElementById('btnToggleBuilder');
    if(!v || !b) return;
    if(v.style.display === 'none') {
        v.style.display = 'block'; b.style.display = 'none';
        if(btn) { btn.innerHTML = '<i class="fas fa-hammer me-2"></i> Mode Setup CALK'; btn.className = 'btn btn-warning rounded-pill fw-bold shadow px-4 text-dark'; }
        renderBuilder(); 
    } else {
        v.style.display = 'none'; b.style.display = 'block';
        if(btn) { btn.innerHTML = '<i class="fas fa-times me-2"></i> Tutup Setup (Lihat Hasil)'; btn.className = 'btn btn-dark rounded-pill fw-bold shadow px-4 text-white'; }
        renderBuilder();
    }
}

function updateJsonData() { const inp = document.getElementById('calk_json_data'); if(inp) inp.value = JSON.stringify({categories: calkCategories}); }

function renderBuilder() {
    let html = '';
    if(calkCategories.length === 0) {
        html = '<div class="text-center py-5"><i class="fas fa-layer-group fa-3x text-muted opacity-25 mb-3"></i><h6 class="text-muted fw-bold">Belum Ada Format</h6></div>';
    }
    
    calkCategories.forEach((cat, cIdx) => {
        html += `<div class="builder-cat animate__animated animate__fadeIn"><div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-3"><div class="d-flex gap-2 flex-grow-1"><input type="text" class="form-control fw-bold text-primary border-0 shadow-sm rounded-pill px-3" value="${cat.name}" onkeyup="updateCatName(${cIdx}, this.value)" placeholder="Nama Judul Kategori (Kosongkan jika tak perlu)"><select class="form-select fw-bold border-0 shadow-sm rounded-pill" style="width: 300px;" onchange="updateCatType(${cIdx}, this.value)"><option value="normal" ${cat.type=='normal'?'selected':''}>Daftar Normal</option><option value="fixed_asset" ${cat.type=='fixed_asset'?'selected':''}>Tabel Aset</option><option value="text" ${cat.type=='text'?'selected':''}>Teks Paragraf</option></select></div><div class="d-flex gap-1 justify-content-end"><button type="button" class="btn btn-sm btn-white shadow-sm border" onclick="moveCat(${cIdx}, -1)"><i class="fas fa-arrow-up"></i></button><button type="button" class="btn btn-sm btn-white shadow-sm border" onclick="moveCat(${cIdx}, 1)"><i class="fas fa-arrow-down"></i></button><button type="button" class="btn btn-sm btn-danger shadow-sm ms-2 px-3 rounded-pill" onclick="deleteCat(${cIdx})"><i class="fas fa-trash"></i></button></div></div>`;
            
        if(cat.type == 'normal') {
            html += `<div class="p-3 bg-white rounded-4 border shadow-sm"><div class="d-flex justify-content-between align-items-center mb-3"><span class="small fw-bold text-muted uppercase">Daftar Akun</span><button type="button" class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3" onclick="openAccountSelector(${cIdx})"><i class="fas fa-link"></i> Hubungkan</button></div><div>`;
            if (cat.accounts.length === 0) { html += `<div class="text-center text-muted small py-3 fst-italic border rounded-3 bg-light">Kosong.</div>`; } else {
                cat.accounts.forEach((accCode, aIdx) => { let accName = allAccounts.find(a => a.kode_akun == accCode)?.nama_akun || 'Akun Dihapus'; html += `<div class="builder-acc"><div><code class="bg-light px-2 py-1 rounded text-primary border">${accCode}</code> <span class="fw-bold ms-2 text-dark">${accName}</span></div><div><button type="button" class="btn-mover shadow-sm" onclick="moveAcc(${cIdx}, ${aIdx}, -1)"><i class="fas fa-arrow-up"></i></button><button type="button" class="btn-mover shadow-sm" onclick="moveAcc(${cIdx}, ${aIdx}, 1)"><i class="fas fa-arrow-down"></i></button><button type="button" class="btn-mover btn-mover-del shadow-sm" onclick="removeAcc(${cIdx}, ${aIdx})"><i class="fas fa-times"></i></button></div></div>`; });
            } html += `</div></div>`;
        } else if (cat.type == 'text') {
            html += `<div class="p-3 bg-white rounded-4 border shadow-sm"><textarea class="form-control border-0 bg-light rounded-3" rows="4" onkeyup="updateCatText(${cIdx}, this.value)">${cat.text_content || ''}</textarea></div>`;
        } else {
            html += `<div class="p-3 bg-success bg-opacity-10 rounded-4 border border-success border-opacity-25 text-center shadow-sm"><p class="small fw-bold text-success mb-0">Matriks Pergerakan Aset Aktif</p></div>`;
        } html += `</div>`;
    });
    document.getElementById('builder-container').innerHTML = html; updateJsonData();
}

function addCategory() { 
    calkCategories.push({ id: 'cat_' + Date.now(), name: '', type: 'normal', accounts: [], text_content: '' }); 
    renderBuilder(); 
}
function updateCatName(cIdx, val) { calkCategories[cIdx].name = val; updateJsonData(); }
function updateCatType(cIdx, val) { calkCategories[cIdx].type = val; renderBuilder(); }
function updateCatText(cIdx, val) { calkCategories[cIdx].text_content = val; updateJsonData(); }
function deleteCat(cIdx) { if(confirm("Hapus elemen ini?")) { calkCategories.splice(cIdx, 1); renderBuilder(); } }
function moveCat(cIdx, dir) { if (cIdx + dir < 0 || cIdx + dir >= calkCategories.length) return; let temp = calkCategories[cIdx]; calkCategories[cIdx] = calkCategories[cIdx + dir]; calkCategories[cIdx + dir] = temp; renderBuilder(); }
function removeAcc(cIdx, aIdx) { calkCategories[cIdx].accounts.splice(aIdx, 1); renderBuilder(); }
function moveAcc(cIdx, aIdx, dir) { let accs = calkCategories[cIdx].accounts; if (aIdx + dir < 0 || aIdx + dir >= accs.length) return; let temp = accs[aIdx]; accs[aIdx] = accs[aIdx + dir]; accs[aIdx + dir] = temp; renderBuilder(); }

let currentEditCatIndex = -1;
function openAccountSelector(catIndex) {
    currentEditCatIndex = catIndex; let selectedAccs = calkCategories[catIndex].accounts; let html = '';
    let grouped = {}; allAccounts.forEach(a => { if(!grouped[a.kategori]) grouped[a.kategori] = []; grouped[a.kategori].push(a); });
    for(let kat in grouped) {
        html += `<div class="fw-bold mt-4 mb-2 text-primary border-bottom pb-1 uppercase small">${kat}</div>`;
        grouped[kat].forEach(acc => {
            let isChecked = selectedAccs.includes(acc.kode_akun) ? 'checked' : '';
            html += `<div class="form-check mb-2 ps-4 py-1 filterable-acc"><input class="form-check-input acc-checkbox shadow-sm border-secondary" type="checkbox" value="${acc.kode_akun}" id="chk_${acc.kode_akun}" ${isChecked}><label class="form-check-label small fw-bold text-dark w-100" for="chk_${acc.kode_akun}"><span class="acc-code d-none">${acc.kode_akun}</span><span class="acc-name d-none">${acc.nama_akun}</span><code class="text-primary bg-light px-2 py-1 border rounded me-2">${acc.kode_akun}</code> ${acc.nama_akun}</label></div>`;
        });
    }
    document.getElementById('accountListContainer').innerHTML = html; document.getElementById('searchAccBuilder').value = '';
    new bootstrap.Modal(document.getElementById('modalSelectAccounts')).show();
}

function filterAccBuilder() {
    let q = document.getElementById('searchAccBuilder').value.toLowerCase();
    document.querySelectorAll('.filterable-acc').forEach(div => {
        let text = div.querySelector('.acc-code').innerText.toLowerCase() + " " + div.querySelector('.acc-name').innerText.toLowerCase();
        div.style.display = text.includes(q) ? 'block' : 'none';
    });
}
function saveSelectedAccounts() {
    let checkboxes = document.querySelectorAll('.acc-checkbox:checked');
    calkCategories[currentEditCatIndex].accounts = Array.from(checkboxes).map(cb => cb.value);
    renderBuilder(); bootstrap.Modal.getInstance(document.getElementById('modalSelectAccounts')).hide();
}
</script>

<style>
    .builder-cat { border: 2px dashed #cbd5e1; padding: 20px; border-radius: 16px; margin-bottom: 20px; background: #f8fafc; transition: 0.3s; }
    .builder-cat:hover { border-color: #3b82f6; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
    .builder-acc { background: #fff; padding: 12px 18px; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 600;}
    .btn-mover { background: #f1f5f9; border: 1px solid #e2e8f0; color: #64748b; cursor: pointer; transition: 0.2s; border-radius: 6px; padding: 4px 10px; font-size: 12px; margin-left: 5px;}
    .btn-mover:hover { color: #0f172a; background: #e2e8f0; }
    .btn-mover-del { color: #ef4444; background: #fef2f2; border-color: #fca5a5; }
    .btn-mover-del:hover { background: #ef4444; color: #fff; }
    
    .tbl-asset { width: 100%; font-size: 11px; border-collapse: collapse; background: #fff;}
    .tbl-asset th { background: #fff; color: #000; text-align: center; padding: 8px; border: 1px solid #000; font-size: 10px; text-transform: uppercase;}
    .tbl-asset td { padding: 8px; border: 1px solid #000; vertical-align: middle; overflow: hidden; color:#000; }
    .row-cat-header td { background: #fff; font-weight: 800; color: #000; text-transform: uppercase; border-left: none; }
    .row-type-header td { background: #fff; font-weight: 700; color: #000; font-style: italic; }
    .row-subtotal td { font-weight: 800; border-top: 2px solid #000; background: rgba(0,0,0,0.02); color: #000;}
    .row-grand-total td { background: #f1f5f9 !important; font-weight: 900; color: #000 !important; border-top: 2px solid #000 !important; border-bottom: 3px double #000 !important;}
    .indent-item { padding-left: 30px !important; }
</style>