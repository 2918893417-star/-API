<?php
/**
 * 密码泄露查询接口 - 代理转发版
 * 
 * 使用方法:
 *   GET https://api.yzwlkj.top/API/password_leak.php?apikey=sk_xxx&password=123123
 * 
 * 数据来源: https://api.yujn.cn/api/pass.php
 */

require_once __DIR__ . '/../Core/api_key_auth.php';

// 获取密码参数
$password = $_GET['password'] ?? '';

if ($password === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 400, 'msg' => '参数错误：请提供 password 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 构造目标接口地址
$targetUrl = 'https://api.yujn.cn/api/pass.php?type=json&password=' . urlencode($password);

// 转发请求到目标接口
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);  // 目标是 HTTPS，建议开启验证
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 API-Proxy/1.0');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// 错误处理
if ($error) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 502, 'msg' => '数据源连接失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $httpCode, 'msg' => "数据源暂时不可用 (HTTP {$httpCode})"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析 JSON 并移除 tips 字段
$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 502, 'msg' => '数据源返回格式异常'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ★ 核心：移除 tips 字段
unset($data['tips']);

// 输出过滤后的结果
header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);