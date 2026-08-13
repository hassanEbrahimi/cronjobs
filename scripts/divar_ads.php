<?php
date_default_timezone_set('Asia/Tehran');

const DIVAR_SEARCH_URL = 'https://divar.ir/s/mashhad/rent-office?credit=5-50000000&rent=3000000-8000000';
const TOP_ADS_COUNT = 5;

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

    if (empty($rows)) {
        throw new RuntimeException('No ads found on page');
    }

    return array_slice($rows, 0, $limit);
}

function loadLastTokens(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveLastTokens(string $file, array $tokens): void
{
    file_put_contents($file, json_encode(array_values($tokens), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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

function formatAdMessage(array $ad): string
{
    $token = $ad['token'] ?? '';
    $title = $ad['title'] ?? 'بدون عنوان';
    $rent  = $ad['middle_description_text'] ?? '';
    $place = $ad['bottom_description_text'] ?? '';
    $link  = 'https://divar.ir/v/' . $token;

    $lines = [
        '🏢 *آگهی جدید دفترکار مشهد*',
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
$ads = [];
$newAds = [];
$sentCount = 0;

try {
    $html = fetchDivarPage(DIVAR_SEARCH_URL);
    $ads = getTopAds($html, TOP_ADS_COUNT);
    $lastTokens = loadLastTokens($stateFile);
    $currentTokens = array_column($ads, 'token');

    foreach ($ads as $ad) {
        if (in_array($ad['token'], $lastTokens, true)) {
            continue;
        }
        if (isIgnoredAd($ad)) {
            continue;
        }
        $newAds[] = $ad;
    }

    foreach ($newAds as $ad) {
        if (sendTelegram(formatAdMessage($ad), $telegramApiUrl, $telegramChatId)) {
            $sentCount++;
        }
    }

    saveLastTokens($stateFile, $currentTokens);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>آگهی‌های دفترکار مشهد - دیوار</title>
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
<h2>۵ آگهی اول — اجاره دفترکار مشهد</h2>

<?php if (!empty($errors)): ?>
    <div class="error"><?php echo htmlspecialchars(implode(' | ', $errors)); ?></div>
<?php endif; ?>

<div class="info">
    <?php if ($sentCount > 0): ?>
        <?php echo $sentCount; ?> پیام جدید به تلگرام ارسال شد.
    <?php elseif (empty($errors) && empty($newAds)): ?>
        آگهی جدیدی در ۵ تای اول نیست.
    <?php elseif (!empty($newAds) && $sentCount === 0): ?>
        آگهی جدید یافت شد ولی ارسال تلگرام ناموفق بود.
    <?php endif; ?>
</div>

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
            $ignored = isIgnoredAd($ad);
            $isNew = in_array($ad, $newAds, true);
            $link = 'https://divar.ir/v/' . ($ad['token'] ?? '');
            $rowClass = $ignored ? 'ignored' : ($isNew ? 'new' : '');
            if ($ignored) {
                $status = 'فیلتر شده';
            } elseif ($isNew) {
                $status = 'جدید';
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
</body>
</html>
