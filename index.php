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
    'tracked' => ['name' => '追踪中', 'latin' => 'TRACKED'],
    'planned' => ['name' => '未追踪', 'latin' => 'UNTRACKED'],
    'done'    => ['name' => '已完成', 'latin' => 'COMPLETED'],
    'all'     => ['name' => '全部',   'latin' => 'ALL'],
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
        $occurrenceMap = $date === null ? [] : tl_occurrence_map([$selectedId], $date, $date);
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

$frontParams = array_filter([
    'tab'  => $tab,
    'cat'  => $categoryId,
    'q'    => $query !== '' ? $query : null,
    'task' => $selectedId,
    'date' => $selectedDate,
], fn ($v) => $v !== null && $v !== '');
$backUrl = tl_front_url($frontParams);
$editUrl = $admin && $selectedEntry !== null
    ? tl_admin_url(['edit' => $selectedId, 'from' => array_diff_key($frontParams, ['task' => true])])
    : '';

$scheme = tl_scheme();

$flash = tl_flash_take();
tl_view_head(['title' => cfg('app.name') . ' · 任务看板', 'scheme' => $scheme]);
?>
<div class="tl-shell">
  <?php tl_topbar('front'); ?>
  <?php tl_flash_view($flash); ?>

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
        <span><?= e($item['name']) ?></span>
        <span class="tl-tab__count"><?= (int) $tabCounts[$item['id']] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($admin): ?>
    <form id="bulk-form" hidden></form>
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

    <button class="tl-action eds-button" type="submit">筛选</button>
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
          <?php
          $trackedTasks = tl_tasks($filters + ['tracked' => true, 'not_done' => true]);
          $trackedFeed = tl_front_tracked($filters, $trackedTasks);
          ?>
          <?php tl_sections_view(tl_prepend_pinned($trackedFeed['sections'], tl_front_pinned($filters, 'tracked', $trackedFeed['due_subitems'], $trackedTasks)), $selectedId); ?>
        <?php elseif ($tab === 'planned'): ?>
          <?php $plannedFeed = tl_front_scope('planned', $filters); ?>
          <?php tl_sections_view(tl_prepend_pinned($plannedFeed['sections'], tl_front_pinned($filters, 'planned')), $selectedId); ?>
        <?php else: ?>
          <?php $scope = tl_front_scope($tab, $filters); ?>
          <?php tl_entries_view($scope['groups'], $selectedId, '这个选项卡里没有事项'); ?>
        <?php endif; ?>
      </div>

    </main>

    <?php tl_detail_view($selectedEntry, $admin, $backUrl, $editUrl); ?>
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
