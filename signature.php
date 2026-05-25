<?php
/**
 * signature.php - DYNAMIC SIGNATURE MANAGER
 * Versi: 3.0 (Sovereign Specific Assignment Edition)
 * Deskripsi: Memungkinkan user membuat tanda tangan sebebas-bebasnya.
 * Role tujuan tanda tangan diketik manual sehingga tidak kaku.
 */
if(!isset($conn)) { require_once 'config/koneksi.php'; }

// DEFINISI JENIS DOKUMEN CETAK YANG ADA DI SISTEM
$doc_types = [
    'SLIP_HONOR' => 'Slip Kuitansi Honorarium Dosen',
    'LAPORAN_HONOR' => 'Laporan Rekap Pengajuan Honorarium',
    'KEUANGAN' => 'Laporan Posisi Keuangan (Neraca)',
    'ASET_NETO' => 'Laporan Perubahan Aset Neto',
    'AKTIVITAS' => 'Laporan Aktivitas',
    'ARUS_KAS' => 'Laporan Arus Kas',
    'NERACA_SALDO' => 'Neraca Saldo / Trial Balance',
    'BUKU_BESAR' => 'Buku Besar Rincian',
    'BUKU_KAS' => 'Rekap Buku Kas & Bank',
    'VOUCHER' => 'Bukti Transaksi Kas (Voucher)',
    'LAPORAN_GAJI' => 'Laporan Rekapitulasi Gaji',
    'SLIP_GAJI' => 'Slip Gaji Pegawai',
    'INVOICE_MHS' => 'Invoice & Kuitansi Mahasiswa',
    'KARTU_PIUTANG' => 'Kartu Histori Piutang Mahasiswa',
    'HISTORY_MHS' => 'Cetak Histori Transaksi Mahasiswa',
    'MONITORING_PIUTANG' => 'Ekspor Excel: Monitoring Piutang',
    'EXCEL_GLOBAL' => 'Ekspor Excel: Laporan Global Lainnya'
];

// GROUPING DATA UNTUK TAMPILAN
$sigs = $conn->query("SELECT * FROM system_signatures ORDER BY doc_type ASC, id ASC");
$grouped_sigs = [];
if($sigs) {
    while($r = $sigs->fetch_assoc()) {
        $grouped_sigs[$r['doc_type']][] = $r;
    }
}
?>

<div class="animate__animated animate__fadeIn">

    <div class="alert alert-info border-0 bg-info bg-opacity-10 text-dark shadow-sm rounded-4 mb-4 p-4">
        <h6 class="fw-bold mb-2"><i class="fas fa-lightbulb me-2 text-info"></i>Cara Kerja Modul Tanda Tangan:</h6>
        <p class="mb-0 small">Anda dapat menambah berapapun jumlah pejabat penandatangan untuk <b>setiap jenis dokumen</b>. Sistem cetak secara otomatis akan mendeteksi berapa jumlah yang Anda buat (misal 2 atau 4) dan akan membagi kolom tabel secara proporsional. Ketik tujuan penandatangan (contoh: "Diperiksa Oleh") sesuai kebutuhan Anda. <br><br><b>Tips:</b> Khusus untuk <i>Slip Kuitansi Honorarium Dosen</i>, jika Anda membuat peran "Penerima", sistem akan otomatis mengganti nama penerima sesuai dengan nama Dosen di dokumen cetak.</p>
    </div>

    <div class="row g-4">
        <?php if(!empty($grouped_sigs)): foreach($grouped_sigs as $dt => $list): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden h-100">
                    <div class="card-header bg-light p-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold text-primary mb-0" style="font-size: 13px;"><i class="fas fa-file-alt me-2"></i><?= $doc_types[$dt] ?? $dt ?></h6>
                        <span class="badge bg-white text-muted border"><?= count($list) ?> TTD</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center">
                            <thead class="table-dark small text-uppercase" style="font-size: 10px;">
                                <tr><th class="ps-3 text-start">Tujuan / Posisi</th><th class="text-start">Nama & Jabatan Tercetak</th><th class="pe-3 text-end">Aksi</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($list as $s): ?>
                                    <tr>
                                        <td class="ps-3 text-start"><span class="badge bg-info bg-opacity-10 text-info border px-2 py-1"><?= strtoupper($s['sign_role']) ?></span></td>
                                        <td class="text-start">
                                            <div class="fw-bold text-dark" style="font-size: 12px;"><?= $s['sign_name'] ?: '<i class="text-muted small">Kosong</i>' ?></div>
                                            <div class="small text-muted" style="font-size: 11px;"><?= $s['sign_position'] ?></div>
                                        </td>
                                        <td class="pe-3 text-end">
                                            <div class="btn-group btn-group-sm rounded-pill border shadow-sm bg-white overflow-hidden">
                                                <button class="btn btn-white text-warning border-end" onclick='modalAddSig(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button>
                                                <button class="btn btn-white text-danger" onclick="delSig(<?= $s['id'] ?>)"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; else: ?>
            <div class="col-12 text-center py-5">
                <i class="fas fa-file-signature fa-4x text-muted opacity-25 mb-3"></i>
                <h5 class="fw-bold text-dark">Belum ada pengaturan tanda tangan.</h5>
                <p class="text-muted">Klik tombol "Tambah TTD Baru" di sudut kanan atas untuk mulai mengatur otorisator dokumen.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL FORM TTD SPESIFIK -->
<div class="modal fade" id="modalSig" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered text-dark">
        <form action="settings.php" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" name="action" value="save_signature">
            <input type="hidden" name="id" id="sig_id">
            <div class="modal-header bg-primary text-white p-4 border-0">
                <h5 class="modal-title fw-bold" id="sigTitle"><i class="fas fa-pen-nib me-2"></i>Form Tanda Tangan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Pilih Dokumen / Laporan</label>
                    <select name="doc_type" id="sig_doc" class="form-select border-0 shadow-sm rounded-pill px-3 fw-bold text-primary" required>
                        <option value="">-- Pilih Jenis Laporan / Dokumen --</option>
                        <?php foreach($doc_types as $k => $v) echo "<option value='$k'>$v</option>"; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Tujuan Tanda Tangan (Ketik Manual)</label>
                    <input type="text" name="sign_role" id="sig_role" class="form-control border-0 shadow-sm rounded-pill px-3 fw-bold text-dark" placeholder="Contoh: Dibuat Oleh, Disetujui Oleh" required>
                </div>
                <div class="mb-3">
                    <label class="small fw-bold text-muted mb-1 uppercase">Nama Lengkap & Gelar</label>
                    <input type="text" name="sign_name" id="sig_name" class="form-control border-0 shadow-sm rounded-pill px-3" placeholder="Contoh: Budi Santoso, S.Kom">
                </div>
                <div class="mb-0">
                    <label class="small fw-bold text-muted mb-1 uppercase">Jabatan Tercetak</label>
                    <input type="text" name="sign_position" id="sig_pos" class="form-control border-0 shadow-sm rounded-pill px-3" placeholder="Contoh: Kepala BAUK / Rektor">
                </div>
            </div>
            <div class="modal-footer border-0 p-4 bg-white text-center d-block">
                <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow">SIMPAN PENGATURAN TANDA TANGAN</button>
            </div>
        </form>
    </div>
</div>

<!-- FORM HIDDEN UNTUK DELETE -->
<form id="formDelSig" action="settings.php" method="POST" class="d-none">
    <input type="hidden" name="action" value="delete_signature">
    <input type="hidden" name="id" id="del_sig_id">
</form>

<script>
function modalAddSig(data = null) {
    const m = new bootstrap.Modal(document.getElementById('modalSig'));
    document.getElementById('sigTitle').innerHTML = data ? '<i class="fas fa-edit me-2"></i>Ubah Tanda Tangan' : '<i class="fas fa-plus me-2"></i>Tambah Tanda Tangan';
    
    // Set Value
    document.getElementById('sig_id').value = data ? data.id : '';
    document.getElementById('sig_doc').value = data ? data.doc_type : '';
    document.getElementById('sig_role').value = data ? data.sign_role : '';
    document.getElementById('sig_name').value = data ? data.sign_name : '';
    document.getElementById('sig_pos').value = data ? data.sign_position : '';
    
    // Buka kunci doc_type agar user juga bisa mengedit / memindahkan dokumennya jika perlu
    document.getElementById('sig_doc').style.pointerEvents = 'auto';
    document.getElementById('sig_doc').classList.remove('bg-secondary', 'bg-opacity-10');
    
    m.show();
}

function delSig(id) {
    if(confirm('Hapus pengaturan tanda tangan ini secara permanen?')) {
        document.getElementById('del_sig_id').value = id;
        document.getElementById('formDelSig').submit();
    }
}
</script>