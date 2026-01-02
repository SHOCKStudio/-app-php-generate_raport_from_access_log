<?php
// ------ KONFIGURACJA ------
$accountName = 'nazwakonta_dhosting';  // Nazwa konta
$domain      = 'domenaklienta.pl';  // Domena
// --------------------------

// Automatycznie generowane ścieżki
$logsDir = "/home/klient.dhosting.pl/{$accountName}/.logs/www/{$domain}/";
$saveDir = "/home/klient.dhosting.pl/{$accountName}/raporty_logs/";
$limit   = 30;

if (!file_exists($saveDir)) { mkdir($saveDir, 0755, true); }

// Obsługa argumentu z konsoli (np. php74 raport.php 30 wygeneruje 30 dni wstecz)
$daysBack = isset($argv[1]) ? (int)$argv[1] : 1;

for ($i = $daysBack; $i >= 1; $i--) {
    generateReport($i, $logsDir, $saveDir, $domain, $limit);
}

function generateReport($daysAgo, $logsDir, $saveDir, $domain, $limit) {
    $dateLogFormat = date('d/M/Y', strtotime("-$daysAgo day"));
    $dateFileName  = date('Y_m_d', strtotime("-$daysAgo day"));
    
    $data = ['total_req' => 0, 'total_bytes' => 0, 'urls' => [], 'methods' => [], 'codes' => [], 'bots' => [], 'ips' => []];

    // Szukamy wszystkich plików logów (bieżący i spakowane .gz)
    $files = glob($logsDir . "access.log*");

    foreach ($files as $f) {
        if (!file_exists($f)) continue;
        $isGZ = (substr($f, -3) == '.gz');
        $handle = $isGZ ? gzopen($f, "r") : fopen($f, "r");
        if ($handle) {
            while (($line = ($isGZ ? gzgets($handle, 8192) : fgets($handle))) !== false) {
                if (strpos($line, $dateLogFormat) === false) continue;
                $pattern = '/^(\S+) \S+ \S+ \[(.+)\] "(\S+) (.*?) (\S+)" (\d{3}) (\d+|-)( ".*?")? ("(.*?)")?/';
                if (preg_match($pattern, $line, $matches)) {
                    $ip = $matches[1]; $method = $matches[3]; $url = $matches[4]; $code = $matches[6];
                    $bytes = ($matches[7] === '-') ? 0 : (int)$matches[7];
                    $ua = isset($matches[10]) ? $matches[10] : 'Unknown';

                    $data['total_req']++;
                    $data['total_bytes'] += $bytes;
                    $full_url = $method . ' ' . $url;
                    @$data['urls'][$full_url]['c']++; @$data['urls'][$full_url]['b'] += $bytes;
                    @$data['methods'][$method]['c']++; @$data['methods'][$method]['b'] += $bytes;
                    @$data['codes'][$code]['c']++; @$data['codes'][$code]['b'] += $bytes;
                    @$data['ips'][$ip]['c']++; @$data['ips'][$ip]['b'] += $bytes;
                    if (preg_match('/(bot|google|bing|crawler|spider|curl|facebook|duckduckgo|uptime|ahrefs|dotbot|yandex|adsbot)/i', $ua)) {
                        @$data['bots'][$ua]['c']++; @$data['bots'][$ua]['b'] += $bytes;
                    }
                }
            }
            $isGZ ? gzclose($handle) : fclose($handle);
        }
    }

    if ($data['total_req'] === 0) return; // Pomijamy jeśli brak logów dla danej daty

    // Formatuje raport
    $out = "Ilość wszystkich wywołań - {$data['total_req']} - $domain ($dateLogFormat)\n";
    $out .= "Transfer - " . round($data['total_bytes'] / 1048576, 4) . " MB\n\n";

    $out .= buildTable("TOP URL", ["URL", "Liczba REQ", "Ile %", "Transfer"], array_slice(sortData($data['urls']), 0, $limit), [120, 12, 8, 15], $data['total_req']);
    $out .= buildTable("METODY", ["Metoda", "Liczba REQ", "Ile %", "Transfer"], sortData($data['methods']), [15, 12, 8, 15], $data['total_req']);
    $out .= buildTable("KODY", ["Kod", "Liczba REQ", "Ile %", "Transfer"], sortData($data['codes']), [15, 12, 8, 15], $data['total_req']);
    $out .= buildTable("BOTY", ["User Agent / Bot", "Liczba REQ", "Ile %", "Transfer"], array_slice(sortData($data['bots']), 0, $limit), [120, 12, 8, 15], $data['total_req']);
    $out .= buildTable("IP", ["Adres IP", "Requesty", "Ile %", "Transfer"], array_slice(sortData($data['ips']), 0, $limit), [45, 12, 8, 15], $data['total_req']);

    file_put_contents($saveDir . "raport_" . $dateFileName . ".txt", $out);
    echo "Wygenerowano raport dla: $dateLogFormat\n";
}

function sortData($a) { uasort($a, function($m, $n) { return $n['c'] <=> $m['c']; }); return $a; }

function buildTable($title, $headers, $data, $widths, $total) {
    $t = "--- $title ---\n";
    foreach($headers as $i => $h) $t .= sprintf("%-".$widths[$i]."s | ", $h);
    $t = rtrim($t, " | ") . "\n" . str_repeat("-", array_sum($widths) + 12) . "\n";
    foreach($data as $key => $v) {
        $p = round(($v['c'] / $total) * 100, 2) . '%';
        $row = [$key, $v['c'], $p, round($v['b'] / 1048576, 4) . " MB"];
        foreach($row as $i => $val) $t .= sprintf("%-".$widths[$i]."s | ", $val);
        $t = rtrim($t, " | ") . "\n";
    }
    return $t . "\n";
}

// Czyszczenie starych plików (30 dni)
foreach (glob($saveDir . "raport_*.txt") as $old) { if (filemtime($old) < (time() - (30 * 86400))) unlink($old); }

