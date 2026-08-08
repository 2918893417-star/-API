<?php
/**
 * 网站备案查询接口
 *
 * 查询域名的 ICP 备案信息，返回结构化 JSON 数据
 *
 * 使用方法:
 *   GET /API/beian.php?apikey=sk_xxx&domain=qq.com
 *   GET /API/beian.php?apikey=sk_xxx&domain=qq.com&raw=1  返回上游原始 JSON
 *
 * 参数:
 *   domain - 必填，要查询的域名
 *   raw    - 可选，设为 1 时返回数据源原始 JSON
 *
 * 响应示例:
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": {
 *     "domain": "qq.com",
 *     "exists": true,
 *     "company": "深圳市腾讯计算机系统有限公司",
 *     "site_name": "NULL",
 *     "type": "企业",
 *     "number": "粤B2-20090059-5",
 *     "audit_time": "2026-07-16 00:00:00"
 *   }
 * }
 *
 * 数据来源: https://api.yujn.cn/api/beian.php
 */

// 引入 API Key 验证中间件(必须在其他代码之前引入)
require_once __DIR__ . '/../Core/api_key_auth.php';

// 引入公共函数（用于调用统计）
require_once __DIR__ . '/function.php';

// ====== 参数处理 ======
$domain = trim($_GET['domain'] ?? '');
$raw    = isset($_GET['raw']) && intval($_GET['raw']) === 1;

// 参数校验
if (empty($domain)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => '参数错误：请提供 domain 参数（要查询的域名）',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 校验域名格式
$domain = strtolower($domain);
if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => '参数错误：domain 必须是合法的域名格式（如 qq.com）',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 请求上游接口 ======
$targetUrl = 'https://api.yujn.cn/api/beian.php?type=json&domain=' . urlencode($domain);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
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
$error    = curl_error($ch);
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 组装结构化数据 ======
$upstreamCode = intval($data['code'] ?? 0);
$exists       = !empty($data['exists']);
$upstreamData = $data['data'] ?? [];

// 判断查询结果
if ($upstreamCode !== 200) {
    $errorMsg = $data['msg'] ?? '查询失败';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 1,
        'msg'  => $errorMsg,
        'data' => [
            'domain'       => $domain,
            'exists'       => false,
            'upstream_code' => $upstreamCode
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 提取备案数据
$domainInfo    = $upstreamData['domain'] ?? $domain;
$company       = $upstreamData['company'] ?? '';
$siteName      = $upstreamData['siteName'] ?? '';
$type          = $upstreamData['type'] ?? '';
$number        = $upstreamData['number'] ?? '';
$auditTime     = $upstreamData['auditTime'] ?? '';

// ====== 输出 JSON ======
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 0,
    'msg'  => 'success',
    'data' => [
        'domain'      => $domainInfo,
        'exists'      => $exists,
        'company'     => $company,
        'site_name'   => $siteName,
        'type'        => $type,
        'number'      => $number,
        'audit_time'  => $auditTime
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
