<?php

require_once __DIR__ . '/../Core/api_key_auth.php';
/**
 * 获取当前时间 API 接口
 *
 * 使用方法:
 *   GET /api/time.php                  # 默认北京时间，JSON格式
 *   GET /api/time.php?timezone=UTC     # 指定时区
 *   GET /api/time.php?format=unix      # 返回Unix时间戳
 *   GET /api/time.php?format=datetime  # 返回日期时间字符串
 */

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 获取参数
$timezone = $_GET['timezone'] ?? 'Asia/Shanghai';
$format   = $_GET['format'] ?? 'json';

// 验证时区合法性
try {
    $tz = new DateTimeZone($timezone);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'msg'  => "无效的时区: {$timezone}",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取当前时间
$now = new DateTime('now', $tz);

// 根据格式输出
switch ($format) {
    case 'unix':
        echo json_encode([
            'unix'     => $now->getTimestamp(),
            'timezone' => $timezone,
        ]);
        break;

    case 'datetime':
        echo json_encode([
            'datetime' => $now->format('Y-m-d H:i:s'),
            'timezone' => $timezone,
        ]);
        break;

    case 'iso':
        echo json_encode([
            'iso8601'  => $now->format(DateTime::ATOM),
            'timezone' => $timezone,
        ]);
        break;

    case 'json':
    default:
        echo json_encode([
            'code'      => 200,
            'datetime'  => $now->format('Y-m-d H:i:s'),
            'iso8601'   => $now->format(DateTime::ATOM),
            'unix'      => $now->getTimestamp(),
            'date'      => $now->format('Y-m-d'),
            'time'      => $now->format('H:i:s'),
            'weekday'   => (int)$now->format('N'),       // 1=周一 ~ 7=周日
            'timezone'  => $timezone,
            'utc_offset'=> $now->format('P'),            // 如 +08:00
        ], JSON_UNESCAPED_UNICODE);
        break;
}