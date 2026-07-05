<?php
date_default_timezone_set("Asia/Tehran");

$resturantIDS = [
    'sahebzaman' => '3754y2',
    'Yaran' => '94ykr6',
    'simorgh' => '0yjjyr',
    'khas' => '7j4dln',
    'babataher' => 'pvlr1m',
    //'yas' => 'p6lgnp',
];

$lat = 36.2923;
$long = 59.57218;

$discountCategoryTitles = ['تخفیف روز', 'فستیوال', 'پارتی'];

$stockStateFile = __DIR__ . '/discount-stock-state.json';

function loadStockState($file) {
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveStockState($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function resetRestaurantSessionIfNeeded(&$restaurantState, $today) {
    if (($restaurantState['session_date'] ?? '') !== $today) {
        $restaurantState['session_date'] = $today;
        $restaurantState['activation_sent'] = false;
        $restaurantState['stock_change_sent'] = false;
    }
}

function isDiscountCategory($title, $discountCategoryTitles) {
    if (in_array($title, $discountCategoryTitles, true)) {
        return true;
    }
    foreach ($discountCategoryTitles as $keyword) {
        if (mb_strpos($title, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function formatPrice($amount) {
    return number_format((int) $amount) . ' تومان';
}

function formatStock($stock) {
    if ($stock === null || $stock === '') {
        return 'نامشخص';
    }
    return (string) $stock;
}

function normalizeStock($stock) {
    if ($stock === null || $stock === '') {
        return null;
    }
    return (string) $stock;
} 
 
function extractDiscountItems($menuCategories, $discountCategoryTitles) {
    $items = [];

    foreach ($menuCategories as $category) {
        $categoryTitle = $category['title'] ?? '';
        if (!isDiscountCategory($categoryTitle, $discountCategoryTitles)) {
            continue;
        }

        foreach ($category['products'] ?? [] as $product) {
            $productTitle = trim($product['title'] ?? '');
            if ($productTitle === '') {
                continue;
            } 

            foreach ($product['variations'] ?? [] as $variation) {
                if (isset($variation['active']) && $variation['active'] === false) {
                    continue;
                }

                $variationId = (string) ($variation['id'] ?? '');
                if ($variationId === '') {
                    continue;
                }

                $variationTitle = trim($variation['title'] ?? '');
                $title = $productTitle;
                if ($variationTitle !== '') {
                    $title .= ' (' . $variationTitle . ')';
                }

                $price = (int) ($variation['price'] ?? 0);
                $discount = $variation['discount'] ?? null;
                $finalPrice = is_array($discount) && isset($discount['amount'])
                    ? (int) $discount['amount']
                    : $price;
                $discountRatio = is_array($discount) && isset($discount['ratio'])
                    ? (int) $discount['ratio']
                    : null;

                $items[$variationId] = [
                    'variation_id' => $variationId,
                    'category' => $categoryTitle,
                    'title' => $title,
                    'price' => $price,
                    'final_price' => $finalPrice,
                    'discount_ratio' => $discountRatio,
                    'stock' => normalizeStock($variation['stock'] ?? null),
                ];
            }
        }
    }

    return $items;
}

function detectStockChanges($currentItems, $previousItems) {
    if (empty($previousItems)) {
        return [];
    }

    $changes = [];

    foreach ($currentItems as $variationId => $item) {
        if (!isset($previousItems[$variationId])) {
            continue;
        }

        $oldStock = $previousItems[$variationId]['stock'] ?? null;
        $newStock = $item['stock'];

        if ($oldStock !== $newStock) {
            $changes[] = [
                'item' => $item,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
            ];
        }
    }

    return $changes;
}

function buildActivationMessage($name, $items, $restaurantUrl) {
    $categories = array_values(array_unique(array_column($items, 'category')));
    $categoryLabel = implode('، ', $categories);

    $lines = [
        "🎉 تخفیف در *$name* فعال شد!",
        "📂 $categoryLabel",
        '',
    ];

    foreach ($items as $item) {
        $line = '• ' . $item['title'];

        if ($item['discount_ratio'] !== null && $item['final_price'] < $item['price']) {
            $line .= "\n  قیمت: " . formatPrice($item['final_price'])
                . ' (از ' . formatPrice($item['price']) . '، ' . $item['discount_ratio'] . '% تخفیف)';
        } elseif ($item['final_price'] > 0) {
            $line .= "\n  قیمت: " . formatPrice($item['final_price']);
        }

        $line .= "\n  موجودی: " . formatStock($item['stock']);
        $lines[] = $line;
    }

    $lines[] = '';
    $lines[] = $restaurantUrl;

    return implode("\n", $lines);
}

function buildStockChangeMessage($name, $changes, $restaurantUrl) {
    $lines = [
        "📦 تغییر موجودی در *$name*",
        '',
    ];

    foreach ($changes as $change) {
        $item = $change['item'];
        $line = '• ' . $item['title'];
        $line .= "\n  موجودی: " . formatStock($change['old_stock'])
            . ' ← ' . formatStock($change['new_stock']);

        if ($item['discount_ratio'] !== null && $item['final_price'] < $item['price']) {
            $line .= "\n  قیمت: " . formatPrice($item['final_price'])
                . ' (' . $item['discount_ratio'] . '% تخفیف)';
        } elseif ($item['final_price'] > 0) {
            $line .= "\n  قیمت: " . formatPrice($item['final_price']);
        }

        $lines[] = $line;
    }

    $lines[] = '';
    $lines[] = $restaurantUrl;

    return implode("\n", $lines);
}

function sendTelegramMessage($apiUrl, $chatId, $message) {
    $telegramData = [
        'chat_id'    => $chatId,
        'text'       => $message,
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

    return file_get_contents($apiUrl, false, $context);
}

$urls = [];
$resturant_urls = [];

foreach ($resturantIDS as $restName => $restKey) {
    $urls[$restName] = "https://apigw.snappfood.ir/menu-read-model/$restKey?lat=$lat&long=$long";
    $resturant_urls[$restName] = "https://snappfood.ir/restaurant/menu/$restKey/";
}

$logFile = __DIR__ . "/exist-times.txt";

$telegramBotToken = "8448585033:AAF2gy_3zMWfsPUcRCp5Uk5UHuqmY0nh6Sw";
$telegramApiUrl   = "https://shrill-art-9e6c.taram8757.workers.dev/bot" . $telegramBotToken . "/sendMessage";
$telegramChatId   = "95963053";

$stockState = loadStockState($stockStateFile);

foreach ($urls as $name => $url) {
    $jsonContent = file_get_contents($url);
    if ($jsonContent === false) {
        echo "Failed to fetch JSON from $name ($url).<br>";
        continue;
    }

    $data = json_decode($jsonContent, true);
    if ($data === null) {
        echo "Failed to decode JSON for $name.<br>";
        continue;
    }

    $menuCategories = $data['data']['menuCategories'] ?? [];
    $currentItems = extractDiscountItems($menuCategories, $discountCategoryTitles);
    $today = date('Y-m-d');

    if (empty($currentItems)) {
        unset($stockState[$name]);
        echo "No discount category in $name.<br>";
        continue;
    }

    if (!isset($stockState[$name])) {
        $stockState[$name] = [];
    }

    resetRestaurantSessionIfNeeded($stockState[$name], $today);

    $previousItems = $stockState[$name]['items'] ?? [];
    $itemCount = count($currentItems);
    echo "Discount found in $name ($itemCount item(s)).<br>";

    $shouldActivationNotify = empty($stockState[$name]['activation_sent']);
    $stockChanges = detectStockChanges($currentItems, $previousItems);
    $shouldStockNotify = empty($stockState[$name]['stock_change_sent']) && !empty($stockChanges);

    if (!$shouldActivationNotify && !$shouldStockNotify) {
        if (!empty($stockChanges)) {
            echo "Stock changed in $name but already notified for this session.<br>";
        } else {
            echo "No notification needed for $name.<br>";
        }
    } else {
        if ($shouldActivationNotify) {
            $telegramMessage = buildActivationMessage($name, array_values($currentItems), $resturant_urls[$name]);
            $result = sendTelegramMessage($telegramApiUrl, $telegramChatId, $telegramMessage);

            if ($result === false) {
                echo "Failed to send activation Telegram for $name.<br>";
            } else {
                $stockState[$name]['activation_sent'] = true;
                $logEntry = date("Y-m-d H:i:s") . " - $name: discount activated ($itemCount item(s))" . PHP_EOL;
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
                echo "Telegram sent for $name activation.<br>";
            }
        }

        if ($shouldStockNotify) {
            $telegramMessage = buildStockChangeMessage($name, $stockChanges, $resturant_urls[$name]);
            $result = sendTelegramMessage($telegramApiUrl, $telegramChatId, $telegramMessage);

            if ($result === false) {
                echo "Failed to send stock change Telegram for $name.<br>";
            } else {
                $stockState[$name]['stock_change_sent'] = true;
                $changeSummary = implode(', ', array_map(function ($change) {
                    return $change['item']['title'] . ': ' . formatStock($change['old_stock']) . '→' . formatStock($change['new_stock']);
                }, $stockChanges));
                $logEntry = date("Y-m-d H:i:s") . " - $name: stock changed ($changeSummary)" . PHP_EOL;
                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
                echo "Telegram sent for $name stock change.<br>";
            }
        }
    }

    $stockState[$name]['items'] = $currentItems;
}

saveStockState($stockStateFile, $stockState);
?>
