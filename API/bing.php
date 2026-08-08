<?php
/**
 * Bing每日壁纸接口 - 免费公开接口
 *
 * 无需 API Key 即可调用，返回 Bing 每日高清壁纸信息
 *
 * 使用方法:
 *   GET /API/bing.php              获取今日壁纸（默认 1920x1080）
 *   GET /API/bing.php?size=UHD     获取 4K 超清壁纸
 *   GET /API/bing.php?size=1280x720 获取 720p 壁纸
 *   GET /API/bing.php?idx=-1       获取昨日壁纸
 *   GET /API/bing.php?n=8          获取最近 8 天的壁纸
 *   GET /API/bing.php?redirect=1   直接 302 跳转到壁纸图片地址
 *
 * 响应示例:
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": [
 *     {
 *       "title": "雪山之巅的晨光",
 *       "copyright": "©摄影师姓名",
 *       "image_url": "https://...jpg",
 *       "date": "2026-07-31"
 *     }
 *   ]
 * }
 */

// 引入 API Key 验证中间件（此接口已在 $publicAPIs 列表中，无需 Key）
require_once __DIR__ . '/../Core/api_key_auth.php';

// 引入公共函数（用于调用统计）
require_once __DIR__ . '/function.php';

// 调用统计（api_id 设为 0，表示公开接口不统计到具体接口）
@addApiAccess(0);

// ====== 参数处理 ======
$size   = $_GET['size'] ?? '1920x1080';   // 图片尺寸
$idx    = intval($_GET['idx'] ?? 0);       // 起始偏移（0=今日，-1=昨日，...）
$n      = min(intval($_GET['n'] ?? 1), 8); // 获取数量，最多 8 张
$redirect = isset($_GET['redirect']);      // 是否直接跳转到图片

// 尺寸映射
$sizeMap = [
    'UHD'      => 'UHD',
    '4k'       => 'UHD',
    '1920x1080' => '1920x1080',
    '1080p'    => '1920x1080',
    '1280x720' => '1280x720',
    '720p'     => '1280x720',
    '640x480'  => '640x480',
    '480p'     => '640x480',
];
$resolution = $sizeMap[$size] ?? '1920x1080';

// ====== 请求 Bing 官方接口 ======
$apiUrl = 'https://cn.bing.com/HPImageArchive.aspx?format=js&idx=' . $idx . '&n=' . $n . '&mkt=zh-CN';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 API-Proxy/1.0');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

// ====== 错误处理 ======
if ($error || $httpCode !== 200 || empty($response)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => -1,
        'msg'  => 'Bing 接口请求失败',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 解析响应 ======
$bingData = json_decode($response, true);
if (!isset($bingData['images']) || !is_array($bingData['images'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => -1,
        'msg'  => 'Bing 接口数据解析失败',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 直接跳转模式 ======
if ($redirect) {
    $firstImage = $bingData['images'][0];
    $imageUrl = 'https://cn.bing.com' . $firstImage['urlbase'] . '_' . $resolution . '.jpg';
    header('Location: ' . $imageUrl);
    exit;
}

// ====== 组装响应数据 ======
$data = [];
foreach ($bingData['images'] as $img) {
    $data[] = [
        'title'      => $img['copyright'] ?? '',
        'copyright'  => $img['copyright'] ?? '',
        'image_url'  => 'https://cn.bing.com' . $img['urlbase'] . '_' . $resolution . '.jpg',
        'thumb_url'  => 'https://cn.bing.com' . $img['url'],
        'date'       => substr($img['enddate'] ?? '', 0, 4) . '-' .
                        substr($img['enddate'] ?? '', 4, 2) . '-' .
                        substr($img['enddate'] ?? '', 6, 2),
    ];
}

// ====== 输出 JSON ======
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 0,
    'msg'  => 'success',
    'data' => $data
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
