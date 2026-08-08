<?php
/**
 * 抖音视频解析接口 - 代理转发版
 *
 * 解析抖音视频分享链接，获取视频/图集直链、封面、标题、作者等信息
 * 支持视频和图集两种内容类型
 *
 * 使用方法:
 *   GET /API/douyin.php?apikey=sk_xxx&url=https://v.douyin.com/xxxxx/
 *   GET /API/douyin.php?apikey=sk_xxx&url=https://v.douyin.com/xxxxx/&raw=1  返回上游原始 JSON
 *
 * 参数:
 *   url  - 必填，抖音视频分享链接
 *   raw  - 可选，设为 1 时返回数据源原始 JSON
 *
 * 响应示例:
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": {
 *     "type": "image",
 *     "author": "小橘仔",
 *     "aweme_id": "7664589810195517102",
 *     "title": "你的世界少了我真没关系吗。#健身 #马甲线 #腹肌 #健身穿搭",
 *     "cover": "https://...",
 *     "video_url": "",
 *     "play_url": "",
 *     "images": ["https://..."],
 *     "source_url": "https://v.douyin.com/xxxxx/"
 *   }
 * }
 *
 * 数据来源: https://api.yujn.cn/api/dy_jx.php
 */

// 引入 API Key 验证中间件(必须在其他代码之前引入)
require_once __DIR__ . '/../Core/api_key_auth.php';

// 引入公共函数（用于调用统计）
require_once __DIR__ . '/function.php';

// ====== 参数处理 ======
$url  = trim($_GET['url'] ?? $_POST['url'] ?? '');
$raw  = isset($_GET['raw']) && intval($_GET['raw']) === 1;

// 参数校验
if (empty($url)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => '参数错误：请提供 url 参数（抖音视频分享链接）',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// URL 合法性校验
$parsed = @parse_url($url);
$host   = strtolower($parsed['host'] ?? '');
$scheme = strtolower($parsed['scheme'] ?? '');
if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => '参数错误：url 必须是合法的 http/https 链接',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 域名白名单校验（防止 SSRF 滥用）
$allowedDomains = ['v.douyin.com', 'www.douyin.com', 'douyin.com', 'iesdouyin.com', 'www.iesdouyin.com'];
$isAllowed = false;
foreach ($allowedDomains as $domain) {
    if ($host === $domain || substr($host, -strlen($domain)) === '.' . $domain) {
        $isAllowed = true;
        break;
    }
}
if (!$isAllowed) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => '参数错误：url 必须是抖音视频分享链接',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 请求上游接口 ======
// 上游使用 msg 参数，对应抖音分享链接
$targetUrl = 'https://api.yujn.cn/api/dy_jx.php?msg=' . urlencode($url);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 API-Proxy/1.0');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json, */*'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error   = curl_error($ch);
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

if ($httpCode !== 200 || empty($response)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(502);
    echo json_encode([
        'code' => 502,
        'msg'  => '数据源暂时不可用 (HTTP ' . $httpCode . ')',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 解析上游 JSON ======
$data = json_decode($response, true);
if (!is_array($data)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(502);
    echo json_encode([
        'code' => 502,
        'msg'  => '数据源返回格式异常',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 原始 JSON 模式 ======
if ($raw) {
    // 剥离 tips 字段后返回
    unset($data['tips']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 判断解析结果 ======
// 上游成功标志：msg 非空且不包含"失败"/"错误"/"不存在"
$msg = $data['msg'] ?? '';
$failKeywords = ['失败', '错误', '不存在', '失效', 'error', 'fail', '404'];
$isFail = false;
foreach ($failKeywords as $kw) {
    if (stripos($msg, $kw) !== false) {
        $isFail = true;
        break;
    }
}

if ($isFail || empty($msg)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode([
        'code' => 1,
        'msg'  => $msg ?: '解析失败，请检查链接是否正确',
        'data' => [
            'source_url' => $url
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 组装结构化数据 ======
// 判断内容类型："图集" => image, "视频" => video
$rawType = $data['type'] ?? '';
$type = 'video';
if ($rawType === '图集' || $rawType === 'image' || $rawType === '图片') {
    $type = 'image';
}

// 图片列表
$images = $data['images'] ?? [];
if (!is_array($images)) {
    $images = [];
}

// 视频地址（优先 play_video，其次 video）
$videoUrl  = $data['play_video'] ?? $data['video'] ?? '';
$cover     = $data['cover'] ?? '';
$title     = $data['title'] ?? '';
$author    = $data['name'] ?? '';
$awemeId   = $data['aweme_id'] ?? '';

// 兜底判断：如果视频和图片都为空，说明解析失败（即使 msg 含"成功"字样）
if (empty($videoUrl) && empty($images)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode([
        'code' => 1,
        'msg'  => '解析失败，未能获取视频或图片资源',
        'data' => [
            'source_url' => $url
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 输出 JSON ======
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 0,
    'msg'  => 'success',
    'data' => [
        'type'       => $type,
        'author'     => $author,
        'aweme_id'   => $awemeId,
        'title'      => $title,
        'cover'      => $cover,
        'video_url'  => $videoUrl,
        'play_url'   => $videoUrl,
        'images'     => $images,
        'source_url' => $url
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
