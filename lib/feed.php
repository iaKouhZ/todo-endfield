<?php
declare(strict_types=1);

/**
 * 待办清单 · 前台编排
 * ---------------------------------------------------------------------------
 * 一条「条目（entry）」= 任务 × 发生日期：
 *   [task, date, done, occurrence]
 * 非重复任务的 date 就是它的截止日期；重复任务在范围内展开成多条条目，
 * 每条发生的完成状态单独来自 task_occurrences。
 *
 * 分组规则：先按分类（后台 position 顺序，未分类最后），
 * 分类内按“当日的截止时间”排序（未设时间排最后）。
 */

function tl_entry(array $task, ?string $date, bool $done, bool $occurrence, array $occurrenceMap): array
{
    return [
        'task'       => $task,
        'date'       => $date,
        'done'       => $done,
        'occurrence' => $occurrence,
        'completed_at' => $occurrence
            ? ($occurrenceMap[(int) $task['id'] . '|' . $date] ?? null)
            : ($task['completed_at'] ?? null),
        'key'        => (int) $task['id'] . '@' . ($date ?? '-'),
    ];
}

function tl_entry_sort_key(array $entry): string
{
    $time = $entry['task']['due_time'] ?? '99:99';
    $day = $entry['date'] ?? '9999-99-99';
    if (!empty($entry['due_subitems'])) {
        $first = $entry['due_subitems'][0];
        $dayTime = min($day . '#' . $time, $first['due_date'] . '#' . ($first['due_time'] ?? '99:99'));
        [$day, $time] = explode('#', $dayTime);
    }
    return $day . '#' . $time . '#'
        . str_pad((string) $entry['task']['id'], 10, '0', STR_PAD_LEFT);
}

/** 未完成且 tracked=1 —— 「追踪中」列表（不要求 status=active）。 */
function tl_task_in_tracked_view(array $task): bool
{
    return ($task['status'] ?? '') !== 'done'
        && (int) ($task['tracked'] ?? 0) === 1;
}

/** 未完成且 tracked=0 —— 「规划中」列表。 */
function tl_task_in_planned_view(array $task): bool
{
    return ($task['status'] ?? '') !== 'done'
        && (int) ($task['tracked'] ?? 0) === 0;
}

function tl_sort_entries(array $entries, string $mode = 'time'): array
{
    usort($entries, static function (array $a, array $b) use ($mode): int {
        if ($mode === 'created') {
            return strcmp((string) $b['task']['created_at'], (string) $a['task']['created_at']);
        }
        return tl_entry_sort_key($a) <=> tl_entry_sort_key($b);
    });
    return $entries;
}

/**
 * 按分类分组。分类顺序跟随后台 position，未分类永远在最后。
 * @return array<int, array{category: array, entries: array}>
 */
function tl_group_by_category(array $entries): array
{
    $groups = [];
    foreach ($entries as $entry) {
        $task = $entry['task'];
        $id = $task['category_id'] !== null ? (int) $task['category_id'] : 0;
        if (!isset($groups[$id])) {
            $groups[$id] = [
                'category' => [
                    'id'        => $id,
                    'name'      => $id === 0 ? '未分类' : (string) ($task['category_name'] ?? '未分类'),
                ],
                'entries' => [],
            ];
        }
        $groups[$id]['entries'][] = $entry;
    }

    $ordered = [];
    foreach (tl_categories() as $category) {
        $id = (int) $category['id'];
        if (isset($groups[$id])) {
            $ordered[] = $groups[$id];
            unset($groups[$id]);
        }
    }
    if (isset($groups[0])) {
        $ordered[] = $groups[0];
        unset($groups[0]);
    }
    foreach ($groups as $group) {
        $ordered[] = $group;
    }
    return $ordered;
}
/**
 * 置顶区排在「已逾期」之上，收纳各时间段的置顶待办。
 * ---------------------------------------------------------------------------
 * 置顶项无论本来该落在哪个时间段，都从原段落里拿掉，统一放到这里。
 * 区内是一条扁平列表，按截止时间升序：已逾期 → 近一周 → 更晚 → 未设截止日；
 * 不按分类分组——否则时间顺序会被打散。
 *
 * $scope = 'tracked' 取追踪中的置顶项，'planned' 取规划中的置顶项。
 * 重复任务只挑一次发生：漏掉的过去那次优先（算逾期），否则取最近的一次。
 */
/** Bound reads by the earliest displayed past occurrence and latest possible row. */
function tl_feed_occurrence_map(array $tasks, DateTimeImmutable $today, DateTimeImmutable $end, bool $includePast): array
{
    $start = $today;
    if ($includePast) {
        foreach ($tasks as $task) {
            $due = tl_parse_date($task['due_date'] ?? null);
            if ($due === null) {
                continue;
            }
            $past = tl_occurrence_before($task, $today);
            $start = min($start, $past ?? $due);
            $end = max($end, $due);
        }
    }
    return tl_occurrence_map(array_column($tasks, 'id'), tl_ymd($start), tl_ymd($end));
}

function tl_front_pinned(array $filters = [], string $scope = 'tracked', array $weekParents = [], ?array $trackedTasks = null): array
{
    $today = tl_today();
    $horizon = $today->modify('+' . max(1, (int) cfg('front.horizon_days', 365)) . ' days');

    $scopeFilters = ['pinned' => true, 'status' => 'all', 'not_done' => true];

    $tasks = $trackedTasks === null ? tl_tasks($filters + $scopeFilters)
        : array_filter($trackedTasks, static fn (array $task): bool => (int) $task['pinned'] === 1);
    $tasks = array_values(array_filter(
        $tasks,
        $scope === 'planned' ? 'tl_task_in_planned_view' : 'tl_task_in_tracked_view'
    ));
    $occurrenceMap = tl_feed_occurrence_map($tasks, $today, $horizon, true);

    $entries = [];
    foreach ($tasks as $task) {
        $id = (int) $task['id'];
        if (isset($weekParents[$id])) {
            continue;
        }
        $dueDate = tl_parse_date($task['due_date'] ?? null);
        $repeat = tl_task_repeat($task);

        if ($dueDate === null) {
            $entries[] = tl_entry($task, null, false, false, $occurrenceMap);
            continue;
        }

        if ($repeat === null) {
            $date = $dueDate->format('Y-m-d');
            $rowDone = (bool) isset($occurrenceMap[$id . '|' . $date]);
            $entries[] = tl_entry($task, $date, $rowDone, false, $occurrenceMap);
            continue;
        }

        $past = tl_occurrence_before($task, $today);
        if ($past !== null && !isset($occurrenceMap[$id . '|' . $past->format('Y-m-d')])) {
            $date = $past->format('Y-m-d');
            $rowDone = isset($occurrenceMap[$id . '|' . $date]);
            $entries[] = tl_entry($task, $date, $rowDone, true, $occurrenceMap);
            continue;
        }
        $next = tl_occurrences_between($task, $today, $horizon);
        $date = $next[0] ?? $dueDate->format('Y-m-d');
        $rowDone = isset($occurrenceMap[$id . '|' . $date]);
        $entries[] = tl_entry($task, $date, $rowDone, true, $occurrenceMap);
    }

    /* 没有截止日的排在最后：tl_entry_sort_key 把 null 日期当成 9999-99-99。 */
    $entries = tl_sort_entries($entries, 'time');

    return ['entries' => $entries, 'count' => count($entries)];
}

/**
 * 把置顶区插到最前面，并重排编号（01 置顶，02 已逾期 …）。
 * 置顶区是扁平列表，不分分类，所以用 entries 而不是 groups。
 */
function tl_prepend_pinned(array $sections, array $pinned): array
{
    if ($pinned['count'] === 0) {
        return $sections;
    }
    $out = [[
        'id'      => 'pinned',
        'index'   => '01',
        'title'   => '置顶',
        'hint'    => '',
        'entries' => $pinned['entries'],
        'groups'  => [],
        'count'   => $pinned['count'],
        'empty'   => '',
        'open'    => true,
    ]];
    foreach ($sections as $offset => $section) {
        $section['index'] = str_pad((string) ($offset + 2), 2, '0', STR_PAD_LEFT);
        $out[] = $section;
    }
    return $out;
}


/**
 * 追踪中 —— 前台唯一的主视图，也是「进行中」「时间线」本身。
 * ---------------------------------------------------------------------------
 * 只有三段：
 *   01 已逾期        已截止但未完成（含重复任务漏掉的那一次），最多 10 条
 *   02 近 N 天       今天至 N 天后，逐日展开；逾期不掺进来
 *   03 未设截止日与远期，远期事项按截止时间升序
 *
 * 逾期和近 N 天两段内先按分类，再按截止时间。
 * 重复任务在区间内逐日展开；「每天」的任务于是这一周每天都会出现。
 */
function tl_front_tracked(array $filters = [], ?array $tasks = null): array
{
    $today = tl_today();
    $overdueLimit = max(1, (int) cfg('front.overdue_limit', 10));
    $weekTo = max(0, (int) cfg('front.week_to', 7));

    $tasks ??= tl_tasks($filters + ['status' => 'all', 'tracked' => true, 'not_done' => true]);
    $tasks = array_values(array_filter($tasks, 'tl_task_in_tracked_view'));
    $weekEnd = $today->modify('+' . $weekTo . ' days');
    $maxInterval = max([1, ...array_map(static fn (array $task): int => (int) ($task['repeat_interval'] ?? 1), $tasks)]);
    $occurrenceMap = tl_feed_occurrence_map($tasks, $today, $weekEnd->modify("+$maxInterval months"), true);
    $subitems = tl_due_subitems_by_task($today, $weekEnd);
    $weekParents = [];

    $week = [];
    $nodate = [];
    $later = [];
    $overdue = [];
    $seen = [];

    $push = static function (array &$bucket, array $task, string $date, bool $occurrence) use (&$seen, $occurrenceMap): void {
        $key = (int) $task['id'] . '@' . $date;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $tid = (int) $task['id'];
        $occDone = isset($occurrenceMap[$tid . '|' . $date]);
        $wholeDone = ($task['status'] ?? '') === 'done';
        $done = $wholeDone || ($occurrence && $occDone);
        $bucket[] = tl_entry($task, $date, $done, $occurrence, $occurrenceMap);
    };

    foreach ($tasks as $task) {
        $id = (int) $task['id'];
        $dueDate = tl_parse_date($task['due_date'] ?? null);
        $repeat = tl_task_repeat($task);
        $pinned = (int) $task['pinned'] === 1;
        if ($pinned && !isset($subitems[$id])) {
            continue;
        }
        $dates = $repeat === null ? [] : tl_occurrences_between($task, $today, $weekEnd);

        if (isset($subitems[$id])) {
            $weekParents[$id] = true;
            $date = $dates[0] ?? $task['due_date'];
            $occurrence = $repeat !== null && $date !== null;
            $done = $occurrence && isset($occurrenceMap[$id . '|' . $date]);
            $entry = tl_entry($task, $date, $done, $occurrence, $occurrenceMap);
            $entry['due_subitems'] = $subitems[$id];
            $week[] = $entry;
            if ($repeat === null || $pinned) {
                continue;
            }
            $seen[$entry['key']] = true;
        }

        if ($dueDate === null) {
            $key = (int) $task['id'] . '@-';
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $nodate[] = tl_entry($task, null, false, false, $occurrenceMap);
            }
            continue;
        }

        if ($repeat === null) {
            $ymd = $dueDate->format('Y-m-d');
            if ($dueDate < $today) {
                $push($overdue, $task, $ymd, false);
            } elseif ($dueDate <= $weekEnd) {
                $push($week, $task, $ymd, false);
            } else {
                $push($later, $task, $ymd, false);
            }
            continue;
        }

        /* 重复任务：近 N 天内逐日展开。 */
        foreach ($dates as $date) {
            $push($week, $task, $date, true);
        }
        /* 最近一次已过去、还没勾掉的发生 → 逾期。 */
        $past = tl_occurrence_before($task, $today);
        if ($past !== null) {
            $pastKey = $past->format('Y-m-d');
            if (!isset($occurrenceMap[(int) $task['id'] . '|' . $pastKey])) {
                $push($overdue, $task, $pastKey, true);
                continue;
            }
        }
        if ($dates === [] && !isset($weekParents[$id])) {
            // 各类重复周期都不会超过同等数量的月，锚点更晚时从锚点开始。
            $end = max($dueDate, $weekEnd->modify('+' . $repeat['interval'] . ' months'));
            $next = tl_occurrences_between($task, $weekEnd->modify('+1 day'), $end)[0] ?? null;
            if ($next !== null) {
                $push($later, $task, $next, true);
            }
        }
    }

    /* 逾期按「最近过去的排前面」；其余按日 + 时间。 */
    $overdue = array_slice(tl_sort_entries($overdue, 'time'), 0, $overdueLimit);

    $group = static fn (array $entries): array => tl_group_by_category(tl_sort_entries($entries));
    $remaining = array_merge(tl_sort_entries($nodate, 'created'), tl_sort_entries($later));

    return [
        'week_parents' => $weekParents,
        'sections' => [
            [
                'id'     => 'overdue',
                'index'  => '01',
                'title'  => '已逾期',
                'latin'  => 'OVERDUE',
                'hint'   => '',
                'groups' => $group($overdue),
                'count'  => count($overdue),
                'empty'  => '没有逾期事项。',
                'open'   => count($overdue) > 0,
            ],
            [
                'id'     => 'week',
                'index'  => '02',
                'title'  => sprintf('近%d天', $weekTo),
                'latin'  => 'THIS WEEK',
                'hint'   => '',
                'groups' => $group($week),
                'count'  => count($week),
                'empty'  => '这一周没有排期。',
                'open'   => count($week) > 0,
            ],
            [
                'id'      => 'nodate',
                'index'   => '03',
                'title'   => '未设截止日与远期',
                'latin'   => 'UNSCHEDULED & LATER',
                'hint'    => '',
                'entries' => $remaining,
                'count'   => count($remaining),
                'empty'   => '没有未设截止日或远期事项。',
                'open'    => count($remaining) > 0,
            ],
        ],
        'counts' => [
            'overdue' => count($overdue),
            'week'    => count($week),
            'nodate'  => count($remaining),
        ],
    ];
}

/**
 * 普通选项卡（已完成 / 规划中 / 全部）：按分类分组，重复任务只显示下一次发生。
 *
 * 「规划中」额外按逾期与否拆成两段（已逾期 / 未逾期），
 * 其余选项卡只返回一个按分类分组的列表。
 */
function tl_front_scope(string $scope, array $filters = []): array
{
    $status = in_array($scope, ['active', 'planned', 'done', 'all'], true) ? $scope : 'all';
    $scopeFilters = ['status' => $status, 'order' => 'due'];

    /* 「规划中」= 未完成且没有打追踪标记 —— 与「追踪中」互补，不重叠。
       注意不能用 status='planned'：那是任务自己的状态字段，和这个选项卡无关。
       「已完成」「全部」不按追踪状态切分。 */
    if ($scope === 'planned') {
        $scopeFilters['status'] = 'all';
        $scopeFilters['untracked'] = true;
        $scopeFilters['not_done'] = true;
        /* 置顶项单独成区，这里排除，避免重复出现。 */
        $scopeFilters['unpinned'] = true;
    }

    $tasks = tl_tasks($filters + $scopeFilters);
    $today = tl_today();
    $horizon = $today->modify('+' . max(1, (int) cfg('front.horizon_days', 365)) . ' days');
    $occurrenceMap = tl_feed_occurrence_map($tasks, $today, $horizon, false);

    $entries = [];
    $overdue = [];
    $upcoming = [];

    foreach ($tasks as $task) {
        $repeat = tl_task_repeat($task);
        $date = $task['due_date'] ?? null;
        $occurrence = false;
        $done = $task['status'] === 'done';

        if ($repeat !== null && !$done) {
            $next = tl_occurrences_between($task, $today, $horizon);
            if ($next !== []) {
                $date = $next[0];
                $occurrence = true;
            } else {
                $date = null;
                $occurrence = false;
            }
        }

        $rowDone = $done;
        if ($repeat !== null && $date !== null && !$done) {
            $rowDone = isset($occurrenceMap[(int) $task['id'] . '|' . $date]);
        }

        $entry = tl_entry($task, $date, $rowDone, $occurrence, $occurrenceMap);
        $entries[] = $entry;

        if ($scope === 'planned') {
            /* 逾期 = 有截止日且早于今天。没有截止日的事项不算逾期
               （它压根没排期，不是「错过」），归到未逾期一段。 */
            $dueDay = tl_parse_date($entry['date']);
            if ($dueDay !== null && $dueDay < $today) {
                $overdue[] = $entry;
            } else {
                $upcoming[] = $entry;
            }
        }
    }

    $result = [
        'entries' => $entries,
        'groups'  => $scope === 'planned' ? [] : tl_group_by_category(tl_sort_entries($entries, 'time')),
        'count'   => count($entries),
    ];

    if ($scope === 'planned') {
        $result['sections'] = [
            [
                'id'     => 'overdue',
                'index'  => '01',
                'title'  => '已逾期',
                'latin'  => 'OVERDUE',
                'hint'   => '已过截止日仍未开始',
                'groups' => tl_group_by_category(tl_sort_entries($overdue, 'time')),
                'count'  => count($overdue),
                'empty'  => '没有逾期的规划事项。',
                'open'   => count($overdue) > 0,
            ],
            [
                'id'     => 'upcoming',
                'index'  => '02',
                'title'  => '未逾期',
                'latin'  => 'UPCOMING',
                'hint'   => '还没到截止日',
                'groups' => tl_group_by_category(tl_sort_entries($upcoming, 'time')),
                'count'  => count($upcoming),
                'empty'  => '没有待开始的规划事项。',
                'open'   => count($upcoming) > 0,
            ],
        ];
    }

    return $result;
}

/**
 * 选项卡计数：只受分类与搜索影响。
 * 「追踪中」与「规划中」按追踪标记互补切分，所以这两个数加起来等于全部未完成数。
 */
function tl_front_counts(array $filters = []): array
{
    return tl_task_counts_sql($filters + ['status' => 'all']);
}
