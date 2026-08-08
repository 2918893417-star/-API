<?php
/* 极速登录接口 - 支持表单提交和AJAX调用 */
error_reporting(0);
ini_set('display_errors', 0);

// 检测是否为 AJAX 请求
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

// 数据库连接
require '../Core/Database/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -999, 'msg' => '数据库未连接，请先安装系统']);
        exit;
    } else {
        // 表单提交，显示错误页面
        echo '<script>alert("数据库未连接，请先安装系统");history.back();</script>';
        exit;
    }
}

session_start();

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

if (!$username || !$password) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'msg' => '请输入完整']);
        exit;
    } else {
        echo '<script>alert("请输入完整");history.back();</script>';
        exit;
    }
}

$result = $db->query("SELECT username, password FROM `mxgapi_config` LIMIT 1");
if (!$result) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'msg' => '系统配置缺失']);
        exit;
    } else {
        echo '<script>alert("系统配置缺失");history.back();</script>';
        exit;
    }
}

$config = $result->fetch_assoc();
if (!$config) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'msg' => '系统配置为空']);
        exit;
    } else {
        echo '<script>alert("系统配置为空");history.back();</script>';
        exit;
    }
}

// 验证密码
if ($username === $config['username'] && $password === $config['password']) {
    $_SESSION['login'] = 'admin';
    $_SESSION['admin_username'] = $username;
    
    // 确保 session 写入
    session_write_close();
    
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 0, 'msg' => '登录成功']);
    } else {
        // 表单提交 - 直接重定向到后台
        header('Location: ../?action=admin');
        exit;
    }
} else {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => -1, 'msg' => '用户名或密码错误']);
    } else {
        echo '<script>alert("用户名或密码错误");history.back();</script>';
        exit;
    }
}
