<?php
declare(strict_types=1);

/**
 * 写操作入口（全部 POST + CSRF）
 * ---------------------------------------------------------------------------
 *   op=complete     标记完成 / 未完成（支持重复任务的单次发生）
 *   op=track        添加 / 移除追踪
 *   op=pin          置顶 / 取消置顶
 *   op=bulk         多选后一键设置状态或删除
 *   op=task_save    新增 / 编辑待办
 *   op=task_delete  删除单条待办
 *   op=cat_save     新增 / 重命名分类
 *   op=cat_delete   删除分类（一并删除 / 归为未分类）
 *   op=purge        清除完成超过 7 天的待办
 *   op=subitem_add / subitem_delete / subitem_done  子任务
 *
 * 所有操作都需要后台登录。
 */

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/backup.php';

tl_boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tl_redirect(tl_front_url());
}

tl_csrf_check();

$op = (string) ($_POST['op'] ?? '');
$isAjax = isset($_POST['ajax']);
$back = tl_safe_path($_POST['back'] ?? null, tl_front_url());

/** 统一收尾：AJAX 返回 JSON，否则写 flash 再跳回。 */
$finish = static function (string $message, string $type = 'ok', ?string $redirect = null, array $extra = []) use ($isAjax, $back): never {
    if ($isAjax) {
        tl_json(array_merge(['ok' => $type !== 'error', 'message' => $message], $extra));
    }
    tl_flash($type, $message);
    tl_redirect($redirect ?? $back);
};

try {
    if (!tl_admin()) {
        $finish('需要后台登录才能操作。', 'error');
    }
    switch ($op) {
        /* ---------------------------------------------------------- 完成 / 追踪 */
        case 'complete': {
            $id = (int) ($_POST['id'] ?? 0);
            $scope = (string) ($_POST['scope'] ?? 'task');
            $date = trim((string) ($_POST['date'] ?? ''));
            $done = (string) ($_POST['done'] ?? '1') === '1';
            $completedAt = $done ? tl_parse_completion_input((string) ($_POST['completed_at'] ?? '')) : null;

            if ($scope === 'occurrence') {
                if ($date === '') {
                    $finish('缺少发生日期。', 'error');
                }
                $task = tl_complete_occurrence($id, $date, $done, $completedAt);
            } else {
                $task = tl_task_set_status($id, $done ? 'done' : 'active', $completedAt);
            }
            $label = $task['title'] ?? '';
            $finish(
                ($completedAt !== null ? '已更新完成时间：' : ($done ? '已标记完成：' : '已标记未完成：')) . $label,
                'ok',
                $back,
                ['task_id' => $id, 'status' => $task['status'] ?? null]
            );
        }

        case 'track': {
            $id = (int) ($_POST['id'] ?? 0);
            if (!isset($_POST['tracked'])) {
                $finish('缺少追踪目标状态。', 'error');
            }
            $task = tl_task_set_tracked($id, (int) ((string) $_POST['tracked'] === '1'));
            $finish(
                ((int) $task['tracked'] === 1 ? '已添加追踪：' : '已移除追踪：') . $task['title'],
                'ok',
                $back,
                ['task_id' => $id, 'tracked' => (int) $task['tracked']]
            );
        }

        case 'pin': {
            $id = (int) ($_POST['id'] ?? 0);
            if (!isset($_POST['pinned'])) {
                $finish('缺少置顶目标状态。', 'error');
            }
            $task = tl_task_set_pin($id, (int) ((string) $_POST['pinned'] === '1'));
            $finish(
                ((int) $task['pinned'] === 1 ? '已置顶：' : '已取消置顶：') . $task['title'],
                'ok',
                $back,
                ['task_id' => $id, 'pinned' => (int) $task['pinned']]
            );
        }

        /* ------------------------------------------------------------- 批量操作 */
        case 'bulk': {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn ($id) => $id > 0)));
            $bulkOp = (string) ($_POST['bulk_op'] ?? '');
            if ($ids === []) {
                $finish('没有选中任何事项。', 'error');
            }

            if ($bulkOp === 'delete') {
                $count = tl_tasks_delete($ids);
                $finish('已删除 ' . $count . ' 条待办（按唯一任务计）。');
            }

            if (str_starts_with($bulkOp, 'status:')) {
                $status = substr($bulkOp, 7);
                $count = tl_tasks_set_status($ids, $status);
                $hint = $status === 'done'
                    ? '（重复任务为整串完成，并取消追踪）'
                    : '';
                $finish('已将 ' . $count . ' 条待办设为「' . tl_status_label($status) . '」。' . $hint);
            }

            $finish('未知的批量操作。', 'error');
        }

        /* --------------------------------------------------------------- 待办 */
        case 'task_save': {
            $id = (int) ($_POST['id'] ?? 0);
            $input = [
                'title'           => $_POST['title'] ?? '',
                'detail'          => $_POST['detail'] ?? '',
                'category_id'     => $_POST['category_id'] ?? '',
                'due_date'        => $_POST['due_date'] ?? '',
                'due_time'        => $_POST['due_time'] ?? '',
                'completed_at'    => $_POST['completed_at'] ?? '',
                'status'          => $_POST['status'] ?? 'active',
                'repeat_unit'     => $_POST['repeat_unit'] ?? '',
                'repeat_interval' => $_POST['repeat_interval'] ?? 1,
                'tracked'         => !empty($_POST['tracked']),
                'pinned'          => !empty($_POST['pinned']),
                'repeat_change_confirm' => !empty($_POST['repeat_change_confirm']),
            ];
            /* 保存后一律回到「新增待办」模式，编辑与新增都一样 ——
               保留列表的筛选条件，但不要带 edit 参数，否则右侧会停在编辑态。 */
            $listQuery = [];
            $backQuery = (string) ($_POST['back'] ?? '');
            if ($backQuery !== '') {
                $parsed = parse_url($backQuery, PHP_URL_QUERY);
                if (is_string($parsed)) {
                    parse_str($parsed, $listQuery);
                }
            }
            unset($listQuery['edit']);
            $listQuery['view'] = 'tasks';
            $listUrl = tl_admin_url($listQuery);

            $rows = [];
            $titles = (array) ($_POST['subitem_title'] ?? []);
            foreach ($titles as $i => $title) {
                $rows[] = [
                    'id'           => (int) ($_POST['subitem_id'][$i] ?? 0),
                    'title'        => is_string($title) ? $title : '',
                    'done'         => !empty($_POST['subitem_done'][$i]),
                    'due_date'     => $_POST['subitem_due_date'][$i] ?? '',
                    'due_time'     => $_POST['subitem_due_time'][$i] ?? '',
                ];
                if (isset($_POST['subitem_completed_at'][$i])) {
                    $rows[array_key_last($rows)]['completed_at'] = $_POST['subitem_completed_at'][$i];
                }
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($id > 0) {
                    tl_task_update($id, $input);
                    $taskId = $id;
                } else {
                    $taskId = tl_task_create($input);
                }
                tl_subitems_save_rows($taskId, $rows);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $returnUrl = tl_task_edit_return($id, $_POST['from'] ?? null);
            $finish($id > 0 ? '已保存修改。' : '已添加待办。', 'ok', $returnUrl ?? $listUrl);
        }

        case 'subitem_add': {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $title = (string) ($_POST['title'] ?? '');
            tl_subitem_add($taskId, $title, $_POST);
            $finish('已添加子任务。');
        }

        case 'subitem_delete': {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $subId = (int) ($_POST['subitem_id'] ?? 0);
            tl_subitem_delete($subId, $taskId);
            $finish('已删除子任务。');
        }

        case 'subitem_done': {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $subId = (int) ($_POST['subitem_id'] ?? 0);
            $done = (string) ($_POST['done'] ?? '1') === '1';
            $completedAt = $done ? tl_parse_completion_input($_POST['completed_at'] ?? '') : null;
            tl_subitem_set_done($subId, $taskId, $done, $completedAt, array_key_exists('completed_at', $_POST));
            $finish($completedAt !== null ? '已更新子任务完成时间。' : ($done ? '已完成子任务。' : '已恢复子任务为未完成。'));
        }

        case 'task_delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $task = tl_task($id);
            if ($task === null) {
                $finish('待办不存在。', 'error');
            }
            tl_task_delete($id);
            $finish('已删除：' . $task['title']);
        }

        /* --------------------------------------------------------------- 分类 */
        case 'cat_save': {
            $id = (int) ($_POST['id'] ?? 0);
            $name = (string) ($_POST['name'] ?? '');
            if ($id > 0) {
                tl_category_rename($id, $name);
                $finish('分类已重命名为「' . trim($name) . '」。', 'ok', tl_admin_url(['view' => 'categories']));
            }
            tl_category_create($name);
            $finish('已添加分类「' . trim($name) . '」。', 'ok', tl_admin_url(['view' => 'categories']));
        }

        case 'cat_delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $strategy = (string) ($_POST['strategy'] ?? 'uncategorize');
            $strategy = $strategy === 'delete' ? 'delete' : 'uncategorize';
            $result = tl_category_delete($id, $strategy);
            $message = $strategy === 'delete'
                ? '已删除分类「' . $result['category'] . '」及其下 ' . $result['tasks'] . ' 条待办。'
                : '已删除分类「' . $result['category'] . '」，' . $result['tasks'] . ' 条待办归为未分类。';
            $finish($message, 'ok', tl_admin_url(['view' => 'categories']));
        }

        /* --------------------------------------------------------------- 清理 */
        case 'export': {
            $format = $_POST['format'] ?? null;
            $stamp = tl_now()->format('Ymd-His');
            if ($format === 'json') {
                $json = json_encode(tl_backup_export_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                header('Content-Type: application/json; charset=UTF-8');
                header('Content-Disposition: attachment; filename="todo-' . $stamp . '.json"');
                echo $json, "\n";
                exit;
            }
            if ($format !== 'sqlite') {
                throw new InvalidArgumentException('请选择 JSON 或 SQLite 备份格式。');
            }
            $path = tl_backup_export_sqlite();
            try {
                header('Content-Type: application/vnd.sqlite3');
                header('Content-Disposition: attachment; filename="todo-' . $stamp . '.sqlite"');
                header('Content-Length: ' . filesize($path));
                readfile($path);
            } finally {
                unlink($path);
            }
            exit;
        }

        case 'import_preview':
        case 'import': {
            $file = $_FILES['backup'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('请选择可上传的 JSON 备份，文件大小须在 10 MB 以内。');
            }
            $path = $file['tmp_name'];
            if (!is_uploaded_file($path) || $file['size'] > 10 * 1024 * 1024) {
                throw new InvalidArgumentException('JSON 备份大小须在 10 MB 以内。');
            }
            try {
                $json = (string) file_get_contents($path);
                $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new InvalidArgumentException('所选文件不是有效的 JSON 备份。', 0, $error);
            }
            if (!is_array($payload)) {
                throw new InvalidArgumentException('所选文件不是有效的 JSON 备份。');
            }
            $digest = hash_hmac('sha256', $json, tl_csrf_token());
            if ($op === 'import_preview') {
                $counts = tl_backup_validate($payload);
                $message = sprintf('备份时间：%s。包含 %d 个分类、%d 项待办、%d 条发生记录和 %d 个子任务。确认后将覆盖当前数据。',
                    $payload['exported_at'] ?? '未知', $counts['categories'], $counts['tasks'],
                    $counts['task_occurrences'], $counts['task_subitems']);
                $finish($message, extra: ['digest' => $digest]);
            }
            if (!hash_equals($digest, (string) ($_POST['backup_digest'] ?? ''))) {
                throw new InvalidArgumentException('请先预览当前选中的备份。');
            }
            $count = tl_backup_import_json($payload, savePrevious: true);
            $finish(sprintf('已恢复 %d 个分类、%d 项待办、%d 条发生记录和 %d 个子任务。',
                $count['categories'], $count['tasks'], $count['task_occurrences'], $count['task_subitems']),
                'ok', tl_admin_url(['view' => 'system']));
        }

        case 'import_rollback': {
            $previous = tl_backup_previous();
            if ($previous === null) {
                throw new InvalidArgumentException('没有可恢复的导入前快照。');
            }
            tl_backup_import_json($previous, savePrevious: true);
            $finish('已恢复上次操作前的数据。', 'ok', tl_admin_url(['view' => 'system']));
        }

        case 'revoke_sessions': {
            tl_session_bump_version();
            tl_auth_clear('admin');
            tl_auth_clear('front');
            tl_flash('ok', '已注销所有网页会话。');
            tl_redirect(tl_login_url());
        }

        case 'purge': {
            $count = tl_purge_done((int) cfg('purge_days', 7));
            $finish('已清除 ' . $count . ' 条完成超过 ' . (int) cfg('purge_days', 7) . ' 天的待办。', 'ok', tl_admin_url());
        }

        default:
            $finish('未知操作。', 'error');
    }
} catch (InvalidArgumentException $error) {
    $finish($error->getMessage(), 'error');
} catch (Throwable $error) {
    if ((bool) cfg('app.debug', false)) {
        $finish($error->getMessage(), 'error');
    }
    error_log('action.php: ' . $error->getMessage());
    $finish('操作失败，请稍后再试。', 'error');
}
