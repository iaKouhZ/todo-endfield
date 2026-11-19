<?php
declare(strict_types=1);

/**
 * 本地开发用的路由脚本：php -S 127.0.0.1:8000 router.php
 * ---------------------------------------------------------------------------
 * PHP 自带的开发服务器不读 .htaccess，所以本地要靠这个脚本复刻同一套规则：
 *   1. 挡住 data/ 等敏感路径（与 .htaccess 一致）；
 *   2. 把伪静态地址映射到入口文件。
 *
 * 线上用的是 .htaccess（Apache）或 deploy/nginx.conf（nginx），
 * 这里只是它们的等价物 —— 三处规则要一起改。
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

/* ------------------------------------------------------------------ 屏蔽 */
$blocked = [
    '#^/data(/|$)#',                                   // SQLite 库
    '#^/lib(/|$)#',                                    // 内部实现
    '#^/config\.php$#',                                // 口令与 cookie 密钥
    '#\.(sqlite|sqlite-wal|sqlite-shm|db|log|ini|bak|sql|json)$#i',
    '#(^|/)\.#',                                       // .git / .env / .htaccess
];
foreach ($blocked as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden\n";
        return true;
    }
}

/* ------------------------------------------------------------------ 映射
 * 规则 → [入口文件, [查询串键 => 捕获组序号（整数）或固定值（字符串）]] */
$routes = [
    '#^/$#'                                                     => ['index.php', []],
    '#^/(tracked|planned|done|all)/task/(\d+)/(\d{4}-\d{2}-\d{2})/?$#' => ['index.php', ['tab' => 1, 'task' => 2, 'date' => 3]],
    '#^/(tracked|planned|done|all)/task/(\d+)/?$#'              => ['index.php', ['tab' => 1, 'task' => 2]],
    '#^/(tracked|planned|done|all)/?$#'                         => ['index.php', ['tab' => 1]],
    '#^/admin/tasks/(\d+)/?$#'                                  => ['admin.php', ['view' => 'tasks', 'edit' => 1]],
    '#^/admin/(tasks|categories|system)/?$#'             => ['admin.php', ['view' => 1]],
    '#^/admin/?$#'                                              => ['admin.php', ['view' => 'tasks']],
    '#^/login/?$#'                                              => ['login.php', []],
    '#^/logout/?$#'                                             => ['logout.php', []],
    '#^/action/?$#'                                             => ['action.php', []],
];

foreach ($routes as $pattern => $route) {
    if (!preg_match($pattern, $path, $matches)) {
        continue;
    }
    [$script, $map] = $route;

    /* 路径里的参数优先于查询串：路径才是规范地址。 */
    $query = $_GET;
    foreach ($map as $name => $value) {
        $query[$name] = is_int($value) ? (string) ($matches[$value] ?? '') : $value;
    }
    $_GET = $query;
    $_REQUEST = array_merge($_REQUEST, $query);

    /* 入口文件按 SCRIPT_NAME 算应用前缀，这里要指到真实入口。 */
    $_SERVER['SCRIPT_NAME'] = '/' . $script;
    require __DIR__ . '/' . $script;
    return true;
}

/* 真实文件（CSS / JS）交给开发服务器自己发。 */
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 Not Found\n";
return true;
