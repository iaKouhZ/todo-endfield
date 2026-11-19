<?php
declare(strict_types=1);

/**
 * 登录 · 一个密码，前台后台通用
 * ---------------------------------------------------------------------------
 * 登录口令通过 TODO_AUTH_PASSWORD 配置，登录状态存在签名 cookie 中。
 * 后台始终需要密码；前台在 auth.public = true 时免密。
 */

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/view.php';

tl_boot();

$back = tl_safe_path($_GET['back'] ?? $_POST['back'] ?? null, tl_front_url());
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tl_csrf_check();
    $ip = tl_client_ip();
    $locked = tl_login_locked_seconds($ip);
    if ($locked === 0) {
        if (tl_login('admin', (string) ($_POST['password'] ?? ''))) {
            tl_login_clear_failures($ip);
            tl_redirect($back);
        }
        $locked = tl_login_record_failure($ip);
        usleep(400000);
        $error = '密码不正确。';
    }
    if ($locked > 0) {
        http_response_code(429);
        header('Retry-After: ' . $locked);
        $error = sprintf('登录尝试过多，请在 %d 分 %d 秒后重试。', intdiv($locked, 60), $locked % 60);
    }
}

if ($error === null && tl_admin()) {
    tl_redirect($back);
}

$scheme = (string) ($_COOKIE['tl_scheme'] ?? 'light');
$scheme = in_array($scheme, ['light', 'dark'], true) ? $scheme : 'light';

tl_view_head(['title' => cfg('app.name') . ' · 登录', 'scheme' => $scheme]);
?>
<div class="tl-shell">
  <?php tl_topbar('login'); ?>
  <?php tl_flash_view(); ?>

  <div class="tl-gate">
    <div class="tl-gate__art">
      <span class="tl-gate__kicker"><?= e(cfg('app.latin')) ?></span>
      <h1 class="tl-gate__display">待办<br><em>清单</em></h1>
      <span class="tl-gate__watermark" aria-hidden="true">LEDGER</span>
    </div>

    <div class="tl-gate__panel">
      <form class="tl-gate__form" method="post" action="<?= e(tl_login_url()) ?>">
        <?= tl_csrf_field() ?>
        <input type="hidden" name="back" value="<?= e($back) ?>">

        <div>
          <div class="tl-gate__label">口令</div>
          <div class="tl-gate__kicker--caption tl-gate__kicker">PASSCODE</div>
        </div>

        <?php if ($error !== null): ?>
          <div class="tl-gate__error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <label>
          <span class="tl-sr">密码</span>
          <input class="tl-gate__input" type="password" name="password" autocomplete="current-password"
                 autofocus required>
        </label>

        <button class="tl-gate__submit" type="submit"><span>继续</span></button>
      </form>
    </div>
  </div>

  <?php tl_view_foot(); ?>
</div>
</body>
</html>
