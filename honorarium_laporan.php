<?php
/**
 * honorarium_laporan.php - TAB 5: LAPORAN HONORARIUM DOSEN
 * Perbaikan: Menambahkan section khusus untuk Dokumen PENGAJUAN
 * Sesuai sinkronisasi Template Cetak Laporan (print_honor.php)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$laporan_data = [];
$tot_bayar = 0; $tot_pajak = 0;

// Tarik data laporan asli
$sql = "SELECT d.*, g.nama_generate, g.periode_bulan, g.periode_tahun, ds.nama as dosen_nama, ds.nip, ds.jabatan_fungsional, ds.program_studi, ds.nama_bank, ds.no_rekening
        FROM honor_generate_detail d 
        JOIN honor_generate g ON d.generate_id = g.id 
        JOIN dosen ds ON d.dosen_id = ds.id 
        WHERE g.status IN ('Final', 'Dibayarkan') ORDER BY g.id DESC";
$res = $conn->query($sql);

if($res) {
    $no = 1;
    while($r = $res->fetch_assoc()) {
        $laporan_data[] = [
            'no' => $no++,
            'nip' => $r['nip'],
            'nama' => $r['dosen_nama'],
            'jabatan' => $r['jabatan_fungsional'],
            'matkul' => $r['mata_kuliah'] ?: '-',
            'prodi' => $r['program_studi'],
            'bruto' => (double)$r['total_honor'],
            'pajak' => (double)$r['potongan_pajak'],
            'netto' => (double)$r['honor_diterima'],
            'rek' => $r['nama_bank'] . ' ' . $r['no_rekening'],
            'status' => $r['status_bayar']
        ];
        $tot_bayar += (double)$r['honor_diterima'];
        $tot_pajak += (double)$r['potongan_pajak'];
    }
}

// 🚀 TARIK DATA KHUSUS PENGAJUAN HONOR
$pengajuan_list = [];
$sql_pengajuan = "SELECT g.*, t.nama_template 
                  FROM honor_generate g 
                  JOIN honor_template t ON g.template_id = t.id 
                  WHERE g.status IN ('Final', 'Dibayarkan') AND t.jenis_tujuan = 'PENGAJUAN' 
                  ORDER BY g.id DESC";
$res_peng = $conn->query($sql_pengajuan);
if($res_peng) while($r = $res_peng->fetch_assoc()) $pengajuan_list[] = $r;

$tot_dosen = count($laporan_data);
$tot_gen = $conn->query("SELECT COUNT(id) FROM honor_generate WHERE status IN ('Final', 'Dibayarkan')")->fetch_row()[0] ?? 0;
?>

<style>
    @media print {
        body * { visibility: hidden; }
        #printReportArea, #printReportArea * { visibility: visible; }
        #printReportArea { position: absolute; left: 0; top: 0; width: 100%; border: none !important; box-shadow: none !important; padding: 0 !important; }
        .no-print { display: none !important; }
        @page { size: A4 landscape; margin: 15mm; }
        
        .print-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .print-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .print-table th, .print-table td { border: 1px solid #000; padding: 5px; color: #000 !important; }
        .print-table th { background-color: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<div class="animate__animated animate__fadeIn text-dark">
    <!-- DASHBOARD MINI -->
    <div class="row g-3 mb-4 no-print text-start">
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background:#dcfce7; color:#166534;"><i class="fas fa-money-bill-wave"></i></div><div><div class="metric-title">Total Dibayarkan</div><div class="metric-value text-success fs-5">Rp <?= number_format($tot_bayar,0,',','.') ?></div></div></div></div>
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background:#e0f2fe; color:#1e40af;"><i class="fas fa-users"></i></div><div><div class="metric-title">Penerima Honor</div><div class="metric-value text-primary fs-5"><?= $tot_dosen ?> Transaksi</div></div></div></div>
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background:#f3e8ff; color:#6d28d9;"><i class="fas fa-layer-group"></i></div><div><div class="metric-title">Generate Selesai</div><div class="metric-value" style="color:var(--bs-primary);"><?= $tot_gen ?> Berkas</div></div></div></div>
        <div class="col-md-3"><div class="metric-card border"><div class="metric-icon" style="background:#ffedd5; color:#c2410c;"><i class="fas fa-percent"></i></div><div><div class="metric-title">Potongan Pajak</div><div class="metric-value text-warning fs-5">Rp <?= number_format($tot_pajak,0,',','.') ?></div></div></div></div>
    </div>

    <!-- 🚀 DOKUMEN PENGAJUAN MUNCUL DISINI -->
    <div class="card border rounded-4 shadow-sm bg-white mb-4 border-start border-success border-4 no-print">
        <div class="card-body p-4 text-start">
            <h5 class="fw-bold text-success mb-3"><i class="fas fa-file-signature me-2"></i>Arsip Dokumen Pengajuan (Rekap) Honor</h5>
            <div class="table-responsive">
                <table class="table table-hover text-center align-middle mb-0">
                    <thead class="table-light"><tr><th>No</th><th class="text-start">Nama Batch / Dokumen Pengajuan</th><th>Periode</th><th>Jenis Template</th><th>Total Pengajuan (Netto)</th><th>Aksi Cetak</th></tr></thead>
                    <tbody>
                        <?php if(empty($pengajuan_list)): ?><tr><td colspan="6" class="text-muted fst-italic py-4">Belum ada dokumen yang disimpan sebagai PENGAJUAN (Rekap Gabungan).</td></tr><?php endif; ?>
                        <?php $n=1; foreach($pengajuan_list as $p): ?>
                        <tr>
                            <td class="fw-bold"><?= $n++ ?></td>
                            <td class="text-start fw-bold text-primary"><?= $p['nama_generate'] ?></td>
                            <td><?= date('M Y', strtotime($p['periode_tahun'].'-'.$p['periode_bulan'].'-01')) ?></td>
                            <td><span class="badge bg-light text-dark border"><i class="fas fa-table text-success me-1"></i><?= $p['nama_template'] ?></span></td>
                            <td class="fw-bold text-success">Rp <?= number_format($p['total_honor'],0,',','.') ?></td>
                            <td><button class="btn btn-sm btn-info text-white shadow-sm fw-bold rounded-pill px-3" onclick="window.open('print_honor.php?mode=pengajuan&gen_id=<?= $p['id'] ?>', '_blank')"><i class="fas fa-print me-1"></i> Cetak Rekap PDF</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PREVIEW LAPORAN KESELURUHAN (AREA PRINT) -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h5 class="fw-bold text-dark m-0"><i class="fas fa-list-alt me-2 text-primary"></i>Laporan Rincian Keseluruhan</h5>
        <div>
            <button type="button" class="btn btn-dark fw-bold rounded-pill shadow-sm" onclick="window.print()"><i class="fas fa-print me-2"></i>Cetak Tabel Rincian</button>
            <button type="button" class="btn btn-success fw-bold rounded-pill shadow-sm text-white ms-2" onclick="exportToExcel()"><i class="fas fa-file-excel me-2"></i>Export Excel</button>
        </div>
    </div>

    <div class="card border rounded-0 shadow-sm bg-white p-5 text-dark" id="printReportArea">
        
        <div class="print-header">
            <h4 class="fw-bold mb-0">STIKES YARSI PONTIANAK</h4>
            <div class="small">Jl. Panglima A'im No. 2, Pontianak, Kalimantan Barat</div>
            <hr style="border: 1px solid #000;">
            <h5 class="fw-bold mt-3 mb-1 text-uppercase">LAPORAN HONORARIUM DOSEN</h5>
            <div class="small fw-bold">Periode Laporan : <?= date('F Y') ?></div>
            <div class="small fw-bold">Tanggal Cetak : <?= date('d M Y') ?></div>
        </div>

        <table class="table table-bordered border-dark print-table text-center align-middle mb-4" id="laporanTable" style="font-size: 12px;">
            <thead class="table-light">
                <tr>
                    <th>No</th><th>NIP</th><th class="text-start">Nama Dosen</th><th>Jabatan</th>
                    <th>Mata Kuliah</th><th>Prodi</th><th>Honor Bruto</th><th>Pajak</th>
                    <th>Honor Diterima</th><th>No. Rekening</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($laporan_data)): ?><tr><td colspan="11" class="text-center py-5 italic text-muted">Data kosong.</td></tr><?php endif; ?>
                <?php foreach($laporan_data as $l): ?>
                <tr>
                    <td><?= $l['no'] ?></td>
                    <td><?= htmlspecialchars($l['nip']) ?></td>
                    <td class="text-start fw-bold"><?= htmlspecialchars($l['nama']) ?></td>
                    <td><?= htmlspecialchars($l['jabatan']) ?></td>
                    <td><?= htmlspecialchars($l['matkul']) ?></td>
                    <td><?= htmlspecialchars($l['prodi']) ?></td>
                    <td class="text-end text-dark"><?= number_format($l['bruto'],0,',','.') ?></td>
                    <td class="text-end text-danger"><?= number_format($l['pajak'],0,',','.') ?></td>
                    <td class="text-end fw-bold text-success"><?= number_format($l['netto'],0,',','.') ?></td>
                    <td class="small"><?= htmlspecialchars($l['rek']) ?></td>
                    <td><?= $l['status'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="row text-center fw-bold text-dark" style="font-size: 13px;">
            <div class="col-8 text-start p-3 border">
                <div>Total Honor Bruto : Rp <?= number_format($tot_bayar + $tot_pajak, 0,',','.') ?></div>
                <div>Total Potongan Pajak : Rp <?= number_format($tot_pajak,0,',','.') ?></div>
                <div class="fs-6 mt-1">Total Honor Diterima : Rp <?= number_format($tot_bayar,0,',','.') ?></div>
                <div class="mt-2 text-muted">Jumlah Penerima : <?= $tot_dosen ?> Transaksi</div>
            </div>
            <div class="col-4">
                Pontianak, <?= date('d F Y') ?><br><br><br><br><br>
                <u>( Bagian Keuangan )</u>
            </div>
        </div>
    </div>
</div>

<script>
    function exportToExcel() {
        let downloadLink;
        let dataType = 'application/vnd.ms-excel';
        let tableSelect = document.getElementById('laporanTable');
        let tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
        let filename = 'Laporan_Honorarium.xls';
        
        downloadLink = document.createElement("a");
        document.body.appendChild(downloadLink);
        
        if(navigator.msSaveOrOpenBlob){
            var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
            navigator.msSaveOrOpenBlob( blob, filename);
        } else {
            downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
            downloadLink.download = filename;
            downloadLink.click();
        }
    }
</script>