<?php
date_default_timezone_set('Asia/Tehran');

const TOP_ADS_COUNT = 5;

$searches = [
    [
        'id'             => 'office',
        'label'          => 'اجاره دفترکار مشهد',
        'heading'        => '🏢 *آگهی جدید دفترکار مشهد*',
        'url'                  => 'https://divar.ir/s/mashhad/rent-office?credit=5-50000000&rent=3000000-8000000',
        'applyIgnore'          => true,
        'dedupePermanentByUrl' => true,
    ],
    [
        'id'                  => 'windows_tablet',
        'label'               => 'تبلت ویندوزی مشهد',
        'heading'             => '📱 *آگهی جدید تبلت ویندوزی مشهد*',
        'url'                 => 'https://divar.ir/s/mashhad/tablet?os=Windows%2C',
        'applyIgnore'         => false,
        'dedupePermanentByUrl' => true,
    ],
    [
        'id'                   => 'tablet_computers',
        'label'                => 'تبلت کامپیوتر ۵–۲۲ میلیون مشهد',
        'heading'              => '📱 *آگهی جدید تبلت مشهد (کامپیوتر)*',
        'url'                  => 'https://divar.ir/s/mashhad/computers?price=5000000-22000000&q=' . rawurlencode('تبلت'),
        'applyIgnore'          => false,
        'dedupePermanentByUrl' => true,
    ],
];

$telegramBotToken = '8399338777:AAHOzILkxgXbBA9Yz1aX-CSEGc-2cn_J0Po';
$telegramApiUrl   = 'https://api.telegram.org/bot' . $telegramBotToken . '/sendMessage';
$telegramChatId   = '95963053';
 
$stateFile = __DIR__ . '/divar-last-tokens.json';

function fetchDivarPage(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: fa-IR,fa;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($html === false || $html === '') {
        throw new RuntimeException('Failed to fetch Divar page: ' . $error);
    }

    return $html;
}

function extractPreloadedState(string $html): array
{
    $pos = strpos($html, 'window.__PRELOADED_STATE__');
    if ($pos === false) {
        throw new RuntimeException('__PRELOADED_STATE__ not found in page');
    }

    $start = strpos($html, '=', $pos);
    if ($start === false) {
        throw new RuntimeException('Invalid __PRELOADED_STATE__ format');
    }
    $start++;

    $json = '';
    $depth = 0;
    $inString = false;
    $escape = false;
    $len = strlen($html);

    for ($i = $start; $i < $len; $i++) {
        $c = $html[$i];
        if ($depth === 0 && $c !== '{') {
            continue;
        }

        $json .= $c;

        if ($escape) {
            $escape = false;
            continue;
        }
        if ($c === '\\') {
            $escape = true;
            continue;
        }
        if ($c === '"') {
            $inString = !$inString;
            continue;
        }
        if ($inString) {
            continue;
        }
        if ($c === '{') {
            $depth++;
        }
        if ($c === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
    }

    $state = json_decode($json, true);
    if (!is_array($state)) {
        throw new RuntimeException('Failed to decode __PRELOADED_STATE__: ' . json_last_error_msg());
    }

    return $state;
}

function findPostRows(array $node, array &$rows): void
{
    if (($node['widget_type'] ?? '') === 'POST_ROW' && isset($node['data']['token'])) {
        $rows[] = $node['data'];
        return;
    }
    if (($node['widgetType'] ?? '') === 'POST_ROW' && isset($node['dto']['data']['token'])) {
        $rows[] = $node['dto']['data'];
        return;
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            findPostRows($value, $rows);
        }
    }
}

function getTopAds(string $html, int $limit): array
{
    $state = extractPreloadedState($html);
    $rows = [];
    findPostRows($state, $rows);

    return array_slice($rows, 0, $limit);
}

function loadState(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    if (isset($data[0]) && is_string($data[0])) {
        return ['office' => $data];
    }
    return $data;
}

function saveState(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function adUrl(array $ad): string
{
    $token = $ad['token'] ?? '';
    return $token === '' ? '' : 'https://divar.ir/v/' . $token;
}

function getSearchLastTokens($searchState): array
{
    if (!is_array($searchState)) {
        return [];
    }
    if (isset($searchState['lastTokens'])) {
        return $searchState['lastTokens'];
    }
    if (isset($searchState['notifiedUrls'])) {
        return [];
    }
    return array_values(array_filter($searchState, 'is_string'));
}

function getNotifiedUrls($searchState): array
{
    if (!is_array($searchState)) {
        return [];
    }
    if (isset($searchState['notifiedUrls'])) {
        return $searchState['notifiedUrls'];
    }
    $urls = [];
    foreach (array_values(array_filter($searchState, 'is_string')) as $token) {
        $urls[] = 'https://divar.ir/v/' . $token;
    }
    return $urls;
}

function acquireStateLock(string $stateFile)
{
    $handle = fopen($stateFile . '.lock', 'c+');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function releaseStateLock($handle): void
{
    if ($handle === false) {
        return;
    }
    flock($handle, LOCK_UN);
    fclose($handle);
}

function sendTelegram(string $message, string $apiUrl, string $chatId): bool
{
    $payload = [
        'chat_id'                  => $chatId,
        'text'                     => $message,
        'parse_mode'               => 'Markdown',
        'disable_web_page_preview' => false,
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $result = curl_exec($ch);
    $ok = $result !== false;
    curl_close($ch);

    return $ok;
}

function normalizeFa(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    return strtr($text, [
        'ي' => 'ی',
        'ك' => 'ک',
        'ة' => 'ه',
        '‌' => ' ',
    ]);
}

function isIgnoredAd(array $ad): bool
{
    $text = normalizeFa(implode(' ', [
        $ad['title'] ?? '',
        $ad['middle_description_text'] ?? '',
        $ad['bottom_description_text'] ?? '',
    ]));

    $keywords = [
        'لاین',
        'سالن زیبایی',
        'کلینیک زیبایی',
        'وکالت',
        'همخونه',
        'شنیون',
        'مژه',
        'میکاپ',
        'ناخن',
        'فشیال',
        'فیشیال',
        'کراتین',
        'بدلیجات',
        'آرایش',
        'وکیل',
        'رنگ',
        'لوازم ارایشی',
        'لوازم آرایشی',
        'اتاق در سالن',
        'سالن',
    ];

    foreach ($keywords as $keyword) {
        if (mb_strpos($text, normalizeFa($keyword)) !== false) {
            return true;
        }
    }

    return false;
}

function formatAdMessage(array $ad, string $heading): string
{
    $token = $ad['token'] ?? '';
    $title = $ad['title'] ?? 'بدون عنوان';
    $rent  = $ad['middle_description_text'] ?? '';
    $place = $ad['bottom_description_text'] ?? '';
    $link  = 'https://divar.ir/v/' . $token;

    $lines = [
        $heading,
        '',
        '*' . $title . '*',
    ];

    if ($rent !== '') {
        $lines[] = '💰 ' . $rent;
    }
    if ($place !== '') {
        $lines[] = '📍 ' . $place;
    }
    $lines[] = '';
    $lines[] = '[مشاهده آگهی](' . $link . ')';

    return implode("\n", $lines);
}

$errors = [];
$results = [];
$sentCount = 0;
$skippedConcurrent = false;
$stateLock = acquireStateLock($stateFile);
if ($stateLock === false) {
    $skippedConcurrent = true;
} else {
    $state = loadState($stateFile);
}

if (!$skippedConcurrent) {
foreach ($searches as $search) {
    $searchId = $search['id'];
    $ads = [];
    $newAds = [];

    try {
        $html = fetchDivarPage($search['url']);
        $ads = getTopAds($html, TOP_ADS_COUNT);
        $searchState = $state[$searchId] ?? [];
        $currentTokens = array_column($ads, 'token');
        $permanentByUrl = !empty($search['dedupePermanentByUrl']);
        $notifiedUrls = $permanentByUrl ? getNotifiedUrls($searchState) : [];
        $lastTokens = getSearchLastTokens($searchState);

        foreach ($ads as $ad) {
            if ($permanentByUrl) {
                $url = adUrl($ad);
                if ($url === '' || in_array($url, $notifiedUrls, true)) {
                    continue;
                }
            } elseif (in_array($ad['token'], $lastTokens, true)) {
                continue;
            }
            if (!empty($search['applyIgnore']) && isIgnoredAd($ad)) {
                continue;
            }
            $newAds[] = $ad;
        }

        foreach ($newAds as $ad) {
            $url = adUrl($ad);
            if ($permanentByUrl && $url !== '') {
                $notifiedUrls[] = $url;
                $state[$searchId] = [
                    'lastTokens'   => array_values($currentTokens),
                    'notifiedUrls' => array_values(array_unique($notifiedUrls)),
                ];
                saveState($stateFile, $state);
            }

            if (sendTelegram(formatAdMessage($ad, $search['heading']), $telegramApiUrl, $telegramChatId)) {
                $sentCount++;
            }
        }

        if ($permanentByUrl) {
            $state[$searchId] = [
                'lastTokens'   => array_values($currentTokens),
                'notifiedUrls' => array_values(array_unique($notifiedUrls)),
            ];
        } else {
            $state[$searchId] = array_values($currentTokens);
        }
    } catch (Throwable $e) {
        $errors[] = $search['label'] . ': ' . $e->getMessage();
    }

    $results[] = [
        'search'       => $search,
        'ads'          => $ads,
        'newAds'       => $newAds,
        'notifiedUrls' => $permanentByUrl ? ($state[$searchId]['notifiedUrls'] ?? []) : [],
    ];
}

saveState($stateFile, $state);
releaseStateLock($stateLock);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>آگهی‌های دیوار مشهد</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        h2 { text-align: center; }
        .error { background: #fee; color: #900; padding: 12px; border-radius: 8px; max-width: 900px; margin: 0 auto 20px; }
        .info { background: #e8f4fd; padding: 12px; border-radius: 8px; max-width: 900px; margin: 0 auto 20px; text-align: center; }
        table { border-collapse: collapse; width: 95%; max-width: 1000px; margin: 0 auto; background: #fff; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
        th { background: #eee; }
        tr.new td { background: #e8ffe8; }
        tr.ignored td { background: #f5f5f5; color: #999; }
        a { color: #c0392b; text-decoration: none; }
    </style>
</head>
<body>
<h2>آگهی‌های دیوار مشهد</h2>

<?php if (!empty($errors)): ?>
    <div class="error"><?php echo htmlspecialchars(implode(' | ', $errors)); ?></div>
<?php endif; ?>

<div class="info">
    <?php if ($skippedConcurrent): ?>
        اجرای دیگری در حال پردازش است؛ برای جلوگیری از پیام تکراری این دور رد شد.
    <?php else: ?>
    <?php
    $hasNew = false;
    foreach ($results as $result) {
        if (!empty($result['newAds'])) {
            $hasNew = true;
            break;
        }
    }
    ?>
    <?php if ($sentCount > 0): ?>
        <?php echo $sentCount; ?> پیام جدید به تلگرام ارسال شد.
    <?php elseif (empty($errors) && !$hasNew): ?>
        آگهی جدیدی در ۵ تای اول هیچ‌کدام از جستجوها نیست.
    <?php elseif ($hasNew && $sentCount === 0): ?>
        آگهی جدید یافت شد ولی ارسال تلگرام ناموفق بود.
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php foreach ($results as $result):
    $search = $result['search'];
    $ads = $result['ads'];
    $newAds = $result['newAds'];
    $notifiedUrls = $result['notifiedUrls'] ?? [];
    $permanentByUrl = !empty($search['dedupePermanentByUrl']);
    ?>
    <h2>۵ آگهی اول — <?php echo htmlspecialchars($search['label']); ?></h2>
    <table>
        <tr>
            <th>#</th>
            <th>عنوان</th>
            <th>اجاره / رهن</th>
            <th>محله</th>
            <th>لینک</th>
            <th>وضعیت</th>
        </tr>
        <?php if (!empty($ads)): ?>
            <?php foreach ($ads as $i => $ad):
                $ignored = !empty($search['applyIgnore']) && isIgnoredAd($ad);
                $link = adUrl($ad);
                $isNew = false;
                foreach ($newAds as $newAd) {
                    if (($newAd['token'] ?? '') === ($ad['token'] ?? '')) {
                        $isNew = true;
                        break;
                    }
                }
                $alreadyNotified = $permanentByUrl && $link !== '' && in_array($link, $notifiedUrls, true);
                $rowClass = $ignored ? 'ignored' : ($isNew ? 'new' : '');
                if ($ignored) {
                    $status = 'فیلتر شده';
                } elseif ($isNew) {
                    $status = 'جدید';
                } elseif ($alreadyNotified) {
                    $status = 'اطلاع‌رسانی شده';
                } else {
                    $status = 'قبلی';
                }
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo htmlspecialchars($ad['title'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($ad['middle_description_text'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($ad['bottom_description_text'] ?? '-'); ?></td>
                    <td><a href="<?php echo htmlspecialchars($link); ?>" target="_blank">مشاهده</a></td>
                    <td><?php echo $status; ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="6">آگهی‌ای یافت نشد.</td></tr>
        <?php endif; ?>
    </table>
<?php endforeach; ?>
</body>
</html>
