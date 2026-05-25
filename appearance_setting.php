<?php
/**
 * appearance_setting.php - UI CUSTOMIZATION ENGINE (WHITE LABEL)
 * Versi: 4.0 (Sovereign Dual-Title Editor)
 * Perbaikan: Memisahkan secara elegan konfigurasi "Nama Aplikasi" (Judul Besar),
 * "Slogan", "Judul Sidebar", hingga "Judul Tab Browser" agar identitas sistem 
 * 100% menjadi milik institusi sepenuhnya.
 */
if(!isset($conn)) { require_once 'config/koneksi.php'; }

$appr = $conn->query("SELECT * FROM sys_appearance WHERE id=1")->fetch_assoc();
if (!$appr) {
    $appr = [
        'login_bg' => '', 'font_family' => 'Inter, sans-serif', 'font_size' => '13px', 
        'primary_color' => '#0d6efd', 'outline_color' => '#e2e8f0',
        'app_name' => 'SYIFA ERP SYSTEM', 'app_slogan' => 'Integrated Financial & Asset System', 
        'sidebar_title' => 'SYIFA ERP', 'tab_style' => 'modern', 'browser_title' => 'SYIFA ERP System'
    ];
}
?>

<style>
    /* CSS Khusus Preview Gaya Tab Menu */
    .tab-preview-box { border: 2px solid #e2e8f0; border-radius: 12px; padding: 15px; cursor: pointer; transition: 0.2s; position: relative; background: #f8fafc; }
    .tab-preview-box:hover { border-color: var(--bs-primary); background: #ffffff; }
    .tab-preview-box.selected { border-color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), 0.05); }
    .tab-preview-box.selected::after { content: '\f058'; font-family: 'Font Awesome 5 Free'; font-weight: 900; position: absolute; top: 10px; right: 10px; color: var(--bs-primary); font-size: 20px; }
    
    .dummy-nav { display: flex; gap: 5px; list-style: none; padding: 0; margin: 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 0; }
    .dummy-link { padding: 10px 15px; font-size: 11px; font-weight: 700; color: #64748b; background: transparent; transition: 0.2s; }
    
    /* Mockup Modern Style */
    .style-modern .dummy-nav { border-bottom: 2px solid #cbd5e1; }
    .style-modern .dummy-link { border-radius: 8px 8px 0 0; }
    .style-modern .dummy-link.active { color: var(--bs-primary); border-bottom: 3px solid var(--bs-primary); background: rgba(var(--bs-primary-rgb), 0.1); }
    
    /* Mockup Pill Style */
    .style-pill .dummy-nav { border-bottom: none; }
    .style-pill .dummy-link { border-radius: 50px; margin-bottom: 5px; }
    .style-pill .dummy-link.active { background: var(--bs-primary); color: #fff; box-shadow: 0 4px 8px rgba(var(--bs-primary-rgb), 0.3); }
</style>

<div class="animate__animated animate__fadeIn">
    <div class="alert alert-info border-0 bg-info bg-opacity-10 text-dark shadow-sm rounded-4 mb-4 p-4">
        <h6 class="fw-bold mb-2"><i class="fas fa-paint-roller me-2 text-info"></i>Personalisasi Tampilan & Identitas Sistem (White-Label):</h6>
        <p class="mb-0 small">Ubah nuansa sistem ERP sesuai identitas institusi Anda. Pengaturan ini akan mengubah Nama Aplikasi, Gambar Latar Belakang (Halaman Login), Warna Utama (Tombol & Sorotan), serta <b>Keseluruhan Desain Tab Navigasi</b> di semua modul menu.</p>
    </div>

    <form action="settings.php" method="POST" enctype="multipart/form-data" class="card border-0 shadow-none">
        <input type="hidden" name="action_appr" value="save_appearance">
        
        <div class="row g-4 mb-4">
            <!-- PENGATURAN TEKS IDENTITAS -->
            <div class="col-md-6">
                <label class="small fw-bold text-primary mb-1 uppercase">Judul Utama Halaman Login</label>
                <input type="text" name="app_name" class="form-control border-0 bg-light shadow-sm py-2 px-3 fw-bold text-dark" value="<?= htmlspecialchars($appr['app_name'] ?? '') ?>" placeholder="Misal: SYIFA ERP SYSTEM" required>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-primary mb-1 uppercase">Slogan / Subtitle Halaman Login</label>
                <input type="text" name="app_slogan" class="form-control border-0 bg-light shadow-sm py-2 px-3 fw-bold text-dark" value="<?= htmlspecialchars($appr['app_slogan'] ?? '') ?>" placeholder="Misal: Integrated Financial & Asset System" required>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-primary mb-1 uppercase">Judul Sistem di Sidebar (Kiri Atas)</label>
                <input type="text" name="sidebar_title" class="form-control border-0 bg-light shadow-sm py-2 px-3 fw-bold text-dark" value="<?= htmlspecialchars($appr['sidebar_title'] ?? '') ?>" placeholder="Misal: SYIFA ERP" required>
            </div>
            <div class="col-md-6">
                <label class="small fw-bold text-primary mb-1 uppercase">Judul Tab Browser Sistem</label>
                <input type="text" name="browser_title" class="form-control border-0 bg-light shadow-sm py-2 px-3 fw-bold text-dark" value="<?= htmlspecialchars($appr['browser_title'] ?? '') ?>" placeholder="Misal: Keuangan Yarsi" required>
            </div>
        </div>

        <hr class="my-4 opacity-25">

        <div class="row g-4">
            <!-- Upload Background -->
            <div class="col-md-5">
                <label class="small fw-bold text-muted mb-2">Gambar Background Login (Desktop)</label>
                <div class="border rounded-4 bg-light p-3 text-center mb-3">
                    <?php if(!empty($appr['login_bg'])): ?>
                        <img src="assets/img/<?= $appr['login_bg'] ?>" id="previewBg" class="img-fluid rounded-3 shadow-sm mb-3" style="max-height: 180px; object-fit: cover; width: 100%;">
                    <?php else: ?>
                        <div id="noBg" class="py-5 text-muted"><i class="fas fa-image fa-3x mb-2 opacity-25"></i><br>Gunakan gambar Default</div>
                        <img src="" id="previewBg" class="img-fluid rounded-3 shadow-sm mb-3 d-none" style="max-height: 180px; object-fit: cover; width: 100%;">
                    <?php endif; ?>
                    
                    <label class="btn btn-outline-primary btn-sm rounded-pill px-4 fw-bold shadow-sm w-100 cursor-pointer">
                        <i class="fas fa-upload me-2"></i>GANTI GAMBAR LOGIN
                        <input type="file" name="login_bg" class="d-none" accept=".jpg,.jpeg,.png" onchange="previewBgImage(this)">
                    </label>
                </div>
                <small class="text-muted d-block" style="font-size: 11px;">* Format disarankan: JPG/PNG HD. Resolusi terbaik 1920x1080px (Lanskap).</small>
            </div>

            <!-- Konfigurasi Font & Warna -->
            <div class="col-md-7">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1">Jenis Font Sistem</label>
                        <select name="font_family" class="form-select bg-light border-0 shadow-sm py-2 px-3 fw-bold text-dark">
                            <option value="'Inter', sans-serif" <?= strpos($appr['font_family'], 'Inter')!==false?'selected':'' ?>>Inter (Modern Default)</option>
                            <option value="'Poppins', sans-serif" <?= strpos($appr['font_family'], 'Poppins')!==false?'selected':'' ?>>Poppins (Elegan & Bulat)</option>
                            <option value="'Roboto', sans-serif" <?= strpos($appr['font_family'], 'Roboto')!==false?'selected':'' ?>>Roboto (Kaku & Bersih)</option>
                            <option value="'Open Sans', sans-serif" <?= strpos($appr['font_family'], 'Open Sans')!==false?'selected':'' ?>>Open Sans (Standar Web)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold text-muted mb-1">Ukuran Base Font</label>
                        <select name="font_size" class="form-select bg-light border-0 shadow-sm py-2 px-3 fw-bold text-dark">
                            <option value="12px" <?= $appr['font_size']=='12px'?'selected':'' ?>>Kecil (12px)</option>
                            <option value="13px" <?= $appr['font_size']=='13px'?'selected':'' ?>>Normal (13px)</option>
                            <option value="14px" <?= $appr['font_size']=='14px'?'selected':'' ?>>Besar (14px)</option>
                        </select>
                    </div>

                    <div class="col-md-6 mt-4">
                        <label class="small fw-bold text-muted mb-1 d-block">Warna Utama (Primary Color)</label>
                        <div class="d-flex align-items-center gap-3 bg-light p-2 rounded-pill shadow-sm">
                            <input type="color" name="primary_color" class="form-control form-control-color border-0 p-0 rounded-circle" style="width:40px; height:40px; cursor:pointer;" value="<?= $appr['primary_color'] ?>" id="colorPrim" onchange="document.getElementById('hexPrim').innerText = this.value">
                            <span class="fw-bold text-dark font-monospace" id="hexPrim"><?= $appr['primary_color'] ?></span>
                        </div>
                    </div>
                    <div class="col-md-6 mt-4">
                        <label class="small fw-bold text-muted mb-1 d-block">Warna Garis (Outline/Border)</label>
                        <div class="d-flex align-items-center gap-3 bg-light p-2 rounded-pill shadow-sm">
                            <input type="color" name="outline_color" class="form-control form-control-color border-0 p-0 rounded-circle" style="width:40px; height:40px; cursor:pointer;" value="<?= $appr['outline_color'] ?>" id="colorOut" onchange="document.getElementById('hexOut').innerText = this.value">
                            <span class="fw-bold text-dark font-monospace" id="hexOut"><?= $appr['outline_color'] ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <hr class="my-4 opacity-25">

        <!-- PREVIEW GAYA TAB MENU -->
        <h6 class="fw-bold text-primary mb-3">Model Gaya Tab Menu (Navigasi Sistem)</h6>
        <p class="small text-muted mb-3">Pilih desain tabulasi yang akan diseragamkan ke seluruh halaman modul (Kas, Anggaran, HRIS, dll).</p>
        
        <!-- Input Hidden untuk menyimpan pilihan Tab -->
        <input type="hidden" name="tab_style" id="inp_tab_style" value="<?= $appr['tab_style'] ?>">

        <div class="row g-3 mb-4">
            <!-- Model Modern (Anggaran Style) -->
            <div class="col-md-6">
                <div class="tab-preview-box style-modern <?= $appr['tab_style'] == 'modern' ? 'selected' : '' ?>" onclick="selectTabStyle('modern')">
                    <div class="small fw-bold text-dark mb-2">Model Modern (Terkotak dengan Garis Bawah)</div>
                    <ul class="dummy-nav">
                        <li class="dummy-link active">Dashboard</li>
                        <li class="dummy-link">Worksheet Anggaran</li>
                        <li class="dummy-link">Monitoring</li>
                    </ul>
                </div>
            </div>
            
            <!-- Model Pill (Kapsul Oval) -->
            <div class="col-md-6">
                <div class="tab-preview-box style-pill <?= $appr['tab_style'] == 'pill' ? 'selected' : '' ?>" onclick="selectTabStyle('pill')">
                    <div class="small fw-bold text-dark mb-2">Model Kapsul (Oval Padat / Rounded)</div>
                    <ul class="dummy-nav">
                        <li class="dummy-link active">Terminal Kasir</li>
                        <li class="dummy-link">Data Tagihan</li>
                        <li class="dummy-link">Kontrol & Aging</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-4 text-end border-top pt-4">
            <button type="submit" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg"><i class="fas fa-save me-2"></i>SIMPAN TEMA & TAMPILAN</button>
        </div>
    </form>
</div>

<script>
function previewBgImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewBg').src = e.target.result;
            document.getElementById('previewBg').classList.remove('d-none');
            if(document.getElementById('noBg')) { document.getElementById('noBg').classList.add('d-none'); }
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function selectTabStyle(styleType) {
    document.getElementById('inp_tab_style').value = styleType;
    document.querySelectorAll('.tab-preview-box').forEach(el => el.classList.remove('selected'));
    document.querySelector('.style-' + styleType).classList.add('selected');
}
</script>