<?php
/**
 * Bulk-set LAN-compatible category on a series of serials in old_serials.
 *
 * Examples:
 *   php set_serial_category.php --category=seller_to_end_user --prefix=M
 *   php set_serial_category.php --category=end_user_client --s-from=1000 --s-to=2000
 *   php set_serial_category.php --category=seller_to_end_user --s-from=440000 --s-to=450000
 *   php set_serial_category.php --category=seller_to_end_user --prefix=M --dry-run
 */
require_once __DIR__ . '/schema_helpers.php';

header('Content-Type: text/plain; charset=utf-8');

function cli_arg(string $key, ?string $default = null): ?string
{
    global $argv;
    if (PHP_SAPI === 'cli') {
        foreach ($argv ?? [] as $a) {
            if (str_starts_with($a, "--$key=")) {
                return substr($a, strlen($key) + 3);
            }
            if ($a === "--$key") {
                return '1';
            }
        }
        return $default;
    }
    return $_GET[$key] ?? $_POST[$key] ?? $default;
}

$category = payamesh_normalize_category(cli_arg('category', PAYAMESH_CATEGORY_END_USER));
$prefix = cli_arg('prefix');
$sFrom = cli_arg('s-from');
$sTo = cli_arg('s-to');
$dry = cli_arg('dry-run') !== null && cli_arg('dry-run') !== '0';

if ($prefix === null && ($sFrom === null || $sTo === null)) {
    echo "Usage:\n";
    echo "  --category=end_user_client|seller_to_end_user\n";
    echo "  --prefix=M          (UPPER(serial) LIKE 'M%')\n";
    echo "  --s-from=N --s-to=M (S numeric range inclusive)\n";
    echo "  --dry-run\n";
    exit(1);
}

$conn = payamesh_mysqli();
payamesh_ensure_unified_serial_columns($conn, false);

$nowMs = (int) (microtime(true) * 1000);
$where = [];
$types = '';
$params = [];

if ($prefix !== null && $prefix !== '') {
    $where[] = 'UPPER(serial) LIKE ?';
    $types .= 's';
    $params[] = strtoupper($prefix) . '%';
}
if ($sFrom !== null && $sTo !== null) {
    $where[] = "UPPER(serial) REGEXP '^S[0-9]+$'";
    $where[] = 'CAST(SUBSTRING(UPPER(serial), 2) AS UNSIGNED) BETWEEN ? AND ?';
    $types .= 'ii';
    $params[] = (int) $sFrom;
    $params[] = (int) $sTo;
}

$whereSql = implode(' AND ', $where);
$countSql = "SELECT COUNT(*) AS c FROM old_serials WHERE $whereSql";
$stmt = $conn->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

echo "Match: $count rows → category=$category\n";
if ($dry) {
    echo "Dry-run only.\n";
    exit(0);
}

$sql = "UPDATE old_serials SET category=?, sync_updated_ms=? WHERE $whereSql";
$typesUpd = 'si' . $types;
$paramsUpd = array_merge([$category, $nowMs], $params);
$stmt = $conn->prepare($sql);
$stmt->bind_param($typesUpd, ...$paramsUpd);
$stmt->execute();
echo "Updated: {$stmt->affected_rows}\n";
$stmt->close();
