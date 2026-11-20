<?php
declare(strict_types=1);

/**
 * 待办清单 · 核心
 * ---------------------------------------------------------------------------
 * 配置读取 / 北京时间 / SQLite / 登录 cookie / CSRF / flash。
 * 这是唯一需要被每个入口文件引入的文件。
 */

const TL_STATUSES = ['active' => '进行中', 'planned' => '规划中', 'done' => '已完成'];

/* ===========================================================================
   配置
   ========================================================================= */
function cfg(string $path, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }
    $node = $config;
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return $default;
        }
        $node = $node[$key];
    }
    return $node;
}

/* ===========================================================================
   启动
   ========================================================================= */
function tl_boot(): void
{
    $debug = (bool) cfg('app.debug', false);
    date_default_timezone_set((string) cfg('app.timezone', 'Asia/Shanghai'));
    error_reporting(E_ALL);
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store, max-age=0');
        /* 必须在任何输出之前签发 CSRF cookie：它要随响应头下发。 */
        tl_csrf_token();
    }
}

/* ===========================================================================
   时间 · 全部按北京时间（config app.timezone）
   ========================================================================= */
function tl_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone((string) cfg('app.timezone', 'Asia/Shanghai'));
}

/** 当前时间。config 中 app.clock 可固定时间，用于演示 / 测试。 */
function tl_now(): DateTimeImmutable
{
    $fixed = cfg('app.clock');
    if (is_string($fixed) && $fixed !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fixed, tl_tz());
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }
    return new DateTimeImmutable('now', tl_tz());
}

function tl_today(): DateTimeImmutable
{
    return tl_now()->setTime(0, 0);
}

/** 严格解析 Y-m-d，失败返回 null。 */
function tl_parse_date(?string $ymd): ?DateTimeImmutable
{
    if (!is_string($ymd) || $ymd === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, tl_tz());
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }
    return $dt->format('Y-m-d') === $ymd ? $dt : null;
}

/** 严格解析 H:i（也接受 H:i:s），返回 H:i。 */
function tl_parse_time(?string $hm): ?string
{
    if (!is_string($hm) || $hm === '') {
        return null;
    }
    foreach (['H:i', 'H:i:s'] as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $hm, tl_tz());
        if ($dt instanceof DateTimeImmutable && $dt->format($format) === $hm) {
            return $dt->format('H:i');
        }
    }
    return null;
}

function tl_ymd(DateTimeImmutable $d): string
{
    return $d->format('Y-m-d');
}

function tl_parse_due(array $task): ?DateTimeImmutable
{
    $date = tl_parse_date($task['due_date'] ?? null);
    if ($date === null) {
        return null;
    }
    $time = tl_parse_time($task['due_time'] ?? null);
    if ($time !== null) {
        [$h, $i] = array_map('intval', explode(':', $time));
        return $date->setTime($h, $i);
    }
    return $date->setTime(23, 59);
}

/** 解析日期时间选择器提交的北京时间，并转换为数据库格式。 */
function tl_parse_completion_input(mixed $value): ?string
{
    if ($value === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('完成时间格式不正确。');
    }
    $format = 'Y-m-d\TH:i';
    $time = DateTimeImmutable::createFromFormat('!' . $format, $value, tl_tz());
    if ($time === false || $time->format($format) !== $value) {
        throw new InvalidArgumentException('完成时间格式不正确。');
    }
    return $time->format('Y-m-d H:i:s');
}

function tl_weekday_cn(DateTimeImmutable $d): string
{
    $names = ['日', '一', '二', '三', '四', '五', '六'];
    return '星期' . $names[(int) $d->format('w')];
}

function tl_date_cn(DateTimeImmutable $d): string
{
    return (int) $d->format('n') . '月' . (int) $d->format('j') . '日';
}

function tl_day_label(DateTimeImmutable $due, ?DateTimeImmutable $now = null): string
{
    $now ??= tl_now();
    $seconds = $due->getTimestamp() - $now->getTimestamp();
    if ($seconds > 0) {
        $hours = intdiv($seconds, 3600);
        if ($hours === 0) {
            return '不到1小时';
        }
        if ($hours < 24) {
            return $hours . '小时后';
        }
        return intdiv($hours, 24) . '天' . ($hours % 24) . '小时后';
    }

    $diff = (int) $now->setTime(0, 0)->diff($due->setTime(0, 0))->format('%r%a');
    if ($diff === 0) {
        return '今天';
    }
    if ($diff === -1) {
        return '昨天';
    }
    return '逾期 ' . abs($diff) . ' 天';
}

/** 月份推进，日号按目标月天数夹取（1 月 31 日 +1 月 = 2 月最后一天）。 */
function tl_add_months(DateTimeImmutable $start, int $months): DateTimeImmutable
{
    $total = ((int) $start->format('Y')) * 12 + ((int) $start->format('n') - 1) + $months;
    $year = intdiv($total, 12);
    $month = $total % 12 + 1;
    $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), tl_tz()))->format('t');
    $day = min((int) $start->format('j'), $daysInMonth);
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), tl_tz());
}

/* ===========================================================================
   输出
   ========================================================================= */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 静态资源地址带上内容指纹：design/tokens.css?v=ab12cd34
 * ---------------------------------------------------------------------------
 * 线上静态资源会被 CDN 缓存好几天，只改文件不改地址的话，浏览器拿到的还是
 * 旧版本，看上去就像改动「没生效」。指纹取自文件内容的哈希：内容不变则地址不变，
 * 内容一变地址立刻跟着变，缓存自然被绕过。用内容而不是修改时间，是因为整批
 * 文件一起拷贝时 mtime 会完全相同，那样几个地址就会撞成同一个指纹。
 *
 * 地址是绝对路径（带应用前缀）：伪静态页面在 /tracked/ 这种目录下，
 * 相对地址会被浏览器解析成 /tracked/assets/app.js，直接 404。
 */
function tl_asset(string $path): string
{
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    $hash = is_file($file) ? (string) md5_file($file) : md5($path . '|' . time());
    return $cache[$path] = tl_base_path() . '/' . ltrim($path, '/') . '?v=' . substr($hash, 0, 8);
}

function tl_is_ajax(): bool
{
    return isset($_POST['ajax']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function tl_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 只接受站内地址，挡住 open redirect。
 * 允许两种形状：绝对路径（/tracked/?cat=3，伪静态）与老地址（index.php?tab=tracked）。
 */
function tl_safe_path(?string $url, string $fallback = ''): string
{
    if (!is_string($url) || $url === '') {
        return $fallback;
    }
    if (str_starts_with($url, '//') || preg_match('#[^A-Za-z0-9_\-./?=&%\#\+]#', $url)) {
        return $fallback;
    }
    if ($url[0] !== '/' && !preg_match('#^[A-Za-z0-9_\-]+\.php#', $url)) {
        return $fallback;
    }
    return $url;
}

function tl_redirect(string $url, int $status = 303): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

function tl_redirect_back(string $fallback = ''): never
{
    tl_redirect(tl_safe_path($_POST['back'] ?? null, $fallback !== '' ? $fallback : tl_front_url()));
}

/* ===========================================================================
   站内地址 · 伪静态
   ---------------------------------------------------------------------------
   路径与入口文件的对应关系。三份服务器配置必须和这里保持一致：
     .htaccess           Apache
     deploy/nginx.conf   nginx
     router.php          本地 php -S

     /                                 → index.php
     /tracked/ /planned/ /done/ /all/ → index.php?tab=<tab>
     /tracked/task/12/                 → index.php?tab=tracked&task=12
     /tracked/task/12/2026-10-24/      → index.php?tab=tracked&task=12&date=2026-10-24
     /admin/                           → admin.php?view=tasks
     /admin/tasks/                     → admin.php?view=tasks
     /admin/categories/                → admin.php?view=categories
     /admin/system/                    → admin.php?view=system
     /admin/tasks/12/                  → admin.php?view=tasks&edit=12
     /login/                           → login.php
     /logout/                          → logout.php
     /action/                          → action.php

   筛选条件（分类 / 搜索 / 状态 / 分页）留在查询串里，不进路径 —— 它们可以任意
   组合，塞进路径只会得到一堆长得吓人的地址。

   服务器不支持 rewrite 时，把 config.php 的 app.pretty_url 关掉，
   地址退回 index.php?… 的老样子，功能不变。
   ========================================================================= */
const TL_FRONT_TABS  = ['tracked', 'planned', 'done', 'all'];
const TL_ADMIN_VIEWS = ['tasks', 'categories', 'system'];

/** 应用在 URL 里的前缀：装在站点根目录是 ''，装在 /todo 下是 '/todo'。 */
function tl_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    /* 只有真正落到入口文件上时 SCRIPT_NAME 才带 .php；本地开发服务器用
       router.php 时它是请求路径（/tracked/），拿它算前缀会算成 /tracked。
       dirname() 在 Windows 上返回反斜杠，这里统一成 /。 */
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = str_ends_with($script, '.php') ? dirname($script) : '/';
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    return $base = ($dir === '' || $dir === '.') ? '' : $dir;
}

/** 伪静态开关。关掉后退回 index.php?… 的老地址。 */
function tl_pretty_url(): bool
{
    return (bool) cfg('app.pretty_url', true);
}

/** 查询串：没有参数返回 ''，否则 '?a=1&b=2'。 */
function tl_query(array $params): string
{
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
    return $params === [] ? '' : '?' . http_build_query($params);
}

/** 前台地址。$params：tab / cat / q / task / date。 */
function tl_front_url(array $params = []): string
{
    $tab = (string) ($params['tab'] ?? 'tracked');
    if (!in_array($tab, TL_FRONT_TABS, true)) {
        $tab = 'tracked';
    }
    $task = (string) ($params['task'] ?? '');
    $date = (string) ($params['date'] ?? '');

    if (!tl_pretty_url()) {
        return tl_base_path() . '/index.php' . tl_query([
            'tab'  => $tab,
            'cat'  => $params['cat'] ?? '',
            'q'    => $params['q'] ?? '',
            'task' => $task,
            'date' => $date,
        ]);
    }

    $path = tl_base_path() . '/' . $tab;
    if ($task !== '' && ctype_digit($task)) {
        $path .= '/task/' . (int) $task;
        if ($date !== '' && preg_match('#^\d{4}-\d{2}-\d{2}$#', $date)) {
            $path .= '/' . $date;
        }
    }
    return $path . '/' . tl_query(['cat' => $params['cat'] ?? '', 'q' => $params['q'] ?? '']);
}

/** 后台地址。$params：view / status / cat / q / tracked / edit / page 等。 */
function tl_admin_url(array $params = []): string
{
    $view = (string) ($params['view'] ?? 'tasks');
    if (!in_array($view, TL_ADMIN_VIEWS, true)) {
        $view = 'tasks';
    }
    $edit = (string) ($params['edit'] ?? '');
    $query = $params;
    unset($query['view'], $query['edit']);

    if (!tl_pretty_url()) {
        return tl_base_path() . '/admin.php' . tl_query(array_merge(
            ['view' => $view],
            $query,
            $edit !== '' ? ['edit' => $edit] : []
        ));
    }

    $path = tl_base_path() . '/admin/' . $view;
    if ($view === 'tasks' && $edit !== '' && ctype_digit($edit)) {
        $path .= '/' . (int) $edit;
    }
    return $path . '/' . tl_query($query);
}

/** 用看板筛选和发生日期重建任务地址。 */
function tl_task_edit_return(int $id, mixed $context): ?string
{
    if ($id <= 0 || !is_array($context) || !in_array($context['tab'] ?? null, TL_FRONT_TABS, true)) {
        return null;
    }
    $params = array_intersect_key($context, array_flip(['tab', 'cat', 'q', 'date']));
    $params = array_filter($params, 'is_string');
    return tl_front_url(['task' => $id] + $params);
}

/** 登录页地址。 */
function tl_login_url(array $params = []): string
{
    return tl_base_path() . (tl_pretty_url() ? '/login/' : '/login.php') . tl_query($params);
}

/** 退出地址（POST）。 */
function tl_logout_url(): string
{
    return tl_base_path() . (tl_pretty_url() ? '/logout/' : '/logout.php');
}

/** 写操作入口地址（POST）。 */
function tl_action_url(): string
{
    return tl_base_path() . (tl_pretty_url() ? '/action/' : '/action.php');
}

/* ===========================================================================
   数据库
   ========================================================================= */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = (string) cfg('db');
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建数据目录：' . $dir);
    }

    // Serialise first connections so no request sees a partially created schema.
    $lock = fopen($path . '.init.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('无法打开数据库初始化锁。');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('无法锁定数据库初始化。');
        }
        clearstatcache(true, $path);
        $needsInit = !is_file($path) || filesize($path) === 0;
        $connection = new PDO('sqlite:' . $path);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 4000');
        if ($needsInit) {
            $connection->exec('PRAGMA journal_mode = WAL');
            $connection->beginTransaction();
            try {
                tl_db_migrate($connection);
                $connection->commit();
            } catch (Throwable $error) {
                $connection->rollBack();
                $connection = null;
                foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                throw $error;
            }
        }
        $pdo = $connection;
    } finally {
        fclose($lock);
    }
    return $pdo;
}

function tl_db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS categories (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL UNIQUE,
            position    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL
        );

        CREATE TABLE IF NOT EXISTS tasks (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            title           TEXT    NOT NULL,
            detail          TEXT,
            category_id     INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            due_date        TEXT,
            due_time        TEXT,
            status          TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'planned', 'done')),
            repeat_unit     TEXT CHECK (repeat_unit IS NULL OR repeat_unit IN ('day', 'week', 'month')),
            repeat_interval INTEGER CHECK (repeat_interval IS NULL OR repeat_interval >= 1),
            tracked         INTEGER NOT NULL DEFAULT 0 CHECK (tracked IN (0, 1)),
            pinned          INTEGER NOT NULL DEFAULT 0 CHECK (pinned IN (0, 1)),
            completed_at    TEXT,
            created_at      TEXT    NOT NULL,
            updated_at      TEXT    NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_tasks_status  ON tasks(status);
        CREATE INDEX IF NOT EXISTS idx_tasks_due     ON tasks(due_date, due_time);
        CREATE INDEX IF NOT EXISTS idx_tasks_cat     ON tasks(category_id);
        CREATE INDEX IF NOT EXISTS idx_tasks_tracked ON tasks(tracked);
        CREATE INDEX IF NOT EXISTS idx_tasks_pinned  ON tasks(pinned);

        /* 重复任务的“发生”：一条任务不必为每一天各建一行，勾掉某一天只记在这里。 */
        CREATE TABLE IF NOT EXISTS task_occurrences (
            task_id      INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
            occur_date   TEXT    NOT NULL,
            completed_at TEXT    NOT NULL,
            PRIMARY KEY (task_id, occur_date)
        );

        /* 子任务可独立勾选；主任务标为完成时全部子任务记为完成。 */
        CREATE TABLE IF NOT EXISTS task_subitems (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id     INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
            title       TEXT    NOT NULL,
            due_date    TEXT,
            due_time    TEXT,
            done        INTEGER NOT NULL DEFAULT 0 CHECK (done IN (0, 1)),
            completed_at TEXT,
            position    INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_task_subitems_task ON task_subitems(task_id, position, id);
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip           TEXT PRIMARY KEY,
            failures     INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT,
            updated_at   TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS app_meta (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        INSERT OR IGNORE INTO app_meta (key, value) VALUES ('session_version', '1');
    SQL);

    $now = tl_now()->format('Y-m-d H:i:s');
    $count = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO categories (name, position, created_at) VALUES (?, ?, ?)');
        foreach ([['学习', 10], ['生活', 20], ['事务', 30]] as [$name, $position]) {
            $stmt->execute([$name, $position, $now]);
        }
    }
}

/* ===========================================================================
   登录 · 签名 cookie（不依赖 session）
   ========================================================================= */
function tl_cookie_options(int $expires): array
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function tl_scheme(): string
{
    $scheme = (string) ($_COOKIE['tl_scheme'] ?? 'light');
    return in_array($scheme, ['light', 'dark'], true) ? $scheme : 'light';
}

function tl_auth_password(): string
{
    return (string) cfg('auth.password', '');
}

function tl_auth_cookie(string $role): string
{
    return (string) cfg($role === 'admin' ? 'cookie.admin_name' : 'cookie.front_name');
}

function tl_auth_ttl(string $role): int
{
    return (int) cfg($role === 'admin' ? 'cookie.admin_ttl' : 'cookie.front_ttl', 3600);
}

function tl_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function tl_login_locked_seconds(string $ip): int
{
    $stmt = db()->prepare('SELECT locked_until FROM login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
    $until = $stmt->fetchColumn();
    return is_string($until) ? max(0, (new DateTimeImmutable($until, tl_tz()))->getTimestamp() - tl_now()->getTimestamp()) : 0;
}

function tl_login_record_failure(string $ip): int
{
    $now = tl_now();
    $minutes = max(1, (int) cfg('auth.lock_minutes', 15));
    $limit = max(1, (int) cfg('auth.max_attempts', 5));
    $stamp = $now->format('Y-m-d H:i:s');
    $cutoff = $now->modify("-$minutes minutes")->format('Y-m-d H:i:s');
    $until = $now->modify("+$minutes minutes")->format('Y-m-d H:i:s');
    $stmt = db()->prepare(<<<'SQL'
        INSERT INTO login_attempts (ip, failures, locked_until, updated_at)
        VALUES (:ip, 1, CASE WHEN :limit <= 1 THEN :until END, :stamp)
        ON CONFLICT(ip) DO UPDATE SET
            failures = CASE
                WHEN locked_until IS NOT NULL OR updated_at <= :cutoff THEN 1
                ELSE failures + 1
            END,
            locked_until = CASE
                WHEN (CASE WHEN locked_until IS NOT NULL OR updated_at <= :cutoff THEN 1 ELSE failures + 1 END) >= :limit
                THEN :until
            END,
            updated_at = :stamp
        WHERE locked_until IS NULL OR locked_until <= :stamp
    SQL);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    foreach (['ip' => $ip, 'stamp' => $stamp, 'cutoff' => $cutoff, 'until' => $until] as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->execute();
    return tl_login_locked_seconds($ip);
}

function tl_login_clear_failures(string $ip): void
{
    $now = tl_now();
    $minutes = max(1, (int) cfg('auth.lock_minutes', 15));
    db()->prepare('DELETE FROM login_attempts WHERE ip = ? OR (locked_until <= ?) OR
        (locked_until IS NULL AND updated_at <= ?)')->execute([
        $ip, $now->format('Y-m-d H:i:s'), $now->modify("-$minutes minutes")->format('Y-m-d H:i:s'),
    ]);
}

function tl_session_version(bool $reload = false): string
{
    static $version = null;
    if ($version === null || $reload) {
        $version = (string) (db()->query("SELECT value FROM app_meta WHERE key = 'session_version'")->fetchColumn() ?: '1');
    }
    return $version;
}

function tl_session_bump_version(): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE app_meta SET value = CAST(value AS INTEGER) + 1 WHERE key = 'session_version'");
        $version = tl_session_version(true);
        $pdo->commit();
        return $version;
    } catch (Throwable $e) {
        $pdo->rollBack();
        tl_session_version(true);
        throw $e;
    }
}

function tl_auth_sign(string $role, int $expires): string
{
    $password = tl_auth_password();
    $material = $role . '|' . $expires . '|' . tl_session_version() . '|' . hash('sha256', $password);
    return hash_hmac('sha256', $material, (string) cfg('cookie.secret'));
}

function tl_auth_issue(string $role): void
{
    $expires = tl_now()->getTimestamp() + tl_auth_ttl($role);
    $value = $expires . '.' . tl_auth_sign($role, $expires);
    setcookie(tl_auth_cookie($role), $value, tl_cookie_options($expires));
    $_COOKIE[tl_auth_cookie($role)] = $value;
}

function tl_auth_clear(string $role): void
{
    setcookie(tl_auth_cookie($role), '', tl_cookie_options(tl_now()->getTimestamp() - 3600));
    unset($_COOKIE[tl_auth_cookie($role)]);
}

function tl_auth_valid(string $role): bool
{
    if (tl_auth_password() === '' || (string) cfg('cookie.secret', '') === '') {
        return false;
    }
    $raw = $_COOKIE[tl_auth_cookie($role)] ?? '';
    if (!is_string($raw) || !str_contains($raw, '.')) {
        return false;
    }
    [$expiresRaw, $signature] = explode('.', $raw, 2);
    if (!ctype_digit($expiresRaw)) {
        return false;
    }
    $expires = (int) $expiresRaw;
    if ($expires < tl_now()->getTimestamp()) {
        return false;
    }
    return hash_equals(tl_auth_sign($role, $expires), $signature);
}

function tl_admin(): bool
{
    return tl_auth_valid('admin');
}

/** 前台公开展示开关：auth.public = true 时前台免密。 */
function tl_front_public(): bool
{
    return (bool) cfg('auth.public', false);
}

function tl_front_allowed(): bool
{
    if (tl_front_public()) {
        return true;
    }
    /* 前后台同一个密码：任一登录状态都能进前台。 */
    return tl_auth_valid('front') || tl_auth_valid('admin');
}

function tl_login(string $role, string $password): bool
{
    $expected = tl_auth_password();
    if ($expected === '' || (string) cfg('cookie.secret', '') === ''
        || !hash_equals($expected, $password)) {
        return false;
    }
    /* 前后台同一个密码：验证通过即同时签发两种登录状态。 */
    tl_auth_issue('front');
    tl_auth_issue('admin');
    return true;
}

/* ===========================================================================
   CSRF · double-submit cookie
   ========================================================================= */
function tl_csrf_token(): string
{
    $name = (string) cfg('cookie.csrf_name', 'tl_csrf');
    $token = $_COOKIE[$name] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        setcookie($name, $token, tl_cookie_options(time() + 86400 * 30));
        $_COOKIE[$name] = $token;
    }
    return $token;
}

function tl_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(tl_csrf_token()) . '">';
}

function tl_csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    $cookie = $_COOKIE[(string) cfg('cookie.csrf_name', 'tl_csrf')] ?? '';
    if (!is_string($sent) || !is_string($cookie) || $cookie === '' || !hash_equals($cookie, $sent)) {
        http_response_code(400);
        if (tl_is_ajax()) {
            tl_json(['ok' => false, 'message' => '页面已过期，请刷新后重试。'], 400);
        }
        exit('页面已过期，请刷新后重试。');
    }
}

/* ===========================================================================
   flash · 一次性的操作回执
   ========================================================================= */
function tl_flash(string $type, string $message): void
{
    $payload = json_encode(['type' => $type, 'message' => $message], JSON_UNESCAPED_UNICODE);
    $value = rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
    setcookie('tl_flash', $value, tl_cookie_options(0));
    $_COOKIE['tl_flash'] = $value;
}

function tl_flash_take(): ?array
{
    $raw = $_COOKIE['tl_flash'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    setcookie('tl_flash', '', tl_cookie_options(tl_now()->getTimestamp() - 3600));
    unset($_COOKIE['tl_flash']);
    $json = base64_decode(strtr($raw, '-_', '+/'), true);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data) || !isset($data['message'])) {
        return null;
    }
    return [
        'type'    => in_array($data['type'] ?? '', ['ok', 'error', 'info'], true) ? $data['type'] : 'info',
        'message' => (string) $data['message'],
    ];
}

/* ===========================================================================
   杂项
   ========================================================================= */
function tl_str_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function tl_str_limit(string $value, int $limit = 200): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
    preg_match_all('/./us', $value, $chars);
    return implode('', array_slice($chars[0] ?? [], 0, $limit));
}

function tl_status_label(?string $status): string
{
    return TL_STATUSES[$status ?? ''] ?? '未知';
}

function tl_repeat_label(?string $unit, ?int $interval): ?string
{
    if ($unit === null || $unit === '') {
        return null;
    }
    $n = max(1, (int) ($interval ?? 1));
    $unitName = ['day' => '天', 'week' => '周', 'month' => '个月'][$unit] ?? '';
    if ($unitName === '') {
        return null;
    }
    return $n === 1 ? '每' . $unitName : '每 ' . $n . ' ' . $unitName;
}

/** 简单分布统计，供页头读数与标签页计数。 */
function tl_counts(array $tasks): array
{
    $out = ['active' => 0, 'planned' => 0, 'done' => 0, 'all' => 0, 'tracked' => 0];
    foreach ($tasks as $task) {
        $status = $task['status'] ?? 'active';
        $out[$status] = ($out[$status] ?? 0) + 1;
        $out['all']++;
        if ((int) ($task['tracked'] ?? 0) === 1 && $status !== 'done') {
            $out['tracked']++;
        }
    }
    return $out;
}
