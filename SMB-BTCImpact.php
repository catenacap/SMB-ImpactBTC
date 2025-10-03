<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// DB connection
$host = '';
$username = '';
$password = '';
$database = '';
$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Fetch BTC
$btcDates = $btcValues = [];
$res = $conn->query("SELECT date, value FROM btc_usd_daily WHERE date >= '2025-02-17' ORDER BY date ASC");
while ($row = $res->fetch_assoc()) {
    $btcDates[] = $row['date'];
    $btcValues[] = floatval($row['value']);
}

// Fetch SMB Forecast
$forecastUrl = "https://crons.catenacap.xyz/catenacap_macro/grab_yahoo_finance_data_daily/SMB_impactfromMOVE.txt";
$lines = explode("\n", trim(file_get_contents($forecastUrl)));
$grouped = [];
foreach ($lines as $i => $line) {
    if ($i == 0) continue;
    $cols = str_getcsv($line);
    if (count($cols) < 3) continue;
    $date = $cols[1];
    $price = floatval(str_replace(['$', ','], '', $cols[2]));
    if (!isset($grouped[$date])) $grouped[$date] = [];
    $grouped[$date][] = $price;
}
ksort($grouped);
$forecastDates = $forecastPrices = [];
foreach ($grouped as $date => $arr) {
    $forecastDates[] = $date;
    $forecastPrices[] = array_sum($arr) / count($arr);
}

// Bollinger Band Components
$sma = $upper = $lower = $sigma = [];
$plus1 = $minus1 = $plus3 = $minus3 = [];
$window = 20;
for ($i = 0; $i < count($forecastPrices); $i++) {
    if ($i < $window - 1) {
        $sma[] = $upper[] = $lower[] = $sigma[] = null;
        $plus1[] = $minus1[] = $plus3[] = $minus3[] = null;
    } else {
        $slice = array_slice($forecastPrices, $i - $window + 1, $window);
        $mean = array_sum($slice) / $window;
        $std = sqrt(array_sum(array_map(fn($v) => pow($v - $mean, 2), $slice)) / $window);
        $sma[] = round($mean, 2);
        $sigma[] = round($std, 3);
        $upper[] = round($mean + 2 * $std, 2);
        $lower[] = round($mean - 2 * $std, 2);
        $plus1[] = round($mean + $std, 2);
        $minus1[] = round($mean - $std, 2);
        $plus3[] = round($mean + 3 * $std, 2);
        $minus3[] = round($mean - 3 * $std, 2);
    }
}

// Volatility Color Mapping
$minVol = min(array_filter($sigma));
$maxVol = max(array_filter($sigma));
$bandColors = [];
foreach ($sigma as $s) {
    if (!is_numeric($s)) {
        $bandColors[] = 'rgba(0,255,0,0)';
        continue;
    }
    $norm = ($s - $minVol) / ($maxVol - $minVol + 1e-6);
    $r = intval(255 * $norm);
    $g = intval(255 * (1 - $norm));
    $bandColors[] = "rgba($r,$g,0,0.25)";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>BTC v SMB-MOVE Forecast with Volatility Bands</title>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>
    <style>
        body { background-color: #212529; margin: 0; }
        #chart { width: 100%; height: 96vh; }
    </style>
</head>
<body>
<div id="chart"></div>
<script>
const dates = <?= json_encode($forecastDates) ?>;
const prices = <?= json_encode($forecastPrices) ?>;
const upper = <?= json_encode($upper) ?>;
const lower = <?= json_encode($lower) ?>;
const plus1 = <?= json_encode($plus1) ?>;
const minus1 = <?= json_encode($minus1) ?>;
const plus3 = <?= json_encode($plus3) ?>;
const minus3 = <?= json_encode($minus3) ?>;
const colors = <?= json_encode($bandColors) ?>;

const lineTrace = (y, label, color) => ({
    x: dates,
    y: y,
    mode: 'lines',
    name: label,
    line: { dash: 'dot', width: 1, color: color },
    hoverinfo: 'skip',
    yaxis: 'y2'
});

let bandFills = [];
for (let i = 0; i < dates.length - 1; i++) {
    if (!upper[i] || !lower[i]) continue;
    bandFills.push({
        type: 'scatter',
        x: [dates[i], dates[i], dates[i + 1], dates[i + 1]],
        y: [lower[i], upper[i], upper[i + 1], lower[i + 1]],
        fill: 'toself',
        fillcolor: colors[i],
        line: { width: 0 },
        mode: 'lines',
        hoverinfo: 'skip',
        showlegend: false,
        yaxis: 'y2'
    });
}

const btcTrace = {
    x: <?= json_encode($btcDates) ?>,
    y: <?= json_encode($btcValues) ?>,
    mode: 'lines',
    name: 'BTC',
    line: { color: '#FFA500' },
    yaxis: 'y1'
};

const smbTrace = {
    x: dates,
    y: prices,
    mode: 'lines+markers',
    name: 'SMB-MOVE Forecast',
    line: { color: '#00FF00' },
    marker: { color: '#00FF00', size: 4 },
    yaxis: 'y2'
};

const layout = {
    title: 'BTC v SMB-MOVE Forecast with Volatility Bands',
    paper_bgcolor: '#212529',
    plot_bgcolor: '#212529',
    font: { color: '#fff' },
    xaxis: { title: 'Date', showgrid: false },
    yaxis: { title: 'BTC Price (USD)', side: 'left', gridcolor: '#444' },
    yaxis2: { title: 'Forecast Price (USD)', side: 'right', overlaying: 'y', gridcolor: '#444' },
    legend: { x: 0.5, y: -0.2, xanchor: 'center', orientation: 'h' }
};

Plotly.newPlot('chart', [
    btcTrace,
    ...bandFills,
    smbTrace,
    lineTrace(plus1, '+1σ', '#66cc66'),
    lineTrace(minus1, '-1σ', '#66cc66'),
    lineTrace(plus3, '+3σ', '#cc6666'),
    lineTrace(minus3, '-3σ', '#cc6666')
], layout);
</script>
</body>
</html>
