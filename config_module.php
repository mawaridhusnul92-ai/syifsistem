<?php
session_start();
// Error Reporting Setup
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --- 1. KONEKSI DATABASE (/config/koneksi.php) ---
$db_host = 'localhost'; $db_user = 'root'; $db_pass = ''; $db_name = 'syifa';

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
} catch (mysqli_sql_exception $e) {
    die("<h3>Error Koneksi:</h3> Pastikan database 'syifa' sudah ada dan file 'syifa_config_db.sql' sudah diimport.<br>Error: " . $e->getMessage());
}

// --- 2. HELPER FUNCTIONS (/config/helper_coa.php) ---

// Fungsi "Magic Wand" Auto Number
function generateKodeOtomatis($conn, $parent_kode) {
    if (empty($parent_kode)) return '';

    // Cari anak terakhir dari parent ini
    $sql = "SELECT kode_akun FROM syifa_akun WHERE parent_kode = ? ORDER BY kode_akun DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $parent_kode);
    $stmt->execute();
    $result = $stmt->get_result();
    $last_child = $result->fetch_assoc();

    if ($last_child) {
        // Jika sudah ada anak (misal 1-1001), ambil angka terakhir dan increment
        // Asumsi format Kode selalu diakhiri angka
        $last_code = $last_child['kode_akun'];
        
        // Logika sederhana: Ambil bagian numerik terakhir
        if (preg_match('/(\d+)$/', $last_code, $matches)) {
            $number = intval($matches[1]);
            $new_number = $number + 1;
            $len = strlen($matches[1]);
            // Rebuild string
            return preg_replace('/(\d+)$/', str_pad($new_number, $len, '0', STR_PAD_LEFT), $last_code);
        }
    } else {
        // Jika belum ada anak, buat anak pertama (misal 1-1000 jadi 1-1001)
        // Logika: Tambahkan '1' atau '01' di belakang parent tergantung pola
        return $parent_kode . "1"; // Sederhana: Parent "1-100" -> Anak "1-1001"
    }
    return $parent_kode . "-01"; // Fallback
}

// Fungsi Render Tree Hierarki
function renderCoaTree($conn, $parent_id = NULL, $kategori_group = 'Neraca') {
    // Filter kategori untuk tampilan Kiri (Neraca) dan Kanan (Laba Rugi)
    $filter_kat = "";
    if ($kategori_group == 'Neraca') {
        $filter_kat = "AND kategori IN ('Aset','Liabilitas','Aset Neto')";
    } else {
        $filter_kat = "AND kategori IN ('Pendapatan','Beban')";
    }

    $sql = "SELECT * FROM syifa_akun WHERE parent_kode " . ($parent_id ? "= '$parent_id'" : "IS NULL") . " $filter_kat ORDER BY kode_akun ASC";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        echo "<ul class='list-unstyled ms-3'>";
        while ($row = $result->fetch_assoc()) {
            $icon = $row['is_group'] ? "fa-folder text-warning" : "fa-file-alt text-secondary";
            $bold = $row['is_group'] ? "fw-bold" : "";
            $badge = $row['is_group'] ? "" : "<span class='badge bg-info text-dark ms-2' style='font-size:0.7em'>".$row['saldo_normal']."</span>";
            
            // Flags badges
            $flags = "";
            if($row['is_cash_account']) $flags .= "<i class='fas fa-money-bill text-success ms-1' title='Akun Kas'></i>";
            if($row['is_budget_account']) $flags .= "<i class='fas fa-chart-pie text-primary ms-1' title='Akun Anggaran'></i>";

            echo "<li class='mb-1'>";
            echo "<div class='d-flex align-items-center'>";
            // Tombol Expand/Collapse (Dummy logic)
            echo "<i class='fas $icon me-2'></i>";
            echo "<span class='$bold'>{$row['kode_akun']} - {$row['nama_akun']} $badge $flags</span>";
            
            // Action Buttons
            echo "<div class='ms-auto btn-group btn-group-sm'>";
            if ($row['is_group']) {
                echo "<button class='btn btn-outline-primary btn-xs' onclick=\"openModalAdd('{$row['kode_akun']}', '{$row['nama_akun']}', '{$row['kategori']}')\" title='Tambah Anak'><i class='fas fa-plus'></i></button>";
            }
            echo "<button class='btn btn-outline-secondary btn-xs' title='Edit'><i class='fas fa-edit'></i></button>";
            echo "</div>"; // end btn group
            
            echo "</div>"; // end flex
            
            // Recursive Call
            renderCoaTree($conn, $row['kode_akun'], $kategori_group);
            
            echo "</li>";
        }
        echo "</ul>";
    }
}

// --- 3. CONTROLLER LOGIC (HANDLE POST REQUESTS) ---

$action = $_GET['action'] ?? '';

// Simpan Profil
if ($action == 'save_profile') {
    $nama = $_POST['nama_institusi'];
    $alamat = $_POST['alamat'];
    $thn = $_POST['thn_ajaran'];
    // Update (asumsi ID 1 selalu ada atau insert jika kosong)
    $check = $conn->query("SELECT id FROM syifa_setting_profile LIMIT 1");
    if($check->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE syifa_setting_profile SET nama_institusi=?, alamat=?, thn_ajaran_aktif=? WHERE id=1");
        $stmt->bind_param("sss", $nama, $alamat, $thn);
    } else {
        $stmt = $conn->prepare("INSERT INTO syifa_setting_profile (nama_institusi, alamat, thn_ajaran_aktif) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $nama, $alamat, $thn);
    }
    $stmt->execute();
    header("Location: ?tab=profile&msg=saved"); exit;
}

// Simpan Akun (Group & Detail)
if ($action == 'save_akun') {
    $kode = $_POST['kode_akun'];
    $nama = $_POST['nama_akun'];
    $parent = $_POST['parent_kode'] ?: NULL;
    $is_group = $_POST['is_group'];
    $kategori = $_POST['kategori'];
    $saldo_normal = $_POST['saldo_normal'];
    $jenis_dana = $_POST['jenis_dana'];
    
    // Field Detail Khusus
    $cashflow = $_POST['cashflow_type'] ?? 'None';
    $is_cash = isset($_POST['is_cash_account']) ? 1 : 0;
    $is_budget = isset($_POST['is_budget_account']) ? 1 : 0;
    $is_student = isset($_POST['is_student_related']) ? 1 : 0;
    
    // Cek Duplikat
    $cek = $conn->query("SELECT kode_akun FROM syifa_akun WHERE kode_akun = '$kode'");
    if($cek->num_rows > 0) {
        echo "<script>alert('Gagal: Kode Akun $kode sudah ada!'); window.history.back();</script>"; exit;
    }

    $stmt = $conn->prepare("INSERT INTO syifa_akun 
        (kode_akun, nama_akun, parent_kode, is_group, kategori, saldo_normal, jenis_dana, cashflow_type, is_cash_account, is_budget_account, is_student_related) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssissssiii", $kode, $nama, $parent, $is_group, $kategori, $saldo_normal, $jenis_dana, $cashflow, $is_cash, $is_budget, $is_student);
    
    if($stmt->execute()) {
        header("Location: ?tab=coa&msg=akun_saved"); exit;
    } else {
        die("Error: " . $conn->error);
    }
}

// AJAX: Magic Wand Auto Number
if ($action == 'get_auto_kode') {
    $parent = $_GET['parent'];
    echo generateKodeOtomatis($conn, $parent);
    exit;
}

// --- 4. VIEW RENDERING ---
$active_tab = $_GET['tab'] ?? 'coa';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modul Pengaturan - SYIFA ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { width: 250px; background: #2c3e50; min-height: 100vh; color: #fff; position: fixed; }
        .content { margin-left: 250px; padding: 20px; }
        .nav-tabs .nav-link.active { border-top: 3px solid #3498db; font-weight: bold; color: #2c3e50; }
        .tree-container { max-height: 700px; overflow-y: auto; background: white; border: 1px solid #ddd; padding: 15px; border-radius: 5px; }
        .form-check-input:checked { background-color: #2ecc71; border-color: #2ecc71; }
    </style>
</head>
<body>

<div class="sidebar p-3">
    <h4 class="text-center mb-4"><i class="fas fa-university"></i> SYIFA ERP</h4>
    <hr>
    <ul class="nav flex-column">
        <li class="nav-item mb-2"><a href="#" class="nav-link text-white"><i class="fas fa-home me-2"></i> Dashboard</a></li>
        <li class="nav-item mb-2"><a href="?tab=coa" class="nav-link text-white active bg-primary rounded"><i class="fas fa-cogs me-2"></i> Pengaturan</a></li>
    </ul>
</div>

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="fas fa-sliders-h text-primary"></i> Modul Pengaturan</h3>
        <span class="text-muted">Administrator Mode</span>
    </div>

    <!-- TABS MENU -->
    <ul class="nav nav-tabs mb-4 bg-white shadow-sm rounded px-3 pt-2">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab=='profile'?'active':''; ?>" href="?tab=profile"><i class="fas fa-university me-2"></i> Profil Kampus</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab=='coa'?'active':''; ?>" href="?tab=coa"><i class="fas fa-list-ol me-2"></i> Master COA</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab=='budget'?'active':''; ?>" href="?tab=budget"><i class="fas fa-money-check-alt me-2"></i> Mapping Anggaran</a>
        </li>
    </ul>

    <!-- TAB CONTENT: PROFILE -->
    <?php if ($active_tab == 'profile'): 
        $prof = $conn->query("SELECT * FROM syifa_setting_profile LIMIT 1")->fetch_assoc();
    ?>
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h5 class="card-title mb-4">Identitas Institusi</h5>
            <form action="?action=save_profile" method="POST">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label>Nama Institusi</label>
                        <input type="text" name="nama_institusi" class="form-control" value="<?php echo $prof['nama_institusi']??''; ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label>Tahun Ajaran Aktif</label>
                        <input type="text" name="thn_ajaran" class="form-control" value="<?php echo $prof['thn_ajaran_aktif']??''; ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label>Alamat Lengkap</label>
                    <textarea name="alamat" class="form-control" rows="3"><?php echo $prof['alamat']??''; ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Simpan Profil</button>
            </form>
        </div>
    </div>
    
    <!-- TAB CONTENT: COA MASTER -->
    <?php elseif ($active_tab == 'coa'): ?>
    <div class="row">
        <!-- KIRI: NERACA -->
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-primary">Posisi Keuangan (Neraca)</h6>
                    <button class="btn btn-sm btn-outline-success" onclick="openModalAddRoot('Neraca')"><i class="fas fa-plus"></i> Grup Baru</button>
                </div>
                <div class="card-body tree-container">
                    <?php renderCoaTree($conn, NULL, 'Neraca'); ?>
                </div>
            </div>
        </div>
        <!-- KANAN: LABA RUGI -->
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-success">Laporan Aktivitas (Laba Rugi)</h6>
                    <button class="btn btn-sm btn-outline-success" onclick="openModalAddRoot('LabaRugi')"><i class="fas fa-plus"></i> Grup Baru</button>
                </div>
                <div class="card-body tree-container">
                    <?php renderCoaTree($conn, NULL, 'LabaRugi'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL ADD AKUN (GABUNGAN GROUP & DETAIL) -->
    <div class="modal fade" id="modalAkun" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form action="?action=save_akun" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTitle">Tambah Akun</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- HIDDEN FIELDS -->
                        <input type="hidden" name="parent_kode" id="inputParent">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label>Akun Induk (Parent)</label>
                                <input type="text" class="form-control bg-light" id="viewParentName" readonly>
                            </div>
                            <div class="col-md-6">
                                <label>Tipe Akun</label>
                                <select name="is_group" id="inputIsGroup" class="form-select" onchange="toggleDetailFields()">
                                    <option value="1">Grup / Folder (Header)</option>
                                    <option value="0">Akun Detail (Detail)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label>Kode Akun <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" name="kode_akun" id="inputKode" class="form-control" required placeholder="Contoh: 1-1001">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateMagicCode()" title="Auto Number"><i class="fas fa-magic"></i></button>
                                </div>
                                <small class="text-muted">Klik tongkat sihir untuk auto-number</small>
                            </div>
                            <div class="col-md-8">
                                <label>Nama Akun <span class="text-danger">*</span></label>
                                <input type="text" name="nama_akun" class="form-control" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label>Kategori (Klasifikasi)</label>
                                <select name="kategori" id="inputKategori" class="form-select">
                                    <option value="Aset">Aset</option>
                                    <option value="Liabilitas">Liabilitas</option>
                                    <option value="Aset Neto">Aset Neto</option>
                                    <option value="Pendapatan">Pendapatan</option>
                                    <option value="Beban">Beban</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Saldo Normal</label>
                                <select name="saldo_normal" class="form-select">
                                    <option value="D">Debit</option>
                                    <option value="K">Kredit</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>Jenis Dana (ISAK 35)</label>
                                <select name="jenis_dana" class="form-select">
                                    <option value="Tanpa Pembatasan">Tanpa Pembatasan</option>
                                    <option value="Dengan Pembatasan">Dengan Pembatasan</option>
                                </select>
                            </div>
                        </div>

                        <!-- FIELDS KHUSUS DETAIL -->
                        <div id="detailFields" style="display:none; background: #f8f9fa; padding: 15px; border-radius: 5px;">
                            <h6 class="text-muted mb-3"><i class="fas fa-project-diagram me-1"></i> Integrasi Modul Lain</h6>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label>Arus Kas (Cashflow)</label>
                                    <select name="cashflow_type" class="form-select">
                                        <option value="None">Tidak Masuk Arus Kas</option>
                                        <option value="Operasional">Aktivitas Operasional</option>
                                        <option value="Investasi">Aktivitas Investasi</option>
                                        <option value="Pendanaan">Aktivitas Pendanaan</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_cash_account" id="chk1">
                                        <label class="form-check-label" for="chk1">Akun Kas/Bank (Bisa menerima uang)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_budget_account" id="chk2">
                                        <label class="form-check-label" for="chk2">Akun Anggaran (Bisa di-budgeting)</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_student_related" id="chk3">
                                        <label class="form-check-label" for="chk3">Terkait Tagihan Mahasiswa</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan Akun</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB CONTENT: BUDGET MAPPING -->
    <?php elseif ($active_tab == 'budget'): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Mapping Akun Anggaran</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info"><i class="fas fa-info-circle"></i> Tentukan akun mana saja yang digunakan untuk penyusunan RAB dan Anggaran Belanja.</div>
            <table class="table table-bordered table-striped">
                <thead class="table-dark">
                    <tr>
                        <th>Kode Akun</th>
                        <th>Nama Akun</th>
                        <th>Jenis Anggaran</th>
                        <th>Unit Terkait</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $maps = $conn->query("SELECT m.*, a.nama_akun FROM syifa_anggaran_map m JOIN syifa_akun a ON m.kode_akun=a.kode_akun");
                    while($m = $maps->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?php echo $m['kode_akun']; ?></td>
                        <td><?php echo $m['nama_akun']; ?></td>
                        <td><span class="badge bg-primary"><?php echo $m['jenis_anggaran']; ?></span></td>
                        <td><?php echo $m['unit_terkait']; ?></td>
                        <td><button class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <!-- Form tambah sederhana (bisa dikembangkan) -->
            <button class="btn btn-primary mt-3"><i class="fas fa-plus"></i> Tambah Mapping</button>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- JAVASCRIPT LOGIC -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var akunModal = new bootstrap.Modal(document.getElementById('modalAkun'));

    function openModalAddRoot(type) {
        document.getElementById('modalTitle').innerText = 'Tambah Grup Akun Utama';
        document.getElementById('inputParent').value = '';
        document.getElementById('viewParentName').value = 'ROOT (Tanpa Induk)';
        document.getElementById('inputIsGroup').value = '1';
        document.getElementById('inputIsGroup').disabled = true; // Root wajib Group
        
        // Auto set kategori based on column
        if(type === 'Neraca') {
            document.getElementById('inputKategori').value = 'Aset';
        } else {
            document.getElementById('inputKategori').value = 'Pendapatan';
        }
        
        toggleDetailFields();
        akunModal.show();
    }

    function openModalAdd(parentKode, parentName, kategori) {
        document.getElementById('modalTitle').innerText = 'Tambah Anak Akun';
        document.getElementById('inputParent').value = parentKode;
        document.getElementById('viewParentName').value = parentKode + ' - ' + parentName;
        document.getElementById('inputIsGroup').value = '0'; // Default Detail
        document.getElementById('inputIsGroup').disabled = false;
        document.getElementById('inputKategori').value = kategori;
        
        // Bersihkan input
        document.getElementById('inputKode').value = '';
        
        toggleDetailFields();
        akunModal.show();
    }

    function toggleDetailFields() {
        var isGroup = document.getElementById('inputIsGroup').value;
        var detailDiv = document.getElementById('detailFields');
        if (isGroup == '0') {
            detailDiv.style.display = 'block';
        } else {
            detailDiv.style.display = 'none';
        }
    }

    function generateMagicCode() {
        var parent = document.getElementById('inputParent').value;
        if (!parent) {
            alert('Silakan pilih akun induk terlebih dahulu (kecuali Root).');
            return;
        }
        
        // AJAX Request ke helper
        fetch('?action=get_auto_kode&parent=' + parent)
            .then(response => response.text())
            .then(data => {
                document.getElementById('inputKode').value = data;
            });
    }
</script>
</body>
</html>