<?php
/**
 * auto_number.php - UI PENGATURAN NOMOR DOKUMEN
 * Versi: 2.1 (Enterprise Clean Alert Edition)
 * Fitur: Format Builder, Live Preview, & Reset Control.
 * Perbaikan: Menghilangkan lingkaran biru pada kotak informasi agar lebih minimalis dan fokus pada teks.
 * STATUS: FULL CODE - NO TRUNCATION
 */
if(!isset($conn)) { require_once 'config/koneksi.php'; }

$nums = $conn->query("SELECT * FROM sys_auto_number ORDER BY module_name ASC")->fetch_all(MYSQLI_ASSOC);

function getPreview($prefix, $format, $last, $length) {
    $seq = str_pad($last + 1, $length, '0', STR_PAD_LEFT);
    $out = str_replace('{PREFIX}', $prefix, $format);
    $out = str_replace('{YEAR}', date('Y'), $out);
    $out = str_replace('{MONTH}', date('m'), $out);
    $out = str_replace('{SEQ}', $seq, $out);
    return $out;
}
?>

<div class="animate__animated animate__fadeIn">
    <!-- 🚀 DIUBAH: Alert Box Super Clean (Lingkaran biru dihapus murni) -->
    <div class="alert bg-white border border-primary border-opacity-25 border-start border-primary border-4 shadow-sm rounded-4 p-4 mb-4">
        <h6 class="fw-bold text-dark mb-1">Informasi Standarisasi Penomoran</h6>
        <p class="small text-muted mb-0">Nomor dokumen yang sudah digunakan tidak dapat diedit atau dihapus secara manual demi integritas data audit Institusi.</p>
    </div>

    <div class="table-responsive rounded-4 border bg-white shadow-sm">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase fw-bold text-muted">
                <tr>
                    <th class="ps-4">Modul Transaksi</th>
                    <th>Prefix</th>
                    <th>Format Penomoran</th>
                    <th class="text-center">Reset</th>
                    <th class="text-end">Terakhir</th>
                    <th>Preview Berikutnya</th>
                    <th class="text-center pe-4">Opsi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($nums as $r): 
                    $preview = getPreview($r['prefix'], $r['format'], $r['last_number'], $r['seq_length']);
                ?>
                <tr>
                    <td class="ps-4">
                        <div class="fw-bold text-dark"><?= $r['module_name'] ?></div>
                        <small class="text-muted">Key: <code><?= $r['module_key'] ?></code></small>
                    </td>
                    <td><span class="badge bg-dark rounded-pill px-3"><?= $r['prefix'] ?></span></td>
                    <td><code class="small"><?= $r['format'] ?></code></td>
                    <td class="text-center"><span class="badge bg-light text-dark border fw-normal"><?= $r['reset_type'] ?></span></td>
                    <td class="text-end fw-bold"><?= str_pad($r['last_number'], $r['seq_length'], '0', STR_PAD_LEFT) ?></td>
                    <td><span class="text-primary fw-bold"><?= $preview ?></span></td>
                    <td class="text-center pe-4">
                        <div class="btn-group btn-group-sm rounded-pill border bg-white overflow-hidden shadow-sm">
                            <button class="btn btn-white text-warning border-end" onclick='editNum(<?= json_encode($r) ?>)' title="Ubah Format"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-white text-danger" onclick="resetManual(<?= $r['id'] ?>)" title="Reset Manual ke Nol"><i class="fas fa-redo-alt"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL EDIT CONFIG -->
<div class="modal fade" id="modalEditNum" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="settings.php" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_autonum_config">
            <input type="hidden" name="id" id="num_id">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h6 class="modal-title fw-bold">Konfigurasi Nomor Dokumen</h6>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="small fw-bold mb-1">PREFIX (AWALAN)</label>
                        <input type="text" name="prefix" id="num_prefix" class="form-control border-0 shadow-sm fw-bold" required maxlength="10">
                    </div>
                    <div class="col-md-6">
                        <label class="small fw-bold mb-1">PANJANG DIGIT URUT</label>
                        <select name="seq_length" id="num_len" class="form-select border-0 shadow-sm">
                            <option value="3">3 Digit (001)</option>
                            <option value="4">4 Digit (0001)</option>
                            <option value="5">5 Digit (00001)</option>
                        </select>
                    </div>
                    
                    <div class="col-12">
                        <label class="small fw-bold mb-1">FORMAT STRUKTUR</label>
                        <select name="format" id="num_format" class="form-select border-0 shadow-sm fw-bold text-primary" required>
                            <option value="{PREFIX}/{YEAR}/{MONTH}/{SEQ}">{PREFIX}/{YEAR}/{MONTH}/{SEQ} (Cth: INV/2026/05/001)</option>
                            <option value="{PREFIX}/{YEAR}/{SEQ}">{PREFIX}/{YEAR}/{SEQ} (Cth: INV/2026/001)</option>
                            <option value="{PREFIX}-{YEAR}{MONTH}-{SEQ}">{PREFIX}-{YEAR}{MONTH}-{SEQ} (Cth: INV-202605-001)</option>
                            <option value="{PREFIX}-{YEAR}-{SEQ}">{PREFIX}-{YEAR}-{SEQ} (Cth: INV-2026-001)</option>
                            <option value="{PREFIX}/{SEQ}">{PREFIX}/{SEQ} (Cth: INV/001)</option>
                            <option value="{YEAR}/{MONTH}/{PREFIX}/{SEQ}">{YEAR}/{MONTH}/{PREFIX}/{SEQ} (Cth: 2026/05/INV/001)</option>
                        </select>
                        <div class="p-2 mt-2 small bg-white rounded border border-primary border-opacity-25 text-muted">
                            <i class="fas fa-lightbulb text-warning me-1"></i> Pilih struktur format yang paling sesuai untuk dokumen ini.
                        </div>
                    </div>
                    
                    <div class="col-md-12">
                        <label class="small fw-bold mb-1">TIPE RESET OTOMATIS</label>
                        <select name="reset_type" id="num_reset" class="form-select border-0 shadow-sm">
                            <option value="Monthly">Reset Tiap Bulan (0001)</option>
                            <option value="Yearly">Reset Tiap Tahun</option>
                            <option value="Never">Terusan (Tidak Pernah Reset)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch p-3 bg-white rounded-3 border">
                            <input class="form-check-input ms-0 me-3" type="checkbox" name="is_active" id="num_active" checked>
                            <label class="form-check-label small fw-bold">AKTIFKAN PENOMORAN INI</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 bg-light">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">SIMPAN PERUBAHAN</button>
            </div>
        </form>
    </div>
</div>

<script>
function editNum(data) {
    const m = new bootstrap.Modal(document.getElementById('modalEditNum'));
    document.getElementById('num_id').value = data.id;
    document.getElementById('num_prefix').value = data.prefix;
    
    // 🚀 SMART FALLBACK: Jika format dari Database tidak ada di pilihan dropdown, tambahkan otomatis!
    const formatSelect = document.getElementById('num_format');
    const formatExists = Array.from(formatSelect.options).some(opt => opt.value === data.format);
    if (!formatExists) {
        const newOption = new Option(data.format + " (Format Tersimpan)", data.format);
        formatSelect.add(newOption);
    }
    formatSelect.value = data.format;
    
    document.getElementById('num_len').value = data.seq_length;
    document.getElementById('num_reset').value = data.reset_type;
    document.getElementById('num_active').checked = (data.is_active == 1);
    m.show();
}

function resetManual(id) {
    if(confirm('Peringatan: Reset manual akan mengembalikan nomor urut ke 0001. Ini dapat menyebabkan bentrok jika tanggal transaksi belum berganti. Lanjutkan?')) {
        const f = document.createElement('form'); f.method='POST'; f.action='settings.php';
        f.innerHTML = `<input type="hidden" name="action" value="reset_autonum_manual"><input type="hidden" name="id" value="${id}">`;
        document.body.appendChild(f); f.submit();
    }
}
</script>
<style>.btn-white { background: #fff; border: none; } .btn-white:hover { background: #f8fafc; color: #0d6efd !important; }</style>