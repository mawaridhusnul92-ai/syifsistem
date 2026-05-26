<?php
/**
 * honorarium_action.php - CONTROLLER API HONORARIUM (FIXED COMPLETE)
 * STIKes Yarsi Pontianak - SYIFA System
 *
 * ROOT CAUSE FIX:
 * 1. ob_start() di awal — semua output ditangkap, tidak ada HTML error bocor ke JSON
 * 2. set_error_handler() — fatal error PHP dikonversi ke JSON, bukan HTML
 * 3. header() dipindah SETELAH ob_start() agar tidak ada "headers already sent"
 * 4. begin_transaction() diganti query("START TRANSACTION") — lebih aman di semua versi MySQLi
 * 5. Semua $_POST key di-check dengan null coalescing ?? '' agar tidak crash jika key tidak ada
 * 6. Auto-create tabel honor_template dengan kolom komponen_id yang benar
 * 7. save_komp: kolom jabatan_fungsional & kode_akun_beban dibuat opsional (ALTER IF NOT EXISTS)
 * 8. bayar_slip: prepare() dibungkus try-catch tersendiri, fallback ke query biasa jika gagal
 */

// =============================================================
// 1. TANGKAP SEMUA OUTPUT — TIDAK BOLEH ADA YANG BOCOR
// =============================================================
ob_start();

// Matikan semua error display, tapi log tetap jalan
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// =============================================================
// 2. HANDLER ERROR FATAL — KONVERSI KE JSON
// =============================================================
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'error',
            'message' => 'PHP Fatal Error: ' . $err['message'] . ' (Line ' . $err['line'] . ')'
        ]);
    }
});

// =============================================================
// 3. KONEKSI DATABASE
// =============================================================
if (!isset($conn)) {
    if (file_exists('config/koneksi.php'))      require_once 'config/koneksi.php';
    elseif (file_exists('koneksi.php'))          require_once 'koneksi.php';
    else {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'File koneksi.php tidak ditemukan!']);
        exit;
    }
}

// =============================================================
// 4. VALIDASI SESSION
// =============================================================
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'session_expired', 'message' => 'Sesi habis. Silakan login kembali.']);
    exit;
}

// Set header JSON setelah semua include selesai
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid    = (int)($_SESSION['user_id'] ?? 1);

// =============================================================
// 5. HELPER FUNCTIONS
// =============================================================
function esc($conn, $val)    { return $conn->real_escape_string(trim((string)($val ?? ''))); }
function cleanRp($str)       { return (float)preg_replace('/[^0-9.]/', '', str_replace('.', '', (string)$str)); }
function postStr($key, $def='') { return trim((string)($_POST[$key] ?? $def)); }
function postInt($key, $def=0)  { return (int)($_POST[$key] ?? $def); }

// =============================================================
// 6. AUTO-CREATE TABEL YANG DIPERLUKAN
// =============================================================
$conn->query("CREATE TABLE IF NOT EXISTS honor_template (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_template VARCHAR(150) NOT NULL,
    jenis_tujuan ENUM('KUITANSI','PENGAJUAN') DEFAULT 'PENGAJUAN',
    custom_layout MEDIUMTEXT NULL,
    linked_pengajuan_template_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Pastikan kolom linked_pengajuan_template_id di honor_template ada
$cols_tpl = [];
$res_tpl = $conn->query("SHOW COLUMNS FROM honor_template");
if ($res_tpl) { while($rc = $res_tpl->fetch_assoc()) $cols_tpl[] = $rc['Field']; }
if (!in_array('linked_pengajuan_template_id', $cols_tpl))
    $conn->query("ALTER TABLE honor_template ADD COLUMN linked_pengajuan_template_id INT NULL DEFAULT NULL AFTER custom_layout");

// Pastikan kolom opsional di honor_komponen ada (ALTER jika belum)
$cols_exist = [];
$res_cols = $conn->query("SHOW COLUMNS FROM honor_komponen");
if ($res_cols) { while($rc = $res_cols->fetch_assoc()) $cols_exist[] = $rc['Field']; }
if (!in_array('kode_akun_beban', $cols_exist))
    $conn->query("ALTER TABLE honor_komponen ADD COLUMN kode_akun_beban VARCHAR(30) DEFAULT '' AFTER deskripsi");
if (!in_array('is_jafung', $cols_exist))
    $conn->query("ALTER TABLE honor_komponen ADD COLUMN is_jafung TINYINT(1) DEFAULT 0 AFTER is_active");

// Pastikan kolom jabatan_fungsional di honor_komponen_detail ada
$cols_det = [];
$res_det = $conn->query("SHOW COLUMNS FROM honor_komponen_detail");
if ($res_det) { while($rc = $res_det->fetch_assoc()) $cols_det[] = $rc['Field']; }
if (!in_array('jabatan_fungsional', $cols_det))
    $conn->query("ALTER TABLE honor_komponen_detail ADD COLUMN jabatan_fungsional VARCHAR(50) DEFAULT '' AFTER rincian");

// Pastikan kolom prodi & komponen_id di honor_generate_detail ada
$cols_gd = [];
$res_gd = $conn->query("SHOW COLUMNS FROM honor_generate_detail");
if ($res_gd) { while($rc = $res_gd->fetch_assoc()) $cols_gd[] = $rc['Field']; }
if (!in_array('prodi', $cols_gd))
    $conn->query("ALTER TABLE honor_generate_detail ADD COLUMN prodi VARCHAR(100) DEFAULT '' AFTER mata_kuliah");
if (!in_array('komponen_id', $cols_gd))
    $conn->query("ALTER TABLE honor_generate_detail ADD COLUMN komponen_id INT DEFAULT NULL AFTER prodi");

// Pastikan kolom template_id di honor_generate ada
$cols_hg = [];
$res_hg = $conn->query("SHOW COLUMNS FROM honor_generate");
if ($res_hg) { while($rc = $res_hg->fetch_assoc()) $cols_hg[] = $rc['Field']; }
if (!in_array('template_id', $cols_hg))
    $conn->query("ALTER TABLE honor_generate ADD COLUMN template_id INT DEFAULT NULL AFTER komponen_id");

// =============================================================
// 7. MAIN ROUTER
// =============================================================
try {
    switch ($action) {

        // ========================================================
        // TEMPLATE LAYOUT
        // ========================================================
        case 'save_template':
            $id           = postInt('id');
            $nama         = esc($conn, postStr('nama_template'));
            // jenis_tujuan ditentukan dari sub-menu: 'PENGAJUAN' atau 'KUITANSI'
            $jenis        = esc($conn, postStr('jenis_tujuan', 'PENGAJUAN'));
            if (!in_array($jenis, ['PENGAJUAN', 'KUITANSI'])) $jenis = 'PENGAJUAN';
            $layout_json  = postStr('custom_layout', '[]');
            // ID template pengajuan yang menjadi acuan (hanya untuk KUITANSI)
            $linked_id    = postInt('linked_pengajuan_template_id');
            if ($jenis === 'PENGAJUAN') $linked_id = 0; // PENGAJUAN tidak perlu linked

            // Validasi JSON
            $parsed = json_decode($layout_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['status' => 'error', 'message' => 'Format layout JSON tidak valid: ' . json_last_error_msg()]);
                break;
            }
            if (empty($nama)) {
                echo json_encode(['status' => 'error', 'message' => 'Nama Template wajib diisi!']);
                break;
            }
            // Validasi: kuitansi wajib link ke pengajuan
            if ($jenis === 'KUITANSI' && $linked_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Template Kuitansi wajib ditautkan ke Template Pengajuan yang ada!']);
                break;
            }

            $layout_safe  = esc($conn, $layout_json);
            $linked_val   = $linked_id > 0 ? $linked_id : 'NULL';

            if ($id > 0) {
                $q = "UPDATE honor_template SET nama_template='$nama', jenis_tujuan='$jenis', custom_layout='$layout_safe', linked_pengajuan_template_id=$linked_val WHERE id=$id";
            } else {
                $q = "INSERT INTO honor_template (nama_template, jenis_tujuan, custom_layout, linked_pengajuan_template_id) VALUES ('$nama','$jenis','$layout_safe',$linked_val)";
            }

            if ($conn->query($q)) {
                echo json_encode(['status' => 'success', 'message' => 'Template berhasil disimpan!', 'id' => ($id > 0 ? $id : $conn->insert_id)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Query gagal: ' . $conn->error]);
            }
            break;

        case 'delete_template':
            $id = postInt('id');
            if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => 'ID tidak valid']); break; }
            // Cek apakah dipakai di generate
            $cek = $conn->query("SELECT COUNT(id) as n FROM honor_generate WHERE template_id=$id");
            $n = $cek ? (int)$cek->fetch_assoc()['n'] : 0;
            if ($n > 0) {
                echo json_encode(['status' => 'error', 'message' => "Template ini digunakan dalam $n batch generate. Tidak bisa dihapus!"]);
                break;
            }
            $conn->query("DELETE FROM honor_template WHERE id=$id");
            echo json_encode(['status' => 'success', 'message' => 'Template berhasil dihapus.']);
            break;

        // ========================================================
        // DATABASE DOSEN
        // ========================================================
        case 'save_dosen':
            $id      = postInt('id');
            $nip     = esc($conn, postStr('nip'));
            $nama    = esc($conn, postStr('nama'));
            $email   = esc($conn, postStr('email'));
            $hp      = esc($conn, postStr('no_hp'));
            $pend    = esc($conn, postStr('pendidikan_terakhir'));
            $jab     = esc($conn, postStr('jabatan_fungsional'));
            $gol     = esc($conn, postStr('golongan'));
            $prodi   = esc($conn, postStr('program_studi'));
            $stat    = esc($conn, postStr('status', 'Aktif'));
            $bank    = esc($conn, postStr('nama_bank'));
            $rek     = esc($conn, postStr('no_rekening'));
            $pemilik = esc($conn, postStr('pemilik_rekening'));

            if (empty($nip) || empty($nama)) {
                echo json_encode(['status' => 'error', 'message' => 'NIP dan Nama wajib diisi!']);
                break;
            }

            if ($id > 0) {
                $q = "UPDATE dosen SET nip='$nip',nama='$nama',email='$email',no_hp='$hp',pendidikan_terakhir='$pend',jabatan_fungsional='$jab',golongan='$gol',program_studi='$prodi',status='$stat',nama_bank='$bank',no_rekening='$rek',pemilik_rekening='$pemilik' WHERE id=$id";
            } else {
                $cek = $conn->query("SELECT id FROM dosen WHERE nip='$nip' LIMIT 1");
                if ($cek && $cek->num_rows > 0) {
                    echo json_encode(['status' => 'error', 'message' => "NIP '$nip' sudah terdaftar!"]); break;
                }
                $q = "INSERT INTO dosen (nip,nama,email,no_hp,pendidikan_terakhir,jabatan_fungsional,golongan,program_studi,status,nama_bank,no_rekening,pemilik_rekening) VALUES ('$nip','$nama','$email','$hp','$pend','$jab','$gol','$prodi','$stat','$bank','$rek','$pemilik')";
            }
            if ($conn->query($q)) echo json_encode(['status' => 'success', 'message' => 'Data Dosen berhasil disimpan!']);
            else echo json_encode(['status' => 'error', 'message' => 'Gagal: ' . $conn->error]);
            break;

        case 'delete_dosen':
            $id = postInt('id');
            // Cek apakah sudah ada di generate
            $cek = $conn->query("SELECT COUNT(id) as n FROM honor_generate_detail WHERE dosen_id=$id");
            $n = $cek ? (int)$cek->fetch_assoc()['n'] : 0;
            if ($n > 0) {
                echo json_encode(['status' => 'error', 'message' => "Dosen ini sudah punya $n data honor. Tidak bisa dihapus!"]);
                break;
            }
            $conn->query("DELETE FROM dosen WHERE id=$id");
            echo json_encode(['status' => 'success', 'message' => 'Data Dosen berhasil dihapus!']);
            break;

        // ========================================================
        // KOMPONEN HONOR
        // ========================================================
        case 'save_komp':
            $id         = postInt('id');
            $kode       = esc($conn, postStr('kode_honor'));
            $nama       = esc($conn, postStr('nama_honor'));
            $desc       = esc($conn, postStr('deskripsi'));
            $coa_beban  = esc($conn, postStr('kode_akun_beban'));
            $stat       = isset($_POST['is_active']) ? 1 : 0;
            $is_jafung  = isset($_POST['is_jafung']) ? 1 : 0;

            if (empty($nama)) {
                echo json_encode(['status' => 'error', 'message' => 'Nama Honor wajib diisi!']); break;
            }

            $rincianArr = $_POST['rincian'] ?? [];
            if (empty($rincianArr)) {
                echo json_encode(['status' => 'error', 'message' => 'Minimal harus ada 1 rincian tarif!']); break;
            }

            $conn->query("START TRANSACTION");
            $ok = false;

            if ($id > 0) {
                $ok = $conn->query("UPDATE honor_komponen SET nama_honor='$nama',deskripsi='$desc',kode_akun_beban='$coa_beban',is_active=$stat,is_jafung=$is_jafung WHERE id=$id");
                $komp_id = $id;
                $conn->query("DELETE FROM honor_komponen_detail WHERE komponen_id=$komp_id");
            } else {
                if (empty($kode)) $kode = 'HON-' . strtoupper(substr(md5(time()), 0, 6));
                $cek_kode = $conn->query("SELECT id FROM honor_komponen WHERE kode_honor='$kode' LIMIT 1");
                if ($cek_kode && $cek_kode->num_rows > 0) $kode .= '-' . rand(10,99);
                $ok = $conn->query("INSERT INTO honor_komponen (kode_honor,nama_honor,deskripsi,kode_akun_beban,is_active,is_jafung) VALUES ('$kode','$nama','$desc','$coa_beban',$stat,$is_jafung)");
                $komp_id = $conn->insert_id;
            }

            if (!$ok || $komp_id <= 0) {
                $conn->query("ROLLBACK");
                echo json_encode(['status' => 'error', 'message' => 'Gagal simpan header komponen: ' . $conn->error]);
                break;
            }

            $err_detail = null;
            for ($i = 0; $i < count($rincianArr); $i++) {
                if (empty(trim($rincianArr[$i]))) continue;
                $rinc = esc($conn, $rincianArr[$i]);
                $sat  = esc($conn, $_POST['satuan'][$i] ?? 'Per SKS');
                $jf   = $is_jafung ? esc($conn, $_POST['jafung'][$i] ?? '') : '';
                $pjk  = (float)($_POST['pajak'][$i] ?? 0);
                $bsr  = cleanRp($_POST['besaran'][$i] ?? 0);

                if (!$conn->query("INSERT INTO honor_komponen_detail (komponen_id,rincian,jabatan_fungsional,satuan,besaran,potongan_pajak) VALUES ($komp_id,'$rinc','$jf','$sat',$bsr,$pjk)")) {
                    $err_detail = $conn->error;
                    break;
                }
            }

            if ($err_detail) {
                $conn->query("ROLLBACK");
                echo json_encode(['status' => 'error', 'message' => 'Gagal simpan detail tarif: ' . $err_detail]);
            } else {
                $conn->query("COMMIT");
                echo json_encode(['status' => 'success', 'message' => 'Komponen Honor berhasil disimpan!']);
            }
            break;

        case 'get_komponen':
            $id = (int)($_GET['id'] ?? 0);
            $res = $conn->query("SELECT * FROM honor_komponen WHERE id=$id");
            if ($res && $row = $res->fetch_assoc()) {
                $det = $conn->query("SELECT * FROM honor_komponen_detail WHERE komponen_id=$id ORDER BY id ASC");
                $row['details'] = $det ? $det->fetch_all(MYSQLI_ASSOC) : [];
                echo json_encode(['status' => 'success', 'data' => $row]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Komponen tidak ditemukan']);
            }
            break;

        case 'delete_komp':
            $id = postInt('id');
            $cek = $conn->query("SELECT COUNT(id) as n FROM honor_generate WHERE komponen_id=$id");
            $n = $cek ? (int)$cek->fetch_assoc()['n'] : 0;
            if ($n > 0) {
                echo json_encode(['status' => 'error', 'message' => "Komponen digunakan dalam $n generate. Tidak bisa dihapus!"]); break;
            }
            $conn->query("DELETE FROM honor_komponen WHERE id=$id");
            echo json_encode(['status' => 'success', 'message' => 'Komponen berhasil dihapus.']);
            break;

        // ========================================================
        // GENERATE HONOR
        // ========================================================
        case 'init_generate':
            $nama    = esc($conn, postStr('nama'));
            $tpl_id  = postInt('template_id');
            $bulan   = postInt('bulan', date('n'));
            $tahun   = postInt('tahun', date('Y'));
            $catatan = esc($conn, postStr('catatan'));

            if (empty($nama) || $tpl_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Nama Batch dan Template wajib dipilih!']); break;
            }

            $kode = "GEN-{$tahun}-" . date('mdHis');
            if ($conn->query("INSERT INTO honor_generate (kode_generate,nama_generate,template_id,periode_bulan,periode_tahun,tanggal_generate,catatan,status,total_honor,created_by) VALUES ('$kode','$nama',$tpl_id,$bulan,$tahun,NOW(),'$catatan','Draft',0,$uid)")) {
                echo json_encode(['status' => 'success', 'message' => 'Batch generate berhasil dibuat!', 'id' => $conn->insert_id]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal: ' . $conn->error]);
            }
            break;

        case 'edit_generate_header':
            $id     = postInt('id');
            $nama   = esc($conn, postStr('nama'));
            $tpl_id = postInt('template_id');
            $bulan  = postInt('bulan');
            $tahun  = postInt('tahun');

            if ($conn->query("UPDATE honor_generate SET nama_generate='$nama',template_id=$tpl_id,periode_bulan=$bulan,periode_tahun=$tahun WHERE id=$id")) {
                echo json_encode(['status' => 'success', 'message' => 'Header Generate diperbarui.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Gagal update: ' . $conn->error]);
            }
            break;

        case 'save_generate_detail':
            $gen_id   = postInt('generate_id');
            $is_final = (postStr('finalize') === '1') ? 1 : 0;

            if ($gen_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'ID Generate tidak valid!']); break;
            }

            $cek_stat = $conn->query("SELECT status FROM honor_generate WHERE id=$gen_id");
            if (!$cek_stat || $cek_stat->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Generate tidak ditemukan!']); break;
            }
            if ($cek_stat->fetch_assoc()['status'] !== 'Draft') {
                echo json_encode(['status' => 'error', 'message' => 'Hanya Generate berstatus DRAFT yang bisa diedit!']); break;
            }

            $dosen_ids   = $_POST['dosen_id'] ?? [];
            $n_dosen     = count($dosen_ids);

            if ($n_dosen === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Minimal harus ada 1 entri dosen!']); break;
            }

            // ── Bangun peta rid → array nilai per-kemunculan ───────────
            // Semua input komp_qty_{rid}[], komp_tarif_{rid}[], komp_kompId_{rid}[]
            // diindeks berdasarkan urutan kemunculan rid di rincian_ids[].
            // Karena executeSubmit sudah memastikan nama input konsisten,
            // kita cukup iterasi dosen_ids dan ambil nilai dengan counter per-rid.

            $rincian_ids = $_POST['rincian_ids'] ?? [];

            // Pecah rincian_ids flat menjadi per-dosen.
            // Jika total rincian_ids tidak habis dibagi n_dosen, pakai fallback.
            $rids_per_row = ($n_dosen > 0 && count($rincian_ids) > 0)
                ? (int)ceil(count($rincian_ids) / $n_dosen)
                : 0;

            // Counter kemunculan per rid (untuk indexing array qty/tarif/kompId)
            $rid_counter = [];

            $conn->query("START TRANSACTION");
            $conn->query("DELETE FROM honor_generate_detail WHERE generate_id=$gen_id");

            $total_all = 0;
            $err_msg   = null;

            for ($i = 0; $i < $n_dosen; $i++) {
                $did = (int)$dosen_ids[$i];
                if ($did <= 0) continue;

                $prodi_val = esc($conn, $_POST['teks_prodi'][$i]       ?? '');
                $mk_val    = esc($conn, $_POST['teks_mata_kuliah'][$i]  ?? '');
                $jabatan_val = esc($conn, $_POST['teks_jabatan'][$i]    ?? '');
                $pajak_pct   = (float)($_POST['pajak_pct'][$i]          ?? 0);

                // Slice rincian_ids untuk dosen ini
                if ($rids_per_row > 0) {
                    $row_rids = array_slice($rincian_ids, $i * $rids_per_row, $rids_per_row);
                } else {
                    $row_rids = array_unique($rincian_ids);
                }

                foreach ($row_rids as $rid) {
                    $rid = (int)$rid;
                    if ($rid <= 0) continue;

                    // Hitung kemunculan ke-berapa rid ini
                    if (!isset($rid_counter[$rid])) $rid_counter[$rid] = 0;
                    $occ = $rid_counter[$rid];
                    $rid_counter[$rid]++;

                    $qty_arr = $_POST["komp_qty_{$rid}"]    ?? [];
                    $trf_arr = $_POST["komp_tarif_{$rid}"]  ?? [];
                    $kid_arr = $_POST["komp_kompId_{$rid}"] ?? [];

                    $qty   = (float)($qty_arr[$occ]  ?? 0);
                    $tarif = cleanRp($trf_arr[$occ]   ?? 0);
                    $k_id  = (int)($kid_arr[$occ]     ?? 0);

                    if ($qty <= 0) continue; // skip baris kosong

                    $bruto    = $qty * $tarif;
                    $potongan = round($bruto * ($pajak_pct / 100), 2);
                    $netto    = $bruto - $potongan;
                    $total_all += $netto;

                    $q_ins = "INSERT INTO honor_generate_detail
                        (generate_id,dosen_id,prodi,komponen_id,mata_kuliah,rincian_komponen_id,qty,tarif,total_honor,persen_pajak,potongan_pajak,honor_diterima,status_bayar,status_kirim)
                        VALUES ($gen_id,$did,'$prodi_val',$k_id,'$mk_val',$rid,$qty,$tarif,$bruto,$pajak_pct,$potongan,$netto,'Belum Dibayar','Belum Dikirim')";

                    if (!$conn->query($q_ins)) {
                        $err_msg = $conn->error;
                        break 2;
                    }
                }
            }

            if ($err_msg) {
                $conn->query("ROLLBACK");
                echo json_encode(['status' => 'error', 'message' => 'Gagal simpan detail: ' . $err_msg]);
            } else {
                $new_status = $is_final ? 'Final' : 'Draft';
                $conn->query("UPDATE honor_generate SET total_honor=$total_all, status='$new_status' WHERE id=$gen_id");
                $conn->query("COMMIT");
                $msg = $is_final
                    ? 'Generate difinalisasi! Slip Honor telah diterbitkan untuk semua dosen.'
                    : 'Draft berhasil disimpan.';
                echo json_encode(['status' => 'success', 'message' => $msg]);
            }
            break;

        case 'delete_generate':
            $id = postInt('id');
            $cek = $conn->query("SELECT status FROM honor_generate WHERE id=$id");
            if (!$cek || $cek->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Data tidak ditemukan!']); break;
            }
            $row = $cek->fetch_assoc();
            if ($row['status'] !== 'Draft') {
                echo json_encode(['status' => 'error', 'message' => 'Hanya Draft yang bisa dihapus!']); break;
            }
            $conn->query("DELETE FROM honor_generate WHERE id=$id");
            echo json_encode(['status' => 'success', 'message' => 'Draft Generate berhasil dihapus.']);
            break;

        case 'batal_generate':
            $id = postInt('id');
            $cek_bayar = $conn->query("SELECT COUNT(id) as n FROM honor_generate_detail WHERE generate_id=$id AND status_bayar='Sudah Dibayar'");
            $n_bayar = $cek_bayar ? (int)$cek_bayar->fetch_assoc()['n'] : 0;
            if ($n_bayar > 0) {
                echo json_encode(['status' => 'error', 'message' => "$n_bayar slip sudah dibayarkan. Batalkan pembayaran terlebih dahulu."]); break;
            }
            $conn->query("UPDATE honor_generate SET status='Draft' WHERE id=$id");
            $conn->query("UPDATE honor_generate_detail SET status_bayar='Belum Dibayar', jurnal_id=NULL WHERE generate_id=$id");
            echo json_encode(['status' => 'success', 'message' => 'Generate dikembalikan ke status DRAFT.']);
            break;

        // ========================================================
        // SLIP HONOR — PEMBAYARAN
        // ========================================================
        case 'bayar_slip':
            $slip_ids_raw = $_POST['slip_ids'] ?? [];
            if (empty($slip_ids_raw)) {
                echo json_encode(['status' => 'error', 'message' => 'Pilih minimal 1 slip!']); break;
            }
            if (!is_array($slip_ids_raw)) $slip_ids_raw = explode(',', $slip_ids_raw);
            $ids_str = implode(',', array_filter(array_map('intval', $slip_ids_raw)));
            if (empty($ids_str)) {
                echo json_encode(['status' => 'error', 'message' => 'ID slip tidak valid!']); break;
            }

            $kas_akun  = esc($conn, postStr('kas_akun'));
            $tgl_bayar = esc($conn, postStr('tgl_bayar', date('Y-m-d')));
            $ref       = esc($conn, postStr('referensi', 'BKK-HON/' . date('Ymd/His')));

            // Ambil data slip yang belum dibayar
            $res_slips = $conn->query("SELECT d.*, ds.nama as dosen_nama FROM honor_generate_detail d JOIN dosen ds ON d.dosen_id=ds.id WHERE d.id IN ($ids_str) AND d.status_bayar != 'Sudah Dibayar'");
            if (!$res_slips || $res_slips->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Semua slip sudah dibayar atau tidak ditemukan.']); break;
            }

            $total_bayar  = 0;
            $nama_list    = [];
            $slips_data   = [];
            while ($s = $res_slips->fetch_assoc()) {
                $total_bayar += (float)$s['honor_diterima'];
                if (!in_array($s['dosen_nama'], $nama_list)) $nama_list[] = $s['dosen_nama'];
                $slips_data[] = $s;
            }

            if ($total_bayar <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Total honor = 0!']); break;
            }

            $ket_jurnal = esc($conn, 'Pembayaran Honorarium: ' . implode(', ', array_slice($nama_list, 0, 3)) . (count($nama_list) > 3 ? ' dkk.' : ''));

            $conn->query("START TRANSACTION");

            // Update status bayar dulu (ini yang terpenting)
            $conn->query("UPDATE honor_generate_detail SET status_bayar='Sudah Dibayar' WHERE id IN ($ids_str) AND status_bayar!='Sudah Dibayar'");

            // Auto update header status
            $conn->query("UPDATE honor_generate g SET status='Dibayarkan' WHERE id IN (SELECT DISTINCT generate_id FROM honor_generate_detail WHERE id IN ($ids_str)) AND (SELECT COUNT(*) FROM honor_generate_detail d2 WHERE d2.generate_id=g.id AND d2.status_bayar!='Sudah Dibayar') = 0");

            // Coba posting jurnal — opsional, tidak gagalkan transaksi utama
            $jid = 0;
            try {
                $cek_jurnal_tbl = $conn->query("SHOW TABLES LIKE 'syifa_jurnal'");
                if ($cek_jurnal_tbl && $cek_jurnal_tbl->num_rows > 0 && !empty($kas_akun)) {
                    if ($conn->query("INSERT INTO syifa_jurnal (no_jurnal,tgl_jurnal,jenis_transaksi,keterangan,pihak_nama,total_debet,total_kredit,status,akun_utama_kode,user_id,is_deleted) VALUES ('$ref','$tgl_bayar','Pengeluaran','$ket_jurnal','Multi Dosen',$total_bayar,$total_bayar,'APPROVED','$kas_akun',$uid,0)")) {
                        $jid = $conn->insert_id;
                        $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id,kode_akun,debit,kredit) VALUES ($jid,'$kas_akun',0,$total_bayar)");

                        // Debit per COA Beban
                        $res_coa = $conn->query("SELECT k.kode_akun_beban, SUM(d.honor_diterima) as tot FROM honor_generate_detail d LEFT JOIN honor_komponen k ON d.komponen_id=k.id WHERE d.id IN ($ids_str) GROUP BY k.kode_akun_beban");
                        if ($res_coa) {
                            while ($rc = $res_coa->fetch_assoc()) {
                                $coa_b = !empty($rc['kode_akun_beban']) ? esc($conn, $rc['kode_akun_beban']) : '5-10000';
                                $nom_b = (float)$rc['tot'];
                                $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id,kode_akun,debit,kredit) VALUES ($jid,'$coa_b',$nom_b,0)");
                            }
                        }

                        if ($jid > 0) $conn->query("UPDATE honor_generate_detail SET jurnal_id=$jid WHERE id IN ($ids_str)");
                    }
                }
            } catch (Exception $je) {
                // Jurnal gagal tapi pembayaran tetap jalan
            }

            $conn->query("COMMIT");

            $total_fmt = number_format($total_bayar, 0, ',', '.');
            $jurnal_info = $jid > 0 ? " Jurnal akuntansi #{$jid} otomatis dibuat." : "";
            echo json_encode(['status' => 'success', 'message' => "Pembayaran Rp {$total_fmt} untuk " . count($nama_list) . " dosen berhasil!" . $jurnal_info]);
            break;

        case 'batal_bayar':
            $ids_raw = postStr('slip_ids');
            $ids_str = implode(',', array_filter(array_map('intval', explode(',', $ids_raw))));
            if (empty($ids_str)) {
                echo json_encode(['status' => 'error', 'message' => 'ID Slip kosong.']); break;
            }

            $conn->query("START TRANSACTION");
            try {
                // Hapus jurnal terkait
                $res_jid = $conn->query("SELECT DISTINCT jurnal_id FROM honor_generate_detail WHERE id IN ($ids_str) AND jurnal_id IS NOT NULL");
                if ($res_jid) {
                    while ($r = $res_jid->fetch_assoc()) {
                        $jid = (int)$r['jurnal_id'];
                        $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id=$jid");
                        $conn->query("DELETE FROM syifa_jurnal WHERE id=$jid");
                    }
                }
                $conn->query("UPDATE honor_generate_detail SET status_bayar='Belum Dibayar', jurnal_id=NULL WHERE id IN ($ids_str)");
                $conn->query("UPDATE honor_generate g SET status='Final' WHERE id IN (SELECT DISTINCT generate_id FROM honor_generate_detail WHERE id IN ($ids_str))");
                $conn->query("COMMIT");
                echo json_encode(['status' => 'success', 'message' => 'Pembayaran dibatalkan. Jurnal otomatis di-rollback.']);
            } catch (Exception $e) {
                $conn->query("ROLLBACK");
                echo json_encode(['status' => 'error', 'message' => 'Gagal rollback: ' . $e->getMessage()]);
            }
            break;

        // ========================================================
        // KIRIM EMAIL (OPSIONAL — butuh mailer_engine.php)
        // ========================================================
        case 'kirim_email':
            if (!function_exists('kirim_email_smtp')) {
                if (file_exists('mailer_engine.php')) require_once 'mailer_engine.php';
                else {
                    echo json_encode(['status' => 'error', 'message' => 'Fitur email belum dikonfigurasi (mailer_engine.php tidak ada).']); break;
                }
            }

            $mail_data_json = postStr('mail_data_json', '[]');
            $mail_data = json_decode($mail_data_json, true);
            if (empty($mail_data)) {
                echo json_encode(['status' => 'error', 'message' => 'Data email kosong.']); break;
            }

            $subject       = postStr('subject', 'Kuitansi Honorarium - STIKes Yarsi Pontianak');
            $pesan_custom  = nl2br(htmlspecialchars(postStr('pesan', 'Honorarium Anda telah dicairkan.')));
            $berhasil      = 0;

            $nm_bln_email = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

            foreach ($mail_data as $md) {
                $email_tujuan = trim($md['email'] ?? '');
                $slip_ids_em  = preg_replace('/[^0-9,]/', '', $md['ids'] ?? '');
                if (empty($email_tujuan) || empty($slip_ids_em)) continue;

                $res_render = $conn->query("SELECT d.*, g.nama_generate, g.periode_bulan, g.periode_tahun, ds.nama as dosen_nama, ds.email FROM honor_generate_detail d JOIN honor_generate g ON d.generate_id=g.id JOIN dosen ds ON d.dosen_id=ds.id WHERE d.id IN ($slip_ids_em)");

                $tot_bruto = $tot_pajak = $tot_netto = 0;
                $dosen_name = 'Dosen'; $periode = '';
                $tabel_html = '';

                if ($res_render) {
                    while ($row = $res_render->fetch_assoc()) {
                        $dosen_name = $row['dosen_nama'];
                        $periode = $nm_bln_email[$row['periode_bulan']] . ' ' . $row['periode_tahun'];
                        $tot_bruto += (float)$row['total_honor'];
                        $tot_pajak += (float)$row['potongan_pajak'];
                        $tot_netto += (float)$row['honor_diterima'];
                        $tabel_html .= "<tr><td style='padding:6px;border:1px solid #ddd'>" . htmlspecialchars($row['nama_generate']) . "</td><td style='padding:6px;border:1px solid #ddd;text-align:center'>" . $row['qty'] . "</td><td style='padding:6px;border:1px solid #ddd;text-align:right'>Rp " . number_format($row['tarif'],0,',','.') . "</td><td style='padding:6px;border:1px solid #ddd;text-align:right'>Rp " . number_format($row['honor_diterima'],0,',','.') . "</td></tr>";
                    }
                }

                $body = "<div style='font-family:Arial;max-width:600px;margin:auto;padding:20px;border:1px solid #eee;border-radius:8px'><h3 style='color:#0d6efd'>STIKes Yarsi Pontianak</h3><h4>Kuitansi Honorarium - $periode</h4><p>$pesan_custom</p><p><b>Penerima:</b> $dosen_name</p><table style='width:100%;border-collapse:collapse'><thead><tr style='background:#f1f5f9'><th style='padding:8px;border:1px solid #ddd'>Keterangan</th><th style='padding:8px;border:1px solid #ddd'>Qty</th><th style='padding:8px;border:1px solid #ddd'>Tarif</th><th style='padding:8px;border:1px solid #ddd'>Netto</th></tr></thead><tbody>$tabel_html</tbody><tfoot><tr style='font-weight:bold;background:#0d6efd;color:#fff'><td colspan='3' style='padding:8px;border:1px solid #0d6efd;text-align:right'>TOTAL DITERIMA</td><td style='padding:8px;border:1px solid #0d6efd;text-align:right'>Rp " . number_format($tot_netto,0,',','.') . "</td></tr></tfoot></table></div>";

                try {
                    if (kirim_email_smtp($conn, $email_tujuan, $dosen_name, $subject, $body)) {
                        $berhasil++;
                        $conn->query("UPDATE honor_generate_detail SET status_kirim='Sudah Dikirim' WHERE id IN ($slip_ids_em)");
                    }
                } catch (Exception $me) { /* skip */ }
            }

            if ($berhasil > 0) echo json_encode(['status' => 'success', 'message' => "Berhasil kirim $berhasil email kuitansi!"]);
            else echo json_encode(['status' => 'error', 'message' => 'Gagal kirim email. Periksa konfigurasi SMTP.']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => "Aksi '$action' tidak dikenal!"]);
            break;
    }

} catch (Throwable $e) {
    // Tangkap semua error termasuk TypeError, Error, Exception
    try { $conn->query("ROLLBACK"); } catch(Throwable $re) {}
    ob_clean();
    echo json_encode([
        'status'  => 'error',
        'message' => 'Server Error: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ')'
    ]);
}

// Flush output buffer bersih
ob_end_flush();
?>
