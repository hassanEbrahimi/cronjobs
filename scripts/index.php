<?php
header("Content-Type: text/html; charset=utf-8");

function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// گرفتن فیلتر از GET
$searchName = isset($_GET['name']) ? trim($_GET['name']) : "";

// 1. API لیست رستوران‌ها
$countt=500;
$listUrl = "https://snappfood.ir/search/api/v1/desktop/vendors-list?long=59.572&lat=36.292&optionalClient=WEBSITE&client=WEBSITE&deviceType=WEBSITE&appVersion=8.1.1&UDID=11d0baad-5033-4359-a817-38ceb019e7ee&page=0&page_size=$countt&filters=%7B%7D&category=%7B%22value%22:1,%22sub%22:[]%7D&query=&sp_alias=restaurant&city_name=mashhad&superType=[1]&cacheKey=%2Fservice%2Frestaurant%2Fcity%2Fmashhad%2F%3Fcategory%3D1%26page%3D2%3Flat%3D-1%26long%3D-1&extra-filter=&locale=fa";

$listJson = fetchUrl($listUrl);
$data = json_decode($listJson, true);

if (!isset($data['data']['finalResult'])) die("❌ No vendors found<br>");

foreach ($data['data']['finalResult'] as $rest) {
    $vendorCode = $rest['data']['code'] ?? '';
    $title      = $rest['data']['title'] ?? 'Unknown';
    $url        = "https://snappfood.ir/restaurant/menu/000-r-$vendorCode/";

    echo "<b>Restaurant:</b> $title<br>";
    echo "<b>URL:</b> <a href='$url' target='_blank'>$url</a><br>";

    if (!$vendorCode) {
        echo "⚠️ No vendor code<br><hr>";
        continue;
    }

    // 2. API جزئیات رستوران
    $detailsUrl = "https://snappfood.ir/mobile/v2/restaurant/details/dynamic?lat=-1&long=-1&optionalClient=WEBSITE&client=WEBSITE&deviceType=WEBSITE&appVersion=8.1.1&UDID=11d0baad-5033-4359-a817-38ceb019e7ee&vendorCode=$vendorCode&locationCacheKey=lat%3D-1%26long%3D-1&show_party=1&fetch-static-data=1&locale=fa";
    $detailsJson = fetchUrl($detailsUrl);
    $details = json_decode($detailsJson, true);

    // 3. جمع‌آوری غذاها
    $foods = [];
    if (isset($details['data']['menus'])) {
        foreach ($details['data']['menus'] as $menu) {
            foreach ($menu['products'] ?? [] as $prod) {
                $name = $prod['title'] ?? '';
                $price = intval($prod['price'] ?? 0);
                $finalPrice = intval($prod['price'] ?? 0)-intval($prod['discount'] ?? 0);

                if ($searchName === "" || mb_strpos($name, $searchName) !== false) {
                    $foods[] = [
                        "name"  => $name,
                        "price" => $price,
                        "fprice" => $finalPrice
                    ];
                }
            }
        }
    }

    // 4. مرتب‌سازی بر اساس قیمت
    usort($foods, function($a, $b) {
        return $a['fprice'] <=> $b['fprice']; // ascending
    });

    // 5. نمایش
    if (count($foods) > 0) {
        foreach ($foods as $f) {

            if($f['fprice'] != $f['price']){
                $discountPercent = (($f['price'] - $f['fprice']) / $f['price']) * 100;
                //echo "Discount - Original Price: {$f['price']}<br>";

                //if(($discountPercent>20 && $f['fprice']<160000) OR (strpos('c'.$f['name'],'چلو') && $f['fprice']<120000 ) ){
                if(  $f['fprice']<120000 && $f['fprice']>55000  ){
                    echo "🍲 {$f['name']} — 💰 {$f['fprice']} تومان";
                    echo " : " . round($discountPercent, 2) . "% <br>";
                    //echo "<span style='color:red' >BIGGG</span> <br>";
                }
            }

        }
    } else {
        //echo "❌ No foods matched<br>";
    }

    echo "<hr><br>";
}
?>
