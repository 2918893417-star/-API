<?php
/**
 * 鹰眼接口 - 需要 VIP 会员权限
 * 
 * 使用方法:
 *   方式1 (GET参数): ?apikey=sk_xxxxxx
 *   方式2 (Header): X-API-Key: sk_xxxxxx
 */

// 引入 API Key 验证中间件(必须在其他代码之前引入)
// 此接口已在 Core/api_key_auth.php 的 $vipOnlyAPIs 列表中, 要求 VIP 会员才能访问
require_once __DIR__ . '/../Core/api_key_auth.php';

// 引入原有功能
require "./function.php"; // 引入函数文件
addApiAccess(1); // 调用统计函数

echo curl("http://ai.kenaisq.top/API/yiyan.php", "GET", 0, 0);
