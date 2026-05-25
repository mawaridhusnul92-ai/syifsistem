<?php
/**
 * journal_list.php - MODUL TABEL REKAP JURNAL
 * Fitur: Detail View, Filter, & Action Management
 */
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

// Fetch Data Journals
$sql = "SELECT j.*, 
        (SELECT SUM(debit) FROM journal_items WHERE journal_id = j.id) as total_debit
        FROM journals j 
        ORDER BY j.journal_date DESC, j.id DESC LIMIT 100";
$journals = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Daftar Jurnal - SYIFA ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style_syifa.css">
</head>
<body class="bg-light">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <?php include 'header_nav.php'; ?>
        <div class="p-4">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-dark mb-0">Rekap Jurnal Umum</h3>
                <a href="journal_entry.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow">
                    <i class="fas fa-plus me-2"></i> Buat Jurnal Baru
                </a>
            </div>

            <?php if(isset($_SESSION['flash'])): ?>
                <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show rounded-4 mb-4 shadow-sm">
                    <?= $_SESSION['flash']['msg'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr class="small">
                                <th class="ps-4">TANGGAL</th>
                                <th>NO REFERENSI</th>
                                <th>KETERANGAN</th>
                                <th>JENIS</th>
                                <th class="text-end">TOTAL DEBIT</th>
                                <th class="text-end">TOTAL KREDIT</th>
                                <th class="text-center pe-4">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $journals->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4"><?= date('d/m/Y', strtotime($row['journal_date'])) ?></td>
                                <td><span class="badge bg-light text-dark border fw-normal"><?= $row['ref_no'] ?></span></td>
                                <td class="small"><?= $row['description'] ?></td>
                                <td>
                                    <span class="badge <?= $row['journal_type']=='GENERAL'?'bg-primary':'bg-warning text-dark' ?> rounded-pill">
                                        <?= $row['journal_type'] ?>
                                    </span>
                                </td>
                                <td class="text-end fw-bold text-primary">Rp <?= number_format($row['total_debit'], 0, ',', '.') ?></td>
                                <td class="text-end fw-bold text-danger">Rp <?= number_format($row['total_debit'], 0, ',', '.') ?></td>
                                <td class="text-center pe-4">
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-info border-0" onclick="viewDetail(<?= $row['id'] ?>)" title="Detail"><i class="fas fa-eye"></i></button>
                                        <?php if(!$row['is_posted']): ?>
                                            <a href="journal_edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning border-0" title="Edit"><i class="fas fa-edit"></i></a>
                                            <button class="btn btn-sm btn-outline-danger border-0" onclick="confirmDelete(<?= $row['id'] ?>)" title="Hapus"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DETAIL -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom p-4">
                    <h5 class="fw-bold mb-0">Rincian Jurnal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="detailContent">
                    <!-- Loaded via AJAX -->
                </div>
                <div class="modal-footer bg-light border-0">
                    <button class="btn btn-outline-primary rounded-pill px-4 fw-bold" onclick="window.print()"><i class="fas fa-print me-2"></i> Cetak</button>
                    <button class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <form id="deleteForm" method="POST" action="journal_delete.php">
        <input type="hidden" name="id" id="deleteId">
    </form>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewDetail(id) {
            $('#detailContent').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
            new bootstrap.Modal(document.getElementById('modalDetail')).show();
            
            $.get('journal_entry.php?ajax_action=get_trx_detail&id='+id, function(res) {
                // Logic render detail voucher (Sama seperti voucher mutasi bank sebelumnya)
                let items = '';
                res.details.forEach(d => {
                    items += `<tr><td>${d.coa_id}</td><td>${d.nama_akun}</td><td class="text-end">${formatRupiah(d.debit)}</td><td class="text-end">${formatRupiah(d.credit)}</td></tr>`;
                });
                $('#detailContent').html(`
                    <div class="row mb-3"><div class="col-6">Ref: <b>${res.header.ref_no}</b></div><div class="col-6 text-end">Tgl: <b>${res.header.journal_date}</b></div></div>
                    <table class="table table-bordered"><thead><tr class="table-light"><th>Kode</th><th>Akun</th><th class="text-end">Debit</th><th class="text-end">Kredit</th></tr></thead><tbody>${items}</tbody></table>
                    <p>Keterangan: <i>${res.header.description}</i></p>
                `);
            });
        }

        function formatRupiah(num) { return new Intl.NumberFormat('id-ID').format(num); }

        function confirmDelete(id) {
            if(confirm('Hapus jurnal ini? Seluruh baris transaksi akan hilang permanen.')) {
                $('#deleteId').val(id);
                $('#deleteForm').submit();
            }
        }
    </script>
</body>
</html>