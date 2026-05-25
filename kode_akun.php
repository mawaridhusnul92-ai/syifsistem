<?php
// =========================================================================
// 🚀 THE NINJA INTERCEPTOR (FULL BYPASS SETTINGS.PHP)
// Versi: 58.0 (Sovereign Grand Master - HTML Error Annihilator)
// MENGENDALIKAN PROSES INSERT/UPDATE SEPENUHNYA UNTUK MENGHINDARI BUG HTML.
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ninja_action'])) {
    if(!isset($conn)) { require_once 'config/koneksi.php'; }
    
    // Pastikan schema table siap
    $conn->query("ALTER TABLE syifa_akun MODIFY grup_aktivitas VARCHAR(100) DEFAULT 'TIDAK_MASUK'");
    
    // Ambil Data
    $id = (int)($_POST['id'] ?? 0);
    $kode = $conn->real_escape_string(trim($_POST['kode']));
    $nama = $conn->real_escape_string(trim($_POST['nama']));
    $kategori = $conn->real_escape_string($_POST['kategori']);
    $is_group = (int)$_POST['is_group'];
    $parent = $conn->real_escape_string($_POST['parent_kode'] ?? '');
    $nb = $conn->real_escape_string($_POST['normal_balance'] ?? 'DEBIT');
    $rg = $conn->real_escape_string($_POST['report_group'] ?? '');
    
    $ob_str = $_POST['opening_balance'] ?? '0';
    $ob = (double)str_replace(['.', ','], '', $ob_str);
    
    $cf = $conn->real_escape_string($_POST['cash_flow_category'] ?? 'NONE');
    $lock = (int)($_POST['is_system_lock'] ?? 0);
    $allow = (int)($_POST['allow_posting'] ?? 1);
    
    $is_aktivitas = (int)($_POST['is_aktivitas_group'] ?? 0);
    $grup_aktivitas = $conn->real_escape_string(trim($_POST['grup_aktivitas'] ?? 'TIDAK_MASUK'));
    
    // Set parent NULL jika kosong
    $parent_val = empty($parent) ? "NULL" : "'$parent'";
    
    if ($id > 0) {
        // Mode Edit
        $query = "UPDATE syifa_akun SET 
                  kode_akun='$kode', nama_akun='$nama', kategori='$kategori', is_group=$is_group, 
                  parent_kode=$parent_val, normal_balance='$nb', report_group='$rg', opening_balance=$ob, 
                  cash_flow_category='$cf', is_system_lock=$lock, allow_posting=$allow, 
                  is_aktivitas_group=$is_aktivitas, grup_aktivitas='$grup_aktivitas' 
                  WHERE id=$id";
    } else {
        // Mode Tambah Baru
        $query = "INSERT INTO syifa_akun 
                  (kode_akun, nama_akun, kategori, is_group, parent_kode, normal_balance, report_group, opening_balance, cash_flow_category, is_system_lock, allow_posting, is_aktivitas_group, grup_aktivitas, is_active) 
                  VALUES 
                  ('$kode', '$nama', '$kategori', $is_group, $parent_val, '$nb', '$rg', $ob, '$cf', $lock, $allow, $is_aktivitas, '$grup_aktivitas', 1)";
    }
    
    if($conn->query($query)) { exit('MAPPING_UPDATED'); } 
    else { exit('ERROR_NINJA: ' . $conn->error); }
}

/**
 * kode_akun.php - MASTER CHART OF ACCOUNTS (ACCOUNTING BRAIN EDITION)
 */
if(!isset($conn)) { require_once 'config/koneksi.php'; }

$check_grup = $conn->query("SHOW COLUMNS FROM syifa_akun LIKE 'grup_aktivitas'");
if ($check_grup && $check_grup->num_rows == 0) {
    $conn->query("ALTER TABLE syifa_akun ADD COLUMN grup_aktivitas VARCHAR(100) DEFAULT 'TIDAK_MASUK' AFTER report_group");
}

$check_col = $conn->query("SHOW COLUMNS FROM syifa_akun LIKE 'is_aktivitas_group'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE syifa_akun ADD COLUMN is_aktivitas_group TINYINT(1) DEFAULT 0 AFTER grup_aktivitas");
}

$accounts_query = $conn->query("SELECT * FROM syifa_akun WHERE is_active = 1 ORDER BY kode_akun ASC");
$all_data = []; $account_map = []; $groups_only = [];
$dynamic_headers = []; 

if ($accounts_query) {
    while ($row = $accounts_query->fetch_assoc()) {
        $row['kode_akun'] = trim($row['kode_akun']);
        $row['parent_kode'] = (!empty($row['parent_kode'])) ? trim($row['parent_kode']) : NULL;
        $all_data[] = $row;
        $account_map[$row['kode_akun']] = $row; 
        
        if ((int)$row['is_group'] === 1) { $groups_only[] = $row; }
        if ((int)$row['is_aktivitas_group'] === 1) { $dynamic_headers[$row['kode_akun']] = $row['nama_akun']; }
    }
}

// 🚀 REKURSIF RENDER DENGAN PEWARISAN BADGE (INHERITED MAPPING)
function renderUnifiedHierarchy($parent_kode, &$data, $level = 0, $account_map, $processed = [], $parent_path = '', $inherited_mapping = 'TIDAK_MASUK') {
    global $dynamic_headers; 
    $html = "";
    
    foreach ($data as $key => $r) {
        $curr_code = $r['kode_akun']; $curr_parent = $r['parent_kode'];
        if (in_array($curr_code, $processed)) continue; 

        $should_render = false;
        if ($parent_kode === NULL) {
            $should_render = ($curr_parent === NULL || !isset($account_map[$curr_parent]));
        } else {
            $should_render = ($curr_parent === $parent_kode && $curr_parent !== $curr_code);
        }

        if ($should_render) {
            $is_g = (int)$r['is_group'];
            $margin = $level * 20; 
            $json = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
            $current_path = ($parent_path === '') ? $curr_code : $parent_path . '|' . $curr_code;

            if ($level === 0) { $html .= "<tbody class='coa-group-tbody'>"; }

            $sticky_class = $is_g ? "sticky-group-row" : "";
            $bg_color = $is_g ? "bg-light" : "bg-white";
            $border_bottom = $is_g ? "border-bottom: 2px solid rgba(13, 110, 253, 0.1) !important;" : "";

            if ($is_g) {
                $chevron = "<i class='fas fa-chevron-down toggle-caret me-2 text-muted' style='transition: transform 0.2s; width: 12px; font-size: 11px;'></i>";
                $icon = "fa-folder text-warning";
                $cursor_class = "cursor-pointer";
                $toggle_action = "onclick=\"toggleCoaRow(event, '{$curr_code}', '{$current_path}')\"";
            } else {
                $chevron = "<span style='display: inline-block; width: 12px; margin-right: 8px;'></span>";
                $icon = "fa-file-invoice text-primary opacity-25";
                $cursor_class = "";
                $toggle_action = "";
            }

            // 🚀 KALKULASI PEWARISAN UNTUK VISUAL BADGE
            $current_mapping = 'TIDAK_MASUK';
            $is_explicit = false;
            
            if ((int)$r['is_aktivitas_group'] === 1) {
                $current_mapping = $r['kode_akun']; 
            } elseif (isset($r['grup_aktivitas']) && $r['grup_aktivitas'] !== 'TIDAK_MASUK') {
                $current_mapping = $r['grup_aktivitas']; 
                $is_explicit = true;
            } elseif ($inherited_mapping !== 'TIDAK_MASUK') {
                $current_mapping = $inherited_mapping; 
            }

            $badges_left = "";
            if(isset($r['is_system_lock']) && $r['is_system_lock'] == 1) {
                $badges_left .= "<span class='badge bg-danger ms-2 shadow-sm' style='font-size:8px; padding:3px 6px;' title='Dikunci Sistem'><i class='fas fa-lock'></i></span> ";
            }
            
            // 🚀 TAMPILAN BADGE DINAMIS (MANDIRI vs WARISAN)
            if((int)$r['is_aktivitas_group'] === 1) {
                $badges_left .= "<span class='badge bg-warning text-dark ms-2 shadow-sm border border-white' style='font-size:8px; padding:3px 6px;' title='Header Laporan Aktivitas'><i class='fas fa-crown'></i> Header Laporan</span> ";
            } elseif($current_mapping !== 'TIDAK_MASUK' && in_array($r['kategori'], ['Pendapatan', 'Beban'])) {
                $mapped_name = $dynamic_headers[$current_mapping] ?? $current_mapping;
                if ($is_explicit) {
                    $badges_left .= "<span class='badge bg-success ms-2 shadow-sm border border-white' style='font-size:8px; padding:3px 6px;' title='Terhubung (Mandiri) ke {$mapped_name}'><i class='fas fa-link'></i> {$mapped_name}</span> ";
                } else {
                    $badges_left .= "<span class='badge bg-white text-success border border-success ms-2 shadow-sm' style='font-size:8px; padding:3px 6px;' title='Mewarisi dari Induk ke {$mapped_name}'><i class='fas fa-level-up-alt'></i> {$mapped_name}</span> ";
                }
            }
            
            $sn_val = $r['saldo_normal'] ?? '';
            $nb_val = $r['normal_balance'] ?? '';
            $is_kredit = ($sn_val == 'K' || strtoupper($nb_val) == 'KREDIT');
            $badge_text = $is_kredit ? 'KR' : 'DE';
            $nb_color = $is_kredit ? 'warning text-dark' : 'primary';
            
            if ($r['kategori'] == 'Pendapatan' && !$is_kredit) {
                $nb_color = 'danger text-white border border-danger animate__animated animate__flash animate__infinite';
                $badge_text = 'ERROR: BUKAN KR';
            }
            if ($r['kategori'] == 'Beban' && $is_kredit) {
                $nb_color = 'danger text-white border border-danger animate__animated animate__flash animate__infinite';
                $badge_text = 'ERROR: BUKAN DE';
            }

            $badge_nb = "<span class='badge bg-$nb_color shadow-sm rounded-pill' style='font-size:9px; padding:4px 8px; width: 35px; text-align: center;' title='Normal Balance'>$badge_text</span>";
            if(strpos($badge_text, 'ERROR') !== false) {
                 $badge_nb = "<span class='badge bg-$nb_color shadow-sm rounded-pill' style='font-size:9px; padding:4px 8px;' title='Normal Balance'>$badge_text</span>";
            }

            $html .= "<tr class='{$sticky_class}' style='{$border_bottom}' data-path='{$current_path}' data-code='{$curr_code}' data-is-group='{$is_g}' id='coa_row_{$curr_code}'>
                        <td class='ps-3 pe-3 align-middle {$bg_color} {$cursor_class}' {$toggle_action}>
                            <div class='d-flex justify-content-between align-items-center w-100' style='user-select: none;'>
                                <div style='margin-left: {$margin}px' class='d-flex align-items-center'>
                                    {$chevron}
                                    <i class='fas {$icon} me-2'></i>
                                    <span class='me-2 font-monospace small text-muted'>{$r['kode_akun']}</span>
                                    <span class='".($is_g ? 'fw-bold text-dark' : 'small text-muted')."'>{$r['nama_akun']}</span>
                                    {$badges_left}
                                </div>
                                <div>{$badge_nb}</div>
                            </div>
                        </td>
                        <td class='text-end font-monospace pe-3 align-middle {$bg_color}'>" . ($is_g ? '' : number_format($r['opening_balance'], 0, ',', '.')) . "</td>
                        <td class='text-center pe-3 align-middle {$bg_color}'>
                            <div class='btn-group btn-group-sm rounded-pill border bg-white shadow-sm overflow-hidden'>
                                <button class='btn btn-white text-warning border-end btn-edit-coa' data-json='{$json}' title='Ubah Akun'><i class='fas fa-edit'></i></button>
                                <button class='btn btn-white text-danger' onclick=\"handleHapus({$r['id']}, '{$r['kode_akun']}', ".(isset($r['is_system_lock']) ? $r['is_system_lock'] : 0).")\" title='Hapus Akun'><i class='fas fa-trash'></i></button>
                            </div>
                        </td>
                    </tr>";
            
            unset($data[$key]); 
            $processed[] = $curr_code; 
            
            // Rekursi membawa panji-panji warisan (inherited mapping) ke anak cucu
            $html .= renderUnifiedHierarchy($curr_code, $data, $level + 1, $account_map, $processed, $current_path, $current_mapping);
            
            if ($level === 0) { $html .= "</tbody>"; }
        }
    }
    return $html;
}

$data_n = []; $data_a = [];
foreach($all_data as $d) {
    if(in_array($d['kategori'], ['Aset', 'Liabilitas', 'Aset Neto'])) $data_n[] = $d;
    else $data_a[] = $d;
}

// Persiapkan data Header Dinamis untuk diakses Javascript
$dynamic_headers_js_array = [];
foreach($dynamic_headers as $kode => $nama) {
    $dynamic_headers_js_array[$kode] = $nama;
}
?>

<style>
    .viewport-coa { overflow-y: auto; scrollbar-width: thin; background: #fff; border-radius: 18px; border: 1px solid #e2e8f0; position: relative; width: 100%; height: 100%; }
    .viewport-coa table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 120px !important; }
    .viewport-coa thead th { position: sticky; top: 0; z-index: 105; background: #1e293b !important; color: #fff; padding: 12px; font-size: 10px; text-transform: uppercase; border: none; height: 38px; box-sizing: border-box; }
    
    .coa-group-tbody { display: table-row-group; }
    .sticky-group-row td { position: sticky; top: 37px; z-index: 100; box-shadow: 0 4px 10px -2px rgba(0,0,0,0.1); background-clip: padding-box; }
    .sticky-group-row td.bg-light { background-color: #f8fafc !important; }
    .sticky-group-row td.bg-white { background-color: #ffffff !important; }
    
    .cursor-pointer { cursor: pointer !important; }
    .sticky-group-row:hover td { background-color: #f1f5f9 !important; }
    .font-monospace { font-family: 'SFMono-Regular', Consolas, Menlo, monospace; }
    .btn-white { background: #fff; border: none; }
    .btn-white:hover { background: #f8fafc; }
</style>

<div>
    <!-- ACTION HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-dark mb-0"><i class="fas fa-layer-group me-2 text-primary"></i>Bagan Akun Terpadu <small class="text-muted small fw-normal ms-2">v58.0 - Perfect Save Engine</small></h5>
        <div class="d-flex align-items-center">
            <button class="btn btn-outline-primary rounded-pill px-3 fw-bold shadow-sm me-2 btn-sm text-uppercase" onclick="toggleAllCoa(true)" style="font-size: 10px;"><i class="fas fa-expand-arrows-alt me-1"></i> Buka Semua</button>
            <button class="btn btn-outline-secondary rounded-pill px-3 fw-bold shadow-sm me-3 btn-sm text-uppercase" onclick="toggleAllCoa(false)" style="font-size: 10px;"><i class="fas fa-compress-arrows-alt me-1"></i> Tutup Semua</button>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="showAddModal()"><i class="fas fa-plus-circle me-2"></i>DAFTAR AKUN BARU</button>
        </div>
    </div>

    <!-- 🚀 MAIN SPLIT VIEW -->
    <div class="row g-4 m-0 p-0 text-dark align-items-start" style="height: 75vh; overflow: hidden; width: 100%;">
        <div class="col-6 h-100 d-flex flex-column pe-2">
            <div class="badge bg-dark text-white px-4 py-2 rounded-pill mb-2 shadow-sm uppercase small fw-bold" style="width: max-content;"><i class="fas fa-balance-scale me-2"></i>1. Posisi Keuangan (Neraca)</div>
            <div class="viewport-coa shadow-sm border-0">
                <table>
                    <thead><tr><th class="ps-4 text-start">Struktur Aktiva & Pasiva</th><th class="text-end pe-4" width="130">Saldo Awal</th><th class="text-center" width="100">Aksi</th></tr></thead>
                    <?= renderUnifiedHierarchy(NULL, $data_n, 0, $account_map) ?>
                </table>
            </div>
        </div>

        <div class="col-6 h-100 d-flex flex-column ps-2">
            <div class="badge bg-primary text-white px-4 py-2 rounded-pill mb-2 shadow-sm uppercase small fw-bold" style="width: max-content;"><i class="fas fa-chart-line me-2"></i>2. Laporan Aktivitas (L/R)</div>
            <div class="viewport-coa shadow-sm border-0">
                <table>
                    <thead><tr><th class="ps-4 text-start">Struktur Pendapatan & Beban</th><th class="text-end pe-4" width="130">Saldo Awal</th><th class="text-center" width="100">Aksi</th></tr></thead>
                    <?= renderUnifiedHierarchy(NULL, $data_a, 0, $account_map) ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL MANAJEMEN COA GOVERNANCE -->
<div class="modal fade" id="modalCOA" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form id="formCOA" onsubmit="handleFormSubmit(event)" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden text-dark">
            <input type="hidden" name="ninja_action" value="update_mapping_aktivitas">
            <input type="hidden" name="id" id="coa_id">
            <input type="hidden" name="allow_posting" id="coa_allow_posting" value="1">
            
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h6 class="modal-title fw-bold text-white" id="coa_title">Konfigurasi Akun Baru</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4 bg-light text-dark">
                <div class="row g-3">
                    
                    <div class="col-12">
                        <label class="small fw-bold text-muted mb-1 uppercase">Tipe Hirarki Akun</label>
                        <div class="d-flex gap-3 bg-white p-2 rounded-pill border shadow-sm">
                            <div class="form-check ms-3">
                                <input class="form-check-input" type="radio" name="is_group" id="type_detail" value="0" checked onclick="toggleAccountType(0)">
                                <label class="form-check-label small fw-bold" for="type_detail">Bisa Dijurnal (Detail 4 Angka)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="is_group" id="type_group" value="1" onclick="toggleAccountType(1)">
                                <label class="form-check-label small fw-bold text-danger" for="type_group">Induk Saja (Grup)</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1 uppercase">Kategori</label>
                        <select name="kategori" id="coa_kategori" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold" required onchange="smartSuggestGovernance()">
                            <option value="Aset">Aset</option>
                            <option value="Liabilitas">Liabilitas</option>
                            <option value="Aset Neto">Aset Neto</option>
                            <option value="Pendapatan">Pendapatan</option>
                            <option value="Beban">Beban</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="small fw-bold text-primary mb-1 uppercase">Normal Balance</label>
                        <select name="normal_balance" id="coa_nb" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold text-primary">
                            <option value="DEBIT">DEBIT (Dr)</option>
                            <option value="KREDIT">KREDIT (Cr)</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="small fw-bold text-primary mb-1 uppercase">Kelompok Laporan (ISAK 35)</label>
                        <select name="report_group" id="coa_rg" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold">
                            <option value="POSISI_KEUANGAN">Posisi Keuangan (Neraca)</option>
                            <option value="AKTIVITAS">Laporan Aktivitas (Laba/Rugi)</option>
                            <option value="ASET_NETO_TANPA_RESTRIKSI">Aset Neto - Tanpa Pembatasan</option>
                            <option value="ASET_NETO_DENGAN_RESTRIKSI">Aset Neto - Dengan Pembatasan</option>
                        </select>
                    </div>

                    <!-- 🚀 KOTAK KONFIGURASI DINAMIS LAPORAN AKTIVITAS -->
                    <div class="col-12" id="box_konfigurasi_aktivitas" style="display: none;">
                        <div class="p-3 border border-primary rounded-4 bg-white shadow-sm">
                            <label class="small fw-bold text-primary mb-2 uppercase"><i class="fas fa-chart-pie me-1"></i> Konfigurasi Laporan Aktivitas</label>
                            
                            <!-- 🚀 KOTAK INFO PEWARISAN -->
                            <div id="info_inherited_mapping" class="alert alert-success border-success bg-success bg-opacity-10 p-2 mb-3" style="font-size: 11px; display: none;"></div>

                            <div class="form-check form-switch mb-3 p-2 bg-light rounded-3 border">
                                <input type="hidden" name="is_aktivitas_group_fallback" value="0">
                                <input class="form-check-input ms-1" type="checkbox" name="is_aktivitas_group" id="coa_is_aktivitas_group" value="1" onchange="handleAktivitasConfig()" style="transform: scale(1.2);">
                                <label class="form-check-label ms-3 fw-bold text-dark" for="coa_is_aktivitas_group"><i class="fas fa-crown text-warning me-1"></i> Jadikan akun ini sebagai <span class="text-primary">Header/Kategori Utama</span> di Laporan</label>
                            </div>
                            
                            <div class="form-check form-switch mb-2 p-2 bg-light rounded-3 border">
                                <input class="form-check-input ms-1" type="checkbox" id="toggle_mapping_aktivitas" checked onchange="handleAktivitasConfig()" style="transform: scale(1.2);">
                                <label class="form-check-label ms-3 fw-bold text-dark" for="toggle_mapping_aktivitas"><i class="fas fa-link text-success me-1"></i> Tautkan akun ini ke Header secara Mandiri</label>
                            </div>
                            
                            <div class="mt-2 ps-4" id="box_grup_aktivitas">
                                <select name="grup_aktivitas" id="coa_grup_aktivitas" class="form-select rounded-pill border-success shadow-sm px-3 fw-bold small">
                                    <option value="TIDAK_MASUK">-- TIDAK DITAUTKAN --</option>
                                    <?php if(empty($dynamic_headers)): ?>
                                        <option value="" disabled>Belum ada Kategori (Buat grup lalu ceklis 'Jadikan Header')</option>
                                    <?php else: ?>
                                        <?php foreach($dynamic_headers as $kode => $nama): ?>
                                            <option value="<?= htmlspecialchars($kode, ENT_QUOTES) ?>">[<?= htmlspecialchars($kode, ENT_QUOTES) ?>] <?= htmlspecialchars($nama, ENT_QUOTES) ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-12"><hr class="my-1 border-secondary opacity-25"></div>

                    <div class="col-12" id="box_parent">
                        <label class="small fw-bold text-muted mb-1 uppercase">Induk Group</label>
                        <select name="parent_kode" id="coa_parent" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold text-dark">
                            <option value="">-- JADIKAN ROOT --</option>
                            <?php foreach($groups_only as $g) echo "<option value='{$g['kode_akun']}' data-kat='{$g['kategori']}'>[{$g['kode_akun']}] {$g['nama_akun']}</option>"; ?>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label class="small fw-bold text-muted mb-1 uppercase">Kode Akun</label>
                        <div class="input-group shadow-sm rounded-pill overflow-hidden border bg-white">
                            <input type="text" name="kode" id="coa_kode" class="form-control border-0 px-3 font-monospace fw-bold" required>
                            <button class="btn btn-light text-primary fw-bold px-3 border-0 border-start shadow-none" type="button" id="btnMagicWand" onclick="generateAutoCode()" title="Auto-Generate Kode Berurutan">
                                <i class="fas fa-magic"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-7">
                        <label class="small fw-bold text-muted mb-1 uppercase">Nama Perkiraan</label>
                        <input type="text" name="nama" id="coa_nama" class="form-control rounded-pill border-0 shadow-sm px-3 fw-bold" required>
                    </div>
                    
                    <div class="col-md-6" id="box_ob">
                        <label class="small fw-bold text-muted mb-1 uppercase">Saldo Awal</label>
                        <input type="text" name="opening_balance" id="coa_ob" class="form-control rounded-pill border-0 shadow-sm px-3 text-end font-monospace fw-bold text-primary" onkeyup="fmtRpInput(this)" value="0">
                    </div>
                    
                    <div class="col-md-6" id="box_cf">
                        <label class="small fw-bold text-muted mb-1 uppercase">Arus Kas</label>
                        <select name="cash_flow_category" id="coa_cf" class="form-select rounded-pill border-0 shadow-sm px-3 fw-bold">
                            <option value="NONE">Tidak Masuk</option><option value="OPERATING">OPERASIONAL</option><option value="INVESTING">INVESTASI</option><option value="FINANCING">PENDANAAN</option>
                        </select>
                    </div>
                    
                    <div class="col-12 pt-2 text-end">
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input" type="checkbox" name="is_system_lock" id="coa_lock" value="1">
                            <label class="form-check-label small fw-bold text-danger" for="coa_lock"><i class="fas fa-lock me-1"></i> Lock System</label>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-footer border-0 p-4 bg-light text-center">
                <div class="d-flex gap-2 w-100">
                    <button type="submit" class="btn btn-primary rounded-pill py-3 fw-bold shadow-sm flex-grow-1 text-uppercase" style="font-size: 11px;">Simpan & Tutup</button>
                    <button type="button" class="btn btn-outline-primary rounded-pill py-3 fw-bold shadow-sm flex-grow-1 text-uppercase" onclick="saveAndCreateNewCOA(this)" style="font-size: 11px;">Simpan & Buat Baru</button>
                </div>
            </div>
        </form>
    </div>
</div>

<form id="formHapusCOA" action="settings.php" method="POST"><input type="hidden" name="action" value="delete_account"><input type="hidden" name="id" id="delId"><input type="hidden" name="kode" id="delKode"></form>

<script>
const allCoaData = <?= json_encode($all_data) ?>;
const dynamicHeadersJS = <?= json_encode($dynamic_headers_js_array) ?>;

function fmtRpInput(el){ el.value = new Intl.NumberFormat('id-ID').format(el.value.replace(/\D/g, "")); }

function toggleCoaRow(event, code, path) {
    if (event.target.closest('button') || event.target.closest('a') || event.target.closest('.btn-group')) return;
    const td = event.currentTarget;
    const row = td.closest('tr');
    const table = row.closest('table');
    const isCollapsed = row.classList.toggle('row-collapsed');
    
    const caret = row.querySelector('.toggle-caret');
    if (caret) caret.style.transform = isCollapsed ? 'rotate(-90deg)' : 'rotate(0deg)';

    let collapsedRows = JSON.parse(localStorage.getItem('coa_collapsed_rows')) || [];
    if (isCollapsed) {
        if (!collapsedRows.includes(code)) collapsedRows.push(code);
    } else {
        collapsedRows = collapsedRows.filter(c => c !== code);
    }
    localStorage.setItem('coa_collapsed_rows', JSON.stringify(collapsedRows));

    const prefix = path + '|';
    const allRows = table.querySelectorAll('tr[data-path]');
    
    allRows.forEach(r => {
        const rPath = r.getAttribute('data-path');
        if (rPath && rPath.startsWith(prefix)) {
            if (isCollapsed) {
                r.style.display = 'none';
            } else {
                if (shouldShowRow(r, table)) r.style.display = '';
            }
        }
    });
}

function shouldShowRow(row, table) {
    const path = row.getAttribute('data-path');
    if (!path) return true;
    const segments = path.split('|');
    segments.pop(); 
    
    let currentPath = '';
    for (let i = 0; i < segments.length; i++) {
        currentPath = (currentPath === '') ? segments[i] : currentPath + '|' + segments[i];
        const ancestorRow = table.querySelector(`tr[data-path="${currentPath}"]`);
        if (ancestorRow && ancestorRow.classList.contains('row-collapsed')) return false;
    }
    return true;
}

function restoreCoaAccordionState() {
    const collapsedRows = JSON.parse(localStorage.getItem('coa_collapsed_rows')) || [];
    const groupRows = document.querySelectorAll('tr[data-is-group="1"]');
    
    groupRows.forEach(row => {
        const code = row.getAttribute('data-code');
        if (collapsedRows.includes(code)) {
            row.classList.add('row-collapsed');
            const caret = row.querySelector('.toggle-caret');
            if (caret) caret.style.transform = 'rotate(-90deg)';
        }
    });
    
    const allTables = document.querySelectorAll('.viewport-coa table');
    allTables.forEach(table => {
        const rows = table.querySelectorAll('tr[data-path]');
        rows.forEach(r => {
            if (!shouldShowRow(r, table)) r.style.display = 'none';
        });
    });
}

function toggleAllCoa(expand) {
    const tables = document.querySelectorAll('.viewport-coa table');
    let collapsedRows = [];
    
    if (!expand) {
        const allGroups = document.querySelectorAll('tr[data-is-group="1"]');
        allGroups.forEach(g => { collapsedRows.push(g.getAttribute('data-code')); });
    }
    localStorage.setItem('coa_collapsed_rows', JSON.stringify(collapsedRows));

    tables.forEach(table => {
        const groupRows = table.querySelectorAll('tr[data-is-group="1"]');
        groupRows.forEach(row => {
            const caret = row.querySelector('.toggle-caret');
            if (expand) {
                row.classList.remove('row-collapsed');
                if (caret) caret.style.transform = 'rotate(0deg)';
            } else {
                row.classList.add('row-collapsed');
                if (caret) caret.style.transform = 'rotate(-90deg)';
            }
        });

        const allRows = table.querySelectorAll('tr[data-path]');
        allRows.forEach(r => {
            const path = r.getAttribute('data-path');
            const segments = path.split('|');
            if (expand) {
                r.style.display = '';
            } else {
                if (segments.length === 1) r.style.display = '';
                else r.style.display = 'none';
            }
        });
    });
}

function generateAutoCode() {
    const isGroup = document.getElementById('type_group').checked;
    const parentKode = document.getElementById('coa_parent').value.trim();
    const kategori = document.getElementById('coa_kategori').value;

    let nextCode = "";

    if (parentKode) {
        const children = allCoaData.filter(a => a.parent_kode === parentKode && a.is_group == (isGroup ? "1" : "0"));
        let basePrefix = parentKode;
        let separator = "-";
        
        let lastSepIndex = Math.max(basePrefix.lastIndexOf('-'), basePrefix.lastIndexOf('.'));
        if (lastSepIndex !== -1) {
            separator = basePrefix.substring(lastSepIndex, lastSepIndex + 1);
            let lastPart = basePrefix.substring(lastSepIndex + 1);
            if (/^\d+$/.test(lastPart)) basePrefix = basePrefix.substring(0, lastSepIndex);
        }

        if (children.length > 0) {
            let maxVal = -1;
            children.forEach(c => {
                let code = c.kode_akun.trim();
                let match = code.match(/[\-\.]?(\d+)$/);
                if (match) {
                    let num = parseInt(match[1], 10);
                    if (num > maxVal) maxVal = num;
                }
            });

            if (maxVal !== -1) {
                if (!isGroup && maxVal < 1000) maxVal = 999;
                let nextNumStr = (maxVal + 1).toString();
                if (!isGroup) nextNumStr = nextNumStr.padStart(4, '0');
                nextCode = basePrefix + separator + nextNumStr;
            } else {
                nextCode = basePrefix + separator + (isGroup ? "1000" : "1001");
            }
        } else {
            let startNum = isGroup ? "1000" : "1001";
            let parentMatch = parentKode.match(/[\-\.]?(\d+)$/);
            if (parentMatch) {
                let pNum = parseInt(parentMatch[1], 10);
                if (!isGroup) startNum = (pNum + 1).toString().padStart(4, '0');
                else startNum = (pNum + 1000).toString().padStart(4, '0');
            }
            nextCode = basePrefix + separator + startNum;
        }
    } else {
        let prefix = "";
        if (kategori === 'Aset') prefix = "1";
        else if (kategori === 'Liabilitas') prefix = "2";
        else if (kategori === 'Aset Neto') prefix = "3";
        else if (kategori === 'Pendapatan') prefix = "4";
        else if (kategori === 'Beban') prefix = "5";

        const roots = allCoaData.filter(a => (!a.parent_kode) && a.kode_akun.startsWith(prefix) && a.is_group == (isGroup ? "1" : "0"));
        
        if (roots.length > 0) {
            let maxVal = -1;
            roots.forEach(r => {
                let match = r.kode_akun.match(/^(\d+)$/);
                if(match) {
                    let num = parseInt(match[1], 10);
                    if (num > maxVal) maxVal = num;
                }
            });
            if (maxVal !== -1) nextCode = (maxVal + 1).toString();
            else nextCode = prefix + "1";
        } else {
            nextCode = prefix;
        }
    }

    document.getElementById('coa_kode').value = nextCode;
    const btn = document.getElementById('btnMagicWand');
    const icon = btn.querySelector('i');
    icon.classList.replace('fa-magic', 'fa-check');
    btn.classList.add('text-success');
    setTimeout(() => {
        icon.classList.replace('fa-check', 'fa-magic');
        btn.classList.remove('text-success');
    }, 1000);
}

document.addEventListener('DOMContentLoaded', function() {
    restoreCoaAccordionState();

    if (sessionStorage.getItem('reopen_coa_modal') === 'true') {
        sessionStorage.removeItem('reopen_coa_modal');
        showAddModal();
        
        const savedParent = sessionStorage.getItem('reopen_coa_parent') || '';
        const savedKategori = sessionStorage.getItem('reopen_coa_kategori') || 'Aset';
        const savedNb = sessionStorage.getItem('reopen_coa_nb') || 'DEBIT';
        const savedRg = sessionStorage.getItem('reopen_coa_rg') || 'POSISI_KEUANGAN';
        const savedType = sessionStorage.getItem('reopen_coa_type') || '0';
        
        const savedIsHeader = sessionStorage.getItem('reopen_coa_is_aktivitas') || '0';
        const savedGrupAct = sessionStorage.getItem('reopen_coa_grup_aktivitas') || 'TIDAK_MASUK';
        
        document.getElementById('coa_parent').value = savedParent;
        document.getElementById('coa_kategori').value = savedKategori;
        document.getElementById('coa_nb').value = savedNb;
        document.getElementById('coa_rg').value = savedRg;
        
        document.getElementById(savedType === '1' ? 'type_group' : 'type_detail').checked = true;
        toggleAccountType(parseInt(savedType));
        filterParentDropdown(savedKategori);
        
        const boxConfig = document.getElementById('box_konfigurasi_aktivitas');
        
        if (savedKategori === 'Pendapatan' || savedKategori === 'Beban') {
            boxConfig.style.display = 'block';
            document.getElementById('coa_is_aktivitas_group').checked = (savedIsHeader === '1');
            
            if (savedIsHeader === '0' && savedGrupAct !== 'TIDAK_MASUK') {
                document.getElementById('toggle_mapping_aktivitas').checked = true;
            } else {
                document.getElementById('toggle_mapping_aktivitas').checked = false;
            }
            
            handleAktivitasConfig();
            document.getElementById('coa_grup_aktivitas').value = savedGrupAct;
        } else {
            boxConfig.style.display = 'none';
        }
        
        generateAutoCode();
        document.getElementById('coa_nama').value = '';
        document.getElementById('coa_ob').value = '0';
        
        setTimeout(() => { 
            const namaField = document.getElementById('coa_nama');
            if (namaField) { namaField.focus(); }
        }, 300);
    }
});

// 🚀 FUNGSI UTAMA: MURNI NINJA FETCH (BEBAS DARI KETERGANTUNGAN SETTINGS.PHP LAMA)
function handleFormSubmit(e) {
    e.preventDefault(); 
    
    const form = document.getElementById('formCOA');
    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    
    const kodeInput = document.getElementById('coa_kode');
    const namaInput = document.getElementById('coa_nama');
    if (!kodeInput.value.trim()) { alert("Kode Akun wajib diisi."); kodeInput.focus(); return; }
    if (!namaInput.value.trim()) { alert("Nama Perkiraan wajib diisi."); namaInput.focus(); return; }

    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
    btn.disabled = true;

    // Paksa ambil nilai toggle
    const isMappedExplicitly = document.getElementById('toggle_mapping_aktivitas').checked;
    const isHeaderGroup = document.getElementById('coa_is_aktivitas_group').checked ? '1' : '0';
    let finalGrupAktivitas = isMappedExplicitly ? document.getElementById('coa_grup_aktivitas').value : 'TIDAK_MASUK';
    
    if (isHeaderGroup === '1') { finalGrupAktivitas = 'TIDAK_MASUK'; }

    const formData = new FormData(form);
    // Suntikkan instruksi khusus agar dieksekusi oleh Ninja Interceptor di file ini juga!
    formData.append('ninja_action', 'update_mapping_aktivitas');
    formData.append('is_aktivitas_group', isHeaderGroup);
    formData.append('grup_aktivitas', finalGrupAktivitas);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.text())
    .then(text => {
        if(text.includes('ERROR_NINJA')) {
            alert("Kesalahan Database: " + text);
            btn.innerHTML = originalText; btn.disabled = false;
        } else {
            window.location.reload(); 
        }
    })
    .catch(error => {
        alert("Gagal menyimpan data karena masalah jaringan.");
        btn.innerHTML = originalText; btn.disabled = false;
    });
}

function saveAndCreateNewCOA(btn) {
    const form = document.getElementById('formCOA');
    const kodeInput = document.getElementById('coa_kode');
    const namaInput = document.getElementById('coa_nama');
    
    if (!kodeInput.value.trim()) { alert("Kode Akun wajib diisi."); kodeInput.focus(); return; }
    if (!namaInput.value.trim()) { alert("Nama Perkiraan wajib diisi."); namaInput.focus(); return; }
    
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
    btn.disabled = true;
    
    sessionStorage.setItem('reopen_coa_modal', 'true');
    sessionStorage.setItem('reopen_coa_parent', document.getElementById('coa_parent').value);
    sessionStorage.setItem('reopen_coa_kategori', document.getElementById('coa_kategori').value);
    sessionStorage.setItem('reopen_coa_nb', document.getElementById('coa_nb').value);
    sessionStorage.setItem('reopen_coa_rg', document.getElementById('coa_rg').value);
    
    const isMappedExplicitly = document.getElementById('toggle_mapping_aktivitas').checked;
    const isHeaderGroup = document.getElementById('coa_is_aktivitas_group').checked ? '1' : '0';
    let finalGrupAktivitas = isMappedExplicitly ? document.getElementById('coa_grup_aktivitas').value : 'TIDAK_MASUK';
    if (isHeaderGroup === '1') { finalGrupAktivitas = 'TIDAK_MASUK'; }
    
    sessionStorage.setItem('reopen_coa_is_aktivitas', isHeaderGroup);
    sessionStorage.setItem('reopen_coa_grup_aktivitas', finalGrupAktivitas);
    
    const typeGroup = document.getElementById('type_group').checked ? '1' : '0';
    sessionStorage.setItem('reopen_coa_type', typeGroup);
    
    const formData = new FormData(form);
    formData.append('ninja_action', 'update_mapping_aktivitas');
    formData.append('is_aktivitas_group', isHeaderGroup);
    formData.append('grup_aktivitas', finalGrupAktivitas);
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(response => response.text())
    .then(text => {
        if(text.includes('ERROR_NINJA')) {
            alert("Kesalahan Database: " + text);
            sessionStorage.removeItem('reopen_coa_modal');
        }
        window.location.reload(); 
    })
    .catch(error => {
        alert("Gagal menghubungi server untuk menyimpan data.");
        btn.innerHTML = originalText; btn.disabled = false;
        sessionStorage.removeItem('reopen_coa_modal');
    });
}

function showAddModal() { 
    const f = document.getElementById('formCOA'); f.reset(); 
    document.getElementById('coa_id').value = ''; 
    document.getElementById('coa_title').innerText = 'Pendaftaran Akun Baru'; 
    toggleAccountType(0); 
    smartSuggestGovernance(); 
    new bootstrap.Modal(document.getElementById('modalCOA')).show(); 
}

function toggleAccountType(isGroup) { 
    document.getElementById('box_ob').style.display = isGroup ? 'none' : 'block'; 
    document.getElementById('box_cf').style.display = isGroup ? 'none' : 'block'; 
    document.getElementById('coa_allow_posting').value = isGroup ? '0' : '1';
    if(isGroup) { document.getElementById('coa_ob').value = '0'; document.getElementById('coa_cf').value = 'NONE'; } 
}

function handleAktivitasConfig() {
    const isHeader = document.getElementById('coa_is_aktivitas_group').checked;
    const toggleMap = document.getElementById('toggle_mapping_aktivitas');
    const boxMap = document.getElementById('box_grup_aktivitas');
    const selMap = document.getElementById('coa_grup_aktivitas');
    const infoBox = document.getElementById('info_inherited_mapping');

    if (isHeader) {
        toggleMap.checked = false;
        toggleMap.disabled = true;
        boxMap.style.display = 'none';
        selMap.value = 'TIDAK_MASUK';
        infoBox.style.display = 'none';
    } else {
        toggleMap.disabled = false;
        if (toggleMap.checked) {
            boxMap.style.display = 'block';
            infoBox.style.display = 'none'; 
        } else {
            boxMap.style.display = 'none';
            selMap.value = 'TIDAK_MASUK';
            document.getElementById('coa_parent').dispatchEvent(new Event('change')); 
        }
    }
}

function smartSuggestGovernance() {
    const kat = document.getElementById('coa_kategori').value;
    const nb = document.getElementById('coa_nb');
    const rg = document.getElementById('coa_rg');
    
    const boxConfig = document.getElementById('box_konfigurasi_aktivitas');
    
    if(kat === 'Aset' || kat === 'Liabilitas' || kat === 'Aset Neto') { 
        nb.value = (kat === 'Aset') ? 'DEBIT' : 'KREDIT'; 
        rg.value = 'POSISI_KEUANGAN'; 
        
        boxConfig.style.display = 'none'; 
        document.getElementById('coa_is_aktivitas_group').checked = false;
        document.getElementById('toggle_mapping_aktivitas').checked = false;
        document.getElementById('coa_grup_aktivitas').value = 'TIDAK_MASUK'; 
    }
    else if(kat === 'Pendapatan' || kat === 'Beban') { 
        nb.value = (kat === 'Pendapatan') ? 'KREDIT' : 'DEBIT'; 
        rg.value = 'AKTIVITAS'; 
        
        boxConfig.style.display = 'block';
        
        if(!document.getElementById('coa_id').value) {
            document.getElementById('toggle_mapping_aktivitas').checked = false;
            document.getElementById('coa_is_aktivitas_group').checked = false;
        }
        
        handleAktivitasConfig();
    }
    
    filterParentDropdown(kat);
}

// 🚀 FUNGSI KUNCI: MENDETEKSI PEWARISAN SAAT USER MEMILIH INDUK
document.getElementById('coa_parent').addEventListener('change', function() {
    const parentCode = this.value;
    const infoBox = document.getElementById('info_inherited_mapping');
    infoBox.style.display = 'none';
    
    if(parentCode) {
        let inheritedFrom = 'TIDAK_MASUK';
        let currParent = parentCode;
        while(currParent) {
            let pNode = allCoaData.find(a => a.kode_akun === currParent);
            if (!pNode) break;
            if (parseInt(pNode.is_aktivitas_group) === 1) { inheritedFrom = pNode.kode_akun; break; }
            if (pNode.grup_aktivitas && pNode.grup_aktivitas !== 'TIDAK_MASUK') { inheritedFrom = pNode.grup_aktivitas; break; }
            currParent = pNode.parent_kode;
        }

        if (inheritedFrom !== 'TIDAK_MASUK') {
            const isExplicit = document.getElementById('toggle_mapping_aktivitas').checked;
            if (!isExplicit) {
                let headerName = dynamicHeadersJS[inheritedFrom] || inheritedFrom;
                infoBox.innerHTML = `<i class='fas fa-info-circle me-1'></i> Saat ini, akun ini <b>otomatis mewarisi</b> mapping ke <b>[${inheritedFrom}] ${headerName}</b> dari induknya. Aktifkan toggle di bawah jika Anda ingin memisahkan mappingnya.`;
                infoBox.style.display = 'block';
            }
        }
    }
});

function filterParentDropdown(kat) { 
    const select = document.getElementById('coa_parent'); 
    select.querySelectorAll('option').forEach(o => { 
        if(o.value === "") return; 
        o.style.display = (o.getAttribute('data-kat') === kat) ? 'block' : 'none'; 
    }); 
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-edit-coa');
    if(btn) {
        const r = JSON.parse(btn.dataset.json);
        document.getElementById('coa_id').value = r.id;
        document.getElementById('coa_kode').value = r.kode_akun;
        document.getElementById('coa_nama').value = r.nama_akun;
        document.getElementById('coa_kategori').value = r.kategori;
        
        const sn_val = r.saldo_normal || '';
        const nb_val = r.normal_balance || '';
        const is_kredit = (sn_val === 'K' || nb_val.toUpperCase() === 'KREDIT');
        
        document.getElementById('coa_nb').value = is_kredit ? 'KREDIT' : 'DEBIT';
        document.getElementById('coa_rg').value = r.report_group || 'POSISI_KEUANGAN';
        document.getElementById('coa_lock').checked = (r.is_system_lock == 1);
        document.getElementById('coa_allow_posting').value = r.allow_posting !== null ? r.allow_posting : 1;

        const isGroup = (parseInt(r.is_group) === 1);
        document.getElementById(isGroup ? 'type_group' : 'type_detail').checked = true;
        toggleAccountType(isGroup ? 1 : 0);
        filterParentDropdown(r.kategori);
        
        const boxConfig = document.getElementById('box_konfigurasi_aktivitas');
        const infoBox = document.getElementById('info_inherited_mapping');
        
        if (r.kategori === 'Pendapatan' || r.kategori === 'Beban') {
            boxConfig.style.display = 'block';
            
            document.getElementById('coa_is_aktivitas_group').checked = (parseInt(r.is_aktivitas_group) === 1);
            
            const isExplicit = (r.grup_aktivitas && r.grup_aktivitas !== 'TIDAK_MASUK');
            
            if (isExplicit) {
                document.getElementById('toggle_mapping_aktivitas').checked = true;
                document.getElementById('coa_grup_aktivitas').value = r.grup_aktivitas;
            } else {
                document.getElementById('toggle_mapping_aktivitas').checked = false;
                document.getElementById('coa_grup_aktivitas').value = 'TIDAK_MASUK';
            }
            
            handleAktivitasConfig();
            
            if (!isExplicit) {
                let inheritedFrom = 'TIDAK_MASUK';
                let currParent = r.parent_kode;
                while(currParent) {
                    let pNode = allCoaData.find(a => a.kode_akun === currParent);
                    if (!pNode) break;
                    if (parseInt(pNode.is_aktivitas_group) === 1) { inheritedFrom = pNode.kode_akun; break; }
                    if (pNode.grup_aktivitas && pNode.grup_aktivitas !== 'TIDAK_MASUK') { inheritedFrom = pNode.grup_aktivitas; break; }
                    currParent = pNode.parent_kode;
                }

                if (inheritedFrom !== 'TIDAK_MASUK') {
                    let headerName = dynamicHeadersJS[inheritedFrom] || inheritedFrom;
                    infoBox.innerHTML = `<i class='fas fa-info-circle me-1'></i> Saat ini, akun ini <b>otomatis mewarisi</b> mapping ke <b>[${inheritedFrom}] ${headerName}</b> dari induknya. Aktifkan toggle di bawah jika Anda ingin memisahkan mappingnya.`;
                    infoBox.style.display = 'block';
                }
            }
            
        } else {
            boxConfig.style.display = 'none';
        }
        
        document.getElementById('coa_parent').value = r.parent_kode || '';
        document.getElementById('coa_ob').value = new Intl.NumberFormat('id-ID').format(r.opening_balance || 0);
        document.getElementById('coa_cf').value = r.cash_flow_category || 'NONE';
        
        document.getElementById('coa_title').innerText = 'Audit & Tata Kelola: ' + r.nama_akun;
        new bootstrap.Modal(document.getElementById('modalCOA')).show();
    }
});

function handleHapus(id, kode, isLocked) { 
    if(isLocked == 1) {
        alert("🛡️ AKSES DITOLAK: Akun ["+kode+"] adalah Akun Sistem yang dilindungi. Tidak dapat dihapus.");
        return;
    }
    if(confirm('Hapus Akun ['+kode+'] secara permanen? Pastikan tidak ada transaksi aktif di buku besar.')) { 
        document.getElementById('delId').value = id; 
        document.getElementById('delKode').value = kode; 
        document.getElementById('formHapusCOA').submit(); 
    } 
}
</script>