<?php
/**
 * prayer_time.php - PRAYER TIME CALCULATION ENGINE
 * Metode: Kemenag RI (Indonesia Standard)
 * Mode: Offline Hisab — No External API Required
 * Arsitektur: Zero-Dependency Mathematical Algorithm (Cloud Safe)
 * Perbaikan: 
 * Menyuntikkan pelindung function_exists agar kebal terhadap error 
 * "Cannot redeclare" jika file tanpa sengaja terpanggil dua kali oleh sistem.
 */

if (!function_exists('getPrayerTimes')) {
    function getPrayerTimes($lat, $lng, $timezone, $date = null)
    {
        // 1. TIMEZONE GUARD (Mencegah PHP Warning di Hosting yang merusak tampilan)
        $old_tz = @date_default_timezone_get();
        date_default_timezone_set('Asia/Jakarta');
        
        if (!$date) $date = date('Y-m-d');

        $timestamp = strtotime($date);
        $day = date('z', $timestamp) + 1;
        
        // Kembalikan timezone ke asalnya
        date_default_timezone_set($old_tz);

        // Deklinasi Matahari (Solar declination approximation)
        $decl = 23.45 * sin(deg2rad(360 / 365 * ($day - 81)));

        // Equation of time
        $b = 360 / 365 * ($day - 81);
        $eqtime = 9.87 * sin(deg2rad(2 * $b)) - 7.53 * cos(deg2rad($b)) - 1.5 * sin(deg2rad($b));

        // Waktu Dzuhur (Noon)
        $noon = 12 + $timezone - ($lng / 15) - ($eqtime / 60);

        // Hour angle helper function
        $calcHourAngle = function($angle) use ($lat, $decl) {
            $cos_val = cos(deg2rad($lat)) * cos(deg2rad($decl));
            if($cos_val == 0) return 0; // Mencegah division by zero
            
            $val = (sin(deg2rad($angle)) - sin(deg2rad($lat)) * sin(deg2rad($decl))) / $cos_val;
            // Clamp nilai antara -1 dan 1 untuk mencegah error acos (NaN)
            $val = max(-1, min(1, $val));
            
            return rad2deg(acos($val)) / 15;
        };

        // Parameter Sudut Standar Kemenag RI
        $fajrAngle = -20;     
        $ishaAngle = -18;
        
        // 2. ASR TRIGONOMETRY FIX (Standar Bayangan 1)
        $asrAngle = rad2deg(atan(1 / (1 + tan(deg2rad(abs($lat - $decl))))));

        $fajr = $noon - $calcHourAngle($fajrAngle);
        $sunrise = $noon - $calcHourAngle(-0.833);
        $asr = $noon + $calcHourAngle($asrAngle);
        $maghrib = $noon + $calcHourAngle(-0.833);
        $isha = $noon + $calcHourAngle($ishaAngle);

        // 3. SAFE TIME FORMATTER (Menggantikan gmdate yang sering error di Hosting Linux)
        $formatTime = function($timeDec) {
            // Tambahkan Ikhtiyat (Waktu Pengaman Kemenag) = 2 menit
            $timeDec += (2 / 60);
            
            if (is_nan($timeDec) || is_infinite($timeDec)) return "--:--";
            
            // Normalisasi agar selalu berada di rentang 0-24 Jam
            $timeDec = fmod($timeDec + 24, 24); 
            
            $hours = floor($timeDec);
            $minutes = round(($timeDec - $hours) * 60);
            
            if ($minutes >= 60) {
                $hours += 1;
                $minutes -= 60;
            }
            $hours = $hours % 24;
            
            return sprintf("%02d:%02d", $hours, $minutes);
        };

        // Format output ke Jam:Menit
        return [
            'Subuh'   => $formatTime($fajr),
            'Dzuhur'  => $formatTime($noon),
            'Ashar'   => $formatTime($asr),
            'Maghrib' => $formatTime($maghrib),
            'Isya'    => $formatTime($isha),
        ];
    }
}
?>