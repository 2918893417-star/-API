<?php
// 错误处理：将PHP错误写入日志便于排查
error_reporting(E_ALL);
ini_set('display_errors', 0);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL) {
        $logFile = __DIR__ . '/error_log.txt';
        $msg = date('Y-m-d H:i:s') . ' | ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'] . "\n";
        @file_put_contents($logFile, $msg, FILE_APPEND);
    }
});

require 'init.php';
$type = $_REQUEST['type'];
/* 用switch判断类型 */
switch($type){

	/* 获取全部API数据 */
	case 'getAllApi' :
		$sql = 'SELECT * FROM `mxgapi_api` order by 1 desc';
		$result = $db->query($sql);
		if($result){
			$result = $result->fetch_all(MYSQLI_ASSOC);
			if(!$result){
				jsonError(-1, '暂无接口');
			}
			foreach($result as $v){
				$arr[] = array(
					'id' => $v['id'],
					'name' => $v['name'],
					'enname' => $v['enname'],
					'desc' => $v['desc'],
					'time' => date('Y-m-d h:i:s', $v['time']),
					'access' => $v['access'],
					'status' => $v['status'],
					'charge_type' => intval($v['charge_type'] ?? 0),
					'price' => floatval($v['price'] ?? 0)
				);
			}
			json(0, '获取成功', $arr);
		}else{
			jsonError(-1, '获取数据失败');
		}
		break;
		
	/* 获取单一API数据 */
	case 'getOneApi' :
		$id = intval($_REQUEST['id']);
		if(!$id){
			jsonError(-1, '缺少参数');
		}
		$sql = 'SELECT * FROM `mxgapi_api` WHERE `id`='.$id;
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_assoc();
			if(!$data){
				jsonError(-1, '暂无接口');
			}
			json(0, '获取成功', $data);
		}else{
			jsonError(-1, '获取数据失败');
		}
		break;
		
	/* 搜索API，返回数据 */
	case 'searchApi' :
		$s = addslashes(sprintf("%s", $_REQUEST['s']));
		if(!$s){
			jsonError(-1, '输入搜索内容');
		}
		$sql = "SELECT * FROM `mxgapi_api` WHERE `status`='1' && `name` LIKE '%" . $s . "%' order by 1 desc";
		$result = $db->query($sql);
		if($result){
			$result = $result->fetch_all(MYSQLI_ASSOC);
			if(!$result){
				jsonError(-1, '没有搜到你想要的接口');
			}
			foreach($result as $v){
				$arr[] = array(
					'id' => $v['id'],
					'name' => $v['name'],
					'enname' => $v['enname'],
					'desc' => $v['desc'],
					'access' => $v['access'],
					'status' => $v['status'],
					'charge_type' => intval($v['charge_type'] ?? 0),
					'price' => floatval($v['price'] ?? 0)
				);
			}
			json(0, '获取成功', $arr);
		}else{
			jsonError(-1, '获取数据失败');
		}
		break;
		
	/* 获取全部友情链接数据 */
	case 'getAllLink' :
		$mod = $_GET['mod'];
		$sql = 'SELECT * FROM `mxgapi_friendlinks`';
		$result = $db->query($sql);
		if($result){
			$arr = $result->fetch_all(MYSQLI_ASSOC);
			if(!$arr){
				jsonError(-1, '暂无友情链接');
			}
			if($mod == 'rand'){
				shuffle($arr);
			}
			json(0, '获取成功', $arr);
		}else{
			jsonError(-1, '获取失败');
		}
		break;
		
	/* 获取单一友链数据 */
	case 'getOneLink' :
		$id = intval($_REQUEST['id']);
		if(!$id){
			jsonError(-1, '缺少参数');
		}
		$sql = 'SELECT * FROM `mxgapi_friendlinks` WHERE `id`='.$id;
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_assoc();
			if(!$data){
				jsonError(-1, '暂无友链');
			}
			json(0, '获取成功', $data);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 获取全部友链申请数据（管理员） */
	case 'getAllApply' :
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
			break;
		}
		// 自愈：确保申请表存在
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
		$sql = 'SELECT * FROM `mxgapi_friendlink_apply` order by `id` desc';
		$result = $db->query($sql);
		if($result){
			$arr = $result->fetch_all(MYSQLI_ASSOC);
			if(!$arr){
				$arr = [];
			}
			json(0, '获取成功', $arr);
		}else{
			jsonError(-1, '获取失败');
		}
		break;

	/* 获取待审核友链申请数量（用于后台角标显示，含前台调用） */
	case 'getApplyCount' :
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
		$row = $db->query("SELECT count(1) AS cnt FROM `mxgapi_friendlink_apply` WHERE `status`=0");
		$cnt = 0;
		if($row){
			$r = $row->fetch_assoc();
			$cnt = intval($r['cnt'] ?? 0);
		}
		json(0, '获取成功', ['count' => $cnt]);
		break;
		
	/* 获取全部公告数据 */
	case 'getAllPost' :
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$sql = 'SELECT * FROM `mxgapi_post` order by 1 desc';
		$result = $db->query($sql);
		if($result){
			$arr = $result->fetch_all(MYSQLI_ASSOC);
			if(!$arr){
				jsonError(-1, '暂无公告');
			}
			json(0, '获取成功', $arr);
		}else{
			jsonError(-1, '获取失败');
		}
		break;
		
	/* 获取单一公告数据 */
	case 'getOnePost' :
		$id = intval($_REQUEST['id']);
		if(!$id){
			jsonError(-1, '缺少参数');
		}
		$sql = 'SELECT * FROM `mxgapi_post` WHERE `id`='.$id;
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_assoc();
			if(!$data){
				jsonError(-1, '暂无公告');
			}
			json(0, '获取成功', $data);
		}else{
			jsonError(-1, '获取失败');
		}
		break;
		
	/* 获取全部接口反馈数据 */
	case 'getAllFeedback' :
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$sql = 'SELECT * FROM `mxgapi_feedback` order by 1 desc';
		$result = $db->query($sql);
		if($result){
			$arr = $result->fetch_all(MYSQLI_ASSOC);
			if(!$arr){
				jsonError(-1, '暂无反馈信息');
			}
			json(0, '获取成功', $arr);
		}else{
			jsonError(-1, '获取失败');
		}
		break;
		
	/* 获取单一反馈数据 */
	case 'getOneFeedback' :
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$id = intval($_REQUEST['id']);
		if(!$id){
			jsonError(-1, '缺少参数');
		}
		$sql = 'SELECT * FROM `mxgapi_feedback` WHERE `id`='.$id;
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_assoc();
			if(!$data){
				jsonError(-1, '暂无该反馈信息');
			}
			json(0, '获取成功', $data);
		}else{
			jsonError(-1, '获取数据失败');
		}
		break;
		
	/* 获取后台首页信息（需要登录） */
	case 'getAdminInfo' :
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		
		$sql = array(
			'api' => 'SELECT count(1) FROM `mxgapi_api`',
			'access' => 'SELECT count(1) FROM `mxgapi_access`',
			'spider' => 'SELECT count(1) FROM `mxgapi_spider`',
			'link' => 'SELECT count(1) FROM `mxgapi_friendlinks`',
			'post' => 'SELECT count(1) FROM `mxgapi_post`',
			'feedback' => 'SELECT count(1) FROM `mxgapi_feedback`',
		);

		// 会员系统统计（先检查表是否存在，避免表未迁移时致命错误）
		$userTotal = 0; $vipCount = 0; $normalCount = 0;
		$userTable = $db->query("SHOW TABLES LIKE 'mxgapi_user'");
		if($userTable && $userTable->num_rows > 0){
			$r = $db->query("SELECT count(1) FROM `mxgapi_user`");
			if($r){ $userTotal = intval($r->fetch_array()[0]); }
			$r = $db->query("SELECT count(1) FROM `mxgapi_user` WHERE `level` > 0 AND `status` = 1");
			if($r){ $vipCount = intval($r->fetch_array()[0]); }
			$r = $db->query("SELECT count(1) FROM `mxgapi_user` WHERE `level` = 0 AND `status` = 1");
			if($r){ $normalCount = intval($r->fetch_array()[0]); }
		}
		
		// 本月订单统计
		$orderMonth = 0; $totalIncome = '0.00';
		$orderTable = $db->query("SHOW TABLES LIKE 'mxgapi_order'");
		if($orderTable && $orderTable->num_rows > 0){
			$monthStart = date('Y-m-01 00:00:00');
			$r = $db->query("SELECT count(1) FROM `mxgapi_order` WHERE `create_time` >= '".strtotime($monthStart)."'");
			if($r){ $orderMonth = intval($r->fetch_array()[0]); }
			$r = $db->query("SELECT SUM(amount) FROM `mxgapi_order` WHERE `status` = 1");
			if($r){
				$income = $r->fetch_array()[0];
				$totalIncome = $income ? number_format($income, 2) : '0.00';
			}
		}
		
		// API Key 统计
		$apikeyCount = 0;
		$apikeyTable = $db->query("SHOW TABLES LIKE 'mxgapi_apikey'");
		if($apikeyTable && $apikeyTable->num_rows > 0){
			$r = $db->query("SELECT count(1) FROM `mxgapi_apikey` WHERE `status` = 1");
			if($r){ $apikeyCount = intval($r->fetch_array()[0]); }
		}

		$timestamp = array(
			strtotime('today')-4*86400,	
			strtotime('today')-3*86400,
			strtotime('today')-2*86400,
			strtotime('today')-86400,
			strtotime('today'),
			strtotime('today')+86400
		);
		// 统计访问（容错：表不存在时返回0）
		$accessTable = $db->query("SHOW TABLES LIKE 'mxgapi_access'");
		for ($i=0;$i<5;$i++) {
			if($accessTable && $accessTable->num_rows > 0){
				$access_sql = "SELECT count(1) FROM `mxgapi_access` WHERE `time` between '{$timestamp[$i]}' and '{$timestamp[($i+1)]}';";
				$access_result = $db->query($access_sql);
				$access_data[] = $access_result ? intval($access_result->fetch_array()[0]) : 0;
			}else{
				$access_data[] = 0;
			}
		}
		for ($i=0;$i<5; $i++) { 
			$access_time[] = date('d', $timestamp[$i]);
		}
		$access = array(
			'access_data' => $access_data,
			'access_time' => $access_time
		);

		// 统计蜘蛛（容错：表不存在时返回0）
		$spiderTable = $db->query("SHOW TABLES LIKE 'mxgapi_spider'");
		for ($i=0;$i<5;$i++) {
			if($spiderTable && $spiderTable->num_rows > 0){
				$spider_sql = "SELECT count(1) FROM `mxgapi_spider` WHERE `time` between '{$timestamp[$i]}' and '{$timestamp[($i+1)]}';";
				$spider_result = $db->query($spider_sql);
				$spider_data[] = $spider_result ? intval($spider_result->fetch_array()[0]) : 0;
			}else{
				$spider_data[] = 0;
			}
		}
		for ($i=0;$i<5; $i++) { 
			$spider_time[] = date('d', $timestamp[$i]);
		}
		$spider = array(
			'spider_data' => $spider_data,
			'spider_time' => $spider_time
		);

		foreach($sql as $key => $val){
			$result = $db->query($val);
			$data[$key] = $result ? intval($result->fetch_array()[0]) : 0;
		}
		
		// 合并会员系统统计
		$memberStats = array(
			'user_total' => $userTotal,
			'vip_count' => $vipCount,
			'normal_count' => $normalCount,
			'order_month' => $orderMonth,
			'total_income' => $totalIncome,
			'apikey_count' => $apikeyCount
		);
		
		json(0, '获取成功！', array_merge($data, $access, $spider, $memberStats));
		break;

	/* 获取前台公开统计数据（无需登录） */
	case 'getSiteStats' :
		// 1. 接口总数（含所有状态）
		$apiCount = 0;
		$r = $db->query("SELECT COUNT(*) AS cnt FROM `mxgapi_api`");
		if($r){ $apiCount = intval($r->fetch_assoc()['cnt']); }

		// 2. 用户总数
		$userCount = 0;
		$userTable = $db->query("SHOW TABLES LIKE 'mxgapi_user'");
		if($userTable && $userTable->num_rows > 0){
			$r = $db->query("SELECT COUNT(*) AS cnt FROM `mxgapi_user` WHERE `status` = 1");
			if($r){ $userCount = intval($r->fetch_assoc()['cnt']); }
		}

		// 3. 累计调用次数
		$accessCount = 0;
		$accessTable = $db->query("SHOW TABLES LIKE 'mxgapi_access'");
		if($accessTable && $accessTable->num_rows > 0){
			$r = $db->query("SELECT COUNT(*) AS cnt FROM `mxgapi_access`");
			if($r){ $accessCount = intval($r->fetch_assoc()['cnt']); }
		}

		// 4. 在线时长（配置表中的站点开启时间计算，若不存在则显示 '--'）
		$onlineHours = '--';
		$configTable = $db->query("SHOW TABLES LIKE 'mxgapi_config'");
		if($configTable && $configTable->num_rows > 0){
			$r = $db->query("SELECT `set_time` FROM `mxgapi_config` LIMIT 1");
			if($r && $row = $r->fetch_assoc()){
				if(!empty($row['set_time']) && intval($row['set_time']) > 0){
					$diff = time() - intval($row['set_time']);
					if($diff > 0){
						$days = floor($diff / 86400);
						$onlineHours = $days > 365 ? round($days / 365, 1) . '年+' : ($days > 0 ? $days . '天+' : '小时+');
					}
				}
			}
		}

		$data = array(
			'api_count' => $apiCount,
			'user_count' => $userCount,
			'access_count' => $accessCount,
			'online_hours' => $onlineHours
		);
		json(0, '获取成功', $data);
		break;
		
	/* 获取网站配置信息 */
	case 'getWebSetting' :
		$sql = 'SELECT title,subtitle,description,keywords,favicon,url,icp,copyright,theme,accent,post_id,set_time,close_site,cc_protect,fire_wall,end_script FROM `mxgapi_config`';
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_assoc();
			$post_id = $data['post_id'];
			$post['post'] = $db->query("SELECT * FROM `mxgapi_post` WHERE `id`='{$post_id}';")->fetch_assoc();
			json(0, '获取成功！', array_merge($data,$post));
		}else{
			jsonError(-1, '获取数据失败！');
		}
		break;
	
	/* 获取邮件配置信息 */
	case 'getSmtpConfig' :
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$sql = 'SELECT smtp_host,smtp_username,smtp_password,smtp_port,smtp_secure FROM `mxgapi_config`';
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_assoc();
			json(0, '获取成功！', $data);
		}else{
			jsonError(-1, '获取数据失败！');
		}
		break;
		
	/* 获取后台用户信息 */
	case 'getUserInfo' : 
		$sql = 'SELECT username,email,qq,qqqrcode,vxqrcode,aliqrcode FROM `mxgapi_config`';
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_assoc();
			$qqhead = [
				'qqhead' => 'https://q2.qlogo.cn/headimg_dl?dst_uin=' . $data['qq'] . '&spec=640',
				'href' => 'mqqapi://card/show_pslcard?src_type=internal&source=sharecard&version=1&uin=' . $data['qq']
			];
			json(0, '获取成功！', array_merge($data,$qqhead));
		}else{
			jsonError(-1, '获取数据失败！');
		}
		break;
		
	/* 获取访问信息 */
	case 'getAccessInfo':
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$num = intval($_REQUEST['num'] ?? '25');
		$result = $db->query("SELECT * FROM `mxgapi_access` order by 1 desc limit ".$num)->fetch_all(MYSQLI_ASSOC);
		if(!$result){
			jsonError(-1, '数据获取失败！');
		} 
		foreach($result as $val){
			$data[] = [
				'id' => $val['id'],
				'ip' => $val['ip'],
				'host' => $val['host'],
				'protocol' => $val['protocol'],
				'method' => $val['method'],
				'user_agent' => $val['user_agent'],
				'time' => date('Y-m-d h:i:s', $val['time'])
			];
		}
		json(0, '获取成功', $data);
		break;
	
	/* 获取IP地址具体位置 */
	case 'getIpAddress':
		require '../Include/Common.php';
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$ip = $_REQUEST['ip'];
		if(!$ip){
			jsonError(-1, '参数错误');
		}else{
			$data = curl('https://api.lisweb.cn/API/ip.php?ip='.$ip, 'GET', 0, 0);
			$data = $data ? json_decode($data, true) : null;
			if($data && isset($data['data']['city']) && $data['data']['city'] != ''){
				json(0, '获取成功', $data['data']['city']);
			}else{
				jsonError(-1, '获取失败');
			}
		}
		break;
		
	/* 退出登录 */
	case 'exitLogin':
		session_start();
		$_SESSION = array();
		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params['path'], $params['domain'],
				$params['secure'], $params['httponly']
			);
		}
		session_destroy();
		jsonError(0, '退出登录成功');
		break;
		
	/* 发送测试邮件 */
	case 'sendTestEmail':
		require '../Include/Common.php';
		if($_REQUEST['to']){
			die(sendMail($_REQUEST['to'], '一封测试邮件', '你收到了这封邮件，表示你的邮件服务器已设置成功。'));
		}else{
			jsonError(-1, '缺少参数！');
		}
		break;
		
	/* 接口调用排行榜 */
	case 'getApiAccessList':
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$sql = 'SELECT name,access FROM `mxgapi_api` order by access desc limit 5';
		$result = $db->query($sql);
		if($result){
			$data = $result->fetch_all(MYSQLI_ASSOC);
			json(0, '获取成功！', $data);
		}else{
			jsonError(-1, '获取数据失败！');
		}
		break;
	
	/* 获取全部登录日志数据 */
	case 'getAllLoginLog' :
		if(!isAdmin()){
			jsonError(-1, '未登录到后台');
		}
		$sql = 'SELECT * FROM `mxgapi_login_log` order by 1 desc';
		$result = $db->query($sql);
		if($result){
			$arr = $result->fetch_all(MYSQLI_ASSOC);
			if(!$result){
				jsonError(-1, '暂无登录信息');
			}
			foreach($arr as $val){
				$data[] = [
					'id' => $val['id'],
					'ip' => $val['ip'],
					'address' => $val['address'],
					'time' => date('Y-m-d h:i:s', $val['time'])
				];
			}
			json(0, '获取成功', $data);
		}else{
			jsonError(-1, '获取失败');
		}
		break;
}
 