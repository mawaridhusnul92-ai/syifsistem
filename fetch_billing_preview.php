<?php
/**
 * fetch_billing_preview.php - AJAX HANDLER FOR BILLING PREVIEW
 * Perbaikan: Struktur kolom rapat dan tag komponen lebih ringkas.
 */
require_once 'config/koneksi.php';

$prodi_id = (int)($_GET['prodi_id'] ?? 0);
$tahun    = $_GET['tahun'] ?? '';
$angkatan = $_GET['angkatan'] ?? '';
$sistem   = $_GET['sistem'] ?? '';

// Ambil Mahasiswa Aktif
$sql_m = "SELECT m.id, m.nim, m.nama, m.angkatan, m.sistem_kuliah 
          FROM syifa_mahasiswa m 
          JOIN mhs_keaktifan_semester k ON m.nim = k.nim 
          WHERE m.prodi_id = $prodi_id AND k.kode_tahun = '$tahun' AND k.status_aktif = 'Aktif'";

if($angkatan) $sql_m .= " AND m.angkatan = '$angkatan'";
if($sistem)   $sql_m .= " AND m.sistem_kuliah = '$sistem'";

$res_m = $conn->query($sql_m);

if($res_m && $res_m->num_rows > 0) {
    while($r = $res_m->fetch_assoc()) {
        // Ambil komponen tarif yang sesuai dengan kriteria mahasiswa ini
        $sql_t = "SELECT nama_tarif, nominal FROM mhs_tarif 
                  WHERE kode_tahun = '$tahun' 
                  AND (prodi_id = $prodi_id OR prodi_id = 0)
                  AND (periode_masuk = '{$r['angkatan']}' OR periode_masuk = '' OR periode_masuk IS NULL)";
        
        $res_t = $conn->query($sql_t);
        $komponen = []; $total = 0;
        while($t = $res_t->fetch_assoc()){
            $komponen[] = "<span class='badge bg-light text-dark border fw-normal badge-komp'>{$t['nama_tarif']}</span>";
            $total += $t['nominal'];
        }
        
        echo "<tr>
                <td class='text-center'><input type='checkbox' name='nims[]' value='{$r['nim']}' class='form-check-input' checked style='width:15px;height:15px;'></td>
                <td class='ps-3 fw-bold'><code>{$r['nim']}</code></td>
                <td>
                    <div class='fw-bold text-truncate' title='{$r['nama']}'>".strtoupper($r['nama'])."</div>
                </td>
                <td class='text-truncate'>
                    ".(empty($komponen) ? '<span class="badge bg-danger">Tarif Kosong</span>' : implode("", $komponen))."
                </td>
                <td class='text-end fw-bold text-primary pe-3'>".number_format($total)."</td>
              </tr>";
    }
} else {
    echo "<tr><td colspan='5' class='text-center py-5 text-muted small italic'>Tidak ada mahasiswa aktif ditemukan.</td></tr>";
}