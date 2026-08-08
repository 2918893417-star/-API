<?php
/* 初始化 */
// 抑制所有错误输出，防止HTML错误页面破坏JSON响应
// 但将错误写入日志文件便于排查
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

// 错误处理：将PHP错误写入日志而非输出到页面
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL) {
        $logFile = __DIR__ . '/error_log.txt';
        $msg = date('Y-m-d H:i:s') . ' | ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'] . "\n";
        @file_put_contents($logFile, $msg, FILE_APPEND);
    }
});

// 将所有错误转为异常以便捕获
set_error_handler(function($severity, $message, $file, $line) {
    $logFile = __DIR__ . '/error_log.txt';
    $msg = date('Y-m-d H:i:s') . ' | ERROR ' . $severity . ': ' . $message . ' in ' . $file . ' on line ' . $line . "\n";
    @file_put_contents($logFile, $msg, FILE_APPEND);
    return false; // 继续执行PHP的标准错误处理
});

require 'init.php';

/**
 * 绑定参数到预处理语句
 * 因为 PHP 的 bind_param 需要引用传递，使用此函数兼容处理
 */
function bindParams($stmt, $types, $values) {
    // bind_param 的第一个参数是类型字符串，后续参数必须是变量引用
    $params = array_merge([$types], $values);
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    return call_user_func_array([$stmt, 'bind_param'], $refs);
}

/**
 * 解析请求参数文本为数组
 * 格式：[名称--类型--必填--备注]，每行一个参数
 */
function parseRequestParams($text) {
    $result = [];
    $text = trim($text);
    if ($text === '') return $result;
    // 按行分割
    $lines = preg_split('/[\r\n]+/', $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '|' || $line === '-') continue;
        // 去除方括号
        $line = trim($line, '[]');
        // 用 -- 分割
        $parts = explode('--', $line);
        if (count($parts) >= 2) {
            $result[] = [
                'name' => trim($parts[0]),
                'type' => trim($parts[1]) ?? '',
                'required' => isset($parts[2]) ? trim($parts[2]) : '否',
                'info' => isset($parts[3]) ? trim($parts[3]) : '',
            ];
        }
    }
    return $result;
}

/**
 * 解析返回参数文本为数组
 * 格式：[名称--类型--说明]，每行一个参数
 */
function parseReturnParams($text) {
    $result = [];
    $text = trim($text);
    if ($text === '') return $result;
    $lines = preg_split('/[\r\n]+/', $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '|' || $line === '-') continue;
        $line = trim($line, '[]');
        $parts = explode('--', $line);
        if (count($parts) >= 2) {
            $result[] = [
                'name' => trim($parts[0]),
                'type' => trim($parts[1]) ?? '',
                'msg' => isset($parts[2]) ? trim($parts[2]) : '',
            ];
        }
    }
    return $result;
}

/**
 * 解析状态码文本为数组
 * 格式：[状态码--状态信息]，每行一个
 */
function parseErrorParams($text) {
    $result = [];
    $text = trim($text);
    if ($text === '') return $result;
    $lines = preg_split('/[\r\n]+/', $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '|' || $line === '-') continue;
        $line = trim($line, '[]');
        $parts = explode('--', $line);
        if (count($parts) >= 1) {
            $result[] = [
                'code' => trim($parts[0]),
                'msg' => isset($parts[1]) ? trim($parts[1]) : '',
            ];
        }
    }
    return $result;
}

$req = $_REQUEST;
switch ($req["type"]) {

		/* 添加API */
	case 'add_api':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$request = parseRequestParams($req["request_parameter"] ?? '');
		$request_json = json_encode(["data" => $request], 320);
		$return = parseReturnParams($req["return_parameter"] ?? '');
		$return_json = json_encode(["data" => $return], 320);
		$errorcode = parseErrorParams($req["error_code"] ?? '');
		$errorcode_json = json_encode(["data" => $errorcode], 320);

		// 使用原始值，预处理语句会自动处理转义
		$name = trim($req['name'] ?? '');
		$enname = trim($req['enname'] ?? '');
		$desc = trim($req['desc'] ?? '');
		$url = trim($req['url'] ?? '');
		$example_url = trim($req['example_url'] ?? '');
		$format = trim($req['format'] ?? '');
		$method = trim($req['method'] ?? '');
		$PHP_example = $req['PHP_example'] ?? '';
		$return_val = $req['return'] ?? '';
		$charge_type = intval($req['charge_type'] ?? 0);
		$price = floatval($req['price'] ?? 0);
		$time = time();
		if ($name && $enname && $desc && $url && $example_url && $format && $method && $request_json && $return_json && $errorcode_json && $PHP_example && $return_val) {
			// 构建 INSERT 语句 - 使用预处理语句防止 SQL 注入并保护 JSON 格式
			$has_charge_type = $db->query("SHOW COLUMNS FROM `mxgapi_api` LIKE 'charge_type'");
			$has_price = $db->query("SHOW COLUMNS FROM `mxgapi_api` LIKE 'price'");
			$has_charge_type = $has_charge_type && $has_charge_type->num_rows > 0;
			$has_price = $has_price && $has_price->num_rows > 0;
			
			// 基础字段 - 使用反引号包裹以防保留字冲突
			$insertFields = ['`name`', '`enname`', '`desc`', '`url`', '`format`', '`method`', '`example_url`', '`request_parameter`', '`return_parameter`', '`error_code`', '`PHP_example`', '`return`', '`status`', '`time`'];
			$insertTypes = 'ssssssssssssii';
			$insertValues = [$name, $enname, $desc, $url, $format, $method, $example_url, $request_json, $return_json, $errorcode_json, $PHP_example, $return_val, 1, $time];
			
			if ($has_charge_type) {
				$insertFields[] = '`charge_type`';
				$insertTypes .= 'i';
				$insertValues[] = $charge_type;
			}
			if ($has_price) {
				$insertFields[] = '`price`';
				$insertTypes .= 'd';
				$insertValues[] = $price;
			}
			
			$placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
			$sql = "INSERT INTO `mxgapi_api`(" . implode(', ', $insertFields) . ") VALUES ($placeholders)";
			$stmt = $db->prepare($sql);
			if (!$stmt) {
				$errorMsg = $db->error ?: '预处理语句失败';
				jsonError(-1, '添加失败: ' . $errorMsg);
			}
			bindParams($stmt, $insertTypes, $insertValues);
			$add_result = $stmt->execute();
			$stmt->close();
			if ($add_result) {
				jsonError(0, '添加成功');
			} else {
				$errorMsg = $db->error;
				if (empty($errorMsg)) {
					$errorMsg = '未知错误';
				}
				jsonError(-1, '添加失败: ' . $errorMsg);
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 删除API */
	case 'del_api':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req["id"]);
		if ($id) {
			$result = $db->query("DELETE FROM `mxgapi_api` WHERE `id`='{$id}';");
			if ($result) {
				jsonError(0, '删除成功');
			} else {
				jsonError(-1, '删除失败');
			}
		} else {
			jsonError(-1, '缺少参数');
		}
		break;

		/* 快速切换接口计费类型 */
	case 'quick_charge_type':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req["id"]);
		$charge_type = intval($req["charge_type"]);
		// 只允许 0=会员专用, 1=按次计费, 2=免费公开
		if (!in_array($charge_type, [0, 1, 2])) {
			jsonError(-1, '计费类型无效');
		}
		if ($id) {
			// 检查字段是否存在
			$columns = $db->query("SHOW COLUMNS FROM `mxgapi_api` LIKE 'charge_type'");
			$has_charge_type = $columns && $columns->num_rows > 0;
			if (!$has_charge_type) {
				jsonError(-1, '数据库未启用计费功能');
			}
			// 切换为按次计费时保留原价格，切换为其他类型时价格清零
			$pricePart = "";
			if ($charge_type != 1) {
				$pricePart = ", `price`='0'";
			}
			$sql = "UPDATE `mxgapi_api` SET `charge_type`='{$charge_type}'{$pricePart} WHERE `id`='{$id}'";
			$result = $db->query($sql);
			if ($result) {
				$typeNames = [0 => '会员专用', 1 => '按次计费', 2 => '免费公开'];
				jsonError(0, '已切换为：' . $typeNames[$charge_type]);
			} else {
				jsonError(-1, '切换失败: ' . $db->error);
			}
		} else {
			jsonError(-1, '缺少参数');
		}
		break;

		/* 修改API信息 */
	case 'edit_api':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$request = parseRequestParams($req["request_parameter"] ?? '');
		$request_json = json_encode(["data" => $request], 320);
		$return = parseReturnParams($req["return_parameter"] ?? '');
		$return_json = json_encode(["data" => $return], 320);
		$errorcode = parseErrorParams($req["error_code"] ?? '');
		$errorcode_json = json_encode(["data" => $errorcode], 320);
		$id = intval($req['id']);
		$name = trim($req['name'] ?? '');
		$enname = trim($req['enname'] ?? '');
		$desc = trim($req['desc'] ?? '');
		$url = trim($req['url'] ?? '');
		$example_url = trim($req['example_url'] ?? '');
		$format = trim($req['format'] ?? '');
		$method = trim($req['method'] ?? '');
		$request_parameter = $request_json;
		$return_parameter = $return_json;
		$error_code = $errorcode_json;
		$PHP_example = $req['PHP_example'] ?? '';
		$return_val = $req['return'] ?? '';
		$status = intval($req['status']);
		$charge_type = isset($req['charge_type']) ? intval($req['charge_type']) : 0;
		$price = isset($req['price']) ? floatval($req['price']) : 0;
		
		if ($id && $name && $enname && $desc && $url && $example_url && $format && $method && $request_parameter && $return_parameter && $error_code && $PHP_example && $return_val) {
			// 使用预处理语句更新，防止 SQL 注入并保护 JSON 格式
			$has_charge_type = $db->query("SHOW COLUMNS FROM `mxgapi_api` LIKE 'charge_type'");
			$has_price = $db->query("SHOW COLUMNS FROM `mxgapi_api` LIKE 'price'");
			$has_charge_type = $has_charge_type && $has_charge_type->num_rows > 0;
			$has_price = $has_price && $has_price->num_rows > 0;
			
			// 字段名使用反引号以防保留字冲突（desc, return 等）
			$setFields = ['`name`', '`enname`', '`desc`', '`url`', '`format`', '`method`', '`example_url`', '`request_parameter`', '`return_parameter`', '`error_code`', '`PHP_example`', '`return`', '`status`'];
			$setTypes = 'ssssssssssssi';
			$setValues = [$name, $enname, $desc, $url, $format, $method, $example_url, $request_parameter, $return_parameter, $error_code, $PHP_example, $return_val, $status];
			
			if ($has_charge_type) {
				$setFields[] = '`charge_type`';
				$setTypes .= 'i';
				$setValues[] = $charge_type;
			}
			if ($has_price) {
				$setFields[] = '`price`';
				$setTypes .= 'd';
				$setValues[] = $price;
			}
			
			$setClause = implode(' = ?, ', $setFields) . ' = ?';
			$sql = "UPDATE `mxgapi_api` SET $setClause WHERE `id` = ?";
			$setTypes .= 'i';
			$setValues[] = $id;
			
			$stmt = $db->prepare($sql);
			if (!$stmt) {
				$errorMsg = $db->error ?: '预处理语句失败';
				jsonError(-1, '保存失败: ' . $errorMsg);
			}
			bindParams($stmt, $setTypes, $setValues);
			$result = $stmt->execute();
			$stmt->close();
			
			if ($result) {
				jsonError(0, '保存成功');
			} else {
				$errorMsg = $db->error;
				if (empty($errorMsg)) {
					$errorMsg = '未知错误';
				}
				jsonError(-1, '保存失败: ' . $errorMsg);
			}
		} else {
			jsonError(-1, '缺少必要参数');
		}
		break;

		/* 登录到后台 */
	case 'login':
		// 先验证数据库连接
		if (!isset($db) || !($db instanceof mysqli)) {
			jsonError(-1, '数据库未连接，请先安装系统');
		}
		$result = $db->query("SELECT * FROM `mxgapi_config`");
		if (!$result) {
			jsonError(-1, '系统配置缺失，请重新安装');
		}
		$config = $result->fetch_assoc();
		if (!$config) {
			jsonError(-1, '系统配置为空，请重新安装');
		}
		
		$username = trim($req["username"]);
		$password = trim($req["password"]);
		if (!$username || !$password) {
			jsonError(-1, '请输入完整');
		}
		
		if ($username == $config["username"] && $password == $config["password"]) {
			$_SESSION['login'] = 'admin';
			$_SESSION['admin_username'] = $username;
			
			// 尝试记录登录日志（失败不阻断登录）
			$ip = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : '127.0.0.1';
			$time = time();
			@$db->query("INSERT INTO `mxgapi_login_log` (`id`, `ip`, `address`, `time`) VALUES (NULL, '{$ip}', '登录成功', '{$time}');");
			
			jsonError(0, '登录成功');
		} else {
			jsonError(-1, '用户名或密码错误');
		}
		break;

		/* 添加友情链接 */
	case 'add_link':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$name = $req["name"];
		$desc = $req["desc"];
		$url = $req["url"];
		$picurl = $req["picurl"];
		$time = time();
		if ($name && $desc && $url && $picurl) {
			$result = $db->query("INSERT INTO `mxgapi_friendlinks`(`id`, `name`, `desc`, `url`, `picurl`, `time`) VALUES (NULL,'{$name}','{$desc}','{$url}','{$picurl}','{$time}')");
			if ($result) {
				jsonError(0, '添加成功');
			} else {
				jsonError(-1, '添加失败');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 删除友情链接 */
	case 'del_link':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req["id"]);
		if (!$id) {
			jsonError(-1, '缺少参数');
		}
		$result = $db->query("DELETE FROM `mxgapi_friendlinks` WHERE `id`='{$id}';");
		if ($result) {
			jsonError(0, '删除成功');
		} else {
			jsonError(-1, '删除失败');
		}
		break;

		/* 修改友情链接 */
	case 'edit_link':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req['id']);
		$name = $req['name'];
		$desc = $req['desc'];
		$url = $req['url'];
		$picurl = $req['picurl'];
		if ($id && $name && $desc && $url && $picurl) {
			$result = $db->query("UPDATE `mxgapi_friendlinks` SET `name`='{$name}',`desc`='{$desc}',`url`='{$url}',`picurl`='{$picurl}' WHERE `id`='{$id}';");
			if ($result) {
				jsonError(0, '保存成功');
			} else {
				jsonError(-1, '保存失败');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 修改后台登录密码 */
	case 'edit_pwd':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$password = $req['password'];
		$password2 = $req['password2'];
		if (trim($password) && trim($password2)) {
			if ($password != $password2) {
				jsonError(-1, '两次密码不一致');
			}
			$result = $db->query("UPDATE `mxgapi_config` SET `password`='{$password}';");
			if ($result) {
				jsonError(0, '修改成功');
			} else {
				jsonError(1, '修改失败');
			}
		} else {
			jsonError(-1, '请输入完整！');
		}
		break;

		/* 修改网站配置信息 */
	case 'websetting':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$title = $req["title"];
		$subtitle = $req["subtitle"];
		$keywords = $req["keywords"];
		$description = $req["description"];
		$favicon = $req["favicon"];
		$url = $req["url"];
		$icp = $req["icp"];
		$copyright = $req["copyright"];
		$end_script = $req["end_script"];
		$accent = $req["accent"];
		if ($title && $subtitle && $keywords && $description && $favicon && $url && $copyright) {
			$result = $db->query("UPDATE `mxgapi_config` SET `title`='{$title}',`subtitle`='{$subtitle}',`description`='{$description}',`keywords`='{$keywords}',`favicon`='{$favicon}',`url`='{$url}',`icp`='{$icp}',`copyright`='{$copyright}',`accent`='blue',`end_script`='{$end_script}';");
			if ($result) {
				jsonError(0, '保存成功');
			} else {
				jsonError(-1, '保存失败');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 修改邮件配置信息 */
	case 'smtp_config':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$smtp_host = $req['smtp_host'];
		$smtp_username = $req['smtp_username'];
		$smtp_password = $req['smtp_password'];
		$smtp_port = $req['smtp_port'];
		$smtp_secure = $req['smtp_secure'];
		if ($smtp_host && $smtp_username && $smtp_password && $smtp_port) {
			$result = $db->query("UPDATE `mxgapi_config` SET `smtp_host`='{$smtp_host}',`smtp_username`='{$smtp_username}',`smtp_password`='{$smtp_password}',`smtp_port`='{$smtp_port}',`smtp_secure`='{$smtp_secure}';");
			if ($result) {
				jsonError(0,'保存成功');
			} else {
				jsonError(-1,'保存失败');
			}
		} else {
			jsonError(-1,'请输入完整');
		}
		break;

	case 'close_site':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$close_site = $req['close_site'];
		$now = $db->query('SELECT close_site FROM `mxgapi_config`;')->fetch_assoc();
		$now = $now['close_site'];
		$sql = "UPDATE `mxgapi_config` SET `close_site`=";

		if ($close_site == '1') {
			if ($close_site != $now) {
				$sql .= 'true';
				$msg = '开启成功';
			} else {
				jsonError(-1, '已经开启了');
			}
		} else if ($close_site == '0') {
			if ($close_site != $now) {
				$sql .= 'false';
				$msg = '关闭成功';
			} else {
				jsonError(-1, '已经关闭了');
			}
		}
		if ($result = $db->query($sql)) {
			jsonError(0, $msg);
		} else {
			jsonError(-1, '未知原因');
		}
		break;



	case 'cc_protect':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$cc_protect = $req['cc_protect'];
		$now = $db->query('SELECT cc_protect FROM `mxgapi_config`;')->fetch_assoc();
		$now = $now['cc_protect'];
		$sql = "UPDATE `mxgapi_config` SET `cc_protect`=";

		if ($cc_protect == '1') {
			if ($cc_protect != $now) {
				$sql .= 'true';
				$msg = '开启成功';
			} else {
				jsonError(-1, '已经开启了');
			}
		} else if ($cc_protect == '0') {
			if ($cc_protect != $now) {
				$sql .= 'false';
				$msg = '关闭成功';
			} else {
				jsonError(-1, '已经关闭了');
			}
		}
		if ($result = $db->query($sql)) {
			jsonError(0, $msg);
		} else {
			jsonError(-1, '未知原因');
		}
		break;


	case 'fire_wall':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$fire_wall = $req['fire_wall'];
		$now = $db->query('SELECT fire_wall FROM `mxgapi_config`;')->fetch_assoc();
		$now = $now['fire_wall'];
		$sql = "UPDATE `mxgapi_config` SET `fire_wall`=";

		if ($fire_wall == '1') {
			if ($fire_wall != $now) {
				$sql .= 'true';
				$msg = '开启成功';
			} else {
				jsonError(-1, '已经开启了');
			}
		} else if ($fire_wall == '0') {
			if ($fire_wall != $now) {
				$sql .= 'false';
				$msg = '关闭成功';
			} else {
				jsonError(-1, '已经关闭了');
			}
		}
		if ($result = $db->query($sql)) {
			jsonError(0, $msg);
		} else {
			jsonError(-1, '未知原因');
		}
		break;

		/* 修改后台用户信息 */
	case 'edit_user':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$username = $req['username'];
		$email = $req['email'];
		$qq = $req['qq'];
		$qqqrcode = $req['qqqrcode'];
		$vxqrcode = $req['vxqrcode'];
		$aliqrcode = $req['aliqrcode'];
		if ($username && $email && $qq) {
			$result = $db->query("UPDATE `mxgapi_config` SET `username`='{$username}',`email`='{$email}',`qq`='{$qq}',`qqqrcode`='{$qqqrcode}',`vxqrcode`='{$vxqrcode}',`aliqrcode`='{$aliqrcode}';");
			if ($result) {
				jsonError(0, '修改成功');
			} else {
				jsonError(-1, '修改失败');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 添加公告 */
	case 'add_post':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$title = $req["title"];
		$content = $req["content"];
		$icon = $req['icon'];
		$time = time();
		if ($title && $content && $icon) {
			$result = $db->query("INSERT INTO `mxgapi_post`(`id`, `title`, `content`, `icon`, `time`) VALUES (NULL,'{$title}','{$content}','{$icon}','{$time}')");
			if ($result) {
				jsonError(0, '添加成功');
			} else {
				jsonError(-1, '添加失败');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 修改公告信息 */
	case 'edit_post':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req['id']);
		$title = $req['title'];
		$content = $req['content'];
		$icon = $req['icon'];
		if ($id && $title && $content && $icon) {
			$result = $db->query("UPDATE `mxgapi_post` SET `title`='{$title}',`content`='{$content}',`icon`='{$icon}' WHERE `id`='{$id}';");
			if ($result) {
				jsonError(0, '修改成功');
			} else {
				jsonError(-1, '修改失败');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 删除公告 */
	case 'del_post':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req["id"]);
		if ($id) {
			if ($id == 1) {
				jsonError(-1, '默认公告不能删除');
			}
			$result = $db->query("DELETE FROM `mxgapi_post` WHERE `id`='{$id}';");
			if ($result) {
				jsonError(0, '删除成功');
			} else {
				jsonError(-1, '删除失败');
			}
		} else {
			jsonError(-1, '缺少参数');
		}
		break;

		/* 更改选择公告ID */
	case 'change_post_id':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req['id']);
		if ($id) {
			if ($id != -1) {
				$post = $db->query("select * from `mxgapi_post` where `id`='{$id}';")->fetch_assoc();
				if (!$post) {
					jsonerror(-1, '公告不存在');
				}
			}
			$result = $db->query("UPDATE `mxgapi_config` SET `post_id`='{$id}';");
			if ($result) {
				jsonError(0, '设置成功');
			} else {
				jsonError(-1, '设置失败');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 提交友情链接申请（公开接口，需做输入过滤） */
	case 'submit_friendlink':
		// 自愈：确保申请表存在（老用户未重装时也可用）
		@$db->query("CREATE TABLE IF NOT EXISTS `mxgapi_friendlink_apply` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`name` varchar(255) NOT NULL,
			`url` varchar(255) NOT NULL,
			`picurl` varchar(255) NOT NULL,
			`desc` varchar(255) NOT NULL,
			`contact` varchar(100) NOT NULL DEFAULT '',
			`ip` varchar(50) NOT NULL DEFAULT '',
			`time` int(20) NOT NULL,
			`status` tinyint(1) NOT NULL DEFAULT '0',
			`remark` varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8");

		$name    = trim($req['name'] ?? '');
		$url     = trim($req['url'] ?? '');
		$picurl  = trim($req['picurl'] ?? '');
		$desc    = trim($req['desc'] ?? '');
		$contact = trim($req['contact'] ?? ''); // 联系方式（可选）
		$ip      = $_SERVER["REMOTE_ADDR"] ?? '';
		$time    = time();

		// 基础长度限制
		if (mb_strlen($name) > 50)   { jsonError(-1, '网站名称不能超过 50 个字符'); break; }
		if (mb_strlen($url) > 255)   { jsonError(-1, '网站地址过长'); break; }
		if (mb_strlen($picurl) > 255){ jsonError(-1, 'Logo 地址过长'); break; }
		if (mb_strlen($desc) > 100)  { jsonError(-1, '网站描述不能超过 100 个字符'); break; }
		if (mb_strlen($contact) > 100){ jsonError(-1, '联系方式过长'); break; }

		// 必填校验
		if ($name === '' || $url === '') {
			jsonError(-1, '请填写网站名称和地址');
			break;
		}

		// URL 合法性校验
		$parsed = @parse_url($url);
		$host   = strtolower($parsed['host'] ?? '');
		$scheme = strtolower($parsed['scheme'] ?? '');
		if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
			jsonError(-1, '网站地址格式不正确');
			break;
		}

		// 简单频率限制：同一 IP 10 分钟内不能重复提交同一 URL
		$escapedIp  = $db->real_escape_string($ip);
		$escapedUrl = $db->real_escape_string($url);
		$recent = $db->query("SELECT id FROM `mxgapi_friendlink_apply` WHERE `ip`='{$escapedIp}' AND `url`='{$escapedUrl}' AND `time` > " . ($time - 600) . " LIMIT 1");
		if ($recent && $recent->num_rows > 0) {
			jsonError(-1, '您已提交过该站点，请耐心等待审核（10分钟内不可重复提交）');
			break;
		}

		// 输入转义
		$escapedName    = $db->real_escape_string($name);
		$escapedPicurl  = $db->real_escape_string($picurl);
		$escapedDesc    = $db->real_escape_string($desc);
		$escapedContact = $db->real_escape_string($contact);

		$sql = "INSERT INTO `mxgapi_friendlink_apply`(`id`,`name`,`url`,`picurl`,`desc`,`contact`,`ip`,`time`,`status`)
				VALUES (NULL,'{$escapedName}','{$escapedUrl}','{$escapedPicurl}','{$escapedDesc}','{$escapedContact}','{$escapedIp}','{$time}',0)";
		$result = $db->query($sql);
		if ($result) {
			jsonError(0, '提交成功，等待管理员审核');
		} else {
			jsonError(-1, '提交失败，请稍后再试');
		}
		break;

		/* 审核通过友链申请（管理员）—— 移动到正式友链表 */
	case 'approve_apply':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
			break;
		}
		$id = intval($req['id']);
		if (!$id) {
			jsonError(-1, '缺少参数');
			break;
		}
		// 确保申请表存在
		@$db->query("CREATE TABLE IF NOT EXISTS `mxgapi_friendlink_apply` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`name` varchar(255) NOT NULL,
			`url` varchar(255) NOT NULL,
			`picurl` varchar(255) NOT NULL,
			`desc` varchar(255) NOT NULL,
			`contact` varchar(100) NOT NULL DEFAULT '',
			`ip` varchar(50) NOT NULL DEFAULT '',
			`time` int(20) NOT NULL,
			`status` tinyint(1) NOT NULL DEFAULT '0',
			`remark` varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8");

		$row = $db->query("SELECT * FROM `mxgapi_friendlink_apply` WHERE `id`='{$id}' LIMIT 1");
		if (!$row || $row->num_rows === 0) {
			jsonError(-1, '申请不存在');
			break;
		}
		$data = $row->fetch_assoc();
		// 重复 URL 检查：防止已存在的友链被再次添加
		$escapedCheckUrl = $db->real_escape_string($data['url']);
		$dup = $db->query("SELECT id FROM `mxgapi_friendlinks` WHERE `url`='{$escapedCheckUrl}' LIMIT 1");
		if ($dup && $dup->num_rows > 0) {
			// 已存在相同 URL，直接删除申请记录并提示
			$db->query("DELETE FROM `mxgapi_friendlink_apply` WHERE `id`='{$id}'");
			jsonError(-1, '该友链已存在，无需重复添加');
			break;
		}
		// 移动到正式友链表
		$escapedName   = $db->real_escape_string($data['name']);
		$escapedUrl    = $db->real_escape_string($data['url']);
		$escapedPicurl = $db->real_escape_string($data['picurl']);
		$escapedDesc   = $db->real_escape_string($data['desc']);
		$time = time();
		$insertResult = $db->query("INSERT INTO `mxgapi_friendlinks`(`id`,`name`,`desc`,`url`,`picurl`,`time`)
			VALUES (NULL,'{$escapedName}','{$escapedDesc}','{$escapedUrl}','{$escapedPicurl}','{$time}')");
		if ($insertResult) {
			// 标记为已通过
			$db->query("UPDATE `mxgapi_friendlink_apply` SET `status`=1 WHERE `id`='{$id}'");
			// 可选：删除原申请记录
			$db->query("DELETE FROM `mxgapi_friendlink_apply` WHERE `id`='{$id}'");
			jsonError(0, '审核通过并已添加到友情链接');
		} else {
			jsonError(-1, '添加到友情链接失败');
		}
		break;

		/* 驳回/删除友链申请 */
	case 'del_apply':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
			break;
		}
		$id = intval($req["id"]);
		if (!$id) {
			jsonError(-1, '缺少参数');
			break;
		}
		@$db->query("CREATE TABLE IF NOT EXISTS `mxgapi_friendlink_apply` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`name` varchar(255) NOT NULL,
			`url` varchar(255) NOT NULL,
			`picurl` varchar(255) NOT NULL,
			`desc` varchar(255) NOT NULL,
			`contact` varchar(100) NOT NULL DEFAULT '',
			`ip` varchar(50) NOT NULL DEFAULT '',
			`time` int(20) NOT NULL,
			`status` tinyint(1) NOT NULL DEFAULT '0',
			`remark` varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8");
		$result = $db->query("DELETE FROM `mxgapi_friendlink_apply` WHERE `id`='{$id}'");
		if ($result) {
			jsonError(0, '删除成功');
		} else {
			jsonError(-1, '删除失败');
		}
		break;

		/* 添加反馈 */
	case 'add_feedback':
		$ip = $_SERVER["REMOTE_ADDR"];
		$api_id = $req['api_id'];
		$api_name = $req['api_name'];
		$title = $req["title"];
		$content = $req["content"];
		$email = $req['email'];
		$time = time();
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			jsonError(-1, '邮箱格式不正确');
		}
		if (trim($title) && trim($content) && $ip && $email && $api_id && $api_name) {
			if ($_SESSION['feedback'][$ip]['api_id'] != $api_id) {
				$result = $db->query("INSERT INTO `mxgapi_feedback`(`id`,`api_id`, `api_name`, `title`, `content`, `ip`, `email`, `time`) VALUES (NULL,'{$api_id}','{$api_name}','{$title}','{$content}','{$ip}','{$email}','{$time}')");
				if ($result) {
					$_SESSION['feedback'][$ip]['api_id'] = $api_id;
					jsonError(0, '反馈成功');
				} else {
					jsonError(-1, '反馈失败');
				}
			} else {
				jsonError(-1, '你已经反馈过该接口了');
			}
		} else {
			jsonError(-1, '请输入完整');
		}
		break;

		/* 删除反馈 */
	case 'del_feedback':
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$id = intval($req["id"]);
		if ($id) {
			$result = $db->query("DELETE FROM `mxgapi_feedback` WHERE `id`='{$id}';");
			if ($result) {
				jsonError(0, '删除成功');
			} else {
				jsonError(-1, '删除失败');
			}
		} else {
			jsonError(-1, '缺少参数');
		}
		break;

		/* 回复反馈 */
	case 'reply_feedback':
		require '../Include/Common.php';
		if (!isAdmin()) {
			jsonError(-1, '用户未登录');
		}
		$email = $req['email'];
		$content = $req['content'];
		if ($email && $content) {
			$result = sendMail($email, '反馈接口信息', $content);
			die($result);
		} else {
			jsonError(-1, '请输入完整');
		}
		break;
}
