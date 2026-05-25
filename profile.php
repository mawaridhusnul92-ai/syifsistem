<?php
/**
 * profile.php - MANAJEMEN IDENTITAS INSTITUSI SYIFA
 * Versi: 17.0 (Clean Identity Form Edition)
 * Perbaikan: Menghilangkan Tab dan Fokus murni pada formulir Identitas & Logo,
 * karena Tombol Kembali sudah ditangani secara elegan oleh settings.php.
 */
if(!isset($conn)) { require_once 'config/koneksi.php'; }

$profile = $conn->query("SELECT * FROM system_profile WHERE id = 1")->fetch_assoc();
if(!$profile) {
    $profile = [
        'institution_name' => '', 'short_name' => '', 'address' => '', 'city' => '',
        'province' => '', 'postal_code' => '', 'phone' => '', 'email' => '', 'website' => '', 'logo' => ''
    ];
}
?>

<div class="animate__animated animate__fadeIn">
    <form action="settings.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_profile">
        
        <div class="row g-4">
            <!-- PANEL KIRI: VISUAL & LOGO -->
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 bg-light p-4 text-center h-100 border border-primary border-opacity-10">
                    <h6 class="fw-bold text-muted mb-4 text-uppercase" style="font-size: 11px;">Logo Resmi Institusi</h6>
                    <div class="mb-4 d-flex justify-content-center">
                        <div class="rounded-4 border-dashed p-3 d-flex align-items-center justify-content-center bg-white" style="width: 200px; height: 200px; border: 2px dashed #dee2e6;">
                            <?php if(!empty($profile['logo'])): ?>
                                <img src="assets/img/<?= $profile['logo'] ?>" id="previewLogo" class="img-fluid rounded-3" style="max-height: 100%;">
                            <?php else: ?>
                                <div id="noLogo" class="text-center text-muted"><i class="fas fa-image fa-3x mb-2 opacity-25"></i><br><small>Belum Ada Logo</small></div>
                                <img src="" id="previewLogo" class="img-fluid rounded-3 d-none" style="max-height: 100%;">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow-sm cursor-pointer">
                            <i class="fas fa-cloud-upload-alt me-2"></i>UNGGAH LOGO BARU
                            <input type="file" name="logo" class="d-none" onchange="previewImage(this)">
                        </label>
                    </div>
                    <p class="text-muted small mb-0">Format: JPG, PNG. Maks: 2MB.<br>Disarankan latar belakang transparan.</p>
                </div>
            </div>

            <!-- PANEL KANAN: FORM DATA -->
            <div class="col-md-8">
                <div class="card border-0 shadow-none rounded-0 p-0 h-100">
                    <h6 class="fw-bold text-primary mb-4 border-bottom pb-2">DATA UTAMA INSTITUSI</h6>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="small fw-bold text-muted mb-1">NAMA PERGURUAN TINGGI / PERUSAHAAN</label>
                            <input type="text" name="institution_name" class="form-control border-0 bg-light shadow-none fw-bold text-dark fs-6 py-2" value="<?= htmlspecialchars($profile['institution_name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">SINGKATAN</label>
                            <input type="text" name="short_name" class="form-control border-0 bg-light shadow-none py-2" value="<?= htmlspecialchars($profile['short_name']) ?>" placeholder="Contoh: YARSI">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">WEBSITE RESMI</label>
                            <div class="input-group">
                                <span class="input-group-text border-0 bg-light"><i class="fas fa-globe text-muted"></i></span>
                                <input type="text" name="website" class="form-control border-0 bg-light shadow-none" value="<?= htmlspecialchars($profile['website']) ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">EMAIL INSTITUSI</label>
                            <div class="input-group">
                                <span class="input-group-text border-0 bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                <input type="email" name="email" class="form-control border-0 bg-light shadow-none" value="<?= htmlspecialchars($profile['email']) ?>">
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary mb-4 mt-5 border-bottom pb-2">ALAMAT & KONTAK RESMI (SESUAI ISAK 35)</h6>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="small fw-bold text-muted mb-1">ALAMAT JALAN & NO. GEDUNG</label>
                            <textarea name="address" class="form-control border-0 bg-light shadow-none" rows="2" required><?= htmlspecialchars($profile['address']) ?></textarea>
                        </div>
                        <div class="col-md-5">
                            <label class="small fw-bold text-muted mb-1">KOTA / KABUPATEN</label>
                            <input type="text" name="city" class="form-control border-0 bg-light shadow-none" value="<?= htmlspecialchars($profile['city']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="small fw-bold text-muted mb-1">PROVINSI</label>
                            <input type="text" name="province" class="form-control border-0 bg-light shadow-none" value="<?= htmlspecialchars($profile['province']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted mb-1">KODE POS</label>
                            <input type="text" name="postal_code" class="form-control border-0 bg-light shadow-none" value="<?= htmlspecialchars($profile['postal_code']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold text-muted mb-1">NOMOR TELEPON / WA</label>
                            <div class="input-group">
                                <span class="input-group-text border-0 bg-light"><i class="fas fa-phone text-muted"></i></span>
                                <input type="text" name="phone" class="form-control border-0 bg-light shadow-none" value="<?= htmlspecialchars($profile['phone']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 text-end">
                        <button type="submit" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg">
                            <i class="fas fa-save me-2"></i>SIMPAN PERUBAHAN IDENTITAS
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewLogo').src = e.target.result;
            document.getElementById('previewLogo').classList.remove('d-none');
            if(document.getElementById('noLogo')) {
                document.getElementById('noLogo').classList.add('d-none');
            }
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<style>
    .border-dashed { border: 2px dashed #dee2e6 !important; transition: 0.3s; }
    .border-dashed:hover { border-color: #0d6efd !important; background-color: #f8fafc; }
    .cursor-pointer { cursor: pointer; }
</style>