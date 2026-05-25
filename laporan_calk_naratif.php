<?php
// Inisialisasi Data Naratif CALK
if (!isset($calk_file)) $calk_file = 'config/calk_data.json';
$calk_data = [];

if (!file_exists($calk_file)) {
    $calk_data = [
        ['title' => '1. UMUM', 'content' => '<p><b>a. Pendirian dan Informasi Umum</b><br>Sejarah pendirian institusi berawal dari... (Edit text ini)</p>'],
        ['title' => '2. KEBIJAKAN AKUNTANSI', 'content' => '<p>Laporan keuangan disusun berdasarkan Standar Akuntansi Keuangan ISAK 35...</p>'],
        ['title' => '3. KLASIFIKASI ASET BERSIH', 'content' => '<p>Aset bersih diklasifikasikan menjadi Tidak Terikat dan Terikat.</p>']
    ];
} else {
    $raw_calk = json_decode(file_get_contents($calk_file), true);
    if (is_array($raw_calk)) {
        if (isset($raw_calk['umum'])) {
            $calk_data = [
                ['title' => '1. UMUM', 'content' => '<p><b>a. Pendirian dan Informasi Umum</b><br>' . nl2br(htmlspecialchars($raw_calk['umum'] ?? '')) . '</p>'],
                ['title' => '2. KEBIJAKAN AKUNTANSI', 'content' => '<p>' . nl2br(htmlspecialchars($raw_calk['kebijakan'] ?? '')) . '</p>'],
                ['title' => '3. KLASIFIKASI ASET BERSIH', 'content' => '<p>' . nl2br(htmlspecialchars($raw_calk['aset_bersih'] ?? '')) . '</p>']
            ];
        } else {
            $calk_data = $raw_calk;
        }
    }
}
?>

<div class="alert bg-primary bg-opacity-10 text-dark border border-primary border-opacity-25 rounded-4 mb-4 small fw-bold shadow-sm">
    <i class="fas fa-info-circle me-2 text-primary"></i><b>NARRATIVE BUILDER:</b> Anda dapat menambahkan blok narasi tanpa batas. Gunakan editor untuk mengatur teks dan tabel.
</div>

<form method="POST" id="formNaratif">
    <input type="hidden" name="save_calk_naratif" value="1">
    
    <div id="naratif-wrapper">
        <?php foreach($calk_data as $idx => $nar): ?>
        <div class="naratif-item mb-4 bg-white p-4 rounded-4 border shadow-sm animate__animated animate__fadeIn">
            <div class="d-flex justify-content-between mb-3 gap-3">
                <input type="text" name="naratif_title[]" class="form-control fw-bold fs-5 border-0 bg-light px-4 rounded-pill text-dark" value="<?= htmlspecialchars($nar['title'] ?? '') ?>" placeholder="Judul Bagian (Contoh: 1. UMUM)" required>
                <button type="button" class="btn btn-outline-danger rounded-pill px-4 shadow-sm fw-bold" onclick="this.closest('.naratif-item').remove()"><i class="fas fa-trash me-2"></i>Hapus Bagian</button>
            </div>
            <!-- 🚀 Teks Editor -->
            <textarea name="naratif_content[]" class="summernote summernote-raw"><?= htmlspecialchars($nar['content'] ?? '') ?></textarea>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="d-flex justify-content-between align-items-center border-top pt-4 mt-2">
        <button type="button" class="btn btn-outline-primary rounded-pill px-4 fw-bold shadow-sm" onclick="addNaratif()"><i class="fas fa-plus me-2"></i>TAMBAH JUDUL / PARAGRAF BARU</button>
        <button type="button" class="btn btn-primary rounded-pill px-5 py-3 fw-bold shadow" onclick="saveNaratifAsync(this)"><i class="fas fa-save me-2"></i>SIMPAN NARASI CALK</button>
    </div>
</form>

<!-- 🚀 PENGAMAT JQUERY & SUMMERNOTE (KHUSUS TAB NARASI) -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    let checkJQ = setInterval(function() {
        if (typeof window.jQuery !== 'undefined') {
            clearInterval(checkJQ);
            
            if (typeof window.jQuery.fn.summernote === 'undefined') {
                let script = document.createElement('script');
                script.src = "https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js";
                script.onload = function() { initSummernote(); };
                document.head.appendChild(script);
            } else {
                initSummernote();
            }
        }
    }, 100);
});

// 🚀 INISIALISASI MURNI: Mengembalikan Pengaturan Font, Size, Bold, Italic yang hilang
function initSummernote() {
    window.jQuery('.summernote').each(function() {
        if (!window.jQuery(this).data('summernote') && !window.jQuery(this).next().hasClass('note-editor')) {
            window.jQuery(this).summernote({
                height: 300,
                minHeight: 300,
                dialogsInBody: true,
                placeholder: 'Rancang format narasi laporan atau sisipkan tabel di sini...',
                toolbar: [
                    ['style', ['style']],
                    ['fontname', ['fontname']],
                    ['fontsize', ['fontsize']],
                    ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['hr', 'link']],
                    ['view', ['fullscreen', 'codeview']]
                ],
                fontNames: ['Arial', 'Arial Black', 'Courier New', 'Helvetica', 'Impact', 'Tahoma', 'Times New Roman', 'Verdana'],
                popover: {
                    table: [
                        ['add', ['addRowDown', 'addRowUp', 'addColLeft', 'addColRight']],
                        ['delete', ['deleteRow', 'deleteCol', 'deleteTable']],
                    ]
                }
            });
        }
    });
}

function addNaratif() {
    const html = `
    <div class="naratif-item mb-4 bg-white p-4 rounded-4 border shadow-sm animate__animated animate__fadeIn">
        <div class="d-flex justify-content-between mb-3 gap-3">
            <input type="text" name="naratif_title[]" class="form-control fw-bold fs-5 border-0 bg-light px-4 rounded-pill text-dark" placeholder="Ketik Judul Bagian di Sini" required>
            <button type="button" class="btn btn-outline-danger rounded-pill px-4 shadow-sm fw-bold" onclick="this.closest('.naratif-item').remove()"><i class="fas fa-trash me-2"></i>Hapus Bagian</button>
        </div>
        <textarea name="naratif_content[]" class="summernote summernote-raw"></textarea>
    </div>`;
    window.jQuery('#naratif-wrapper').append(html);
    initSummernote(); 
}

function saveNaratifAsync(btnEl) {
    // 🚀 OMNI-SYNC MANUAL PULL: Memaksa sinkronisasi isi Summernote ke <textarea>
    if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.summernote !== 'undefined') {
        window.jQuery('.summernote').each(function() {
            window.jQuery(this).val(window.jQuery(this).summernote('code'));
        });
    }

    const form = document.getElementById('formNaratif');
    const formData = new FormData(form);
    formData.append('ajax', '1');
    
    const oriText = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...';
    btnEl.disabled = true;

    fetch('index.php?page=laporan_calk', { method: 'POST', body: formData })
    .then(async r => {
        const text = await r.text();
        try { return JSON.parse(text); } 
        catch(e) { throw new Error("Gagal parsing balasan JSON dari server."); }
    })
    .then(res => {
        if(res.status === 'success') {
            btnEl.innerHTML = '<i class="fas fa-check-circle me-2"></i>Tersimpan!';
            btnEl.classList.replace('btn-primary', 'btn-success');
            setTimeout(() => {
                btnEl.innerHTML = oriText;
                btnEl.classList.replace('btn-success', 'btn-primary');
                btnEl.disabled = false;
            }, 2000);
        }
    }).catch(e => {
        alert("Terjadi Kendala: " + e.message);
        btnEl.innerHTML = oriText;
        btnEl.disabled = false;
    });
}

// 🚀 OMNI-SYNC EXTENSION: Menyinkronkan teks juga saat tombol GENERATE CALK (di header router) ditekan
const formSaveSnapshot = document.getElementById('formSaveSnapshot');
if (formSaveSnapshot) {
    formSaveSnapshot.addEventListener('submit', function(e) {
        if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.summernote !== 'undefined') {
            window.jQuery('.summernote').each(function() {
                window.jQuery(this).val(window.jQuery(this).summernote('code'));
            });
        }
    });
}
</script>

<style>
    /* 🚀 SUMMERNOTE UI FIXES */
    .note-editor.note-lite { border-radius: 16px; border: 1px solid #cbd5e1; overflow: hidden; background: #fff; }
    
    /* Mencegah Textarea mengecil jika JS lambat termuat */
    .summernote-raw { width: 100%; min-height: 300px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 15px; display: block; }
    
    .note-editor.note-lite .note-editing-area .note-editable { 
        padding: 20px; font-family: 'Times New Roman', Times, serif; 
        font-size: 14px; color: #000; min-height: 300px !important; 
    }
    
    .note-toolbar { background: #f8fafc !important; border-bottom: 1px solid #e2e8f0 !important; padding: 10px !important; }
    .note-popover { z-index: 10500 !important; }
    
    .note-editor.note-lite .note-editing-area .note-editable table,
    .calk-text table { width: 100% !important; table-layout: fixed !important; word-wrap: break-word !important; }
    .note-editor.note-lite .note-editing-area .note-editable table td,
    .note-editor.note-lite .note-editing-area .note-editable table th,
    .calk-text table td, .calk-text table th { word-break: break-word !important; overflow-wrap: break-word !important; border: 1px solid #cbd5e1;}
</style>