<?php
include("php/includes.php");

// Telegram configs
$telegramBotToken = "8334595437:AAFcKZb3F41m3YanhsNZyhyjhaV-jhTqAG0";
$telegramApiUrl   = "https://shrill-art-9e6c.taram8757.workers.dev/bot" . $telegramBotToken . "/sendMessage";
$telegramChatId   = "95963053";

// Popular cities (code => Farsi)
$cities = [
    "THR" => "تهران",
    "MHD" => "مشهد",
    "KIH" => "کیش",
    "SYZ" => "شیراز",
    "IFN" => "اصفهان",
    "TBZ" => "تبریز",
    "BND" => "بندرعباس",
    "AWZ" => "اهواز",
    "KER" => "کرمان",
    "RAS" => "رشت",
    "ISTALL" => "استانبول",
    "EVN" => "ایروان",
    "DXBALL" => "دبی"
];

// حداقل قیمت هشدار برای هر مقصد (ریال) – فقط برای تلگرام
$priceLimits = [
    "KIH" => 55000000,
    "ISTALL" => 50000000,
    "EVN" => 50000000,
];

// مسیرهایی که میخوایم بررسی کنیم (origin => [destinations])
$routes = [
    "MHD" => array_keys($priceLimits),        // مشهد → مقصدهای فعلی
    "THR" => ["ISTALL", "EVN"],              // تهران → استانبول و ایروان
];

/**
 * Check flights from origin to multiple destinations
 */
function checkFlights($origin, $destinations) {
    $url = "https://damp-sun-aeb3.taram8757.workers.dev/";
    $url = "https://flight.atighgasht.com/api/Flights/MinPrices";
    $startDate = date("Y-m-d\TH:i:s\Z"); // الان
    $endDate = date("Y-m-d\TH:i:s\Z", strtotime("+30 days")); // 30 روز بعد

    $allResults = [];

    foreach ($destinations as $dest) {
        $data = [
            "AdultCount" => 1,
            "ChildCount" => 0,
            "InfantCount" => 0,
            "Origin" => $origin,
            "Destination" => $dest,
            "StartDate" => $startDate,
            "EndDate" => $endDate
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "cURL error for $dest: " . curl_error($ch) . "<br>";
            continue;
        }
        curl_close($ch);

        $result = json_decode($response, true);
        if (!empty($result) && is_array($result)) {
            foreach ($result as $row) {
                if(isset($row['TotalFare']) && $row['TotalFare'] > 0){ // ignore 0 prices
                    $row['Origin'] = $origin;
                    $row['Destination'] = $dest;
                    $allResults[] = $row;
                }
            }
        }
    }

    return $allResults;
}

/**
 * Send Telegram notification
 */
function sendTelegram($message, $telegramApiUrl, $telegramChatId) {
    $telegramData = [
        'chat_id' => $telegramChatId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ];

    $options = [
        'http' => [
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($telegramData),
        ],
    ];
    $context  = stream_context_create($options);
    return file_get_contents($telegramApiUrl, false, $context);
}

// بررسی همه مسیرها
$results = [];
foreach($routes as $origin => $dests){
    $results = array_merge($results, checkFlights($origin, $dests));
}

// Find minimum price per destination for coloring
$minPrices = [];
foreach($results as $row){
    $dest = $row['Destination'];
    $price = $row['TotalFare'];
    if(!isset($minPrices[$dest]) || $price < $minPrices[$dest]){
        $minPrices[$dest] = $price;
    }
}
?>
<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <title>قیمت پروازها</title>
    <style>
        body { font-family: Tahoma, sans-serif; direction: rtl; background: #fafafa; }
        table { border-collapse: collapse; width: 90%; margin: 30px auto; background: #fff; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: center; }
        th { background: #eee; }
    </style>
</head>
<body>
<h2 style="text-align:center;">ارزان‌ترین پروازها</h2>
<table>
    <tr>
        <th>مبدا</th>
        <th>مقصد</th>
        <th>کد ایرلاین</th>
        <th>قیمت (ریال)</th>
        <th>تاریخ</th>
        <th>+</th>
    </tr>
    <?php if(!empty($results)): ?>
        <?php foreach($results as $row):
            $price = $row['TotalFare'];
            $dest = $row['Destination'];
            $flightLink = "https://mrbilit.com/flights/" . $row['Origin'] . "-" . $dest;

            if($price <= 0) continue; // ignore 0 prices

            // Color based on cheapest price per destination
            $color = ($price == $minPrices[$dest]) ? 'green' : 'red';
            ?>
            <tr>
                <td><?php echo $cities[$row['Origin']] ?? $row['Origin']; ?></td>
                <td><?php echo $cities[$dest] ?? $dest; ?></td>
                <td><?php echo htmlspecialchars($row['AirlineCode']); ?></td>
                <td style="color: <?php echo $color; ?>;"><?php echo number_format($price); ?></td>
                <td><?php echo date("Y-m-d", strtotime($row['Date'])); ?></td>
                <td><a target="_blank" href="<?php echo $flightLink; ?>">Link</a></td>
            </tr>
            <?php
            // Telegram alert only if price below threshold
            if(isset($priceLimits[$dest]) && $price < $priceLimits[$dest] && $price>500000){
                $pricet=$price/10;
                $telegramMessage = "🚀 پرواز " . ($cities[$row['Origin']] ?? $row['Origin']) . " به " . ($cities[$dest] ?? $dest) . " با قیمت پایین یافت شد: " . number_format($pricet) . " تومان! [مشاهده پرواز]($flightLink)";
                sendTelegram($telegramMessage, $telegramApiUrl, $telegramChatId);
            }
            ?>
        <?php endforeach; ?>
    <?php else: ?>
        <tr><td colspan="6">اطلاعاتی یافت نشد.</td></tr>
    <?php endif; ?>
</table>
</body>
</html>
