<?php
/**
 * API Key 验证中间件
 * 在 API 调用前进行 Key 验证、频率限制检查、计费处理
 * 
 * 使用方法:
 *   在每个 API 文件开头引入此文件
 *   require __DIR__ . '/../Core/api_key_auth.php';
 * 
 * 如果想在调用前自定义接口信息，可以在引入前设置:
 *   $GLOBALS['api_charge_type'] = 1;     // 0=会员专用 1=按次计费
 *   $GLOBALS['api_price'] = 0.01;        // 单次调用价格(元)
 *   $GLOBALS['api_required_level'] = 1;  // 需要的最低会员等级
 */

require_once __DIR__ . '/../Data/init.php';
require_once __DIR__ . '/apikey_functions.php';

// 获取 API Key (支持 header 和参数两种方式)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_FORWARDED_FOR_APY_KEY'] ?? $_GET['apikey'] ?? $_POST['apikey'] ?? '';

// 定义公开接口(不需要验证，硬编码的永久免费接口)
$publicAPIs = ['test', 'hello', 'bing'];

// 获取当前接口名称
$currentAPI = basename($_SERVER['SCRIPT_NAME'], '.php');

// 公开接口跳过验证
if(in_array($currentAPI, $publicAPIs)){
    $GLOBALS['api_auth'] = [
        'is_public' => true,
        'user_id' => 0,
        'level' => 0,
        'level_name' => '公开接口'
    ];
    return;
}

// 动态免费接口检查：从数据库查询 charge_type=2 的接口
// 管理员在后台添加接口时选择"免费"选项，即可免 API Key 调用
if(!empty($currentAPI)){
    $escapedAPI = $db->real_escape_string($currentAPI);
    $freeResult = $db->query("SELECT `id`,`name` FROM `mxgapi_api` WHERE `charge_type`=2 AND (`url` LIKE '%{$escapedAPI}%' OR `enname`='{$escapedAPI}') LIMIT 1");
    if($freeResult && $freeResult->num_rows > 0){
        $freeInfo = $freeResult->fetch_assoc();
        // 免费接口仍然记录调用次数
        if(intval($freeInfo['id']) > 0){
            $db->query("UPDATE `mxgapi_api` SET `access`=`access`+1 WHERE `id`='{$freeInfo['id']}'");
        }
        $GLOBALS['api_auth'] = [
            'is_public' => true,
            'user_id' => 0,
            'level' => 0,
            'level_name' => '免费公开接口',
            'api_id' => intval($freeInfo['id']),
            'api_name' => $freeInfo['name']
        ];
        return;
    }
}

// 获取请求的会员要求 (接口内可通过 $GLOBALS['api_required_level'] 预设)
$requiredLevel = $GLOBALS['api_required_level'] ?? 0;

// 如果没有提供 API Key
if(empty($apiKey)){
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode([
        'code' => 401,
        'msg' => '缺少 API Key',
        'data' => [
            'tip' => '请在请求中添加 apikey 参数或使用 X-API-Key header',
            'register_url' => '?action=register'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 验证 API Key
$authResult = validateApiKey($apiKey);

if($authResult === false){
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode([
        'code' => 403,
        'msg' => '无效的 API Key',
        'data' => [
            'tip' => '请检查您的 API Key 是否正确'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 检查频率限制错误
if(isset($authResult['error'])){
    header('Content-Type: application/json');
    http_response_code(429);
    echo json_encode([
        'code' => 429,
        'msg' => $authResult['message'],
        'data' => $authResult
    ], JSON_PRETTY_PRINT);
    exit;
}

// ====== 计费与权限检查 ======
// 1. 获取接口信息
$apiInfo = getApiInfoByRequest();

// 如果外部预设了计费信息，以外部为准
if(isset($GLOBALS['api_charge_type'])){
    if($apiInfo === null){
        $apiInfo = [];
    }
    $apiInfo['charge_type'] = intval($GLOBALS['api_charge_type']);
    $apiInfo['price'] = floatval($GLOBALS['api_price'] ?? 0);
    $apiInfo['id'] = $apiInfo['id'] ?? 0;
    $apiInfo['name'] = $apiInfo['name'] ?? $currentAPI;
}

// 2. 会员专用接口权限检查
$vipCheck = checkApiVipPermission($apiInfo, $authResult['level']);
if($vipCheck !== true){
    // 同时回滚调用次数（因为前面 validateApiKey 中已经 +1 了）
    if(isset($authResult['key_id'])){
        global $db;
        $db->query("UPDATE `mxgapi_apikey` SET `calls_today`=`calls_today`-1, `calls_total`=`calls_total`-1 WHERE `id`='{$authResult['key_id']}'");
        // 删除刚才插入的日志
        if(isset($GLOBALS['last_api_log_id']) && $GLOBALS['last_api_log_id'] > 0){
            $db->query("DELETE FROM `mxgapi_api_log` WHERE `id`='{$GLOBALS['last_api_log_id']}'");
        }
        // 更新状态为失败
        $db->query("UPDATE `mxgapi_api_log` SET `status`=0 WHERE `id`='{$GLOBALS['last_api_log_id']}'");
    }
    header('Content-Type: application/json');
    http_response_code($vipCheck['code']);
    echo json_encode($vipCheck, JSON_PRETTY_PRINT);
    exit;
}

// 如果外部设置了更高的等级要求，再次检查
if($requiredLevel > 0 && $authResult['level'] < $requiredLevel){
    // 回滚调用次数
    if(isset($authResult['key_id'])){
        global $db;
        $db->query("UPDATE `mxgapi_apikey` SET `calls_today`=`calls_today`-1, `calls_total`=`calls_total`-1 WHERE `id`='{$authResult['key_id']}'");
        if(isset($GLOBALS['last_api_log_id']) && $GLOBALS['last_api_log_id'] > 0){
            $db->query("DELETE FROM `mxgapi_api_log` WHERE `id`='{$GLOBALS['last_api_log_id']}'");
        }
    }
    $levelNames = [0 => '普通用户', 1 => '月会员', 2 => '永久会员', 3 => '年会员'];
    $requiredName = $levelNames[$requiredLevel] ?? 'VIP会员';
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode([
        'code' => 403,
        'msg' => '接口需要' . $requiredName . '或以上权限',
        'data' => [
            'tip' => '您当前的等级：' . $authResult['level_name'] . '，请充值会员后再调用',
            'current_level' => $authResult['level_name'],
            'required_level' => $requiredName,
            'vip_url' => '?action=svip'
        ]
    ], JSON_PRETTY_PRINT);
    exit;
}

// 3. 执行计费处理
$chargeResult = processApiCharge($apiInfo, $authResult);
if($chargeResult !== true){
    // 计费失败，回滚调用次数
    if(isset($authResult['key_id'])){
        global $db;
        $db->query("UPDATE `mxgapi_apikey` SET `calls_today`=`calls_today`-1, `calls_total`=`calls_total`-1 WHERE `id`='{$authResult['key_id']}'");
        if(isset($GLOBALS['last_api_log_id']) && $GLOBALS['last_api_log_id'] > 0){
            $db->query("UPDATE `mxgapi_api_log` SET `status`=0, `amount`=0 WHERE `id`='{$GLOBALS['last_api_log_id']}'");
        }
    }
    header('Content-Type: application/json');
    http_response_code($chargeResult['code']);
    echo json_encode($chargeResult, JSON_PRETTY_PRINT);
    exit;
}

// 认证和计费成功,存储用户信息供后续使用
$GLOBALS['api_auth'] = $authResult;

// 添加响应头
header('X-API-User: ' . $authResult['username']);
header('X-API-Level: ' . $authResult['level_name']);
header('X-API-Calls-Remaining: ' . max(0, $authResult['daily_limit'] - $authResult['calls_today']));
if(isset($authResult['charged'])){
    header('X-API-Charged: ' . $authResult['charged']);
}
if(isset($authResult['charge_note'])){
    header('X-API-Charge-Note: ' . $authResult['charge_note']);
}
if(isset($authResult['balance_after'])){
    header('X-API-Balance: ' . $authResult['balance_after']);
}
