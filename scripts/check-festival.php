<?php
// Set timezone
date_default_timezone_set("Asia/Tehran");


$restaurantIDS = [];

// Get the current hour

$resturantIDS = [
    'sahebzaman' => '3754y2',
    'khas' => '7j4dln',
    'babataher' => 'pvlr1m',
    //'yas' => 'p6lgnp', 
];

$telegramCooldownSeconds = 30 * 60;
$telegramMaxSendsPerWindow = 3;
$telegramCooldownFile = __DIR__ . '/telegram-cooldown.json';

function loadTelegramCooldowns($file) {
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveTelegramCooldowns($file, $cooldowns) {
    file_put_contents($file, json_encode($cooldowns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function getTelegramSendState($name, $cooldowns, $cooldownSeconds, $maxSends) {
    if (!isset($cooldowns[$name])) {
        return ['can_send' => true, 'count' => 0, 'window_start' => time()];
    }

    $state = $cooldowns[$name];
    $count = (int) ($state['count'] ?? 0);
    $windowStart = (int) ($state['window_start'] ?? 0);
    $elapsed = time() - $windowStart;

    if ($elapsed >= $cooldownSeconds) {
        return ['can_send' => true, 'count' => 0, 'window_start' => time()];
    }

    if ($count >= $maxSends) {
        return [
            'can_send' => false,
            'count' => $count,
            'window_start' => $windowStart,
            'remaining_seconds' => $cooldownSeconds - $elapsed,
        ];
    }

    return ['can_send' => true, 'count' => $count, 'window_start' => $windowStart];
}


foreach ($resturantIDS as $restNmae=>$restKey){
    $urls[$restNmae]="https://snappfood.ir/mobile/v2/restaurant/details/dynamic?lat=36.29255&long=59.5723&optionalClient=WEBSITE&client=WEBSITE&deviceType=WEBSITE&appVersion=8.1.1&UDID=000&vendorCode=$restKey&locationCacheKey=lat%3D36.29255%26long%3D59.5723&show_party=1&fetch-static-data=1&locale=fa";
    $resturant_urls[$restNmae]="https://snappfood.ir/restaurant/menu/$restKey/";
}


// Recursive function to search for a word in array/object
function containsWord($data, $word) {
    if (is_array($data)) {
        foreach ($data as $value) {
            if (containsWord($value, $word)) {
                return true;
            }
        }
    } elseif (is_string($data)) {
        if (strpos($data, $word) !== false) {
            return true;
        }
    }
    return false;
}

// Log file path
$logFile = __DIR__ . "/exist-times.txt";

// Telegram configs
$telegramBotToken = "8448585033:AAF2gy_3zMWfsPUcRCp5Uk5UHuqmY0nh6Sw";
$telegramApiUrl   = "https://shrill-art-9e6c.taram8757.workers.dev/bot" . $telegramBotToken . "/sendMessage";
$telegramChatId   = "95963053";

$telegramCooldowns = loadTelegramCooldowns($telegramCooldownFile);

// Loop through each URL
foreach ($urls as $name => $url) {
    // Fetch the JSON content
    $jsonContent = file_get_contents($url);
    if ($jsonContent === false) {
        echo "Failed to fetch JSON from $name ($url).<br>";
        continue;
    }

    // Decode JSON into PHP array
    $data = json_decode($jsonContent, true);
    if ($data === null) {
        echo "Failed to decode JSON for $name.<br>";
        continue;
    }

    // Check for the word
    if (containsWord($data, "فستیوال") || containsWord($data, "پارتی") || containsWord($data, "تخفیف روز") || containsWord($data, "تخفیف روز")) {
        echo "The word 'فستیوال' exists in $name.<br>";

        // Prepare log entry
        $logEntry = date("Y-m-d H:i:s") . " - $name: فستیوال exists" . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

        $sendState = getTelegramSendState($name, $telegramCooldowns, $telegramCooldownSeconds, $telegramMaxSendsPerWindow);

        if (!$sendState['can_send']) {
            $remainingMinutes = (int) ceil($sendState['remaining_seconds'] / 60);
            echo "Telegram skipped for $name (sent {$telegramMaxSendsPerWindow} times, cooldown: ~{$remainingMinutes} min left).<br>";
        } else {
            $telegramMessage = "🎉 فستیوال در *$name* فعال شد!" . "\n" . $resturant_urls[$name];
            $telegramData = [
                'chat_id'    => $telegramChatId,
                'text'       => $telegramMessage,
                'parse_mode' => 'Markdown',
            ];

            $options = [
                'http' => [
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query($telegramData),
                ],
            ];
            $context = stream_context_create($options);
            $result = file_get_contents($telegramApiUrl, false, $context);

            if ($result === false) {
                echo "Failed to send Telegram message for $name.<br>";
            } else {
                $newCount = $sendState['count'] + 1;
                $telegramCooldowns[$name] = [
                    'count' => $newCount,
                    'window_start' => $sendState['window_start'],
                ];
                saveTelegramCooldowns($telegramCooldownFile, $telegramCooldowns);
                echo "Telegram message sent for $name ({$newCount}/{$telegramMaxSendsPerWindow}).<br>";
            }
        }

    } else {
        echo "The word 'فستیوال' does NOT exist in $name.<br>";
    }
}
?>
