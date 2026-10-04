<?php
/**
 * Bot-channel serial registration — reuses seller/client rules from remote SMS pipeline.
 * Returns array: reply, registrationSucceeded, imageVerificationRequired, registeredSerial
 */
declare(strict_types=1);

function bot_reg_result(
    string $reply,
    bool $succeeded = false,
    bool $needsPhoto = false,
    ?string $serial = null
): array {
    return [
        'reply' => $reply,
        'registrationSucceeded' => $succeeded,
        'imageVerificationRequired' => $needsPhoto,
        'registeredSerial' => $serial,
    ];
}

function bot_reg_photo_flags(mysqli $conn, string $serial, string $phone): array
{
    require_once __DIR__ . '/photo_verification.php';
    $needs = photo_serial_needs_after_register($conn, $serial, $phone);
    return [$needs, $needs ? $serial : null];
}

function bot_handle_registration_text(mysqli $conn, string $phone, string $text, string $channel): array
{
    require_once __DIR__ . '/settings_store.php';
    require_once __DIR__ . '/bootstrap.php';
    $phone = comms_normalize_phone($phone);
    $text = trim($text);
    if ($phone === '' || $text === '') {
        return bot_reg_result('پیام نامعتبر است.');
    }

    // City + km combined
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $text) && preg_match('/[0-9\x{06F0}-\x{06F9}]/u', $text)) {
        preg_match_all('/[\x{0600}-\x{06FF}]+/u', $text, $mCity);
        preg_match_all('/[0-9\x{06F0}-\x{06F9}]+/u', $text, $mKm);
        $city = isset($mCity[0][0]) ? $mCity[0][0] : '';
        $km = isset($mKm[0][0]) ? bot_fa_digits_to_en($mKm[0][0]) : '';
        if ($city !== '' && $km !== '') {
            return bot_apply_city_km($conn, $phone, $city, (int) $km, $channel);
        }
    }

    // Persian city only
    if (preg_match('/^[\x{0600}-\x{06FF}\s]+$/u', $text)) {
        return bot_apply_city_only($conn, $phone, trim($text), $channel);
    }

    // Serial code
    $serial = strtoupper(preg_replace('/\s+/', '', $text) ?? '');
    if ($serial === '') {
        return bot_reg_result('لطفاً سریال یا شهر را ارسال کنید.');
    }
    return bot_register_serial($conn, $phone, $serial, $channel);
}

function bot_fa_digits_to_en(string $s): string
{
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($fa, $en, $s);
}

function bot_register_serial(mysqli $conn, string $phone, string $serial, string $channel): array
{
    $table = serials_table($conn);
    $stmt = $conn->prepare("SELECT * FROM `$table` WHERE UPPER(serial) = ? LIMIT 1");
    if (!$stmt) {
        return bot_reg_result('خطا در پردازش.');
    }
    $stmt->bind_param('s', $serial);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$row) {
        require_once __DIR__ . '/settings_store.php';
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'serial_not_found', ['@1' => ''])
            : 'کد نامعتبر است.';
        return bot_reg_result($msg);
    }
    $existingPhone = trim((string) ($row['phone'] ?? ''));
    if ($existingPhone !== '' && $existingPhone !== '0') {
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'serial_already_used', ['@1' => ''])
            : 'این سریال قبلاً ثبت شده است.';
        return bot_reg_result($msg);
    }

    $dbSerial = (string) $row['serial'];
    $isSeller = (trim((string) ($row['category'] ?? '')) === 'seller_to_end_user');
    $knownCity = serial_known_city_for_phone($conn, $phone, $dbSerial);
    $nowDate = date('Y-m-d');
    $syncMs = (int) round(microtime(true) * 1000);
    $hasSync = column_exists($conn, $table, 'sync_updated_ms');
    $hasJalali = column_exists($conn, $table, 'date_jalali');
    $jalali = today_jalali_date();
    $regSource = $channel;

    if ($isSeller) {
        if ($knownCity) {
            bot_update_serial_registration($conn, $table, $dbSerial, $phone, $nowDate, $jalali, $knownCity, null, $syncMs, $hasSync, $hasJalali, $regSource);
            $msg = function_exists('crm_resolve_sms_template')
                ? crm_resolve_sms_template($conn, 'seller_reply_valid', [])
                : 'ثبت سریال فروشنده با موفقیت انجام شد.';
            [$needs, $ser] = bot_reg_photo_flags($conn, $dbSerial, $phone);
            return bot_reg_result($msg, true, $needs, $ser);
        }
        bot_update_serial_registration($conn, $table, $dbSerial, $phone, $nowDate, $jalali, null, null, $syncMs, $hasSync, $hasJalali, $regSource);
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'seller_prompt_city', [])
            : 'لطفا نام شهر خود را ارسال کنید.';
        return bot_reg_result($msg);
    }

    // End-user client — not complete until city+km
    if ($knownCity) {
        bot_update_serial_registration($conn, $table, $dbSerial, $phone, $nowDate, $jalali, $knownCity, null, $syncMs, $hasSync, $hasJalali, $regSource);
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'client_prompt_after_serial', ['@1' => 'تبریز', '@2' => '3350'])
            : 'لطفا کیلومتر را ارسال کنید. مثال: 3350';
        return bot_reg_result($msg);
    }
    bot_update_serial_registration($conn, $table, $dbSerial, $phone, $nowDate, $jalali, null, null, $syncMs, $hasSync, $hasJalali, $regSource);
    $msg = function_exists('crm_resolve_sms_template')
        ? crm_resolve_sms_template($conn, 'client_prompt_after_serial', ['@1' => 'تبریز', '@2' => '3350'])
        : 'لطفا شهر و کیلومتر را ارسال کنید.';
    return bot_reg_result($msg);
}

function bot_update_serial_registration(
    mysqli $conn,
    string $table,
    string $serial,
    string $phone,
    string $time,
    string $jalali,
    ?string $city,
    ?int $km,
    int $syncMs,
    bool $hasSync,
    bool $hasJalali,
    string $regSource
): void {
    $sets = ['phone = ?', 'time = ?'];
    $types = 'ss';
    $params = [$phone, $time];
    if ($hasJalali) {
        $sets[] = 'date_jalali = ?';
        $types .= 's';
        $params[] = $jalali;
    }
    if ($city !== null && $city !== '' && column_exists($conn, $table, 'city')) {
        $sets[] = 'city = ?';
        $types .= 's';
        $params[] = $city;
    }
    if ($km !== null && column_exists($conn, $table, 'km')) {
        $sets[] = 'km = ?';
        $types .= 'i';
        $params[] = $km;
    }
    if ($hasSync) {
        $sets[] = 'sync_updated_ms = ?';
        $types .= 'i';
        $params[] = $syncMs;
    }
    if (column_exists($conn, $table, 'reg_source')) {
        $sets[] = 'reg_source = ?';
        $types .= 's';
        $params[] = $regSource;
    }
    $types .= 's';
    $params[] = $serial;
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE serial = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    require_once __DIR__ . '/sync/sync_buffer_helpers.php';
    sync_buffer_enqueue_serial_lookup($conn, $serial, $table, $regSource);

    if (is_file(__DIR__ . '/activity_store.php')) {
        require_once __DIR__ . '/activity_store.php';
        $payload = ['serial' => $serial, 'phone' => $phone, 'source' => $regSource];
        if ($city) {
            $payload['city'] = $city;
        }
        if ($km !== null) {
            $payload['km'] = $km;
        }
        crm_activity_record($conn, 'registered', $payload);
    }
}

function bot_apply_city_only(mysqli $conn, string $phone, string $city, string $channel): array
{
    $table = serials_table($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM `$table` WHERE phone = ? AND (city IS NULL OR city = '') ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return bot_reg_result('سابقه ثبت یافت نشد.');
    }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$row) {
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'registration_session_missing', ['@1' => ''])
            : 'سابقه ثبت نام یافت نشد.';
        return bot_reg_result($msg);
    }
    $serial = (string) $row['serial'];
    $isSeller = (trim((string) ($row['category'] ?? '')) === 'seller_to_end_user');
    $syncMs = (int) round(microtime(true) * 1000);
    $stmt = $conn->prepare(
        column_exists($conn, $table, 'sync_updated_ms')
            ? "UPDATE `$table` SET city = ?, sync_updated_ms = ? WHERE serial = ?"
            : "UPDATE `$table` SET city = ? WHERE serial = ?"
    );
    if (!$stmt) {
        return bot_reg_result('خطا در ذخیره شهر.');
    }
    if (column_exists($conn, $table, 'sync_updated_ms')) {
        $stmt->bind_param('sis', $city, $syncMs, $serial);
    } else {
        $stmt->bind_param('ss', $city, $serial);
    }
    $stmt->execute();
    $stmt->close();

    require_once __DIR__ . '/sync/sync_buffer_helpers.php';
    sync_buffer_enqueue_serial_lookup($conn, $serial, $table, $channel);

    if ($isSeller) {
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'seller_reply_valid', [])
            : 'ثبت سریال فروشنده با موفقیت انجام شد.';
        [$needs, $ser] = bot_reg_photo_flags($conn, $serial, $phone);
        return bot_reg_result($msg, true, $needs, $ser);
    }
    $hasKm = isset($row['km']) && $row['km'] !== null && $row['km'] !== '' && (int) $row['km'] !== 0;
    if ($hasKm) {
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'client_reply_valid', [])
            : 'ثبت با موفقیت انجام شد.';
        [$needs, $ser] = bot_reg_photo_flags($conn, $serial, $phone);
        return bot_reg_result($msg, true, $needs, $ser);
    }
    return bot_reg_result('شهر ثبت شد. لطفاً کیلومتر را ارسال کنید.');
}

function bot_apply_city_km(mysqli $conn, string $phone, string $city, int $km, string $channel): array
{
    $table = serials_table($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM `$table` WHERE phone = ? ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return bot_reg_result('سابقه ثبت یافت نشد.');
    }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $row = stmt_fetch_assoc($stmt);
    $stmt->close();
    if (!$row) {
        $msg = function_exists('crm_resolve_sms_template')
            ? crm_resolve_sms_template($conn, 'registration_session_missing', ['@1' => ''])
            : 'سابقه ثبت نام یافت نشد.';
        return bot_reg_result($msg);
    }
    $serial = (string) $row['serial'];
    $isSeller = (trim((string) ($row['category'] ?? '')) === 'seller_to_end_user');
    $syncMs = (int) round(microtime(true) * 1000);
    if ($isSeller) {
        return bot_apply_city_only($conn, $phone, $city, $channel);
    }
    $hasSync = column_exists($conn, $table, 'sync_updated_ms');
    if ($hasSync) {
        $stmt = $conn->prepare("UPDATE `$table` SET city = ?, km = ?, sync_updated_ms = ? WHERE serial = ?");
        if ($stmt) {
            $stmt->bind_param('siis', $city, $km, $syncMs, $serial);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("UPDATE `$table` SET city = ?, km = ? WHERE serial = ?");
        if ($stmt) {
            $stmt->bind_param('sis', $city, $km, $serial);
            $stmt->execute();
            $stmt->close();
        }
    }
    require_once __DIR__ . '/sync/sync_buffer_helpers.php';
    sync_buffer_enqueue_serial_lookup($conn, $serial, $table, $channel);
    $msg = function_exists('crm_resolve_sms_template')
        ? crm_resolve_sms_template($conn, 'client_reply_valid', [])
        : 'شماره سریال شما معتبر است و گارانتی شما آغاز شد.';
    [$needs, $ser] = bot_reg_photo_flags($conn, $serial, $phone);
    return bot_reg_result($msg, true, $needs, $ser);
}
