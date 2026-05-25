<?php
/**
 * report_classifier.php - ISAK 35 AUTO CLASSIFICATION ENGINE
 * Deskripsi: Mengklasifikasikan akun ke laporan keuangan yang tepat secara otomatis.
 */

function classifyAccountToReport($akun) {
    // Membaca "Otak" COA dari kolom report_group
    switch($akun['report_group']) {

        case 'POSISI_KEUANGAN':
            return 'laporan_posisi_keuangan';

        case 'AKTIVITAS':
            return 'laporan_aktivitas';

        case 'ASET_NETO_TANPA_RESTRIKSI':
            return 'aset_neto_tanpa';

        case 'ASET_NETO_DENGAN_RESTRIKSI':
            return 'aset_neto_dengan';

        default:
            return 'unclassified';
    }
}
?>