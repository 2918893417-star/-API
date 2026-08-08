<?php
/**
 * 端口扫描接口 - 代理转发版
 *
 * 扫描目标 IP/域名的常用端口开放状态，返回结构化 JSON 数据
 *
 * 使用方法:
 *   GET /API/port.php?apikey=sk_xxx&ip=ai.weikang66.cn
 *
 * 参数:
 *   ip       - 必填，要扫描的 IP 地址或域名
 *   raw      - 可选，设为 1 时返回数据源原始文本
 *
 * 响应示例:
 * {
 *   "code": 0,
 *   "msg": "success",
 *   "data": {
 *     "ip": "ai.weikang66.cn",
 *     "scan_time": 1234567890,
 *     "ports": [
 *       {"port": 21,   "service": "FTP",       "status": "关闭", "open": false},
 *       {"port": 80,   "service": "默认",       "status": "关闭", "open": false},
 *       {"port": 443,  "service": "SSL",       "status": "开启", "open": true}
 *     ],
 *     "summary": {
 *       "total": 7,
 *       "open": 1,
 *       "closed": 6
 *     }
 *   }
 * }
 *
 * 数据来源: https://api.yujn.cn/api/port.php
 */

// 引入 API Key 验证中间件(必须在其他代码之前引入)
require_once __DIR__ . '/../Core/api_key_auth.php';

// 引入公共函数（用于调用统计）
require_once __DIR__ . '/function.php';

// ====== 参数处理 ======
$ip   = trim($_GET['ip'] ?? '');
$raw  = isset($_GET['raw']) && intval($_GET['raw']) === 1;

// 参数校验
if (empty($ip)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => '参数错误：请提供 ip 参数（IP 地址或域名）',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 校验 IP 或域名格式（防止注入异常字符）
// 允许：IPv4、IPv6、域名（含连字符、点）
$isValid = false;
if (filter_var($ip, FILTER_VALIDATE_IP)) {
    // 合法 IP 地址
    $isValid = true;
} elseif (preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $ip)) {
    // 合法域名
    $isValid = true;
}

if (!$isValid) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => '参数错误：ip 必须是合法的 IP 地址或域名',
        'data' => null
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 请求上游接口 ======
$targetUrl = 'https://api.yujn.cn/api/port.php?ip=' . urlencode($ip);

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
    'Accept: text/plain, application/json, */*'
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

// ====== 原始文本模式 ======
if ($raw) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $response;
    exit;
}

// ====== 解析端口扫描结果 ======
// 原始格式示例: 端口扫描 ━━━ 21[FTP]:关闭 22[SSH]:关闭 80[默认]:关闭 ━━━
// 匹配 pattern: 端口号[服务名]:状态
preg_match_all('/(\d+)\[([^\]]+)\]:(开启|关闭|开放|open|closed)/u', $response, $matches, PREG_SET_ORDER);

$ports   = [];
$openCnt = 0;
$closedCnt = 0;

foreach ($matches as $m) {
    $port    = intval($m[1]);
    $service = trim($m[2]);
    $rawStat = $m[3];
    // 统一状态：开启/开放/open => true，关闭/closed => false
    $isOpen  = in_array($rawStat, ['开启', '开放', 'open'], true);
    $status  = $isOpen ? '开启' : '关闭';

    if ($isOpen) {
        $openCnt++;
    } else {
        $closedCnt++;
    }

    $ports[] = [
        'port'    => $port,
        'service' => $service,
        'status'  => $status,
        'open'    => $isOpen
    ];
}

// 按端口号升序排列
usort($ports, function ($a, $b) {
    return $a['port'] - $b['port'];
});

// ====== 输出 JSON ======
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 0,
    'msg'  => 'success',
    'data' => [
        'ip'        => $ip,
        'scan_time' => time(),
        'ports'     => $ports,
        'summary'   => [
            'total'   => count($ports),
            'open'    => $openCnt,
            'closed'  => $closedCnt
        ]
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
