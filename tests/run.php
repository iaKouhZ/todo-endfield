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
$test['子任务按截止时间排序，并按原 ID 保存编辑'] = static function (): void {
    $task = tl_task_create(['title' => '子任务排序']);
    $undated = tl_subitem_add($task, '未设日期');
    $nextYear = tl_subitem_add($task, '次年', ['due_date' => '2027-01-01']);
    $dateOnly = tl_subitem_add($task, '仅设日期', ['due_date' => '2026-12-31']);
    $morning = tl_subitem_add($task, '上午', ['due_date' => '2026-12-31', 'due_time' => '09:00']);
    $sameTime = tl_subitem_add($task, '同一时刻', ['due_date' => '2026-12-31', 'due_time' => '09:00']);
    $endOfDay = tl_subitem_add($task, '午夜前', ['due_date' => '2026-12-31', 'due_time' => '23:59']);
    $rows = tl_task_subitems($task);
    same([$morning, $sameTime, $dateOnly, $endOfDay, $nextYear, $undated], array_column($rows, 'id'));

    $rows[0]['due_date'] = '2027-01-02';
    tl_subitems_save_rows($task, $rows);
    $rows = tl_task_subitems($task);
    same([$sameTime, $dateOnly, $endOfDay, $nextYear, $morning, $undated], array_column($rows, 'id'));
    same('2027-01-02', $rows[4]['due_date']);
    same('上午', $rows[4]['title']);
};
$test['远期任务按发生日期排序，无截止日排在最后'] = static function (): void {
    $undated = tl_task_create(['title' => '远期排序 无截止日', 'tracked' => true]);
    $later = tl_task_create(['title' => '远期排序 次年', 'due_date' => '2027-01-01', 'tracked' => true]);
    $series = tl_task_create([
        'title' => '远期排序 月末', 'due_date' => '2026-02-28', 'due_time' => '09:00',
        'repeat_unit' => 'month', 'repeat_interval' => 1, 'tracked' => true,
    ]);
    tl_complete_occurrence($series, '2026-02-28', true);
    $afternoon = tl_task_create([
        'title' => '远期排序 同日下午', 'due_date' => '2026-03-28', 'due_time' => '15:00', 'tracked' => true,
    ]);
    $sections = array_column(tl_front_tracked(['q' => '远期排序'])['sections'], null, 'id');
    $entries = $sections['nodate']['entries'];
    same([$series, $afternoon, $later, $undated], array_map(
        static fn (array $entry): int => (int) $entry['task']['id'], $entries
    ));
    same('2026-03-28', $entries[0]['date']);
    same('/tracked/task/12/2026-03-28/?cat=3&q=two+words', tl_task_edit_return(12, [
        'tab' => 'tracked', 'date' => '2026-03-28', 'cat' => '3', 'q' => 'two words',
    ]));
    same(null, tl_task_edit_return(12, ['tab' => '//foreign.test']));
};
$test['追踪中的置顶任务保留近期子任务且不重复分组'] = static function (): void {
    $filters = ['q' => '置顶边界'];
    $dates = [
        '远期' => '2026-03-28', '窗口内' => '2026-03-23',
        '逾期' => '2026-03-19', '未设截止' => null, '重复' => '2026-03-24',
    ];
    $ids = [];
    $expectedChildren = [];
    foreach ($dates as $label => $due) {
        $task = tl_task_create([
            'title' => '置顶边界 ' . $label, 'due_date' => $due, 'tracked' => true,
        ] + ($label === '重复' ? ['repeat_unit' => 'day', 'repeat_interval' => 1] : []));
        $ids[] = $task;
        tl_task_set_pin($task, 1);
        $expectedChildren[$task] = [];
        foreach (['2026-03-20' => '窗口下界', '2026-03-27' => '窗口上界'] as $childDate => $childTitle) {
            if ($due === null || $childDate <= $due) {
                tl_subitem_add($task, $childTitle, ['due_date' => $childDate]);
                $expectedChildren[$task][] = $childTitle;
            }
        }
        tl_subitem_add($task, '窗口外', ['due_date' => '2026-03-19']);
        $done = tl_subitem_add($task, '已完成子任务', ['due_date' => min($due ?? '2026-03-20', '2026-03-20')]);
        tl_subitem_set_done($done, $task, true);
    }
    $feed = tl_front_tracked($filters);
    same([0, 0, 0], array_column($feed['sections'], 'count'));
    $pinned = tl_front_pinned($filters, 'tracked', $feed['due_subitems']);
    same(count($ids), $pinned['count']);
    $entries = array_column($pinned['entries'], null, 'key');
    foreach ($ids as $index => $id) {
        $date = array_values($dates)[$index];
        $entry = $entries[$id . '@' . ($date ?? '-')];
        same($expectedChildren[$id], array_column($entry['due_subitems'] ?? [], 'title'));
    }
    tl_task_set_pin($ids[0], 0);
    $feed = tl_front_tracked($filters);
    same(1, $feed['counts']['week']);
    same(count($ids) - 1, tl_front_pinned($filters, 'tracked', $feed['due_subitems'])['count']);
};
$test['重复任务代表条目跳过已完成的发生'] = static function (): void {
    $id = tl_task_create([
        'title' => '下一次未完成', 'due_date' => '2026-03-19', 'repeat_unit' => 'day',
        'repeat_interval' => 1, 'tracked' => true, 'pinned' => true,
    ]);
    $filters = ['q' => '下一次未完成'];
    same('2026-03-19', tl_front_pinned($filters)['entries'][0]['date']);
    foreach (['2026-03-19', '2026-03-20', '2026-03-21'] as $day) {
        tl_complete_occurrence($id, $day, true);
    }
    same('2026-03-22', tl_front_pinned($filters)['entries'][0]['date']);
    same('2026-03-22', tl_front_scope('all', $filters)['entries'][0]['date']);
};
$test['子任务继承主任务截止日期和时间并校验上限'] = static function (): void {
    $id = tl_task_create([
        'title' => '子任务截止验证', 'due_date' => '2026-03-22', 'due_time' => '15:00', 'tracked' => true,
    ]);
    $inherited = tl_subitem_add($id, '完全继承');
    tl_subitem_add($id, '同日跟随时刻', ['due_date' => '2026-03-22']);
    tl_subitem_add($id, '较早日期', ['due_date' => '2026-03-21']);
    rejects(static fn () => tl_subitem_add($id, '跨日超期', ['due_date' => '2026-03-23']), InvalidArgumentException::class);
    rejects(static fn () => tl_subitem_add($id, '同日超期', [
        'due_date' => '2026-03-22', 'due_time' => '15:01',
    ]), InvalidArgumentException::class);
    rejects(static fn () => tl_subitems_save_rows($id, [[
        'id' => $inherited, 'title' => '被编辑的子任务', 'due_date' => '2026-03-23',
    ]]), InvalidArgumentException::class);
    $feed = tl_front_tracked(['q' => '子任务截止验证']);
    $rows = array_column($feed['due_subitems'][$id], null, 'title');
    same(null, array_column(tl_task_subitems($id), null, 'id')[$inherited]['due_date']);
    same('2026-03-22', $rows['完全继承']['due_date']);
    same('15:00', $rows['完全继承']['due_time']);
    same('15:00', $rows['同日跟随时刻']['due_time']);
    same(null, $rows['较早日期']['due_time']);

    $series = tl_task_create([
        'title' => '重复主任务截止验证', 'due_date' => '2026-03-20', 'due_time' => '15:00',
        'repeat_unit' => 'day', 'repeat_interval' => 1, 'tracked' => true,
    ]);
    tl_subitem_add($series, '跟随下一次发生');
    tl_complete_occurrence($series, '2026-03-20', true);
    $feed = tl_front_tracked(['q' => '重复主任务截止验证']);
    same('2026-03-21', $feed['due_subitems'][$series][0]['due_date']);
    same('15:00', $feed['due_subitems'][$series][0]['due_time']);
};
$test['主任务完成与恢复保留子任务自己的勾选和时间'] = static function (): void {
    $id = tl_task_create([
        'title' => '主任务完成时间', 'due_date' => '2026-03-20', 'repeat_unit' => 'day', 'repeat_interval' => 1,
    ]);
    $standalone = tl_subitem_add($id, '独立完成');
    $inherited = tl_subitem_add($id, '随主任务完成');
    tl_subitem_set_done($standalone, $id, true);
    $read = static fn (): array => array_column(tl_task_subitems($id), null, 'id');
    same('2026-03-20 12:00:00', $read()[$standalone]['own_completed_at']);
    $before = tl_backup_export_json()['task_subitems'];

    tl_complete_occurrence($id, '2026-03-20', true);
    same(0, (int) $read()[$inherited]['effective_done']);
    same($before, tl_backup_export_json()['task_subitems']);

    tl_task_set_status($id, 'done', '2026-03-20 10:00:29');
    $rows = $read();
    same(1, (int) $rows[$standalone]['effective_done']);
    same('2026-03-20 12:00:00', $rows[$standalone]['effective_completed_at']);
    same(0, (int) $rows[$inherited]['done']);
    same(1, (int) $rows[$inherited]['effective_done']);
    same(null, $rows[$inherited]['own_completed_at']);
    same('2026-03-20 10:00:29', $rows[$inherited]['effective_completed_at']);
    same($before, tl_backup_export_json()['task_subitems']);

    rejects(static fn () => tl_subitem_set_done($inherited, $id, true, '2026-03-20 10:01:00'), InvalidArgumentException::class);
    rejects(static fn () => tl_subitem_set_done($standalone, $id, false), InvalidArgumentException::class);
    tl_subitem_set_done($standalone, $id, true, '2026-03-20 11:55:27');
    tl_subitem_set_done($standalone, $id, true, '2026-03-20 11:55:00');
    tl_task_set_status($id, 'done', '2026-03-20 11:00:00');
    same('2026-03-20 11:00:00', $read()[$inherited]['effective_completed_at']);
    tl_tasks_set_status([$id], 'planned');
    $rows = $read();
    same(1, (int) $rows[$standalone]['effective_done']);
    same('2026-03-20 11:55:27', $rows[$standalone]['effective_completed_at']);
    same(0, (int) $rows[$inherited]['effective_done']);
    same(null, $rows[$inherited]['effective_completed_at']);
};
$test['后台保存时区分禁用的子任务勾选框和显式提交的勾选'] = static function (): void {
    $id = tl_task_create(['title' => '后台编辑状态']);
    $standalone = tl_subitem_add($id, '独立完成');
    $inherited = tl_subitem_add($id, '随主任务完成');
    $rows = [
        ['id' => $standalone, 'title' => '独立完成', 'done' => true],
        ['id' => $inherited, 'title' => '随主任务完成'],
        ['id' => 0, 'title' => '整表新行', 'done' => true],
        ['id' => 0, 'title' => '新增未完成'],
    ];
    tl_subitems_save_rows($id, $rows);
    $read = static fn (): array => array_column(tl_task_subitems($id), null, 'title');
    same('2026-03-20 12:00:00', $read()['整表新行']['own_completed_at']);
    same(0, (int) $read()['新增未完成']['done']);
    tl_tasks_set_status([$id], 'done');
    $rows = array_map(static fn (array $row): array => ['id' => $row['id'], 'title' => $row['title']], array_values($read()));
    tl_subitems_save_rows($id, $rows);
    same([1, 0, 1, 0], array_map(static fn (array $row): int => (int) $row['done'], array_values($read())));
    $previous = tl_task_update($id, ['title' => '后台编辑状态', 'status' => 'active']);
    tl_subitems_save_rows($id, $rows, preserveDoneState: $previous === 'done');
    same([1, 0, 1, 0], array_map(static fn (array $row): int => (int) $row['effective_done'], array_values($read())));
    same('2026-03-20 12:00:00', $read()['独立完成']['effective_completed_at']);
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
    same(0, tl_backup_validate($empty)['tasks']);
    same($expected, tl_backup_export_json());
    tl_backup_import_json($empty, savePrevious: true);
    same(0, tl_task_count());
    same($expected, tl_backup_previous());
    tl_backup_import_json(json_decode(json_encode($expected, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR));
    same($expected, tl_backup_export_json());
    $invalid = $expected;
    $invalid['task_subitems'][0]['task_id'] = 999999;
    rejects(static fn () => tl_backup_import_json($invalid), InvalidArgumentException::class);
    same($expected, tl_backup_export_json());
    same($expected, tl_backup_previous());
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
