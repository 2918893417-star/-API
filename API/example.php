<?php
/**
 * API 调用示例 - 带 API Key 验证
 * 
 * 使用方法:
 *   方式1 (GET参数): ?apikey=sk_xxxxxx
 *   方式2 (Header): X-API-Key: sk_xxxxxx
 * 
 * 响应头信息:
 *   X-API-User: 用户名
 *   X-API-Level: 会员等级
 *   X-API-Calls-Remaining: 剩余调用次数
 */

// 引入 API Key 验证中间件(必须在其他代码之前引入)
require_once __DIR__ . '/../Core/api_key_auth.php';

// 引入原有功能
require_once __DIR__ . '/function.php';

// 获取 API ID (可从数据库配置获取)
$apiId = intval($_GET['id'] ?? 1);

// 调用统计
addApiAccess($apiId);

// 获取当前用户信息 (由 api_key_auth.php 设置)
$userInfo = $GLOBALS['api_auth'];

// 设置响应格式
header('Content-Type: application/json; charset=utf-8');

// 示例 API 响应
$response = [
    'code' => 0,
    'msg' => 'success',
    'data' => [
        'message' => '这是一个示例 API 响应',
        'timestamp' => time(),
        'api_info' => [
            'name' => '示例接口',
            'version' => '1.0'
        ],
        'user_info' => [
            'username' => $userInfo['username'],
            'level' => $userInfo['level_name'],
            'calls_today' => $userInfo['calls_today'],
            'daily_limit' => $userInfo['daily_limit']
        ]
    ]
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
