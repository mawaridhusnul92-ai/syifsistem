<?php
/**
 * user_profile.php - HALAMAN PENGATURAN PROFIL INDIVIDU PENGGUNA
 * Versi: 3.1 (Sovereign Personal Space - Bulletproof Column Check)
 * Perbaikan: Menyesuaikan metode pengecekan kolom Avatar agar aman 
 * digunakan di MySQL versi berapapun tanpa memicu sintaks Error.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$uid = (int)$_SESSION['user_id'];

// 🛡️ AUTO-HEALER MENGGUNAKAN SHOW COLUMNS (AMANKAN DARI SYNTAX ERROR)
try { 
    $cek_av = $conn->query("SHOW COLUMNS FROM users LIKE 'avatar'");
    if ($cek_av && $cek_av->num_rows == 0) {
        @$conn->query("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL");
    }
} catch (Exception $e) {}

// --- LOGIKA PENYIMPANAN PROFIL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $password = trim($_POST['password'] ?? '');
    
    $conn->begin_transaction();
    try {
        // 🚀 TANGKAP HASIL CROP FOTO DARI BASE64
        if (!empty($_POST['cropped_avatar'])) {
            $base64_string = $_POST['cropped_avatar'];
            $img_parts = explode(";base64,", $base64_string);
            $img_type_aux = explode("image/", $img_parts[0]);
            $img_type = $img_type_aux[1] ?? 'png';
            $img_base64 = base64_decode($img_parts[1]);
            
            $avatar_name = 'avatar_' . $uid . '_' . time() . '.png';
            $target_dir = 'assets/img/avatars/';
            
            if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
            
            if (file_put_contents($target_dir . $avatar_name, $img_base64)) {
                $conn->query("UPDATE users SET avatar='$avatar_name' WHERE id=$uid");
            }
        }
        
        if (!empty($password) && $password !== '********') {
            $pass_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, password=? WHERE id=?");
            $stmt->bind_param("sssi", $name, $email, $pass_hash, $uid);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=? WHERE id=?");
            $stmt->bind_param("ssi", $name, $email, $uid);
        }
        $stmt->execute();
        $conn->commit();
        
        $_SESSION['name'] = $name;
        $_SESSION['email'] = $email;
        
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profil Anda berhasil diperbarui.'];
        header("Location: index.php?page=user_profile"); exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()];
    }
}

$user = $conn->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();
$avatar_url = !empty($user['avatar']) ? "assets/img/avatars/".$user['avatar'] : "";
?>

<!-- 🚀 IMPORT LIBRARY CROPPER.JS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4 text-dark">
        <div>
            <h4 class="fw-bold text-primary mb-0"><i class="fas fa-user-circle me-2"></i>Pengaturan Akun & Profil Saya</h4>
            <small class="text-muted fw-bold">Kelola Foto Profil, Username Login, dan Kunci Sandi Anda.</small>
        </div>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4 text-dark text-start">
            <i class="fas <?= $_SESSION['flash']['type']=='success'?'fa-check-circle':'fa-exclamation-triangle' ?> me-2"></i><?= $_SESSION['flash']['msg'] ?>
            <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <form action="index.php?page=user_profile" method="POST" id="profileForm" class="card border-0 shadow-none bg-transparent">
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="cropped_avatar" id="cropped_avatar_input">
        
        <div class="row g-4 text-dark">
            <!-- PANEL KIRI: AVATAR -->
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4 text-center h-100">
                    <h6 class="fw-bold text-muted mb-4 text-uppercase" style="font-size: 11px;">Foto Profil (Avatar)</h6>
                    <div class="mb-4 d-flex justify-content-center">
                        <div class="rounded-circle border-dashed p-1 d-flex align-items-center justify-content-center bg-light overflow-hidden shadow-sm position-relative" style="width: 180px; height: 180px; border: 2px dashed #dee2e8;">
                            <?php if($avatar_url): ?>
                                <img src="<?= $avatar_url ?>" id="previewAvatar" class="img-fluid rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div id="noAvatar" class="text-center text-muted"><i class="fas fa-user fa-4x mb-2 opacity-25"></i></div>
                                <img src="" id="previewAvatar" class="img-fluid rounded-circle d-none" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="btn btn-outline-primary btn-sm rounded-pill px-4 fw-bold shadow-sm" style="cursor:pointer;">
                            <i class="fas fa-camera me-2"></i>UNGGAH & SESUAIKAN FOTO
                            <input type="file" id="fileAvatarUpload" class="d-none" accept=".jpg,.jpeg,.png">
                        </label>
                    </div>
                    <p class="text-muted small mb-0">Sistem akan secara otomatis menyesuaikan rasio 1:1 (Persegi).</p>
                </div>
            </div>

            <!-- PANEL KANAN: DATA -->
            <div class="col-md-8">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4 h-100">
                    <h6 class="fw-bold text-primary mb-4 border-bottom pb-3">INFORMASI LOGIN & KEAMANAN</h6>
                    <div class="row g-4">
                        <div class="col-md-12">
                            <label class="small fw-bold text-muted mb-2 text-uppercase">Username Akses</label>
                            <input type="text" name="name" class="form-control form-control-lg border-0 bg-light shadow-sm rounded-pill px-4 fw-bold text-dark" value="<?= htmlspecialchars($user['name']) ?>" required>
                        </div>
                        <div class="col-md-12">
                            <label class="small fw-bold text-muted mb-2 text-uppercase">Alamat Email (Opsional)</label>
                            <input type="email" name="email" class="form-control form-control-lg border-0 bg-light shadow-sm rounded-pill px-4" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-12 mt-4">
                            <label class="small fw-bold text-danger mb-2 text-uppercase">Ganti Password / PIN</label>
                            <div class="input-group input-group-lg shadow-sm rounded-pill overflow-hidden">
                                <input type="password" name="password" id="user_pass" class="form-control border-0 bg-light px-4" placeholder="Ketik jika ingin mengubah...">
                                <button class="btn btn-light border-0 px-4 bg-light text-muted" type="button" onclick="togglePassVisibility()"><i class="fas fa-eye" id="eyeIcon"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 text-end border-top pt-4">
                        <button type="submit" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow-lg">SIMPAN PROFIL SAYA</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- 🚀 MODAL CROPPER STUDIO -->
<div class="modal fade" id="modalCrop" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-crop-alt me-2"></i>Sesuaikan Foto Profil</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3 bg-light text-center">
                <!-- 🛡️ Wadah Fix Cropper -->
                <div id="cropContainer" style="width: 100%; height: 400px; background: #000; overflow: hidden; border-radius: 8px;">
                    <img id="imageToCrop" style="max-width: 100%; display: block;" src="">
                </div>
            </div>
            <div class="modal-footer p-4 border-0 bg-white">
                <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-muted shadow-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow flex-grow-1" id="btnApplyCrop"><i class="fas fa-check me-2"></i>Terapkan Potongan</button>
            </div>
        </div>
    </div>
</div>

<script>
// 🚀 LOGIKA CROPPER JS
let cropper = null;
const imageToCrop = document.getElementById('imageToCrop');
const fileInput = document.getElementById('fileAvatarUpload');
const modalCropEl = document.getElementById('modalCrop');

fileInput.addEventListener('change', function(e) {
    const files = e.target.files;
    if (files && files.length > 0) {
        const reader = new FileReader();
        reader.onload = function(event) {
            imageToCrop.src = event.target.result;
            const modalCrop = bootstrap.Modal.getOrCreateInstance(modalCropEl);
            modalCrop.show();
        };
        reader.readAsDataURL(files[0]);
    }
});

modalCropEl.addEventListener('shown.bs.modal', function () {
    if (cropper) { cropper.destroy(); }
    cropper = new Cropper(imageToCrop, {
        aspectRatio: 1, 
        viewMode: 1,    
        autoCropArea: 1,
        responsive: true,
        guides: true,
        center: true,
        highlight: false,
        cropBoxMovable: true,
        cropBoxResizable: true,
        toggleDragModeOnDblclick: false,
    });
});

modalCropEl.addEventListener('hidden.bs.modal', function () {
    if (cropper) { cropper.destroy(); cropper = null; }
    fileInput.value = ''; 
});

document.getElementById('btnApplyCrop').addEventListener('click', function() {
    if (cropper) {
        const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
        const croppedBase64 = canvas.toDataURL('image/png');
        document.getElementById('cropped_avatar_input').value = croppedBase64;
        
        document.getElementById('previewAvatar').src = croppedBase64;
        document.getElementById('previewAvatar').classList.remove('d-none');
        if(document.getElementById('noAvatar')) { document.getElementById('noAvatar').classList.add('d-none'); }
        
        bootstrap.Modal.getInstance(modalCropEl).hide();
    }
});

function togglePassVisibility() { const input = document.getElementById('user_pass'); const icon = document.getElementById('eyeIcon'); if (input.type === "password") { input.type = "text"; icon.classList.replace('fa-eye', 'fa-eye-slash'); } else { input.type = "password"; icon.classList.replace('fa-eye-slash', 'fa-eye'); } }
</script>