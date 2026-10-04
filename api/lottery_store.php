<?php
/**
 * Remote lottery — seller-scoped pool from old_serials (not Mcode).
 */
declare(strict_types=1);

function lottery_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS winners (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            time DATETIME DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            score_at_win INT DEFAULT NULL,
            date_jalali VARCHAR(32) DEFAULT NULL,
            INDEX idx_winners_time (time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS lottery_suspended_phones (
            phone VARCHAR(20) NOT NULL PRIMARY KEY,
            reason VARCHAR(255) DEFAULT NULL,
            created_at BIGINT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    foreach ([
        'score_at_win' => 'INT DEFAULT NULL',
        'date_jalali' => 'VARCHAR(32) DEFAULT NULL',
        'city' => 'VARCHAR(100) DEFAULT NULL',
        'time' => 'DATETIME DEFAULT NULL',
    ] as $col => $def) {
        if (!column_exists($conn, 'winners', $col)) {
            @$conn->query("ALTER TABLE winners ADD COLUMN `$col` $def");
        }
    }
}

function lottery_is_stom_range(string $serial): bool
{
    $serial = strtoupper(trim($serial));
    if (!preg_match('/^S(\d+)$/', $serial, $m)) {
        return false;
    }
    $num = (int) $m[1];
    return $num >= 440000 && $num <= 450000;
}

function lottery_is_seller_row(array $row): bool
{
    if (lottery_is_stom_range((string) ($row['serial'] ?? ''))) {
        return true;
    }
    return trim((string) ($row['category'] ?? '')) === 'seller_to_end_user';
}

function lottery_norm_phone(string $phone): string
{
    if (function_exists('comms_normalize_phone')) {
        require_once __DIR__ . '/comms_store.php';
        return comms_normalize_phone($phone);
    }
    $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
    if ($digits !== '' && strpos($digits, '98') === 0 && strlen($digits) >= 12) {
        $digits = '0' . substr($digits, 2);
    }
    if ($digits !== '' && strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

/** @return list<string> */
function lottery_suspended_set(mysqli $conn): array
{
    lottery_ensure_schema($conn);
    $set = [];
    $res = $conn->query('SELECT phone FROM lottery_suspended_phones');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $p = lottery_norm_phone((string) ($row['phone'] ?? ''));
            if ($p !== '') {
                $set[$p] = true;
            }
        }
    }
    return array_keys($set);
}

/**
 * Seller pool: SUM(score) by phone from old_serials.
 *
 * @return list<array{phone:string,score:int,city:?string}>
 */
function lottery_list_participants(mysqli $conn, ?string $cityFilter = null, ?string $phoneFilter = null): array
{
    lottery_ensure_schema($conn);
    $table = serials_table($conn);
    if (!column_exists($conn, $table, 'score')) {
        return [];
    }
    $hasCat = column_exists($conn, $table, 'category');
    $hasCity = column_exists($conn, $table, 'city');
    $cols = 'serial, phone, score';
    if ($hasCat) {
        $cols .= ', category';
    }
    if ($hasCity) {
        $cols .= ', city';
    }
    $sql = "SELECT $cols FROM `$table`
            WHERE phone IS NOT NULL AND TRIM(phone) <> '' AND TRIM(phone) <> '0'
              AND score IS NOT NULL AND score > 0";
    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }

    $suspended = array_fill_keys(lottery_suspended_set($conn), true);
    /** @var array<string,array{phone:string,score:int,city:?string}> $byPhone */
    $byPhone = [];
    while ($row = $res->fetch_assoc()) {
        if (!lottery_is_seller_row($row)) {
            continue;
        }
        $phone = lottery_norm_phone((string) ($row['phone'] ?? ''));
        if ($phone === '' || isset($suspended[$phone])) {
            continue;
        }
        $score = (int) ($row['score'] ?? 0);
        if ($score <= 0) {
            continue;
        }
        $city = $hasCity ? trim((string) ($row['city'] ?? '')) : '';
        if ($city === '') {
            $city = null;
        }
        if (!isset($byPhone[$phone])) {
            $byPhone[$phone] = ['phone' => $phone, 'score' => 0, 'city' => $city];
        }
        $byPhone[$phone]['score'] += $score;
        if ($byPhone[$phone]['city'] === null && $city !== null) {
            $byPhone[$phone]['city'] = $city;
        }
    }

    $cityNeedle = $cityFilter !== null ? trim($cityFilter) : '';
    $phoneNeedle = $phoneFilter !== null ? lottery_norm_phone($phoneFilter) : '';
    if ($phoneNeedle === '' && $phoneFilter !== null) {
        $phoneNeedle = preg_replace('/\D/', '', trim($phoneFilter)) ?? '';
    }

    $items = [];
    foreach ($byPhone as $p) {
        if ($p['score'] <= 0) {
            continue;
        }
        if ($cityNeedle !== '') {
            $c = (string) ($p['city'] ?? '');
            if ($c === '' || mb_stripos($c, $cityNeedle) === false) {
                continue;
            }
        }
        if ($phoneNeedle !== '') {
            $hay = preg_replace('/\D/', '', $p['phone']) ?? '';
            if ($hay === '' || strpos($hay, $phoneNeedle) === false) {
                continue;
            }
        }
        $items[] = $p;
    }
    usort($items, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return $items;
}

function lottery_get_preset_phone(mysqli $conn): ?string
{
    require_once __DIR__ . '/settings_store.php';
    $data = crm_settings_get_or_default($conn, 'lottery_preset', crm_default_lottery_preset());
    $phone = isset($data['phone']) ? trim((string) $data['phone']) : '';
    if ($phone === '') {
        return null;
    }
    $n = lottery_norm_phone($phone);
    return $n !== '' ? $n : null;
}

/**
 * @param list<array{phone:string,score:int,city:?string}> $participants
 * @return array{phone:string,score:int,city:?string}|null
 */
function lottery_draw_weighted(mysqli $conn, array $participants): ?array
{
    if ($participants === []) {
        return null;
    }
    $preset = lottery_get_preset_phone($conn);
    if ($preset !== null) {
        foreach ($participants as $p) {
            if ($p['phone'] === $preset) {
                return $p;
            }
        }
    }
    $total = 0;
    foreach ($participants as $p) {
        $total += max(0, (int) $p['score']);
    }
    if ($total <= 0) {
        return $participants[array_rand($participants)];
    }
    $ticket = random_int(0, $total - 1);
    foreach ($participants as $p) {
        $ticket -= max(0, (int) $p['score']);
        if ($ticket < 0) {
            return $p;
        }
    }
    return $participants[count($participants) - 1];
}

function lottery_winner_to_api(array $row): array
{
    $time = (string) ($row['time'] ?? '');
    $wonAt = 0;
    if ($time !== '') {
        $ts = strtotime($time);
        if ($ts !== false) {
            $wonAt = (int) ($ts * 1000);
        }
    }
    return [
        'id' => (int) ($row['id'] ?? 0),
        'phone' => lottery_norm_phone((string) ($row['phone'] ?? '')),
        'city' => isset($row['city']) && trim((string) $row['city']) !== '' ? trim((string) $row['city']) : null,
        'scoreAtWin' => (int) ($row['score_at_win'] ?? 0),
        'dateJalali' => (string) ($row['date_jalali'] ?? ''),
        'wonAt' => $wonAt,
    ];
}

function lottery_list_winners(mysqli $conn, int $page, int $limit): array
{
    lottery_ensure_schema($conn);
    $page = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;
    $total = 0;
    $res = $conn->query('SELECT COUNT(*) AS c FROM winners');
    if ($res && ($row = $res->fetch_assoc())) {
        $total = (int) ($row['c'] ?? 0);
    }
    $items = [];
    $stmt = $conn->prepare('SELECT * FROM winners ORDER BY id DESC LIMIT ? OFFSET ?');
    if ($stmt) {
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        foreach (stmt_fetch_all_assoc($stmt) as $row) {
            $items[] = lottery_winner_to_api($row);
        }
        $stmt->close();
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
}

function lottery_list_suspended(mysqli $conn): array
{
    lottery_ensure_schema($conn);
    $items = [];
    $res = $conn->query('SELECT phone, reason, created_at FROM lottery_suspended_phones ORDER BY created_at DESC');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = [
                'phone' => lottery_norm_phone((string) ($row['phone'] ?? '')),
                'reason' => $row['reason'] ?? null,
                'createdAt' => isset($row['created_at']) ? (int) $row['created_at'] : null,
            ];
        }
    }
    return ['items' => $items];
}

function lottery_zero_seller_scores(mysqli $conn, string $phone): void
{
    $phone = lottery_norm_phone($phone);
    $table = serials_table($conn);
    if (!column_exists($conn, $table, 'score')) {
        return;
    }
    $hasCat = column_exists($conn, $table, 'category');
    $cols = 'id, serial, phone, score';
    if ($hasCat) {
        $cols .= ', category';
    }
    $stmt = $conn->prepare(
        "SELECT $cols FROM `$table` WHERE phone = ? AND score IS NOT NULL AND score > 0"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $rows = stmt_fetch_all_assoc($stmt);
    $stmt->close();
    foreach ($rows as $row) {
        if (!lottery_is_seller_row($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $u = $conn->prepare("UPDATE `$table` SET score = 0 WHERE id = ?");
            if ($u) {
                $u->bind_param('i', $id);
                $u->execute();
                $u->close();
            }
        } else {
            $serial = (string) ($row['serial'] ?? '');
            $u = $conn->prepare("UPDATE `$table` SET score = 0 WHERE serial = ? AND phone = ?");
            if ($u) {
                $u->bind_param('ss', $serial, $phone);
                $u->execute();
                $u->close();
            }
        }
    }

    if (table_exists($conn, 'Mcode') && column_exists($conn, 'Mcode', 'score')) {
        $u = $conn->prepare('UPDATE Mcode SET score = 0 WHERE phone = ?');
        if ($u) {
            $u->bind_param('s', $phone);
            $u->execute();
            $u->close();
        }
    }
}

/**
 * @return array{id:int,phone:string,city:?string,scoreAtWin:int,dateJalali:string,wonAt:int}
 */
function lottery_confirm_winner(mysqli $conn, string $phone): array
{
    lottery_ensure_schema($conn);
    $phone = lottery_norm_phone($phone);
    if ($phone === '') {
        throw new InvalidArgumentException('شماره نامعتبر است');
    }
    $participants = lottery_list_participants($conn, null, null);
    $match = null;
    foreach ($participants as $p) {
        if ($p['phone'] === $phone) {
            $match = $p;
            break;
        }
    }
    if ($match === null) {
        throw new InvalidArgumentException('این شماره در جمع شرکت‌کنندگان فروشنده نیست');
    }
    $score = (int) $match['score'];
    $city = $match['city'] !== null ? (string) $match['city'] : '';
    $jalali = today_jalali_date();
    $time = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        'INSERT INTO winners (phone, time, city, score_at_win, date_jalali) VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('ثبت برنده ناموفق بود');
    }
    $stmt->bind_param('sssis', $phone, $time, $city, $score, $jalali);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    lottery_zero_seller_scores($conn, $phone);

    require_once __DIR__ . '/settings_store.php';
    crm_settings_put($conn, 'lottery_preset', ['phone' => null]);

    if (is_file(__DIR__ . '/activity_store.php')) {
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'lottery_winner', [
            'phone' => $phone,
            'score' => $score,
            'message' => 'برنده قرعه‌کشی: ' . $phone,
        ]);
    }

    $wonAt = (int) (strtotime($time) * 1000);
    return [
        'id' => $id,
        'phone' => $phone,
        'city' => $city !== '' ? $city : null,
        'scoreAtWin' => $score,
        'dateJalali' => $jalali,
        'wonAt' => $wonAt,
    ];
}
