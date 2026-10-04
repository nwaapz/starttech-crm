<?php
/**
 * Unify remote serials into old_serials (LAN-like single inventory).
 * Uses bulk SQL (fast) — safe for cPanel browser runs.
 *
 * Browser:
 *   /crm/install/unify_serials_seller.php?dry-run=1
 *   /crm/install/unify_serials_seller.php
 *   /crm/install/unify_serials_seller.php?merge-new-serials=1
 */
require_once __DIR__ . '/schema_helpers.php';

@set_time_limit(600);
@ini_set('memory_limit', '512M');
@ini_set('display_errors', '1');
@ini_set('max_execution_time', '600');

header('Content-Type: text/plain; charset=utf-8');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

function arg_flag(string $name): bool
{
    global $argv;
    if (PHP_SAPI !== 'cli') {
        return isset($_GET[$name]) || isset($_POST[$name]);
    }
    foreach ($argv ?? [] as $a) {
        if ($a === "--$name") {
            return true;
        }
    }
    return false;
}

function say(string $msg): void
{
    echo $msg . "\n";
    flush();
}

$dry = arg_flag('dry-run');
$mergeNew = arg_flag('merge-new-serials');

$conn = payamesh_mysqli();
payamesh_ensure_unified_serial_columns($conn, true);

$seller = PAYAMESH_CATEGORY_SELLER;
$nowMs = (int) round(microtime(true) * 1000);

$stats = [
    'mcode_total' => 0,
    'mcode_inserted' => 0,
    'mcode_updated' => 0,
    's440_flagged' => 0,
    'm_prefix_flagged' => 0,
    'new_merged' => 0,
];

say($dry ? '=== DRY RUN ===' : '=== APPLY (bulk SQL) ===');
say('Started: ' . date('c'));

// Ensure helpful index for joins (ignore if exists / no permission)
@$conn->query('CREATE INDEX idx_old_serials_serial_upper ON old_serials (serial)');
@$conn->query('CREATE INDEX idx_mcode_serial ON Mcode (serial)');

$mcheck = $conn->query("SHOW TABLES LIKE 'Mcode'");
if ($mcheck && $mcheck->num_rows > 0) {
    $stats['mcode_total'] = (int) ($conn->query('SELECT COUNT(*) AS c FROM Mcode')->fetch_assoc()['c'] ?? 0);
    say("Mcode rows: {$stats['mcode_total']}");

    // Counts (same for dry + apply preview)
    $qIns = $conn->query(
        "SELECT COUNT(*) AS c FROM Mcode m
         WHERE m.serial IS NOT NULL AND TRIM(m.serial) <> ''
           AND NOT EXISTS (
             SELECT 1 FROM old_serials o WHERE o.serial = m.serial
           )"
    );
    // Case-insensitive fallback if collation differs
    if (!$qIns) {
        $qIns = $conn->query(
            "SELECT COUNT(*) AS c FROM Mcode m
             WHERE m.serial IS NOT NULL AND TRIM(m.serial) <> ''
               AND NOT EXISTS (
                 SELECT 1 FROM old_serials o WHERE UPPER(o.serial)=UPPER(m.serial)
               )"
        );
    }
    $stats['mcode_inserted'] = (int) ($qIns->fetch_assoc()['c'] ?? 0);

    $qUpd = $conn->query(
        "SELECT COUNT(*) AS c FROM Mcode m
         INNER JOIN old_serials o ON o.serial = m.serial
         WHERE m.serial IS NOT NULL AND TRIM(m.serial) <> ''
           AND (
             o.category <> '$seller'
             OR IFNULL(o.score,0) < IFNULL(m.score,0)
             OR ((o.phone IS NULL OR o.phone = '') AND m.phone IS NOT NULL AND m.phone <> '' AND m.phone <> '0')
           )"
    );
    if (!$qUpd) {
        $qUpd = $conn->query(
            "SELECT COUNT(*) AS c FROM Mcode m
             INNER JOIN old_serials o ON UPPER(o.serial)=UPPER(m.serial)
             WHERE m.serial IS NOT NULL AND TRIM(m.serial) <> ''
               AND (
                 o.category <> '$seller'
                 OR IFNULL(o.score,0) < IFNULL(m.score,0)
                 OR ((o.phone IS NULL OR o.phone = '') AND m.phone IS NOT NULL AND m.phone <> '' AND m.phone <> '0')
               )"
        );
    }
    $stats['mcode_updated'] = (int) ($qUpd->fetch_assoc()['c'] ?? 0);

    say("Would/will INSERT from Mcode: {$stats['mcode_inserted']}");
    say("Would/will UPDATE existing: {$stats['mcode_updated']}");

    if (!$dry) {
        say('Bulk INSERT from Mcode…');
        $ok = $conn->query(
            "INSERT INTO old_serials (serial, phone, city, score, category, sync_updated_ms, time)
             SELECT m.serial,
                    NULLIF(NULLIF(m.phone, '0'), ''),
                    m.city,
                    IFNULL(m.score, 0),
                    '$seller',
                    $nowMs,
                    COALESCE(m.time, NOW())
             FROM Mcode m
             WHERE m.serial IS NOT NULL AND TRIM(m.serial) <> ''
               AND NOT EXISTS (
                 SELECT 1 FROM old_serials o WHERE o.serial = m.serial
               )"
        );
        if (!$ok) {
            // Retry with UPPER match
            say('Retry INSERT with UPPER(serial) match…');
            $ok = $conn->query(
                "INSERT INTO old_serials (serial, phone, city, score, category, sync_updated_ms, time)
                 SELECT m.serial,
                        NULLIF(NULLIF(m.phone, '0'), ''),
                        m.city,
                        IFNULL(m.score, 0),
                        '$seller',
                        $nowMs,
                        COALESCE(m.time, NOW())
                 FROM Mcode m
                 WHERE m.serial IS NOT NULL AND TRIM(m.serial) <> ''
                   AND NOT EXISTS (
                     SELECT 1 FROM old_serials o WHERE UPPER(o.serial)=UPPER(m.serial)
                   )"
            );
        }
        if (!$ok) {
            say('INSERT failed: ' . $conn->error);
            exit(1);
        }
        $stats['mcode_inserted'] = $conn->affected_rows;
        say("Inserted: {$stats['mcode_inserted']}");

        say('Bulk UPDATE existing from Mcode…');
        $ok = $conn->query(
            "UPDATE old_serials o
             INNER JOIN Mcode m ON o.serial = m.serial
             SET
               o.category = '$seller',
               o.score = GREATEST(IFNULL(o.score,0), IFNULL(m.score,0)),
               o.phone = CASE
                 WHEN (o.phone IS NULL OR o.phone = '')
                      AND m.phone IS NOT NULL AND m.phone <> '' AND m.phone <> '0'
                 THEN m.phone ELSE o.phone END,
               o.city = CASE
                 WHEN (o.city IS NULL OR o.city = '') AND m.city IS NOT NULL AND m.city <> ''
                 THEN m.city ELSE o.city END,
               o.sync_updated_ms = $nowMs"
        );
        if (!$ok) {
            $ok = $conn->query(
                "UPDATE old_serials o
                 INNER JOIN Mcode m ON UPPER(o.serial)=UPPER(m.serial)
                 SET
                   o.category = '$seller',
                   o.score = GREATEST(IFNULL(o.score,0), IFNULL(m.score,0)),
                   o.phone = CASE
                     WHEN (o.phone IS NULL OR o.phone = '')
                          AND m.phone IS NOT NULL AND m.phone <> '' AND m.phone <> '0'
                     THEN m.phone ELSE o.phone END,
                   o.city = CASE
                     WHEN (o.city IS NULL OR o.city = '') AND m.city IS NOT NULL AND m.city <> ''
                     THEN m.city ELSE o.city END,
                   o.sync_updated_ms = $nowMs"
            );
        }
        if (!$ok) {
            say('UPDATE failed: ' . $conn->error);
            exit(1);
        }
        $stats['mcode_updated'] = $conn->affected_rows;
        say("Updated: {$stats['mcode_updated']}");
    }
} else {
    say('Mcode table missing — skip M import');
}

// S440–S450
say('S440000–S450000…');
$cntRes = $conn->query(
    "SELECT COUNT(*) AS c FROM old_serials
     WHERE serial REGEXP '^S[0-9]+$'
       AND CAST(SUBSTRING(serial, 2) AS UNSIGNED) BETWEEN 440000 AND 450000
       AND category <> '$seller'"
);
$stats['s440_flagged'] = (int) ($cntRes->fetch_assoc()['c'] ?? 0);
say(($dry ? 'Would flag' : 'Will flag') . ": {$stats['s440_flagged']}");

if (!$dry) {
    $ok = $conn->query(
        "UPDATE old_serials
         SET category = '$seller', sync_updated_ms = $nowMs
         WHERE serial REGEXP '^S[0-9]+$'
           AND CAST(SUBSTRING(serial, 2) AS UNSIGNED) BETWEEN 440000 AND 450000
           AND category <> '$seller'"
    );
    if (!$ok) {
        say('S440 update failed: ' . $conn->error);
        exit(1);
    }
    $stats['s440_flagged'] = $conn->affected_rows;
    say("Flagged S440–S450: {$stats['s440_flagged']}");

    $ok = $conn->query(
        "UPDATE old_serials
         SET category = '$seller', sync_updated_ms = $nowMs
         WHERE serial LIKE 'M%' AND category <> '$seller'"
    );
    $stats['m_prefix_flagged'] = $ok ? $conn->affected_rows : 0;
    say("M-prefix flagged: {$stats['m_prefix_flagged']}");
}

if ($mergeNew) {
    say('new_serials merge…');
    $ncheck = $conn->query("SHOW TABLES LIKE 'new_serials'");
    if ($ncheck && $ncheck->num_rows > 0) {
        $q = $conn->query(
            "SELECT COUNT(*) AS c FROM new_serials n
             WHERE n.serial IS NOT NULL AND TRIM(n.serial) <> ''
               AND NOT EXISTS (SELECT 1 FROM old_serials o WHERE o.serial = n.serial)"
        );
        $stats['new_merged'] = (int) ($q->fetch_assoc()['c'] ?? 0);
        say(($dry ? 'Would merge' : 'Will merge') . ": {$stats['new_merged']}");
        if (!$dry) {
            // Default end_user; seller if M% or S440-450 (handled by later M%/S updates too)
            $ok = $conn->query(
                "INSERT INTO old_serials (serial, phone, city, km, score, category, sync_updated_ms, time)
                 SELECT n.serial, n.phone, n.city, n.km, IFNULL(n.score,0),
                        'end_user_client', $nowMs, COALESCE(n.time, NOW())
                 FROM new_serials n
                 WHERE n.serial IS NOT NULL AND TRIM(n.serial) <> ''
                   AND NOT EXISTS (SELECT 1 FROM old_serials o WHERE o.serial = n.serial)"
            );
            $stats['new_merged'] = $ok ? $conn->affected_rows : 0;
            say("Merged: {$stats['new_merged']}");
            // Re-apply seller flags for newly merged M / S440
            $conn->query(
                "UPDATE old_serials SET category='$seller', sync_updated_ms=$nowMs
                 WHERE (serial LIKE 'M%' OR (
                   serial REGEXP '^S[0-9]+$'
                   AND CAST(SUBSTRING(serial, 2) AS UNSIGNED) BETWEEN 440000 AND 450000
                 )) AND category <> '$seller'"
            );
        }
    }
}

say('');
say('Summary:');
foreach ($stats as $k => $v) {
    say("  $k = $v");
}
say('Finished: ' . date('c'));
say($dry ? 'Re-run WITHOUT ?dry-run=1 to apply.' : 'Done. Mcode left as backup.');
