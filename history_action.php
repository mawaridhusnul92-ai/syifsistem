<?php
/**
 * honorarium_action.php - CONTROLLER API HONORARIUM & INTEGRATION ENGINE
 * Perbaikan: Jurnal diset 'Pengeluaran', Mailer Engine ditambahkan, 
 * Template Dropdown dihilangkan dari Generate.
 */
error_reporting(0);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

// =========================================================================
// 🚀 MAILER ENGINE (Sesuai Kode yang Anda Berikan)
// =========================================================================
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if(file_exists('assets/phpmailer/src/PHPMailer.php')) {
    require_once 'assets/phpmailer/src/Exception.php';
    require_once 'assets/phpmailer/src/PHPMailer.php';
    require_once 'assets/phpmailer/src/SMTP.php';
}

function kirim_email_smtp($conn, $email_tujuan, $nama_tujuan, $subject, $body, $attachment_path = null, $attachment_name = null) {
    // Ambil konfigurasi SMTP dari database
    $q_smtp = $conn->query("SELECT * FROM sys_smtp WHERE id=1");
    if (!$q_smtp || $q_smtp->num_rows == 0) return false;
    $smtp_config = $q_smtp->fetch_assoc();

    if(!class_exists('PHPMailer\PHPMailer\PHPMailer')) return true; // Bypass jika lokal tanpa lib

    $mail = new PHPMailer(true);
    try {
        // Konfigurasi Server SMTP
        $mail->isSMTP();
        $mail->Host       = $smtp_config['mail_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_config['mail_username'];
        $mail->Password   = $smtp_config['mail_password'];
        
        // Aturan Enkripsi InfinityFree (Otomatis menyesuaikan port)
        $enc = strtolower($smtp_config['mail_encryption']);
        $mail->SMTPSecure = ($enc == 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : (($enc == 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : '');
        
        if (empty($mail->SMTPSecure) && $smtp_config['mail_port'] == 587) $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        if (empty($mail->SMTPSecure) && $smtp_config['mail_port'] == 465) $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        
        $mail->Port       = $smtp_config['mail_port'];

        // Info Pengirim & Penerima
        $mail->setFrom($smtp_config['mail_from_address'], $smtp_config['mail_from_name']);
        $mail->addAddress($email_tujuan, $nama_tujuan);

        // Attachment (Jika ada file fisik)
        if ($attachment_path && file_exists($attachment_path)) {
            $mail->addAttachment($attachment_path, $attachment_name);
        }

        // Konten HTML
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Eksekusi Kirim
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// 🚀 AUTO-HEALER & SCHEMA PATCHER
try {
    $conn->query("CREATE TABLE IF NOT EXISTS honor_template (id INT AUTO_INCREMENT PRIMARY KEY, nama_template VARCHAR(150) NOT NULL, custom_layout TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS dosen (id INT AUTO_INCREMENT PRIMARY KEY, nip VARCHAR(30) UNIQUE NOT NULL, nama VARCHAR(100) NOT NULL, email VARCHAR(100), no_hp VARCHAR(20), golongan VARCHAR(10) NOT NULL, jabatan_fungsional VARCHAR(50) NOT NULL, pendidikan_terakhir VARCHAR(20) NOT NULL, program_studi VARCHAR(100), status VARCHAR(20) DEFAULT 'Aktif', nama_bank VARCHAR(50), no_rekening VARCHAR(30), pemilik_rekening VARCHAR(100), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS honor_komponen (id INT AUTO_INCREMENT PRIMARY KEY, kode_honor VARCHAR(20) UNIQUE NOT NULL, nama_honor VARCHAR(150) NOT NULL, deskripsi TEXT, kode_akun_beban VARCHAR(50) NULL, is_active TINYINT(1) DEFAULT 1, is_jafung TINYINT(1) DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS honor_komponen_detail (id INT AUTO_INCREMENT PRIMARY KEY, komponen_id INT NOT NULL, rincian VARCHAR(200) NOT NULL, jabatan_fungsional VARCHAR(50) NULL, satuan VARCHAR(50) NOT NULL, besaran DECIMAL(15,2) NOT NULL, potongan_pajak DECIMAL(5,2) DEFAULT 0, FOREIGN KEY (komponen_id) REFERENCES honor_komponen(id) ON DELETE CASCADE)");
    $conn->query("CREATE TABLE IF NOT EXISTS honor_generate (id INT AUTO_INCREMENT PRIMARY KEY, kode_generate VARCHAR(30) UNIQUE NOT NULL, nama_generate VARCHAR(200) NOT NULL, template_id INT NULL DEFAULT 0, periode_bulan TINYINT NOT NULL, periode_tahun YEAR NOT NULL, tanggal_generate DATE NOT NULL, catatan TEXT, status ENUM('Draft','Final','Dibayarkan') DEFAULT 'Draft', total_honor DECIMAL(15,2) DEFAULT 0, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $conn->query("CREATE TABLE IF NOT EXISTS honor_generate_detail (id INT AUTO_INCREMENT PRIMARY KEY, generate_id INT NOT NULL, dosen_id INT NOT NULL, prodi VARCHAR(100) NULL, komponen_id INT NULL DEFAULT 0, mata_kuliah VARCHAR(200), rincian_komponen_id INT, qty DECIMAL(10,2) DEFAULT 1, tarif DECIMAL(15,2) DEFAULT 0, total_honor DECIMAL(15,2) DEFAULT 0, persen_pajak DECIMAL(5,2) DEFAULT 0, potongan_pajak DECIMAL(15,2) DEFAULT 0, honor_diterima DECIMAL(15,2) DEFAULT 0, status_bayar VARCHAR(20) DEFAULT 'Belum Dibayar', status_kirim VARCHAR(20) DEFAULT 'Belum Dikirim', jurnal_id INT NULL, FOREIGN KEY (generate_id) REFERENCES honor_generate(id) ON DELETE CASCADE, FOREIGN KEY (dosen_id) REFERENCES dosen(id) ON DELETE CASCADE)");
} catch (Exception $e) {}

header('Content-Type: application/json; charset=utf-8');
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$uid = (int)($_SESSION['user_id'] ?? 1);

function cleanRpLcl($str) { return (double)str_replace(['.', ','], '', $str); }

try {
    switch ($action) {
        
        // ========================================================
        // 🚀 CRUD TEMPLATE FORM DINAMIS
        // ========================================================
        case 'save_template':
            $id = (int)($_POST['id'] ?? 0);
            $nama = $conn->real_escape_string($_POST['nama_template']);
            $layout_json = $conn->real_escape_string($_POST['custom_layout']);
            
            if ($id > 0) $q = "UPDATE honor_template SET nama_template='$nama', custom_layout='$layout_json' WHERE id=$id";
            else $q = "INSERT INTO honor_template (nama_template, custom_layout) VALUES ('$nama', '$layout_json')";
            
            if($conn->query($q)) echo json_encode(['status' => 'success', 'message' => 'Template Form berhasil disimpan!']);
            else echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan template.']);
            break;

        case 'delete_template':
            $id = (int)$_POST['id'];
            $conn->query("DELETE FROM honor_template WHERE id=$id");
            echo json_encode(['status' => 'success', 'message' => 'Template berhasil dihapus.']);
            break;

        // ========================================================
        // 🚀 CRUD DOSEN & KOMPONEN
        // ========================================================
        case 'save_dosen':
            $id = (int)($_POST['id'] ?? 0);
            $nip = $conn->real_escape_string($_POST['nip']); $nama = $conn->real_escape_string($_POST['nama']); $email = $conn->real_escape_string($_POST['email']); $hp = $conn->real_escape_string($_POST['no_hp']); $pend = $conn->real_escape_string($_POST['pendidikan_terakhir']); $jab = $conn->real_escape_string($_POST['jabatan_fungsional']); $gol = $conn->real_escape_string($_POST['golongan']); $prodi = $conn->real_escape_string($_POST['program_studi']); $stat = $conn->real_escape_string($_POST['status']); $bank = $conn->real_escape_string($_POST['nama_bank']); $rek = $conn->real_escape_string($_POST['no_rekening']); $pemilik = $conn->real_escape_string($_POST['pemilik_rekening']);
            if ($id > 0) $q = "UPDATE dosen SET nip='$nip', nama='$nama', email='$email', no_hp='$hp', pendidikan_terakhir='$pend', jabatan_fungsional='$jab', golongan='$gol', program_studi='$prodi', status='$stat', nama_bank='$bank', no_rekening='$rek', pemilik_rekening='$pemilik' WHERE id=$id";
            else $q = "INSERT INTO dosen (nip, nama, email, no_hp, pendidikan_terakhir, jabatan_fungsional, golongan, program_studi, status, nama_bank, no_rekening, pemilik_rekening) VALUES ('$nip', '$nama', '$email', '$hp', '$pend', '$jab', '$gol', '$prodi', '$stat', '$bank', '$rek', '$pemilik')";
            if($conn->query($q)) echo json_encode(['status'=>'success', 'message'=>'Data Dosen berhasil disimpan!']);
            else echo json_encode(['status'=>'error', 'message'=>'Gagal menyimpan.']);
            break;

        case 'delete_dosen':
            $id = (int)$_POST['id']; $conn->query("DELETE FROM dosen WHERE id=$id"); echo json_encode(['status'=>'success', 'message'=>'Data dihapus!']);
            break;

        case 'save_komp':
            $id = (int)($_POST['id'] ?? 0);
            $kode = $conn->real_escape_string($_POST['kode_honor']);
            $nama = $conn->real_escape_string($_POST['nama_honor']);
            $desc = $conn->real_escape_string($_POST['deskripsi']);
            $coa_beban = $conn->real_escape_string($_POST['kode_akun_beban']);
            $stat = isset($_POST['is_active']) ? 1 : 0;
            $is_jafung = isset($_POST['is_jafung']) ? 1 : 0;
            
            $conn->begin_transaction();
            try {
                if ($id > 0) {
                    $conn->query("UPDATE honor_komponen SET nama_honor='$nama', deskripsi='$desc', kode_akun_beban='$coa_beban', is_active=$stat, is_jafung=$is_jafung WHERE id=$id");
                    $komp_id = $id;
                    $conn->query("DELETE FROM honor_komponen_detail WHERE komponen_id=$komp_id");
                } else {
                    $conn->query("INSERT INTO honor_komponen (kode_honor, nama_honor, deskripsi, kode_akun_beban, is_active, is_jafung) VALUES ('$kode', '$nama', '$desc', '$coa_beban', $stat, $is_jafung)");
                    $komp_id = $conn->insert_id;
                }

                $rincianArr = $_POST['rincian'] ?? [];
                for ($i = 0; $i < count($rincianArr); $i++) {
                    if (empty(trim($rincianArr[$i]))) continue;
                    $rinc = $conn->real_escape_string($rincianArr[$i]);
                    $sat = $conn->real_escape_string($_POST['satuan'][$i]);
                    $jf = $is_jafung ? $conn->real_escape_string($_POST['jafung'][$i] ?? '') : '';
                    $pjk = (double)$_POST['pajak'][$i];
                    $bsr = cleanRpLcl($_POST['besaran'][$i]);
                    
                    $conn->query("INSERT INTO honor_komponen_detail (komponen_id, rincian, jabatan_fungsional, satuan, besaran, potongan_pajak) VALUES ($komp_id, '$rinc', '$jf', '$sat', $bsr, $pjk)");
                }
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'Komponen berhasil disimpan!']);
            } catch (Exception $e) { $conn->rollback(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
            break;

        case 'delete_komp':
            $id = (int)$_POST['id']; $conn->query("DELETE FROM honor_komponen WHERE id=$id"); echo json_encode(['status' => 'success', 'message' => 'Komponen dihapus.']);
            break;

        // ========================================================
        // 🚀 CRUD GENERATE HONOR (THE MATRIX PARSER)
        // ========================================================
        case 'init_generate':
            $nama = $conn->real_escape_string($_POST['nama']);
            $tpl_id = (int)($_POST['template_id'] ?? 0);
            $bulan = (int)$_POST['bulan'];
            $tahun = (int)$_POST['tahun'];
            $kode = "GEN-{$tahun}-" . time();
            $catatan = $conn->real_escape_string($_POST['catatan'] ?? '');
            
            if($conn->query("INSERT INTO honor_generate (kode_generate, nama_generate, template_id, periode_bulan, periode_tahun, tanggal_generate, catatan, status, created_by) VALUES ('$kode', '$nama', $tpl_id, $bulan, $tahun, NOW(), '$catatan', 'Draft', $uid)")) {
                echo json_encode(['status'=>'success', 'id'=>$conn->insert_id]);
            } else { echo json_encode(['status'=>'error', 'message'=>'Gagal membuat batch: '.$conn->error]); }
            break;
            
        case 'edit_generate_header':
            $id = (int)$_POST['id'];
            $nama = $conn->real_escape_string($_POST['nama']);
            $tpl_id = (int)($_POST['template_id'] ?? 0);
            $bulan = (int)$_POST['bulan'];
            $tahun = (int)$_POST['tahun'];
            
            if($conn->query("UPDATE honor_generate SET nama_generate='$nama', template_id=$tpl_id, periode_bulan=$bulan, periode_tahun=$tahun WHERE id=$id")) {
                echo json_encode(['status'=>'success', 'message'=>'Header Generate Honor berhasil diperbarui.']);
            } else { echo json_encode(['status'=>'error', 'message'=>'Gagal update: '.$conn->error]); }
            break;

        case 'save_generate_detail':
            $gen_id = (int)$_POST['generate_id'];
            $is_final = isset($_POST['finalize']) && $_POST['finalize'] == 1 ? 1 : 0;
            
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM honor_generate_detail WHERE generate_id = $gen_id");
                
                $dosen_ids = $_POST['dosen_id'] ?? [];
                $rincian_ids = $_POST['rincian_ids'] ?? [];
                $pajak_pcts = $_POST['pajak_pct'] ?? [];
                $total_honor_all = 0;
                
                for($i=0; $i<count($dosen_ids); $i++) {
                    $did = (int)$dosen_ids[$i];
                    if($did == 0) continue;
                    
                    $pajak = (double)($pajak_pcts[$i] ?? 0);
                    $prodi = $conn->real_escape_string($_POST['teks_prodi'][$i] ?? '');
                    $mk = $conn->real_escape_string($_POST['teks_mata_kuliah'][$i] ?? '');
                    
                    // Loop komponen matrix per dosen
                    if (!empty($rincian_ids)) {
                        foreach ($rincian_ids as $rid) {
                            $qty = (double)($_POST["komp_qty_{$rid}"][$i] ?? 0);
                            if ($qty <= 0) continue; // Abaikan jika 0
                            
                            $tarif = cleanRpLcl($_POST["komp_tarif_{$rid}"][$i] ?? 0);
                            $kid = (int)($_POST["komp_kompId_{$rid}"][$i] ?? 0);
                            
                            $bruto = $qty * $tarif;
                            $potongan = $bruto * ($pajak / 100);
                            $netto = $bruto - $potongan;
                            $total_honor_all += $netto;
                            
                            $conn->query("INSERT INTO honor_generate_detail (generate_id, dosen_id, prodi, komponen_id, mata_kuliah, rincian_komponen_id, qty, tarif, total_honor, persen_pajak, potongan_pajak, honor_diterima) VALUES ($gen_id, $did, '$prodi', $kid, '$mk', $rid, $qty, $tarif, $bruto, $pajak, $potongan, $netto)");
                        }
                    }
                }
                
                $status = $is_final ? 'Final' : 'Draft';
                $conn->query("UPDATE honor_generate SET total_honor = $total_honor_all, status = '$status' WHERE id = $gen_id");
                
                $conn->commit();
                echo json_encode(['status'=>'success', 'message'=> $is_final ? 'Data berhasil difinalisasi. Slip honor telah terbit!' : 'Draf berhasil disimpan.']);
            } catch(Exception $e) { $conn->rollback(); echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]); }
            break;

        case 'delete_generate':
            $id = (int)$_POST['id'];
            $conn->query("DELETE FROM honor_generate WHERE id=$id"); 
            echo json_encode(['status'=>'success', 'message'=>'Draft Generate Honor berhasil dihapus permanen.']);
            break;

        case 'batal_generate':
            $id = (int)$_POST['id'];
            $conn->query("UPDATE honor_generate SET status='Draft' WHERE id=$id");
            $conn->query("UPDATE honor_generate_detail SET status_bayar='Belum Dibayar', jurnal_id=NULL WHERE generate_id=$id");
            echo json_encode(['status'=>'success', 'message'=>'Generate dibatalkan. Mode draf terbuka kembali.']);
            break;

        // ========================================================
        // 🚀 TRUE INTEGRATION: PEMBAYARAN SLIP KE BUKU BESAR
        // Perbaikan: jenis_transaksi = 'Pengeluaran' agar tercatat di Manajemen Kas & Bank
        // ========================================================
        case 'bayar_slip':
            $slip_ids = $_POST['slip_ids'] ?? []; 
            if(empty($slip_ids)) { echo json_encode(['status'=>'error', 'message'=>'Pilih minimal 1 slip untuk dibayar.']); exit; }

            $kas_akun = $conn->real_escape_string($_POST['kas_akun']);
            $tgl_bayar = $conn->real_escape_string($_POST['tgl_bayar']);
            $ref = $conn->real_escape_string($_POST['referensi'] ?? "BKK-HON/".date('Ymd/His'));

            $ids_str = implode(',', array_map('intval', $slip_ids));
            
            $res_slips = $conn->query("SELECT d.*, ds.nama as dosen_nama FROM honor_generate_detail d JOIN dosen ds ON d.dosen_id=ds.id WHERE d.id IN ($ids_str) AND d.status_bayar != 'Sudah Dibayar'");
            $total_bayar = 0; $rincian_ket = [];
            while($s = $res_slips->fetch_assoc()) {
                $total_bayar += (double)$s['honor_diterima'];
                if(!in_array($s['dosen_nama'], $rincian_ket)) $rincian_ket[] = $s['dosen_nama'];
            }
            if($total_bayar <= 0) { echo json_encode(['status'=>'error', 'message'=>'Slip terpilih sudah dibayar atau nominal 0.']); exit; }

            $ket_jurnal = "Pembayaran Honorarium a.n: " . implode(', ', array_slice($rincian_ket, 0, 3)) . (count($rincian_ket) > 3 ? " dkk." : "");

            $conn->begin_transaction();
            try {
                // 🚀 BUKU BESAR: Gunakan status 'APPROVED' dan jenis_transaksi 'Pengeluaran'
                $stmt_j = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, jenis_transaksi, keterangan, pihak_nama, total_debet, total_kredit, status, akun_utama_kode, user_id, is_deleted) VALUES (?, ?, 'Pengeluaran', ?, 'Multi Dosen', ?, ?, 'APPROVED', ?, ?, 0)");
                $stmt_j->bind_param("sssddsi", $ref, $tgl_bayar, $ket_jurnal, $total_bayar, $total_bayar, $kas_akun, $uid);
                $stmt_j->execute();
                $jid = $conn->insert_id;

                // Masukkan Kas di Posisi Kredit 
                $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit) VALUES ($jid, '$kas_akun', 0, $total_bayar)");

                // PEMECAHAN OTOMATIS COA BEBAN BERDASARKAN KOMPONEN (DEBIT)
                $sql_group = "SELECT k.kode_akun_beban, SUM(d.honor_diterima) as tot_beban
                              FROM honor_generate_detail d
                              LEFT JOIN honor_komponen k ON d.komponen_id = k.id
                              WHERE d.id IN ($ids_str) AND d.status_bayar != 'Sudah Dibayar'
                              GROUP BY k.kode_akun_beban";
                $res_group = $conn->query($sql_group);
                while($rg = $res_group->fetch_assoc()) {
                    $coa_b = !empty($rg['kode_akun_beban']) ? $rg['kode_akun_beban'] : '5-1000'; // Default Beban
                    $nom_b = (double)$rg['tot_beban'];
                    $conn->query("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit) VALUES ($jid, '$coa_b', $nom_b, 0)");
                }

                // UPDATE STATUS SLIP
                $conn->query("UPDATE honor_generate_detail SET status_bayar='Sudah Dibayar', jurnal_id=$jid WHERE id IN ($ids_str)");
                
                // AUTO UPDATE HEADER JIKA SEMUA LUNAS
                $conn->query("UPDATE honor_generate g SET status='Dibayarkan' WHERE id IN (SELECT generate_id FROM honor_generate_detail WHERE id IN ($ids_str)) AND NOT EXISTS (SELECT 1 FROM honor_generate_detail d2 WHERE d2.generate_id = g.id AND d2.status_bayar != 'Sudah Dibayar')");

                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => "Pembayaran berhasil! Jurnal otomatis terekam di Transaksi Kas & Bank."]);
            } catch (Exception $e) {
                $conn->rollback(); echo json_encode(['status' => 'error', 'message' => 'Gagal posting jurnal: ' . $e->getMessage()]);
            }
            break;

        case 'batal_bayar':
            $slip_ids = $_POST['slip_ids'] ?? ''; 
            if(empty($slip_ids)) { echo json_encode(['status'=>'error', 'message'=>'ID Slip Kosong.']); exit; }

            $conn->begin_transaction();
            try {
                $res_jid = $conn->query("SELECT DISTINCT jurnal_id FROM honor_generate_detail WHERE id IN ($slip_ids) AND jurnal_id IS NOT NULL");
                if($res_jid) {
                    while($r = $res_jid->fetch_assoc()) {
                        $jid = (int)$r['jurnal_id'];
                        $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id = $jid");
                        $conn->query("DELETE FROM syifa_jurnal WHERE id = $jid");
                    }
                }
                
                $conn->query("UPDATE honor_generate_detail SET status_bayar='Belum Dibayar', jurnal_id=NULL WHERE id IN ($slip_ids)");
                $conn->query("UPDATE honor_generate g SET status='Final' WHERE id IN (SELECT generate_id FROM honor_generate_detail WHERE id IN ($slip_ids))");

                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => "Pembayaran Kasir dibatalkan. Jurnal Buku Besar otomatis dihapus (Rollback)."]);
            } catch (Exception $e) {
                $conn->rollback(); echo json_encode(['status' => 'error', 'message' => 'Gagal Rollback: ' . $e->getMessage()]);
            }
            break;

        // ========================================================
        // 🚀 FITUR KIRIM EMAIL DOSEN
        // ========================================================
        case 'kirim_email':
            $ids_str = $conn->real_escape_string($_POST['slip_ids'] ?? '');
            $email = $conn->real_escape_string($_POST['email'] ?? '');
            $subject = $conn->real_escape_string($_POST['subject'] ?? 'Pemberitahuan Honorarium - STIKes Yarsi');
            $pesan = $_POST['pesan'] ?? '';
            
            if(empty($ids_str) || empty($email)) { echo json_encode(['status'=>'error', 'message'=>'Email atau Slip Kosong.']); exit; }
            
            $status_email = kirim_email_smtp($conn, $email, 'Dosen', $subject, $pesan);
            
            if ($status_email) {
                $conn->query("UPDATE honor_generate_detail SET status_kirim='Sudah Dikirim' WHERE id IN ($ids_str)");
                echo json_encode(['status' => 'success', 'message' => "Slip Honor berhasil dikirimkan ke Email $email!"]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "Gagal mengirim ke SMTP. Pastikan setting Email di Pengaturan Sistem sudah benar."]);
            }
            break;

        default: echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid!']); break;
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
```

```php:Cetak Pengajuan & Slip Kuitansi:print_slip_honor.php
<?php
/**
 * print_slip_honor.php - THE SUPREME PDF PRINTER (DYNAMIC GROUPING)
 * Fungsi: Merender Kuitansi dan Laporan Pengajuan.
 * Perbaikan Mutlak: 
 * 1. Jika di print dari Tab Generate (Mode Pengajuan), maka 1 tabel per 1 jenis honor.
 * 2. Jika di print dari Tab Slip (Mode Kuitansi), maka 1 Kuitansi per 1 Dosen.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { die("Akses Ditolak: Silakan login terlebih dahulu."); }

$mode = $_GET['mode'] ?? 'slip'; 
$ids_str = $conn->real_escape_string($_GET['detail_ids'] ?? '');
$gen_id = (int)($_GET['gen_id'] ?? 0);

$where = "1=0";
if ($mode == 'slip' && !empty($ids_str)) {
    $where = "d.id IN ($ids_str)";
} else if ($mode == 'pengajuan' && $gen_id > 0) {
    $where = "d.generate_id = $gen_id";
} else {
    die("<h3 style='padding: 50px;'>Parameter tidak valid.</h3>");
}

$sql = "SELECT d.*, g.nama_generate, g.kode_generate, g.periode_bulan, g.periode_tahun, 
        ds.nama as dosen_nama, ds.jabatan_fungsional as jabatan, ds.program_studi as default_prodi,
        hk.nama_honor as nama_komponen, hkd.rincian as nama_rincian, hkd.satuan
        FROM honor_generate_detail d
        JOIN honor_generate g ON d.generate_id = g.id
        JOIN dosen ds ON d.dosen_id = ds.id
        LEFT JOIN honor_komponen hk ON d.komponen_id = hk.id
        LEFT JOIN honor_komponen_detail hkd ON d.rincian_komponen_id = hkd.id
        WHERE $where ORDER BY hk.id ASC, d.id ASC";
$res = $conn->query($sql);

$details = [];
$dosen = [];
$t_bruto = 0; $t_pajak = 0;

$nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];

while($r = $res->fetch_assoc()) {
    if ($mode == 'pengajuan') {
        // GROUPING BERDASARKAN JENIS KOMPONEN (HONOR)
        $nama_gen_clean = strtoupper($r['nama_komponen'] ?: $r['nama_generate']);
    } else {
        // GROUPING BERDASARKAN NAMA DOSEN (KUITANSI)
        $nama_gen_clean = strtoupper($r['dosen_nama']);
    }
    
    $details[$nama_gen_clean][] = $r;
    
    if (empty($dosen) && $mode == 'slip') {
        $dosen = [ 'nama' => $r['dosen_nama'], 'jabatan' => $r['jabatan'], 'periode' => $nm_bln[$r['periode_bulan']] . ' ' . $r['periode_tahun'] ];
    }
    
    if ($mode == 'pengajuan') {
        $dosen = [ 'nama' => 'PENGELOLA KEUANGAN', 'jabatan' => 'Bendahara', 'periode' => $nm_bln[$r['periode_bulan']] . ' ' . $r['periode_tahun'] ];
    }
    
    $t_bruto += (double)$r['total_honor'];
    $t_pajak += (double)$r['potongan_pajak'];
}
$t_netto = $t_bruto - $t_pajak;

function penyebut($nilai) {
    $nilai = abs($nilai);
    $huruf = array("", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas");
    $temp = "";
    if ($nilai < 12) { $temp = " ". $huruf[$nilai]; }
    else if ($nilai <20) { $temp = penyebut($nilai - 10). " Belas"; }
    else if ($nilai < 100) { $temp = penyebut($nilai/10)." Puluh". penyebut($nilai % 10); }
    else if ($nilai < 200) { $temp = " Seratus" . penyebut($nilai - 100); }
    else if ($nilai < 1000) { $temp = penyebut($nilai/100) . " Ratus" . penyebut($nilai % 100); }
    else if ($nilai < 2000) { $temp = " Seribu" . penyebut($nilai - 1000); }
    else if ($nilai < 1000000) { $temp = penyebut($nilai/1000) . " Ribu" . penyebut($nilai % 1000); }
    else if ($nilai < 1000000000) { $temp = penyebut($nilai/1000000) . " Juta" . penyebut($nilai % 1000000); }
    return $temp;
}
function terbilang($nilai) {
    if($nilai<0) $hasil = "minus ". trim(penyebut($nilai)); else $hasil = trim(penyebut($nilai));
    return ucwords($hasil) . " Rupiah";
}

$profile = $conn->query("SELECT * FROM system_profile WHERE id=1")->fetch_assoc();
$inst_name = $profile['institution_name'] ?? 'STIKes YARSI PONTIANAK';

// CUSTOM TEMPLATE BUILDER DARI SETTING FORM
$layout_json = $conn->query("SELECT custom_layout FROM honor_template ORDER BY id ASC LIMIT 1")->fetch_row()[0] ?? '[]';
$layout_cols = json_decode($layout_json, true) ?: [];

if (empty($layout_cols)) {
    $layout_cols = [
        ['type'=>'teks', 'label'=>'PRODI', 'source'=>'prodi'],
        ['type'=>'teks', 'label'=>'MATA KULIAH', 'source'=>'mata_kuliah'],
        ['type'=>'teks', 'label'=>'TENAGA PENGAJAR', 'source'=>'dosen_nama'],
        ['type'=>'qty', 'label'=>'QTY / VOL'],
        ['type'=>'tarif', 'label'=>'TARIF DASAR']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Dokumen Laporan</title>
    <style>
        @page { size: A4 landscape; margin: 15mm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 10pt; color: #000; margin: 0; background: #525659; }
        .a4-landscape { background: #fff; width: 297mm; min-height: 210mm; margin: 0 auto; padding: 15mm; box-shadow: 0 10px 30px rgba(0,0,0,0.5); box-sizing: border-box; }
        
        .doc-title { text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 2mm; text-transform: uppercase; }
        .doc-sub { text-align: center; font-size: 11pt; margin-bottom: 5mm; }
        .info-table { width: 100%; margin-bottom: 5mm; font-size: 11pt; line-height: 1.6; }
        
        .tbl-data { width: 100%; border-collapse: collapse; margin-bottom: 8mm; font-size: 10pt; }
        .tbl-data th, .tbl-data td { border: 1px solid #000; padding: 6px; vertical-align: middle; }
        .tbl-data th { background-color: #f1f5f9 !important; text-transform: uppercase; font-size: 9pt; text-align: center; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-weight: bold; }
        
        .group-title { font-size: 11pt; font-weight: bold; text-transform: uppercase; margin-bottom: 2mm; margin-top: 5mm; color: #000;}

        .text-center { text-align: center; } .text-end { text-align: right; } .fw-bold { font-weight: bold; }
        
        @media print {
            body { background: #fff; padding: 0; margin: 0; }
            .a4-landscape { box-shadow: none; margin: 0; width: 100%; height: auto; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body onload="setTimeout(window.print, 500)">
    <div style="text-align: center; margin: 20px 0;" class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #0d6efd; color: #fff; border: none; border-radius: 5px; font-weight: bold;">🖨️ CETAK DOKUMEN</button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background: #dc3545; color: #fff; border: none; border-radius: 5px; font-weight: bold; margin-left: 10px;">TUTUP</button>
    </div>

    <div class="a4-landscape">
        
        <?php if($mode == 'slip'): ?>
            <div class="doc-title">KUITANSI PEMBAYARAN</div>
            <div class="doc-sub">Pada hari ini <?= date('l') ?>, tanggal <?= date('d F Y') ?>, telah ditransfer honor <?= strtolower($dosen['periode'] ?? '') ?></div>
            
            <table class="info-table">
                <tr><td width="150">Dari</td><td width="10">:</td><td>Bendahara Institusi / Pengelola Keuangan</td></tr>
                <tr><td>Kepada</td><td>:</td><td class="fw-bold fs-5"><?= htmlspecialchars($dosen['nama'] ?? '') ?></td></tr>
                <tr><td>Nominal</td><td>:</td><td class="fw-bold">Rp <?= number_format($t_netto, 0, ',', '.') ?></td></tr>
                <tr><td>Sejumlah</td><td>:</td><td class="fw-bold" style="font-style: italic;"><?= terbilang($t_netto) ?></td></tr>
                <tr><td>Dengan rincian sbb</td><td>:</td><td></td></tr>
            </table>
        <?php else: ?>
            <!-- JIKA MODE PENGAJUAN / LAPORAN -->
            <table width="100%" style="border-bottom: 3px solid #000; margin-bottom: 5mm;">
                <tr><td width="100%" style="text-align:center; padding-bottom:5px;">
                    <div style="font-size:16pt; font-weight:bold; text-transform:uppercase;"><?= htmlspecialchars($inst_name) ?></div>
                    <div style="font-size:11pt; margin-top:2px;">LAPORAN PENGAJUAN HONORARIUM DOSEN</div>
                </td></tr>
            </table>
            <div class="doc-sub fw-bold" style="text-align: left;">
                Periode Laporan: <?= strtoupper($dosen['periode'] ?? '') ?>
            </div>
        <?php endif; ?>
        
        <!-- 🚀 TABEL DINAMIS PER TEMPLATE -->
        <?php foreach($details as $nama_gen => $items): ?>
            <div class="group-title"><?= htmlspecialchars($nama_gen) ?></div>
            <table class="tbl-data">
                <thead>
                    <tr>
                        <th width="3%">No</th>
                        <?php foreach($layout_cols as $c) {
                            $col_name = $c['label'];
                            if ($c['type'] == 'qty') $col_name = "JUMLAH " . $c['label'];
                            elseif ($c['type'] == 'tarif') $col_name = "BESARAN Rp / " . $c['label'];
                            echo "<th>" . strtoupper($col_name) . "</th>";
                        } ?>
                        <th width="10%">TOTAL BRUTO</th>
                        <th width="8%">POT. PAJAK</th>
                        <th width="12%">HONOR DITERIMA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sub_bruto = 0; $sub_pajak = 0; $sub_netto = 0; $n = 1;
                    foreach($items as $i): 
                        $sub_bruto += (double)$i['total_honor']; $sub_pajak += (double)$i['potongan_pajak']; $sub_netto += (double)$i['honor_diterima'];
                    ?>
                    <tr>
                        <td class="text-center"><?= $n++ ?></td>
                        <?php foreach($layout_cols as $c): 
                            if ($c['type'] == 'teks') {
                                $src = $c['source'];
                                $val = ($src == 'prodi' && empty($i['prodi'])) ? $i['default_prodi'] : ($i[$src] ?? '-');
                                echo "<td>" . htmlspecialchars($val) . "</td>";
                            } elseif ($c['type'] == 'qty') {
                                echo "<td class='text-center fw-bold'>" . (float)$i['qty'] . "</td>";
                            } elseif ($c['type'] == 'tarif') {
                                echo "<td class='text-end'>" . number_format($i['tarif'], 0, ',', '.') . "</td>";
                            }
                        endforeach; ?>
                        
                        <td class="text-end"><?= number_format($i['total_honor'], 0, ',', '.') ?></td>
                        <td class="text-end text-danger"><?= (float)$i['persen_pajak'] ?>%</td>
                        <td class="text-end fw-bold"><?= number_format($i['honor_diterima'], 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="fw-bold" style="background-color: #f8fafc; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
                    <tr>
                        <td colspan="<?= count($layout_cols) + 1 ?>" class="text-end">SUBTOTAL <?= htmlspecialchars($nama_gen) ?></td>
                        <td class="text-end"><?= number_format($sub_bruto, 0, ',', '.') ?></td>
                        <td class="text-end text-danger">- <?= number_format($sub_pajak, 0, ',', '.') ?></td>
                        <td class="text-end text-success">Rp <?= number_format($sub_netto, 0, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endforeach; ?>

        <!-- GRAND TOTAL KESELURUHAN -->
        <table class="tbl-data" style="margin-top: 10mm; border: 2px solid #000;">
            <tr>
                <td class="text-end fw-bold" style="font-size: 13pt; padding: 10px;">TOTAL KESELURUHAN <?= $mode == 'slip' ? 'DITERIMA' : 'DIAJUKAN' ?> (NETTO)</td>
                <td class="text-end fw-bold" style="font-size: 15pt; width: 250px; color: #15803d; padding: 10px;">Rp <?= number_format($t_netto, 0, ',', '.') ?></td>
            </tr>
        </table>
        
        <table style="width: 100%; margin-top: 15mm; font-size: 11pt; text-align: center; font-weight: bold; page-break-inside: avoid; border: none;">
            <tr>
                <td width="33%" style="border: none;">Mengetahui/Menyetujui,<br>Ketua/Direktur<br><br><br><br><br><br>( _______________________ )</td>
                <td width="33%" style="border: none;"></td>
                <td width="33%" style="border: none;">Pontianak, <?= date('d F Y') ?><br>Penerima,<br><br><br><br><br><br>( <?= htmlspecialchars($dosen['nama'] ?? '____________________') ?> )</td>
            </tr>
        </table>
    </div>
</body>
</html>

```

```php:Generate Honorarium:honorarium_generate.php
<?php
/**
 * honorarium_generate.php - TAB 3: GENERATE HONOR (JANTUNG SISTEM)
 * Perbaikan Mutlak: 
 * 1. Tombol Aksi di Generate (Draft, Final, Dibayarkan) disesuaikan.
 * 2. Tarif Dasar (Besaran Honor) diubah menjadi READONLY mutlak dan dilatarabukan.
 * 3. Form Submission dikawal ketat oleh Vanilla JS.
 */
$view_mode = $_GET['view'] ?? 'list';
$gen_id = (int)($_GET['id'] ?? 0);

$generate_list = [];
$res_gen = $conn->query("SELECT * FROM honor_generate ORDER BY id DESC");
if($res_gen) while($r = $res_gen->fetch_assoc()) $generate_list[] = $r;

if ($view_mode == 'detail' && $gen_id > 0) {
    $gen_head = $conn->query("SELECT * FROM honor_generate WHERE id = $gen_id")->fetch_assoc();
    $gen_details = [];
    $res_det = $conn->query("SELECT d.*, ds.nama as dosen_nama, ds.nip FROM honor_generate_detail d LEFT JOIN dosen ds ON d.dosen_id = ds.id WHERE d.generate_id = $gen_id ORDER BY d.id ASC");
    if($res_det) while($r = $res_det->fetch_assoc()) $gen_details[] = $r;
    
    $dosen_list = $conn->query("SELECT id, nama, nip, program_studi FROM dosen WHERE status='Aktif' ORDER BY nama ASC")->fetch_all(MYSQLI_ASSOC);
    $prodi_list = [];
    $res_prodi = $conn->query("SELECT nama_prodi FROM mhs_prodi ORDER BY nama_prodi ASC");
    if($res_prodi) { while($r = $res_prodi->fetch_assoc()) $prodi_list[] = $r['nama_prodi']; }

    $rincian_komponen = [];
    $res_rinc = $conn->query("SELECT * FROM honor_komponen_detail ORDER BY id ASC");
    while($rin = $res_rinc->fetch_assoc()) $rincian_komponen[$rin['komponen_id']][] = $rin;
    
    $master_komponen = $conn->query("SELECT * FROM honor_komponen WHERE is_active=1 ORDER BY nama_honor ASC")->fetch_all(MYSQLI_ASSOC);
}
?>

<style>
    .table-gen { min-width: 1800px; }
    .table-gen th { background-color: #f8fafc !important; color: #475569 !important; font-size: 11px; text-transform: uppercase; padding: 12px 8px; border: 1px solid #e2e8f0; text-align: center; vertical-align: middle;}
    .table-gen td { font-size: 13px; vertical-align: middle; padding: 8px; border: 1px solid #f1f5f9; color: #1e293b; }
    .inp-gen { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; font-size: 12px; font-weight: 600; width: 100%; transition: 0.3s; }
    .inp-gen:focus { border-color: var(--bs-primary); box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1); outline: none; }
    
    /* MENGHILANGKAN SPINNER PADA INPUT TYPE NUMBER & READONLY STYLE */
    input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
    .inp-nom[readonly], .inp-tarif[readonly] { background-color: #f1f5f9 !important; color: #64748b !important; cursor: not-allowed; border-style: dashed; }
    .inp-nom:not([readonly]) { text-align: right; color: var(--bs-primary); background: #f8fafc; }
    
    .btn-action { width: 28px; height: 28px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; font-size: 12px;}
    .btn-action:hover { transform: scale(1.1); }
</style>

<div class="animate__animated animate__fadeIn">
<?php if ($view_mode == 'list'): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0 text-dark">Daftar Generate Honor</h5>
        <button class="btn btn-primary rounded-pill shadow-sm px-4 fw-bold" onclick="showModalGenerate()"><i class="fas fa-plus me-2"></i>Buat Batch Generate Baru</button>
    </div>

    <div class="card border-0 bg-white rounded-4 shadow-sm border">
        <div class="table-responsive p-3">
            <table class="table table-hover table-gen mb-0 text-center" style="min-width: 100%;">
                <thead><tr><th class="text-start ps-3">Kode Batch</th><th class="text-start">Nama Batch (Generate)</th><th>Periode</th><th>Status</th><th class="text-end pe-3">Total Honor</th><th width="140">Aksi</th></tr></thead>
                <tbody>
                    <?php if(empty($generate_list)): ?><tr><td colspan="6" class="text-center py-5 text-muted fst-italic">Belum ada data batch generate honor.</td></tr><?php endif; ?>
                    <?php foreach($generate_list as $g): 
                        $b_cls = match($g['status']) { 'Final'=>'bg-success', 'Dibayarkan'=>'bg-primary', default=>'bg-warning text-dark' };
                        $nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
                    ?>
                    <tr>
                        <td class="ps-3 text-start"><code class="text-dark bg-light px-2 py-1 rounded border fw-bold"><?= $g['kode_generate'] ?></code></td>
                        <td class="text-start fw-bold text-primary"><?= $g['nama_generate'] ?></td>
                        <td class="fw-bold text-muted"><?= $nm_bln[$g['periode_bulan']] . ' ' . $g['periode_tahun'] ?></td>
                        <td><span class="badge <?= $b_cls ?> rounded-pill px-3 py-1"><?= $g['status'] ?></span></td>
                        <td class="text-end pe-3 fw-bold text-success">Rp <?= number_format($g['total_honor'], 0, ',', '.') ?></td>
                        <td>
                            <div class="d-flex justify-content-center gap-1">
                                <?php if($g['status'] == 'Draft'): ?>
                                    <a href="?page=honorarium&tab=generate&view=detail&id=<?= $g['id'] ?>" class="btn-action btn btn-light border text-primary shadow-sm" title="Susun Honor (Detail)"><i class="fas fa-list-ol"></i></a>
                                    <button type="button" class="btn-action btn btn-light border text-warning shadow-sm" title="Edit Judul Batch" onclick='editHeaderGen(<?= json_encode($g, JSON_HEX_APOS) ?>)'><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn-action btn btn-light border text-danger shadow-sm" title="Hapus Permanen" onclick="hapusGenerate(<?= $g['id'] ?>)"><i class="fas fa-trash"></i></button>
                                <?php elseif($g['status'] == 'Final'): ?>
                                    <button type="button" class="btn-action btn btn-light border text-info shadow-sm" title="Lihat Rekap Pengajuan" onclick="window.open('print_slip_honor.php?mode=pengajuan&gen_id=<?= $g['id'] ?>', '_blank')"><i class="fas fa-eye"></i></button>
                                    <button type="button" class="btn-action btn btn-light border text-warning shadow-sm" title="Batalkan Generate & Tarik Slip" onclick="batalGenerate(<?= $g['id'] ?>)"><i class="fas fa-undo"></i></button>
                                <?php elseif($g['status'] == 'Dibayarkan'): ?>
                                    <button type="button" class="btn-action btn btn-light border text-info shadow-sm" title="Lihat Rekap Pengajuan" onclick="window.open('print_slip_honor.php?mode=pengajuan&gen_id=<?= $g['id'] ?>', '_blank')"><i class="fas fa-eye"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL CEGAH REDIRECT -->
    <div class="modal fade" id="modalNewGen" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <form action="javascript:void(0);" id="formNewGen" onsubmit="handleInitGen(event)" class="modal-content border-0 shadow-lg rounded-4 text-dark overflow-hidden">
                <input type="hidden" name="action" id="actionGen" value="init_generate">
                <input type="hidden" name="id" id="editGenId" value="">
                <div class="modal-header p-4 bg-primary text-white border-0">
                    <h5 class="modal-title fw-bold text-white" id="titleGen"><i class="fas fa-cogs me-2 text-warning"></i>Buat Batch Generate Honor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Nama Batch (Generate) <span class="text-danger">*</span></label>
                        <input type="text" name="nama" id="inpNamaGen" class="form-control rounded-3 border fw-bold px-3 py-2" required placeholder="Contoh: Pembayaran Honor Smt Ganjil 2026">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Bulan Periode <span class="text-danger">*</span></label>
                            <select name="bulan" id="inpBlnGen" class="form-select rounded-3 border fw-bold" required>
                                <?php $nb = ["Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"]; foreach($nb as $idx => $b) { if($idx==0) continue; echo "<option value='$idx'>$b</option>"; } ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Tahun Periode <span class="text-danger">*</span></label>
                            <input type="number" name="tahun" id="inpThnGen" class="form-control rounded-3 border fw-bold text-center" value="<?= date('Y') ?>" required>
                        </div>
                    </div>
                    <div class="mb-0" id="boxCatatan">
                        <label class="form-label small fw-bold text-muted">Catatan (Opsional)</label>
                        <textarea name="catatan" id="inpCatatanGen" class="form-control rounded-3 border" rows="2" placeholder="Memo internal..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 bg-white">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm border" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow" id="btnSubmitInitGen">Simpan <i class="fas fa-arrow-right ms-2"></i></button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", () => {
        document.body.appendChild(document.getElementById('modalNewGen'));
    });

    function handleInitGen(e) {
        e.preventDefault(); 
        let btn = document.getElementById('btnSubmitInitGen'); let ori = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...'; btn.disabled = true;
        
        fetch('honorarium_action.php', { method: 'POST', body: new FormData(e.target) }).then(r => r.json()).then(res => {
            if(res.status === 'success') {
                if(document.getElementById('actionGen').value == 'edit_generate_header') {
                    Swal.fire({ icon: 'success', title: 'Tersimpan!', text: res.message, timer: 1500, showConfirmButton: false }).then(() => window.location.reload());
                } else {
                    window.location.href = '?page=honorarium&tab=generate&view=detail&id=' + res.id;
                }
            } else { Swal.fire('Gagal', res.message, 'error'); btn.innerHTML = ori; btn.disabled = false; }
        }).catch(e => { Swal.fire('Error', 'Terputus dari server', 'error'); btn.innerHTML = ori; btn.disabled = false; });
    }
        
    function showModalGenerate() { 
        document.getElementById('formNewGen').reset(); document.getElementById('actionGen').value = 'init_generate';
        document.getElementById('boxCatatan').style.display = 'block'; document.getElementById('titleGen').innerHTML = '<i class="fas fa-cogs me-2 text-warning"></i>Buat Batch Generate Honor';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNewGen')).show(); 
    }

    function editHeaderGen(g) {
        document.getElementById('actionGen').value = 'edit_generate_header';
        document.getElementById('editGenId').value = g.id;
        document.getElementById('inpNamaGen').value = g.nama_generate;
        document.getElementById('inpBlnGen').value = g.periode_bulan;
        document.getElementById('inpThnGen').value = g.periode_tahun;
        document.getElementById('boxCatatan').style.display = 'none';
        document.getElementById('titleGen').innerHTML = '<i class="fas fa-edit me-2 text-warning"></i>Edit Batch Generate';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNewGen')).show();
    }

    function batalGenerate(id) {
        Swal.fire({
            title: 'Batalkan Generate?', text: "Menarik kembali semua Slip Honor yang telah diterbitkan dan mengembalikan ke DRAFT.",
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#6c757d', confirmButtonText: 'Ya, Batalkan!'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData(); fd.append('action', 'batal_generate'); fd.append('id', id);
                fetch('honorarium_action.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => {
                    if(res.status == 'success') Swal.fire({ icon: 'success', title: 'Berhasil!', text: res.message, timer: 1500, showConfirmButton: false }).then(() => window.location.reload());
                    else Swal.fire('Ditolak', res.message, 'error');
                });
            }
        });
    }

    function hapusGenerate(id) {
        Swal.fire({
            title: 'Hapus Draf?', text: "Yakin ingin menghapus draf ini secara permanen?",
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#6c757d', confirmButtonText: 'Ya, Hapus!'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData(); fd.append('action', 'delete_generate'); fd.append('id', id);
                fetch('honorarium_action.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res => {
                    if(res.status == 'success') window.location.reload();
                    else Swal.fire('Gagal', res.message, 'error');
                });
            }
        });
    }
    </script>

<?php elseif ($view_mode == 'detail'): 
    $nm_bln = ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    $periode_str = $nm_bln[$gen_head['periode_bulan']] . ' ' . $gen_head['periode_tahun'];
    $is_locked = ($gen_head['status'] != 'Draft');
?>
    <div class="card border border-primary border-4 border-start-0 border-end-0 border-bottom-0 rounded-4 shadow-sm bg-white mb-3">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light" style="border-radius: 16px 16px 0 0;">
            <div>
                <span class="badge <?= $is_locked?'bg-success':'bg-secondary' ?> px-3 py-1 rounded-pill mb-1 fw-bold"><?= strtoupper($gen_head['status']) ?></span>
                <h5 class="fw-bold mb-0 text-dark"><?= $gen_head['nama_generate'] ?></h5>
            </div>
            <div class="text-end">
                <div class="small text-muted fw-bold">Periode: <span class="text-dark"><?= $periode_str ?></span></div>
                <div class="small text-muted fw-bold">Kode Batch: <span class="text-primary"><?= $gen_head['kode_generate'] ?></span></div>
            </div>
        </div>
        <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex gap-2">
                <a href="?page=honorarium&tab=generate" class="btn btn-light border fw-bold rounded-pill shadow-sm"><i class="fas fa-arrow-left me-2"></i>Kembali</a>
                <?php if(!$is_locked): ?>
                <button type="button" class="btn btn-outline-primary fw-bold rounded-pill shadow-sm" onclick="addHonorRow()"><i class="fas fa-plus me-2"></i>Buat Honor (Tambah Baris)</button>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-info fw-bold rounded-pill shadow-sm text-white" onclick="window.open('print_slip_honor.php?mode=pengajuan&gen_id=<?= $gen_id ?>', '_blank')"><i class="fas fa-print me-2"></i>Cetak Pengajuan Rekap</button>
                
                <?php if(!$is_locked): ?>
                <button type="button" class="btn btn-warning fw-bold rounded-pill shadow-sm text-dark" onclick="submitHonorDetail(0)"><i class="fas fa-save me-2"></i>Simpan Draft</button>
                <button type="button" class="btn btn-primary fw-bold rounded-pill shadow-sm" onclick="submitHonorDetail(1)"><i class="fas fa-check-double me-2"></i>Finalisasi & Terbitkan Slip</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- TABEL VERTIKAL LEGA -->
    <form id="formDetailGen" class="card border-0 rounded-4 shadow-sm bg-white overflow-hidden mb-4 border" action="javascript:void(0);" onsubmit="return false;">
        <input type="hidden" name="action" value="save_generate_detail">
        <input type="hidden" name="generate_id" value="<?= $gen_id ?>">
        <input type="hidden" name="finalize" id="inpFinalize" value="0">
        
        <div class="table-responsive" style="min-height: 400px; padding-bottom: 20px;">
            <table class="table table-gen mb-0" id="tblHonorDetail" style="min-width: 2000px;">
                <thead class="table-light">
                    <tr>
                        <th width="40">No</th>
                        <th width="250" class="text-start">TENAGA PENGAJAR</th>
                        <th width="150">PRODI</th>
                        <th width="200">KOMPONEN HONOR (MASTER)</th>
                        <th width="300" class="text-start">RINCIAN PEKERJAAN & KET MATA KULIAH</th>
                        <th width="80">QTY</th>
                        <th width="120">TARIF (Rp)</th>
                        <th width="120">TOTAL BRUTO</th>
                        <th width="80">PAJAK (%)</th>
                        <th width="120">POTONGAN</th>
                        <th width="150" class="text-end pe-4">HONOR DITERIMA</th>
                        <?php if(!$is_locked): ?><th width="80">Aksi</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="honorBody"></tbody>
            </table>
        </div>
    </form>

    <!-- SUMMARY PANEL -->
    <div class="row justify-content-end">
        <div class="col-md-5 col-lg-4">
            <div class="card border-2 rounded-4 shadow-sm bg-light border-primary border-opacity-25">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fas fa-calculator me-2 text-primary"></i>Ringkasan Generate</h6>
                    <div class="d-flex justify-content-between mb-2 small fw-bold">
                        <span class="text-muted">Jumlah Dosen / Baris</span><span class="text-dark" id="sumDosen">0</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 small fw-bold">
                        <span class="text-muted">Total Honor Bruto</span><span class="text-dark" id="sumBruto">Rp 0</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 small fw-bold">
                        <span class="text-danger">Total Potongan Pajak</span><span class="text-danger" id="sumPajak">Rp 0</span>
                    </div>
                    <hr class="my-2 border-secondary opacity-25">
                    <div class="d-flex justify-content-between fs-5 fw-bold">
                        <span class="text-primary">TOTAL BERSIH</span><span class="text-success" id="sumNetto">Rp 0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const isLocked = <?= $is_locked ? 'true' : 'false' ?>;
        const dosenData = <?= json_encode($dosen_list) ?>;
        const masterKomp = <?= json_encode($master_komponen) ?>;
        const rincianKomp = <?= json_encode($rincian_komponen) ?>;
        const dbDetails = <?= json_encode($gen_details) ?>;
        const prodiList = <?= json_encode($prodi_list ?? []) ?>;
        
        let rCount = 0;

        let dosenOpts = '<option value="">-- Dosen --</option>';
        dosenData.forEach(d => { dosenOpts += `<option value="${d.id}" data-prodi="${d.program_studi}">${d.nama}</option>`; });

        let kompOpts = '<option value="">-- Pilih Jenis Honor --</option>';
        masterKomp.forEach(k => { kompOpts += `<option value="${k.id}">${k.nama_honor}</option>`; });

        let prodiOpts = '<option value="">-- Ketik/Pilih --</option>';
        prodiList.forEach(p => { prodiOpts += `<option value="${p}">${p}</option>`; });

        // 🚀 FIX MUTLAK BUG DESIMAL MYSQL (.00)
        function formatRpJS(val) { 
            if(!val || val == 0) return '0'; 
            let strVal = val.toString();
            if (strVal.includes('.')) { strVal = strVal.split('.')[0]; } 
            let clean = strVal.replace(/[^0-9]/g, ''); 
            return clean ? new Intl.NumberFormat('id-ID').format(clean) : '0'; 
        }
        function cleanRp(str) { return parseFloat(str.toString().replace(/[^0-9]/g, '')) || 0; }

        function syncProdi(selDosen, rowId) {
            let opt = selDosen.options[selDosen.selectedIndex];
            if(opt && opt.value !== '') {
                let prodiDosen = opt.getAttribute('data-prodi');
                const pInp = document.querySelector(`#hr_${rowId} .inp-prodi`);
                if(pInp) pInp.value = prodiDosen;
            }
        }

        function cloneDosenRow(id) {
            let selDosen = document.querySelector(`#hr_${id} select[name="dosen_id[]"]`).value;
            let inpProdi = document.querySelector(`#hr_${id} .inp-prodi`).value;
            addHonorRow({dosen_id: selDosen, prodi: inpProdi});
        }

        function addHonorRow(d = null) {
            rCount++;
            let did = d ? d.dosen_id : ''; 
            let prodi = d ? d.prodi : '';
            let kid = d ? d.komponen_id : '';
            let rid = d ? d.rincian_komponen_id : '';
            let matkul = d ? d.mata_kuliah : ''; 
            let qty = d ? d.qty : 0; let tarif = d ? formatRpJS(d.tarif) : '0'; let pajak = d ? d.persen_pajak : 0;
            let readOnly = isLocked ? 'disabled' : '';

            let dOpt = dosenOpts.replace(`value="${did}"`, `value="${did}" selected`);
            let kOpt = kompOpts.replace(`value="${kid}"`, `value="${kid}" selected`);
            
            let prodiHtml = `<input type="text" name="teks_prodi[]" class="inp-gen text-dark rounded-3 inp-prodi" list="dlProdi" value="${prodi}" ${readOnly} placeholder="Nama Prodi...">
                             <datalist id="dlProdi">${prodiOpts}</datalist>`;

            let html = `
            <tr id="hr_${rCount}" class="honor-row">
                <td class="text-center align-middle fw-bold row-no">${rCount}</td>
                <td class="text-start"><select name="dosen_id[]" class="inp-gen text-dark rounded-3" onchange="syncProdi(this, ${rCount})" ${readOnly} required>${dOpt}</select></td>
                <td>${prodiHtml}</td>
                <td><select name="komponen_id[]" class="inp-gen text-dark rounded-3 sel-komp" onchange="loadRincian(this, ${rCount})" ${readOnly} required>${kOpt}</select></td>
                <td>
                    <select name="rincian_id[]" class="inp-gen text-dark rounded-3 sel-rinc mb-1" onchange="syncRincian(this, ${rCount})" ${readOnly} required><option value="">-- Pilih Rincian --</option></select>
                    <input type="text" name="teks_mata_kuliah[]" class="inp-gen text-dark rounded-3" value="${matkul}" ${readOnly} placeholder="Keterangan..." required>
                </td>
                <td><input type="number" name="qty[]" class="inp-gen text-center inp-qty rounded-3" value="${qty}" step="0.01" min="0" ${readOnly} onkeyup="calcRow(${rCount})" onchange="calcRow(${rCount})"></td>
                <td><input type="text" name="tarif[]" class="inp-gen inp-nom inp-tarif rounded-3" value="${tarif}" readonly tabindex="-1"></td>
                <td class="text-end fw-bold align-middle text-dark txt-total">Rp 0</td>
                <td><input type="number" name="pajak_pct[]" class="inp-gen text-center text-danger inp-pajak-pct rounded-3" value="${pajak}" step="0.1" min="0" max="100" ${readOnly} onkeyup="calcRow(${rCount})" onchange="calcRow(${rCount})"></td>
                <td class="text-end fw-bold align-middle text-danger txt-potongan">Rp 0</td>
                <td class="text-end pe-4 fw-bold align-middle fs-6 text-success txt-netto">Rp 0</td>
                ${!isLocked ? `<td class="text-center align-middle">
                    <div class="d-flex justify-content-center gap-1">
                        <button type="button" class="btn-action bg-light border text-primary shadow-sm" onclick="cloneDosenRow(${rCount})" title="Tambah baris untuk dosen ini"><i class="fas fa-plus"></i></button>
                        <button type="button" class="btn-action bg-light border text-danger shadow-sm" onclick="delHonorRow(${rCount})" title="Hapus Baris"><i class="fas fa-times"></i></button>
                    </div>
                </td>` : ''}
            </tr>`;
            
            document.getElementById('honorBody').insertAdjacentHTML('beforeend', html);
            if (kid) { loadRincian(document.querySelector(`#hr_${rCount} .sel-komp`), rCount, rid); }
            calcRow(rCount);
        }

        function loadRincian(sel, rowId, selectedRid = '') {
            let kid = sel.value;
            let rSel = document.querySelector(`#hr_${rowId} .sel-rinc`);
            rSel.innerHTML = '<option value="">-- Pilih Rincian --</option>';
            if(kid && rincianKomp[kid]) {
                rincianKomp[kid].forEach(r => {
                    let isSel = (r.id == selectedRid) ? 'selected' : '';
                    let jfText = r.jabatan_fungsional ? ` [${r.jabatan_fungsional}]` : '';
                    rSel.innerHTML += `<option value="${r.id}" data-tarif="${r.besaran}" data-pajak="${r.potongan_pajak}" ${isSel}>${r.rincian}${jfText} (${r.satuan})</option>`;
                });
            }
        }

        function syncRincian(sel, id) {
            if(sel.selectedIndex <= 0) return;
            let opt = sel.options[sel.selectedIndex];
            document.querySelector(`#hr_${id} .inp-tarif`).value = formatRpJS(opt.getAttribute('data-tarif'));
            document.querySelector(`#hr_${id} .inp-pajak-pct`).value = opt.getAttribute('data-pajak');
            calcRow(id);
        }

        function delHonorRow(id) { document.getElementById('hr_'+id).remove(); reindexRows(); calcSummary(); }
        function reindexRows() { let idx = 1; document.querySelectorAll('#tblHonorDetail .row-no').forEach(td => { td.innerText = idx++; }); }

        function calcRow(id) {
            let row = document.getElementById('hr_'+id); if(!row) return;
            let qty = parseFloat(row.querySelector('.inp-qty').value) || 0;
            let tarif = cleanRp(row.querySelector('.inp-tarif').value);
            let pct = parseFloat(row.querySelector('.inp-pajak-pct').value) || 0;

            let total = qty * tarif; let potongan = total * (pct / 100); let netto = total - potongan;

            row.querySelector('.txt-total').innerText = formatRpJS(total);
            row.querySelector('.txt-potongan').innerText = formatRpJS(potongan);
            row.querySelector('.txt-netto').innerText = formatRpJS(netto);

            if(netto <= 0) row.classList.add('row-zero'); else row.classList.remove('row-zero');
            calcSummary();
        }

        function calcSummary() {
            let sumB = 0; let sumP = 0; let count = 0;
            document.querySelectorAll('.honor-row').forEach(r => { count++; sumB += cleanRp(r.querySelector('.txt-total').innerText); sumP += cleanRp(r.querySelector('.txt-potongan').innerText); });

            document.getElementById('sumDosen').innerText = count + ' Baris';
            document.getElementById('sumBruto').innerText = 'Rp ' + formatRpJS(sumB);
            document.getElementById('sumPajak').innerText = 'Rp ' + formatRpJS(sumP);
            document.getElementById('sumNetto').innerText = 'Rp ' + formatRpJS(sumB - sumP);
        }

        function submitHonorDetail(isFinal) {
            if (document.querySelectorAll('.honor-row').length === 0) { Swal.fire('Ditolak','Minimal harus ada 1 baris perhitungan dosen!','error'); return; }
            document.getElementById('inpFinalize').value = isFinal;
            if (isFinal) {
                Swal.fire({
                    title: 'Finalisasi Generate?', text: "Data yang difinalisasi akan menerbitkan Slip Honor dan tidak bisa diedit lagi.",
                    icon: 'warning', showCancelButton: true, confirmButtonColor: '#0d6efd', cancelButtonColor: '#6c757d', confirmButtonText: 'Ya, Finalisasi!'
                }).then((result) => { if (result.isConfirmed) { executeSubmit(); } });
            } else { executeSubmit(); }
        }

        function executeSubmit() {
            const fd = new FormData(document.getElementById('formDetailGen'));
            let btn = document.querySelector('button[onclick="submitHonorDetail(0)"]');
            if(btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Menyimpan...'; btn.disabled = true; }
            
            fetch('honorarium_action.php', { method: 'POST', body: fd })
            .then(async r => {
                const text = await r.text();
                try { return JSON.parse(text); } 
                catch(e) { throw new Error("JSON Rusak/Server Error."); }
            })
            .then(res => {
                if(res.status == 'success') {
                    Swal.fire({ icon: 'success', title: 'Berhasil', text: res.message, timer: 1500, showConfirmButton: false }).then(() => {
                        if(document.getElementById('inpFinalize').value == 1) window.location.href = '?page=honorarium&tab=slip';
                        else window.location.href = '?page=honorarium&tab=generate&view=detail&id=<?= $gen_id ?>'; 
                    });
                } else { Swal.fire('Gagal', res.message, 'error'); if(btn){ btn.innerHTML = 'Simpan Draft'; btn.disabled = false;} }
            }).catch(e => { Swal.fire('Error', 'Terputus dari server: ' + e.message, 'error'); if(btn){ btn.innerHTML = 'Simpan Draft'; btn.disabled = false;} });
        }

        document.addEventListener('DOMContentLoaded', () => { 
            if(dbDetails.length > 0) { dbDetails.forEach(d => addHonorRow(d)); }
            else if(!isLocked) { addHonorRow(); }
        });
    </script>
<?php endif; ?>
</div>