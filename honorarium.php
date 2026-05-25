<?php
/**
 * honorarium.php - MASTER HUB MANAJEMEN HONORARIUM
 * Modul Standalone: SYIFA System - STIKes Yarsi Pontianak
 * Perbaikan: Urutan tab disesuaikan (Database -> Komponen -> Setting -> Generate -> Slip -> Laporan).
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn)) { require_once 'config/koneksi.php'; }

$active_tab = $_GET['tab'] ?? 'database';

// Mapping Tab yang valid sesuai urutan baru
$allowed_tabs = ['database', 'komponen', 'setting_form', 'generate', 'slip', 'laporan'];
if (!in_array($active_tab, $allowed_tabs)) { $active_tab = 'database'; }
?>

<!-- 🚀 INJEKSI SWEETALERT2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .nav-tabs-syifa { border-bottom: 2px solid #e2e8f0; gap: 5px; }
    .nav-tabs-syifa .nav-link { border: none; color: #64748b; font-weight: 700; padding: 15px 20px; border-radius: 12px 12px 0 0; transition: 0.3s; font-size: 13px; }
    .nav-tabs-syifa .nav-link:hover { color: var(--bs-primary); background: rgba(13, 110, 253, 0.05); }
    .nav-tabs-syifa .nav-link.active { color: var(--bs-primary) !important; background: #fff; border-bottom: 4px solid var(--bs-primary) !important; }
    .nav-tabs-syifa .nav-link i { margin-right: 8px; font-size: 14px; }
    .nav-tabs-syifa .nav-link.active i { color: var(--bs-primary); }

    .metric-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: none; display: flex; align-items: center; transition: 0.3s; }
    .metric-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    .metric-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-right: 15px; }
    .metric-title { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
    .metric-value { font-size: 24px; font-weight: 900; line-height: 1; }

    .badge-aktif { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .badge-nonaktif { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .badge-draft { background-color: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }
</style>

<div class="container-fluid py-4 text-dark animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded-4 shadow-sm border-start border-primary border-4">
        <div>
            <h4 class="fw-bold mb-1 text-primary"><i class="fas fa-chalkboard-teacher me-2 text-warning"></i>Manajemen Honorarium</h4>
            <p class="text-muted small mb-0 fw-bold">Pusat Database Dosen, Komponen Biaya, Generate, Slip, dan Laporan Honor.</p>
        </div>
    </div>

    <!-- URUTAN TAB TELAH DIPERBAIKI -->
    <ul class="nav nav-tabs nav-tabs-syifa mb-4">
        <li class="nav-item"><a class="nav-link <?= $active_tab=='database'?'active':'' ?>" href="?page=honorarium&tab=database"><i class="fas fa-users-cog"></i> Database Dosen</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='komponen'?'active':'' ?>" href="?page=honorarium&tab=komponen"><i class="fas fa-layer-group"></i> Komponen Tarif</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='setting_form'?'active':'' ?>" href="?page=honorarium&tab=setting_form"><i class="fas fa-table"></i> Setting Form Layout</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='generate'?'active':'' ?>" href="?page=honorarium&tab=generate"><i class="fas fa-cogs"></i> Susun Honor</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='slip'?'active':'' ?>" href="?page=honorarium&tab=slip"><i class="fas fa-receipt"></i> Slip & Pembayaran</a></li>
        <li class="nav-item"><a class="nav-link <?= $active_tab=='laporan'?'active':'' ?>" href="?page=honorarium&tab=laporan"><i class="fas fa-chart-bar"></i> Laporan</a></li>
    </ul>

    <div class="tab-content">
        <?php 
            if ($active_tab == 'database') {
                if(file_exists('honorarium_database_dosen.php')) include 'honorarium_database_dosen.php';
            } elseif ($active_tab == 'komponen') {
                if(file_exists('honorarium_komponen.php')) include 'honorarium_komponen.php';
            } elseif ($active_tab == 'setting_form') {
                if(file_exists('honorarium_setting_form.php')) include 'honorarium_setting_form.php';
            } elseif ($active_tab == 'generate') {
                if(file_exists('honorarium_generate.php')) include 'honorarium_generate.php';
            } elseif ($active_tab == 'slip') {
                if(file_exists('honorarium_slip.php')) include 'honorarium_slip.php';
            } elseif ($active_tab == 'laporan') {
                if(file_exists('honorarium_laporan.php')) include 'honorarium_laporan.php';
            } else {
                echo "<div class='alert bg-white p-5 text-center shadow-sm rounded-4 border'><i class='fas fa-tools fa-3x text-muted opacity-25 mb-3 d-block'></i><h5 class='fw-bold text-dark'>Modul Sedang Dalam Pengembangan</h5></div>";
            }
        ?>
    </div>
</div>