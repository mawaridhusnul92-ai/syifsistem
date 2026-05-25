<?php
/**
 * helper_intelligence.php - BUDGET INTELLIGENCE ENGINE UTILITY
 * Versi: 1.0 (Grand Master - Deviation & Risk Logic)
 */

if(!function_exists('safeQuerySum')){
    function safeQuerySum($conn, $sql) {
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) { $r = $res->fetch_row(); return (double)($r[0] ?? 0); }
        return 0;
    }
}

/**
 * Mendapatkan Status Deviasi berdasarkan persentase serapan
 */
function getDeviationStatus($pagu, $real) {
    if ($pagu <= 0) return ['label' => 'No Budget', 'class' => 'text-muted'];
    $percent = ($real / $pagu) * 100;
    
    if ($percent > 100) return ['label' => 'Over Budget', 'class' => 'status-critical'];
    if ($percent >= 90) return ['label' => 'Critical (Limit)', 'class' => 'status-critical'];
    if ($percent >= 70) return ['label' => 'Warning (High)', 'class' => 'status-warning'];
    return ['label' => 'On Track', 'class' => 'status-on-track'];
}

/**
 * Kalkulasi Spending Velocity (Burn Rate)
 */
function calculateBurnRate($pagu, $real) {
    if ($pagu <= 0) return 0;
    $current_month = (int)date('n');
    $expected_usage_percent = ($current_month / 12) * 100;
    $actual_usage_percent = ($real / $pagu) * 100;
    
    if ($actual_usage_percent == 0) return 0;
    return ($actual_usage_percent / $expected_usage_percent) * 100;
}