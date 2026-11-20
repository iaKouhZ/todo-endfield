<?php
declare(strict_types=1);

const TL_BACKUP_TABLES = [
    'categories' => [
        'id' => 'id', 'name' => 'text', 'position' => 'int', 'created_at' => 'timestamp',
    ],
    'tasks' => [
        'id' => 'id', 'title' => 'text', 'detail' => '?string', 'category_id' => '?id',
        'due_date' => '?date', 'due_time' => '?time', 'status' => 'status',
        'repeat_unit' => '?repeat', 'repeat_interval' => '?id', 'tracked' => 'flag',
        'pinned' => 'flag', 'completed_at' => '?timestamp', 'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ],
    'task_occurrences' => ['task_id' => 'id', 'occur_date' => 'date', 'completed_at' => 'timestamp'],
    'task_subitems' => [
        'id' => 'id', 'task_id' => 'id', 'title' => 'text', 'due_date' => '?date',
        'due_time' => '?time', 'done' => 'flag', 'completed_at' => '?timestamp',
        'position' => 'int', 'created_at' => 'timestamp', 'updated_at' => 'timestamp',
    ],
];

function tl_backup_read(PDO $pdo): array
{
    $out = ['version' => 1, 'exported_at' => tl_now()->format('Y-m-d H:i:s')];
    foreach (TL_BACKUP_TABLES as $table => $columns) {
        $order = $table === 'task_occurrences' ? 'task_id, occur_date' : 'id';
        $out[$table] = $pdo->query(
            'SELECT ' . implode(', ', array_keys($columns)) . " FROM $table ORDER BY $order"
        )->fetchAll();
    }
    return $out;
}

function tl_backup_previous(): ?array
{
    $json = db()->query("SELECT value FROM app_meta WHERE key = 'backup_previous'")->fetchColumn();
    return $json === false ? null : json_decode($json, true, 64, JSON_THROW_ON_ERROR);
}

function tl_backup_export_json(): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $out = tl_backup_read($pdo);
        $pdo->commit();
        return $out;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function tl_backup_export_sqlite(): string
{
    $path = tempnam(sys_get_temp_dir(), 'todo-backup-');
    if ($path === false) {
        throw new RuntimeException('无法创建备份临时文件。');
    }
    try {
        db()->prepare('VACUUM INTO ?')->execute([$path]);
        return $path;
    } catch (Throwable $error) {
        unlink($path);
        throw $error;
    }
}

function tl_backup_valid_value(mixed $value, string $type): bool
{
    if (str_starts_with($type, '?')) {
        if ($value === null) {
            return true;
        }
        $type = substr($type, 1);
    }
    return match ($type) {
        'id' => is_int($value) && $value > 0,
        'int' => is_int($value),
        'flag' => $value === 0 || $value === 1,
        'text' => is_string($value) && trim($value) !== '',
        'string' => is_string($value),
        'status' => is_string($value) && isset(TL_STATUSES[$value]),
        'repeat' => in_array($value, ['day', 'week', 'month'], true),
        'date' => is_string($value) && !str_contains($value, chr(0)) && tl_parse_date($value)?->format('Y-m-d') === $value,
        'time' => is_string($value) && tl_parse_time($value) === $value,
        'timestamp' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1
            && ($stamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, tl_tz())) !== false
            && $stamp->format('Y-m-d H:i:s') === $value,
    };
}

function tl_backup_validate(array $payload): array
{
    if (($payload['version'] ?? null) !== 1) {
        throw new InvalidArgumentException('不支持的 JSON 备份版本。');
    }
    if (isset($payload['exported_at']) && !tl_backup_valid_value($payload['exported_at'], 'timestamp')) {
        throw new InvalidArgumentException('备份缺少有效的备份时间。');
    }
    $counts = [];
    $primaryKeys = [];
    foreach (TL_BACKUP_TABLES as $table => $columns) {
        if (!is_array($payload[$table] ?? null) || !array_is_list($payload[$table])) {
            throw new InvalidArgumentException("备份缺少有效的 $table 数据。");
        }
        foreach ($payload[$table] as $index => $row) {
            if (!is_array($row) || array_diff_key($row, $columns) || array_diff_key($columns, $row)) {
                throw new InvalidArgumentException("备份 $table 第 " . ($index + 1) . ' 行字段不完整或含未知字段。');
            }
            foreach ($columns as $field => $type) {
                if (!tl_backup_valid_value($row[$field], $type)) {
                    throw new InvalidArgumentException("备份 $table 第 " . ($index + 1) . " 行的 $field 无效。");
                }
            }
            $key = $table === 'task_occurrences' ? $row['task_id'] . '|' . $row['occur_date'] : $row['id'];
            if (isset($primaryKeys[$table][$key])) {
                throw new InvalidArgumentException("备份 $table 含重复记录。");
            }
            $primaryKeys[$table][$key] = true;
            if ($table === 'tasks' && $row['category_id'] !== null && !isset($primaryKeys['categories'][$row['category_id']])
                || ($table === 'task_occurrences' || $table === 'task_subitems') && !isset($primaryKeys['tasks'][$row['task_id']])) {
                throw new InvalidArgumentException("备份 $table 含无效的关联 ID。");
            }
        }
        $counts[$table] = count($payload[$table]);
    }

    return $counts;
}

function tl_backup_import_json(array $payload, bool $savePrevious = false): array
{
    $counts = tl_backup_validate($payload);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($savePrevious) {
            $json = json_encode(tl_backup_read($pdo), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $pdo->prepare("INSERT INTO app_meta (key, value) VALUES ('backup_previous', ?)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([$json]);
        }
        foreach (array_reverse(array_keys(TL_BACKUP_TABLES)) as $table) {
            $pdo->exec("DELETE FROM $table");
        }
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('categories', 'tasks', 'task_subitems')");
        foreach (TL_BACKUP_TABLES as $table => $columns) {
            $names = array_keys($columns);
            $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES ('
                . implode(', ', array_fill(0, count($names), '?')) . ')';
            $stmt = $pdo->prepare($sql);
            foreach ($payload[$table] as $row) {
                $stmt->execute(array_map(static fn (string $name): mixed => $row[$name], $names));
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw new InvalidArgumentException('备份导入失败，现有数据已保留。请检查重复记录和关联 ID。', 0, $error);
    }
    tl_categories_reset();
    return $counts;
}
