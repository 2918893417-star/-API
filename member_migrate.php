<?php
/**
 * 会员系统 - 数据库迁移脚本
 * 用于给已安装的系统补齐会员/订单/API Key等表
 * 使用方法：浏览器访问 http://你的域名/member_migrate.php
 */

require_once __DIR__ . '/Core/Database/connect.php';

if (!$db) {
    die('数据库连接失败，请先完成安装');
}

$messages = [];

$tables = [
    'mxgapi_user' => "CREATE TABLE `mxgapi_user` (`id` int(11) NOT NULL AUTO_INCREMENT,`username` varchar(50) NOT NULL,`password` varchar(255) NOT NULL,`email` varchar(100) NOT NULL,`mobile` varchar(20) DEFAULT NULL,`avatar` varchar(255) DEFAULT '',`balance` decimal(10,2) NOT NULL DEFAULT '0.00',`level` tinyint(1) NOT NULL DEFAULT '0',`expire_time` int(20) DEFAULT '0',`status` tinyint(1) NOT NULL DEFAULT '1',`reg_time` int(20) NOT NULL,`last_login_time` int(20) DEFAULT '0',PRIMARY KEY (`id`),UNIQUE KEY `uk_username` (`username`),KEY `idx_email` (`email`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='用户表'",
    'mxgapi_plan' => "CREATE TABLE `mxgapi_plan` (`id` int(11) NOT NULL AUTO_INCREMENT,`name` varchar(50) NOT NULL,`description` varchar(255) DEFAULT '',`price` decimal(10,2) NOT NULL,`duration` int(11) NOT NULL DEFAULT '0',`level` tinyint(1) NOT NULL DEFAULT '0',`features` text DEFAULT NULL,`sort` int(11) NOT NULL DEFAULT '0',`status` tinyint(1) NOT NULL DEFAULT '1',`create_time` int(20) NOT NULL,PRIMARY KEY (`id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='会员套餐表'",
    'mxgapi_order' => "CREATE TABLE `mxgapi_order` (`id` int(11) NOT NULL AUTO_INCREMENT,`order_no` varchar(32) NOT NULL,`user_id` int(11) NOT NULL,`user_email` varchar(100) DEFAULT '',`plan_id` int(11) NOT NULL,`plan_name` varchar(50) DEFAULT '',`amount` decimal(10,2) NOT NULL,`pay_type` varchar(20) NOT NULL DEFAULT 'balance',`status` tinyint(1) NOT NULL DEFAULT '0',`remark` varchar(255) DEFAULT '',`pay_time` int(20) DEFAULT '0',`create_time` int(20) NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `uk_order_no` (`order_no`),KEY `idx_user_id` (`user_id`),KEY `idx_status` (`status`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='订单表'",
    'mxgapi_apikey' => "CREATE TABLE `mxgapi_apikey` (`id` int(11) NOT NULL AUTO_INCREMENT,`user_id` int(11) NOT NULL COMMENT '用户ID',`api_key` varchar(64) NOT NULL COMMENT 'API密钥',`api_key_masked` varchar(20) DEFAULT '' COMMENT '脱敏显示的密钥',`name` varchar(50) DEFAULT '' COMMENT '密钥名称',`calls_today` int(11) NOT NULL DEFAULT '0' COMMENT '今日调用次数',`calls_total` int(11) NOT NULL DEFAULT '0' COMMENT '总调用次数',`daily_limit` int(11) NOT NULL DEFAULT '1000' COMMENT '每日调用限制',`last_reset_time` int(20) DEFAULT '0' COMMENT '最后重置时间',`status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1=启用 0=禁用',`last_used_time` int(20) DEFAULT '0' COMMENT '最后使用时间',`create_time` int(20) NOT NULL COMMENT '创建时间',PRIMARY KEY (`id`),UNIQUE KEY `uk_api_key` (`api_key`),KEY `idx_user_id` (`user_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='API密钥表'",
    'mxgapi_api_log' => "CREATE TABLE `mxgapi_api_log` (`id` int(11) NOT NULL AUTO_INCREMENT,`key_id` int(11) DEFAULT NULL COMMENT 'API Key ID',`user_id` int(11) DEFAULT NULL COMMENT '用户ID',`api_id` int(11) DEFAULT NULL COMMENT '接口ID',`api_name` varchar(100) DEFAULT '' COMMENT '接口名称',`ip` varchar(20) NOT NULL COMMENT '请求IP',`time` int(20) NOT NULL COMMENT '请求时间',`status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1=成功 0=失败',`amount` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '本次调用费用',PRIMARY KEY (`id`),KEY `idx_key_id` (`key_id`),KEY `idx_user_id` (`user_id`),KEY `idx_time` (`time`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='API调用日志表'",
    'mxgapi_charge_log' => "CREATE TABLE `mxgapi_charge_log` (`id` int(11) NOT NULL AUTO_INCREMENT,`user_id` int(11) NOT NULL COMMENT '用户ID',`api_id` int(11) DEFAULT NULL COMMENT '接口ID',`api_name` varchar(100) DEFAULT '' COMMENT '接口名称',`api_key_id` int(11) DEFAULT NULL COMMENT 'API Key ID',`charge_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '扣费类型:1=按次扣费,2=会员免费',`amount` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '扣费金额',`balance_before` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '扣费前余额',`balance_after` decimal(10,4) NOT NULL DEFAULT '0.0000' COMMENT '扣费后余额',`ip` varchar(20) DEFAULT '' COMMENT '请求IP',`time` int(20) NOT NULL COMMENT '扣费时间',PRIMARY KEY (`id`),KEY `idx_user_id` (`user_id`),KEY `idx_api_id` (`api_id`),KEY `idx_time` (`time`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='接口扣费日志表'",
];

foreach ($tables as $name => $sql) {
    $check = $db->query("SHOW TABLES LIKE '{$name}'");
    if ($check && $check->num_rows == 0) {
        if ($db->query($sql)) {
            $messages[] = "✅ 成功创建 {$name} 表";
        } else {
            $messages[] = "❌ 创建 {$name} 表失败: " . $db->error;
        }
    } else {
        $messages[] = "ℹ️ {$name} 表已存在，跳过";
    }
}

// 默认套餐
$planCheck = $db->query("SELECT COUNT(*) AS c FROM `mxgapi_plan`");
if ($planCheck) {
    $planCount = intval($planCheck->fetch_array()['c']);
    if ($planCount == 0) {
        $planSqls = [
            "INSERT INTO `mxgapi_plan` (`name`,`description`,`price`,`duration`,`level`,`features`,`sort`,`status`,`create_time`) VALUES('月会员','30天无限次API调用','10.00',30,1,'{\"calls\":\"999999\",\"discount\":\"100%\",\"support\":\"priority\"}',1,1,'" . time() . "')",
            "INSERT INTO `mxgapi_plan` (`name`,`description`,`price`,`duration`,`level`,`features`,`sort`,`status`,`create_time`) VALUES('年会员','365天无限次API调用','88.00',365,3,'{\"calls\":\"999999\",\"discount\":\"100%\",\"support\":\"priority\"}',2,1,'" . time() . "')",
            "INSERT INTO `mxgapi_plan` (`name`,`description`,`price`,`duration`,`level`,`features`,`sort`,`status`,`create_time`) VALUES('永久会员','终身无限次API调用','100.00',0,2,'{\"calls\":\"999999\",\"discount\":\"100%\",\"support\":\"priority\"}',3,1,'" . time() . "')",
        ];
        foreach ($planSqls as $sql) {
            if ($db->query($sql)) {
                $messages[] = "✅ 默认套餐数据已写入";
            } else {
                $messages[] = "❌ 写入套餐失败: " . $db->error;
            }
        }
    } else {
        $messages[] = "ℹ️ 套餐数据已存在（{$planCount}条），跳过";
    }
}

// 默认管理员账号
$userCheck = $db->query("SELECT COUNT(*) AS c FROM `mxgapi_user` WHERE `username`='admin'");
if ($userCheck) {
    $userCount = intval($userCheck->fetch_array()['c']);
    if ($userCount == 0) {
        $pwd = password_hash('admin123', PASSWORD_DEFAULT);
        $sql = "INSERT INTO `mxgapi_user` (`username`,`password`,`email`,`balance`,`level`,`status`,`reg_time`) VALUES('admin','{$pwd}','admin@example.com',0,3,1,'" . time() . "')";
        if ($db->query($sql)) {
            $messages[] = "✅ 已创建默认管理员账号 admin（密码 admin123，登录后请尽快修改）";
        } else {
            $messages[] = "⚠️ 管理员账号创建失败: " . $db->error;
        }
    } else {
        $messages[] = "ℹ️ 管理员账号已存在，跳过";
    }
}

// 输出结果
echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>会员系统迁移</title>
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
<h1>🧧 会员系统 - 数据库迁移</h1>
<p class="subtitle">正在为系统补齐会员/订单/API Key 等功能所需的数据表</p>
<div style="margin-top:20px;">';

foreach ($messages as $msg) {
    $class = 'info';
    if (strpos($msg, '✅') !== false) $class = 'success';
    if (strpos($msg, '❌') !== false) $class = 'error';
    if (strpos($msg, '⚠️') !== false) $class = 'error';
    echo "<div class=\"msg {$class}\">{$msg}</div>";
}

echo '</div>
<div class="tip">
    <strong>迁移完成！</strong><br>
    1. 已补齐 mxgapi_user / mxgapi_plan / mxgapi_order / mxgapi_apikey / mxgapi_api_log / mxgapi_charge_log 等表<br>
    2. 后台仪表盘现在可以正常显示会员统计、订单统计、API Key 统计等数据<br>
    3. 请使用 <code>admin</code> / <code>admin123</code> 登录管理后台，登录后请及时修改密码<br>
    4. 迁移完成后，建议删除本文件以保证安全
</div>
<a href="./?action=admin" class="btn">进入后台管理</a>
<a href="./?action=admin&page=plan_manage" class="btn" style="background:#10b981;">管理套餐</a>
</div>
</body>
</html>';
