<?php
/**
 * hr_jabatan.php - MASTER JABATAN & GOLONGAN SYIFA
 */
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$jabatan = $conn->query("SELECT * FROM hr_jabatan ORDER BY level_jabatan ASC, nama_jabatan ASC")->fetch_all(MYSQLI_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold text-dark mb-0">Struktur Jabatan & Golongan</h6>
    <button class="btn btn-danger btn-sm rounded-pill px-3 shadow-sm fw-bold" onclick="showModalJabatan()">+ TAMBAH JABATAN</button>
</div>

<div class="table-responsive border rounded-3">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light small text-uppercase fw-bold">
            <tr>
                <th class="ps-3">Nama Jabatan</th>
                <th>Golongan</th>
                <th class="text-end">Gapok Default</th>
                <th class="text-end">Tunjangan</th>
                <th class="text-center">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if(!empty($jabatan)): foreach($jabatan as $j): ?>
            <tr>
                <td class="ps-3 fw-bold text-dark"><?= strtoupper($j['nama_jabatan']) ?></td>
                <td><span class="badge bg-light text-dark border px-3"><?= $j['golongan'] ?></span></td>
                <td class="text-end fw-bold">Rp <?= number_format($j['gaji_pokok_default']) ?></td>
                <td class="text-end text-success fw-bold">Rp <?= number_format($j['tunjangan_jabatan_default']) ?></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-link text-warning p-0 me-2" onclick='editJabatan(<?= json_encode($j) ?>)'><i class="fas fa-edit"></i></button>
                    <button class="btn btn-sm btn-link text-danger p-0" onclick="confirmDeleteJab(<?= $j['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                </td>
            </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="text-center py-4 text-muted small italic">Data jabatan kosong.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Jabatan tetap dipanggil dari hr_payroll_setup.php -->