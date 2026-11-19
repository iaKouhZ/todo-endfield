<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(dirname(__DIR__));

function check_command(array $arguments): void
{
    passthru(implode(' ', array_map('escapeshellarg', $arguments)), $status);
    if ($status !== 0) {
        exit($status);
    }
}

function check_condition(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "$message\n");
        exit(1);
    }
}

foreach (['*.php', 'lib/*.php', 'tests/*.php', 'scripts/*.php'] as $pattern) {
    foreach (glob($pattern) as $path) {
        check_command([PHP_BINARY, '-l', $path]);
        if (!str_starts_with($path, 'tests/') && !str_starts_with($path, 'scripts/')) {
            check_condition(!preg_match('/\sstyle\s*=/i', file_get_contents($path)), "$path 含内联 style 属性");
        }
    }
}
foreach (['app', 'admin'] as $name) {
    check_command(['node', '--check', "assets/$name.js"]);
}
foreach (['tokens', 'components'] as $name) {
    check_condition(hash_file('sha256', "assets/eds/$name.css") === hash_file('sha256', "design/$name.css"),
        "两份 EDS $name.css 不一致");
}

$apache = file_get_contents('.htaccess');
$nginx = file_get_contents('deploy/nginx.conf');
$router = file_get_contents('router.php');
preg_match_all('~^\s*RewriteRule\s+(\^\S*)\s+([^ ]+\.php\S*)\s+\[L,QSA\]~m', $apache, $apacheRules, PREG_SET_ORDER);
preg_match_all('~^\s*rewrite "\^/(.*?)" /([^ ]+\.php\S*) last;~m', $nginx, $nginxRules, PREG_SET_ORDER);
preg_match_all("~^\\s*'#\\^/(.*?)#'\\s*=> \\['(\\w+\\.php)'~m", $router, $routerRules, PREG_SET_ORDER);
$normalize = static fn (string $rule): string => str_replace('[0-9]', '\d', $rule);
$map = static function (array $rules, bool $prefix) use ($normalize): array {
    $result = [];
    foreach ($rules as [, $pattern, $target]) {
        $result[$normalize($prefix ? '^' . $pattern : $pattern)] = $target;
    }
    return $result;
};
$apacheMap = $map($apacheRules, false);
$nginxMap = $map($nginxRules, true);
$routerMap = $map($routerRules, true);
check_condition(count($apacheMap) === 10 && $apacheMap === $nginxMap, 'Apache 与 nginx 路由不一致');
check_condition(array_map(static fn (string $target): string => explode('?', $target)[0], $apacheMap) === $routerMap,
    'router.php 路由与服务器配置不一致');

check_command([PHP_BINARY, 'tests/run.php']);
