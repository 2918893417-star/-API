<?php
/* 初始化 - 轻量版，不加载Common.php以提升性能 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

/* 连接数据库 */
require '../Core/Database/connect.php';

// 检查数据库连接是否正常
if (!isset($db) || !($db instanceof mysqli)) {
    die(json_encode(array(
        'code' => -999,
        'msg' => '数据库连接未配置，请先运行安装程序：?action=install'
    )));
}

/* 设置时间地区 */
date_default_timezone_set("PRC");

/* 开始一个SESSION会话 */
session_start();

// 定义常用的JSON辅助函数，避免加载整个Common.php
if (!function_exists('jsonError')) {
    function jsonError($code, $msg) {
        die(json_encode(array('code' => $code, 'msg' => $msg), 320));
    }
}
if (!function_exists('json')) {
    function json($code, $msg, $data) {
        die(json_encode(array('code' => $code, 'msg' => $msg, 'data' => $data), 320));
    }
}
if (!function_exists('isAdmin')) {
    function isAdmin() {
        session_start();
        if (isset($_SESSION['login']) && $_SESSION['login'] == 'admin') {
            return true;
        }
        return false;
    }
}