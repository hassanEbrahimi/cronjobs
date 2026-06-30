<?php
include("../php/includes.php");

// Set timezone
date_default_timezone_set("Asia/Tehran");

// Telegram configs
$telegramBotToken = "8031324573:AAFeN4JkNbWBJwQ8jpvGC-B7RckrZ9VRzBM";
$telegramApiUrl   = "https://shrill-art-9e6c.taram8757.workers.dev/bot" . $telegramBotToken . "/sendMessage";
$telegramChatId   = "95963053";

// Get input params
$date = isset($_GET['date']) ? $_GET['date'] : date("Y-m-d");
$trainNumbers = isset($_GET['trains']) ? explode(",", $_GET['trains']) : [];

// If no train numbers provided
if (empty($trainNumbers)) {
    die(json_encode(['error' => 'Please provide train numbers in the "trains" parameter. Example: ?trains=319,591'], JSON_UNESCAPED_UNICODE));
}

// API URL

//$url = "https://train.mrbilit.com/api/GetAvailable/v2?from=191&to=1&date={$date}&genderCode=3&adultCount=1&childCount=0&infantCount=0&exclusive=false&availableStatus=Both";
$url = "
https://train.mrbilit.com/api/GetAvailable/v2?from=55&to=191&date={$date}&genderCode=3&adultCount=1&childCount=0&infantCount=0&exclusive=false&availableStatus=Both";
https://train.mrbilit.com/api/GetAvailable/v2?from=191&to=1&date=2025-12-06&genderCode=3&adultCount=1&childCount=0&infantCount=0&exclusive=false&availableStatus=Both

// Fetch data
$json = @file_get_contents($url);

if ($json === false) {
    die(json_encode(['error' => 'Failed to fetch data from API'], JSON_UNESCAPED_UNICODE));
}

$data = json_decode($json, true);

if (!isset($data['trains'])) {
    die(json_encode(['error' => 'Invalid API response'], JSON_UNESCAPED_UNICODE));
}

$result = [];

// تبدیل تاریخ میلادی به شمسی
function gregorianToJalaliDate($gDate) {
    return jdate("Y/m/d", strtotime($gDate));
}
function formatTime($time) {
    return jdate("H:i", strtotime($time));
}

foreach ($data['trains'] as $train) {
    if (!in_array($train['trainNumber'], $trainNumbers)) continue; // only requested trains

    if (!isset($train['prices'])) continue;

    foreach ($train['prices'] as $price) {
        if (!isset($price['classes'])) continue;

        foreach ($price['classes'] as $class) {
            if ($class['isAvailable'] === true && $class['capacity'] > 0) {
                $class['price']=$class['price']/10;
                $trainInfo = [
                    'trainNumber'   => $train['trainNumber'],
                    'from'          => $train['fromName'],
                    'to'            => $train['toName'],
                    'departureTime' => $train['departureTime'],
                    'arrivalTime'   => $train['arrivalTime'],
                    'wagonName'     => $class['wagonName'],
                    'capacity'      => $class['capacity'],
                    'price'         => number_format($class['price']),
                ];
                $result[] = $trainInfo;

                // تاریخ و ساعت شمسی
                $departureDate = gregorianToJalaliDate($trainInfo['departureTime']);
                $depTime = formatTime($trainInfo['departureTime']);
                $arrTime = formatTime($trainInfo['arrivalTime']);

                // لینک علی‌بابا
                $link = "https://www.alibaba.ir/train/MHD-THR?adult=1&child=0&infant=0&ticketType=Family&isExclusive=false&departing=" . $departureDate;

                // --- Send Telegram message ---
                $telegramMessage = "🚂 بلیت قطار پیدا شد!\n\n"
                    ."📅 تاریخ حرکت: *{$departureDate}*\n"
                    ."🕑 ساعت حرکت: *{$depTime}*\n"
                    ."🕑 ساعت رسیدن: *{$arrTime}*\n\n"
                    ."🚆 شماره قطار: *{$trainInfo['trainNumber']}*\n"
                    ."📍 مسیر: {$trainInfo['from']} → {$trainInfo['to']}\n"
                    ."🛏 واگن: {$trainInfo['wagonName']}\n"
                    ."💺 ظرفیت: *{$trainInfo['capacity']}* نفر\n"
                    ."💵 قیمت: {$trainInfo['price']} تومان\n\n"
                    ."🔗 [خرید بلیت از علی‌بابا]({$link})";

                $telegramData = [
                    'chat_id'    => $telegramChatId,
                    'text'       => $telegramMessage,
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
                @file_get_contents($telegramApiUrl, false, $context);
            }
        }
    }
}

// Output
header('Content-Type: application/json; charset=utf-8');

if (empty($result)) {
    echo json_encode(['message' => 'No seats available for the requested trains'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
