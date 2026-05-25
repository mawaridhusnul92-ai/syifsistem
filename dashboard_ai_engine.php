<?php
/**
 * dashboard_ai_engine.php - RULE-BASED AI INSIGHT GENERATOR
 * Deskripsi: Mengevaluasi agregat data untuk mendeteksi risiko dan memberikan rekomendasi.
 */

function generateExecutiveInsight($data, $bulan_berjalan) {
    $insights = [];
    $score = 100;

    $pct_belanja = $data['absorption_rate'];
    $forecast = $data['forecast_year_end'];
    $pagu = $data['pagu_belanja'];
    $burn_rate = $data['burn_rate'];
    
    // Data Pendapatan
    $pct_pendapatan = ($data['pagu_pendapatan'] > 0) ? ($data['realisasi_pendapatan'] / $data['pagu_pendapatan']) * 100 : 0;
    
    $pct_piutang_lunas = ($data['piutang_total'] > 0) ? ($data['piutang_dibayar'] / $data['piutang_total']) * 100 : 0;
    $pct_piutang_nunggak = 100 - $pct_piutang_lunas;

    // RULE 1: OVERSPENDING WARNING
    if ($pct_belanja > 90 && $bulan_berjalan < 10) {
        $insights[] = "<i class='fas fa-exclamation-triangle text-danger me-2'></i> <b>Peringatan Overspending:</b> Realisasi belanja telah mencapai " . round($pct_belanja) . "%. Hentikan pembelanjaan non-prioritas segera.";
        $score -= 25;
    } else if ($pct_belanja > 0) {
        $insights[] = "<i class='fas fa-check-circle text-success me-2'></i> Penyerapan anggaran belanja berjalan dalam batas wajar (".round($pct_belanja)."%).";
    }

    // RULE 2: REVENUE PERFORMANCE (PENDAPATAN)
    if ($pct_pendapatan >= 80) {
        $insights[] = "<i class='fas fa-hand-holding-usd text-success me-2'></i> <b>Pendapatan Stabil:</b> Penerimaan dana telah memenuhi " . round($pct_pendapatan) . "% dari target tahunan.";
    } else if ($bulan_berjalan >= 9 && $pct_pendapatan < 60) {
        $insights[] = "<i class='fas fa-arrow-down text-warning me-2'></i> <b>Peringatan Pendapatan:</b> Penerimaan (" . round($pct_pendapatan) . "%) tertinggal jauh dari target. Lakukan akselerasi tagihan.";
        $score -= 15;
    }

    // RULE 3: DEFICIT RISK
    if ($forecast > $pagu && $pagu > 0) {
        $defisit_est = $forecast - $pagu;
        $insights[] = "<i class='fas fa-chart-line text-warning me-2'></i> <b>Risiko Defisit Akhir Tahun:</b> Berdasarkan laju bakar (Burn Rate), diproyeksikan kekurangan pagu belanja sebesar Rp " . number_format($defisit_est, 0, ',', '.') . ".";
        $score -= 20;
    }

    // RULE 4: PIUTANG MAHASISWA
    if ($pct_piutang_nunggak > 40 && $data['piutang_total'] > 0) {
        $insights[] = "<i class='fas fa-coins text-danger me-2'></i> Tunggakan piutang mahasiswa sangat tinggi (" . round($pct_piutang_nunggak) . "%). <b>Warning: Optimalisasi taktik penagihan diperlukan.</b>";
        $score -= 15;
    }

    // RULE 5: LIKUIDITAS KAS
    $bulan_bertahan = ($burn_rate > 0) ? ($data['saldo_kas'] / $burn_rate) : 99;
    if ($bulan_bertahan < 3 && $data['realisasi_belanja'] > 0) {
        $insights[] = "<i class='fas fa-vault text-danger me-2'></i> <b>Status Keuangan Waspada:</b> Saldo kas saat ini hanya cukup untuk membiayai operasional rutin selama ".round($bulan_bertahan, 1)." bulan ke depan.";
        $score -= 30;
    }

    // STATUS KESEHATAN KESELURUHAN
    $kesehatan_kas = [
        'status' => 'SEHAT & STABIL',
        'badge' => 'success',
        'index' => $score,
        'ringkasan' => 'Postur keuangan Institusi berada dalam kondisi likuid dan terkendali dengan baik.'
    ];

    if ($score <= 50) {
        $kesehatan_kas['status'] = 'KRITIS';
        $kesehatan_kas['badge'] = 'danger';
        $kesehatan_kas['ringkasan'] = 'Darurat Finansial. Arus kas terancam, pendapatan seret, dan proyeksi melampaui pagu.';
    } else if ($score < 80) {
        $kesehatan_kas['status'] = 'WASPADA';
        $kesehatan_kas['badge'] = 'warning text-dark';
        $kesehatan_kas['ringkasan'] = 'Terdapat potensi risiko pada likuiditas, perlambatan pendapatan, atau pembengkakan belanja.';
    }

    return [
        'health' => $kesehatan_kas,
        'points' => empty($insights) ? ["<i class='fas fa-sync text-muted me-2'></i> Data operasional sedang dikumpulkan oleh sistem..."] : $insights
    ];
}

// Eksekusi engine
$bulan_eval = ($filter_tahun == date('Y')) ? (int)date('n') : 12;
if (!empty($filter_bulan)) $bulan_eval = (int)$filter_bulan;
if ($bulan_eval == 0) $bulan_eval = 1;

$ai_insights = generateExecutiveInsight($data, $bulan_eval);
?>