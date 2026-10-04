<?php
/**
 * Persistable CRM settings for the remote panel.
 *
 * Storage:
 *   crm_settings(setting_key PK, setting_value LONGTEXT JSON, updated_at)
 *   club_auto_message_rules(...) for multi-row club auto message rules
 *
 * Requires bootstrap.php helpers: json_out, json_error, body_json, table_exists, etc.
 */
declare(strict_types=1);

function crm_ensure_settings_schema(mysqli $conn): void
{
    $ok = $conn->query(
        "CREATE TABLE IF NOT EXISTS crm_settings (
            setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
            setting_value LONGTEXT NOT NULL,
            updated_at BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($ok === false) {
        json_error('DB schema error (crm_settings): ' . $conn->error, 500);
    }

    $ok = $conn->query(
        "CREATE TABLE IF NOT EXISTS club_auto_message_rules (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            offset_months INT NOT NULL DEFAULT 0,
            offset_days INT NOT NULL DEFAULT 0,
            send_hour INT NOT NULL DEFAULT 9,
            send_minute INT NOT NULL DEFAULT 0,
            delay_seconds INT NOT NULL DEFAULT 0,
            daily_cap INT NOT NULL DEFAULT 200,
            send_sms TINYINT(1) NOT NULL DEFAULT 1,
            send_rubika TINYINT(1) NOT NULL DEFAULT 0,
            send_telegram TINYINT(1) NOT NULL DEFAULT 0,
            send_bale TINYINT(1) NOT NULL DEFAULT 0,
            serial_category VARCHAR(32) DEFAULT NULL,
            serial_group_ids TEXT DEFAULT NULL,
            auto_source VARCHAR(32) DEFAULT NULL,
            linked_serial_group_id BIGINT DEFAULT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            last_run_at BIGINT DEFAULT NULL,
            last_run_date_jalali VARCHAR(32) DEFAULT NULL,
            last_success_count INT NOT NULL DEFAULT 0,
            last_fail_count INT NOT NULL DEFAULT 0,
            total_sent_count INT NOT NULL DEFAULT 0,
            INDEX idx_club_auto_linked (linked_serial_group_id, auto_source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($ok === false) {
        json_error('DB schema error (club_auto_message_rules): ' . $conn->error, 500);
    }
    // Additive columns for existing installs.
    if (function_exists('column_exists')) {
        if (!column_exists($conn, 'club_auto_message_rules', 'auto_source')) {
            $conn->query('ALTER TABLE club_auto_message_rules ADD COLUMN auto_source VARCHAR(32) DEFAULT NULL');
        }
        if (!column_exists($conn, 'club_auto_message_rules', 'linked_serial_group_id')) {
            $conn->query('ALTER TABLE club_auto_message_rules ADD COLUMN linked_serial_group_id BIGINT DEFAULT NULL');
        }
        if (!column_exists($conn, 'club_auto_message_rules', 'daily_cap')) {
            $conn->query(
                'ALTER TABLE club_auto_message_rules ADD COLUMN daily_cap INT NOT NULL DEFAULT 200 AFTER delay_seconds'
            );
        }
    }
    if (is_file(__DIR__ . '/club_auto_runner.php')) {
        require_once __DIR__ . '/club_auto_runner.php';
        if (function_exists('crm_club_ensure_sends_table')) {
            crm_club_ensure_sends_table($conn);
        }
        if (function_exists('crm_club_ensure_daily_cap_column')) {
            crm_club_ensure_daily_cap_column($conn);
        }
    }
}

function crm_settings_get(mysqli $conn, string $key): ?array
{
    crm_ensure_settings_schema($conn);
    $stmt = $conn->prepare('SELECT setting_value FROM crm_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$row) {
        return null;
    }
    $decoded = json_decode((string) $row['setting_value'], true);
    return is_array($decoded) ? $decoded : null;
}

function crm_settings_put(mysqli $conn, string $key, array $value): array
{
    crm_ensure_settings_schema($conn);
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        json_error('Failed to encode settings', 500);
    }
    $now = (int) round(microtime(true) * 1000);
    $stmt = $conn->prepare(
        'INSERT INTO crm_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
    );
    if (!$stmt) {
        json_error('DB prepare failed: ' . $conn->error, 500);
    }
    $stmt->bind_param('ssi', $key, $json, $now);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('Failed to save settings: ' . $err, 500);
    }
    $stmt->close();

    require_once __DIR__ . '/activity_store.php';
    crm_activity_record($conn, 'settings_changed', [
        'key' => $key,
        'message' => 'تغییر تنظیمات: ' . crm_activity_settings_label($key),
    ], $now);

    return $value;
}

function crm_settings_get_or_default(mysqli $conn, string $key, array $default): array
{
    $stored = crm_settings_get($conn, $key);
    if ($stored === null) {
        return $default;
    }
    // Shallow merge so new default keys appear after upgrades.
    return array_replace($default, $stored);
}

function crm_default_sms_processing(): array
{
    return [
        'mode' => 'SERIAL_ONLY',
        'separator' => '#',
        'combinedPattern' => ['SERIAL', 'KM', 'CITY'],
        'stepFieldOrder' => ['SERIAL', 'CITY', 'KM'],
        'stepFlowType' => 'SEQUENTIAL',
        'sessionTimeoutMinutes' => 30,
    ];
}

function crm_default_sim_quota(): array
{
    return [
        'dailyLimit' => 300,
        'backupSimsEnabled' => false,
    ];
}

function crm_default_bot_menu(): array
{
    return [
        'serialEnabled' => true,
        'inviteEnabled' => true,
        'inviteMessage' => '',
        'menuRegisterWarranty' => true,
        'menuWarrantyStatus' => true,
        'menuSendProductPhoto' => true,
        'menuInviteFriends' => true,
        'menuMyScore' => true,
        'menuSupport' => true,
        'menuHelp' => true,
    ];
}

function crm_default_referral(): array
{
    return [
        'enabled' => false,
        'basePoints' => 10,
        'decayPercent' => 50,
        'maxDepth' => 3,
    ];
}

function crm_default_whatsapp_config(): array
{
    return [
        'searchBoxSelector' => '',
        'searchResultSelector' => '',
        'messageInputSelector' => '',
        'sendButtonSelector' => '',
        'openMode' => 'search',
        'searchByPhoneFirst' => true,
        'sendDelaySeconds' => 3,
        'perContactTimeoutMs' => 60000,
    ];
}

function crm_default_lottery_preset(): array
{
    return ['phone' => null];
}

function crm_default_sync_config(): array
{
    return [
        'configured' => false,
        'url' => '',
        'lastPushMs' => 0,
        'lastPullMs' => 0,
    ];
}

/** Full SMS message catalog (LAN + remote registration dialogues). */
function crm_default_sms_messages(): array
{
    $defs = [
        // --- نتیجه ثبت ---
        [
            'id' => 'reply_valid',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'ثبت موفق (عمومی / اپ)',
            'descriptionFa' => 'ارسال پس از ثبت معتبر سریال در اپ — @1 مدت/کیلومتر گارانتی گروه',
            'template' => 'شماره سریال شما معتبر است و گارانتی شما آغاز شد. مدت گارانتی: @1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'مدت گارانتی (مثلاً ۶ ماه یا ۶۰۰۰۰ کیلومتر)']],
        ],
        [
            'id' => 'reply_invalid',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'سریال نامعتبر (عمومی / اپ)',
            'descriptionFa' => 'سریال ناموجود، استفاده‌شده، یا پیام خالی در اپ',
            'template' => 'شماره سریال معتبر نیست.',
            'placeholders' => [],
        ],
        [
            'id' => 'serial_not_found',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'کد نامعتبر (سرور)',
            'descriptionFa' => 'سریال در دیتابیس یافت نشد — @1 لینک پورتال (اختیاری)',
            'template' => 'کد نامعتبر است. لطفا مجددا کد گارانتی را بصورت صحیح وارد کنید. در صورت نیاز با واحد پشتیبانی تماس بگیرید ۱۵۷-۰۵۹۰-۰۹۳۶@1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'پسوند پورتال (یا خالی بگذارید)']],
        ],
        [
            'id' => 'serial_already_used',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'سریال قبلاً ثبت شده',
            'descriptionFa' => 'سریال با شماره دیگری ثبت شده — @1 لینک پورتال',
            'template' => 'این سریال قبلاً با شماره تماس ثبت شده است.@1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'پسوند پورتال']],
        ],
        [
            'id' => 'empty_serial',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'پیام خالی',
            'descriptionFa' => 'بدنه پیامک خالی است — @1 لینک پورتال',
            'template' => 'شماره سریال وارد نشده است.@1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'پسوند پورتال']],
        ],
        [
            'id' => 'processing_error',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'خطای پردازش',
            'descriptionFa' => 'خطای داخلی سرور هنگام ثبت — @1 لینک پورتال',
            'template' => 'خطا در پردازش درخواست. لطفا دوباره تلاش کنید.@1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'پسوند پورتال']],
        ],
        [
            'id' => 'registration_session_missing',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'جلسه ثبت یافت نشد',
            'descriptionFa' => 'شهر/کیلومتر بدون سریال قبلی — @1 لینک پورتال',
            'template' => 'سابقه ثبت نام یافت نشد. لطفا ابتدا کد سریال خود را ارسال کنید.@1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'پسوند پورتال']],
        ],
        [
            'id' => 'digit_serial_hint',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'راهنمای سریال عددی',
            'descriptionFa' => 'کاربر فقط عدد فرستاده — @1 لینک پورتال',
            'template' => 'برای فعالسازی گارانتی تسمه تایم کد را بصورت زیر ثبت کنید. مثال S123456 در صورت نیاز با ما تماس بگیرید ۱۵۷-۰۵۹۰-۰۹۳۶@1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'پسوند پورتال']],
        ],
        [
            'id' => 'invalid_serial_format',
            'categoryFa' => 'نتیجه ثبت',
            'titleFa' => 'فرمت سریال نامعتبر',
            'descriptionFa' => 'فرمت پیامک قابل تشخیص نیست — @1 لینک پورتال',
            'template' => 'فرمت سریال وارد شده معتبر نیست.@1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'پسوند پورتال']],
        ],

        // --- ثبت فروشنده (city-only) ---
        [
            'id' => 'seller_prompt_city',
            'categoryFa' => 'ثبت فروشنده',
            'titleFa' => 'پیام اول — درخواست شهر',
            'descriptionFa' => 'پس از ارسال سریال فروشنده، اگر شهر از قبل شناخته نباشد',
            'template' => 'لطفا نام شهر خود را ارسال کنید.',
            'placeholders' => [],
        ],
        [
            'id' => 'seller_reply_valid',
            'categoryFa' => 'ثبت فروشنده',
            'titleFa' => 'پیام دوم — تأیید ثبت',
            'descriptionFa' => 'پس از دریافت شهر (یا شهر شناخته‌شده از سابقه). امتیاز جداگانه ممکن است به انتهای پیام اضافه شود.',
            'template' => 'ثبت سریال فروشنده با موفقیت انجام شد.',
            'placeholders' => [],
        ],

        // --- ثبت مشتری نهایی ---
        [
            'id' => 'client_prompt_after_serial',
            'categoryFa' => 'ثبت مشتری نهایی',
            'titleFa' => 'پیام اول — پس از سریال',
            'descriptionFa' => 'درخواست شهر و کیلومتر پس از ثبت سریال مشتری (جایگزین ستون a در main_sms)',
            'template' => 'لطفا شهر و کیلومتر را ارسال کنید. مثال: تبریز 3350',
            'placeholders' => [
                ['token' => '@1', 'labelFa' => 'شهر نمونه (اختیاری در متن)'],
                ['token' => '@2', 'labelFa' => 'کیلومتر نمونه (اختیاری در متن)'],
            ],
        ],
        [
            'id' => 'client_reply_valid',
            'categoryFa' => 'ثبت مشتری نهایی',
            'titleFa' => 'پیام دوم — تأیید ثبت',
            'descriptionFa' => 'تأیید پس از دریافت شهر و کیلومتر (جایگزین ستون c در main_sms)',
            'template' => 'شماره سریال شما معتبر است و گارانتی شما آغاز شد.',
            'placeholders' => [],
        ],

        // --- ثبت مرحله‌ای (اپ) ---
        [
            'id' => 'session_expired',
            'categoryFa' => 'ثبت مرحله‌ای',
            'titleFa' => 'انقضای جلسه',
            'descriptionFa' => 'پایان مهلت ثبت مرحله‌ای',
            'template' => 'زمان ثبت به پایان رسید. لطفاً مجدداً سریال را ارسال کنید.',
            'placeholders' => [],
        ],
        [
            'id' => 'step_prompt_field',
            'categoryFa' => 'ثبت مرحله‌ای',
            'titleFa' => 'درخواست فیلد بعدی',
            'descriptionFa' => 'درخواست ارسال فیلد بعدی در ثبت مرحله‌ای',
            'template' => 'لطفاً @1 را ارسال کنید.',
            'placeholders' => [['token' => '@1', 'labelFa' => 'نام فیلد (شماره سریال / شهر / کیلومتر)']],
        ],
        [
            'id' => 'step_prompt_city_km',
            'categoryFa' => 'ثبت مرحله‌ای',
            'titleFa' => 'درخواست شهر و کیلومتر',
            'descriptionFa' => 'مرحله دوم در حالت «شهر+کیلومتر در یک پیام»',
            'template' => 'لطفاً شهر و کیلومتر را در یک پیام ارسال کنید. مثال: @1 @2',
            'placeholders' => [
                ['token' => '@1', 'labelFa' => 'شهر نمونه'],
                ['token' => '@2', 'labelFa' => 'کیلومتر نمونه'],
            ],
        ],
        [
            'id' => 'km_invalid',
            'categoryFa' => 'ثبت مرحله‌ای',
            'titleFa' => 'کیلومتر نامعتبر',
            'descriptionFa' => 'کیلومتر عددی نیست (مرحله‌ای / ترکیبی)',
            'template' => 'کیلومتر باید عدد باشد.',
            'placeholders' => [],
        ],

        // --- ثبت ترکیبی ---
        [
            'id' => 'combined_missing_city',
            'categoryFa' => 'ثبت ترکیبی',
            'titleFa' => 'شهر وارد نشده',
            'descriptionFa' => 'خطای پارس — شهر خالی',
            'template' => 'لطفاً نام شهر را در پیام وارد کنید.',
            'placeholders' => [],
        ],
        [
            'id' => 'combined_missing_km',
            'categoryFa' => 'ثبت ترکیبی',
            'titleFa' => 'کیلومتر نامعتبر',
            'descriptionFa' => 'خطای پارس — کیلومتر خالی یا غیرعددی',
            'template' => 'کیلومتر باید عدد باشد.',
            'placeholders' => [],
        ],
        [
            'id' => 'combined_format_error',
            'categoryFa' => 'ثبت ترکیبی',
            'titleFa' => 'فرمت نادرست',
            'descriptionFa' => 'سریال، شهر و کیلومتر در یک پیام لازم است',
            'template' => 'لطفاً سریال، شهر و کیلومتر را در یک پیام ارسال کنید. مثال: @1 @2 @3',
            'placeholders' => [
                ['token' => '@1', 'labelFa' => 'سریال نمونه'],
                ['token' => '@2', 'labelFa' => 'شهر نمونه'],
                ['token' => '@3', 'labelFa' => 'کیلومتر نمونه'],
            ],
        ],

        // --- تکمیل ثبت ناقص ---
        [
            'id' => 'partial_missing_city_km',
            'categoryFa' => 'تکمیل ثبت ناقص',
            'titleFa' => 'درخواست شهر و کیلومتر',
            'descriptionFa' => 'ثبت ناقص — هر دو فیلد',
            'template' => 'لطفاً شهر و کیلومتر را ارسال کنید. مثال: @1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'مثال (سریال + شهر + کیلومتر)']],
        ],
        [
            'id' => 'partial_missing_city',
            'categoryFa' => 'تکمیل ثبت ناقص',
            'titleFa' => 'درخواست شهر',
            'descriptionFa' => 'ثبت ناقص — فقط شهر',
            'template' => 'لطفاً نام شهر را در پیام وارد کنید. مثال: @1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'مثال (سریال + شهر)']],
        ],
        [
            'id' => 'km_invalid_with_example',
            'categoryFa' => 'تکمیل ثبت ناقص',
            'titleFa' => 'کیلومتر نامعتبر با مثال',
            'descriptionFa' => 'ثبت ناقص — کیلومتر نامعتبر',
            'template' => 'کیلومتر باید عدد باشد. مثال: @1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'مثال (سریال + کیلومتر)']],
        ],

        // --- یادآوری تکمیل ---
        [
            'id' => 'completion_reminder',
            'categoryFa' => 'یادآوری تکمیل',
            'titleFa' => 'یادآوری تکمیل',
            'descriptionFa' => 'ارسال از گزارش‌ها — حالت ترکیبی یا سریال',
            'template' => 'ثبت گارانتی شما ناقص است. لطفاً @1 را ارسال کنید. مثال: @2',
            'placeholders' => [
                ['token' => '@1', 'labelFa' => 'فیلدهای ناقص (شهر / کیلومتر / شهر و کیلومتر)'],
                ['token' => '@2', 'labelFa' => 'مثال کامل'],
            ],
        ],
        [
            'id' => 'completion_reminder_step_combined',
            'categoryFa' => 'یادآوری تکمیل',
            'titleFa' => 'یادآوری — مرحله ترکیبی',
            'descriptionFa' => 'حالت مرحله‌ای + شهر و کیلومتر در یک پیام',
            'template' => 'ثبت گارانتی شما ناقص است. ابتدا سریال را ارسال کنید: @1 سپس شهر و کیلومتر را در یک پیام بفرستید. مثال: @2 @3',
            'placeholders' => [
                ['token' => '@1', 'labelFa' => 'شماره سریال'],
                ['token' => '@2', 'labelFa' => 'شهر نمونه'],
                ['token' => '@3', 'labelFa' => 'کیلومتر نمونه'],
            ],
        ],
        [
            'id' => 'completion_reminder_step_sequential',
            'categoryFa' => 'یادآوری تکمیل',
            'titleFa' => 'یادآوری — مرحله‌ای',
            'descriptionFa' => 'حالت مرحله‌ای + ترتیب جداگانه',
            'template' => 'ثبت گارانتی شما ناقص است. لطفاً مراحل زیر را در پیام‌های جداگانه ارسال کنید: @1',
            'placeholders' => [['token' => '@1', 'labelFa' => 'فهرست مراحل']],
        ],
    ];
    return ['messages' => $defs];
}

/**
 * Merge saved templates onto the full default catalog (keeps new message ids after upgrades).
 * @param list<array>|null $savedMessages
 */
function crm_merge_sms_messages(?array $savedMessages): array
{
    $defaults = crm_default_sms_messages()['messages'];
    $byId = [];
    if (is_array($savedMessages)) {
        foreach ($savedMessages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $id = trim((string) ($msg['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $byId[$id] = $msg;
        }
    }
    $out = [];
    foreach ($defaults as $def) {
        $id = (string) $def['id'];
        $tpl = $def['template'];
        if (isset($byId[$id]['template']) && trim((string) $byId[$id]['template']) !== '') {
            $tpl = (string) $byId[$id]['template'];
        }
        $merged = $def;
        $merged['template'] = $tpl;
        $out[] = $merged;
    }
    return ['messages' => $out];
}

function crm_sms_messages_effective(mysqli $conn): array
{
    $stored = crm_settings_get($conn, 'sms_messages');
    $savedList = is_array($stored) ? ($stored['messages'] ?? null) : null;
    return crm_merge_sms_messages(is_array($savedList) ? $savedList : null);
}

function crm_normalize_bot_settings(array $body): array
{
    $base = crm_default_bot_menu();
    foreach ($base as $k => $default) {
        if (!array_key_exists($k, $body)) {
            continue;
        }
        if (is_bool($default)) {
            $base[$k] = (bool) $body[$k];
        } else {
            $base[$k] = (string) ($body[$k] ?? '');
        }
    }
    return $base;
}

function crm_handle_settings_routes(mysqli $conn, string $method, string $path): bool
{
    // ----- SMS processing -----
    if ($path === '/settings/sms-processing') {
        if ($method === 'GET') {
            json_out(crm_settings_get_or_default($conn, 'sms_processing', crm_default_sms_processing()));
        }
        if ($method === 'PUT') {
            $body = body_json();
            $out = crm_default_sms_processing();
            $mode = (string) ($body['mode'] ?? $out['mode']);
            if (!in_array($mode, ['SERIAL_ONLY', 'COMBINED_PATTERN', 'STEP_BY_STEP'], true)) {
                json_error('Invalid sms processing mode', 400);
            }
            $out['mode'] = $mode;
            $out['separator'] = (string) ($body['separator'] ?? '#');
            if (isset($body['combinedPattern']) && is_array($body['combinedPattern'])) {
                $out['combinedPattern'] = array_values($body['combinedPattern']);
            }
            if (isset($body['stepFieldOrder']) && is_array($body['stepFieldOrder'])) {
                $out['stepFieldOrder'] = array_values($body['stepFieldOrder']);
            }
            $flow = (string) ($body['stepFlowType'] ?? $out['stepFlowType']);
            if (!in_array($flow, ['SEQUENTIAL', 'COMBINED_SECOND'], true)) {
                json_error('Invalid stepFlowType', 400);
            }
            $out['stepFlowType'] = $flow;
            $timeout = (int) ($body['sessionTimeoutMinutes'] ?? 30);
            if ($timeout < 1 || $timeout > 24 * 60) {
                json_error('sessionTimeoutMinutes out of range', 400);
            }
            $out['sessionTimeoutMinutes'] = $timeout;
            json_out(crm_settings_put($conn, 'sms_processing', $out));
        }
    }

    // ----- SIM quota -----
    if ($path === '/settings/sim-quota') {
        if ($method === 'GET') {
            json_out(crm_settings_get_or_default($conn, 'sim_quota', crm_default_sim_quota()));
        }
        if ($method === 'PUT') {
            $body = body_json();
            $limit = (int) ($body['dailyLimit'] ?? 300);
            if ($limit < 1 || $limit > 100000) {
                json_error('dailyLimit must be between 1 and 100000', 400);
            }
            $out = [
                'dailyLimit' => $limit,
                'backupSimsEnabled' => (bool) ($body['backupSimsEnabled'] ?? false),
            ];
            json_out(crm_settings_put($conn, 'sim_quota', $out));
        }
    }

    // ----- SMS message templates -----
    if ($path === '/settings/sms-messages') {
        if ($method === 'GET') {
            json_out(crm_sms_messages_effective($conn));
        }
        if ($method === 'PUT') {
            $body = body_json();
            $messages = $body['messages'] ?? null;
            if (!is_array($messages) || $messages === []) {
                json_error('messages array required', 400);
            }
            foreach ($messages as $msg) {
                if (!is_array($msg) || trim((string) ($msg['id'] ?? '')) === '') {
                    json_error('Each message needs an id', 400);
                }
                if (trim((string) ($msg['template'] ?? '')) === '') {
                    json_error('متن پیام نمی‌تواند خالی باشد.', 400);
                }
            }
            $merged = crm_merge_sms_messages($messages);
            json_out(crm_settings_put($conn, 'sms_messages', $merged));
        }
    }

    // ----- Bot channel settings -----
    foreach (['rubika' => 'rubika_settings', 'telegram' => 'telegram_settings', 'bale' => 'bale_settings'] as $channel => $key) {
        if ($path === '/settings/' . $channel) {
            if ($method === 'GET') {
                json_out(crm_settings_get_or_default($conn, $key, crm_default_bot_menu()));
            }
            if ($method === 'PUT') {
                json_out(crm_settings_put($conn, $key, crm_normalize_bot_settings(body_json())));
            }
        }
    }

    // ----- Referral -----
    if ($path === '/settings/referral') {
        if ($method === 'GET') {
            json_out(crm_settings_get_or_default($conn, 'referral_settings', crm_default_referral()));
        }
        if ($method === 'PUT') {
            $body = body_json();
            $out = [
                'enabled' => (bool) ($body['enabled'] ?? false),
                'basePoints' => max(0, (int) ($body['basePoints'] ?? 10)),
                'decayPercent' => max(0, min(100, (int) ($body['decayPercent'] ?? 50))),
                'maxDepth' => max(1, min(20, (int) ($body['maxDepth'] ?? 3))),
            ];
            json_out(crm_settings_put($conn, 'referral_settings', $out));
        }
    }

    // ----- WhatsApp config -----
    if ($path === '/whatsapp/config') {
        if ($method === 'GET') {
            json_out(crm_settings_get_or_default($conn, 'whatsapp_config', crm_default_whatsapp_config()));
        }
        if ($method === 'PUT') {
            $body = body_json();
            $base = crm_default_whatsapp_config();
            $out = [
                'searchBoxSelector' => (string) ($body['searchBoxSelector'] ?? $base['searchBoxSelector']),
                'searchResultSelector' => (string) ($body['searchResultSelector'] ?? $base['searchResultSelector']),
                'messageInputSelector' => (string) ($body['messageInputSelector'] ?? $base['messageInputSelector']),
                'sendButtonSelector' => (string) ($body['sendButtonSelector'] ?? $base['sendButtonSelector']),
                'openMode' => (($body['openMode'] ?? 'search') === 'link') ? 'link' : 'search',
                'searchByPhoneFirst' => (bool) ($body['searchByPhoneFirst'] ?? true),
                'sendDelaySeconds' => max(0, (int) ($body['sendDelaySeconds'] ?? 3)),
                'perContactTimeoutMs' => max(1000, (int) ($body['perContactTimeoutMs'] ?? 60000)),
            ];
            json_out(crm_settings_put($conn, 'whatsapp_config', $out));
        }
    }

    // ----- Lottery preset -----
    if ($path === '/lottery/preset') {
        if ($method === 'GET') {
            json_out(crm_settings_get_or_default($conn, 'lottery_preset', crm_default_lottery_preset()));
        }
        if ($method === 'PUT') {
            $body = body_json();
            $phone = $body['phone'] ?? null;
            if ($phone !== null) {
                $phone = trim((string) $phone);
                if ($phone === '') {
                    $phone = null;
                }
            }
            json_out(crm_settings_put($conn, 'lottery_preset', ['phone' => $phone]));
        }
    }

    // ----- Admin sync config (settings-adjacent) -----
    if ($path === '/admin/sync-status' && $method === 'GET') {
        $cfg = crm_settings_get_or_default($conn, 'sync_config', crm_default_sync_config());
        $cfg['configured'] = trim((string) ($cfg['url'] ?? '')) !== '' && trim((string) ($cfg['token'] ?? '')) !== '';
        $cfg['pendingOutbox'] = 0;
        // Never echo token back.
        unset($cfg['token']);
        json_out($cfg);
    }
    if ($path === '/admin/sync-config' && $method === 'PUT') {
        $body = body_json();
        $current = crm_settings_get_or_default($conn, 'sync_config', crm_default_sync_config() + ['token' => '']);
        if (array_key_exists('url', $body)) {
            $current['url'] = trim((string) $body['url']);
        }
        if (array_key_exists('token', $body)) {
            $current['token'] = trim((string) $body['token']);
        }
        $saved = crm_settings_put($conn, 'sync_config', $current);
        $saved['configured'] = trim((string) ($saved['url'] ?? '')) !== '' && trim((string) ($saved['token'] ?? '')) !== '';
        $saved['pendingOutbox'] = 0;
        unset($saved['token']);
        json_out($saved);
    }
    if (($path === '/admin/cloud-sync/get' || $path === '/admin/cloud-sync/send' || $path === '/admin/cloud-sync/pull') && $method === 'POST') {
        json_out(['ok' => true, 'message' => 'Sync is not available on the remote panel']);
    }

    if ($path === '/sms-processing/debug-log' && $method === 'GET') {
        json_out([
            'count' => 0,
            'entries' => [],
            'text' => 'SMS processing debug log is only available on the LAN CRM (phone app).',
        ]);
    }
    if ($path === '/sms-processing/debug-log/clear' && $method === 'POST') {
        json_out([
            'count' => 0,
            'entries' => [],
            'text' => 'SMS processing debug log is only available on the LAN CRM (phone app).',
        ]);
    }

    return false;
}

function crm_club_rule_to_api(array $row): array
{
    $groupIds = [];
    if (!empty($row['serial_group_ids'])) {
        $decoded = json_decode((string) $row['serial_group_ids'], true);
        if (is_array($decoded)) {
            $groupIds = array_values(array_map('intval', $decoded));
        }
    }
    $cat = $row['serial_category'] ?? null;
    if ($cat !== null && $cat !== 'end_user_client' && $cat !== 'seller_to_end_user') {
        $cat = null;
    }
    return [
        'id' => (int) $row['id'],
        'title' => (string) ($row['title'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'offsetMonths' => (int) ($row['offset_months'] ?? 0),
        'offsetDays' => (int) ($row['offset_days'] ?? 0),
        'sendHour' => (int) ($row['send_hour'] ?? 9),
        'sendMinute' => (int) ($row['send_minute'] ?? 0),
        'delaySeconds' => (int) ($row['delay_seconds'] ?? 0),
        'dailyCap' => max(1, min(10000, (int) ($row['daily_cap'] ?? 200))),
        'sendSms' => !empty($row['send_sms']),
        'sendRubika' => !empty($row['send_rubika']),
        'sendTelegram' => !empty($row['send_telegram']),
        'sendBale' => !empty($row['send_bale']),
        'serialCategory' => $cat,
        'serialGroupIds' => $groupIds,
        'serialGroupLabels' => null,
        'enabled' => !empty($row['enabled']),
        'createdAt' => (int) ($row['created_at'] ?? 0),
        'updatedAt' => (int) ($row['updated_at'] ?? 0),
        'lastRunAt' => isset($row['last_run_at']) && $row['last_run_at'] !== null ? (int) $row['last_run_at'] : null,
        'lastRunDateJalali' => $row['last_run_date_jalali'] ?? null,
        'lastSuccessCount' => (int) ($row['last_success_count'] ?? 0),
        'lastFailCount' => (int) ($row['last_fail_count'] ?? 0),
        'totalSentCount' => (int) ($row['total_sent_count'] ?? 0),
        'runStatus' => null,
        'runStartedAt' => null,
        'runTotalCount' => null,
        'runProcessedCount' => null,
        'runSentCount' => null,
        'runFailCount' => null,
        'autoSource' => isset($row['auto_source']) && $row['auto_source'] !== ''
            ? (string) $row['auto_source']
            : null,
        'linkedSerialGroupId' => isset($row['linked_serial_group_id']) && $row['linked_serial_group_id'] !== null
            ? (int) $row['linked_serial_group_id']
            : null,
    ];
}

function crm_club_parse_body(array $body): array
{
    $message = trim((string) ($body['message'] ?? ''));
    if ($message === '') {
        json_error('message is required', 400);
    }
    $groupIds = [];
    if (isset($body['serialGroupIds']) && is_array($body['serialGroupIds'])) {
        foreach ($body['serialGroupIds'] as $id) {
            $groupIds[] = (int) $id;
        }
    }
    $cat = $body['serialCategory'] ?? null;
    if ($cat !== null && $cat !== '' && $cat !== 'end_user_client' && $cat !== 'seller_to_end_user') {
        json_error('Invalid serialCategory', 400);
    }
    if ($cat === '') {
        $cat = null;
    }
    $offsetMonths = max(0, (int) ($body['offsetMonths'] ?? 0));
    $offsetDays = (int) ($body['offsetDays'] ?? 0);
    if ($offsetDays < -366) {
        $offsetDays = -366;
    }
    if ($offsetDays > 366) {
        $offsetDays = 366;
    }
    if ($offsetMonths === 0 && $offsetDays === 0) {
        json_error('offsetMonths or offsetDays required', 400);
    }
    if ($offsetDays < 0 && $offsetMonths <= 0) {
        json_error('offsetMonths required when offsetDays is negative', 400);
    }
    $autoSource = trim((string) ($body['autoSource'] ?? ''));
    if ($autoSource !== '' && $autoSource !== 'warranty_end' && $autoSource !== 'warranty_pre') {
        json_error('Invalid autoSource', 400);
    }
    if ($autoSource === '') {
        $autoSource = null;
    }
    $linkedGroupId = null;
    if (array_key_exists('linkedSerialGroupId', $body) && $body['linkedSerialGroupId'] !== null && $body['linkedSerialGroupId'] !== '') {
        $linkedGroupId = (int) $body['linkedSerialGroupId'];
    } elseif ($autoSource !== null && count($groupIds) === 1) {
        $linkedGroupId = $groupIds[0];
    }
    return [
        'title' => trim((string) ($body['title'] ?? '')),
        'message' => $message,
        'offsetMonths' => $offsetMonths,
        'offsetDays' => $offsetDays,
        'sendHour' => max(0, min(23, (int) ($body['sendHour'] ?? 9))),
        'sendMinute' => max(0, min(59, (int) ($body['sendMinute'] ?? 0))),
        'delaySeconds' => max(0, (int) ($body['delaySeconds'] ?? 0)),
        'dailyCap' => max(1, min(10000, (int) ($body['dailyCap'] ?? 200))),
        'sendSms' => (bool) ($body['sendSms'] ?? true),
        'sendRubika' => (bool) ($body['sendRubika'] ?? false),
        'sendTelegram' => (bool) ($body['sendTelegram'] ?? false),
        'sendBale' => (bool) ($body['sendBale'] ?? false),
        'serialCategory' => $cat,
        'serialGroupIdsJson' => json_encode(array_values($groupIds), JSON_UNESCAPED_UNICODE),
        'autoSource' => $autoSource,
        'linkedSerialGroupId' => $linkedGroupId,
        'enabled' => (bool) ($body['enabled'] ?? true),
    ];
}

function crm_club_set_linkage(mysqli $conn, int $id, ?string $autoSource, ?int $linkedSerialGroupId): void
{
    $autoSql = $autoSource !== null && $autoSource !== ''
        ? "'" . $conn->real_escape_string($autoSource) . "'"
        : 'NULL';
    $linkedSql = $linkedSerialGroupId !== null ? (string) (int) $linkedSerialGroupId : 'NULL';
    $conn->query(
        "UPDATE club_auto_message_rules
         SET auto_source = $autoSql, linked_serial_group_id = $linkedSql
         WHERE id = " . (int) $id
    );
}

function crm_handle_club_auto_routes(mysqli $conn, string $method, string $path): bool
{
    crm_ensure_settings_schema($conn);

    if ($path === '/club-auto-messages' && $method === 'GET') {
        $res = $conn->query('SELECT * FROM club_auto_message_rules ORDER BY id DESC');
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = crm_club_rule_to_api($row);
            }
        }
        json_out(['items' => $items]);
    }

    if ($path === '/club-auto-messages' && $method === 'POST') {
        $parsed = crm_club_parse_body(body_json());
        $now = (int) round(microtime(true) * 1000);
        $sql = 'INSERT INTO club_auto_message_rules
            (title, message, offset_months, offset_days, send_hour, send_minute, delay_seconds, daily_cap,
             send_sms, send_rubika, send_telegram, send_bale, serial_category, serial_group_ids,
             enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            json_error('DB prepare failed: ' . $conn->error, 500);
        }
        $sendSms = $parsed['sendSms'] ? 1 : 0;
        $sendRubika = $parsed['sendRubika'] ? 1 : 0;
        $sendTelegram = $parsed['sendTelegram'] ? 1 : 0;
        $sendBale = $parsed['sendBale'] ? 1 : 0;
        $enabled = $parsed['enabled'] ? 1 : 0;
        $cat = $parsed['serialCategory'] !== null ? (string) $parsed['serialCategory'] : '';
        $groupsJson = (string) $parsed['serialGroupIdsJson'];
        $stmt->bind_param(
            'ssiiiiiiiiiissiii',
            $parsed['title'],
            $parsed['message'],
            $parsed['offsetMonths'],
            $parsed['offsetDays'],
            $parsed['sendHour'],
            $parsed['sendMinute'],
            $parsed['delaySeconds'],
            $parsed['dailyCap'],
            $sendSms,
            $sendRubika,
            $sendTelegram,
            $sendBale,
            $cat,
            $groupsJson,
            $enabled,
            $now,
            $now
        );
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            json_error('Insert failed: ' . $err, 500);
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        crm_club_set_linkage($conn, $id, $parsed['autoSource'], $parsed['linkedSerialGroupId']);
        $got = $conn->query('SELECT * FROM club_auto_message_rules WHERE id = ' . $id)->fetch_assoc();
        if (!$got) {
            json_error('Insert succeeded but row missing', 500);
        }
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'club_auto_created', [
            'ruleId' => $id,
            'title' => (string) ($got['title'] ?? $parsed['title']),
        ]);
        json_out(crm_club_rule_to_api($got), 201);
    }

    if (preg_match('#^/club-auto-messages/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];
        if ($method === 'PUT') {
            $parsed = crm_club_parse_body(body_json());
            $now = (int) round(microtime(true) * 1000);
            $sql = 'UPDATE club_auto_message_rules SET
                title=?, message=?, offset_months=?, offset_days=?, send_hour=?, send_minute=?, delay_seconds=?, daily_cap=?,
                send_sms=?, send_rubika=?, send_telegram=?, send_bale=?, serial_category=?, serial_group_ids=?,
                enabled=?, updated_at=?
                WHERE id=?';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                json_error('DB prepare failed: ' . $conn->error, 500);
            }
            $sendSms = $parsed['sendSms'] ? 1 : 0;
            $sendRubika = $parsed['sendRubika'] ? 1 : 0;
            $sendTelegram = $parsed['sendTelegram'] ? 1 : 0;
            $sendBale = $parsed['sendBale'] ? 1 : 0;
            $enabled = $parsed['enabled'] ? 1 : 0;
            $cat = $parsed['serialCategory'] !== null ? (string) $parsed['serialCategory'] : '';
            $groupsJson = (string) $parsed['serialGroupIdsJson'];
            $stmt->bind_param(
                'ssiiiiiiiiiissiii',
                $parsed['title'],
                $parsed['message'],
                $parsed['offsetMonths'],
                $parsed['offsetDays'],
                $parsed['sendHour'],
                $parsed['sendMinute'],
                $parsed['delaySeconds'],
                $parsed['dailyCap'],
                $sendSms,
                $sendRubika,
                $sendTelegram,
                $sendBale,
                $cat,
                $groupsJson,
                $enabled,
                $now,
                $id
            );
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                json_error('Update failed: ' . $err, 500);
            }
            $stmt->close();
            crm_club_set_linkage($conn, $id, $parsed['autoSource'], $parsed['linkedSerialGroupId']);
            $got = $conn->query('SELECT * FROM club_auto_message_rules WHERE id = ' . $id)->fetch_assoc();
            if (!$got) {
                json_error('Not found', 404);
            }
            require_once __DIR__ . '/activity_store.php';
            crm_activity_record($conn, 'club_auto_updated', [
                'ruleId' => $id,
                'title' => (string) ($got['title'] ?? $parsed['title']),
            ]);
            json_out(crm_club_rule_to_api($got));
        }
        if ($method === 'DELETE') {
            $title = '';
            $titleRes = $conn->query('SELECT title FROM club_auto_message_rules WHERE id = ' . (int) $id . ' LIMIT 1');
            if ($titleRes && ($tr = $titleRes->fetch_assoc())) {
                $title = (string) ($tr['title'] ?? '');
            }
            $stmt = $conn->prepare('DELETE FROM club_auto_message_rules WHERE id = ?');
            if (!$stmt) {
                json_error('DB prepare failed: ' . $conn->error, 500);
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected <= 0) {
                json_error('Not found', 404);
            }
            require_once __DIR__ . '/activity_store.php';
            crm_activity_record($conn, 'club_auto_deleted', [
                'ruleId' => $id,
                'title' => $title,
            ]);
            http_response_code(204);
            exit;
        }
    }

    // Remote sending uses Melipayamak via club_auto_runner (PHP cron / run-now) — not the Android SMS pipeline.
    require_once __DIR__ . '/club_auto_runner.php';

    if (preg_match('#^/club-auto-messages/(\d+)/run-now$#', $path, $m) && $method === 'POST') {
        $id = (int) $m[1];
        $got = $conn->query('SELECT * FROM club_auto_message_rules WHERE id = ' . $id)->fetch_assoc();
        if (!$got) {
            json_error('Not found', 404);
        }
        crm_club_run_rule($conn, $got, true);
        $fresh = $conn->query('SELECT * FROM club_auto_message_rules WHERE id = ' . $id)->fetch_assoc();
        json_out(crm_club_rule_to_api($fresh ?: $got));
    }
    if (preg_match('#^/club-auto-messages/(\d+)/sends$#', $path, $m) && $method === 'GET') {
        $id = (int) $m[1];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        json_out(crm_club_list_sends($conn, $id, $page, $limit));
    }
    if (preg_match('#^/club-auto-messages/(\d+)/preview$#', $path, $m) && $method === 'GET') {
        $id = (int) $m[1];
        $got = $conn->query('SELECT * FROM club_auto_message_rules WHERE id = ' . $id)->fetch_assoc();
        if (!$got) {
            json_error('Not found', 404);
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
        json_out(crm_club_preview_rule($conn, $got, $page, $limit));
    }
    if ($path === '/club-auto-messages/tick' && $method === 'POST') {
        $summary = crm_club_run_due_rules($conn);
        json_out(['ok' => true] + $summary);
    }

    return false;
}

function crm_handle_admin_user_routes(mysqli $conn, string $method, string $path, array $authUser): bool
{
    if ($path === '/admin/users' && $method === 'GET') {
        $res = $conn->query('SELECT id, name, role FROM users ORDER BY id ASC');
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $now = (int) round(microtime(true) * 1000);
                $items[] = [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['name'],
                    'role' => (int) $row['role'],
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ];
            }
        }
        json_out($items);
    }

    if ($path === '/admin/users' && $method === 'POST') {
        $body = body_json();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = (int) ($body['role'] ?? 1);
        if ($username === '' || $password === '') {
            json_error('username and password required', 400);
        }
        if (strlen($password) < 4) {
            json_error('Password too short', 400);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (name, pass, role) VALUES (?, ?, ?)');
        if (!$stmt) {
            json_error('DB prepare failed: ' . $conn->error, 500);
        }
        $stmt->bind_param('ssi', $username, $hash, $role);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            json_error('Create user failed: ' . $err, 400);
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();
        $now = (int) round(microtime(true) * 1000);
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'admin_user_created', [
            'message' => 'ایجاد کاربر: ' . $username,
            'username' => $username,
        ], $now, (string) ($authUser['username'] ?? ''));
        json_out([
            'id' => $id,
            'username' => $username,
            'role' => $role,
            'createdAt' => $now,
            'updatedAt' => $now,
        ], 201);
    }

    if (preg_match('#^/admin/users/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $id = (int) $m[1];
        if ($id === (int) ($authUser['id'] ?? 0)) {
            json_error('Cannot delete the currently signed-in user', 400);
        }
        $uname = '';
        $uRes = $conn->query('SELECT name FROM users WHERE id = ' . $id . ' LIMIT 1');
        if ($uRes && ($ur = $uRes->fetch_assoc())) {
            $uname = (string) ($ur['name'] ?? '');
        }
        $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
        if (!$stmt) {
            json_error('DB prepare failed: ' . $conn->error, 500);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$ok) {
            json_error('Not found', 404);
        }
        require_once __DIR__ . '/activity_store.php';
        crm_activity_record($conn, 'admin_user_deleted', [
            'message' => 'حذف کاربر: ' . ($uname !== '' ? $uname : ('#' . $id)),
            'username' => $uname,
        ], null, (string) ($authUser['username'] ?? ''));
        json_out(['ok' => true]);
    }

    if (preg_match('#^/admin/users/(\d+)/password$#', $path, $m) && $method === 'PUT') {
        $id = (int) $m[1];
        $body = body_json();
        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < 4) {
            json_error('Password too short', 400);
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET pass = ? WHERE id = ?');
        if (!$stmt) {
            json_error('DB prepare failed: ' . $conn->error, 500);
        }
        $stmt->bind_param('si', $hash, $id);
        $stmt->execute();
        $ok = $stmt->affected_rows >= 0;
        $stmt->close();
        if (!$ok) {
            json_error('Update failed', 500);
        }
        json_out(['ok' => true]);
    }

    return false;
}
