<?php
/**
 * PHP Ping API 接口
 * 
 * 功能：接收一个域名或IP，执行Ping操作，并返回格式化的文本结果。
 * 请求方式：GET
 * 请求示例：/ping.php?url=example.com
 * 返回格式：TEXT
 */

// 设置响应头为纯文本，并指定 UTF-8 编码以正确显示中文
header('Content-Type: text/plain; charset=utf-8');

// 1. 获取并验证 'url' 参数
$target = isset($_GET['url']) ? trim($_GET['url']) : '';

if (empty($target)) {
    http_response_code(400); // Bad Request
    echo "错误：请提供一个有效的 URL 或 IP 地址作为 'url' 参数。\n示例：?url=example.com";
    exit;
}

// 简单的安全检查，防止命令注入
// 只允许字母、数字、点、连字符和冒号 (用于IPv6)
if (!preg_match('/^[a-zA-Z0-9\.\-:]+$/', $target)) {
    http_response_code(400);
    echo "错误：提供的 URL 或 IP 地址包含非法字符。";
    exit;
}

// 2. 执行 Ping 命令
// 使用 -c 4 限制 ping 次数为4次，-W 2 设置超时时间为2秒
// escapeshellarg() 进一步确保参数安全
$command = 'ping -c 4 -W 2 ' . escapeshellarg($target);
$output = shell_exec($command);

// 检查命令是否成功执行
if ($output === null) {
    http_response_code(500); // Internal Server Error
    echo "错误：无法执行 ping 命令。请检查服务器环境。";
    exit;
}

// 3. 解析 Ping 结果
// 提取 IP 地址 (从 PING ... (x.x.x.x) 格式中)
$ip = 'N/A';
if (preg_match('/PING [^\(]+\(([0-9\.]+)\)/', $output, $matches)) {
    $ip = $matches[1];
}

// 提取延迟统计信息 (min/avg/max/mdev)
// 典型的输出行: rtt min/avg/max/mdev = 31.990/32.450/32.797/0.300 ms
$max_latency = 'N/A';
$min_latency = 'N/A';
if (preg_match('/rtt min\/avg\/max\/mdev = ([0-9\.]+)\/([0-9\.]+)\/([0-9\.]+)\/([0-9\.]+) ms/', $output, $matches)) {
    $min_latency = $matches[1] . 'ms';
    $max_latency = $matches[3] . 'ms';
}

// 4. 获取服务器归属和运营部信息 (模拟)
// 注意：真实的地理位置和运营商信息需要通过 IP 查询数据库（如 MaxMind GeoIP）或第三方 API 获取。
// 这里我们进行模拟，以匹配您提供的示例格式。
$server_location = "中国-北京-北京 京东云"; // 模拟数据
$server_operator = "中国-香港 UCloud";   // 模拟数据

// 如果您想尝试获取真实IP的地理位置，可以使用免费的API（不稳定且有限制）
// $geo_data = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,isp");
// if ($geo_data) {
//     $data = json_decode($geo_data, true);
//     if ($data && $data['status'] == 'success') {
//         $server_location = "{$data['country']}-{$data['regionName']}-{$data['city']}";
//         $server_operator = $data['isp'];
//     }
// }


// 5. 格式化并输出最终结果
$response = "";
$response .= "网站域名: {$target}\n";
$response .= "网站IP: {$ip}\n";
$response .= "最大延迟: {$max_latency}\n";
$response .= "最小延迟: {$min_latency}\n";
$response .= "服务器归属: {$server_location}\n";
$response .= "服务器运营部: {$server_operator}";

echo $response;

?>