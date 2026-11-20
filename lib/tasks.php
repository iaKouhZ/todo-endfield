<?php
declare(strict_types=1);

/**
 * 待办清单 · 领域层
 * ---------------------------------------------------------------------------
 * 分类、待办事项、重复展开、状态变更、批量操作、清理。
 *
 * 重复待办 = 一条任务 + 若干“发生”(occurrence)。
 * 发生的完成记录单独存表，所以「每天」的任务不会因为勾掉今天而整串消失，
 * 也不会为每一天各建一行。
 */

/* ===========================================================================
   分类
   ========================================================================= */
function tl_categories(bool $reset = false): array
{
    static $categories = null;
    if ($reset) {
        $categories = null;
        return [];
    }
    return $categories ??= db()->query(
        'SELECT c.*, (SELECT COUNT(*) FROM tasks t WHERE t.category_id = c.id) AS task_count
         FROM categories c ORDER BY c.position ASC, c.id ASC'
    )->fetchAll();
}

function tl_categories_reset(): void
{
    tl_categories(true);
}

function tl_category(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tl_category_by_name(string $name): ?array
{
    $stmt = db()->prepare('SELECT * FROM categories WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function tl_category_create(string $name): int
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('分类名称不能为空。');
    }
    if (tl_str_length($name) > 24) {
        throw new InvalidArgumentException('分类名称最多 24 个字。');
    }
    if (tl_category_by_name($name) !== null) {
        throw new InvalidArgumentException('分类「' . $name . '」已存在。');
    }
    $max = (int) db()->query('SELECT COALESCE(MAX(position), 0) FROM categories')->fetchColumn();
    $stmt = db()->prepare('INSERT INTO categories (name, position, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$name, $max + 10, tl_now()->format('Y-m-d H:i:s')]);
    tl_categories_reset();
    return (int) db()->lastInsertId();
}

function tl_category_rename(int $id, string $name): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('分类名称不能为空。');
    }
    if (tl_str_length($name) > 24) {
        throw new InvalidArgumentException('分类名称最多 24 个字。');
    }
    $other = tl_category_by_name($name);
    if ($other !== null && (int) $other['id'] !== $id) {
        throw new InvalidArgumentException('分类「' . $name . '」已存在。');
    }
    $stmt = db()->prepare('UPDATE categories SET name = ? WHERE id = ?');
    $stmt->execute([$name, $id]);
    tl_categories_reset();
}

/**
 * 删除分类。
 * $taskStrategy = 'delete' 一并删除分类下的待办；'uncategorize' 全部归为未分类。
 */
function tl_category_delete(int $id, string $taskStrategy): array
{
    $category = tl_category($id);
    if ($category === null) {
        throw new InvalidArgumentException('分类不存在。');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE category_id = ?');
        $stmt->execute([$id]);
        $affected = (int) $stmt->fetchColumn();

        if ($taskStrategy === 'delete') {
            $pdo->prepare('DELETE FROM tasks WHERE category_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('UPDATE tasks SET category_id = NULL WHERE category_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    tl_categories_reset();
    return ['category' => $category['name'], 'tasks' => $affected, 'strategy' => $taskStrategy];
}

/* ===========================================================================
   待办事项 · 读取
   ========================================================================= */
function tl_task(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, c.name AS category_name
         FROM tasks t LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 列出待办事项。
 * $filters: status (active|planned|done|all|tracked), category (int|'none'), q (string),
 *           tracked (bool), limit, offset, order.
 */
function tl_tasks_where(array $filters): array
{
    $where = [];
    $params = [];

    $status = $filters['status'] ?? 'all';
    if (in_array($status, ['active', 'planned', 'done'], true)) {
        $where[] = 't.status = :status';
        $params[':status'] = $status;
    }

    if (isset($filters['category']) && $filters['category'] !== '' && $filters['category'] !== null) {
        if ($filters['category'] === 'none') {
            $where[] = 't.category_id IS NULL';
        } elseif (ctype_digit((string) $filters['category'])) {
            $where[] = 't.category_id = :category';
            $params[':category'] = (int) $filters['category'];
        }
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(t.title LIKE :q ESCAPE '\\' OR t.detail LIKE :q ESCAPE '\\' OR c.name LIKE :q ESCAPE '\\')";
        $params[':q'] = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }

    if (!empty($filters['tracked'])) {
        $where[] = 't.tracked = 1';
    }
    if (!empty($filters['untracked'])) {
        $where[] = 't.tracked = 0';
    }
    if (!empty($filters['pinned'])) {
        $where[] = 't.pinned = 1';
    }
    if (!empty($filters['unpinned'])) {
        $where[] = 't.pinned = 0';
    }
    if (!empty($filters['not_done'])) {
        $where[] = "t.status <> 'done'";
    }
    if (!empty($filters['has_due'])) {
        $where[] = 't.due_date IS NOT NULL';
    }
    if (!empty($filters['no_due'])) {
        $where[] = 't.due_date IS NULL';
    }

    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
}

function tl_tasks(array $filters = []): array
{
    [$whereSql, $params] = tl_tasks_where($filters);
    $order = $filters['order'] ?? 'due';
    $orderSql = match ($order) {
        'created'  => 't.created_at DESC, t.id DESC',
        'completed'=> 't.completed_at DESC, t.id DESC',
        default    => 'CASE WHEN t.due_date IS NULL THEN 0 ELSE 1 END, t.due_date ASC, CASE WHEN t.due_time IS NULL THEN 0 ELSE 1 END, t.due_time ASC, t.id ASC',
    };

    $sql = 'SELECT t.*, c.name AS category_name
            FROM tasks t LEFT JOIN categories c ON c.id = t.category_id';
    $sql .= $whereSql . ' ORDER BY ' . $orderSql;

    $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;
    $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
        if ($offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** 与 tl_tasks 相同条件，仅返回行数。 */
function tl_task_count(array $filters = []): int
{
    [$whereSql, $params] = tl_tasks_where($filters);
    $sql = 'SELECT COUNT(*) FROM tasks t LEFT JOIN categories c ON c.id = t.category_id' . $whereSql;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function tl_task_counts_sql(array $filters = []): array
{
    [$whereSql, $params] = tl_tasks_where($filters);
    $sql = 'SELECT t.status, t.tracked, COUNT(*) AS cnt
            FROM tasks t LEFT JOIN categories c ON c.id = t.category_id'
        . $whereSql . ' GROUP BY t.status, t.tracked';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $counts = array_fill_keys(['active', 'planned', 'done', 'all', 'tracked', 'tracked_open', 'planned_open'], 0);
    foreach ($stmt as $row) {
        $n = (int) $row['cnt'];
        $status = $row['status'];
        $counts[$status] += $n;
        $counts['all'] += $n;
        if ($status !== 'done') {
            $counts[(int) $row['tracked'] === 1 ? 'tracked_open' : 'planned_open'] += $n;
        }
    }
    $counts['tracked'] = $counts['tracked_open'];
    return $counts;
}

/* ===========================================================================
   待办事项 · 写入
   ========================================================================= */
function tl_validate_task_input(array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('摘要不能为空。');
    }
    if (tl_str_length($title) > 140) {
        throw new InvalidArgumentException('摘要最多 140 个字。');
    }

    $detail = trim((string) ($input['detail'] ?? ''));
    if ($detail === '') {
        $detail = null;
    } elseif (tl_str_length($detail) > 4000) {
        throw new InvalidArgumentException('详情最多 4000 个字。');
    }

    $categoryRaw = $input['category_id'] ?? '';
    $categoryId = null;
    if ($categoryRaw !== '' && $categoryRaw !== null && $categoryRaw !== 'none') {
        if (!ctype_digit((string) $categoryRaw) || tl_category((int) $categoryRaw) === null) {
            throw new InvalidArgumentException('分类不存在。');
        }
        $categoryId = (int) $categoryRaw;
    }

    [$date, $time] = tl_validate_deadline($input);

    $repeatUnit = (string) ($input['repeat_unit'] ?? '');
    $repeatInterval = null;
    if ($repeatUnit !== '') {
        if (!in_array($repeatUnit, ['day', 'week', 'month'], true)) {
            throw new InvalidArgumentException('重复频率不正确。');
        }
        if ($date === null) {
            throw new InvalidArgumentException('设置重复频率前需要先设置截止日期。');
        }
        $repeatInterval = max(1, min(365, (int) ($input['repeat_interval'] ?? 1)));
    }

    $status = (string) ($input['status'] ?? 'active');
    if (!array_key_exists($status, TL_STATUSES)) {
        $status = 'active';
    }

    $tracked = !empty($input['tracked']) ? 1 : 0;
    /* 已完成：一律取消追踪（前后台共识）。 */
    if ($status === 'done') {
        $tracked = 0;
    }

    return [
        'title'           => $title,
        'detail'          => $detail,
        'category_id'     => $categoryId,
        'due_date'        => $date,
        'due_time'        => $time,
        'status'          => $status,
        'repeat_unit'     => $repeatUnit === '' ? null : $repeatUnit,
        'repeat_interval' => $repeatInterval,
        'tracked'         => $tracked,
        'pinned'          => !empty($input['pinned']) ? 1 : 0,
        'completed_at'    => $status === 'done' ? tl_parse_completion_input((string) ($input['completed_at'] ?? '')) : null,
    ];
}

function tl_task_create(array $input): int
{
    $data = tl_validate_task_input($input);
    $now = tl_now()->format('Y-m-d H:i:s');
    $stmt = db()->prepare(
        'INSERT INTO tasks
           (title, detail, category_id, due_date, due_time, status, repeat_unit, repeat_interval,
            tracked, pinned, completed_at, created_at, updated_at)
         VALUES
           (:title, :detail, :category_id, :due_date, :due_time, :status, :repeat_unit, :repeat_interval,
            :tracked, :pinned, :completed_at, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':title'           => $data['title'],
        ':detail'          => $data['detail'],
        ':category_id'     => $data['category_id'],
        ':due_date'        => $data['due_date'],
        ':due_time'        => $data['due_time'],
        ':status'          => $data['status'],
        ':repeat_unit'     => $data['repeat_unit'],
        ':repeat_interval' => $data['repeat_interval'],
        ':tracked'         => $data['tracked'],
        ':pinned'          => $data['pinned'],
        ':completed_at'    => $data['status'] === 'done' ? ($data['completed_at'] ?? $now) : null,
        ':created_at'      => $now,
        ':updated_at'      => $now,
    ]);
    tl_categories_reset();
    return (int) db()->lastInsertId();
}

/**
 * 读取任务已有完成记录日期列表。
 * @return string[] Y-m-d
 */
function tl_occurrence_dates_for_task(int $taskId): array
{
    $stmt = db()->prepare('SELECT occur_date FROM task_occurrences WHERE task_id = ? ORDER BY occur_date');
    $stmt->execute([$taskId]);
    return array_map(static fn ($row) => (string) $row['occur_date'], $stmt->fetchAll());
}

/**
 * 修改重复锚点/频率/间隔时，删去不再符合新规则的完成记录；若有作废记录且未确认则抛错。
 */
function tl_occurrences_prune_after_repeat_change(
    int $taskId,
    array $taskRow,
    string $newDueYmd,
    ?string $newUnit,
    ?int $newInterval,
    bool $confirm
): void {
    $dates = tl_occurrence_dates_for_task($taskId);
    if ($dates === []) {
        return;
    }
    $anchor = tl_parse_date($newDueYmd);
    if ($anchor === null) {
        return;
    }
    $repeat = ($newUnit !== null && $newUnit !== '' && $newInterval !== null)
        ? ['unit' => $newUnit, 'interval' => max(1, $newInterval)]
        : null;

    $invalid = [];
    foreach ($dates as $ymd) {
        $day = tl_parse_date($ymd);
        if ($day === null) {
            continue;
        }
        if ($repeat === null) {
            if ($day->format('Y-m-d') !== $anchor->format('Y-m-d')) {
                $invalid[] = $ymd;
            }
            continue;
        }
        if (!tl_repeat_date_matches_schedule($anchor, $repeat['unit'], $repeat['interval'], $day)) {
            $invalid[] = $ymd;
        }
    }
    if ($invalid === []) {
        return;
    }
    if (!$confirm) {
        throw new InvalidArgumentException(
            '修改重复规则会使 ' . count($invalid)
            . ' 条已完成发生记录不再适用；请勾选「确认调整重复规则」后重试。'
        );
    }
    $place = implode(',', array_fill(0, count($invalid), '?'));
    $stmt = db()->prepare(
        'DELETE FROM task_occurrences WHERE task_id = ? AND occur_date IN (' . $place . ')'
    );
    $stmt->execute(array_merge([$taskId], $invalid));
}

function tl_task_update(int $id, array $input): void
{
    $task = tl_task($id);
    if ($task === null) {
        throw new InvalidArgumentException('待办事项不存在。');
    }
    $data = tl_validate_task_input($input);
    $pinned = array_key_exists('pinned', $input) ? $data['pinned'] : (int) $task['pinned'];
    $confirmRepeat = !empty($input['repeat_change_confirm']);

    $oldDue = (string) ($task['due_date'] ?? '');
    $oldUnit = $task['repeat_unit'] ?? null;
    $oldInterval = isset($task['repeat_interval']) ? (int) $task['repeat_interval'] : null;
    $newDue = $data['due_date'] ?? '';
    $newUnit = $data['repeat_unit'];
    $newInterval = $data['repeat_interval'];

    $repeatRuleChanged = $oldDue !== $newDue
        || ($oldUnit ?? '') !== ($newUnit ?? '')
        || (int) ($oldInterval ?? 0) !== (int) ($newInterval ?? 0)
        || ((($oldUnit ?? '') === '' || $oldUnit === null) !== (($newUnit ?? '') === '' || $newUnit === null));

    if ($repeatRuleChanged && $newDue !== '') {
        tl_occurrences_prune_after_repeat_change(
            $id,
            $task,
            $newDue,
            $newUnit,
            $newInterval,
            $confirmRepeat
        );
    }

    $now = tl_now()->format('Y-m-d H:i:s');
    $completedAt = $data['status'] === 'done' ? ($data['completed_at'] ?? $task['completed_at'] ?? $now) : null;
    /* 已完成必须取消追踪。 */
    $trackedOut = $data['status'] === 'done' ? 0 : $data['tracked'];
    $stmt = db()->prepare(
        'UPDATE tasks SET title = ?, detail = ?, category_id = ?, due_date = ?, due_time = ?,
            status = ?, repeat_unit = ?, repeat_interval = ?, tracked = ?, pinned = ?,
            completed_at = ?, updated_at = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $data['title'], $data['detail'], $data['category_id'], $data['due_date'], $data['due_time'],
        $data['status'], $data['repeat_unit'], $data['repeat_interval'], $trackedOut, $pinned,
        $completedAt, $now, $id,
    ]);
    tl_subitems_sync_with_parent_status($id, $data['status'], $task['status'], $completedAt);
    tl_categories_reset();
}

function tl_task_delete(int $id): void
{
    db()->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    tl_categories_reset();
}

/* ===========================================================================
   子任务
   ========================================================================= */
function tl_validate_deadline(array $input): array
{
    $date = $input['due_date'] ?? '';
    $time = $input['due_time'] ?? '';
    if (!is_string($date) || ($date !== '' && tl_parse_date($date) === null)) {
        throw new InvalidArgumentException('截止日期格式不正确。');
    }
    if (!is_string($time) || ($time !== '' && tl_parse_time($time) === null)) {
        throw new InvalidArgumentException('截止时间格式不正确。');
    }
    if ($time !== '' && $date === '') {
        throw new InvalidArgumentException('设置截止时间前需要先设置截止日期。');
    }
    return [$date === '' ? null : $date, tl_parse_time($time)];
}

function tl_subitem_title_validate(string $title): string
{
    $title = trim($title);
    if ($title === '') {
        throw new InvalidArgumentException('子任务摘要不能为空。');
    }
    if (tl_str_length($title) > 200) {
        throw new InvalidArgumentException('子任务摘要最多 200 个字。');
    }
    return $title;
}

/** @return list<array<string, mixed>> */
function tl_task_subitems(int $taskId): array
{
    $stmt = db()->prepare(
        "SELECT s.*, s.completed_at AS own_completed_at,
            CASE WHEN t.status = 'done' AND t.completed_at IS NOT NULL THEN
                MIN(COALESCE(s.completed_at, t.completed_at), t.completed_at)
            ELSE s.completed_at END AS effective_completed_at
         FROM task_subitems s JOIN tasks t ON t.id = s.task_id WHERE s.task_id = ?
         ORDER BY s.due_date IS NULL, s.due_date, COALESCE(s.due_time, '23:59'), s.position, s.id"
    );
    $stmt->execute([$taskId]);
    return $stmt->fetchAll();
}

function tl_due_subitems_by_task(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $stmt = db()->prepare(
        "SELECT s.id, s.task_id, s.title, s.due_date, s.due_time
         FROM task_subitems s JOIN tasks t ON t.id = s.task_id
         WHERE t.tracked = 1 AND t.status <> 'done' AND s.done = 0 AND s.due_date BETWEEN ? AND ?
         ORDER BY s.due_date, COALESCE(s.due_time, '23:59'), s.position, s.id"
    );
    $stmt->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
    $byTask = [];
    foreach ($stmt as $row) {
        $byTask[(int) $row['task_id']][] = $row;
    }
    return $byTask;
}

/** 主任务标为完成时，子任务全部显示为已完成。 */
function tl_subitems_sync_with_parent_status(int $taskId, string $status, string $previousStatus, ?string $completedAt): void
{
    if ($status === 'done') {
        $now = tl_now()->format('Y-m-d H:i:s');
        db()->prepare('UPDATE task_subitems SET done = 1, completed_at = NULL, updated_at = ?
                       WHERE task_id = ? AND (done = 0 OR completed_at > ?)')
            ->execute([$now, $taskId, $completedAt]);
    } elseif ($previousStatus === 'done') {
        $now = tl_now()->format('Y-m-d H:i:s');
        db()->prepare('UPDATE task_subitems SET done = 0, completed_at = NULL, updated_at = ?
                       WHERE task_id = ?')->execute([$now, $taskId]);
    }
}

function tl_subitem_add(int $taskId, string $title, array $deadline = []): int
{
    $task = tl_task($taskId);
    if ($task === null) {
        throw new InvalidArgumentException('待办事项不存在。');
    }
    if (($task['status'] ?? '') === 'done') {
        throw new InvalidArgumentException('主任务已完成时不能添加子任务；请先恢复为未完成。');
    }
    $title = tl_subitem_title_validate($title);
    [$dueDate, $dueTime] = tl_validate_deadline($deadline);
    $pdo = db();
    $now = tl_now()->format('Y-m-d H:i:s');
    $mx = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM task_subitems WHERE task_id = ?');
    $mx->execute([$taskId]);
    $max = (int) $mx->fetchColumn();
    $stmt = $pdo->prepare(
        'INSERT INTO task_subitems (task_id, title, due_date, due_time, done, position, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?)'
    );
    $stmt->execute([$taskId, $title, $dueDate, $dueTime, $max + 10, $now, $now]);
    return (int) $pdo->lastInsertId();
}

function tl_subitem_delete(int $subId, int $taskId): void
{
    $stmt = db()->prepare('DELETE FROM task_subitems WHERE id = ? AND task_id = ?');
    $stmt->execute([$subId, $taskId]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException('子任务不存在。');
    }
}

function tl_validate_subitem_completion(?string $completedAt, ?string $parentCompletion): void
{
    if ($parentCompletion !== null && $completedAt !== null && $completedAt > $parentCompletion) {
        throw new InvalidArgumentException('子任务完成时间不能晚于主任务完成时间。');
    }
}

function tl_subitem_set_done(int $subId, int $taskId, bool $done, ?string $completedAt = null, bool $editTime = false): void
{
    $parent = tl_task($taskId);
    $editingCompletion = $done && ($editTime || $completedAt !== null);
    $parentDone = ($parent['status'] ?? null) === 'done';
    if ($parentDone && !$editingCompletion) {
        throw new InvalidArgumentException('主任务已完成时不能切换子任务状态；请先恢复主任务为未完成。');
    }

    $stamp = db()->prepare('SELECT completed_at FROM task_subitems WHERE id = ? AND task_id = ?');
    $stamp->execute([$subId, $taskId]);
    $previous = $stamp->fetch(PDO::FETCH_ASSOC);
    if ($previous === false) {
        throw new InvalidArgumentException('子任务不存在。');
    }
    $old = $previous['completed_at'];
    if ($completedAt !== null && $old !== null && $completedAt === substr($old, 0, 16) . ':00') {
        $completedAt = $old;
    }
    tl_validate_subitem_completion($completedAt, $parentDone ? $parent['completed_at'] : null);
    $now = tl_now()->format('Y-m-d H:i:s');
    $value = $done ? ($editingCompletion && $parentDone ? $completedAt : ($completedAt ?? $old)) : null;
    db()->prepare('UPDATE task_subitems SET done = ?, completed_at = ?, updated_at = ? WHERE id = ? AND task_id = ?')
        ->execute([(int) $done, $value, $now, $subId, $taskId]);
}

/**
 * 保存后台编辑的整表子任务（按行 id / 标题 / 是否完成）。
 * @param list<array{id:int, title:string, done:bool, due_date:string, due_time:string, completed_at:string}> $rows
 */
function tl_subitems_save_rows(int $taskId, array $rows, ?array $beforeParentEdit = null): void
{
    $task = tl_task($taskId);
    if ($task === null) {
        return;
    }
    $pdo = db();
    $now = tl_now()->format('Y-m-d H:i:s');
    $parentDone = $task['status'] === 'done';
    $previous = array_column(tl_task_subitems($taskId), 'own_completed_at', 'id');
    $keep = [];
    $pos = 0;
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $title = tl_subitem_title_validate($title);
        [$dueDate, $dueTime] = tl_validate_deadline($row);
        $done = (int) (!empty($row['done']) || $task['status'] === 'done');
        $completedAt = $done ? tl_parse_completion_input($row['completed_at'] ?? '') : null;
        $old = $previous[$id] ?? null;
        $original = ($beforeParentEdit ?? $previous)[$id] ?? null;
        if ($completedAt !== null && $original !== null && $completedAt === substr($original, 0, 16) . ':00') {
            $completedAt = $old;
        }
        tl_validate_subitem_completion($completedAt, $parentDone ? $task['completed_at'] : null);
        if ($done && $completedAt === null && (!$parentDone || !array_key_exists('completed_at', $row))) {
            $completedAt = $old;
        }
        $pos += 10;
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE task_subitems SET title = ?, due_date = ?, due_time = ?, done = ?,
                 completed_at = ?,
                 position = ?, updated_at = ? WHERE id = ? AND task_id = ?'
            );
            $stmt->execute([$title, $dueDate, $dueTime, $done, $completedAt, $pos, $now, $id, $taskId]);
            if ($stmt->rowCount() > 0) {
                $keep[] = $id;
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO task_subitems (task_id, title, due_date, due_time, done, completed_at, position, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$taskId, $title, $dueDate, $dueTime, $done, $completedAt, $pos, $now, $now]);
            $keep[] = (int) $pdo->lastInsertId();
        }
    }
    if ($keep !== []) {
        $place = implode(',', array_fill(0, count($keep), '?'));
        $stmt = $pdo->prepare(
            'DELETE FROM task_subitems WHERE task_id = ? AND id NOT IN (' . $place . ')'
        );
        $stmt->execute(array_merge([$taskId], $keep));
    } else {
        $pdo->prepare('DELETE FROM task_subitems WHERE task_id = ?')->execute([$taskId]);
    }
}

function tl_tasks_delete(array $ids): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
    if ($ids === []) {
        return 0;
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('DELETE FROM tasks WHERE id IN (' . $place . ')');
    $stmt->execute($ids);
    tl_categories_reset();
    return $stmt->rowCount();
}

/** 一键设置状态（整串任务）。 */
function tl_tasks_set_status(array $ids, string $status, ?string $completedAt = null): int
{
    if (!array_key_exists($status, TL_STATUSES)) {
        throw new InvalidArgumentException('状态不正确。');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
    if ($ids === []) {
        return 0;
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $now = tl_now()->format('Y-m-d H:i:s');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $prevById = [];
        foreach ($ids as $tid) {
            $prevById[$tid] = tl_task($tid);
        }
        if ($status === 'done') {
            $stmt = $pdo->prepare(
                'UPDATE tasks SET status = ?, completed_at = COALESCE(?, completed_at, ?), tracked = 0, updated_at = ? WHERE id IN (' . $place . ')'
            );
            $stmt->execute(array_merge([$status, $completedAt, $now, $now], $ids));
        } else {
            $stmt = $pdo->prepare(
                'UPDATE tasks SET status = ?, completed_at = NULL, updated_at = ? WHERE id IN (' . $place . ')'
            );
            $stmt->execute(array_merge([$status, $now], $ids));
        }
        foreach ($prevById as $tid => $prev) {
            if ($prev !== null) {
                $effectiveCompletion = $status === 'done' ? ($completedAt ?? $prev['completed_at'] ?? $now) : null;
                tl_subitems_sync_with_parent_status($tid, $status, $prev['status'], $effectiveCompletion);
            }
        }
        $pdo->commit();
        return $stmt->rowCount();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function tl_task_set_status(int $id, string $status, ?string $completedAt = null): array
{
    $task = tl_task($id);
    if ($task === null) {
        throw new InvalidArgumentException('待办事项不存在。');
    }
    tl_tasks_set_status([$id], $status, $completedAt);
    return tl_task($id);
}

function tl_task_set_tracked(int $id, int $tracked): array
{
    $task = tl_task($id);
    if ($task === null) {
        throw new InvalidArgumentException('待办事项不存在。');
    }
    $tracked = $tracked ? 1 : 0;
    if ($tracked === 1) {
        if (($task['status'] ?? '') === 'done') {
            throw new InvalidArgumentException('已完成的任务请先恢复为未完成，再添加追踪。');
        }
        $repeat = tl_task_repeat($task);
        if ($repeat !== null) {
            $pdo = db();
            $stmt = $pdo->prepare(
                'SELECT 1 FROM task_occurrences WHERE task_id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() !== false) {
                throw new InvalidArgumentException('该重复任务仍有未合并的按日完成记录；请先通过详情或后台处理完成状态再追踪整串。');
            }
        }
    }
    $stmt = db()->prepare('UPDATE tasks SET tracked = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$tracked, tl_now()->format('Y-m-d H:i:s'), $id]);
    return tl_task($id);
}

/** 置顶 / 取消置顶。 */
function tl_task_set_pin(int $id, int $pinned): array
{
    $task = tl_task($id);
    if ($task === null) {
        throw new InvalidArgumentException('待办事项不存在。');
    }
    $pinned = $pinned ? 1 : 0;
    $stmt = db()->prepare('UPDATE tasks SET pinned = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$pinned, tl_now()->format('Y-m-d H:i:s'), $id]);
    return tl_task($id);
}

/**
 * 标记某个“发生”为完成 / 未完成。
 * 非重复任务：直接改整条状态。
 * 重复任务：只把该天的发生记为完成，整串保持进行中。
 */
function tl_complete_occurrence(int $taskId, ?string $date, bool $done, ?string $completedAt = null): array
{
    $task = tl_task($taskId);
    if ($task === null) {
        throw new InvalidArgumentException('待办事项不存在。');
    }

    $repeat = tl_task_repeat($task);
    if ($repeat === null || $date === null) {
        $status = $done ? 'done' : 'active';
        tl_task_set_status($taskId, $status, $completedAt);
        return tl_task($taskId);
    }

    $day = tl_parse_date($date);
    if ($day === null) {
        throw new InvalidArgumentException('发生日期不正确。');
    }

    $anchor = tl_parse_date($task['due_date'] ?? null);
    if ($anchor === null) {
        throw new InvalidArgumentException('重复任务缺少锚点日期。');
    }
    if (!tl_repeat_date_matches_schedule($anchor, $repeat['unit'], $repeat['interval'], $day)) {
        throw new InvalidArgumentException('该日期不属于此重复规则。');
    }

    if (($task['status'] ?? '') === 'done') {
        if ($done) {
            return tl_task($taskId);
        }
        /* 整串已完成时，恢复未完成必须改整条任务，不能只动某一天。 */
        throw new InvalidArgumentException('该重复任务已整串完成；请先在后台将状态改为进行中，再调整单日记录。');
    }

    if ($done) {
        $stmt = db()->prepare(
            'INSERT INTO task_occurrences (task_id, occur_date, completed_at) VALUES (?, ?, ?)
             ON CONFLICT(task_id, occur_date) DO UPDATE SET completed_at = COALESCE(?, task_occurrences.completed_at)'
        );
        $stmt->execute([$taskId, $day->format('Y-m-d'), $completedAt ?? tl_now()->format('Y-m-d H:i:s'), $completedAt]);
    } else {
        $stmt = db()->prepare('DELETE FROM task_occurrences WHERE task_id = ? AND occur_date = ?');
        $stmt->execute([$taskId, $day->format('Y-m-d')]);
    }
    return tl_task($taskId);
}

/* ===========================================================================
   重复 · 发生
   ========================================================================= */
function tl_task_repeat(array $task): ?array
{
    $unit = $task['repeat_unit'] ?? null;
    if ($unit === null || $unit === '') {
        return null;
    }
    return ['unit' => $unit, 'interval' => max(1, (int) ($task['repeat_interval'] ?? 1))];
}

/** 从锚点日期向后推进一次（日/周；月重复请用 tl_repeat_nth）。 */
function tl_repeat_step(DateTimeImmutable $date, string $unit, int $interval): DateTimeImmutable
{
    return match ($unit) {
        'week'  => $date->modify('+' . ($interval * 7) . ' days'),
        'month' => tl_add_months($date, $interval),
        default => $date->modify('+' . $interval . ' days'),
    };
}

/**
 * 锚点之后的第 $n 次发生：$n=0 为锚点当天，$n=1 为第一次间隔后。
 * 月重复为 anchor + n×间隔 月（日号按目标月夹取），不链式累加。
 */
function tl_repeat_nth(DateTimeImmutable $anchor, string $unit, int $interval, int $n): DateTimeImmutable
{
    $interval = max(1, $interval);
    $anchor = $anchor->setTime(0, 0);
    if ($n <= 0) {
        return $anchor;
    }
    return match ($unit) {
        'month' => tl_add_months($anchor, $n * $interval),
        'week'  => $anchor->modify('+' . ($n * $interval * 7) . ' days'),
        default => $anchor->modify('+' . ($n * $interval) . ' days'),
    };
}

/** 给定锚点与重复规则，判断某个发生日是否在序列上。 */
function tl_repeat_date_matches_schedule(
    DateTimeImmutable $anchor,
    string $unit,
    int $interval,
    DateTimeImmutable $candidate
): bool {
    $interval = max(1, $interval);
    $anchor = $anchor->setTime(0, 0);
    $candidate = $candidate->setTime(0, 0);
    if ($candidate < $anchor) {
        return false;
    }
    if ($candidate->format('Y-m-d') === $anchor->format('Y-m-d')) {
        return true;
    }
    if ($unit === 'month') {
        $lo = 1;
        $hi = 2;
        while (tl_repeat_nth($anchor, $unit, $interval, $hi) < $candidate) {
            $hi *= 2;
            if ($hi > 500000) {
                return false;
            }
        }
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $d = tl_repeat_nth($anchor, $unit, $interval, $mid);
            $cmp = $d <=> $candidate;
            if ($cmp === 0) {
                return true;
            }
            if ($cmp < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return false;
    }
    $diffDays = (int) floor(($candidate->getTimestamp() - $anchor->getTimestamp()) / 86400);
    if ($diffDays < 0) {
        return false;
    }
    if ($unit === 'week') {
        return $diffDays % ($interval * 7) === 0;
    }
    /* day */
    return $diffDays > 0 && $diffDays % $interval === 0;
}

/** 小于等于 $before 的最后一次发生（含锚点）；无则 null。 */
function tl_occurrence_last_on_or_before(
    DateTimeImmutable $anchor,
    string $unit,
    int $interval,
    DateTimeImmutable $before
): ?DateTimeImmutable {
    $anchor = $anchor->setTime(0, 0);
    $before = $before->setTime(0, 0);
    if ($anchor > $before) {
        return null;
    }
    if ($unit === 'month') {
        $lo = 0;
        $hi = 1;
        while (tl_repeat_nth($anchor, $unit, $interval, $hi) <= $before) {
            $hi *= 2;
            if ($hi > 200000) {
                break;
            }
        }
        $best = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $d = tl_repeat_nth($anchor, $unit, $interval, $mid);
            if ($d <= $before) {
                $best = $d;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        return $best;
    }
    $diffDays = (int) floor(($before->getTimestamp() - $anchor->getTimestamp()) / 86400);
    if ($diffDays < 0) {
        return null;
    }
    $step = $unit === 'week' ? $interval * 7 : $interval;
    $k = intdiv($diffDays, $step);
    return match ($unit) {
        'week' => $anchor->modify('+' . ($k * $interval * 7) . ' days'),
        default => $anchor->modify('+' . ($k * $interval) . ' days'),
    };
}

/**
 * 生成 [from, to] 区间内的发生日期（含端点）。按「锚点 + 第 N 次间隔」推算，输出严格落在窗口内。
 * 返回 Y-m-d 列表。
 */
function tl_occurrences_between(array $task, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $anchor = tl_parse_date($task['due_date'] ?? null);
    if ($anchor === null) {
        return [];
    }
    $repeat = tl_task_repeat($task);
    $from = $from->setTime(0, 0);
    $to = $to->setTime(0, 0);

    if ($repeat === null) {
        return ($anchor >= $from && $anchor <= $to) ? [$anchor->format('Y-m-d')] : [];
    }

    $unit = $repeat['unit'];
    $interval = max(1, $repeat['interval']);
    $anchor = $anchor->setTime(0, 0);

    if ($from > $to || $anchor > $to) {
        return [];
    }
    $k = 0;
    if ($anchor < $from) {
        if ($unit === 'month') {
            $lo = 0;
            $hi = 1;
            while (tl_repeat_nth($anchor, $unit, $interval, $hi) < $from) {
                $hi *= 2;
            }
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if (tl_repeat_nth($anchor, $unit, $interval, $mid) < $from) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $k = $lo;
        } else {
            $step = $unit === 'week' ? $interval * 7 : $interval;
            $days = (int) $anchor->diff($from)->days;
            $k = intdiv($days + $step - 1, $step);
        }
    }
    $out = [];
    for ($n = 0; $n < 200000; $n++, $k++) {
        $cursor = tl_repeat_nth($anchor, $unit, $interval, $k);
        if ($cursor > $to) {
            break;
        }
        $out[] = $cursor->format('Y-m-d');
    }
    return $out;
}

/** 重复任务在 $before 之前最近的一次发生（用于“已逾期”）。 */
function tl_occurrence_before(array $task, DateTimeImmutable $before): ?DateTimeImmutable
{
    $anchor = tl_parse_date($task['due_date'] ?? null);
    $repeat = tl_task_repeat($task);
    if ($anchor === null || $repeat === null) {
        return null;
    }
    $limit = $before->setTime(0, 0);
    $unit = $repeat['unit'];
    $interval = max(1, $repeat['interval']);
    $last = tl_occurrence_last_on_or_before($anchor, $unit, $interval, $limit->modify('-1 day'));
    return $last;
}

/** 一次取回若干任务的完成记录，避免逐行查询。 */
function tl_occurrence_map(array $taskIds, ?string $fromDate = null, ?string $toDate = null): array
{
    $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
    if ($taskIds === []) {
        return [];
    }
    $map = [];
    foreach (array_chunk($taskIds, 500) as $ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT task_id, occur_date, completed_at FROM task_occurrences WHERE task_id IN (' . $place . ')';
        if ($fromDate !== null) {
            $sql .= ' AND occur_date >= ?';
            $ids[] = $fromDate;
        }
        if ($toDate !== null) {
            $sql .= ' AND occur_date <= ?';
            $ids[] = $toDate;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($ids);
        foreach ($stmt as $row) {
            $map[(int) $row['task_id'] . '|' . $row['occur_date']] = $row['completed_at'];
        }
    }
    return $map;
}

function tl_occurrences_clear(int $taskId): void
{
    db()->prepare('DELETE FROM task_occurrences WHERE task_id = ?')->execute([$taskId]);
}

/* ===========================================================================
   清理 · 完成超过 N 天
   ========================================================================= */
function tl_purge_done(int $days = 7): int
{
    $cutoff = tl_now()->modify('-' . max(0, $days) . ' days')->format('Y-m-d H:i:s');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE status = 'done' AND completed_at IS NOT NULL AND completed_at < ?");
        $stmt->execute([$cutoff]);
        $removed = $stmt->rowCount();
        $pdo->prepare(<<<'SQL'
            DELETE FROM task_occurrences
            WHERE completed_at < :cutoff AND occur_date < :today
            AND NOT EXISTS (
                SELECT 1 FROM tasks t
                WHERE t.id = task_occurrences.task_id AND t.status <> 'done' AND t.repeat_unit IS NOT NULL
                AND task_occurrences.occur_date = (
                    SELECT MAX(o.occur_date) FROM task_occurrences o
                    WHERE o.task_id = t.id AND o.occur_date < :today
                )
            )
        SQL)->execute(['cutoff' => $cutoff, 'today' => tl_today()->format('Y-m-d')]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    tl_categories_reset();
    return $removed;
}

/* ===========================================================================
   派生属性 · 供视图使用
   ========================================================================= */
function tl_is_overdue(array $task, ?DateTimeImmutable $now = null): bool
{
    if (($task['status'] ?? '') === 'done') {
        return false;
    }
    $due = tl_parse_due($task);
    if ($due === null) {
        return false;
    }
    $now ??= tl_now();
    return $due < $now;
}

function tl_due_label(array $task): string
{
    $due = tl_parse_due($task);
    if ($due === null) {
        return '未设截止';
    }
    $day = tl_day_label($due);
    $hasTime = !empty($task['due_time']);
    return $day . ' · ' . tl_date_cn($due) . ($hasTime ? ' ' . $due->format('H:i') : '');
}

function tl_completion_label(array $task, ?string $date, string $completedAt): string
{
    $task['due_date'] = $date;
    $due = tl_parse_due($task);
    if ($due === null) {
        return '完成于 ' . substr($completedAt, 0, 16);
    }
    $completed = new DateTimeImmutable($completedAt, tl_tz());
    $minutes = intdiv($completed->getTimestamp(), 60) - intdiv($due->getTimestamp(), 60);
    if ($minutes <= 0) {
        return '已按时完成';
    }
    return sprintf('逾期 %d天%d时%d分完成', intdiv($minutes, 1440), intdiv($minutes % 1440, 60), $minutes % 60);
}
