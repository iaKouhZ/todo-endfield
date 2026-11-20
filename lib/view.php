<?php
declare(strict_types=1);

/**
 * 视图助手 · 前后台共用
 */

function tl_view_head(array $options): void
{
    $title = (string) ($options['title'] ?? cfg('app.name'));
    $palette = (string) ($options['palette'] ?? 'signal');
    $scheme = (string) ($options['scheme'] ?? 'light');
    $bodyClass = (string) ($options['body_class'] ?? '');
    /* 页面渲染时刻（北京时间）：前端用它做跨天检测。
       顶栏时钟存在时两者一致；后台某些视图没有时钟，也能靠这里兜底。 */
    $renderNow = tl_now();
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-eds-scheme="<?= e($scheme) ?>" data-eds-palette="<?= e($palette) ?>"
      data-tl-pretty="<?= tl_pretty_url() ? '1' : '0' ?>"
      data-tl-base="<?= e(tl_base_path()) ?>"
      data-tl-day="<?= e($renderNow->format('Y-m-d')) ?>"
      data-tl-tz="<?= e((string) cfg('app.timezone', 'Asia/Shanghai')) ?>"
      data-tl-rendered="<?= e((string) ((int) $renderNow->getTimestamp() * 1000)) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(tl_asset('assets/eds/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(tl_asset('assets/eds/components.css')) ?>">
<link rel="stylesheet" href="<?= e(tl_asset('assets/app.css')) ?>">
</head>
<body class="eds-root eds-square <?= e($bodyClass) ?>">
<?php
}

function tl_view_foot(): void
{
    ?>
<div class="tl-footer">
  <span>时间按北京时间（<?= e((string) cfg('app.timezone', 'Asia/Shanghai')) ?>）</span>
  <div class="tl-topbar__controls">
    <?php tl_theme_switch(); ?>
  </div>
</div>
<?php
}

/** 本条「发生」在 UI 上是否算已完成（含重复任务某日打勾）。 */
function tl_entry_is_done(array $entry): bool
{
    $task = $entry['task'];
    if (($task['status'] ?? '') === 'done') {
        return true;
    }
    return !empty($entry['done']);
}

function tl_theme_switch(): void
{
    ?>
<div class="tl-theme" role="group" aria-label="明暗">
  <button type="button" data-tl-scheme="light" aria-pressed="true">亮</button>
  <button type="button" data-tl-scheme="dark" aria-pressed="false">暗</button>
</div>
<?php
}

function tl_flash_view(?array $flash): void
{
    if ($flash === null) {
        return;
    }
    $type = in_array($flash['type'], ['ok', 'error', 'info'], true) ? $flash['type'] : 'info';
    ?>
<div class="tl-flash tl-flash--<?= e($type) ?>" role="status"><?= e($flash['message']) ?></div>
<?php
}

function tl_topbar(string $active): void
{
    $frontAllowed = tl_front_allowed();
    $isAdmin = tl_admin();
    $now = tl_now();
    ?>
<header class="tl-topbar">
  <div class="tl-brand">
    <span class="tl-brand__name">
      <span class="tl-brand__dot" aria-hidden="true"></span>
      <?= e(cfg('app.name')) ?>
    </span>
    <span class="tl-brand__latin"><?= e(cfg('app.latin')) ?></span>
  </div>

  <nav class="tl-topnav" aria-label="主导航">
    <?php if ($frontAllowed): ?>
      <a class="tl-topnav__item" href="<?= e(tl_front_url()) ?>" <?= $active === 'front' ? 'aria-current="page"' : '' ?>>任务看板</a>
    <?php endif; ?>
    <a class="tl-topnav__item" href="<?= e(tl_admin_url()) ?>" <?= $active === 'admin' ? 'aria-current="page"' : '' ?>>任务管理</a>
  </nav>

  <span class="tl-topbar__spacer"></span>

  <div class="tl-topbar__side">
    <?php
    /* 时钟：先由服务端渲染出静态值（JS 不可用时也正确），
       再把「服务端此刻」的毫秒时间戳与时区交给前端，
       由它按北京时间继续走秒，并在跨天时刷新整页。 */
    ?>
    <div class="tl-clock"
         data-clock
         data-epoch="<?= e((string) ((int) $now->getTimestamp() * 1000)) ?>"
         data-tz="<?= e((string) cfg('app.timezone', 'Asia/Shanghai')) ?>"
         data-day="<?= e($now->format('Y-m-d')) ?>">
      <time class="tl-clock__time" data-clock-time datetime="<?= e($now->format('H:i')) ?>"><?= e($now->format('H:i')) ?></time>
      <span class="tl-clock__meta" data-clock-meta><?= e(tl_date_cn($now)) ?> · <?= e(tl_weekday_cn($now)) ?></span>
    </div>
    <?php if ($isAdmin): ?>
      <form class="tl-form-flush" method="post" action="<?= e(tl_logout_url()) ?>">
        <?= tl_csrf_field() ?>
        <input type="hidden" name="role" value="admin">
        <input type="hidden" name="back" value="<?= e(tl_front_url()) ?>">
        <button class="tl-ghostlink" type="submit">退出管理</button>
      </form>
    <?php endif; ?>
  </div>
</header>
<?php
}

/* ===========================================================================
   任务行
   ---------------------------------------------------------------------------
   行本身是一个链接（点开详情）。复选框是独立的元素，通过 form 属性挂在
   批量表单上，因此不会嵌进 <a>，也没有点击区域的相互吞并。
   =========================================================================== */
function tl_row_view(array $entry, ?int $selectedId): void
{
    $task = $entry['task'];
    $id = (int) $task['id'];
    $status = (string) $task['status'];
    $done = tl_entry_is_done($entry);
    $repeat = tl_task_repeat($task);
    $tracked = (int) $task['tracked'] === 1;
    $selected = $selectedId !== null && $selectedId === $id && (!$entry['occurrence'] || (string) ($_GET['date'] ?? '') === (string) ($entry['date'] ?? ''));
    $overdue = false;
    if (!$done && $entry['date'] !== null) {
        $rowDue = $task;
        if (!empty($entry['occurrence']) && $entry['date'] !== null) {
            $rowDue = $task;
            $rowDue['due_date'] = $entry['date'];
        }
        $overdue = tl_is_overdue($rowDue);
    }
    $date = $entry['date'];

    $classes = ['tl-row'];
    $classes[] = $done ? 'tl-row--done' : '';
    $classes[] = $selected ? 'tl-row--selected' : '';
    $classes[] = $overdue ? 'tl-row--overdue' : '';
    $classes = implode(' ', array_filter($classes));

    if ($done && $entry['completed_at'] !== null) {
        $deadline = tl_completion_label($task, $date, $entry['completed_at']);
    } else {
        $deadline = tl_due_label(array_replace($task, ['due_date' => $date]));
    }

    $params = array_filter([
        'tab'  => $_GET['tab'] ?? null,
        'cat'  => $_GET['cat'] ?? null,
        'q'    => $_GET['q'] ?? null,
        'task' => $id,
        'date' => $date,
    ], fn ($v) => $v !== null && $v !== '');
    $openUrl = tl_front_url($params);
    ?>
<div class="<?= $classes ?>" data-task-row data-task-id="<?= $id ?>" data-occur-date="<?= e($date ?? '') ?>"
     data-due-date="<?= e($date ?? '') ?>" data-due-time="<?= e($task['due_time'] ?? '') ?>">
  <a class="tl-row__link" href="<?= e($openUrl) ?>">
    <span class="tl-row__main">
      <span class="tl-row__title"><?= e($task['title']) ?></span>
      <span class="tl-row__meta">
        <span class="tl-row__cat"><?= e($task['category_name'] ?? '未分类') ?></span>
        <?php if ($repeat !== null): ?>
          <span class="tl-repeat-mark"><?= e(tl_repeat_label($repeat['unit'], $repeat['interval'])) ?></span>
        <?php endif; ?>
        <?php if ((int) $task['pinned'] === 1): ?>
          <span class="tl-pin-mark">置顶</span>
        <?php endif; ?>
        <?php if ($tracked): ?>
          <span class="tl-track-mark">追踪</span>
        <?php endif; ?>
      </span>
      <?php /* 窄屏把截止时间放到分类行下面 —— 桌面端这一行不显示，用右侧那份。 */ ?>
      <span class="tl-row__mobiledue <?= $overdue ? 'tl-row__mobiledue--overdue' : '' ?>"><?= e($deadline) ?></span>
    </span>
    <span class="tl-row__side">
      <span class="tl-row__deadline"><?= e($deadline) ?></span>
      <span class="tl-status tl-status--<?= e($status) ?>"><?= e(tl_status_label($status)) ?></span>
    </span>
    <?php if (!empty($entry['due_subitems'])): ?>
      <ul class="tl-row__subitems">
        <?php foreach ($entry['due_subitems'] as $subitem): ?>
          <li data-subtask-id="<?= (int) $subitem['id'] ?>" data-due-date="<?= e($subitem['due_date']) ?>"
              data-due-time="<?= e($subitem['due_time'] ?? '') ?>">
            <span><?= e($subitem['title']) ?></span>
            <span class="tl-row__subdue"><?= e(tl_due_label($subitem)) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </a>
</div>
<?php
}

/** 分类分组下的行列表。 */
function tl_entries_view(array $groups, ?int $selectedId, string $emptyText = '暂无事项'): void
{
    if ($groups === []) {
        ?>
<div class="tl-empty">
  <span><?= e($emptyText) ?></span>
</div>
        <?php
        return;
    }
    ?>
<div class="tl-rows">
    <?php foreach ($groups as $group): ?>
      <div class="tl-section-head">
        <span class="tl-section-head__mark" aria-hidden="true"></span>
        <span class="tl-section-head__name"><?= e($group['category']['name']) ?></span>
        <span class="tl-section-head__count"><?= count($group['entries']) ?></span>
      </div>
      <?php foreach ($group['entries'] as $entry) { tl_row_view($entry, $selectedId); } ?>
    <?php endforeach; ?>
</div>
    <?php
}

/**
 * 可折叠的分段视图：置顶区、追踪中三段、规划中两段共用这一套。
 * 每段可折叠，段内每个分类也可折叠；空段默认折叠。
 *
 * 段可以用 groups（按分类分组）或 entries（扁平列表，置顶区用）两种形式。
 *
 * @param array<int, array> $sections 每项含 id/index/title/hint/groups|entries/count/empty/open
 */
function tl_sections_view(array $sections, ?int $selectedId): void
{
    foreach ($sections as $section) {
        $open = !empty($section['open']);
        ?>
<section class="tl-track-section" data-fold-section
         data-fold-id="<?= e($section['id']) ?>"
         data-fold-open="<?= $open ? '1' : '0' ?>">
  <header class="tl-track-head">
    <button class="tl-fold" type="button" data-fold-toggle
            aria-expanded="<?= $open ? 'true' : 'false' ?>"
            aria-controls="fold-<?= e($section['id']) ?>">
      <span class="tl-fold__chevron" aria-hidden="true"></span>
      <span class="tl-track-head__title"><?= e($section['title']) ?></span>
      <?php if (($section['hint'] ?? '') !== ''): ?>
        <span class="tl-track-head__hint"><?= e($section['hint']) ?></span>
      <?php endif; ?>
      <span class="tl-track-head__count"><?= (int) $section['count'] ?> 条</span>
    </button>
  </header>
  <div class="tl-fold__body" id="fold-<?= e($section['id']) ?>">
    <?php if (isset($section['entries'])): ?>
      <?php if ($section['entries'] === []): ?>
        <div class="tl-empty">
          <span><?= e($section['empty']) ?></span>
        </div>
      <?php else: ?>
        <div class="tl-rows">
          <?php foreach ($section['entries'] as $entry) { tl_row_view($entry, $selectedId); } ?>
        </div>
      <?php endif; ?>
    <?php elseif ($section['groups'] === []): ?>
      <div class="tl-empty">
        <span><?= e($section['empty']) ?></span>
      </div>
    <?php else: ?>
      <?php foreach ($section['groups'] as $group): ?>
        <?php
        $gid = $section['id'] . '-cat-' . (int) $group['category']['id'];
        ?>
        <div class="tl-catfold" data-fold-group data-fold-id="<?= e($gid) ?>" data-fold-open="1">
          <button class="tl-section-head tl-section-head--button" type="button"
                  data-fold-toggle aria-expanded="true" aria-controls="fold-<?= e($gid) ?>">
            <span class="tl-fold__chevron" aria-hidden="true"></span>
            <span class="tl-section-head__mark" aria-hidden="true"></span>
            <span class="tl-section-head__name"><?= e($group['category']['name']) ?></span>
            <span class="tl-section-head__count"><?= count($group['entries']) ?></span>
          </button>
          <div class="tl-fold__body tl-rows" id="fold-<?= e($gid) ?>">
            <?php foreach ($group['entries'] as $entry) { tl_row_view($entry, $selectedId); } ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
        <?php
    }
}

function tl_empty_block(string $title, string $hint, string $mark = 'NOTHING HERE'): void
{
    ?>
<div class="tl-empty tl-empty--center">
  <span class="tl-empty-title"><?= e($title) ?></span>
  <span class="tl-empty-hint"><?= e($hint) ?></span>
</div>
<?php
}

function tl_subitem_deadline_fields(array $subitem = [], ?int $index = null, bool $readonly = false): void
{
    $prefix = $index !== null ? 'subitem_' : '';
    $suffix = $index !== null ? '[' . $index . ']' : '';
    ?>
    <div class="tl-subitems-deadline">
      <label class="tl-form__row">
        <span class="tl-form__label">子任务截止日期</span>
        <input class="tl-form__input" type="date" name="<?= $prefix ?>due_date<?= $suffix ?>"
               value="<?= e($subitem['due_date'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?>>
      </label>
      <label class="tl-form__row">
        <span class="tl-form__label">截止时间</span>
        <input class="tl-form__input" type="time" name="<?= $prefix ?>due_time<?= $suffix ?>"
               value="<?= e($subitem['due_time'] ?? '') ?>" <?= $readonly ? 'readonly' : '' ?>>
      </label>
      <?php if ($index !== null && !empty($subitem['id'])): ?>
        <div class="tl-subitems-completion">
          <?php if (!$readonly || (int) $subitem['done'] === 1): ?>
            <?php tl_completion_field($subitem['own_completed_at'] ?? null, name: 'subitem_completed_at' . $suffix); ?>
          <?php elseif ($subitem['effective_completed_at'] !== null): ?>
            <span class="tl-form__label">完成于 <?= e(substr($subitem['effective_completed_at'], 0, 16)) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

function tl_completion_field(?string $completedAt, bool $required = false, string $name = 'completed_at'): void
{
    ?>
    <label class="tl-form__row">
      <span class="tl-form__label">完成时间</span>
      <input class="tl-form__input" type="datetime-local" name="<?= e($name) ?>"
             value="<?= e(str_replace(' ', 'T', substr($completedAt ?? '', 0, 16))) ?>" <?= $required ? 'required' : '' ?>>
    </label>
    <?php
}

/* ===========================================================================
   右侧详情
   =========================================================================== */
function tl_detail_view(?array $entry, bool $admin, string $backUrl = '', string $editUrl = ''): void
{
    if ($entry === null) {
        ?>
<aside class="tl-detail" id="detail" aria-label="任务详情">
  <div class="tl-detail__empty">
    <span class="tl-detail__empty-mark">↖</span>
    <span class="tl-detail-placeholder">选择左侧任一事项</span>
  </div>
</aside>
        <?php
        return;
    }

    $task = $entry['task'];
    $id = (int) $task['id'];
    $date = $entry['date'];
    $repeat = tl_task_repeat($task);
    $status = (string) $task['status'];
    $tracked = (int) $task['tracked'] === 1;
    $pinned = (int) $task['pinned'] === 1;

    $dueLabel = '—';
    $due = tl_parse_due(array_replace($task, ['due_date' => $date]));
    if ($due !== null) {
        $dueLabel = tl_date_cn($due) . ' · ' . tl_weekday_cn($due) . ' · ' . tl_day_label($due);
        if (!empty($task['due_time'])) {
            $dueLabel .= ' ' . $due->format('H:i');
        }
    }

    $markAction = ($entry['occurrence'] && $date !== null && $status !== 'done') ? 'occurrence' : 'task';
    $isDoneNow = tl_entry_is_done($entry);
    $completedAt = $isDoneNow ? $entry['completed_at'] : null;
    $subitems = $entry['subitems'] ?? [];
    if (!is_array($subitems)) {
        $subitems = [];
    }
    $subBack = $backUrl !== '' ? $backUrl : (string) ($_SERVER['REQUEST_URI'] ?? tl_front_url());
    ?>
<aside class="tl-detail" id="detail" aria-label="任务详情">
  <div class="tl-detail__top">
    <div class="tl-detail__kicker">
      <?= e(tl_status_label($status)) ?><?= $entry['occurrence'] && $date !== null ? ' · ' . e($date) : '' ?>
    </div>
    <h2 class="tl-detail__title"><?= e($task['title']) ?></h2>
    <div class="tl-detail__chips">
      <span class="eds-chip eds-chip--active"><?= e($task['category_name'] ?? '未分类') ?></span>
      <?php if ($repeat !== null): ?>
        <span class="eds-chip"><?= e(tl_repeat_label($repeat['unit'], $repeat['interval'])) ?></span>
      <?php endif; ?>
      <?php if ($tracked): ?>
        <span class="eds-chip eds-chip--solid">追踪中</span>
      <?php endif; ?>
      <?php if ($pinned): ?>
        <span class="eds-chip">置顶</span>
      <?php endif; ?>
      <span class="eds-chip"><?= e(tl_status_label($status)) ?></span>
    </div>

    <div class="tl-detail__facts">
      <div class="tl-fact">
        <span class="tl-fact__label">截止</span>
        <span class="tl-fact__value"><?= e($dueLabel) ?></span>
      </div>
      <?php if ($completedAt !== null): ?>
        <div class="tl-fact">
          <span class="tl-fact__label">完成</span>
          <span class="tl-fact__value"><?= e($date === null ? substr($completedAt, 0, 16) : tl_completion_label($task, $date, $completedAt)) ?></span>
        </div>
      <?php endif; ?>
      <div class="tl-fact">
        <span class="tl-fact__label">分类</span>
        <span class="tl-fact__value"><?= e($task['category_name'] ?? '未分类') ?></span>
      </div>
    </div>
  </div>

  <div class="tl-detail__mid">
    <?php if (!empty($task['detail'])): ?>
      <p class="tl-detail__body"><?= e($task['detail']) ?></p>
    <?php else: ?>
      <p class="tl-detail__body tl-detail__body--fallback"><?= e($task['title']) ?></p>
    <?php endif; ?>

    <?php if ($completedAt !== null && $admin): ?>
      <form method="post" action="<?= e(tl_action_url()) ?>" class="tl-detail__subadd">
        <?= tl_csrf_field() ?>
        <input type="hidden" name="op" value="complete">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="scope" value="<?= e($markAction) ?>">
        <input type="hidden" name="date" value="<?= e($date ?? '') ?>">
        <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? tl_front_url()) ?>">
        <?php tl_completion_field($completedAt, true); ?>
        <button class="tl-action eds-button" type="submit">保存完成时间</button>
      </form>
    <?php elseif ($completedAt !== null && $date !== null): ?>
      <p class="tl-detail__subnote">完成时间 <?= e(substr($completedAt, 0, 16)) ?></p>
    <?php endif; ?>

    <?php if ($subitems !== [] || ($admin && $status !== 'done')): ?>
      <div class="tl-detail__sub" data-detail-subitems>
        <div class="tl-detail__subhead">子任务</div>
        <ul class="tl-detail__sublist">
          <?php foreach ($subitems as $sub): ?>
            <?php
            $sid = (int) $sub['id'];
            $ownDone = (int) $sub['done'] === 1;
            $sdone = (int) $sub['effective_done'] === 1;
            $subCompletedAt = $sdone ? $sub['effective_completed_at'] : null;
            $sub = tl_subitem_effective_deadline($sub, $task, $date);
            ?>
            <li class="tl-detail__subitem" data-own-done="<?= $ownDone ? '1' : '0' ?>" data-completed-at="<?= e($subCompletedAt ?? '') ?>">
              <?php if ($admin): ?>
                <form method="post" action="<?= e(tl_action_url()) ?>" class="tl-detail__subform">
                  <?= tl_csrf_field() ?>
                  <input type="hidden" name="op" value="subitem_done">
                  <input type="hidden" name="task_id" value="<?= $id ?>">
                  <input type="hidden" name="subitem_id" value="<?= $sid ?>">
                  <input type="hidden" name="back" value="<?= e($subBack) ?>">
                  <input type="hidden" name="done" value="<?= $sdone ? '0' : '1' ?>">
                  <button type="submit" class="tl-detail__subcheck" aria-label="<?= $sdone ? '标记未完成' : '标记完成' ?>"
                          <?= $status === 'done' ? 'disabled' : '' ?>>
                    <span class="tl-detail__subbox <?= $sdone ? 'tl-detail__subbox--on' : '' ?>" aria-hidden="true"></span>
                  </button>
                </form>
              <?php else: ?>
                <span class="tl-detail__subbox <?= $sdone ? 'tl-detail__subbox--on' : '' ?>" aria-hidden="true"></span>
              <?php endif; ?>
              <div class="tl-detail__subtitle">
                <span class="<?= $sdone ? 'tl-detail__subtitle--done' : '' ?>"><?= e($sub['title']) ?></span>
                <?php if ($sdone): ?>
                  <?php if ($subCompletedAt !== null): ?>
                    <span class="tl-detail__subdue"><?= e(tl_completion_label($sub, $sub['due_date'], $subCompletedAt)) ?></span>
                  <?php endif; ?>
                  <?php if ($admin && $ownDone): ?>
                    <details class="tl-detail__subcompletion">
                      <summary><?= $subCompletedAt === null || $sub['due_date'] === null ? '修改完成时间' : '完成时间 ' . e(substr($subCompletedAt, 0, 16)) ?></summary>
                      <form method="post" action="<?= e(tl_action_url()) ?>" class="tl-detail__subadd">
                        <?= tl_csrf_field() ?>
                        <input type="hidden" name="op" value="subitem_done">
                        <input type="hidden" name="task_id" value="<?= $id ?>">
                        <input type="hidden" name="subitem_id" value="<?= $sid ?>">
                        <input type="hidden" name="back" value="<?= e($subBack) ?>">
                        <?php tl_completion_field($sub['own_completed_at'], true); ?>
                        <button class="tl-action eds-button" type="submit">保存完成时间</button>
                      </form>
                    </details>
                  <?php elseif ($subCompletedAt !== null && $sub['due_date'] !== null): ?>
                    <span class="tl-detail__subdue">完成时间 <?= e(substr($subCompletedAt, 0, 16)) ?></span>
                  <?php endif; ?>
                <?php elseif ($sub['due_date'] !== null): ?>
                  <span class="tl-detail__subdue"><?= e(tl_due_label($sub)) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($admin && $status !== 'done'): ?>
                <form method="post" action="<?= e(tl_action_url()) ?>" class="tl-detail__subdel">
                  <?= tl_csrf_field() ?>
                  <input type="hidden" name="op" value="subitem_delete">
                  <input type="hidden" name="task_id" value="<?= $id ?>">
                  <input type="hidden" name="subitem_id" value="<?= $sid ?>">
                  <input type="hidden" name="back" value="<?= e($subBack) ?>">
                  <button class="tl-action tl-action--delete eds-button" type="submit" aria-label="删除子任务：<?= e($sub['title']) ?>"
                          data-confirm="删除这条子任务？" data-confirm-title="删除子任务"><span aria-hidden="true">✕</span></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($admin && $status !== 'done'): ?>
          <form method="post" action="<?= e(tl_action_url()) ?>" class="tl-detail__subadd">
            <?= tl_csrf_field() ?>
            <input type="hidden" name="op" value="subitem_add">
            <input type="hidden" name="task_id" value="<?= $id ?>">
            <input type="hidden" name="back" value="<?= e($subBack) ?>">
            <input class="tl-detail__subinput" type="text" name="title" maxlength="200" placeholder="添加子任务…" required>
            <button class="tl-action eds-button" type="submit">添加</button>
            <?php tl_subitem_deadline_fields(); ?>
          </form>
        <?php elseif ($status === 'done' && $subitems !== []): ?>
          <p class="tl-detail__subnote">主任务已完成，子任务已全部标记完成。</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="tl-detail__foot">
    <?php if ($admin): ?>
      <div class="tl-detail__actions">
        <form class="tl-form-contents" method="post" action="<?= e(tl_action_url()) ?>">
          <?= tl_csrf_field() ?>
          <input type="hidden" name="op" value="complete">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="scope" value="<?= e($markAction) ?>">
          <input type="hidden" name="date" value="<?= e($date ?? '') ?>">
          <input type="hidden" name="done" value="<?= $isDoneNow ? '0' : '1' ?>">
          <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? tl_front_url()) ?>">
          <button class="tl-action eds-button <?= $isDoneNow ? '' : 'eds-button--solid' ?>" type="submit">
            <?= $markAction === 'occurrence' ? ($isDoneNow ? '撤销本次完成' : '完成本次') : ($isDoneNow ? '标记为未完成' : '标记为完成') ?>
          </button>
        </form>
        <form class="tl-form-contents" method="post" action="<?= e(tl_action_url()) ?>">
          <?= tl_csrf_field() ?>
          <input type="hidden" name="op" value="track">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="tracked" value="<?= $tracked ? '0' : '1' ?>">
          <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? tl_front_url()) ?>">
          <button class="tl-action eds-button" type="submit"><?= $tracked ? '移除追踪' : '添加追踪' ?></button>
        </form>
        <form class="tl-form-contents" method="post" action="<?= e(tl_action_url()) ?>">
          <?= tl_csrf_field() ?>
          <input type="hidden" name="op" value="pin">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="pinned" value="<?= $pinned ? '0' : '1' ?>">
          <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? tl_front_url()) ?>">
          <button class="tl-action eds-button" type="submit"><?= $pinned ? '取消置顶' : '置顶' ?></button>
        </form>
        <a class="tl-action eds-button" href="<?= e($editUrl) ?>">编辑任务</a>
      </div>
    <?php else: ?>
      <div class="tl-detail__guest">
        <span><a href="<?= e(tl_login_url()) ?>">登录</a>后可操作</span>
      </div>
    <?php endif; ?>
  </div>
</aside>
<?php
}
