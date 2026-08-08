<?php
// 载入用户中心页面
session_start();
if(empty($_SESSION['user_id'])){
	header('Location: ?action=login');
	exit;
}
require_once __TEMPLATE_DIR__.'/Home/user_center.html';
