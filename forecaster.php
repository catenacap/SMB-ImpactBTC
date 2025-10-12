<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database credentials
$host = 'xxxx';
$username = 'xxx';
$password = 'xxx';
$database = 'xxxx';

$forecast_info_file = 'SMB_impactfromMOVE.txt'; // Path to your text file

// Connect to the database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Function to fetch data from Yahoo Finance
function fetchData($symbol, $interval = '1d') {
	
		$context = stream_context_create([
	      "http" => [
	          "method" => "GET",
	          "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
	      ]
	  ]);
	
    $url = "https://query2.finance.yahoo.com/v8/finance/chart/{$symbol}?interval={$interval}&range=2y";
    $json = @file_get_contents($url, false, $context);
    
    if ($json === false) {
        return ["error" => "Failed to fetch data for $symbol"];
    }
    
    $data = json_decode($json, true);
    if (isset($data['chart']['error'])) {
        return ["error" => $data['chart']['error']['description']];
    }
    
    return $data;
}

// Fetch MOVE Index Data
$moveData = fetchData('%5EMOVE', '1d');
if (isset($moveData['error'])) {
    die(json_encode($moveData));
}

$movePrices = [];
if (isset($moveData['chart']['result'][0]['timestamp'])) {
    foreach ($moveData['chart']['result'][0]['timestamp'] as $i => $timestamp) {
        $date = date('Y-m-d', $timestamp);
        $price = $moveData['chart']['result'][0]['indicators']['quote'][0]['close'][$i];
        $movePrices[$date] = $price;
    }
}

// Fetch BTC Data
$query = "SELECT date, value FROM btc_usd_weekly ORDER BY date DESC";
$stmt = $pdo->query($query);
$btcData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$btcPrices = [];
foreach ($btcData as $row) {
    $btcPrices[$row['date']] = (float) $row['value'];
}

// Fetch Liquidity Data from Database (Only from Jan 2023 onwards)
$query = "SELECT date_period, collactural_multiplier, shadow_monetary_base_tn$ FROM crossborder_capital_global_liquidity 
          WHERE STR_TO_DATE(date_period, '%d/%m/%Y') >= '2023-01-01' 
          ORDER BY STR_TO_DATE(date_period, '%d/%m/%Y') DESC";
$stmt = $pdo->query($query);
$liquidityData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process Liquidity Data
$liquidityRecords = [];
foreach ($liquidityData as $row) {
    $date = DateTime::createFromFormat('d/m/Y', $row['date_period'])->format('Y-m-d');
    $liquidityRecords[$date] = [
        'collateral_multiplier' => (float) $row['collactural_multiplier'],
        'smb' => (float) $row['shadow_monetary_base_tn$']
    ];
}

// Compute Correlations
function computeCorrelation($x, $y) {
    $n = min(count($x), count($y));
    if ($n < 2) return 0;
    
    $meanX = array_sum(array_slice($x, 0, $n)) / $n;
    $meanY = array_sum(array_slice($y, 0, $n)) / $n;
    
    $numerator = 0;
    $denominatorX = 0;
    $denominatorY = 0;
    
    for ($i = 0; $i < $n; $i++) {
        $diffX = $x[$i] - $meanX;
        $diffY = $y[$i] - $meanY;
        
        $numerator += $diffX * $diffY;
        $denominatorX += pow($diffX, 2);
        $denominatorY += pow($diffY, 2);
    }
    
    return $numerator / sqrt($denominatorX * $denominatorY);
}

$moveArray = array_values($movePrices);
$collateralArray = array_values(array_column($liquidityRecords, 'collateral_multiplier'));
$smbArray = array_values(array_column($liquidityRecords, 'smb'));
$btcArray = array_values($btcPrices);

$correlationMoveCollateral = computeCorrelation($moveArray, $collateralArray);
$correlationCollateralSMB = computeCorrelation($collateralArray, $smbArray);
$correlationBTC_Collateral = computeCorrelation($btcArray, $collateralArray);
$correlationBTC_SMB = computeCorrelation($btcArray, $smbArray);

// Compute BTC Future Price Projection
$latestBTCDate = array_key_first($btcPrices);
$latestBTCPrice = reset($btcPrices);
$previousBTCPrice = next($btcPrices) ?: $latestBTCPrice;
$btcTrend = (($latestBTCPrice - $previousBTCPrice) / $previousBTCPrice) * 100;
$btcFuturePrice = $latestBTCPrice * (1 + ($btcTrend / 100));

// Compute Forecasts
$moveDates = array_keys($movePrices);
$latestMoveDate = end($moveDates);
$forecastDate = date('Y-m-d', strtotime($latestMoveDate . " +3 days"));

$forecast = [
    'forecast_date' => $forecastDate,
    'BTC' => [
        'future_price' => "$" . number_format($btcFuturePrice, 2),
        'percentage_change' => number_format($btcTrend, 2) . "%"
    ],
    'MOVE' => [
        'predicted_collateral_multiplier_change' => number_format($correlationMoveCollateral * 0.1, 2) . "%",
        'predicted_smb_change' => number_format($correlationCollateralSMB * 0.1, 2) . "%"
    ],
    'BTC_Impact' => [
        'predicted_collateral_multiplier_change' => number_format($correlationBTC_Collateral * 0.1, 2) . "%",
        'predicted_smb_change' => number_format($correlationBTC_SMB * 0.1, 2) . "%"
    ]
];

$forecast_info = date('Y-m-d') . ", " . $forecastDate . ", $" . number_format($btcFuturePrice, 2) . "\n"; // Data to append, ending with a newline

// Check if file exists, if not, create it
if (!file_exists($forecast_info_file)) {
  file_put_contents($forecast_info_file, "Date_Today, Date_Forecast, Future_Price" . "\n"); // Create an empty file
}

$forecast_info_content = file_get_contents($forecast_info_file);

if (strpos($forecast_info_content, date('Y-m-d') . ", " . $forecastDate . ", ") !== false) {
	// Data already exist for the forecast date so do not write.
} else {
	// Append data to the file
	file_put_contents($forecast_info_file, $forecast_info, FILE_APPEND | LOCK_EX);
}

// Output JSON Response
echo json_encode($forecast, JSON_PRETTY_PRINT);
?>
