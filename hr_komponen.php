<?php
/**
 * hr_komponen.php - MASTER KOMPONEN GAJI SYIFA
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$komponen = $conn->query("SELECT * FROM hr_komponen ORDER BY jenis DESC, nama_komponen ASC")->fetch_all(MYSQLI_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold text-dark mb-0">Daftar Komponen Pendapatan & Potongan</h6>
    <button class="btn btn-danger btn-sm rounded-pill px-3 shadow-sm fw-bold" onclick="showModalKomponen()">+ TAMBAH KOMPONEN</button>
</div>

<div class="table-responsive border rounded-3">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light small text-uppercase fw-bold">
            <tr>
                <th class="ps-3">Nama Komponen</th>
                <th>Kategori</th>
                <th>Sifat</th>
                <th>Akun COA</th>
                <th class="text-center">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if(!empty($komponen)): foreach($komponen as $k): ?>
            <tr>
                <td class="ps-3 fw-bold text-dark"><?= $k['nama_komponen'] ?></td>
                <td><span class="badge bg-<?= $k['jenis']=='Pendapatan'?'success':'danger' ?>-subtle text-<?= $k['jenis']=='Pendapatan'?'success':'danger' ?> px-3"><?= $k['jenis'] ?></span></td>
                <td class="small"><?= $k['sifat'] ?></td>
                <td><code><?= $k['kode_akun'] ?></code></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-link text-warning p-0 me-2" onclick='editKomponen(<?= json_encode($k) ?>)'><i class="fas fa-edit"></i></button>
                    <button class="btn btn-sm btn-link text-danger p-0" onclick="confirmDeleteKom(<?= $k['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center py-4 text-muted small italic">Data komponen kosong.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>