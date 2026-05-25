<?php
/**
 * anggaran_unit_validasi.php - MEJA KERJA VALIDASI CHECKER
 * Versi: 126.0 (Sovereign Grand Master - Locked UI Buttons by Role Matrix)
 */

$sql_menunggu = "SELECT r.*, u.nama_unit 
                 FROM anggaran_unit_reports r 
                 JOIN m_unit u ON r.unit_id = u.id 
                 WHERE r.status = 'MENUNGGU' 
                 ORDER BY r.submitted_at DESC";
$res_menunggu = $conn->query($sql_menunggu);
?>

<div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
    <div class="card-header bg-white p-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-tasks me-2 text-warning"></i>Antrean Validasi Laporan Unit</h5>
            <small class="text-muted">Periksa bukti dan laporan sebelum memasukkannya ke Arsip Permanen.</small>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 text-center">
            <thead class="bg-dark text-white small text-uppercase">
                <tr>
                    <th width="150">Waktu Kirim</th>
                    <th class="text-start ps-4">Unit Kerja</th>
                    <th class="text-start">Nama Laporan / Bukti</th>
                    <th>Periode Laporan</th>
                    <th class="text-end">Saldo Akhir</th>
                    <th width="120" class="pe-4 text-center">Aksi Checker</th>
                </tr>
            </thead>
            <tbody>
                <?php if($res_menunggu && $res_menunggu->num_rows > 0): while($m = $res_menunggu->fetch_assoc()): 
                    $p_awal = $m['periode_awal'] ?? $m['tgl_mulai'] ?? date('Y-m-01');
                    $p_akhir = $m['periode_akhir'] ?? $m['tgl_selesai'] ?? date('Y-m-d');
                ?>
                <tr>
                    <td class="text-muted small"><?= date('d/m/y H:i', strtotime($m['submitted_at'])) ?></td>
                    <td class="text-start ps-4 fw-bold text-primary"><?= htmlspecialchars($m['nama_unit']) ?></td>
                    <td class="text-start">
                        <div class="fw-bold text-dark"><?= htmlspecialchars($m['nama_laporan']) ?></div>
                    </td>
                    <td class="small text-dark"><?= date('d/m/y', strtotime($p_awal)) ?> - <?= date('d/m/y', strtotime($p_akhir)) ?></td>
                    <td class="text-end fw-bold text-dark">Rp <?= number_format($m['saldo_akhir'] ?? 0) ?></td>
                    <td class="pe-4 text-center">
                        <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                            <button class="btn btn-sm btn-warning rounded-pill px-4 fw-bold shadow-sm" onclick='openValidasiModal(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)'>TINJAU</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-light text-muted rounded-pill px-4 fw-bold shadow-none border" disabled><i class="fas fa-eye me-1"></i> LIHAT</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr>
                    <td colspan="6" class="py-5 text-center text-muted italic">
                        <i class="fas fa-check-double fa-3x opacity-25 mb-3 d-block text-success"></i>
                        Semua laporan telah divalidasi. Tidak ada antrean.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL VALIDASI & KEPUTUSAN -->
<div class="modal fade" id="mdlValidasi" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white p-4 border-0">
                <h5 class="modal-title fw-bold">Validasi Laporan Unit</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="bg-white p-3 rounded-3 shadow-sm border mb-4">
                    <small class="text-muted fw-bold d-block uppercase" style="font-size:10px;">Laporan</small>
                    <div class="fw-bold text-primary mb-2" id="val_nama_laporan">-</div>
                    <div class="row">
                        <div class="col-6"><small class="text-muted fw-bold uppercase" style="font-size:10px;">Unit Kerja</small><div class="fw-bold text-dark" id="val_unit">-</div></div>
                        <div class="col-6 text-end"><small class="text-muted fw-bold uppercase" style="font-size:10px;">Saldo Akhir</small><div class="fw-bold text-dark" id="val_saldo">-</div></div>
                    </div>
                </div>

                <div class="mb-4">
                    <a href="#" target="_blank" id="btnLihatLaporanSistem" class="btn btn-dark rounded-pill px-4 shadow-sm w-100 fw-bold mb-2">
                        <i class="fas fa-file-invoice-dollar me-2"></i>Buka Laporan Jurnal Sistem
                    </a>
                    
                    <a href="#" target="_blank" id="btnLihatLampiran" class="btn btn-outline-danger rounded-pill px-4 shadow-sm w-100 fw-bold">
                        <i class="fas fa-paperclip me-2"></i>Lihat Lampiran Fisik (Bukti Upload)
                    </a>
                    
                    <small class="text-muted d-block mt-2 italic text-center">Pastikan lampiran fisik sesuai dengan laporan jurnal sistem.</small>
                </div>

                <?php if(defined('RBAC_EDIT') && RBAC_EDIT): ?>
                <ul class="nav nav-pills mb-3 nav-fill" id="pills-tab" role="tablist">
                    <li class="nav-item"><button class="nav-link active rounded-pill fw-bold" data-bs-toggle="pill" data-bs-target="#pill-approve">SETUJUI</button></li>
                    <li class="nav-item"><button class="nav-link rounded-pill fw-bold text-danger" data-bs-toggle="pill" data-bs-target="#pill-reject">KEMBALIKAN (REVISI)</button></li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pill-approve">
                        <form action="budget_unit_action.php" method="POST">
                            <input type="hidden" name="action" value="approve_validasi">
                            <input type="hidden" name="report_id" id="app_report_id">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-1">Judul Dokumen di Arsip Pusat</label>
                                <input type="text" name="judul_arsip" id="app_judul" class="form-control rounded-pill border-0 shadow-sm px-3" required>
                            </div>
                            <button type="submit" class="btn btn-success w-100 rounded-pill py-3 fw-bold shadow">SETUJUI & MASUKKAN KE ARSIP</button>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="pill-reject">
                        <form action="budget_unit_action.php" method="POST">
                            <input type="hidden" name="action" value="revisi_validasi">
                            <input type="hidden" name="report_id" id="rev_report_id">
                            <div class="mb-3">
                                <label class="small fw-bold text-danger mb-1"><i class="fas fa-edit me-1"></i>Pesan Revisi (Wajib)</label>
                                <textarea name="catatan" class="form-control border-0 shadow-sm rounded-4 px-3 py-2" rows="3" placeholder="Sebutkan kesalahan yang harus diperbaiki unit..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger w-100 rounded-pill py-3 fw-bold shadow">KEMBALIKAN KE UNIT</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function openValidasiModal(d) {
    const modalEl = document.getElementById('mdlValidasi');
    if (modalEl.parentNode !== document.body) {
        document.body.appendChild(modalEl);
    }

    const reportIdEl = document.getElementById('app_report_id');
    if(reportIdEl) reportIdEl.value = d.id;
    
    const revReportIdEl = document.getElementById('rev_report_id');
    if(revReportIdEl) revReportIdEl.value = d.id;
    
    const namaLap = d.nama_laporan || 'Laporan Umum';
    const namaUnit = d.nama_unit || 'Unit';
    document.getElementById('val_nama_laporan').innerText = namaLap;
    document.getElementById('val_unit').innerText = namaUnit;
    
    const appJudulEl = document.getElementById('app_judul');
    if(appJudulEl) appJudulEl.value = namaLap + " - " + namaUnit;
    
    let saldo = parseFloat(d.saldo_akhir);
    if (isNaN(saldo)) saldo = 0;
    document.getElementById('val_saldo').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(saldo);
    
    document.getElementById('btnLihatLaporanSistem').href = 'laporan_anggaran_unit.php?id=' + d.id;

    const btnLampiran = document.getElementById('btnLihatLampiran');
    if(d.file_bukti && d.file_bukti.trim() !== '') {
        let path = d.file_bukti;
        if(!path.includes('uploads/')) {
            path = 'uploads/laporan_unit/' + path;
        }
        btnLampiran.href = path;
        btnLampiran.classList.remove('btn-secondary', 'disabled');
        btnLampiran.classList.add('btn-outline-danger');
        btnLampiran.innerHTML = '<i class="fas fa-paperclip me-2"></i>Lihat Lampiran Fisik (Bukti Upload)';
    } else {
        btnLampiran.href = '#';
        btnLampiran.classList.remove('btn-outline-danger');
        btnLampiran.classList.add('btn-secondary', 'disabled');
        btnLampiran.innerHTML = '<i class="fas fa-times-circle me-2"></i>Tidak Ada Lampiran Upload';
    }
    
    const myModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    myModal.show();
}
</script>