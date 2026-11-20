<?php
declare(strict_types=1);

/**
 * 后台工作台
 * ---------------------------------------------------------------------------
 *   ?view=tasks       待办事项（列表 / 编辑 / 批量）
 *   ?view=categories  分类设置
 *   ?view=system      数据与清理
 */

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/feed.php';
require __DIR__ . '/lib/view.php';
require __DIR__ . '/lib/admin.php';

tl_boot();

if (!tl_admin()) {
    /* 回跳地址用绝对路径：登录页可能挂在别处，相对路径会被解析到别处去。 */
    $target = tl_admin_url(array_filter($_GET, fn ($v) => is_string($v) && $v !== ''));
    tl_redirect(tl_login_url(['back' => $target]));
}

$view = (string) ($_GET['view'] ?? 'tasks');
if (!in_array($view, TL_ADMIN_VIEWS, true)) {
    $view = 'tasks';
}

$data = tl_admin_data();
['categories' => $categories, 'globalCounts' => $globalCounts, 'now' => $now] = $data;

$flash = tl_flash_take();
tl_view_head(['title' => cfg('app.name') . ' · 任务管理', 'scheme' => tl_scheme()]);
?>
<div class="tl-shell">
  <?php tl_topbar('admin'); ?>
  <?php tl_flash_view($flash); ?>

  <div class="tl-masthead">
    <div>
      <h1 class="tl-masthead__title">任务管理</h1>
      <p class="tl-masthead__sub"><?= e(tl_date_cn($now)) ?> · <?= e(tl_weekday_cn($now)) ?></p>
    </div>
    <div class="tl-readouts">
      <div class="tl-readout tl-readout--accent">
        <span class="tl-readout__value"><?= $globalCounts['active'] ?></span>
        <span class="tl-readout__label">进行中</span>
      </div>
      <div class="tl-readout">
        <span class="tl-readout__value"><?= $globalCounts['planned'] ?></span>
        <span class="tl-readout__label">规划中</span>
      </div>
      <div class="tl-readout">
        <span class="tl-readout__value"><?= $globalCounts['done'] ?></span>
        <span class="tl-readout__label">已完成</span>
      </div>
      <div class="tl-readout">
        <span class="tl-readout__value"><?= count($categories) ?></span>
        <span class="tl-readout__label">分类</span>
      </div>
    </div>
  </div>

  <nav class="tl-tabs" role="tablist" aria-label="后台视图">
    <?php
    $adminMeta = [
        'tasks'      => ['name' => '待办事项'],
        'categories' => ['name' => '分类设置'],
        'system'     => ['name' => '维护'],
    ];
    foreach (TL_ADMIN_VIEWS as $adminView) {
        $item = ['id' => $adminView] + $adminMeta[$adminView];
        ?>
      <a class="tl-tab" role="tab"
         href="<?= e(tl_admin_url(['view' => $item['id']])) ?>"
         aria-selected="<?= $view === $item['id'] ? 'true' : 'false' ?>">
        <span><?= e($item['name']) ?></span>
      </a>
    <?php } ?>
  </nav>

  <?php match ($view) {
      'tasks' => tl_admin_render_tasks($data),
      'categories' => tl_admin_render_categories($data),
      'system' => tl_admin_render_system($data),
  }; ?>

  <template id="subitem-row-template">
    <?php tl_admin_empty_subitem_row(); ?>
  </template>
  <?php tl_view_foot(); ?>
</div>

<dialog class="tl-dialog" id="confirm-dialog">
  <div class="tl-dialog__band">
    <div class="tl-dialog__header">
      <span class="tl-dialog__title" data-confirm-title>确认操作</span>
    </div>
    <div class="tl-dialog__body"><p data-confirm-message></p></div>
    <div class="tl-dialog__actions">
      <button class="eds-capsule eds-capsule--dark eds-capsule--cancel" type="button" data-confirm-cancel>取消</button>
      <button class="eds-capsule eds-capsule--accent eds-capsule--confirm" type="button" data-confirm-ok>确认</button>
    </div>
  </div>
</dialog>

<script src="<?= e(tl_asset('assets/app.js')) ?>"></script>
<script src="<?= e(tl_asset('assets/admin.js')) ?>"></script>
</body>
</html>
