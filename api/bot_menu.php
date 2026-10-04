<?php
declare(strict_types=1);

/**
 * Bot reply keyboard menus — mirrors Android BotMenuHelper / BotMenuReplies.
 */
function bot_menu_items(): array
{
    return [
        'register_warranty' => 'ثبت گارانتی',
        'warranty_status' => 'وضعیت گارانتی',
        'send_product_photo' => 'ارسال عکس محصول',
        'invite_friends' => 'دعوت دوستان',
        'my_score' => 'امتیاز من',
        'support' => 'پشتیبانی',
        'help' => 'راهنما',
    ];
}

function bot_menu_settings_for(mysqli $conn, string $channel): array
{
    require_once __DIR__ . '/settings_store.php';
    return crm_settings_get_or_default($conn, $channel . '_settings', crm_default_bot_menu());
}

function bot_menu_enabled_labels(mysqli $conn, string $channel): array
{
    $settings = bot_menu_settings_for($conn, $channel);
    $map = [
        'register_warranty' => 'menuRegisterWarranty',
        'warranty_status' => 'menuWarrantyStatus',
        'send_product_photo' => 'menuSendProductPhoto',
        'invite_friends' => 'menuInviteFriends',
        'my_score' => 'menuMyScore',
        'support' => 'menuSupport',
        'help' => 'menuHelp',
    ];
    $labels = [];
    foreach (bot_menu_items() as $id => $label) {
        $key = $map[$id] ?? null;
        if ($key === null) {
            continue;
        }
        if (!empty($settings[$key])) {
            $labels[] = $label;
        }
    }
    return $labels;
}

/** Layout rows as 2+2+… like Android. */
function bot_menu_keyboard_rows(array $labels): array
{
    if ($labels === []) {
        return [];
    }
    $rows = [];
    $index = 0;
    $n = count($labels);
    while ($index < $n) {
        $remaining = $n - $index;
        $take = ($remaining === 1) ? 1 : min(2, $remaining);
        $rows[] = array_slice($labels, $index, $take);
        $index += $take;
    }
    return $rows;
}

function bot_menu_match_label(string $text, mysqli $conn, string $channel): ?string
{
    $trimmed = trim($text);
    foreach (bot_menu_enabled_labels($conn, $channel) as $label) {
        if ($label === $trimmed) {
            foreach (bot_menu_items() as $id => $l) {
                if ($l === $label) {
                    return $id;
                }
            }
        }
    }
    return null;
}

function bot_menu_build_reply(mysqli $conn, string $itemId, string $phone, string $channel): string
{
    switch ($itemId) {
        case 'register_warranty':
            return 'لطفاً کد سریال گارانتی خود را ارسال کنید.';
        case 'warranty_status':
            return bot_menu_warranty_status($conn, $phone);
        case 'send_product_photo':
            return 'از منوی «ارسال عکس محصول» استفاده کنید یا منتظر راهنمای ربات بمانید.';
        case 'invite_friends':
            return bot_menu_invite_text($conn, $channel);
        case 'my_score':
            return bot_menu_score_text($conn, $phone);
        case 'support':
            return 'پشتیبانی: ۱۵۷-۰۵۹۰-۰۹۳۶';
        case 'help':
            return "راهنما:\n۱) ثبت گارانتی با ارسال سریال\n۲) مشاهده وضعیت گارانتی\n۳) دعوت دوستان";
        default:
            return 'گزینه نامعتبر است.';
    }
}

function bot_menu_warranty_status(mysqli $conn, string $phone): string
{
    $phone = comms_normalize_phone($phone);
    $table = serials_table($conn);
    $stmt = $conn->prepare(
        "SELECT serial, city, km, date_jalali, time FROM `$table`
         WHERE phone = ? AND " . is_registered_sql() . ' ORDER BY id DESC LIMIT 5'
    );
    if (!$stmt) {
        return 'خطا در دریافت وضعیت گارانتی.';
    }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $res = method_exists($stmt, 'get_result') ? $stmt->get_result() : null;
    $lines = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $serial = (string) ($row['serial'] ?? '');
            $date = serial_date_jalali($row) ?? '—';
            $city = trim((string) ($row['city'] ?? '')) ?: '—';
            $km = isset($row['km']) && $row['km'] !== '' && $row['km'] !== null ? (string) (int) $row['km'] : '—';
            $lines[] = "سریال $serial | تاریخ $date | شهر $city | کیلومتر $km";
        }
    }
    $stmt->close();
    if ($lines === []) {
        return 'برای این شماره سریال ثبت‌شده‌ای یافت نشد.';
    }
    return "وضعیت گارانتی:\n" . implode("\n", $lines);
}

function bot_menu_invite_text(mysqli $conn, string $channel): string
{
    $settings = bot_menu_settings_for($conn, $channel);
    $custom = trim((string) ($settings['inviteMessage'] ?? ''));
    $runtime = bots_get_runtime($conn, $channel);
    $username = trim((string) ($runtime['bot_username'] ?? ''));
    $link = '';
    if ($username !== '') {
        $clean = ltrim($username, '@');
        if ($channel === 'rubika') {
            $link = 'https://rubika.ir/' . $clean;
        } elseif ($channel === 'telegram') {
            $link = 'https://t.me/' . $clean;
        } else {
            $link = 'https://ble.ir/' . $clean;
        }
    }
    if ($custom !== '') {
        return str_replace(['@1', '{link}'], $link, $custom);
    }
    $intro = 'دوستان خود را به ربات دعوت کنید.';
    return $link !== '' ? ($intro . "\n\n" . $link) : $intro;
}

function bot_menu_score_text(mysqli $conn, string $phone): string
{
    $phone = comms_normalize_phone($phone);
    $table = serials_table($conn);
    if (!column_exists($conn, $table, 'score')) {
        return 'امتیاز برای این شماره در دسترس نیست.';
    }
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(score),0) AS s FROM `$table` WHERE phone = ? AND " . is_registered_sql()
    );
    if (!$stmt) {
        return 'امتیاز برای این شماره در دسترس نیست.';
    }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    $score = (int) ($row['s'] ?? 0);
    return 'امتیاز شما: ' . $score;
}
