<?php
error_reporting(E_ERROR);

// 检测是否授权
// 已关闭原作授权验证（auth.muxiuge.cn 已失效），否则任何域名/IP 都会被拦截到 not_auth.html
// if(!domainAuth($_SERVER['HTTP_HOST'])){
// 	include __TEMPLATE_DIR__.'/Home/not_auth.html';
// 	exit;
// }

// 获取行为GET参数
$action = $_GET['action'];

// 检测系统是否安装
if(!file_exists(__CORE_DIR__.'/install.lock') && $action != 'install'){
	// 未安装时直接跳转到安装页面
	header('Location: ?action=install');
	exit;
}

if ($action != 'admin' && $action != 'install') {
	addAccess(); // 调用统计访问
	is_spider(); // 调用统计蜘蛛
}

// 检测站点是否维护中
if($config['close_site'] == '1' && $action != 'admin'){
	include __TEMPLATE_DIR__.'/Home/close.html';
	exit;
}

// 检测站点是否开启防CC功能
if($config['cc_protect'] == '1' && $action != 'admin'){
	include __INCLUDE_DIR__.'/Firewall/CCProtect.php';
}

// 检测站点是否开启防SQL注入功能
if($config['fire_wall'] == '1' && $action != 'admin'){
	include __INCLUDE_DIR__.'/Firewall/DisSQL.php';
}



// 行为初始化
if($action == 'doc'){
	
	/* 加载文档页面 */
	include __DIR__.'/Doc.php';
	
}else if($action == 'apilist'){
	
	/* 加载API列表页面 */
	include __DIR__.'/ApiList.php';
	
}else if($action == 'svip'){
	
	/* 加载套餐会员页面 */
	include __DIR__.'/Svip.php';
	
}else if($action == 'login'){
	
	/* 加载登录页面 */
	include __DIR__.'/Login.php';
	
}else if($action == 'register'){
	
	/* 加载注册页面 */
	include __DIR__.'/Register.php';
	
}else if($action == 'user_center'){
	
	/* 加载用户中心 */
	include __DIR__.'/UserCenter.php';
	
}else if($action == 'friendlinks'){
	
	/* 加载友链页面 */
	include __DIR__.'/Friendlinks.php';
	
}else if($action == 'about'){
	
	/* 加载关于页面 */
	include __DIR__.'/About.php';

}else if($action == 'disclaimer'){

	/* 加载免责声明页面 */
	include __DIR__.'/Disclaimer.php';

}else if($action == 'install'){
	
	/* 加载安装页面 */
	include __DIR__.'/Install.php';
	
}else if($action == 'jump'){
	
	/* 加载跳转页面 */
	include __DIR__.'/Jump.php';
	
}else if($action == '404'){
	
	/* 加载404页面 */
	include __DIR__.'/404.php';
	
}else if($action == 'error'){
	
	/* 加载接口维护页面 */
	include __DIR__.'/Error.php';
	
}else if($action == 'admin'){
	
	/* 加载后台页面 */
	include __DIR__.'/Admin.php';
	
}else{

	/* 加载首页 */
	include __DIR__.'/Home.php';
	
}