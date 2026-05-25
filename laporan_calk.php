<?php
/**
 * laporan_calk.php - CATATAN ATAS LAPORAN KEUANGAN (CALK) MASTER ROUTER
 * Versi: 28.0 (Sovereign Grand Master - Safe Router & Pre-Emptive Sync)
 * STATUS: 100% FULL CODE
 * Perbaikan Mutlak: 
 * 1. Mencegah HTTP 500 dengan memastikan fungsi PHP aman dari redeclaration.
 * 2. Memperbaiki JavaScript saveCalkSnapshotAsync agar SELALU memaksa
 * sinkronisasi Summernote sebelum data disedot, sehingga teks naratif 
 * dijamin muncul di Laporan Konsolidasi.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if(!function_exists('formatAkuntansi')) {
    function formatAkuntansi($val) {
        if ($val == 0) return "-";
        if ($val < 0) return "( " . number_format(abs($val), 0, ',', '.') . " )";
        return number_format($val, 0, ',', '.');
    }
}

if(!function_exists('fmtAudAsset')) {
    function fmtAudAsset($n, $isBold = false) {
        if ($n == 0) return "-";
        $f = number_format(abs($n), 0, ',', '.');
        if ($n < 0) $f = "($f)";
        $weight = $isBold ? "bold" : "normal";
        return "<div style='text-align: right; width: 100%; font-weight: $weight;'>Rp $f</div>";
    }
}

$calk_file = 'config/calk_data.json';
$calk_config_file = 'config/calk_config.json';
$calk_snapshot_file = 'config/calk_snapshot.json';

if (!file_exists('config')) { @mkdir('config', 0777, true); }

// Inisialisasi Data Naratif CALK
$calk_data = [];
if (!file_exists($calk_file)) {
    $calk_data = [
        ['title' => '1. UMUM', 'content' => '<p><b>a. Pendirian dan Informasi Umum</b><br>Sejarah pendirian institusi berawal dari... (Edit text ini)</p>'],
        ['title' => '2. KEBIJAKAN AKUNTANSI', 'content' => '<p>Laporan keuangan disusun berdasarkan Standar Akuntansi Keuangan ISAK 35...</p>'],
        ['title' => '3. KLASIFIKASI ASET BERSIH', 'content' => '<p>Aset bersih diklasifikasikan menjadi Tidak Terikat dan Terikat.</p>']
    ];
    file_put_contents($calk_file, json_encode($calk_data));
} else {
    $raw_calk = json_decode(file_get_contents($calk_file), true);
    if (is_array($raw_calk)) {
        if (isset($raw_calk['umum'])) {
            $calk_data = [
                ['title' => '1. UMUM', 'content' => '<p><b>a. Pendirian dan Informasi Umum</b><br>' . nl2br(htmlspecialchars($raw_calk['umum'] ?? '')) . '</p>'],
                ['title' => '2. KEBIJAKAN AKUNTANSI', 'content' => '<p>' . nl2br(htmlspecialchars($raw_calk['kebijakan'] ?? '')) . '</p>'],
                ['title' => '3. KLASIFIKASI ASET BERSIH', 'content' => '<p>' . nl2br(htmlspecialchars($raw_calk['aset_bersih'] ?? '')) . '</p>']
            ];
            file_put_contents($calk_file, json_encode($calk_data));
        } else {
            $calk_data = $raw_calk;
        }
    }
}

if (!file_exists($calk_config_file)) { file_put_contents($calk_config_file, json_encode(['categories' => []])); }

// HANDLER POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Simpan Naratif Saja
    if (isset($_POST['save_calk_naratif'])) {
        $titles = $_POST['naratif_title'] ?? [];
        $contents = $_POST['naratif_content'] ?? [];
        $new_data = [];
        for ($i = 0; $i < count($titles); $i++) {
            if (trim($titles[$i]) !== '') {
                $new_data[] = ['title' => trim($titles[$i]), 'content' => $contents[$i]];
            }
        }
        file_put_contents($calk_file, json_encode($new_data));
        
        if(isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            while (ob_get_level()) { @ob_end_clean(); } 
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'msg' => 'Narasi berhasil disimpan.']);
            exit;
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Deskripsi CALK (Bagian Naratif) berhasil disimpan!'];
        header("Location: index.php?page=laporan_calk&tab=deskripsi"); 
        exit;
    }
    
    // Simpan Snapshot (Periode & Data Narasi Sekaligus)
    if (isset($_POST['save_calk_snapshot'])) {
        
        if (isset($_POST['naratif_title'])) {
            $titles = $_POST['naratif_title'] ?? [];
            $contents = $_POST['naratif_content'] ?? [];
            $new_data = [];
            for ($i = 0; $i < count($titles); $i++) {
                if (trim($titles[$i]) !== '') {
                    $new_data[] = ['title' => trim($titles[$i]), 'content' => $contents[$i]];
                }
            }
            if(!empty($new_data)) file_put_contents($calk_file, json_encode($new_data));
        }

        // Ambil Snapshot lama agar periode tidak hilang jika disave dari tab narasi
        $old_snap = file_exists($calk_snapshot_file) ? json_decode(file_get_contents($calk_snapshot_file), true) : [];
        if(!is_array($old_snap)) $old_snap = [];

        $snapshot = $old_snap;
        foreach($_POST as $k => $v) {
            if (!in_array($k, ['save_calk_snapshot', 'action', 'page', 'tab', 'ajax', 'naratif_title', 'naratif_content', 'save_calk_naratif'])) { 
                $snapshot[$k] = $v; 
            }
        }
        file_put_contents($calk_snapshot_file, json_encode($snapshot));
        
        if(isset($_POST['ajax']) && $_POST['ajax'] == '1') {
            while (ob_get_level()) { @ob_end_clean(); } 
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'msg' => 'Snapshot dan Narasi tersimpan']);
            exit;
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Laporan CALK berhasil dikunci!'];
        header("Location: index.php?page=laporan_calk&tab=detail&" . http_build_query($snapshot)); 
        exit;
    }

    // Simpan Konfigurasi Builder (Tab 2)
    if (isset($_POST['save_calk_config'])) {
        file_put_contents($calk_config_file, $_POST['calk_json_data']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Struktur Format CALK (Builder) berhasil diperbarui!'];
        $params = $_POST; unset($params['save_calk_config'], $params['calk_json_data']);
        header("Location: index.php?page=laporan_calk&tab=detail&" . http_build_query($params)); 
        exit;
    }
}

$active_tab = $_GET['tab'] ?? 'deskripsi';
?>

<div class="container-fluid py-4 animate__animated animate__fadeIn">
    
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-info border-4">
        <div class="mb-3 mb-md-0">
            <h4 class="fw-bold text-dark mb-1"><i class="fas fa-book-open me-2 text-info"></i>Catatan Atas Laporan Keuangan (CALK)</h4>
            <p class="text-muted small mb-0 fw-bold">Dynamic Builder: Susun struktur Laporan, Tabel Aset, dan Narasi Rich Text.</p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap justify-content-md-end">
            <a href="index.php?page=laporan_keuangan" class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm"><i class="fas fa-arrow-left me-2"></i>Kembali</a>
            
            <form class="m-0 p-0 d-inline" id="formSaveSnapshot" onsubmit="saveCalkSnapshotAsync(event)">
                <input type="hidden" name="save_calk_snapshot" value="1">
                <?php foreach($_GET as $k => $v): if(!is_array($v) && !in_array($k, ['page','tab','ajax'])) echo "<input type='hidden' name='".htmlspecialchars($k)."' value='".htmlspecialchars($v)."'>"; endforeach; ?>
                <button type="submit" class="btn btn-success rounded-pill fw-bold shadow px-4 text-white" id="btnGenCalk" title="Simpan konfigurasi periode ini untuk ditarik ke Generate Konsolidasi">
                    <i class="fas fa-file-export me-2"></i>GENERATE CALK
                </button>
            </form>

            <?php if($active_tab == 'detail'): ?>
            <button type="button" id="btnToggleBuilder" class="btn btn-warning rounded-pill fw-bold shadow px-4 text-dark" onclick="toggleBuilder()"><i class="fas fa-hammer me-2"></i><span id="btnTextBuilder">Mode Setup CALK</span></button>
            <?php endif; ?>
        </div>
    </div>

    <?php if(isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> border-0 shadow-sm rounded-4 mb-4 fw-bold">
            <i class="fas fa-check-circle me-2"></i> <?= $_SESSION['flash']['msg'] ?>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="card-header bg-white border-bottom p-0">
            <ul class="nav nav-tabs nav-fill" style="border-bottom: 0;">
                <li class="nav-item"><a class="nav-link py-3 fw-bold <?= $active_tab=='deskripsi'?'active bg-light border-bottom border-primary border-3 text-primary':'text-muted' ?>" href="?page=laporan_calk&tab=deskripsi"><i class="fas fa-align-left me-2"></i> CALK 1 (Naratif)</a></li>
                <li class="nav-item"><a class="nav-link py-3 fw-bold <?= $active_tab=='detail'?'active bg-light border-bottom border-primary border-3 text-primary':'text-muted' ?>" href="?page=laporan_calk&tab=detail"><i class="fas fa-tools me-2"></i> CALK 2 (Rincian & Tabel Aset)</a></li>
            </ul>
        </div>
        
        <div class="card-body p-4">
            <?php 
                // 🚀 MODULAR INCLUSION: Memisahkan File Fisik
                if ($active_tab == 'deskripsi') {
                    if(file_exists('laporan_calk_naratif.php')) include 'laporan_calk_naratif.php';
                    else echo "<div class='alert alert-danger'>File laporan_calk_naratif.php tidak ditemukan!</div>";
                } else {
                    if(file_exists('laporan_calk_detail.php')) include 'laporan_calk_detail.php';
                    else echo "<div class='alert alert-danger'>File laporan_calk_detail.php tidak ditemukan!</div>";
                }
            ?>
        </div>
    </div>
</div>

<script>
function saveCalkSnapshotAsync(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    const oriText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
    btn.disabled = true;

    // 🚀 FIX MUTLAK 1: PRE-EMPTIVE SYNC SUMMERNOTE
    // Memaksa Editor Editor memuntahkan isinya ke dalam Textarea asli sebelum ditarik sistem.
    if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.summernote !== 'undefined') {
        window.jQuery('.summernote').each(function() {
            window.jQuery(this).val(window.jQuery(this).summernote('code'));
        });
    }

    // Menggabungkan data dari Form Naratif (Jika ada)
    const formNar = document.getElementById('formNaratif');
    const formData = formNar ? new FormData(formNar) : new FormData();
    
    // Hapus pengaman save_calk_naratif agar PHP mengeksekusi blok OMNI-SAVER (Snapshot)
    formData.delete('save_calk_naratif');

    formData.append('save_calk_snapshot', '1');
    formData.append('ajax', '1');

    // Menarik input dropdown dari Tab Detail (Rincian Aset)
    const periodFields = ['period_type', 'bulan', 'triwulan', 'semester', 'tahun'];
    periodFields.forEach(p => {
        const inputSrc = document.querySelector(`select[name="${p}"]`);
        if(inputSrc) {
            formData.delete(p);
            formData.append(p, inputSrc.value);
        }
    });

    const chk = document.getElementById('chkCompare');
    if(chk && chk.checked) {
        formData.append('is_compare', '1');
        ['comp_period_type', 'comp_bulan', 'comp_triwulan', 'comp_semester', 'comp_tahun'].forEach(p => {
            const inputSrc = document.querySelector(`select[name="${p}"]`);
            if(inputSrc) {
                formData.delete(p);
                formData.append(p, inputSrc.value);
            }
        });
    } else if (chk) {
        formData.append('is_compare', '0');
    }

    fetch('index.php?page=laporan_calk', { method: 'POST', body: formData })
    .then(async r => {
        const text = await r.text();
        try { return JSON.parse(text); } 
        catch(ex) { throw new Error("Format respon server tidak dikenali."); }
    })
    .then(res => {
        if(res.status === 'success') {
            btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Terkunci & Siap!';
            btn.classList.replace('btn-success', 'btn-primary');
            alert('Pengaturan periode dan narasi CALK berhasil dikunci! Kini siap ditarik di menu Generate Laporan Konsolidasi.');
            setTimeout(() => {
                btn.innerHTML = oriText;
                btn.classList.replace('btn-primary', 'btn-success');
                btn.disabled = false;
            }, 3000);
        }
    }).catch(e => {
        alert("Peringatan: " + e.message);
        btn.innerHTML = oriText;
        btn.disabled = false;
    });
}
</script>