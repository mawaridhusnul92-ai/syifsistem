<?php
/**
 * journal_entry.php - MODUL ENTRI JURNAL UMUM
 * Fitur: Dynamic Rows, Real-time Validation, Integrated COA
 */
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

// --- 1. POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_journal'])) {
    $conn->begin_transaction();
    try {
        $date = $_POST['journal_date'];
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));
        $ref = $_POST['ref_no'];
        $type = $_POST['journal_type'];
        $desc = $_POST['description'];

        // Insert Header
        $stmt = $conn->prepare("INSERT INTO journals (journal_type, journal_date, period_year, period_month, ref_no, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiissi", $type, $date, $year, $month, $ref, $desc, $_SESSION['user_id']);
        $stmt->execute();
        $journal_id = $conn->insert_id;

        // Insert Items
        $stmt_item = $conn->prepare("INSERT INTO journal_items (journal_id, coa_id, debit, credit) VALUES (?, ?, ?, ?)");
        
        $total_debit = 0;
        $total_credit = 0;

        foreach ($_POST['coa_id'] as $key => $coa) {
            $deb = (float)str_replace(['.', ','], '', $_POST['debit'][$key] ?: '0');
            $cre = (float)str_replace(['.', ','], '', $_POST['credit'][$key] ?: '0');
            
            if ($coa != "" && ($deb > 0 || $cre > 0)) {
                $stmt_item->bind_param("isdd", $journal_id, $coa, $deb, $cre);
                $stmt_item->execute();
                $total_debit += $deb;
                $total_credit += $cre;
            }
        }

        // Final Balance Check
        if (abs($total_debit - $total_credit) > 0.01) {
            throw new Exception("Jurnal tidak seimbang (Unbalanced)! Selisih: " . ($total_debit - $total_credit));
        }

        $conn->commit();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Jurnal berhasil disimpan.'];
        header("Location: journal_list.php"); exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}

// Data COA untuk Dropdown
$accounts = $conn->query("SELECT kode_akun, nama_akun FROM syifa_akun WHERE is_group=0 AND is_active=1 ORDER BY kode_akun");
$coa_options = "";
while($row = $accounts->fetch_assoc()) {
    $coa_options .= "<option value='{$row['kode_akun']}'>{$row['kode_akun']} - {$row['nama_akun']}</option>";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>Entri Jurnal - SYIFA ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style_syifa.css">
    <style>
        .table-input input { border: none; background: transparent; width: 100%; text-align: right; }
        .table-input input:focus { outline: none; background: #f8f9fa; }
        .balance-box { font-size: 1.2rem; font-weight: bold; padding: 15px; border-radius: 10px; }
        .text-debit { color: #0d6efd; }
        .text-credit { color: #dc3545; }
    </style>
</head>
<body class="bg-light">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <?php include 'header_nav.php'; ?>
        <div class="p-4">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-dark mb-0">Entri Jurnal Umum</h3>
                <a href="journal_list.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fas fa-list me-2"></i> Daftar Jurnal
                </a>
            </div>

            <?php if(isset($error_msg)): ?>
                <div class="alert alert-danger rounded-4 shadow-sm mb-4"><?= $error_msg ?></div>
            <?php endif; ?>

            <form method="POST" id="journalForm">
                <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">TANGGAL JURNAL</label>
                            <input type="date" name="journal_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">NOMOR REFERENSI</label>
                            <input type="text" name="ref_no" class="form-control fw-bold" placeholder="JU-<?= date('YmdHis') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">JENIS JURNAL</label>
                            <select name="journal_type" class="form-select fw-bold">
                                <option value="GENERAL">GENERAL (Umum)</option>
                                <option value="ADJUSTMENT">ADJUSTMENT (Penyesuaian)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">TAHUN/BULAN</label>
                            <input type="text" class="form-control bg-light" value="<?= date('F Y') ?>" readonly>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold text-muted">KETERANGAN / DESKRIPSI</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Tuliskan alasan atau keterangan jurnal..." required></textarea>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                    <table class="table table-bordered mb-0 table-input align-middle" id="itemTable">
                        <thead class="table-dark">
                            <tr class="text-center small">
                                <th width="45%">AKUN (CHART OF ACCOUNT)</th>
                                <th width="25%">DEBIT (Rp)</th>
                                <th width="25%">KREDIT (Rp)</th>
                                <th width="5%"><i class="fas fa-cog"></i></th>
                            </tr>
                        </thead>
                        <tbody id="journalBody">
                            <!-- Initial Rows -->
                            <?php for($i=0; $i<2; $i++): ?>
                            <tr>
                                <td class="p-2">
                                    <select name="coa_id[]" class="form-select border-0 shadow-none select-coa" required>
                                        <option value="">-- Pilih Akun --</option>
                                        <?= $coa_options ?>
                                    </select>
                                </td>
                                <td><input type="text" name="debit[]" class="input-debit" value="0" onkeyup="recalculate()"></td>
                                <td><input type="text" name="credit[]" class="input-credit" value="0" onkeyup="recalculate()"></td>
                                <td class="text-center"><button type="button" class="btn btn-link text-danger btn-sm p-0" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td class="text-end pe-4">TOTAL</td>
                                <td class="text-end text-debit" id="totalDebitDisplay">0</td>
                                <td class="text-end text-credit" id="totalCreditDisplay">0</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="p-3 bg-white border-top">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" onclick="addRow()">
                            <i class="fas fa-plus me-1"></i> Tambah Baris
                        </button>
                    </div>
                </div>

                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div id="statusBalance" class="balance-box bg-white shadow-sm border-start border-danger border-4 text-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i> Belum Seimbang (Unbalanced)
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="reset" class="btn btn-light rounded-pill px-4 fw-bold me-2">Reset</button>
                        <button type="submit" name="save_journal" id="btnSave" class="btn btn-success rounded-pill px-5 py-2 fw-bold shadow" disabled>
                            <i class="fas fa-save me-2"></i> SIMPAN JURNAL
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const coaTemplate = `<?= $coa_options ?>`;

        function addRow() {
            const row = `<tr>
                <td class="p-2"><select name="coa_id[]" class="form-select border-0 shadow-none select-coa" required><option value="">-- Pilih Akun --</option>${coaTemplate}</select></td>
                <td><input type="text" name="debit[]" class="input-debit" value="0" onkeyup="recalculate()"></td>
                <td><input type="text" name="credit[]" class="input-credit" value="0" onkeyup="recalculate()"></td>
                <td class="text-center"><button type="button" class="btn btn-link text-danger btn-sm p-0" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
            </tr>`;
            $('#journalBody').append(row);
        }

        function removeRow(btn) {
            if($('#journalBody tr').length > 2) {
                $(btn).closest('tr').remove();
                recalculate();
            }
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('id-ID').format(num);
        }

        function parseNumber(str) {
            return parseFloat(str.replace(/\./g, '').replace(/,/g, '.')) || 0;
        }

        function recalculate() {
            let tDebit = 0;
            let tCredit = 0;

            $('.input-debit').each(function() {
                let val = parseNumber($(this).val());
                $(this).val(formatNumber(val));
                tDebit += val;
            });

            $('.input-credit').each(function() {
                let val = parseNumber($(this).val());
                $(this).val(formatNumber(val));
                tCredit += val;
            });

            $('#totalDebitDisplay').text(formatNumber(tDebit));
            $('#totalCreditDisplay').text(formatNumber(tCredit));

            const status = $('#statusBalance');
            const btn = $('#btnSave');

            if (tDebit > 0 && tDebit === tCredit) {
                status.removeClass('text-danger border-danger').addClass('text-success border-success');
                status.html('<i class="fas fa-check-circle me-2"></i> Jurnal Seimbang (Balanced)');
                btn.prop('disabled', false);
            } else {
                status.removeClass('text-success border-success').addClass('text-danger border-danger');
                status.html('<i class="fas fa-exclamation-triangle me-2"></i> Belum Seimbang (Selisih: ' + formatNumber(Math.abs(tDebit - tCredit)) + ')');
                btn.prop('disabled', true);
            }
        }

        // Format Rupiah on display initialization
        $(document).ready(function() { recalculate(); });
    </script>
</body>
</html>