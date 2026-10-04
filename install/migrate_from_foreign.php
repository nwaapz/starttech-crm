<?php
/**
 * Mode C — create canonical DB and import from a foreign schema using a mapping JSON.
 *
 * mapping.json example:
 * {
 *   "source": {"host":"localhost","username":"u","password":"p","database":"old"},
 *   "target": {"host":"localhost","username":"u","password":"p","database":"payamesh_crm"},
 *   "tables": [
 *     {"target":"old_serials","from":"warranty","columns":{"serial":"code","phone":"mobile","km":"odo","city":"town","time":"reg_at"}}
 *   ],
 *   "dryRun": true
 * }
 *
 * CLI: php migrate_from_foreign.php mapping.json
 */
header('Content-Type: text/plain; charset=utf-8');

$mappingFile = $argv[1] ?? (__DIR__ . '/mapping.example.json');
if (!is_file($mappingFile)) {
    echo "Usage: php migrate_from_foreign.php mapping.json\n";
    echo "See mapping.example.json\n";
    exit(1);
}

$map = json_decode(file_get_contents($mappingFile), true);
if (!is_array($map)) {
    fwrite(STDERR, "Invalid mapping JSON\n");
    exit(1);
}

$src = $map['source'];
$dst = $map['target'];
$dry = !empty($map['dryRun']);

$source = new mysqli($src['host'], $src['username'], $src['password'], $src['database']);
if ($source->connect_error) {
    fwrite(STDERR, "Source: {$source->connect_error}\n");
    exit(1);
}
$target = new mysqli($dst['host'], $dst['username'], $dst['password']);
if ($target->connect_error) {
    fwrite(STDERR, "Target connect: {$target->connect_error}\n");
    exit(1);
}

$dbName = $dst['database'];
if (!$dry) {
    $target->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
}
$target->select_db($dbName);
if (!$dry) {
    $sql = file_get_contents(__DIR__ . '/canonical_schema.sql');
    $target->multi_query($sql);
    while ($target->more_results() && $target->next_result()) { /* drain */ }
}

function latinDigits(string $v): string {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace(array_merge($persian, $arabic), array_merge($latin, $latin), $v);
}

foreach ($map['tables'] as $tableMap) {
    $from = $tableMap['from'];
    $to = $tableMap['target'];
    $cols = $tableMap['columns'];
    $selectCols = array_values($cols);
    $selectSql = 'SELECT `' . implode('`, `', array_map(fn ($c) => str_replace('`', '', $c), $selectCols)) . "` FROM `$from`";
    $res = $source->query($selectSql);
    if (!$res) {
        echo "SKIP $from: {$source->error}\n";
        continue;
    }
    $inserted = 0;
    $skipped = 0;
    $targetKeys = array_keys($cols);
    while ($row = $res->fetch_assoc()) {
        $values = [];
        foreach ($cols as $targetCol => $sourceCol) {
            $raw = $row[$sourceCol] ?? null;
            if (is_string($raw)) {
                $raw = latinDigits(trim($raw));
            }
            $values[$targetCol] = $raw;
        }
        if (empty($values['serial'] ?? null) && $to !== 'users') {
            $skipped++;
            continue;
        }
        if ($dry) {
            $inserted++;
            continue;
        }
        $fields = array_keys($values);
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $fieldSql = '`' . implode('`,`', $fields) . '`';
        $updates = [];
        foreach ($fields as $f) {
            if ($f === 'serial' || $f === 'name') {
                continue;
            }
            $updates[] = "`$f`=VALUES(`$f`)";
        }
        $dup = $updates ? (' ON DUPLICATE KEY UPDATE ' . implode(',', $updates)) : '';
        $sql = "INSERT INTO `$to` ($fieldSql) VALUES ($placeholders)$dup";
        $stmt = $target->prepare($sql);
        if (!$stmt) {
            echo "prepare fail $to: {$target->error}\n";
            break;
        }
        $types = str_repeat('s', count($values));
        $bind = [];
        foreach ($values as $v) {
            $bind[] = $v === null ? null : (string) $v;
        }
        $stmt->bind_param($types, ...$bind);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
        }
        $stmt->close();
    }
    echo "$from → $to: inserted=$inserted skipped=$skipped dryRun=" . ($dry ? 'yes' : 'no') . "\n";
}

echo "Done. Point config/database.php at target DB `{$dst['database']}`.\n";
