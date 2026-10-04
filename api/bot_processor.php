<?php
/**
 * Shared inbound bot processor for Rubika / Telegram / Bale.
 */
declare(strict_types=1);

require_once __DIR__ . '/bots_store.php';
require_once __DIR__ . '/comms_store.php';
require_once __DIR__ . '/bot_menu.php';
require_once __DIR__ . '/photo_verification.php';
require_once __DIR__ . '/bots/TelegramClient.php';
require_once __DIR__ . '/bots/BaleClient.php';
require_once __DIR__ . '/bots/RubikaClient.php';

function bot_process_incoming(mysqli $conn, string $channel, array $update): void
{
    if (!bots_valid_channel($channel)) {
        return;
    }
    if (strcasecmp((string) ($update['senderType'] ?? ''), 'Bot') === 0) {
        return;
    }
    $runtime = bots_get_runtime($conn, $channel);
    $token = trim((string) ($runtime['token'] ?? ''));
    if ($token === '' || empty($runtime['active'])) {
        return;
    }

    $chatId = (string) ($update['chatId'] ?? '');
    $messageId = (string) ($update['messageId'] ?? '');
    $text = trim((string) ($update['text'] ?? ''));
    $photoFileId = !empty($update['photoFileId']) ? (string) $update['photoFileId'] : null;
    $now = (int) ($update['timestamp'] ?? round(microtime(true) * 1000));
    if ($chatId === '' || $messageId === '') {
        return;
    }

    $inboxText = $text;
    if ($inboxText === '' && $photoFileId !== null) {
        $inboxText = '[photo]';
    }

    // Dedupe inbox
    if (!bots_append_message(
        $conn,
        $channel,
        $messageId,
        $chatId,
        $inboxText,
        'incoming',
        null,
        $update['senderId'] ?? null,
        $update['senderName'] ?? null,
        $now
    )) {
        return; // already processed
    }

    $contact = bots_find_contact($conn, $channel, $chatId);
    $phone = null;
    if (!empty($update['phoneNumber'])) {
        $phone = comms_normalize_phone((string) $update['phoneNumber']);
    }
    if ($phone === null || $phone === '') {
        $phone = $contact['phone'] ?? null;
        if ($phone) {
            $phone = comms_normalize_phone((string) $phone);
        }
    }
    if (($phone === null || $phone === '') && $text !== '') {
        $phone = bots_extract_phone_from_text($text);
    }

    $senderName = trim((string) ($update['senderName'] ?? '')) ?: ($contact['sender_name'] ?? null);
    bots_upsert_contact($conn, $channel, $chatId, $phone ?: null, $senderName);

    $commsPhone = comms_phone_key($phone, $channel, $chatId);
    if ($inboxText !== '') {
        comms_insert($conn, $commsPhone, 'incoming', $inboxText, $channel, 'received', $now);
    }

    if ($phone === null || $phone === '') {
        bot_request_phone($conn, $channel, $token, $chatId, $now);
        return;
    }

    bots_upsert_contact($conn, $channel, $chatId, $phone, $senderName);

    if (!empty($update['phoneNumber']) || (bots_extract_phone_from_text($text) !== null && bots_extract_phone_from_text($text) === $phone)) {
        $looksLikeOnlyPhone = ($text === '' || bots_extract_phone_from_text($text) === $phone)
            && !preg_match('/[A-Za-z]/', $text)
            && $photoFileId === null;
        if ($looksLikeOnlyPhone || !empty($update['phoneNumber'])) {
            $reply = 'شماره شما ثبت شد. از منو استفاده کنید یا سریال را ارسال کنید.';
            bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $reply, $now);
            return;
        }
    }

    if ($photoFileId !== null) {
        bot_handle_incoming_photo($conn, $channel, $token, $chatId, $phone, $update, $now);
        return;
    }

    if ($text === '') {
        return;
    }

    if (strpos($text, '/start') === 0) {
        $reply = 'به ربات گارانتی خوش آمدید. از منو استفاده کنید یا سریال را ارسال کنید.';
        bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $reply, $now, 'start');
        return;
    }

    if (photo_session_find($conn, $channel, $chatId)) {
        $reply = photo_handle_session_text($conn, $channel, $chatId, $phone, $text);
        if ($reply !== null) {
            bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $reply, $now, 'photo', [PHOTO_CANCEL_LABEL]);
        }
        return;
    }

    $menuId = bot_menu_match_label($text, $conn, $channel);
    if ($menuId !== null) {
        if ($menuId === 'send_product_photo') {
            $start = photo_start_flow($conn, $channel, $chatId, $phone);
            $extra = $start['serialButtons'];
            $extra[] = PHOTO_CANCEL_LABEL;
            bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $start['reply'], $now, 'photo', $extra);
            return;
        }
        $reply = bot_menu_build_reply($conn, $menuId, $phone, $channel);
        bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $reply, $now);
        return;
    }

    $settings = bot_menu_settings_for($conn, $channel);
    if (empty($settings['serialEnabled'])) {
        return;
    }

    require_once __DIR__ . '/bot_registration.php';
    $result = bot_handle_registration_text($conn, $phone, $text, $channel);
    $reply = is_array($result) ? (string) ($result['reply'] ?? '') : (string) $result;
    bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $reply, $now);

    if (is_array($result)
        && !empty($result['registrationSucceeded'])
        && !empty($result['imageVerificationRequired'])
        && !empty($result['registeredSerial'])
    ) {
        $start = photo_start_flow_for_serial(
            $conn,
            $channel,
            $chatId,
            $phone,
            (string) $result['registeredSerial']
        );
        bot_send_with_menu(
            $conn,
            $channel,
            $token,
            $chatId,
            $phone,
            $start['reply'],
            $now + 1,
            'photo_reg',
            array_merge($start['serialButtons'], [PHOTO_CANCEL_LABEL])
        );
    }
}

function bot_handle_incoming_photo(
    mysqli $conn,
    string $channel,
    string $token,
    string $chatId,
    string $phone,
    array $update,
    int $now
): void {
    $fileId = (string) ($update['photoFileId'] ?? '');
    if ($fileId === '') {
        return;
    }
    try {
        if ($channel === 'telegram') {
            [$bytes, $mime] = TelegramClient::downloadFileBytes($token, $fileId);
        } elseif ($channel === 'bale') {
            [$bytes, $mime] = BaleClient::downloadFileBytes($token, $fileId);
        } else {
            [$bytes, $mime] = RubikaClient::downloadFileBytes($token, $fileId);
        }
        $mime = $mime ?: (($update['photoMimeType'] ?? null) ?: 'image/jpeg');
        $result = photo_save_bytes($conn, $channel, $chatId, $phone, $bytes, $mime, $fileId);
        bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $result['reply'], $now, 'photo_ok');
    } catch (Throwable $e) {
        error_log('[bot_processor] photo failed: ' . $e->getMessage());
        $msg = 'دریافت تصویر ناموفق بود. دوباره تلاش کنید.';
        bot_send_with_menu($conn, $channel, $token, $chatId, $phone, $msg, $now, 'photo_err');
    }
}

function bot_request_phone(mysqli $conn, string $channel, string $token, string $chatId, int $now): void
{
    $contact = bots_find_contact($conn, $channel, $chatId);
    $last = (int) ($contact['phone_request_sent_at'] ?? 0);
    if ($last > 0 && ($now - $last) < 60000) {
        return;
    }
    $text = 'برای ادامه، لطفاً شماره موبایل خود را ارسال کنید.';
    try {
        if ($channel === 'telegram') {
            TelegramClient::sendPhoneNumberRequest($token, $chatId, $text);
        } elseif ($channel === 'bale') {
            BaleClient::sendPhoneNumberRequest($token, $chatId, $text);
        } else {
            RubikaClient::sendPhoneNumberRequest($token, $chatId, $text);
        }
        bots_upsert_contact($conn, $channel, $chatId, null, null, $now);
        $outId = 'phone_req_' . $now;
        bots_append_message($conn, $channel, $outId, $chatId, $text, 'outgoing', null, null, null, $now);
        comms_insert($conn, comms_phone_key(null, $channel, $chatId), 'outgoing', $text, $channel, 'sent', $now);
    } catch (Throwable $e) {
        error_log('[bot_processor] phone request failed: ' . $e->getMessage());
    }
}

/**
 * @param list<string> $extraButtons appended to reply keyboard (e.g. serials + انصراف)
 */
function bot_send_with_menu(
    mysqli $conn,
    string $channel,
    string $token,
    string $chatId,
    string $phone,
    string $text,
    int $now,
    string $idPrefix = 'out',
    array $extraButtons = []
): void {
    $labels = bot_menu_enabled_labels($conn, $channel);
    foreach ($extraButtons as $btn) {
        $btn = trim((string) $btn);
        if ($btn !== '' && !in_array($btn, $labels, true)) {
            $labels[] = $btn;
        }
    }
    $rows = bot_menu_keyboard_rows($labels);
    try {
        if ($channel === 'telegram') {
            TelegramClient::sendMessageWithReplyKeyboard($token, $chatId, $text, $rows);
        } elseif ($channel === 'bale') {
            BaleClient::sendMessageWithReplyKeyboard($token, $chatId, $text, $rows);
        } else {
            RubikaClient::sendMessageWithReplyKeyboard($token, $chatId, $text, $rows);
        }
        $outId = $idPrefix . '_' . $chatId . '_' . $now . '_' . mt_rand(100, 999);
        bots_append_message($conn, $channel, $outId, $chatId, $text, 'outgoing', $phone, null, null, $now);
        comms_insert($conn, $phone, 'outgoing', $text, $channel, 'sent', $now);
    } catch (Throwable $e) {
        error_log('[bot_processor] send failed: ' . $e->getMessage());
        comms_insert($conn, $phone, 'outgoing', $text, $channel, 'failed', $now);
    }
}

function bot_send_plain(mysqli $conn, string $channel, string $phone, string $text): bool
{
    $runtime = bots_get_runtime($conn, $channel);
    $token = trim((string) ($runtime['token'] ?? ''));
    if ($token === '' || empty($runtime['active'])) {
        return false;
    }
    $chatId = bots_chat_id_for_phone($conn, $channel, $phone);
    if ($chatId === null) {
        return false;
    }
    $now = (int) round(microtime(true) * 1000);
    try {
        if ($channel === 'telegram') {
            TelegramClient::sendMessage($token, $chatId, $text);
        } elseif ($channel === 'bale') {
            BaleClient::sendMessage($token, $chatId, $text);
        } else {
            RubikaClient::sendMessage($token, $chatId, $text);
        }
        $outId = 'send_' . $chatId . '_' . $now;
        bots_append_message($conn, $channel, $outId, $chatId, $text, 'outgoing', $phone, null, null, $now);
        comms_insert($conn, $phone, 'outgoing', $text, $channel, 'sent', $now);
        return true;
    } catch (Throwable $e) {
        error_log('[bot_send_plain] ' . $e->getMessage());
        comms_insert($conn, $phone, 'outgoing', $text, $channel, 'failed', $now);
        return false;
    }
}
