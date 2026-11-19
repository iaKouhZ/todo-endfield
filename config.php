<?php
/**
 * 待办清单 · 配置文件
 * ---------------------------------------------------------------------------
 * 登录口令和 Cookie 签名密钥从环境变量读取。
 * 全部时间按北京时间（Asia/Shanghai）处理，与服务器时区无关。
 */

return [
    'app' => [
        'name'     => '待办清单',
        'latin'    => 'TODO LEDGER',
        'timezone' => 'Asia/Shanghai',
        'debug'    => false,
        /* 伪静态：地址形如 /tracked/task/12/。
           需要服务器配好 rewrite（.htaccess 或 deploy/nginx.conf）。
           服务器不支持 rewrite 时把它关掉，地址退回 index.php?… 的老样子。 */
        'pretty_url' => true,
    ],

    /* ------------------------------------------------------------------ 访问控制
     * 一个密码，前台后台通用。
     * public = true 时前台免密公开展示；后台永远需要密码。
     */
    'auth' => [
        'password' => (string) getenv('TODO_AUTH_PASSWORD'),
        'public'   => false,
        'max_attempts' => 5,
        'lock_minutes' => 15,
    ],

    /* 追踪中页面：三段（已逾期 / 近一周 / 未设截止日与远期）。 */
    'front' => [
        'overdue_limit' => 10,   // 已逾期：最多 10 条
        'week_to'       => 7,    // 近一周：今天至 7 天后
        'horizon_days'  => 365,  // 重复任务向后展开的上限
    ],

    /* ------------------------------------------------------------------ Cookie
     * secret 用于给登录 cookie 签名。换一个随机长串即可让所有登录状态失效。
     */
    'cookie' => [
        'secret'     => (string) getenv('TODO_COOKIE_SECRET'),
        'admin_ttl'  => 60 * 60 * 12,        // 12 小时
        'front_ttl'  => 60 * 60 * 24 * 30,   // 30 天
        'admin_name' => 'tl_admin',
        'front_name' => 'tl_front',
        'csrf_name'  => 'tl_csrf',
    ],

    /* ------------------------------------------------------------------ 数据
     * SQLite 文件，首次运行自动创建。
     */
    'db' => __DIR__ . '/data/todo.sqlite',

    /* ------------------------------------------------------------------ 维护 */
    'purge_days' => 7,
];
