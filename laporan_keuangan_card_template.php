<?php
/**
 * laporan_keuangan_card_template.php - TEMPLATE VISUAL KARTU LAPORAN
 * Versi: 46.0 (Sovereign Elegant Edition)
 * Perbaikan: Penghapusan label "Independent Hub" & Redesain Tombol Aksi.
 */
?>
<div class="col-md-6 col-lg-3 mb-2 animate__animated animate__fadeInUp">
    <a href="index.php?page=<?= $m['link'] ?>" class="report-card shadow-sm h-100">
        <!-- Area Icon -->
        <div class="report-icon <?= $m['bg'] ?> text-white shadow-sm">
            <i class="fas <?= $m['icon'] ?> fa-lg"></i>
        </div>
        
        <!-- Konten Teks -->
        <h6 class="card-title"><?= $m['title'] ?></h6>
        <p class="card-desc mb-0"><?= $m['desc'] ?></p>
        
        <!-- Tombol Aksi Elegan -->
        <div class="btn-open-module mt-auto">
            BUKA LAPORAN <i class="fas fa-chevron-right ms-2"></i>
        </div>
    </a>
</div>