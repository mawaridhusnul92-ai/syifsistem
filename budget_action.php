<?php
/**
 * budget_action.php - INTEGRATED BUDGET CONTROLLER SYIFA ERP
 * Versi: 6.1 (Grand Master - ArgumentCount Fix)
 * Perbaikan: Memperbaiki jumlah argument pada bind_param di sistem Worksheet.
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid = $_SESSION['user_id'];

/**
 * Fungsi Helper untuk Redirect dan Notifikasi
 */
function done($type, $msg, $page = 'rapb', $extra = '') {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    // Membangun URL redirect
    $url = "index.php?page=" . $page;
    if(isset($_POST['tahun'])) {
        $url .= "&tahun=" . $_POST['tahun'];
    } elseif(isset($_GET['tahun'])) {
        $url .= "&tahun=" . $_GET['tahun'];
    }
    $url .= $extra;
    
    header("Location: " . $url);
    exit;
}

// =========================================================================
// SEGMEN 1: SISTEM WORKSHEET (ENTRY MODERN)
// =========================================================================

// 1.1 CREATE WORKSHEET HEADER
if ($action == 'create_worksheet_header') {
    $tahun = (int)$_POST['tahun'];
    $desc = $conn->real_escape_string($_POST['deskripsi']);
    
    $sql = "INSERT INTO syifa_budget_headers (tahun_anggaran, deskripsi, kategori, created_by) 
            VALUES ($tahun, '$desc', 'Pendapatan', $uid)";
    
    if($conn->query($sql)) {
        $new_id = $conn->insert_id;
        done('success', 'Worksheet berhasil dibuat. Silakan isi rincian anggaran.', 'anggaran_pendapatan', "&view=worksheet&header_id=$new_id");
    } else {
        done('danger', 'Gagal membuat header worksheet: ' . $conn->error, 'anggaran_pendapatan');
    }
}

// 1.2 SAVE WORKSHEET (DRAFT ATAU FINAL)
if ($action == 'save_worksheet_draft' || $action == 'generate_worksheet_final') {
    $hid = (int)$_POST['header_id'];
    $tahun = (int)$_POST['tahun'];
    $status_final = ($action == 'generate_worksheet_final') ? 'Generated' : 'Draft';
    $status_item = ($action == 'generate_worksheet_final') ? 'Disetujui' : 'Draft';

    // Cek apakah RAPB sudah dikunci secara global
    $rapb_cek = $conn->query("SELECT status FROM syifa_rapb WHERE tahun_anggaran='$tahun'")->fetch_assoc();
    if($rapb_cek && $rapb_cek['status'] == 'Disahkan') {
        done('danger', "Akses Ditolak: RAPB Tahun $tahun sudah DISAHKAN. Worksheet tidak dapat diubah.", 'anggaran_pendapatan', "&view=worksheet&header_id=$hid");
    }

    // Bersihkan detail lama dalam worksheet ini agar bisa ditimpa (Sync)
    $conn->query("DELETE FROM syifa_budgets WHERE header_id = $hid");

    $total_all = 0;
    if(!empty($_POST['coa'])) {
        foreach($_POST['coa'] as $i => $coa) {
            $uraian = $conn->real_escape_string($_POST['uraian'][$i]);
            $nominal = (double)str_replace(['.', ','], '', $_POST['nominal'][$i]);
            $total_all += $nominal;

            // Masukkan data ke tabel anggaran utama
            // PLACEHOLDERS: 7 (header_id, tahun_anggaran, kode_akun, uraian_manual, nominal_pagu, status, created_by)
            $stmt = $conn->prepare("INSERT INTO syifa_budgets (header_id, tahun_anggaran, kategori, kode_akun, uraian_manual, nominal_pagu, status, created_by) VALUES (?, ?, 'Pendapatan', ?, ?, ?, ?, ?)");
            
            // FIX: Definisi tipe menjadi 7 karakter (iissdsi) agar sinkron dengan variabel
            // i=int, s=string, d=double/decimal
            $stmt->bind_param("iissdsi", $hid, $tahun, $coa, $uraian, $nominal, $status_item, $uid);
            $stmt->execute();
        }
    }

    // Update Header Worksheet (Total & Status)
    $conn->query("UPDATE syifa_budget_headers SET total_anggaran = $total_all, status = '$status_final' WHERE id = $hid");

    $msg = ($status_final == 'Generated') ? "Anggaran BERHASIL DISAHKAN dan masuk ke target Dashboard." : "Draf worksheet berhasil disimpan.";
    done('success', $msg, 'anggaran_pendapatan', "&view=worksheet&header_id=$hid");
}

// 1.3 DELETE WORKSHEET
if ($action == 'delete_worksheet') {
    $id = (int)$_GET['id'];
    
    // Cek apakah sudah digenerate?
    $cek = $conn->query("SELECT status, tahun_anggaran FROM syifa_budget_headers WHERE id = $id")->fetch_assoc();
    if($cek['status'] == 'Generated') {
        // Cek lagi RAPB global
        $rapb_cek = $conn->query("SELECT status FROM syifa_rapb WHERE tahun_anggaran='{$cek['tahun_anggaran']}'")->fetch_assoc();
        if($rapb_cek && $rapb_cek['status'] == 'Disahkan') {
            done('danger', "Gagal: Worksheet yang sudah masuk dalam RAPB Disahkan tidak boleh dihapus.", 'anggaran_pendapatan');
        }
    }

    $conn->query("DELETE FROM syifa_budget_headers WHERE id = $id");
    $conn->query("DELETE FROM syifa_budgets WHERE header_id = $id");
    done('success', 'Worksheet dan seluruh rincian draf di dalamnya telah dihapus.', 'anggaran_pendapatan');
}

// =========================================================================
// SEGMEN 2: SISTEM ANGGARAN KLASIK (LEGACY SUPPORT & EXPENSE)
// =========================================================================

// 2.1 SIMPAN ANGGARAN BELANJA
if ($action == 'save_budget_expense') {
    $tahun = $_POST['tahun'];
    
    // VALIDASI LOCK GLOBAL
    $rapb = $conn->query("SELECT status FROM syifa_rapb WHERE tahun_anggaran='$tahun'")->fetch_assoc();
    if($rapb && $rapb['status'] == 'Disahkan') {
        done('danger', "GAGAL: RAPB Tahun $tahun sudah DISAHKAN dan TERKUNCI. Gunakan menu Revisi jika diperlukan.", "anggaran_belanja");
    }

    $jenis = $_POST['jenis_belanja']; // Operasional (70%) / Pengembangan (30%)
    $akun  = $_POST['kode_akun'];
    $pagu  = (double)str_replace(['.', ','], '', $_POST['nominal']);
    $ket   = $_POST['keterangan'];

    // Cek Duplikasi Akun pada tahun yang sama
    $cek = $conn->query("SELECT id FROM syifa_budgets WHERE kode_akun='$akun' AND tahun_anggaran='$tahun' AND kategori='Pengeluaran'");
    if ($cek->num_rows > 0) {
        $id = $cek->fetch_assoc()['id'];
        $stmt = $conn->prepare("UPDATE syifa_budgets SET nominal_pagu=?, jenis_belanja=?, keterangan=? WHERE id=?");
        $stmt->bind_param("dssi", $pagu, $jenis, $ket, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO syifa_budgets (tahun_anggaran, kategori, jenis_belanja, kode_akun, nominal_pagu, keterangan, status, created_by) VALUES (?, 'Pengeluaran', ?, ?, ?, ?, 'Draft', ?)");
        $stmt->bind_param("sssdsi", $tahun, $jenis, $akun, $pagu, $ket, $uid);
    }

    if ($stmt->execute()) done('success', 'Anggaran belanja berhasil disimpan.', 'anggaran_belanja');
    else done('danger', 'Gagal menyimpan: ' . $conn->error, 'anggaran_belanja');
}

// 2.2 SIMPAN ANGGARAN PENDAPATAN (KLASIK / QUICK ENTRY)
if ($action == 'save_budget_income') {
    $tahun = $_POST['tahun'];
    
    $rapb = $conn->query("SELECT status FROM syifa_rapb WHERE tahun_anggaran='$tahun'")->fetch_assoc();
    if($rapb && $rapb['status'] == 'Disahkan') {
        done('danger', "GAGAL: RAPB Tahun $tahun sudah DISAHKAN dan TERKUNCI.", "anggaran_pendapatan");
    }

    $akun  = $_POST['kode_akun'];
    $pagu  = (double)str_replace(['.', ','], '', $_POST['nominal']);
    $ket   = $_POST['keterangan'];

    $cek = $conn->query("SELECT id FROM syifa_budgets WHERE kode_akun='$akun' AND tahun_anggaran='$tahun' AND kategori='Pendapatan'");
    if ($cek->num_rows > 0) {
        $id = $cek->fetch_assoc()['id'];
        $stmt = $conn->prepare("UPDATE syifa_budgets SET nominal_pagu=?, keterangan=? WHERE id=?");
        $stmt->bind_param("dsi", $pagu, $ket, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO syifa_budgets (tahun_anggaran, kategori, kode_akun, nominal_pagu, keterangan, status, created_by) VALUES (?, 'Pendapatan', ?, ?, ?, 'Draft', ?)");
        $stmt->bind_param("ssdsi", $tahun, $akun, $pagu, $ket, $uid);
    }

    if ($stmt->execute()) done('success', 'Target pendapatan berhasil disimpan.', 'anggaran_pendapatan');
    else done('danger', 'Gagal menyimpan: ' . $conn->error, 'anggaran_pendapatan');
}

// 2.3 HAPUS ITEM ANGGARAN DETIL
if ($action == 'delete_budget') {
    $id = (int)$_GET['id'];
    $src = $_GET['source'] ?? 'rapb';
    
    $b = $conn->query("SELECT tahun_anggaran FROM syifa_budgets WHERE id=$id")->fetch_assoc();
    if ($b) {
        $rapb = $conn->query("SELECT status FROM syifa_rapb WHERE tahun_anggaran='{$b['tahun_anggaran']}'")->fetch_assoc();
        if($rapb && $rapb['status'] == 'Disahkan') {
            done('danger', "GAGAL: Item terkunci karena RAPB sudah disahkan.", $src);
        }
    }
    
    $conn->query("DELETE FROM syifa_budgets WHERE id=$id");
    done('success', 'Item anggaran dihapus.', $src);
}

// =========================================================================
// SEGMEN 3: MANAJEMEN STRATEGIS RAPB (LEVEL PENGESAHAN)
// =========================================================================

// 3.1 UPDATE STATUS RAPB (PEMBAHASAN / REVISI)
if ($action == 'update_status_rapb') {
    $tahun = $_POST['tahun'];
    $status = $_POST['status']; // 'Draft' | 'Revisi'
    
    $cek = $conn->query("SELECT id FROM syifa_rapb WHERE tahun_anggaran='$tahun'");
    if($cek->num_rows == 0) {
        $conn->query("INSERT INTO syifa_rapb (tahun_anggaran, status) VALUES ('$tahun', '$status')");
    } else {
        $conn->query("UPDATE syifa_rapb SET status='$status' WHERE tahun_anggaran='$tahun'");
    }
    
    // Jika status dikembalikan ke Revisi/Draft, buka kunci item anggaran agar bisa diedit kembali
    if ($status == 'Revisi' || $status == 'Draft') {
        $conn->query("UPDATE syifa_budgets SET status='Draft' WHERE tahun_anggaran='$tahun'");
    }
    
    done('success', "Status RAPB Tahun $tahun diubah menjadi: $status", "rapb");
}

// 3.2 PENGESAHAN RAPB (FINAL LOCK)
if ($action == 'sahkan_rapb') {
    $tahun = $_POST['tahun'];
    $no_sk = $_POST['no_sk'];
    $tgl   = $_POST['tgl_pengesahan'];
    $oleh  = $_POST['disahkan_oleh'];
    
    // Hitung Angka Final dari Seluruh Item Anggaran (Aggregated)
    $q_inc = $conn->query("SELECT SUM(nominal_pagu) as t FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND kategori='Pendapatan'")->fetch_assoc();
    $q_exp = $conn->query("SELECT SUM(nominal_pagu) as t FROM syifa_budgets WHERE tahun_anggaran='$tahun' AND kategori='Pengeluaran'")->fetch_assoc();
    
    $tot_inc = (double)($q_inc['t'] ?? 0);
    $tot_exp = (double)($q_exp['t'] ?? 0);
    $surplus = $tot_inc - $tot_exp;

    // VALIDASI KERAS: Tidak boleh defisit saat pengesahan resmi
    if ($surplus < 0) {
        done('danger', "GAGAL PENGESAHAN: RAPB Defisit (Belanja > Pendapatan). Harap revisi rincian anggaran.", "rapb");
    }

    // Penanganan Dokumen SK (Upload PDF)
    $file_name = '';
    if (!empty($_FILES['file_sk']['name'])) {
        $target_dir = "uploads/dokumen_rapb/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $file_name = "SK_RAPB_" . $tahun . "_" . time() . ".pdf";
        move_uploaded_file($_FILES["file_sk"]["tmp_name"], $target_dir . $file_name);
    }

    // Update atau Insert Header RAPB
    $cek = $conn->query("SELECT id FROM syifa_rapb WHERE tahun_anggaran='$tahun'");
    if($cek->num_rows == 0) {
        $conn->query("INSERT INTO syifa_rapb (tahun_anggaran, status) VALUES ('$tahun', 'Draft')");
    }

    $sql = "UPDATE syifa_rapb SET 
            status = 'Disahkan', nomor_sk = '$no_sk', tgl_pengesahan = '$tgl', 
            disahkan_oleh = '$oleh', total_pendapatan = $tot_inc, 
            total_belanja = $tot_exp, surplus_defisit = $surplus 
            WHERE tahun_anggaran = '$tahun'";
            
    if($file_name) $sql = str_replace("WHERE", ", file_sk='$file_name' WHERE", $sql);
    
    $conn->query($sql);

    // KUNCI SELURUH ITEM ANGGARAN (Disetujui)
    $conn->query("UPDATE syifa_budgets SET status='Disetujui' WHERE tahun_anggaran='$tahun'");
    
    done('success', "RAPB Tahun $tahun BERHASIL DISAHKAN. Data anggaran kini TERKUNCI untuk realisasi.", "rapb");
}
?>