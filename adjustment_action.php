<?php
/**
 * adjustment_action.php - CONTROLLER JURNAL UMUM & PENYESUAIAN
 * Versi: 3.0 (Smart Sync Module Edition)
 * Deskripsi: Mendukung penyimpanan identitas mahasiswa, tagihan, dan aset pada detail jurnal.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config/koneksi.php';

// Proteksi Keamanan Dasar
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function done($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    $tab = (isset($_POST['jenis_jurnal_input']) && $_POST['jenis_jurnal_input'] == 'penyesuaian') ? 'ajp' : 'umum';
    header("Location: index.php?page=jurnal&tab=$tab");
    exit;
}

if (in_array($action, ['save_ajp', 'save_umum'])) {
    $id    = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $date  = $_POST['date'];
    $desc  = $_POST['desc'];
    $jenis = $_POST['jenis_jurnal_input'] ?? 'umum'; 
    $uid   = $_SESSION['user_id'];

    $conn->begin_transaction();
    try {
        $td = 0; $tk = 0; $rows = [];
        
        // Loop Baris Jurnal
        foreach ($_POST['kode_akun'] as $i => $kode) {
            if (empty($kode)) continue;
            
            $d = (double)str_replace(['.',','], '', $_POST['debit'][$i]);
            $k = (double)str_replace(['.',','], '', $_POST['kredit'][$i]);
            if ($d == 0 && $k == 0) continue;
            
            $td += $d; $tk += $k;
            
            // Ambil Context Data jika ada
            $m_id   = !empty($_POST['mhs_id'][$i]) ? (int)$_POST['mhs_id'][$i] : NULL;
            $t_ref  = !empty($_POST['tagihan_ref'][$i]) ? (int)$_POST['tagihan_ref'][$i] : NULL;
            $ast_id = !empty($_POST['asset_id'][$i]) ? (int)$_POST['asset_id'][$i] : NULL;

            $rows[] = [
                'acc' => $kode, 
                'd' => $d, 
                'k' => $k,
                'm_id' => $m_id,
                't_ref' => $t_ref,
                'ast_id' => $ast_id
            ];
        }

        // Validasi Keseimbangan
        if ($td <= 0 || abs($td - $tk) > 0.1) {
            throw new Exception("Jurnal tidak seimbang atau nominal kosong. Selisih: " . number_format(abs($td - $tk)));
        }

        if ($id) {
            // Update Header
            $stmt = $conn->prepare("UPDATE syifa_jurnal SET tgl_jurnal=?, keterangan=?, total_debet=?, total_kredit=? WHERE id=?");
            $stmt->bind_param("ssddi", $date, $desc, $td, $tk, $id);
            $stmt->execute();
            // Bersihkan Detail Lama (Re-entry strategy)
            $conn->query("DELETE FROM syifa_jurnal_detail WHERE jurnal_id=$id");
            $jid = $id;
        } else {
            // Insert Header Baru
            $num_key = ($jenis == 'penyesuaian') ? 'jurnal_penyesuaian' : 'jurnal_umum';
            $ref = getNextNumber($conn, $num_key);
            $stmt = $conn->prepare("INSERT INTO syifa_jurnal (no_jurnal, tgl_jurnal, keterangan, total_debet, total_kredit, created_by, jenis_jurnal) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssddis", $ref, $date, $desc, $td, $tk, $uid, $jenis);
            $stmt->execute();
            $jid = $conn->insert_id;
        }

        // Simpan Detail Baris Jurnal dengan Sinkronisasi Modul
        $stmt_d = $conn->prepare("INSERT INTO syifa_jurnal_detail (jurnal_id, kode_akun, debit, kredit, mahasiswa_id, tagihan_id_ref, aset_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $r) {
            $stmt_d->bind_param("isddiii", $jid, $r['acc'], $r['d'], $r['k'], $r['m_id'], $r['t_ref'], $r['ast_id']);
            $stmt_d->execute();
            
            // LOGIKA KAPITALISASI OTOMATIS: Jika Aset Tetap di Debet via Jurnal
            if ($r['ast_id'] && $r['d'] > 0) {
                $conn->query("UPDATE assets SET purchase_value = purchase_value + {$r['d']}, current_book_value = current_book_value + {$r['d']} WHERE id = {$r['ast_id']}");
            }
        }

        $conn->commit();
        done('success', "Jurnal " . strtoupper($jenis) . " berhasil diposting dan tersinkronisasi.");
    } catch (Exception $e) {
        $conn->rollback();
        done('danger', "Gagal Posting: " . $e->getMessage());
    }
}