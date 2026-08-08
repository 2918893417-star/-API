<?php
/**
 * 接口计费系统 - 数据库迁移脚本
 * 用于给已安装的系统添加计费功能相关的字段和表
 * 使用方法：浏览器访问 http://你的域名/billing_migrate.php
 */

require_once __DIR__ . '/Core/Database/connect.php';

if (!$db) {
    die('数据库连接失败，请先完成安装');
}

$migrations = [];
$messages = [];

// 1. 给 mxgapi_api 表添加 charge_type 字段
$check = $db->query("SHOW COLUMNS FROM `mxgapi_api` LIKE 'charge_type'");
if ($check && $check->num_rows == 0) {
    $sql = "ALTER TABLE `mxgapi_api` ADD COLUMN `charge_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '计费类型:0=会员专用,1=按次计费' AFTER `status`";
    if ($db->query($sql)) {
        $messages[] = '✅ 成功添加 charge_type 字段到 mxgapi_api 表';
    } else {
        $messages[] = '❌ 添加 charge_type 字段失败: ' . $db->error;
    }
} else {
    $messages[] = 'ℹ️ charge_type 字段已存在，跳过';
}

// 2. 给 mxgapi_api 表添加 price 字段
$check = $db->query("SHOW COLUMNS FROM `mxgapi_api` LIKE 'price'");
if ($check && $check->num_rows == 0) {
    $sql = "ALTER TABLE `mxgapi_api` ADD COLUMN `price` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '单次调用价格(元)' AFTER `charge_type`";
    if ($db->query($sql)) {
        $messages[] = '✅ 成功添加 price 字段到 mxgapi_api 表';
    } else {
        $messages[] = '❌ 添加 price 字段失败: ' . $db->error;
    }
} else {
    $messages[] = 'ℹ️ price 字段已存在，跳过';
}

// 3. 创建 mxgapi_charge_log 扣费日志表
$check = $db->query("SHOW TABLES LIKE 'mxgapi_charge_log'");
if ($check && $check->num_rows == 0) {
    $sql = "CREATE TABLE `mxgapi_charge_log` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL COMMENT '用户ID',
        `api_id` int(11) DEFAULT NULL COMMENT '接口ID',
        `api_name` varchar(100) DEFAULT '' COMMENT '接口名称',
        `api_key_id` int(11) DEFAULT NULL COMMENT 'API Key ID',
        `charge_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '扣费类型:1=按次扣费,2=会员免费',
        `amount` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '扣费金额',
        `balance_before` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '扣费前余额',
        `balance_after` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '扣费后余额',
        `ip` varchar(20) DEFAULT '' COMMENT '请求IP',
        `time` int(20) NOT NULL COMMENT '扣费时间',
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_api_id` (`api_id`),
        KEY `idx_time` (`time`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='接口扣费日志表'";
    if ($db->query($sql)) {
        $messages[] = '✅ 成功创建 mxgapi_charge_log 扣费日志表';
    } else {
        $messages[] = '❌ 创建 mxgapi_charge_log 表失败: ' . $db->error;
    }
} else {
    $messages[] = 'ℹ️ mxgapi_charge_log 表已存在，跳过';
}

// 4. 给 mxgapi_api_log 表增加 amount 字段（可选，记录调用时产生的费用）
$check = $db->query("SHOW COLUMNS FROM `mxgapi_api_log` LIKE 'amount'");
if ($check && $check->num_rows == 0) {
    $sql = "ALTER TABLE `mxgapi_api_log` ADD COLUMN `amount` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '本次调用费用' AFTER `status`";
    if ($db->query($sql)) {
        $messages[] = '✅ 成功添加 amount 字段到 mxgapi_api_log 表';
    } else {
        $messages[] = '❌ 添加 amount 字段失败: ' . $db->error;
    }
} else {
    $messages[] = 'ℹ️ mxgapi_api_log.amount 字段已存在，跳过';
}

// 输出结果
echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>接口计费系统迁移</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; background: #f8fafc; color: #1e293b; }
.card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
h1 { margin: 0 0 8px; font-size: 22px; }
.subtitle { color: #64748b; margin-bottom: 24px; }
.msg { padding: 10px 14px; border-radius: 8px; margin-bottom: 8px; font-size: 14px; background: #f1f5f9; }
.success { background: #dcfce7; color: #166534; }
.info { background: #e0f2fe; color: #075985; }
.error { background: #fee2e2; color: #991b1b; }
.tip { margin-top: 24px; padding: 14px; background: #fef3c7; border-radius: 8px; color: #92400e; font-size: 14px; }
.btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px; font-weight: 500; }
.btn:hover { background: #2563eb; }
</style>
</head>
<body>
<div class="card">
<h1>🔧 接口计费系统 - 数据库迁移</h1>
<p class="subtitle">正在为系统添加接口计费功能所需的字段和数据表</p>
<div style="margin-top:20px;">';

foreach ($messages as $msg) {
    $class = 'info';
    if (strpos($msg, '✅') !== false) $class = 'success';
    if (strpos($msg, '❌') !== false) $class = 'error';
    if (strpos($msg, 'ℹ️') !== false) $class = 'info';
    echo "<div class=\"msg {$class}\">{$msg}</div>";
}

echo '</div>
<div class="tip">
    <strong>迁移完成！</strong><br>
    1. 现在你可以进入后台管理，编辑每个接口设置计费方式（会员专用 / 按次计费）<br>
    2. 按次计费的接口需要设置单次调用价格（元）<br>
    3. 用户调用按次计费接口时，将自动从余额中扣除对应金额<br>
    4. 会员用户调用按次计费接口可免费（可根据需要自行调整折扣逻辑）
</div>
<a href="./?action=admin&page=control" class="btn">进入后台管理</a>
</div>
</body>
</html>';
