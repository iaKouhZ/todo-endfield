<?php
declare(strict_types=1);

/**
 * 前台 · 追踪（这就是时间线，也就是进行中）
 * ---------------------------------------------------------------------------
 * 选项卡：追踪中 / 已完成 / 全部
 * 左栏列表按分类分组；右栏详情分上（摘要）中（详情）下（操作）三段，
 * 操作区只在后台登录时显示。
 */

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/feed.php';
require __DIR__ . '/lib/view.php';

tl_boot();

$query = trim((string) ($_GET['q'] ?? ''));
$categoryRaw = (string) ($_GET['cat'] ?? '');
$categoryId = ctype_digit($categoryRaw) ? (int) $categoryRaw : null;

if (!tl_front_allowed()) {
    /* 回跳地址用绝对路径：登录页可能挂在别处，相对路径会被解析到别处去。 */
    $target = tl_front_url([
        'tab'  => $_GET['tab'] ?? null,
        'cat'  => $_GET['cat'] ?? null,
        'q'    => $_GET['q'] ?? null,
        'task' => $_GET['task'] ?? null,
        'date' => $_GET['date'] ?? null,
    ]);
    tl_redirect(tl_login_url(['back' => $target]));
}

/* 选项卡清单：id 的顺序就是 TL_FRONT_TABS 的顺序，地址生成靠它。 */
$tabMeta = [
    'tracked' => ['index' => '01', 'name' => '追踪中', 'latin' => 'TRACKED'],
    'planned' => ['index' => '02', 'name' => '规划中', 'latin' => 'PLANNED'],
    'done'    => ['index' => '03', 'name' => '已完成', 'latin' => 'COMPLETED'],
    'all'     => ['index' => '04', 'name' => '全部',   'latin' => 'ALL'],
];

$tabs = [];
foreach (TL_FRONT_TABS as $tabId) {
    $tabs[] = ['id' => $tabId] + $tabMeta[$tabId];
}

$tabNames = array_column($tabs, 'name', 'id');
$tab = (string) ($_GET['tab'] ?? 'tracked');
if (!in_array($tab, TL_FRONT_TABS, true)) {
    $tab = 'tracked';
}

$admin = tl_admin();
$categories = tl_categories();

$filters = [];
if ($categoryId !== null) {
    $filters['category'] = $categoryId;
}
if ($query !== '') {
    $filters['q'] = $query;
}

$counts = tl_front_counts($filters);
$tabCounts = [
    'tracked' => $counts['tracked_open'],
    'planned' => $counts['planned_open'],
    'done'    => $counts['done'],
    'all'     => $counts['all'],
];

/* 当前选中的任务 */
$selectedId = isset($_GET['task']) && ctype_digit((string) $_GET['task']) ? (int) $_GET['task'] : null;
$selectedDate = isset($_GET['date']) && $_GET['date'] !== '' ? (string) $_GET['date'] : null;

$selectedEntry = null;
if ($selectedId !== null) {
    $task = tl_task($selectedId);
    if ($task !== null) {
        $repeat = tl_task_repeat($task);
        $date = $selectedDate ?? $task['due_date'];
        $occurrenceMap = tl_occurrence_map([$selectedId]);
        $wholeDone = $task['status'] === 'done';
        if ($repeat === null) {
            $occurrence = false;
            $done = $wholeDone;
        } else {
            /* 整串已完成：详情里按「整条任务」操作，避免误用单次发生语义。 */
            $occurrence = $date !== null && !$wholeDone;
            $done = $wholeDone;
            if (!$wholeDone && $date !== null && isset($occurrenceMap[$selectedId . '|' . $date])) {
                $done = true;
            }
        }
        $selectedEntry = tl_entry($task, $date, $done, $occurrence, $occurrenceMap);
        $selectedEntry['subitems'] = tl_task_subitems($selectedId);
    }
}

$backUrl = tl_front_url(array_filter([
    'tab'  => $tab,
    'cat'  => $categoryId,
    'q'    => $query !== '' ? $query : null,
    'task' => $selectedId,
    'date' => $selectedDate,
], fn ($v) => $v !== null && $v !== ''));

$scheme = (string) ($_COOKIE['tl_scheme'] ?? 'light');
$scheme = in_array($scheme, ['light', 'dark'], true) ? $scheme : 'light';

tl_view_head(['title' => cfg('app.name') . ' · 追踪', 'scheme' => $scheme]);
?>
<div class="tl-shell">
  <?php tl_topbar('front'); ?>
  <?php tl_flash_view(); ?>

  <div class="tl-masthead">
    <div>
      <h1 class="tl-masthead__title"><?= e($tabNames[$tab]) ?></h1>
      <p class="tl-masthead__sub"><?= $admin ? '可以标记与追踪' : '公开只读' ?></p>
    </div>
    <button class="tl-mobile-toggle" type="button" data-mobile-panel="board"
            aria-expanded="false" aria-controls="tl-board">
      <span class="tl-fold__chevron" aria-hidden="true"></span>
      <span>看板</span>
      <span class="tl-mobile-toggle__value"><?= (int) $tabCounts[$tab] ?> 条 · <?= $counts['tracked'] ?> 追踪中</span>
    </button>

    <div class="tl-readouts" id="tl-board" data-mobile-body="board">
      <div class="tl-readout tl-readout--accent">
        <span class="tl-readout__value"><?= $counts['active'] ?></span>
        <span class="tl-readout__label">进行中</span>
      </div>
      <div class="tl-readout">
        <span class="tl-readout__value"><?= $counts['planned'] ?></span>
        <span class="tl-readout__label">规划中</span>
      </div>
      <div class="tl-readout">
        <span class="tl-readout__value"><?= $counts['tracked'] ?></span>
        <span class="tl-readout__label">追踪</span>
      </div>
      <div class="tl-readout">
        <span class="tl-readout__value"><?= $counts['done'] ?></span>
        <span class="tl-readout__label">已完成</span>
      </div>
      <div class="tl-readout">
        <span class="tl-readout__value"><?= $counts['all'] ?></span>
        <span class="tl-readout__label">全部</span>
      </div>
    </div>
  </div>

  <nav class="tl-tabs" role="tablist" aria-label="任务视图">
    <?php foreach ($tabs as $item): ?>
      <a class="tl-tab"
         role="tab"
         href="<?= e(tl_front_url(array_filter([
             'tab' => $item['id'],
             'cat' => $categoryId,
             'q'   => $query !== '' ? $query : null,
         ], fn ($v) => $v !== null && $v !== ''))) ?>"
         aria-selected="<?= $item['id'] === $tab ? 'true' : 'false' ?>">
        <span class="tl-tab__index"><?= e($item['index']) ?></span>
        <span><?= e($item['name']) ?></span>
        <span class="tl-tab__count"><?= (int) $tabCounts[$item['id']] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php // 批量操作表单：复选框通过 form 属性挂在这里，行本身仍是链接 ?>
  <?php if ($admin): ?>
    <form id="bulk-form" method="post" action="<?= e(tl_action_url()) ?>">
      <?= tl_csrf_field() ?>
      <input type="hidden" name="op" value="bulk">
      <input type="hidden" name="back" value="<?= e($backUrl) ?>">
    </form>
  <?php endif; ?>

  <button class="tl-mobile-toggle" type="button" data-mobile-panel="filter"
          aria-expanded="false" aria-controls="tl-filter">
    <span class="tl-fold__chevron" aria-hidden="true"></span>
    <span>筛选</span>
    <span class="tl-mobile-toggle__value"><?= $categoryId !== null ? e(tl_category($categoryId)['name'] ?? '分类') : '全部分类' ?><?= $query !== '' ? ' · ' . e(tl_str_limit($query, 12)) : '' ?></span>
  </button>

  <form class="tl-toolbar" id="tl-filter" data-mobile-body="filter" method="get" action="<?= e(tl_front_url(['tab' => $tab])) ?>" data-filter-form>
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <label class="tl-field">
      <span class="tl-field__label">分类</span>
      <select class="tl-select" name="cat" data-auto-submit>
        <option value="">全部分类</option>
        <?php foreach ($categories as $category): ?>
          <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
            <?= e($category['name']) ?> (<?= (int) $category['task_count'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="tl-field">
      <span class="tl-field__label">搜索</span>
      <input class="tl-search" type="search" name="q" value="<?= e($query) ?>"
             placeholder="摘要 / 详情 / 分类" autocomplete="off">
    </label>

    <button class="eds-button" type="submit">筛选</button>
    <?php if ($categoryId !== null || $query !== ''): ?>
      <a class="tl-clear" href="<?= e(tl_front_url(['tab' => $tab])) ?>">清除条件</a>
    <?php endif; ?>

    <span class="tl-toolbar__spacer"></span>
    <span class="tl-filter-note">
      <?php if ($tab === 'tracked'): ?>
        追踪 <strong><?= $counts['tracked'] ?></strong> 条
      <?php else: ?>
        共 <strong><?= (int) $tabCounts[$tab] ?></strong> 条
      <?php endif; ?>
    </span>
  </form>

  <div class="tl-workspace">
    <main class="tl-main">
      <div class="tl-main__scroll">
        <?php if ($tab === 'tracked'): ?>
          <?php $trackedFeed = tl_front_tracked($filters); ?>
          <?php tl_sections_view(tl_prepend_pinned($trackedFeed['sections'], tl_front_pinned($filters, 'tracked', $trackedFeed['week_parents'])), $admin, $selectedId); ?>
        <?php elseif ($tab === 'planned'): ?>
          <?php $plannedFeed = tl_front_scope('planned', $filters); ?>
          <?php tl_sections_view(tl_prepend_pinned($plannedFeed['sections'], tl_front_pinned($filters, 'planned')), $admin, $selectedId); ?>
        <?php else: ?>
          <?php $scope = tl_front_scope($tab, $filters); ?>
          <?php tl_entries_view($scope['groups'], $admin, $admin, $selectedId, '这个选项卡里没有事项'); ?>
        <?php endif; ?>
      </div>

      <?php if ($admin): ?>
        <div class="tl-bulk" id="bulk-bar" hidden>
          <span class="tl-bulk__count">已选 <strong data-bulk-count>0</strong> 项</span>
          <button class="eds-button" type="submit" form="bulk-form" name="bulk_op" value="status:active">设为进行中</button>
          <button class="eds-button" type="submit" form="bulk-form" name="bulk_op" value="status:planned">设为规划中</button>
          <button class="eds-button eds-button--solid" type="submit" form="bulk-form" name="bulk_op" value="status:done">设为已完成</button>
          <span class="tl-toolbar__spacer"></span>
          <button class="eds-button eds-button--danger" type="submit" form="bulk-form" name="bulk_op" value="delete"
                  data-confirm="删除选中的待办事项？此操作不可撤销。" data-confirm-title="删除待办">删除</button>
          <button class="tl-ghostlink" type="button" data-bulk-clear>取消选择</button>
        </div>
      <?php endif; ?>
    </main>

    <?php tl_detail_view($selectedEntry, $admin, $backUrl); ?>
  </div>

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
</body>
</html>
