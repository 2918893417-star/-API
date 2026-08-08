<?php
/**
 * API Key 相关函数
 * 独立于 user.php,供 api_key_auth.php 调用
 */

/**
 * 根据会员等级获取每日调用限制
 */
function getDailyLimitByLevel($level){
    switch($level){
        case 0: return 100;      // 普通用户
        case 1: return 10000;    // 月会员
        case 2: return 999999;   // 永久会员 (无限)
        case 3: return 999999;   // 年会员 (无限)
        default: return 100;
    }
}

/**
 * 获取会员等级名称
 */
function getLevelName($level){
    $names = [
        0 => '普通用户',
        1 => '月会员',
        2 => '永久会员',
        3 => '年会员'
    ];
    return $names[$level] ?? '未知等级';
}

/**
 * 根据当前请求获取接口信息
 * 优先通过 ?id= 参数，其次通过 URL 文件名匹配 url 字段，最后匹配 enname 字段
 */
function getApiInfoByRequest(){
    global $db;

    $apiId = intval($_GET['id'] ?? 0);
    if($apiId > 0){
        $result = $db->query("SELECT * FROM `mxgapi_api` WHERE `id`='{$apiId}' LIMIT 1");
        if($result && $result->num_rows > 0){
            return $result->fetch_assoc();
        }
    }

    // 通过当前脚本文件名匹配
    $scriptName = basename($_SERVER['SCRIPT_NAME'], '.php');
    if(!empty($scriptName)){
        // 先匹配 url 字段 (API文件名)
        $escaped = $db->real_escape_string($scriptName);
        $result = $db->query("SELECT * FROM `mxgapi_api` WHERE `url` LIKE '%{$escaped}%' LIMIT 1");
        if($result && $result->num_rows > 0){
            return $result->fetch_assoc();
        }
        // 再匹配 enname 字段
        $result = $db->query("SELECT * FROM `mxgapi_api` WHERE `enname`='{$escaped}' LIMIT 1");
        if($result && $result->num_rows > 0){
            return $result->fetch_assoc();
        }
    }

    return null;
}

/**
 * 检查接口是否需要会员权限
 * @param array $api 接口信息
 * @param int $userLevel 用户等级
 * @return array|true 通过返回true，不通过返回错误信息
 */
function checkApiVipPermission($api, $userLevel){
    // 如果接口没有设置 charge_type 字段（老数据兼容），视为会员专用接口
    $chargeType = intval($api['charge_type'] ?? 0);

    // 免费公开接口 (charge_type=2)：任何人可调用，直接放行
    if($chargeType === 2){
        return true;
    }

    // 会员专用接口 (charge_type=0)：需要等级 level>=1
    if($chargeType === 0){
        $requiredLevel = 1;
        if($userLevel < $requiredLevel){
            return [
                'code' => 403,
                'msg' => '该接口为会员专用，请升级会员后调用',
                'data' => [
                    'current_level' => getLevelName($userLevel),
                    'required_level' => getLevelName($requiredLevel),
                    'tip' => '请前往会员中心购买会员',
                    'svip_url' => '?action=svip'
                ]
            ];
        }
    }

    return true;
}

/**
 * 处理接口计费
 * @param array $api 接口信息
 * @param array $authResult 验证结果 (包含 user_id, level, key_id 等)
 * @return array|true 计费成功返回true，失败返回错误信息
 */
function processApiCharge($api, &$authResult){
    global $db;

    if(empty($api)){
        // 没有找到接口信息，不进行计费（兼容老接口）
        $authResult['charged'] = 0;
        $authResult['charge_type'] = 'unknown';
        return true;
    }

    $chargeType = intval($api['charge_type'] ?? 0);
    $price = floatval($api['price'] ?? 0);
    $userId = intval($authResult['user_id']);
    $userLevel = intval($authResult['level']);
    $keyId = intval($authResult['key_id']);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $time = time();

    // 计费类型文案映射
    $chargeTypeTextMap = [0 => '会员专用', 1 => '按次计费', 2 => '免费公开'];
    $authResult['api_charge_type'] = $chargeType;
    $authResult['api_charge_type_text'] = $chargeTypeTextMap[$chargeType] ?? '会员专用';
    $authResult['api_price'] = $price;

    // 免费公开接口 (charge_type=2)：不扣费，直接返回成功
    if($chargeType === 2){
        $authResult['charged'] = 0;
        $authResult['charge_note'] = '免费公开接口';
        return true;
    }

    if($chargeType === 0){
        // ====== 会员专用接口 ======
        // 已在 checkApiVipPermission 中检查过等级，这里直接记录为会员免费调用
        $chargeLogType = 2; // 2=会员免费
        $chargedAmount = 0;

        // 获取用户当前余额
        $balanceResult = $db->query("SELECT `balance` FROM `mxgapi_user` WHERE `id`='{$userId}'");
        $balance = $balanceResult ? floatval($balanceResult->fetch_array()[0]) : 0;
        $balanceBefore = $balance;
        $balanceAfter = $balance;

        // 记录扣费日志（会员免费，金额为0）
        $db->query("INSERT INTO `mxgapi_charge_log`(
            `user_id`,`api_id`,`api_name`,`api_key_id`,`charge_type`,`amount`,
            `balance_before`,`balance_after`,`ip`,`time`
        ) VALUES(
            '{$userId}','{$api['id']}','".$db->real_escape_string($api['name'])."','{$keyId}','{$chargeLogType}','0.0000',
            '{$balanceBefore}','{$balanceAfter}','{$ip}','{$time}'
        )");

        $authResult['charged'] = 0;
        $authResult['charge_note'] = '会员免费调用';
        return true;

    } else {
        // ====== 按次计费接口 ======
        $isVip = ($userLevel > 0);

        // 会员调用按次计费接口：免费（可以按需修改折扣逻辑，比如 $price * 0.5 表示5折）
        if($isVip){
            $chargedAmount = 0;
            $chargeLogType = 2; // 会员免费
            $discountNote = '会员免费';
        } else {
            $chargedAmount = $price;
            $chargeLogType = 1; // 按次扣费
            $discountNote = '按次扣费 ¥' . number_format($price, 4);
        }

        // 获取用户当前余额（行锁方式，避免并发问题）
        $db->query("START TRANSACTION");
        $balanceResult = $db->query("SELECT `balance` FROM `mxgapi_user` WHERE `id`='{$userId}' FOR UPDATE");
        if(!$balanceResult || $balanceResult->num_rows == 0){
            $db->query("ROLLBACK");
            return [
                'code' => 500,
                'msg' => '获取用户信息失败',
                'data' => []
            ];
        }
        $balance = floatval($balanceResult->fetch_array()[0]);
        $balanceBefore = $balance;

        // 检查余额是否足够（仅针对需要扣费的普通用户）
        if(!$isVip && $balance < $chargedAmount){
            $db->query("ROLLBACK");
            return [
                'code' => 402,
                'msg' => '余额不足，请先充值',
                'data' => [
                    'required' => number_format($chargedAmount, 4),
                    'current' => number_format($balance, 2),
                    'tip' => '本次调用需要 ¥' . number_format($chargedAmount, 4) . '，当前余额 ¥' . number_format($balance, 2),
                    'recharge_url' => '?action=user&section=balance'
                ]
            ];
        }

        // 扣除余额（仅实际扣费金额 > 0 时更新）
        $balanceAfter = $balanceBefore;
        if($chargedAmount > 0){
            $balanceAfter = $balanceBefore - $chargedAmount;
            $updateResult = $db->query("UPDATE `mxgapi_user` SET `balance`='{$balanceAfter}' WHERE `id`='{$userId}'");
            if(!$updateResult){
                $db->query("ROLLBACK");
                return [
                    'code' => 500,
                    'msg' => '扣费失败，请稍后重试',
                    'data' => []
                ];
            }
        }

        // 记录扣费日志
        $db->query("INSERT INTO `mxgapi_charge_log`(
            `user_id`,`api_id`,`api_name`,`api_key_id`,`charge_type`,`amount`,
            `balance_before`,`balance_after`,`ip`,`time`
        ) VALUES(
            '{$userId}','{$api['id']}','".$db->real_escape_string($api['name'])."','{$keyId}','{$chargeLogType}','{$chargedAmount}',
            '{$balanceBefore}','{$balanceAfter}','{$ip}','{$time}'
        )");

        // 在调用日志中也记录本次费用
        $logId = intval($GLOBALS['last_api_log_id'] ?? 0);
        if($logId > 0){
            $db->query("UPDATE `mxgapi_api_log` SET `amount`='{$chargedAmount}' WHERE `id`='{$logId}'");
        }

        $db->query("COMMIT");

        $authResult['charged'] = $chargedAmount;
        $authResult['balance_before'] = $balanceBefore;
        $authResult['balance_after'] = $balanceAfter;
        $authResult['charge_note'] = $discountNote;
        return true;
    }
}

/**
 * 验证 API Key
 * @param string $apiKey API Key
 * @return array|false 返回用户信息或 false
 */
function validateApiKey($apiKey){
    global $db;

    if(empty($apiKey)){
        return false;
    }

    // 查询 Key 信息
    $result = $db->query("SELECT * FROM `mxgapi_apikey` WHERE `api_key`='".$db->real_escape_string($apiKey)."' AND `status`=1");
    if(!$result || $result->num_rows == 0){
        return false;
    }

    $keyInfo = $result->fetch_assoc();
    $userId = $keyInfo['user_id'];

    // 获取用户信息
    $userResult = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$userId}'");
    if(!$userResult || $userResult->num_rows == 0){
        return false;
    }

    $user = $userResult->fetch_assoc();

    // 检查账号状态
    if($user['status'] != 1){
        return false;
    }

    // 检查会员是否过期
    if($user['level'] > 0 && $user['expire_time'] > 0 && $user['expire_time'] < time()){
        $db->query("UPDATE `mxgapi_user` SET `level`=0, `expire_time`=0 WHERE `id`='{$userId}'");
        $user['level'] = 0;
    }

    // 获取每日限制
    $dailyLimit = getDailyLimitByLevel($user['level']);

    // 检查是否需要重置今日调用次数
    $todayStart = strtotime(date('Y-m-d'));
    if($keyInfo['last_reset_time'] < $todayStart){
        $db->query("UPDATE `mxgapi_apikey` SET `calls_today`=0, `last_reset_time`='".time()."' WHERE `id`='{$keyInfo['id']}'");
        $keyInfo['calls_today'] = 0;
    }

    // 检查频率限制
    if($keyInfo['calls_today'] >= $dailyLimit){
        return ['error' => 'rate_limit_exceeded', 'message' => '今日调用次数已达上限'];
    }

    // 记录调用日志（此时还未计费，计费在 processApiCharge 中完成）
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $apiId = intval($_GET['id'] ?? 0);

    // 尝试获取接口名称
    $apiName = '';
    if($apiId > 0){
        $apiRes = $db->query("SELECT `name` FROM `mxgapi_api` WHERE `id`='{$apiId}' LIMIT 1");
        if($apiRes && $apiRes->num_rows > 0){
            $apiName = $apiRes->fetch_assoc()['name'];
        }
    }

    $db->query("INSERT INTO `mxgapi_api_log`(`key_id`,`user_id`,`api_id`,`api_name`,`ip`,`time`,`status`) 
                VALUES('{$keyInfo['id']}','{$userId}','{$apiId}','".$db->real_escape_string($apiName)."','{$ip}','".time()."',1)");
    $GLOBALS['last_api_log_id'] = $db->insert_id;

    // 更新调用次数和最后使用时间
    $db->query("UPDATE `mxgapi_apikey` SET `calls_today`=`calls_today`+1, `calls_total`=`calls_total`+1, `last_used_time`='".time()."' WHERE `id`='{$keyInfo['id']}'");

    // 返回用户信息
    return [
        'user_id' => $userId,
        'username' => $user['username'],
        'level' => $user['level'],
        'level_name' => getLevelName($user['level']),
        'is_vip' => $user['level'] > 0,
        'calls_today' => $keyInfo['calls_today'] + 1,
        'daily_limit' => $dailyLimit,
        'key_id' => $keyInfo['id'],
        'balance' => floatval($user['balance'])
    ];
}

/**
 * 生成唯一 API Key
 */
function generateApiKeyString(){
    $prefix = 'sk_';
    $random = bin2hex(random_bytes(16));
    return $prefix . $random;
}

/**
 * 获取脱敏后的 Key (显示前8位和后4位)
 */
function maskApiKey($key){
    if(strlen($key) <= 12){
        return $key;
    }
    return substr($key, 0, 8) . str_repeat('*', strlen($key) - 12) . substr($key, -4);
}
