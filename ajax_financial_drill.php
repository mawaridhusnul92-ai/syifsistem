<?php
/**
 * ajax_financial_drill.php - MULTI-UI AUDIT TRAIL RESOLVER
 * Versi: 1.4 (Enterprise Audit Edition)
 * Deskripsi: Menyediakan tampilan detail spesifik per kategori akun (Piutang, Aset, Liabilitas).
 */
require_once 'config/koneksi.php';

$action = $_GET['action'] ?? 'get_neraca_drill';
$kode   = $_GET['kode'] ?? '';
$end    = $_GET['end'] ?? date('Y-m-d');
$start  = $_GET['start'] ?? date('Y-01-01', strtotime($end));

echo '<div class="table-responsive"><table class="table table-hover align-middle mb-0" style="font-size:12px;">';

// =========================================================================
// ACTION: get_neraca_drill (Routing berdasarkan Kategori Akun)
// =========================================================================
if ($action == 'get_neraca_drill') {
    
    // --- SKEMA 1: PIUTANG USAHA / MAHASISWA (1-1201) ---
    if ($kode == '1-1201' || strpos($kode, '1-12') === 0) {
        echo '<thead class="table-light"><tr><th class="ps-4">Nama Mahasiswa</th><th class="text-end">Saldo Piutang</th><th class="text-center pe-4">Aksi</th></tr></thead><tbody>';
        $sql = "SELECT m.nim, m.nama, SUM(jd.debit - jd.kredit) as saldo 
                FROM syifa_jurnal_detail jd 
                JOIN syifa_mahasiswa m ON jd.mahasiswa_id = m.id 
                JOIN syifa_jurnal j ON jd.jurnal_id = j.id
                WHERE jd.kode_akun = '$kode' AND j.tgl_jurnal <= '$end'
                GROUP BY m.id HAVING saldo != 0 ORDER BY m.nama ASC";
        $res = $conn->query($sql);
        while($r = $res->fetch_assoc()) {
            echo "<tr>
                    <td class='ps-4'><b>{$r['nama']}</b><br><small class='text-muted'>NIM: {$r['nim']}</small></td>
                    <td class='text-end fw-bold text-danger'>".number_format($r['saldo'])."</td>
                    <td class='text-center pe-4'>
                        <a href='index.php?page=tagihan_monitoring&nim={$r['nim']}&from=neraca' class='btn btn-xs btn-outline-primary rounded-pill'>
                            <i class='fas fa-search me-1'></i>Detail
                        </a>
                    </td>
                  </tr>";
        }
    }

    // --- SKEMA 2: ASET TETAP & AKUMULASI (1-2xxx) ---
    elseif (strpos($kode, '1-2') === 0) {
        $is_depr = (strpos($kode, '02') !== false || strpos($kode, '03') !== false); // Cek jika akun penyusutan
        $label_val = $is_depr ? 'Saldo Penyusutan' : 'Nilai Buku';
        
        echo '<thead class="table-light"><tr><th class="ps-4">Nama Aset / Inventaris</th><th class="text-end">'.$label_val.'</th><th class="text-center pe-4">Aksi</th></tr></thead><tbody>';
        
        // Logika Aset: Tarik per item aset
        $sql = "SELECT a.id, a.asset_code, a.asset_name, SUM(jd.debit - jd.kredit) as saldo 
                FROM syifa_jurnal_detail jd 
                JOIN assets a ON jd.aset_id = a.id 
                JOIN syifa_jurnal j ON jd.jurnal_id = j.id
                WHERE jd.kode_akun = '$kode' AND j.tgl_jurnal <= '$end'
                GROUP BY a.id ORDER BY a.asset_name ASC";
        $res = $conn->query($sql);
        while($r = $res->fetch_assoc()) {
            $val_display = $is_depr ? abs($r['saldo']) : $r['saldo'];
            echo "<tr>
                    <td class='ps-4'><b>{$r['asset_name']}</b><br><small class='text-muted'>{$r['asset_code']}</small></td>
                    <td class='text-end fw-bold text-primary'>".number_format($val_display)."</td>
                    <td class='text-center pe-4'>
                        <button onclick=\"openLedgerDrill('{$kode}', '{$r['id']}', '{$r['asset_name']}')\" class='btn btn-xs btn-outline-dark rounded-pill'>
                            <i class='fas fa-book me-1'></i>Ledger
                        </button>
                    </td>
                  </tr>";
        }
    }

    // --- SKEMA 3: LIABILITAS / KEWAJIBAN (2-xxxx) & AKUN LAIN ---
    else {
        // Cek apakah akun gaji (Utang Gaji, PPh 21, BPJS, Pinjaman Karyawan)
        $is_salary_related = (strpos($kode, '2-10') === 0 || strpos($kode, '1-13') === 0);
        
        echo '<thead class="table-light">
                <tr>
                    <th class="ps-4">Tgl</th>
                    <th>Transaksi</th>
                    '.($is_salary_related ? '<th>Karyawan</th>' : '').'
                    <th>Deskripsi</th>
                    <th class="text-end">Debet</th>
                    <th class="text-end">Kredit</th>
                    <th class="text-end pe-4">Saldo</th>
                </tr>
              </thead><tbody>';
        
        // Query Ledger Terbalik (Saldo Akhir di Atas)
        $sql = "SELECT j.tgl_jurnal, j.no_jurnal, j.keterangan, jd.debit, jd.kredit, p.nama_lengkap as nama_pegawai, j.id as jid
                FROM syifa_jurnal_detail jd 
                JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
                LEFT JOIN hr_pegawai p ON jd.pegawai_id = p.id
                WHERE jd.kode_akun = '$kode' AND j.tgl_jurnal <= '$end'
                ORDER BY j.tgl_jurnal DESC, j.id DESC LIMIT 50";
        
        $res = $conn->query($sql);
        
        // Hitung Saldo Berjalan (Untuk menentukan saldo awal di paling bawah)
        $total_res = $conn->query("SELECT SUM(debit - kredit) as net FROM syifa_jurnal_detail WHERE kode_akun='$kode' AND jurnal_id IN (SELECT id FROM syifa_jurnal WHERE tgl_jurnal <= '$end')")->fetch_assoc();
        $running_bal = (double)($total_res['net'] ?? 0);

        while($r = $res->fetch_assoc()) {
            $row_bal = $running_bal;
            $running_bal -= ($r['debit'] - $r['kredit']);
            
            // Link Ubah menyesuaikan asal jurnal
            $edit_link = "onclick=\"editTrx('{$r['jid']}', '{$r['no_jurnal']}')\"";
            
            echo "<tr>
                    <td class='ps-4 text-muted'>".date('d/m/y', strtotime($r['tgl_jurnal']))."</td>
                    <td><a href='javascript:void(0)' $edit_link class='text-decoration-none fw-bold'>{$r['no_jurnal']}</a></td>
                    ".($is_salary_related ? "<td><small>{$r['nama_pegawai']}</small></td>" : "")."
                    <td><small>{$r['keterangan']}</small></td>
                    <td class='text-end'>".($r['debit'] > 0 ? number_format($r['debit']) : '-')."</td>
                    <td class='text-end'>".($r['kredit'] > 0 ? number_format($r['kredit']) : '-')."</td>
                    <td class='text-end pe-4 fw-bold'>".number_format($row_bal)."</td>
                  </tr>";
        }
    }
}

// =========================================================================
// ACTION: get_ledger_asset (Buku Besar Khusus per Item Aset)
// =========================================================================
elseif ($action == 'get_ledger_asset') {
    $aset_id = (int)$_GET['aset_id'];
    echo '<thead class="table-dark">
            <tr>
                <th class="ps-4">Tanggal</th>
                <th>Jenis</th>
                <th>Rekening/Ref</th>
                <th>Keterangan</th>
                <th class="text-end">Debet</th>
                <th class="text-end pe-4">Saldo</th>
            </tr>
          </thead><tbody>';

    $sql = "SELECT j.tgl_jurnal, j.no_jurnal, j.keterangan, jd.debit, jd.kredit, j.akun_utama_kode
            FROM syifa_jurnal_detail jd 
            JOIN syifa_jurnal j ON jd.jurnal_id = j.id 
            WHERE jd.kode_akun = '$kode' AND jd.aset_id = $aset_id
            ORDER BY j.tgl_jurnal DESC";
    $res = $conn->query($sql);
    
    // Hitung saldo akhir
    $bal_res = $conn->query("SELECT SUM(debit - kredit) as bal FROM syifa_jurnal_detail WHERE kode_akun='$kode' AND aset_id=$aset_id")->fetch_assoc();
    $cur_bal = (double)$bal_res['bal'];

    while($r = $res->fetch_assoc()) {
        $row_bal = $cur_bal;
        $cur_bal -= ($r['debit'] - $r['kredit']);
        echo "<tr>
                <td class='ps-4'>".date('d/m/y', strtotime($r['tgl_jurnal']))."</td>
                <td class='small fw-bold'>".(strpos($r['no_jurnal'], 'BKK') !== false ? 'Pembayaran' : 'Perolehan')."</td>
                <td><code>{$r['akun_utama_kode']}</code></td>
                <td><small>{$r['keterangan']}</small></td>
                <td class='text-end'>".number_format($r['debit'])."</td>
                <td class='text-end pe-4 fw-bold text-primary'>".number_format($row_bal)."</td>
              </tr>";
    }
}

echo '</tbody></table></div>';
echo '<div class="p-3 bg-light text-center small text-muted border-top">
        <i class="fas fa-info-circle me-1"></i> Gunakan tombol aksi untuk meninjau data operasional lebih lanjut.
      </div>';