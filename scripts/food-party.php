<?php
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Tehran');

const DEFAULT_LAT   = 36.2922;
const DEFAULT_LONG  = 59.57219;
const API_URL       = 'https://snappfood.ir/search/api/v2/food-party';
const PAGE_SIZE     = 100;
const CACHE_FILE    = __DIR__ . '/food-party.json';
const CACHE_TTL_SEC = 300;

$keyword  = isset($_GET['q']) ? trim($_GET['q']) : '';
$maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (int) $_GET['max_price'] : 0;
$lat      = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float) $_GET['lat'] : DEFAULT_LAT;
$long     = isset($_GET['long']) && $_GET['long'] !== '' ? (float) $_GET['long'] : DEFAULT_LONG;
$sort     = isset($_GET['sort']) ? $_GET['sort'] : 'price_asc';
$refresh  = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$submitted = isset($_GET['q']) || isset($_GET['max_price']) || isset($_GET['sort']);

function fetchUrl(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Accept-Language: fa-IR,fa;q=0.9',
        ],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $body === '') {
        throw new RuntimeException('خطا در دریافت داده از اسنپ‌فود: ' . $error);
    }

    if ($status >= 400) {
        throw new RuntimeException('خطای HTTP ' . $status);
    }

    return $body;
}

function loadCachedPartyData(bool $allowStale = false): ?array
{
    if (!is_file(CACHE_FILE)) {
        return null;
    }

    if (!$allowStale && (time() - filemtime(CACHE_FILE)) > CACHE_TTL_SEC) {
        return null;
    }

    $data = json_decode(file_get_contents(CACHE_FILE), true);
    if (!is_array($data) || empty($data['success']) || !isset($data['data']['products'])) {
        return null;
    }

    return $data['data'];
}

function savePartyCache(array $partyData): void
{
    file_put_contents(
        CACHE_FILE,
        json_encode(['success' => true, 'data' => $partyData], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function fetchFoodPartyPage(float $lat, float $long, int $page): array
{
    $url = API_URL . '?' . http_build_query([
        'lat'            => $lat,
        'long'           => $long,
        'optionalClient' => 'PWA',
        'superType'      => 1,
        'sort'           => '',
        'page'           => $page,
        'page_size'      => PAGE_SIZE,
    ]);

    $data = json_decode(fetchUrl($url), true);
    if (!is_array($data)) {
        throw new RuntimeException('پاسخ API نامعتبر است.');
    }

    if (empty($data['success']) || !isset($data['data']['products'])) {
        $message = $data['message'] ?? 'داده‌ای یافت نشد.';
        throw new RuntimeException((string) $message);
    }

    return $data['data'];
}

function fetchFoodParty(float $lat, float $long, bool $refresh = false): array
{
    if (!$refresh) {
        $cached = loadCachedPartyData();
        if ($cached !== null) {
            return $cached;
        }
    }

    try {
        $firstPage = fetchFoodPartyPage($lat, $long, 0);
        $products = $firstPage['products'];
        $totalCount = (int) ($firstPage['total_count'] ?? count($products));

        for ($page = 1; $page * PAGE_SIZE < $totalCount; $page++) {
            $nextPage = fetchFoodPartyPage($lat, $long, $page);
            $products = array_merge($products, $nextPage['products']);
        }

        $firstPage['products'] = $products;
        savePartyCache($firstPage);

        return $firstPage;
    } catch (Throwable $e) {
        $stale = loadCachedPartyData(true);
        if ($stale !== null) {
            return $stale;
        }
        throw $e;
    }
}

function formatPrice(int $amount): string
{
    return number_format($amount) . ' تومان';
}

function buildRestaurantUrl(string $vendorTitle, string $vendorCode): string
{
    $slug = preg_replace('/\s+/u', '_', trim($vendorTitle));
    $path = rawurlencode($slug . '-r-' . $vendorCode);
    return 'https://snappfood.ir/restaurant/menu/' . $path . '/?is_pickup=0';
}

function buildFoodUrl(array $product): string
{
    $variationId = (int) ($product['productVariationId'] ?? $product['id'] ?? 0);
    $vendorId    = trim((string) ($product['vendorId'] ?? ''));
    $vendorCode  = trim((string) ($product['vendorCode'] ?? ''));
    $superType   = (int) ($product['superTypeId'] ?? 1);
    $dealCode    = trim((string) ($product['deal_project_code'] ?? ''));
    $dealId      = (int) ($product['deal_project_id'] ?? 0);

    if ($variationId <= 0 || $vendorId === '' || $vendorCode === '' || $dealCode === '' || $dealId <= 0) {
        return '';
    }

    return 'https://snappfood.ir/product-details/party/' . $variationId . '/?' . http_build_query([
        'vendorId'        => $vendorId,
        'code'            => $vendorCode,
        'superType'       => $superType,
        'dealProjectCode' => $dealCode,
        'dealProjectId'   => $dealId,
    ]);
}

function normalizeProduct(array $product): array
{
    $price    = (int) ($product['price'] ?? 0);
    $discount = (int) ($product['discount'] ?? 0);
    $final    = max(0, $price - $discount);

    $title = trim($product['productVariationTitle'] ?? $product['title'] ?? '');
    $vendorTitle = trim($product['vendorTitle'] ?? '');
    $vendorCode  = trim($product['vendorCode'] ?? '');

    return [
        'title'          => $title,
        'vendor_title'   => $vendorTitle,
        'vendor_code'    => $vendorCode,
        'restaurant_url' => $vendorCode !== '' ? buildRestaurantUrl($vendorTitle, $vendorCode) : '',
        'food_url'       => buildFoodUrl($product),
        'description'    => trim($product['description'] ?? ''),
        'price'          => $price,
        'discount'       => $discount,
        'final_price'    => $final,
        'discount_ratio' => (int) ($product['discountRatio'] ?? 0),
        'rating'         => $product['rating'] ?? null,
        'remaining'      => $product['remaining'] ?? $product['stock'] ?? null,
        'min_order'      => (int) ($product['minOrder'] ?? 0),
        'delivery_fee'   => (int) ($product['deliveryFeeAfterDiscount'] ?? $product['deliveryFee'] ?? 0),
        'image'          => $product['main_image'] ?? $product['image'] ?? '',
        'is_pro'         => !empty($product['is_pro']),
        'is_eco'         => !empty($product['is_eco']),
    ];
}

function matchesFilters(array $item, string $keyword, int $maxPrice): bool
{
    if ($maxPrice > 0 && $item['final_price'] > $maxPrice) {
        return false;
    }

    if ($keyword === '') {
        return true;
    }

    $haystack = $item['title'] . ' ' . $item['vendor_title'] . ' ' . $item['description'];
    return mb_stripos($haystack, $keyword) !== false;
}

function sortItems(array &$items, string $sort): void
{
    usort($items, function (array $a, array $b) use ($sort) {
        switch ($sort) {
            case 'price_desc':
                return $b['final_price'] <=> $a['final_price'];
            case 'discount':
                return $b['discount_ratio'] <=> $a['discount_ratio'];
            case 'rating':
                return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
            case 'price_asc':
            default:
                return $a['final_price'] <=> $b['final_price'];
        }
    });
}

$error = null;
$partyTitle = '';
$partyPeriod = '';
$products = [];
$totalCount = 0;

if ($submitted) {
    try {
        $partyData = fetchFoodParty($lat, $long, $refresh);
        $partyTitle = trim($partyData['title'] ?? 'فود پارتی');
        $partyPeriod = trim($partyData['activePeriodTitle'] ?? '');
        $totalCount = (int) ($partyData['total_count'] ?? count($partyData['products']));

        foreach ($partyData['products'] as $product) {
            if (($product['superTypeAlias'] ?? '') !== 'RESTAURANT') {
                continue;
            }

            $item = normalizeProduct($product);
            if ($item['title'] === '' || $item['vendor_title'] === '') {
                continue;
            }
            if (!matchesFilters($item, $keyword, $maxPrice)) {
                continue;
            }
            $products[] = $item;
        }

        sortItems($products, $sort);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>جستجوی فود پارتی اسنپ‌فود</title>
    <style>
        :root {
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #ff00a6;
            --accent-dark: #d10088;
            --border: #e5e7eb;
            --success: #059669;
            --danger: #dc2626;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Tahoma, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        .wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 1.6rem;
        }

        .subtitle {
            color: var(--muted);
            margin-bottom: 24px;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        }

        form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
        }

        label {
            display: block;
            font-size: 0.9rem;
            margin-bottom: 6px;
            color: var(--muted);
        }

        input, select, button {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font: inherit;
            background: #fff;
        }

        button {
            background: var(--accent);
            color: #fff;
            border: none;
            cursor: pointer;
            font-weight: 700;
        }

        button:hover { background: var(--accent-dark); }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            color: var(--muted);
            font-size: 0.92rem;
        }

        .meta strong { color: var(--text); }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: #f3f4f6;
        }

        .card-body {
            padding: 14px 16px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .food-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0;
        }

        .food-title a {
            color: inherit;
            text-decoration: none;
        }

        .food-title a:hover {
            color: var(--accent-dark);
            text-decoration: underline;
        }

        .food-link {
            font-size: 0.88rem;
            font-weight: 700;
        }

        .food-link a {
            color: var(--accent-dark);
            text-decoration: none;
        }

        .food-link a:hover { text-decoration: underline; }

        .vendor {
            color: var(--muted);
            font-size: 0.92rem;
        }

        .vendor a {
            color: var(--accent-dark);
            text-decoration: none;
            font-weight: 700;
        }

        .vendor a:hover { text-decoration: underline; }

        .desc {
            color: var(--muted);
            font-size: 0.88rem;
            margin: 0;
        }

        .price-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: auto;
        }

        .final-price {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--success);
        }

        .old-price {
            text-decoration: line-through;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            background: #fee2e2;
            color: var(--danger);
        }

        .tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 0.8rem;
            color: var(--muted);
        }

        .tag {
            background: #f3f4f6;
            border-radius: 999px;
            padding: 2px 8px;
        }

        .alert {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 14px;
        }

        .empty {
            text-align: center;
            color: var(--muted);
            padding: 40px 16px;
        }

        @media (max-width: 640px) {
            form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>جستجوی فود پارتی</h1>
    <p class="subtitle">جستجو بر اساس کلیدواژه و حداکثر قیمت در لیست فود پارتی اسنپ‌فود مشهد</p>

    <div class="panel">
        <form method="get">
            <div>
                <label for="q">کلیدواژه</label>
                <input type="text" id="q" name="q" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>" placeholder="مثلاً: چلو، برگر، پیتزا">
            </div>
            <div>
                <label for="max_price">حداکثر قیمت (تومان)</label>
                <input type="number" id="max_price" name="max_price" min="0" step="1000" value="<?= $maxPrice > 0 ? $maxPrice : '' ?>" placeholder="مثلاً: 150000">
            </div>
            <div>
                <label for="sort">مرتب‌سازی</label>
                <select id="sort" name="sort">
                    <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>ارزان‌ترین</option>
                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>گران‌ترین</option>
                    <option value="discount" <?= $sort === 'discount' ? 'selected' : '' ?>>بیشترین تخفیف</option>
                    <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>بالاترین امتیاز</option>
                </select>
            </div>
            <div>
                <label for="lat">عرض جغرافیایی</label>
                <input type="text" id="lat" name="lat" value="<?= htmlspecialchars((string) $lat, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label for="long">طول جغرافیایی</label>
                <input type="text" id="long" name="long" value="<?= htmlspecialchars((string) $long, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <button type="submit">جستجو</button>
            </div>
            <div>
                <label for="refresh">&nbsp;</label>
                <button type="submit" name="refresh" value="1" style="background:#374151;">بروزرسانی از API</button>
            </div>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($submitted): ?>
        <div class="meta">
            <?php if ($partyTitle !== ''): ?>
                <span><strong>رویداد:</strong> <?= htmlspecialchars($partyTitle, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if ($partyPeriod !== ''): ?>
                <span><strong>بازه:</strong> <?= htmlspecialchars($partyPeriod, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <span><strong>نتایج:</strong> <?= count($products) ?> از <?= $totalCount ?></span>
        </div>

        <?php if (count($products) === 0): ?>
            <div class="panel empty">غذایی با این فیلترها پیدا نشد.</div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($products as $item): ?>
                    <article class="card">
                        <?php if ($item['image'] !== ''): ?>
                            <img src="<?= htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                        <?php endif; ?>
                        <div class="card-body">
                            <h2 class="food-title">
                                <?php if ($item['food_url'] !== ''): ?>
                                    <a href="<?= htmlspecialchars($item['food_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </h2>
                            <?php if ($item['food_url'] !== ''): ?>
                                <div class="food-link">
                                    <a href="<?= htmlspecialchars($item['food_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">لینک مستقیم غذا</a>
                                </div>
                            <?php endif; ?>
                            <div class="vendor">
                                رستوران:
                                <?php if ($item['restaurant_url'] !== ''): ?>
                                    <a href="<?= htmlspecialchars($item['restaurant_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                                        <?= htmlspecialchars($item['vendor_title'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($item['vendor_title'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($item['description'] !== ''): ?>
                                <p class="desc"><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <div class="price-row">
                                <span class="final-price"><?= formatPrice($item['final_price']) ?></span>
                                <?php if ($item['discount'] > 0): ?>
                                    <span class="old-price"><?= formatPrice($item['price']) ?></span>
                                    <span class="badge"><?= $item['discount_ratio'] ?>٪ تخفیف</span>
                                <?php endif; ?>
                            </div>

                            <div class="tags">
                                <?php if ($item['rating'] !== null): ?>
                                    <span class="tag">امتیاز: <?= number_format((float) $item['rating'], 1) ?></span>
                                <?php endif; ?>
                                <?php if ($item['remaining'] !== null && $item['remaining'] !== ''): ?>
                                    <span class="tag">موجودی: <?= htmlspecialchars((string) $item['remaining'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <?php if ($item['min_order'] > 0): ?>
                                    <span class="tag">حداقل سفارش: <?= formatPrice($item['min_order']) ?></span>
                                <?php endif; ?>
                                <?php if ($item['delivery_fee'] >= 0): ?>
                                    <span class="tag">پیک: <?= formatPrice($item['delivery_fee']) ?></span>
                                <?php endif; ?>
                                <?php if ($item['is_pro']): ?>
                                    <span class="tag">PRO</span>
                                <?php endif; ?>
                                <?php if ($item['is_eco']): ?>
                                    <span class="tag">اکو</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
