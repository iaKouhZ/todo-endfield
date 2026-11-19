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

$categories = tl_categories();
$now = tl_now();

/* ---------------------------------------------------------------- 编辑对象 */
$editTask = null;
$editSubitems = [];
if (!empty($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editTask = tl_task((int) $_GET['edit']);
    if ($editTask !== null) {
        $editSubitems = tl_task_subitems((int) $editTask['id']);
    }
}

/* ---------------------------------------------------------------- 列表筛选 */
$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'planned', 'done'], true)) {
    $statusFilter = 'all';
}
$catFilter = (string) ($_GET['cat'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$trackedOnly = !empty($_GET['tracked']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;

$listFilters = ['status' => $statusFilter, 'order' => 'created'];
if ($catFilter === 'none') {
    $listFilters['category'] = 'none';
} elseif (ctype_digit($catFilter)) {
    $listFilters['category'] = (int) $catFilter;
}
if ($search !== '') {
    $listFilters['q'] = $search;
}
if ($trackedOnly) {
    $listFilters['tracked'] = true;
}

$totalTasks = (int) tl_task_count($listFilters);
$pages = max(1, (int) ceil($totalTasks / $perPage));
$page = min($page, $pages);
$pageTasks = tl_tasks($listFilters + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);

$globalCounts = tl_task_counts_sql();
$scheme = (string) ($_COOKIE['tl_scheme'] ?? 'light');
$scheme = in_array($scheme, ['light', 'dark'], true) ? $scheme : 'light';

/* 后台当前这一页的全部参数：view / edit 进路径，其余留查询串。 */
$adminParams = array_filter($_GET, fn ($v) => is_string($v) && $v !== '');

$backUrl = tl_admin_url($adminParams);

/* 取消编辑：回到同一个列表（保留筛选），但不带 edit，右侧就换回新增表单。 */
$listUrl = tl_admin_url(array_diff_key($adminParams, ['edit' => true]));

function tl_admin_empty_subitem_row(): void
{
    ?>
    <div class="tl-subitems-row">
      <input type="hidden" name="subitem_id[0]" value="0">
      <span class="tl-subitems-done tl-subitems-done--spacer" aria-hidden="true"></span>
      <input class="tl-form__input" type="text" name="subitem_title[0]" maxlength="200" placeholder="可选子任务">
      <?php tl_subitem_deadline_fields([], 0); ?>
      <button class="tl-ghostlink" type="button" data-subitem-remove>移除</button>
    </div>
    <?php
}

tl_view_head(['title' => cfg('app.name') . ' · 后台', 'scheme' => $scheme]);
?>
<div class="tl-shell">
  <?php tl_topbar('admin'); ?>
  <?php tl_flash_view(); ?>

  <div class="tl-masthead">
    <div>
      <h1 class="tl-masthead__title">工作台</h1>
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
        'tasks'      => ['index' => '01', 'name' => '待办事项'],
        'categories' => ['index' => '02', 'name' => '分类设置'],
        'system'     => ['index' => '03', 'name' => '维护'],
    ];
    foreach (TL_ADMIN_VIEWS as $adminView) {
        $item = ['id' => $adminView] + $adminMeta[$adminView];
        ?>
      <a class="tl-tab" role="tab"
         href="<?= e(tl_admin_url(['view' => $item['id']])) ?>"
         aria-selected="<?= $view === $item['id'] ? 'true' : 'false' ?>">
        <span class="tl-tab__index"><?= e($item['index']) ?></span>
        <span><?= e($item['name']) ?></span>
      </a>
    <?php } ?>
  </nav>

  <?php if ($view === 'tasks'): ?>
    <!-- ================================================================ 待办 -->
    <div class="tl-admin-grid">
      <main class="tl-admin-main">
        <form class="tl-toolbar" method="get" action="<?= e(tl_admin_url(['view' => 'tasks'])) ?>" data-filter-form>
          <label class="tl-field">
            <span class="tl-field__label">状态</span>
            <select class="tl-select" name="status" data-auto-submit>
              <?php foreach (['all' => '全部', 'active' => '进行中', 'planned' => '规划中', 'done' => '已完成'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="tl-field">
            <span class="tl-field__label">分类</span>
            <select class="tl-select" name="cat" data-auto-submit>
              <option value="">全部分类</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $catFilter === (string) $category['id'] ? 'selected' : '' ?>>
                  <?= e($category['name']) ?> (<?= (int) $category['task_count'] ?>)
                </option>
              <?php endforeach; ?>
              <option value="none" <?= $catFilter === 'none' ? 'selected' : '' ?>>未分类</option>
            </select>
          </label>
          <label class="tl-field">
            <span class="tl-field__label">搜索</span>
            <input class="tl-search" type="search" name="q" value="<?= e($search) ?>" placeholder="摘要 / 详情 / 分类">
          </label>
          <label class="tl-field">
            <span class="tl-field__label">追踪</span>
            <select class="tl-select" name="tracked" data-auto-submit>
              <option value="">不限</option>
              <option value="1" <?= $trackedOnly ? 'selected' : '' ?>>仅追踪中</option>
            </select>
          </label>
          <button class="eds-button" type="submit">筛选</button>
          <span class="tl-toolbar__spacer"></span>
          <span class="tl-filter-note">
            共 <strong><?= $totalTasks ?></strong> 条<?= $pages > 1 ? ' · 第 ' . $page . '/' . $pages . ' 页' : '' ?>
          </span>
        </form>

          <table class="tl-table tl-table--clickable">
            <thead>
              <tr>
                <th class="tl-col-check"><input class="tl-table__check" type="checkbox" data-check-all aria-label="全选"></th>
                <th>摘要</th>
                <th class="tl-col-category">分类</th>
                <th class="tl-col-deadline">截止</th>
                <th class="tl-col-status">状态</th>
                <th class="tl-col-task-actions">操作</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($pageTasks === []): ?>
                <tr><td colspan="6">
                  <div class="tl-empty">
                    <span>没有符合条件的待办。用右侧表单新增一条。</span>
                  </div>
                </td></tr>
              <?php endif; ?>
              <?php foreach ($pageTasks as $task): ?>
                <?php
                $id = (int) $task['id'];
                $repeat = tl_task_repeat($task);
                $due = tl_parse_due($task);
                /* 整行就是编辑入口：点哪里都进右侧表单，和前台点行进详情一致。 */
                $editUrl = tl_admin_url(array_filter([
                    'view'    => 'tasks',
                    'status'  => $statusFilter !== 'all' ? $statusFilter : null,
                    'cat'     => $catFilter !== '' ? $catFilter : null,
                    'q'       => $search !== '' ? $search : null,
                    'tracked' => $trackedOnly ? '1' : null,
                    'edit'    => $id,
                ], fn ($v) => $v !== null && $v !== ''));
                $rowSelected = $editTask !== null && (int) $editTask['id'] === $id;
                ?>
                <tr class="<?= $rowSelected ? 'tl-table__row--selected' : '' ?>" data-row-link="<?= e($editUrl) ?>">
                  <td><input class="tl-table__check" type="checkbox" name="ids[]" value="<?= $id ?>"
                             form="admin-bulk" data-check-item aria-label="选择：<?= e($task['title']) ?>"></td>
                  <td>
                    <a class="tl-table__link" href="<?= e($editUrl) ?>">
                      <span class="tl-table__title">
                        <strong><?= e($task['title']) ?></strong>
                        <span>
                          #<?= $id ?>
                          <?php if ((int) $task['pinned'] === 1): ?> · 置顶<?php endif; ?>
                          <?php if ((int) $task['tracked'] === 1): ?> · 追踪<?php endif; ?>
                          <?php if ($repeat !== null): ?> · <?= e(tl_repeat_label($repeat['unit'], $repeat['interval'])) ?><?php endif; ?>
                          <?php if (!empty($task['detail'])): ?> · 有详情<?php endif; ?>
                        </span>
                      </span>
                    </a>
                  </td>
                  <td><?= e($task['category_name'] ?? '未分类') ?></td>
                  <td class="tl-table__num">
                    <?php if ($due !== null): ?>
                      <?= e($due->format('m-d')) ?><?= !empty($task['due_time']) ? ' ' . e($task['due_time']) : '' ?>
                    <?php else: ?>
                      <span class="tl-text-dimmed">—</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="tl-status tl-status--<?= e($task['status']) ?>"><?= e(tl_status_label($task['status'])) ?></span></td>
                  <td>
                    <div class="tl-table__actions">
                      <form class="tl-form-inline" method="post" action="<?= e(tl_action_url()) ?>">
                        <?= tl_csrf_field() ?>
                        <input type="hidden" name="op" value="task_delete">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="back" value="<?= e($backUrl) ?>">
                        <button class="tl-danger" type="submit"
                                data-confirm="删除「<?= e($task['title']) ?>」？此操作不可撤销。"
                                data-confirm-title="删除待办">删</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

        <form id="admin-bulk" method="post" action="<?= e(tl_action_url()) ?>">
          <?= tl_csrf_field() ?>
          <input type="hidden" name="op" value="bulk">
          <input type="hidden" name="back" value="<?= e($backUrl) ?>">
          <div class="tl-bulk-static tl-bulk" <?= $pageTasks === [] ? 'hidden' : '' ?> data-admin-bulk>
            <span class="tl-bulk__count">已选 <strong data-admin-count>0</strong> 项</span>
            <button class="eds-button" type="submit" name="bulk_op" value="status:active">设为进行中</button>
            <button class="eds-button" type="submit" name="bulk_op" value="status:planned">设为规划中</button>
            <button class="eds-button eds-button--solid" type="submit" name="bulk_op" value="status:done">设为已完成（重复任务整串）</button>
            <button class="eds-button eds-button--danger" type="submit" name="bulk_op" value="delete"
                    data-confirm="删除选中的待办？此操作不可撤销。" data-confirm-title="批量删除">删除选中</button>
          </div>
        </form>

        <?php if ($pages > 1): ?>
          <div class="tl-toolbar-flat tl-toolbar">
            <?php
            $baseQuery = array_filter([
                'status'  => $statusFilter !== 'all' ? $statusFilter : null,
                'cat'     => $catFilter !== '' ? $catFilter : null,
                'q'       => $search !== '' ? $search : null,
                'tracked' => $trackedOnly ? '1' : null,
            ], fn ($v) => $v !== null);
            ?>
            <?php for ($p = 1; $p <= $pages; $p++): ?>
              <a class="tl-clear-compact eds-button <?= $p === $page ? 'eds-button--solid' : '' ?>"
                 href="<?= e(tl_admin_url($baseQuery + ['view' => 'tasks', 'page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </main>

      <aside class="tl-aside">
        <?php if ($editTask !== null): ?>
          <form class="tl-card tl-card--raised" method="post" action="<?= e(tl_action_url()) ?>" data-filter-form>
            <?= tl_csrf_field() ?>
            <input type="hidden" name="op" value="task_save">
            <input type="hidden" name="id" value="<?= (int) $editTask['id'] ?>">
            <input type="hidden" name="back" value="<?= e($backUrl) ?>">

            <div class="tl-card__head">
              <span class="tl-card__title">编辑待办</span>
              <span class="tl-card__latin">#<?= (int) $editTask['id'] ?></span>
              <a class="tl-push-right tl-clear tl-clear--lg" href="<?= e($listUrl) ?>">取消</a>
            </div>

            <div class="tl-form">
              <div class="tl-form__row">
                <label class="tl-form__label" for="f-title">摘要 <span>必填 · 最多 140 字</span></label>
                <input class="tl-form__input" id="f-title" type="text" name="title" required maxlength="140"
                       value="<?= e($editTask['title']) ?>">
              </div>

              <div class="tl-form__row">
                <label class="tl-form__label" for="f-detail">详情 <span>可留空</span></label>
                <textarea class="tl-form__textarea" id="f-detail" name="detail"
                          placeholder="留空时前台详情区显示摘要"><?= e($editTask['detail'] ?? '') ?></textarea>
              </div>

              <div class="tl-form__row">
                <label class="tl-form__label" for="f-cat">分类</label>
                <select class="tl-form__select" id="f-cat" name="category_id">
                  <option value="">未分类</option>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"
                      <?= (int) $editTask['category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                      <?= e($category['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="tl-form__grid">
                <div class="tl-form__row">
                  <label class="tl-form__label" for="f-date">截止日期</label>
                  <input class="tl-form__input" id="f-date" type="date" name="due_date"
                         value="<?= e($editTask['due_date'] ?? '') ?>">
                </div>
                <div class="tl-form__row">
                  <label class="tl-form__label" for="f-time">截止时间</label>
                  <input class="tl-form__input" id="f-time" type="time" name="due_time"
                         value="<?= e($editTask['due_time'] ?? '') ?>">
                </div>
              </div>

              <div class="tl-form__grid">
                <div class="tl-form__row">
                  <label class="tl-form__label" for="f-unit">重复</label>
                  <select class="tl-form__select" id="f-unit" name="repeat_unit">
                    <option value="">不重复</option>
                    <option value="day" <?= $editTask['repeat_unit'] === 'day' ? 'selected' : '' ?>>每 N 天</option>
                    <option value="week" <?= $editTask['repeat_unit'] === 'week' ? 'selected' : '' ?>>每 N 周</option>
                    <option value="month" <?= $editTask['repeat_unit'] === 'month' ? 'selected' : '' ?>>每 N 个月</option>
                  </select>
                </div>
                <div class="tl-form__row">
                  <label class="tl-form__label" for="f-interval">间隔</label>
                  <input class="tl-form__input" id="f-interval" type="number" name="repeat_interval" min="1" max="365"
                         value="<?= e((string) ($editTask['repeat_interval'] ?? 1)) ?>">
                </div>
              </div>

              <?php if ($editTask['repeat_unit']): ?>
                <div class="tl-form__row">
                  <label class="tl-check-flex tl-check">
                    <input type="checkbox" name="repeat_change_confirm" value="1">
                    <span>确认调整重复规则（可能删除不再符合新规则的单日完成记录）</span>
                  </label>
                </div>
              <?php endif; ?>

              <div class="tl-form__grid">
                <div class="tl-form__row">
                  <label class="tl-form__label" for="f-status">状态</label>
                  <select class="tl-form__select" id="f-status" name="status">
                    <?php foreach (TL_STATUSES as $value => $label): ?>
                      <option value="<?= e($value) ?>" <?= $editTask['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="tl-form__row">
                  <span class="tl-form__label">追踪</span>
                  <label class="tl-switch">
                    <input type="checkbox" name="tracked" value="1" <?= (int) $editTask['tracked'] === 1 ? 'checked' : '' ?> id="f-tracked">
                    <span class="tl-switch__text" data-on="追踪中" data-off="未追踪"><?= (int) $editTask['tracked'] === 1 ? '追踪中' : '未追踪' ?></span>
                  </label>
                </div>
              </div>

              <?php if ($editTask['status'] === 'done'): ?>
                <?php tl_completion_field($editTask['completed_at']); ?>
              <?php endif; ?>

              <div class="tl-form__row">
                <span class="tl-form__label">置顶</span>
                <label class="tl-switch">
                  <input type="checkbox" name="pinned" value="1" <?= (int) $editTask['pinned'] === 1 ? 'checked' : '' ?> id="f-pinned">
                  <span class="tl-switch__text" data-on="已置顶" data-off="未置顶"><?= (int) $editTask['pinned'] === 1 ? '已置顶' : '未置顶' ?></span>
                </label>
              </div>

              <div class="tl-form__row">
                <span class="tl-form__label">子任务</span>
                <div class="tl-subitems-editor" id="subitems-editor" data-subitems-editor>
                  <?php foreach ($editSubitems as $idx => $sub): ?>
                    <?php $subRowId = (int) $sub['id']; ?>
                    <div class="tl-subitems-row">
                      <input type="hidden" name="subitem_id[<?= (int) $idx ?>]" value="<?= $subRowId ?>">
                      <?php if ($subRowId > 0): ?>
                        <label class="tl-subitems-done">
                          <input class="tl-table__check" type="checkbox" name="subitem_done[<?= (int) $idx ?>]" value="1" <?= (int) $sub['done'] === 1 ? 'checked' : '' ?>
                                 <?= $editTask['status'] === 'done' ? 'disabled' : '' ?> aria-label="子任务已完成">
                        </label>
                      <?php else: ?>
                        <span class="tl-subitems-done tl-subitems-done--spacer" aria-hidden="true"></span>
                      <?php endif; ?>
                      <input class="tl-form__input" type="text" name="subitem_title[<?= (int) $idx ?>]" maxlength="200" value="<?= e((string) $sub['title']) ?>"
                             <?= $editTask['status'] === 'done' ? 'readonly' : '' ?>>
                      <?php tl_subitem_deadline_fields($sub, $idx, $editTask['status'] === 'done'); ?>
                      <button class="tl-ghostlink" type="button" data-subitem-remove>移除</button>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($editSubitems === [] && $editTask['status'] !== 'done'): ?>
                    <?php tl_admin_empty_subitem_row(); ?>
                  <?php endif; ?>
                </div>
                <?php if ($editTask['status'] !== 'done'): ?>
                  <button type="button" class="eds-button tl-subitems-add" data-subitem-add-row data-subitem-editor="subitems-editor">添加子任务行</button>
                  <p class="tl-note-spaced tl-note">主任务标为已完成时，所有子任务将显示为已完成。</p>
                <?php else: ?>
                  <p class="tl-note-spaced tl-note">主任务已完成，子任务已全部勾选。</p>
                <?php endif; ?>
              </div>

              <div class="tl-form__actions">
                <button class="eds-button eds-button--solid" type="submit">保存修改</button>
                <a class="eds-button tl-cancel-btn" href="<?= e($listUrl) ?>">取消</a>
              </div>
            </div>
          </form>
        <?php else: ?>
          <form class="tl-card tl-card--raised" method="post" action="<?= e(tl_action_url()) ?>" data-filter-form>
            <?= tl_csrf_field() ?>
            <input type="hidden" name="op" value="task_save">
            <input type="hidden" name="back" value="<?= e($backUrl) ?>">

            <div class="tl-card__head">
              <span class="tl-card__title">新增待办</span>
            </div>

            <div class="tl-form">
              <div class="tl-form__row">
                <label class="tl-form__label" for="n-title">摘要 <span>必填</span></label>
                <input class="tl-form__input" id="n-title" type="text" name="title" required maxlength="140"
                       placeholder="要做什么？">
              </div>

              <div class="tl-form__row">
                <label class="tl-form__label" for="n-detail">详情 <span>可留空</span></label>
                <textarea class="tl-form__textarea" id="n-detail" name="detail" placeholder="补充说明、清单、链接…"></textarea>
              </div>

              <div class="tl-form__row">
                <label class="tl-form__label" for="n-cat">分类</label>
                <select class="tl-form__select" id="n-cat" name="category_id">
                  <option value="">未分类</option>
                  <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="tl-form__grid">
                <div class="tl-form__row">
                  <label class="tl-form__label" for="n-date">截止日期</label>
                  <input class="tl-form__input" id="n-date" type="date" name="due_date">
                </div>
                <div class="tl-form__row">
                  <label class="tl-form__label" for="n-time">截止时间</label>
                  <input class="tl-form__input" id="n-time" type="time" name="due_time">
                </div>
              </div>

              <div class="tl-form__grid">
                <div class="tl-form__row">
                  <label class="tl-form__label" for="n-unit">重复</label>
                  <select class="tl-form__select" id="n-unit" name="repeat_unit">
                    <option value="">不重复</option>
                    <option value="day">每 N 天</option>
                    <option value="week">每 N 周</option>
                    <option value="month">每 N 个月</option>
                  </select>
                </div>
                <div class="tl-form__row">
                  <label class="tl-form__label" for="n-interval">间隔</label>
                  <input class="tl-form__input" id="n-interval" type="number" name="repeat_interval" min="1" max="365" value="1">
                </div>
              </div>

              <div class="tl-form__grid">
                <div class="tl-form__row">
                  <label class="tl-form__label" for="n-status">状态</label>
                  <select class="tl-form__select" id="n-status" name="status">
                    <option value="active">进行中</option>
                    <option value="planned">规划中</option>
                  </select>
                </div>
                <div class="tl-form__row">
                  <span class="tl-form__label">追踪</span>
                  <label class="tl-switch">
                    <input type="checkbox" name="tracked" value="1" id="n-tracked">
                    <span class="tl-switch__text" data-on="追踪中" data-off="未追踪">未追踪</span>
                  </label>
                </div>
              </div>

              <div class="tl-form__row">
                <span class="tl-form__label">置顶</span>
                <label class="tl-switch">
                  <input type="checkbox" name="pinned" value="1" id="n-pinned">
                  <span class="tl-switch__text" data-on="已置顶" data-off="未置顶">未置顶</span>
                </label>
              </div>

              <div class="tl-form__row">
                <span class="tl-form__label">子任务</span>
                <div class="tl-subitems-editor" id="subitems-editor-new" data-subitems-editor>
                  <?php tl_admin_empty_subitem_row(); ?>
                </div>
                <button type="button" class="eds-button tl-subitems-add" data-subitem-add-row data-subitem-editor="subitems-editor-new">添加子任务行</button>
              </div>

              <div class="tl-form__actions">
                <button class="eds-button eds-button--solid" type="submit">添加待办</button>

              </div>
            </div>
          </form>
        <?php endif; ?>

        <div class="tl-card">
          <div class="tl-card__head">
            <span class="tl-card__title">分类速览</span>
            <a class="tl-push-right tl-clear" href="<?= e(tl_admin_url(['view' => 'categories'])) ?>">设置</a>
          </div>
          <?php foreach ($categories as $category): ?>
            <div class="tl-cat-row">
              <span class="tl-cat-row__name"><?= e($category['name']) ?></span>
              <span class="tl-cat-row__count"><?= (int) $category['task_count'] ?></span>
              <span class="tl-cat-row__actions">
                <a href="<?= e(tl_admin_url(['view' => 'tasks', 'cat' => (int) $category['id']])) ?>">查看</a>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="tl-card">
          <div class="tl-card__head">
            <span class="tl-card__title">清理</span>
          </div>
          <form method="post" action="<?= e(tl_action_url()) ?>">
            <?= tl_csrf_field() ?>
            <input type="hidden" name="op" value="purge">
            <input type="hidden" name="back" value="<?= e($backUrl) ?>">
            <button class="eds-button eds-button--danger" type="submit"
                    data-confirm="清除所有完成超过 <?= (int) cfg('purge_days', 7) ?> 天的待办？此操作不可撤销。"
                    data-confirm-title="清除已完成">执行清理</button>
          </form>
        </div>
      </aside>
    </div>

  <?php elseif ($view === 'categories'): ?>
    <!-- ================================================================ 分类 -->
    <div class="tl-admin-grid">
      <main class="tl-admin-main">
        <div class="tl-card">
          <div class="tl-card__head">
            <span class="tl-card__title">分类列表</span>
            <span class="tl-push-right tl-filter-note"><?= count($categories) ?> 个分类</span>
          </div>

          <table class="tl-table">
            <thead>
              <tr>
                <th>名称</th>
                <th class="tl-col-count">待办数</th>
                <th class="tl-col-category-actions">操作</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($categories as $category): ?>
                <tr>
                  <td>
                    <form method="post" action="<?= e(tl_action_url()) ?>" class="tl-form-flush tl-inline-form">
                      <?= tl_csrf_field() ?>
                      <input type="hidden" name="op" value="cat_save">
                      <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                      <input type="hidden" name="back" value="<?= e(tl_admin_url(['view' => 'categories'])) ?>">
                      <input class="tl-category-name-input tl-input" type="text" name="name" value="<?= e($category['name']) ?>"
                             maxlength="24" aria-label="分类名称">
                      <button class="eds-button" type="submit">重命名</button>
                    </form>
                  </td>
                  <td class="tl-table__num"><?= (int) $category['task_count'] ?></td>
                  <td>
                    <div class="tl-table__actions">
                      <a href="<?= e(tl_admin_url(['view' => 'tasks', 'cat' => (int) $category['id']])) ?>">查看</a>
                      <a href="#" data-open-cat-delete
                         data-cat-id="<?= (int) $category['id'] ?>"
                         data-cat-name="<?= e($category['name']) ?>"
                         data-cat-count="<?= (int) $category['task_count'] ?>"
                         data-cat-action="delete-category">删除</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </main>

      <aside class="tl-aside">
        <form class="tl-card tl-card--raised" method="post" action="<?= e(tl_action_url()) ?>">
          <?= tl_csrf_field() ?>
          <input type="hidden" name="op" value="cat_save">
          <input type="hidden" name="back" value="<?= e(tl_admin_url(['view' => 'categories'])) ?>">
          <div class="tl-card__head">
            <span class="tl-card__title">新增分类</span>
          </div>
          <div class="tl-form">
            <div class="tl-form__row">
              <label class="tl-form__label" for="c-name">名称 <span>最多 24 字</span></label>
              <input class="tl-form__input" id="c-name" type="text" name="name" maxlength="24" required placeholder="例如：科研 / 健身">
            </div>
            <div class="tl-form__actions">
              <button class="eds-button eds-button--solid" type="submit">添加分类</button>
            </div>
          </div>
        </form>

      </aside>
    </div>

    <!-- 删除分类对话框：自带表单，不用脚本拼接 action -->
    <dialog class="tl-dialog" id="cat-dialog">
      <div class="tl-dialog__band">
        <div class="tl-dialog__header">
          <span class="tl-dialog__title">删除分类「<span data-cat-name-label></span>」</span>
        </div>
        <div class="tl-dialog__body">
          <p>
            该分类下有 <strong data-cat-count-label>0</strong> 条待办。请选择它们的去向：
          </p>
          <form class="tl-form-flush" method="post" action="<?= e(tl_action_url()) ?>" id="cat-delete-form">
            <?= tl_csrf_field() ?>
            <input type="hidden" name="op" value="cat_delete">
            <input type="hidden" name="id" value="" data-cat-id-field>
            <input type="hidden" name="back" value="<?= e(tl_admin_url(['view' => 'categories'])) ?>">

            <div class="tl-choice">
              <label class="tl-choice__option">
                <input type="radio" name="strategy" value="uncategorize" checked>
                <span>
                  <span class="tl-choice__label">归为未分类</span>
                  <span class="tl-choice__hint">待办保留，分类字段清空，仍可在「未分类」下找到。</span>
                </span>
              </label>
              <label class="tl-choice__option">
                <input type="radio" name="strategy" value="delete">
                <span>
                  <span class="tl-choice__label"><strong>一并删除</strong>分类下的待办</span>
                  <span class="tl-choice__hint">这些待办会被永久删除，无法恢复。</span>
                </span>
              </label>
            </div>
          </form>
        </div>

        <?php // 动作行必须是纸带的直接子元素：它靠负 margin 骑在下缘，
               // 放进 overflow:auto 的 body 里会被裁掉。 ?>
        <div class="tl-dialog__actions">
          <button class="eds-capsule eds-capsule--dark eds-capsule--cancel" type="button" data-cat-cancel>取消</button>
          <button class="eds-capsule eds-capsule--accent eds-capsule--confirm" type="submit"
                  form="cat-delete-form">删除分类</button>
        </div>
      </div>
    </dialog>

  <?php else: ?>
    <!-- ================================================================ 维护 -->
    <div class="tl-admin-grid">
      <main class="tl-admin-main">
        <div class="tl-card">
          <div class="tl-card__head">
            <span class="tl-card__title">数据概况</span>
          </div>
          <div class="tl-metric"><span>全部待办</span><strong><?= (int) $globalCounts['all'] ?></strong></div>
          <div class="tl-metric"><span>进行中</span><strong><?= (int) $globalCounts['active'] ?></strong></div>
          <div class="tl-metric"><span>规划中</span><strong><?= (int) $globalCounts['planned'] ?></strong></div>
          <div class="tl-metric"><span>已完成</span><strong><?= (int) $globalCounts['done'] ?></strong></div>
          <div class="tl-metric"><span>追踪中（未完成）</span><strong><?= (int) $globalCounts['tracked'] ?></strong></div>
          <div class="tl-metric"><span>分类</span><strong><?= count($categories) ?></strong></div>
        </div>

        <div class="tl-card">
          <div class="tl-card__head">
            <span class="tl-card__title">配置摘要</span>
          </div>
          <div class="tl-metric"><span>时区</span><strong class="tl-mono"><?= e(cfg('app.timezone')) ?></strong></div>
          <div class="tl-metric"><span>前台访问</span><strong><?= tl_front_public() ? '公开' : '需要密码' ?></strong></div>
          <div class="tl-metric"><span>后台访问</span><strong>始终需要密码</strong></div>
          <div class="tl-metric"><span>追踪窗口</span><strong>今天 — <?= (int) cfg('front.week_to') ?>天后</strong></div>
          <div class="tl-metric"><span>清理阈值</span><strong><?= (int) cfg('purge_days') ?> 天</strong></div>
          <p class="tl-note">改口令或访问策略请编辑 <code>config.php</code>。</p>
        </div>
      </main>

      <aside class="tl-aside">
        <div class="tl-card tl-card--raised">
          <div class="tl-card__head">
            <span class="tl-card__title">一键清理</span>
          </div>
          <p class="tl-card__note">已完成且超过 <?= (int) cfg('purge_days') ?> 天的待办会被删除。</p>
          <form method="post" action="<?= e(tl_action_url()) ?>">
            <?= tl_csrf_field() ?>
            <input type="hidden" name="op" value="purge">
            <input type="hidden" name="back" value="<?= e(tl_admin_url(['view' => 'system'])) ?>">
            <button class="eds-button eds-button--danger" type="submit"
                    data-confirm="清除完成超过 <?= (int) cfg('purge_days') ?> 天的待办？此操作不可撤销。"
                    data-confirm-title="清除已完成">执行清理</button>
          </form>
        </div>

        <div class="tl-card">
          <div class="tl-card__head">
            <span class="tl-card__title">会话</span>
          </div>
          <form method="post" action="<?= e(tl_action_url()) ?>" data-no-pjax>
            <?= tl_csrf_field() ?>
            <input type="hidden" name="op" value="revoke_sessions">
            <button class="eds-button eds-button--danger" type="submit"
                    data-confirm="注销所有设备上的网页会话？"
                    data-confirm-title="注销全部设备">注销全部设备</button>
          </form>
          <p class="tl-card__note">登录有效期 <?= (int) round((int) cfg('cookie.admin_ttl') / 3600) ?> 小时。</p>
          <form method="post" action="<?= e(tl_logout_url()) ?>">
            <?= tl_csrf_field() ?>
            <input type="hidden" name="role" value="admin">
            <input type="hidden" name="back" value="<?= e(tl_front_url()) ?>">
            <button class="eds-button" type="submit">退出后台</button>
          </form>
        </div>
      </aside>
    </div>
  <?php endif; ?>

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
