<?php
/**
 * 随机视频接口 - 代理转发版
 *
 * 每次调用返回一个随机短视频的直链，支持 JSON 返回与 302 直连播放两种模式
 *
 * 使用方法:
 *   GET /API/video.php?apikey=sk_xxx              获取随机视频地址（JSON）
 *   GET /API/video.php?apikey=sk_xxx&redirect=1   302 直接跳转到视频地址（可用于 <video src>）
 *
 * 参数:
 *   redirect  - 可选，设为 1 时直接 302 跳转到视频地址
 *   type      - 可选，预留参数，视频分类（如 yuzu），默认 yuzu
 *
 * 响应示例:
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": {
 *     "url": "https://alimov2.a.kwimgs.com/.../xxx.mp4",
 *     "type": "mp4",
 *     "source": "yuzu",
 *     "timestamp": 1234567890
 *   }
 * }
 *
 * 数据来源: http://api.yujn.cn/api/yuzu.php （302 重定向到随机视频）
 */

// 引入 API Key 验证中间件(必须在其他代码之前引入)
require_once __DIR__ . '/../Core/api_key_auth.php';

// 引入公共函数（用于调用统计）
require_once __DIR__ . '/function.php';

// ====== 参数处理 ======
$redirect = isset($_GET['redirect']) && intval($_GET['redirect']) === 1;
$type     = trim($_GET['type'] ?? 'yuzu');

// 分类映射（预留扩展，目前仅 yuzu）
$typeMap = [
    'yuzu' => 'https://api.yujn.cn/api/yuzu.php',
];
// 兜底：未知分类默认走 yuzu
$upstreamUrl = $typeMap[$type] ?? $typeMap['yuzu'];

// ====== 请求上游接口（不跟随重定向，仅取 Location 头）======
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $upstreamUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HEADER, 1);          // 返回响应头
curl_setopt($ch, CURLOPT_NOBODY, 0);          // GET 请求（302 响应体很小）
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);  // 关键：不跟随重定向，直接拿到 302
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 API-Proxy/1.0');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: text/html, application/json, */*'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error   = curl_error($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

// ====== 错误处理 ======
if ($error) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(502);
    echo json_encode([
        'code' => 502,
        'msg'  => '数据源连接失败，请稍后重试',
        'data' => ['error' => $error]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($response)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(502);
    echo json_encode([
        'code' => 502,
        'msg'  => '数据源无响应',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 解析响应头，提取 Location ======
$rawHeaders = substr($response, 0, $headerSize);
$videoUrl   = '';

// 逐行解析所有响应头（兼容单次 302，也兼容多跳场景）
$lines = preg_split('/\r\n/', $rawHeaders);
foreach ($lines as $line) {
    if (preg_match('/^Location:\s*(.+)$/i', $line, $m)) {
        $videoUrl = trim($m[1]);
        // 取最后一跳的 Location（如果有多次重定向）
    }
}

if (empty($videoUrl)) {
    // 上游可能直接返回 200 + 视频流（未走 302），无法提取直链
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(502);
    echo json_encode([
        'code' => 502,
        'msg'  => '未能获取视频地址（数据源未返回重定向地址）',
        'data' => [
            'http_code' => $httpCode,
            'tip'       => '上游接口可能已变更，请联系管理员'
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 推断视频格式
$ext = 'mp4';
if (preg_match('/\.(mp4|flv|m3u8|mov|avi|webm)(?:\?|$)/i', $videoUrl, $em)) {
    $ext = strtolower($em[1]);
}

// ====== 302 直连模式 ======
if ($redirect) {
    header('Location: ' . $videoUrl);
    http_response_code(302);
    exit;
}

// ====== JSON 模式 ======
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 0,
    'msg'  => 'success',
    'data' => [
        'url'       => $videoUrl,
        'type'      => $ext,
        'source'    => $type,
        'timestamp' => time()
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
