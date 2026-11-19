<?php
declare(strict_types=1);

/** 退出登录。 */

require __DIR__ . '/lib/core.php';

tl_boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tl_redirect(tl_front_url());
}

tl_csrf_check();

$role = (string) ($_POST['role'] ?? '');
if (in_array($role, ['admin', 'front'], true)) {
    tl_auth_clear($role);
}

tl_flash('info', '已退出' . ($role === 'admin' ? '后台' : '前台') . '。');
tl_redirect(tl_safe_path($_POST['back'] ?? null, tl_front_url()));
