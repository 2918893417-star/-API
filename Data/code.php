<?php
/* 初始化 */
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require 'init.php';

/* 统一鉴权:仅管理员可访问代码管理接口 */
if (!isAdmin()) {
    jsonError(-1, '用户未登录或无权限');
}

/* API 接口代码所在目录(绝对路径) */
$API_DIR = __DIR__ . '/../API';

/* 受保护文件名单,禁止编辑/删除 */
$PROTECTED_FILES = ['function.php'];

/* 计费中间件引入代码 */
$BILLING_CODE = "require_once __DIR__ . '/../Core/api_key_auth.php';";

$req = $_REQUEST;
$type = $req['type'] ?? '';

switch ($type) {

    /* 列出 API 目录下所有 PHP 文件 */
    case 'list':
        $files = [];
        if (is_dir($API_DIR)) {
            $items = scandir($API_DIR);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $fullPath = $API_DIR . '/' . $item;
                if (!is_file($fullPath)) continue;
                /* 仅列出 .php 文件 */
                if (strtolower(pathinfo($item, PATHINFO_EXTENSION)) !== 'php') continue;
                $content = file_get_contents($fullPath);
                $hasBilling = strpos($content, 'api_key_auth.php') !== false ? true : false;
                $files[] = [
                    'name'         => $item,
                    'size'         => filesize($fullPath),
                    'size_text'    => formatSize(filesize($fullPath)),
                    'mtime'        => filemtime($fullPath),
                    'mtime_text'   => date('Y-m-d H:i:s', filemtime($fullPath)),
                    'has_billing'  => $hasBilling,
                    'protected'    => in_array($item, $PROTECTED_FILES),
                    'lines'        => substr_count($content, "\n") + 1,
                ];
            }
            /* 按修改时间倒序 */
            usort($files, function($a, $b) {
                return $b['mtime'] - $a['mtime'];
            });
        }
        json(0, '获取成功', $files);
        break;

    /* 读取单个文件内容 */
    case 'read':
        $file = basename($req['file'] ?? '');
        if (!$file) {
            jsonError(-1, '缺少文件名');
        }
        $fullPath = $API_DIR . '/' . $file;
        if (!is_file($fullPath)) {
            jsonError(-1, '文件不存在');
        }
        $content = file_get_contents($fullPath);
        if ($content === false) {
            jsonError(-1, '读取失败');
        }
        json(0, '获取成功', [
            'name'        => $file,
            'content'     => $content,
            'size_text'   => formatSize(filesize($fullPath)),
            'mtime_text'  => date('Y-m-d H:i:s', filemtime($fullPath)),
            'has_billing' => strpos($content, 'api_key_auth.php') !== false,
            'protected'   => in_array($file, $PROTECTED_FILES),
            'lines'       => substr_count($content, "\n") + 1,
            'test_url'    => './API/' . $file,
        ]);
        break;

    /* 保存文件内容 */
    case 'save':
        $file = basename($req['file'] ?? '');
        $content = $req['content'] ?? '';
        if (!$file) {
            jsonError(-1, '缺少文件名');
        }
        if (in_array($file, $PROTECTED_FILES)) {
            jsonError(-1, '该文件受保护,禁止修改');
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
            jsonError(-1, '仅允许编辑 PHP 文件');
        }
        $fullPath = $API_DIR . '/' . $file;
        if (!is_file($fullPath)) {
            jsonError(-1, '文件不存在');
        }
        /* 简单校验:必须以 <?php 开头 */
        if (trim($content) !== '' && strpos(ltrim($content), '<?php') !== 0) {
            jsonError(-1, 'PHP 文件必须以 <?php 开头');
        }
        /* 备份原文件 */
        $bakPath = $fullPath . '.bak';
        if (is_file($fullPath)) {
            @copy($fullPath, $bakPath);
        }
        $result = file_put_contents($fullPath, $content);
        if ($result === false) {
            jsonError(-1, '保存失败,请检查目录权限');
        }
        /* 删除备份(保存成功) */
        if (is_file($bakPath)) {
            @unlink($bakPath);
        }
        json(0, '保存成功', [
            'name'        => $file,
            'size_text'   => formatSize(filesize($fullPath)),
            'mtime_text'  => date('Y-m-d H:i:s', filemtime($fullPath)),
            'has_billing' => strpos($content, 'api_key_auth.php') !== false,
            'lines'       => substr_count($content, "\n") + 1,
        ]);
        break;

    /* 删除文件 */
    case 'delete':
        $file = basename($req['file'] ?? '');
        if (!$file) {
            jsonError(-1, '缺少文件名');
        }
        if (in_array($file, $PROTECTED_FILES)) {
            jsonError(-1, '该文件受保护,禁止删除');
        }
        $fullPath = $API_DIR . '/' . $file;
        if (!is_file($fullPath)) {
            jsonError(-1, '文件不存在');
        }
        if (!@unlink($fullPath)) {
            jsonError(-1, '删除失败,请检查目录权限');
        }
        jsonError(0, '删除成功');
        break;

    /* 新建接口文件 */
    case 'create':
        $file = basename($req['file'] ?? '');
        $addBilling = intval($req['add_billing'] ?? 1);
        if (!$file) {
            jsonError(-1, '请输入文件名');
        }
        /* 规范化文件名:仅允许字母数字下划线 */
        $nameOnly = pathinfo($file, PATHINFO_FILENAME);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $nameOnly)) {
            jsonError(-1, '文件名只能包含字母、数字、下划线,且以字母开头');
        }
        /* 自动补 .php 后缀 */
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
            $file = $nameOnly . '.php';
        }
        $fullPath = $API_DIR . '/' . $file;
        if (is_file($fullPath)) {
            jsonError(-1, '文件已存在');
        }
        /* 生成模板代码 */
        $template = "<?php\n";
        $template .= "/**\n";
        $template .= " * " . $nameOnly . " 接口\n";
        $template .= " * 创建时间: " . date('Y-m-d H:i:s') . "\n";
        $template .= " */\n\n";
        if ($addBilling) {
            $template .= $BILLING_CODE . "\n\n";
        }
        $template .= "header('Content-Type: application/json; charset=utf-8');\n\n";
        $template .= "// 在此处编写接口业务逻辑\n";
        $template .= "echo json_encode([\n";
        $template .= "    'code' => 0,\n";
        $template .= "    'msg'  => 'success',\n";
        $template .= "    'data' => [\n";
        $template .= "        'message' => 'Hello from " . $nameOnly . " API'\n";
        $template .= "    ]\n";
        $template .= "], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);\n";

        $result = file_put_contents($fullPath, $template);
        if ($result === false) {
            jsonError(-1, '创建失败,请检查目录权限');
        }
        json(0, '创建成功', ['name' => $file]);
        break;

    /* 一键添加计费代码 */
    case 'add_billing':
        $file = basename($req['file'] ?? '');
        if (!$file) {
            jsonError(-1, '缺少文件名');
        }
        if (in_array($file, $PROTECTED_FILES)) {
            jsonError(-1, '该文件受保护,禁止修改');
        }
        $fullPath = $API_DIR . '/' . $file;
        if (!is_file($fullPath)) {
            jsonError(-1, '文件不存在');
        }
        $content = file_get_contents($fullPath);
        if ($content === false) {
            jsonError(-1, '读取失败');
        }
        /* 已存在计费代码 */
        if (strpos($content, 'api_key_auth.php') !== false) {
            jsonError(-1, '该接口已包含计费代码,无需重复添加');
        }
        /* 在 <?php 标签之后插入计费代码 */
        /* 兼容 <?php 和 <?php\n 两种形式 */
        $phpTagPos = strpos($content, '<?php');
        if ($phpTagPos === false) {
            jsonError(-1, '文件格式异常,未找到 <?php 标签');
        }
        /* 找到 <?php 结束位置 */
        $insertPos = $phpTagPos + 5;
        /* 跳过紧随其后的换行和空白 */
        while (isset($content[$insertPos]) && in_array($content[$insertPos], ["\n", "\r", " ", "\t"])) {
            $insertPos++;
        }
        /* 构造插入内容:计费代码 + 注释 + 空行 */
        $insertCode = "/**\n * 计费与权限验证(由代码管理器自动注入)\n */\n";
        $insertCode .= $BILLING_CODE . "\n\n";
        $newContent = substr($content, 0, $insertPos) . $insertCode . substr($content, $insertPos);

        /* 备份 */
        $bakPath = $fullPath . '.bak';
        @copy($fullPath, $bakPath);

        $result = file_put_contents($fullPath, $newContent);
        if ($result === false) {
            jsonError(-1, '写入失败,请检查目录权限');
        }
        @unlink($bakPath);
        json(0, '计费代码已添加', [
            'name'        => $file,
            'has_billing' => true,
        ]);
        break;

    /* 测试运行接口(返回接口实际输出) */
    case 'test':
        $file = basename($req['file'] ?? '');
        if (!$file) {
            jsonError(-1, '缺少文件名');
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
            jsonError(-1, '仅允许测试 PHP 文件');
        }
        $fullPath = $API_DIR . '/' . $file;
        if (!is_file($fullPath)) {
            jsonError(-1, '文件不存在');
        }
        json(0, '测试地址已生成', ['url' => './API/' . $file]);
        break;

    default:
        jsonError(-1, '未知操作类型');
        break;
}

/* 格式化文件大小 */
function formatSize($bytes)
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 2) . ' KB';
    return round($bytes / (1024 * 1024), 2) . ' MB';
}
