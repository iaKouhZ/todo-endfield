<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; got ' . var_export($actual, true));
    }
}

function rejects(callable $action, string $exception): void
{
    try {
        $action();
    } catch (Throwable $error) {
        if ($error instanceof $exception) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected ' . $exception);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/todo-tests-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
register_shutdown_function(static function () use ($tmp): void {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($tmp);
});
mkdir($tmp . '/lib');
foreach (['core', 'tasks', 'feed', 'backup'] as $file) {
    copy("$root/lib/$file.php", "$tmp/lib/$file.php");
}
copy("$root/router.php", "$tmp/router.php");
$config = require $root . '/config.php';
$config['app']['clock'] = '2026-03-20 12:00:00';
$config['app']['pretty_url'] = true;
$config['auth']['password'] = bin2hex(random_bytes(16));
$config['cookie']['secret'] = bin2hex(random_bytes(32));
$config['db'] = "$tmp/data/test.sqlite";
file_put_contents("$tmp/config.php", '<?php return ' . var_export($config, true) . ';');
foreach (['core', 'tasks', 'feed', 'backup'] as $file) {
    require "$tmp/lib/$file.php";
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$test = [];
$test['重复展开及月末'] = static function (): void {
    same('2024-02-29', tl_ymd(tl_add_months(tl_parse_date('2024-01-31'), 1)));
    $anchor = tl_parse_date('2010-01-31');
    $from = tl_parse_date('2025-01-30');
    $to = tl_parse_date('2027-01-31');
    foreach (['day' => 3, 'week' => 2, 'month' => 1] as $unit => $interval) {
        $task = ['due_date' => tl_ymd($anchor), 'repeat_unit' => $unit, 'repeat_interval' => $interval];
        $expected = [];
        for ($n = 0; ; $n++) {
            $date = tl_repeat_nth($anchor, $unit, $interval, $n);
            if ($date > $to) {
                break;
            }
            if ($date >= $from) {
                $expected[] = tl_ymd($date);
            }
        }
        same($expected, tl_occurrences_between($task, $from, $to));
        same([], tl_occurrences_between($task, $to, $from));
    }
    $monthly = ['due_date' => '2026-01-31', 'repeat_unit' => 'month', 'repeat_interval' => 1];
    same('2026-02-28', tl_ymd(tl_occurrence_before($monthly, tl_today())));
    same(true, tl_repeat_date_matches_schedule(tl_parse_date('2026-01-31'), 'month', 1, tl_parse_date('2026-02-28')));
    same(false, tl_repeat_date_matches_schedule(tl_parse_date('2026-01-31'), 'month', 1, tl_parse_date('2026-02-27')));
};
$test['跳转、限流和 Cookie 吊销'] = static function (): void {
    same('/tracked/', tl_safe_path('https://example.invalid/path', '/tracked/'));
    same('/tracked/', tl_safe_path('//example.invalid/path', '/tracked/'));
    same('/done/', tl_safe_path('/done/', '/tracked/'));
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.2';
    $ip = tl_client_ip();
    same('192.0.2.1', $ip);
    for ($n = 0; $n < 4; $n++) {
        same(0, tl_login_record_failure($ip));
    }
    same(900, tl_login_record_failure($ip));
    same(900, tl_login_locked_seconds($ip));
    same(0, tl_login_locked_seconds('192.0.2.2'));
    tl_login_clear_failures($ip);
    same(0, tl_login_locked_seconds($ip));
    same(0, (int) db()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    same(true, tl_login('admin', cfg('auth.password')));
    same(true, tl_admin());
    same('2', tl_session_bump_version());
    same(false, tl_admin());
    same(false, tl_front_allowed());
    same(true, tl_login('admin', cfg('auth.password')));
    same(true, tl_admin());
};
$test['SQL 约束、分类缓存和精确搜索'] = static function (): void {
    $pdo = db();
    foreach (["status = 'none'", "repeat_unit = 'year'", 'repeat_interval = 0', 'tracked = -1', 'pinned = 2'] as $pair) {
        $pdo->exec("INSERT INTO tasks (title, created_at, updated_at) VALUES ('test', '2026-03-01 00:00:00', '2026-03-01 00:00:00')");
        rejects(static fn () => $pdo->exec("UPDATE tasks SET $pair WHERE id = last_insert_rowid()"), PDOException::class);
    }
    $category = tl_category_create('验证');
    $before = tl_categories();
    $task = tl_task_create(['title' => 'literal % _ \\', 'category_id' => $category]);
    $after = tl_categories();
    same(0, (int) end($before)['task_count']);
    same(1, (int) end($after)['task_count']);
    same($task, tl_task_subitems($task) === [] ? $task : -1);
    rejects(static fn () => $pdo->exec("INSERT INTO task_subitems (task_id, title, done, created_at, updated_at) VALUES ($task, 'test', 2, '', '')"), PDOException::class);
    foreach (['%', '_', '\\'] as $literal) {
        same(1, tl_task_count(['q' => $literal]));
        same($task, (int) tl_tasks(['q' => $literal])[0]['id']);
    }
    tl_task_set_tracked($task, 1);
    foreach ([[], ['q' => '%'], ['category' => $category], ['not_done' => true]] as $filters) {
        $rows = tl_tasks($filters);
        $count = tl_counts($rows);
        $count['tracked_open'] = $count['tracked'];
        $count['planned_open'] = $count['all'] - $count['done'] - $count['tracked'];
        same($count, tl_task_counts_sql($filters));
    }
};
$test['清理、范围裁剪及备份往返'] = static function (): void {
    $task = tl_task_create([
        'title' => '月末', 'due_date' => '2025-12-31', 'repeat_unit' => 'month', 'repeat_interval' => 1,
    ]);
    tl_subitem_add($task, '子任务');
    $write = db()->prepare('INSERT INTO task_occurrences (task_id, occur_date, completed_at) VALUES (?, ?, ?)');
    foreach (['2025-12-31', '2026-01-31', '2026-02-28'] as $day) {
        $write->execute([$task, $day, "$day 12:00:00"]);
    }
    same(1, count(tl_occurrence_map([$task], '2026-01-31', '2026-01-31')));
    same(0, tl_purge_done());
    same([$task . '|2026-02-28' => '2026-02-28 12:00:00'], tl_occurrence_map([$task]));
    $expected = tl_backup_export_json();
    same(false, isset($expected['app_meta']) || isset($expected['login_attempts']));
    $sqlite = tl_backup_export_sqlite();
    try {
        $copy = new PDO('sqlite:' . $sqlite);
        same('ok', $copy->query('PRAGMA integrity_check')->fetchColumn());
        same(count($expected['tasks']), (int) $copy->query('SELECT COUNT(*) FROM tasks')->fetchColumn());
    } finally {
        unset($copy);
        unlink($sqlite);
    }
    $empty = ['version' => 1, 'tasks' => [], 'categories' => [], 'task_occurrences' => [], 'task_subitems' => []];
    tl_backup_import_json($empty);
    same(0, tl_task_count());
    tl_backup_import_json(json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR));
    same($expected, tl_backup_export_json());
    $invalid = $expected;
    $invalid['task_subitems'][0]['task_id'] = 999999;
    rejects(static fn () => tl_backup_import_json($invalid), InvalidArgumentException::class);
    same($expected, tl_backup_export_json());
    same('2', tl_session_version());
};
$test['站内地址与 router 往返'] = static function () use ($tmp): void {
    foreach (['index', 'admin', 'login', 'logout', 'action'] as $name) {
        file_put_contents("$tmp/$name.php", '<?php echo json_encode([basename(__FILE__), $_GET]);');
    }
    $routes = [
        [tl_front_url(['tab' => 'done', 'task' => 12, 'date' => '2026-03-01', 'q' => 'foo']), 'index.php',
            ['q' => 'foo', 'tab' => 'done', 'task' => '12', 'date' => '2026-03-01']],
        [tl_admin_url(['view' => 'tasks', 'edit' => 12]), 'admin.php', ['view' => 'tasks', 'edit' => '12']],
        [tl_admin_url(['view' => 'system']), 'admin.php', ['view' => 'system']],
        [tl_login_url(['back' => '/done/']), 'login.php', ['back' => '/done/']],
        [tl_logout_url(), 'logout.php', []],
        [tl_action_url(), 'action.php', []],
    ];
    foreach ($routes as [$uri, $script, $params]) {
        $_SERVER['REQUEST_URI'] = $uri;
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
        $_REQUEST = $_GET;
        ob_start();
        require "$tmp/router.php";
        same([$script, $params], json_decode(ob_get_clean(), true));
    }
};

ob_start(); // Keep cookie functions working until all test cases have finished.
foreach ($test as $name => $run) {
    try {
        $run();
        echo "通过  $name\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "失败  $name\n$error\n");
        exit(1);
    }
}
ob_end_flush();
