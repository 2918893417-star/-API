<?php
require 'init.php';
require_once __DIR__ . '/../Core/apikey_functions.php';

$type = $_REQUEST['type'];

switch($type){

	/* 用户注册 */
	case 'register' :
		$username = trim($_POST['username']);
		$password = $_POST['password'];
		$email = trim($_POST['email']);
		
		if(!$username || !$password || !$email){
			jsonError(-1, '参数不完整');
		}
		if(strlen($username) < 3 || strlen($username) > 20){
			jsonError(-1, '用户名长度应在3-20位之间');
		}
		if(strlen($password) < 6){
			jsonError(-1, '密码长度不少于6位');
		}
		if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
			jsonError(-1, '邮箱格式不正确');
		}
		
		$escapedUsername = $db->real_escape_string($username);
		$escapedEmail = $db->real_escape_string($email);
		
		$check = $db->query("SELECT id FROM `mxgapi_user` WHERE `username`='{$escapedUsername}'");
		if($check->num_rows > 0){
			jsonError(-1, '用户名已存在');
		}
		$check = $db->query("SELECT id FROM `mxgapi_user` WHERE `email`='{$escapedEmail}'");
		if($check->num_rows > 0){
			jsonError(-1, '邮箱已被注册');
		}
		
		// 使用 password_hash 加密密码 (比 MD5 更安全)
		$hashedPwd = password_hash($password, PASSWORD_DEFAULT);
		$time = time();
		$sql = "INSERT INTO `mxgapi_user`(`username`,`password`,`email`,`balance`,`level`,`expire_time`,`status`,`reg_time`) 
				VALUES('{$escapedUsername}','{$hashedPwd}','{$escapedEmail}','0.00','0','0','1','{$time}')";
		$result = $db->query($sql);
		if($result){
			json(0, '注册成功', ['user_id' => $db->insert_id]);
		}else{
			jsonError(-1, '注册失败');
		}
		break;

	/* 用户登录 */
	case 'login' :
		$username = trim($_POST['username']);
		$password = $_POST['password'];
		
		if(!$username || !$password){
			jsonError(-1, '参数不完整');
		}
		
		$escapedUsername = $db->real_escape_string($username);
		
		// 先查找用户(使用更安全的方式验证密码)
		$result = $db->query("SELECT * FROM `mxgapi_user` WHERE `username`='{$escapedUsername}'");
		if(!$result || $result->num_rows == 0){
			jsonError(-1, '用户名或密码错误');
		}
		$user = $result->fetch_assoc();
		
		// 验证密码 (兼容旧 MD5 和新 password_hash)
		$passwordValid = false;
		$needsUpdate = false;
		
		// 如果是旧 MD5 格式(32位十六进制),先验证 MD5 并升级
		if(strlen($user['password']) === 32 && ctype_xdigit($user['password'])){
			if(md5($password) === $user['password']){
				$passwordValid = true;
				$needsUpdate = true;
			}
		} else {
			// 新格式使用 password_verify
			if(password_verify($password, $user['password'])){
				$passwordValid = true;
				// 如果需要重新哈希(算法参数变化)
				if(password_needs_rehash($user['password'], PASSWORD_DEFAULT)){
					$needsUpdate = true;
				}
			}
		}
		
		if(!$passwordValid){
			jsonError(-1, '用户名或密码错误');
		}
		
		if($user['status'] != 1){
			jsonError(-1, '账户已被禁用');
		}
		
		// 如果需要更新密码哈希格式
		if($needsUpdate){
			$newHash = password_hash($password, PASSWORD_DEFAULT);
			$db->query("UPDATE `mxgapi_user` SET `password`='{$newHash}' WHERE `id`='{$user['id']}'");
		}
		
		$_SESSION['user_id'] = $user['id'];
		$_SESSION['user_login'] = true;
		$_SESSION['user_login_name'] = $user['username'];
		
		$db->query("UPDATE `mxgapi_user` SET `last_login_time`='".time()."' WHERE `id`='{$user['id']}'");
		
		json(0, '登录成功', getUserData($user));
		break;

	/* 用户登出 */
	case 'logout' :
		session_start();
		unset($_SESSION['user_id']);
		unset($_SESSION['user_login']);
		unset($_SESSION['user_login_name']);
		// 如果是AJAX请求,返回JSON;否则直接跳转
		if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'){
			json(0, '退出成功', []);
		}else{
			header('Location: ../?action=login');
			exit;
		}
		break;

	/* 获取当前登录用户信息 */
	case 'getUserInfo' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '未登录');
		}
		$user_id = intval($_SESSION['user_id']);

		// 自动补齐可能缺失的字段
		$alterColumns = array(
			'qq' => "ALTER TABLE `mxgapi_user` ADD COLUMN `qq` varchar(30) NOT NULL DEFAULT '' AFTER `email`",
			'qq_qrcode' => "ALTER TABLE `mxgapi_user` ADD COLUMN `qq_qrcode` varchar(500) NOT NULL DEFAULT '' AFTER `qq`",
			'wx_qrcode' => "ALTER TABLE `mxgapi_user` ADD COLUMN `wx_qrcode` varchar(500) NOT NULL DEFAULT '' AFTER `qq_qrcode`",
			'ali_qrcode' => "ALTER TABLE `mxgapi_user` ADD COLUMN `ali_qrcode` varchar(500) NOT NULL DEFAULT '' AFTER `wx_qrcode`"
		);
		foreach($alterColumns as $col => $alterSql){
			$exists = $db->query("SHOW COLUMNS FROM `mxgapi_user` LIKE '".$col."'");
			if(!$exists || $exists->num_rows === 0){
				@$db->query($alterSql);
			}
		}

		$result = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$user_id}'");
		if(!$result || $result->num_rows == 0){
			jsonError(-1, '用户不存在');
		}
		$user = $result->fetch_assoc();
		json(0, '获取成功', getUserData($user));
		break;

	/* 获取所有用户(后台) */
	case 'getAllUsers' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$sql = 'SELECT * FROM `mxgapi_user` ORDER BY id DESC';
		$result = $db->query($sql);
		if($result){
			$users = [];
			while($user = $result->fetch_assoc()){
				$user['reg_time'] = date('Y-m-d H:i:s', $user['reg_time']);
				$user['last_login_time'] = $user['last_login_time'] ? date('Y-m-d H:i:s', $user['last_login_time']) : '-';
				$user['level_name'] = getLevelName($user['level']);
				$user['expire_time'] = $user['expire_time'] ? date('Y-m-d H:i:s', $user['expire_time']) : '永久';
				$users[] = $user;
			}
			json(0, '获取成功', $users);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 更新用户状态(后台) */
	case 'updateUserStatus' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$id = intval($_POST['id']);
		$status = intval($_POST['status']);
		$result = $db->query("UPDATE `mxgapi_user` SET `status`='{$status}' WHERE `id`='{$id}'");
		if($result){
			json(0, '更新成功', []);
		}else{
			jsonError(-1, '更新失败');
		}
		break;

	/* 删除用户(后台) */
	case 'deleteUser' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$id = intval($_POST['id']);
		$result = $db->query("DELETE FROM `mxgapi_user` WHERE `id`='{$id}'");
		if($result){
			json(0, '删除成功', []);
		}else{
			jsonError(-1, '删除失败');
		}
		break;

	/* 获取所有套餐 */
	case 'getPlans' :
		$sql = 'SELECT * FROM `mxgapi_plan` WHERE `status`=1 ORDER BY sort ASC';
		$result = $db->query($sql);
		if($result){
			$plans = $result->fetch_all(MYSQLI_ASSOC);
			$plans = array_map('formatPlan', $plans);
			json(0, '获取成功', $plans);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 获取所有套餐(后台) */
	case 'getAllPlans' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$sql = 'SELECT * FROM `mxgapi_plan` ORDER BY sort ASC';
		$result = $db->query($sql);
		if($result){
			$plans = $result->fetch_all(MYSQLI_ASSOC);
			$plans = array_map('formatPlan', $plans);
			json(0, '获取成功', $plans);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 创建/更新套餐(后台) */
	case 'savePlan' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$id = intval($_POST['id']);
		$data = [
			'name' => $db->real_escape_string($_POST['name']),
			'description' => $db->real_escape_string($_POST['description']),
			'price' => floatval($_POST['price']),
			'duration' => intval($_POST['duration']),
			'level' => intval($_POST['level']),
			'features' => $db->real_escape_string($_POST['features']),
			'sort' => intval($_POST['sort']),
			'status' => intval($_POST['status'])
		];
		if($id > 0){
			$set = [];
			foreach($data as $k => $v){
				$set[] = "`{$k}`='{$v}'";
			}
			$sql = "UPDATE `mxgapi_plan` SET ".implode(',', $set)." WHERE `id`='{$id}'";
		}else{
			$data['create_time'] = time();
			$sql = "INSERT INTO `mxgapi_plan`(`".implode('`,`', array_keys($data))."`) VALUES('".implode("','", $data)."')";
		}
		$result = $db->query($sql);
		if($result){
			json(0, '保存成功', []);
		}else{
			jsonError(-1, '保存失败');
		}
		break;

	/* 删除套餐(后台) */
	case 'deletePlan' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$id = intval($_POST['id']);
		$result = $db->query("DELETE FROM `mxgapi_plan` WHERE `id`='{$id}'");
		if($result){
			json(0, '删除成功', []);
		}else{
			jsonError(-1, '删除失败');
		}
		break;

	/* 创建订单 */
	case 'createOrder' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$plan_id = intval($_POST['plan_id']);
		$pay_type = $_POST['pay_type'] ?: 'balance';
		
		$plan = $db->query("SELECT * FROM `mxgapi_plan` WHERE `id`='{$plan_id}' AND `status`=1");
		if(!$plan || $plan->num_rows == 0){
			jsonError(-1, '套餐不存在或已下架');
		}
		$planData = $plan->fetch_assoc();
		
		$user = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$user_id}'")->fetch_assoc();
		
		$orderNo = 'ORD'.date('YmdHis').mt_rand(1000, 9999);
		$time = time();
		
		if($pay_type == 'balance'){
			if($user['balance'] < $planData['price']){
				jsonError(-1, '余额不足,请先充值或选择其他支付方式');
			}
		}
		
		$sql = "INSERT INTO `mxgapi_order`(`order_no`,`user_id`,`user_email`,`plan_id`,`plan_name`,`amount`,`pay_type`,`status`,`create_time`) 
				VALUES('{$orderNo}','{$user_id}','".$db->real_escape_string($user['email'])."','{$plan_id}','".$db->real_escape_string($planData['name'])."','{$planData['price']}','{$pay_type}','0','{$time}')";
		$result = $db->query($sql);
		
		if(!$result){
			jsonError(-1, '创建订单失败');
		}
		
		$order_id = $db->insert_id;
		$order = ['order_id' => $order_id, 'order_no' => $orderNo, 'amount' => $planData['price'], 'pay_type' => $pay_type];
		
		// 如果是余额支付,直接完成
		if($pay_type == 'balance'){
			processOrderPaid($order_id, $user_id, $planData, $user);
			$order['status'] = 1;
			$order['msg'] = '支付成功';
		}else{
			$order['msg'] = '订单已创建,请完成支付';
		}
		
		json(0, '创建成功', $order);
		break;

	/* 获取当前用户订单列表 */
	case 'getMyOrders' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$sql = "SELECT * FROM `mxgapi_order` WHERE `user_id`='{$user_id}' ORDER BY id DESC";
		$result = $db->query($sql);
		if($result){
			$orders = [];
			while($order = $result->fetch_assoc()){
				$order['create_time'] = date('Y-m-d H:i:s', $order['create_time']);
				$order['pay_time'] = $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '-';
				$order['status_name'] = getOrderStatusName($order['status']);
				$order['pay_type_name'] = getPayTypeName($order['pay_type']);
				$orders[] = $order;
			}
			json(0, '获取成功', $orders);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 获取所有订单(后台) */
	case 'getAllOrders' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$sql = "SELECT o.*, u.username FROM `mxgapi_order` o LEFT JOIN `mxgapi_user` u ON o.user_id=u.id ORDER BY o.id DESC";
		$result = $db->query($sql);
		if($result){
			$orders = [];
			while($order = $result->fetch_assoc()){
				$order['create_time'] = date('Y-m-d H:i:s', $order['create_time']);
				$order['pay_time'] = $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '-';
				$order['status_name'] = getOrderStatusName($order['status']);
				$order['pay_type_name'] = getPayTypeName($order['pay_type']);
				$orders[] = $order;
			}
			json(0, '获取成功', $orders);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 管理员手动开通会员 */
	case 'adminActivatePlan' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$user_id = intval($_POST['user_id']);
		$plan_id = intval($_POST['plan_id']);
		
		$plan = $db->query("SELECT * FROM `mxgapi_plan` WHERE `id`='{$plan_id}' AND `status`=1");
		if(!$plan || $plan->num_rows == 0){
			jsonError(-1, '套餐不存在');
		}
		$planData = $plan->fetch_assoc();
		$user = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$user_id}'")->fetch_assoc();
		
		$time = time();
		$orderNo = 'ORD'.date('YmdHis').mt_rand(1000, 9999);
		$sql = "INSERT INTO `mxgapi_order`(`order_no`,`user_id`,`user_email`,`plan_id`,`plan_name`,`amount`,`pay_type`,`status`,`create_time`) 
				VALUES('{$orderNo}','{$user_id}','".$db->real_escape_string($user['email'])."','{$plan_id}','".$db->real_escape_string($planData['name'])."','0.00','admin','1','{$time}')";
		$db->query($sql);
		processOrderPaid($db->insert_id, $user_id, $planData, $user);
		json(0, '开通成功', []);
		break;

	/* 用户充值余额 */
	case 'recharge' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$amount = floatval($_POST['amount']);
		
		if($amount <= 0){
			jsonError(-1, '充值金额必须大于0');
		}
		
		// 模拟充值(实际项目中需接入支付网关)
		$result = $db->query("UPDATE `mxgapi_user` SET `balance`=`balance`+'{$amount}' WHERE `id`='{$user_id}'");
		if($result){
			$user = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$user_id}'")->fetch_assoc();
			json(0, '充值成功', ['balance' => $user['balance']]);
		}else{
			jsonError(-1, '充值失败');
		}
		break;

	/* 管理员更新用户余额 */
	case 'adminUpdateBalance' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$user_id = intval($_POST['user_id']);
		$balance = floatval($_POST['balance']);
		$result = $db->query("UPDATE `mxgapi_user` SET `balance`='{$balance}' WHERE `id`='{$user_id}'");
		if($result){
			json(0, '更新成功', []);
		}else{
			jsonError(-1, '更新失败');
		}
		break;

	/* 管理员更新用户等级(手动调整) */
	case 'adminUpdateLevel' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$user_id = intval($_POST['user_id']);
		$level = intval($_POST['level']);
		$expire_time = intval($_POST['expire_time']);
		$result = $db->query("UPDATE `mxgapi_user` SET `level`='{$level}',`expire_time`='{$expire_time}' WHERE `id`='{$user_id}'");
		if($result){
			json(0, '更新成功', []);
		}else{
			jsonError(-1, '更新失败');
		}
		break;

	/* 生成 API Key */
	case 'generateApiKey' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$name = trim($_POST['name']) ?: 'API Key';
		
		// 检查当前 Key 数量限制
		$count = $db->query("SELECT COUNT(*) as cnt FROM `mxgapi_apikey` WHERE `user_id`='{$user_id}'")->fetch_assoc();
		if($count['cnt'] >= 10){
			jsonError(-1, '每个用户最多创建10个API Key');
		}
		
		// 生成随机 Key
		$apiKey = 'sk_' . bin2hex(random_bytes(16));
		$maskedKey = substr($apiKey, 0, 8) . str_repeat('*', 24);
		$time = time();
		
		// 获取用户会员等级对应的每日限制
		$user = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$user_id}'")->fetch_assoc();
		$dailyLimit = getDailyLimitByLevel($user['level']);
		
		$sql = "INSERT INTO `mxgapi_apikey`(`user_id`,`api_key`,`api_key_masked`,`name`,`daily_limit`,`last_reset_time`,`status`,`create_time`) 
				VALUES('{$user_id}','{$apiKey}','{$maskedKey}','".$db->real_escape_string($name)."','{$dailyLimit}','{$time}','1','{$time}')";
		$result = $db->query($sql);
		if($result){
			json(0, '生成成功', [
				'id' => $db->insert_id,
				'api_key' => $apiKey,
				'name' => $name,
				'daily_limit' => $dailyLimit
			]);
		}else{
			jsonError(-1, '生成失败');
		}
		break;

	/* 获取当前用户的 API Key 列表 */
	case 'getMyApiKeys' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$sql = "SELECT * FROM `mxgapi_apikey` WHERE `user_id`='{$user_id}' ORDER BY id DESC";
		$result = $db->query($sql);
		if($result){
			$keys = [];
			while($row = $result->fetch_assoc()){
				$row['create_time'] = date('Y-m-d H:i:s', $row['create_time']);
				$row['last_used_time'] = $row['last_used_time'] ? date('Y-m-d H:i:s', $row['last_used_time']) : '-';
				$keys[] = $row;
			}
			json(0, '获取成功', $keys);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 删除/禁用 API Key */
	case 'deleteApiKey' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$key_id = intval($_POST['key_id']);
		
		$key = $db->query("SELECT * FROM `mxgapi_apikey` WHERE `id`='{$key_id}' AND `user_id`='{$user_id}'");
		if(!$key || $key->num_rows == 0){
			jsonError(-1, 'Key不存在或无权限');
		}
		
		$result = $db->query("UPDATE `mxgapi_apikey` SET `status`='0' WHERE `id`='{$key_id}'");
		if($result){
			json(0, '已禁用', []);
		}else{
			jsonError(-1, '操作失败');
		}
		break;

	/* 启用 API Key */
	case 'enableApiKey' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$key_id = intval($_POST['key_id']);
		
		$key = $db->query("SELECT * FROM `mxgapi_apikey` WHERE `id`='{$key_id}' AND `user_id`='{$user_id}'");
		if(!$key || $key->num_rows == 0){
			jsonError(-1, 'Key不存在或无权限');
		}
		
		$result = $db->query("UPDATE `mxgapi_apikey` SET `status`='1' WHERE `id`='{$key_id}'");
		if($result){
			json(0, '已启用', []);
		}else{
			jsonError(-1, '操作失败');
		}
		break;

	/* 管理员获取所有 API Key */
	case 'getAllApiKeys' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$sql = "SELECT k.*, u.username FROM `mxgapi_apikey` k LEFT JOIN `mxgapi_user` u ON k.user_id=u.id ORDER BY k.id DESC";
		$result = $db->query($sql);
		if($result){
			$keys = [];
			while($row = $result->fetch_assoc()){
				$row['create_time'] = date('Y-m-d H:i:s', $row['create_time']);
				$row['last_used_time'] = $row['last_used_time'] ? date('Y-m-d H:i:s', $row['last_used_time']) : '-';
				$keys[] = $row;
			}
			json(0, '获取成功', $keys);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 获取当前用户的扣费记录 */
	case 'getMyChargeLogs' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$page = intval($_REQUEST['page'] ?? 1);
		$pageSize = intval($_REQUEST['pageSize'] ?? 20);
		$offset = ($page - 1) * $pageSize;

		$countSql = "SELECT COUNT(*) as cnt FROM `mxgapi_charge_log` WHERE `user_id`='{$user_id}'";
		$countRes = $db->query($countSql);
		$total = $countRes ? intval($countRes->fetch_assoc()['cnt']) : 0;

		$sql = "SELECT * FROM `mxgapi_charge_log` WHERE `user_id`='{$user_id}' ORDER BY `id` DESC LIMIT {$offset},{$pageSize}";
		$result = $db->query($sql);
		$logs = [];
		if($result){
			while($row = $result->fetch_assoc()){
				$row['time'] = date('Y-m-d H:i:s', $row['time']);
				$row['charge_type_name'] = $row['charge_type'] == 1 ? '按次扣费' : '会员免费';
				$logs[] = $row;
			}
		}

		// 统计总扣费
		$totalAmount = 0;
		$statSql = "SELECT SUM(`amount`) as total FROM `mxgapi_charge_log` WHERE `user_id`='{$user_id}' AND `charge_type`=1";
		$statRes = $db->query($statSql);
		if($statRes){
			$tmp = $statRes->fetch_assoc();
			$totalAmount = floatval($tmp['total'] ?? 0);
		}

		json(0, '获取成功', [
			'list' => $logs,
			'total' => $total,
			'page' => $page,
			'pageSize' => $pageSize,
			'totalPages' => ceil($total / $pageSize),
			'total_amount' => number_format($totalAmount, 4)
		]);
		break;

	/* 用户保存个人资料(修改邮箱/密码等) */
	case 'saveUserProfile' :
		session_start();
		if(empty($_SESSION['user_id'])){
			jsonError(-1, '请先登录');
		}
		$user_id = intval($_SESSION['user_id']);
		$user = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$user_id}'")->fetch_assoc();
		if(!$user){
			jsonError(-1, '用户不存在');
		}

		$email = isset($_POST['email']) ? trim($_POST['email']) : '';
		$qq = isset($_POST['qq']) ? trim($_POST['qq']) : '';
		$wx_qrcode = isset($_POST['wx_qrcode']) ? trim($_POST['wx_qrcode']) : '';
		$ali_qrcode = isset($_POST['ali_qrcode']) ? trim($_POST['ali_qrcode']) : '';
		$qq_qrcode = isset($_POST['qq_qrcode']) ? trim($_POST['qq_qrcode']) : '';

		if(empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)){
			jsonError(-1, '请输入有效的邮箱地址');
		}
		$check = $db->query("SELECT id FROM `mxgapi_user` WHERE `email`='".$db->real_escape_string($email)."' AND `id`!='{$user_id}'");
		if($check && $check->num_rows > 0){
			jsonError(-1, '该邮箱已被其他用户使用');
		}

		// 自动补齐可能缺失的字段（QQ/二维码等），首次使用时自动 ALTER
		$alterColumns = array(
			'qq' => "ALTER TABLE `mxgapi_user` ADD COLUMN `qq` varchar(30) NOT NULL DEFAULT '' AFTER `email`",
			'qq_qrcode' => "ALTER TABLE `mxgapi_user` ADD COLUMN `qq_qrcode` varchar(500) NOT NULL DEFAULT '' AFTER `qq`",
			'wx_qrcode' => "ALTER TABLE `mxgapi_user` ADD COLUMN `wx_qrcode` varchar(500) NOT NULL DEFAULT '' AFTER `qq_qrcode`",
			'ali_qrcode' => "ALTER TABLE `mxgapi_user` ADD COLUMN `ali_qrcode` varchar(500) NOT NULL DEFAULT '' AFTER `wx_qrcode`"
		);
		foreach($alterColumns as $col => $alterSql){
			$exists = $db->query("SHOW COLUMNS FROM `mxgapi_user` LIKE '".$col."'");
			if(!$exists || $exists->num_rows === 0){
				@$db->query($alterSql);
			}
		}

		// 获取用户表实际存在的字段，避免 ALTER 未执行时出错
		$tableColumns = array();
		$colRes = $db->query("SHOW COLUMNS FROM `mxgapi_user`");
		if($colRes){
			while($col = $colRes->fetch_assoc()){
				$tableColumns[] = $col['Field'];
			}
		}

		$fields = array();
		$fields[] = "`email`='".$db->real_escape_string($email)."'";
		if($qq !== '' && in_array('qq', $tableColumns)){
			$fields[] = "`qq`='".$db->real_escape_string($qq)."'";
		}
		if($wx_qrcode !== '' && in_array('wx_qrcode', $tableColumns)){
			$fields[] = "`wx_qrcode`='".$db->real_escape_string($wx_qrcode)."'";
		}
		if($ali_qrcode !== '' && in_array('ali_qrcode', $tableColumns)){
			$fields[] = "`ali_qrcode`='".$db->real_escape_string($ali_qrcode)."'";
		}
		if($qq_qrcode !== '' && in_array('qq_qrcode', $tableColumns)){
			$fields[] = "`qq_qrcode`='".$db->real_escape_string($qq_qrcode)."'";
		}

		$sql = "UPDATE `mxgapi_user` SET ".implode(',', $fields)." WHERE `id`='{$user_id}'";
		$result = $db->query($sql);
		if(!$result){
			jsonError(-1, '保存失败');
		}

		$oldPassword = isset($_POST['old_password']) ? $_POST['old_password'] : '';
		$newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
		$confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

		if(!empty($oldPassword) && !empty($newPassword)){
			$hash = hash('sha256', $oldPassword);
			if($hash !== $user['password']){
				jsonError(-1, '原密码不正确');
			}
			if(strlen($newPassword) < 6){
				jsonError(-1, '新密码长度至少为 6 位');
			}
			if($newPassword !== $confirmPassword){
				jsonError(-1, '两次输入的新密码不一致');
			}
			$newHash = hash('sha256', $newPassword);
			$db->query("UPDATE `mxgapi_user` SET `password`='".$db->real_escape_string($newHash)."' WHERE `id`='{$user_id}'");
		}

		$updated = $db->query("SELECT * FROM `mxgapi_user` WHERE `id`='{$user_id}'")->fetch_assoc();
		json(0, '保存成功', getUserData($updated));
		break;

	/* 获取所有用户扣费记录(后台) */
	case 'getAllChargeLogs' :
		if(!isAdmin()){
			jsonError(-1, '无权限');
		}
		$page = intval($_REQUEST['page'] ?? 1);
		$pageSize = intval($_REQUEST['pageSize'] ?? 50);
		$offset = ($page - 1) * $pageSize;

		$countSql = "SELECT COUNT(*) as cnt FROM `mxgapi_charge_log`";
		$countRes = $db->query($countSql);
		$total = $countRes ? intval($countRes->fetch_assoc()['cnt']) : 0;

		$sql = "SELECT c.*, u.username FROM `mxgapi_charge_log` c LEFT JOIN `mxgapi_user` u ON c.user_id=u.id ORDER BY c.id DESC LIMIT {$offset},{$pageSize}";
		$result = $db->query($sql);
		$logs = [];
		if($result){
			while($row = $result->fetch_assoc()){
				$row['time'] = date('Y-m-d H:i:s', $row['time']);
				$row['charge_type_name'] = $row['charge_type'] == 1 ? '按次扣费' : '会员免费';
				$logs[] = $row;
			}
		}

		// 统计总扣费
		$totalAmount = 0;
		$statSql = "SELECT SUM(`amount`) as total FROM `mxgapi_charge_log` WHERE `charge_type`=1";
		$statRes = $db->query($statSql);
		if($statRes){
			$tmp = $statRes->fetch_assoc();
			$totalAmount = floatval($tmp['total'] ?? 0);
		}

		json(0, '获取成功', [
			'list' => $logs,
			'total' => $total,
			'page' => $page,
			'pageSize' => $pageSize,
			'totalPages' => ceil($total / $pageSize),
			'total_amount' => number_format($totalAmount, 4)
		]);
		break;
}

/**
 * 处理订单支付完成逻辑
 */
function processOrderPaid($order_id, $user_id, $planData, $user){
	global $db;
	$time = time();
	
	// 扣除余额
	$db->query("UPDATE `mxgapi_user` SET `balance`=`balance`-'{$planData['price']}' WHERE `id`='{$user_id}'");
	
	// 计算会员到期时间
	$duration = intval($planData['duration']);
	if($duration > 0){
		$newExpire = $time + ($duration * 86400);
		// 如果用户当前会员未过期,在现有基础上累加
		if($user['level'] > 0 && $user['expire_time'] > $time && $user['level'] != 2){
			$newExpire = $user['expire_time'] + ($duration * 86400);
		}
	}else{
		$newExpire = 0;
	}
	
	// 更新用户会员信息
	$db->query("UPDATE `mxgapi_user` SET `level`='{$planData['level']}',`expire_time`='{$newExpire}' WHERE `id`='{$user_id}'");
	
	// 更新订单状态
	$db->query("UPDATE `mxgapi_order` SET `status`='1',`pay_time`='{$time}' WHERE `id`='{$order_id}'");
}

/**
 * 获取用户展示数据
 */
function getUserData($user){
	$data = [
		'id' => $user['id'],
		'username' => $user['username'],
		'email' => $user['email'],
		'avatar' => isset($user['avatar']) ? $user['avatar'] : '',
		'qq' => isset($user['qq']) ? $user['qq'] : '',
		'qq_qrcode' => isset($user['qq_qrcode']) ? $user['qq_qrcode'] : '',
		'wx_qrcode' => isset($user['wx_qrcode']) ? $user['wx_qrcode'] : '',
		'ali_qrcode' => isset($user['ali_qrcode']) ? $user['ali_qrcode'] : '',
		'balance' => $user['balance'],
		'level' => $user['level'],
		'level_name' => getLevelName($user['level']),
		'expire_time' => $user['expire_time'],
		'expire_time_text' => $user['expire_time'] ? date('Y-m-d H:i:s', $user['expire_time']) : '永久',
		'is_vip' => $user['level'] > 0 ? ($user['expire_time'] == 0 || $user['expire_time'] > time()) : false,
		'reg_time' => date('Y-m-d H:i:s', $user['reg_time'])
	];
	return $data;
}

/**
 * 获取会员等级名称 (兼容函数,实际定义在 apikey_functions.php)
 */
if(!function_exists('getLevelName')){
    function getLevelName($level){
        $names = [0 => '普通用户', 1 => '月会员', 2 => '永久会员', 3 => '年会员'];
        return $names[$level] ?? '未知';
    }
}

/**
 * 获取订单状态名称
 */
function getOrderStatusName($status){
	$names = [0 => '待支付', 1 => '已支付', 2 => '已退款', 3 => '已取消'];
	return $names[$status] ?? '未知';
}

/**
 * 获取支付方式名称
 */
function getPayTypeName($type){
	$names = ['balance' => '余额支付', 'alipay' => '支付宝', 'wechat' => '微信支付', 'admin' => '管理员'];
	return $names[$type] ?? $type;
}

/**
 * 格式化套餐数据
 */
function formatPlan($plan){
	$plan['features'] = json_decode($plan['features'], true) ?: [];
	return $plan;
}

/* 以下函数已迁移至 Core/apikey_functions.php */
