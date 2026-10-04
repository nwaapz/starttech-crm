<?php
declare(strict_types=1);

/**
 * Shared HTTP JSON helper for bot platform APIs.
 */
function bot_http_json(string $url, array $payload = [], string $method = 'POST', int $timeout = 25): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        // PHP json_encode([]) => "[]" which Rubika rejects as INVALID_INPUT for getMe.
        // Empty object "{}" matches Android JSONObject() / official clients.
        $body = $payload === []
            ? '{}'
            : json_encode($payload, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif (strtoupper($method) === 'GET' && $payload !== []) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($payload);
        curl_setopt($ch, CURLOPT_URL, $url);
    }
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException('HTTP error: ' . $err);
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response (HTTP ' . $code . ')');
    }
    return $decoded;
}

/**
 * @return array{0:string,1:?string} raw bytes + Content-Type
 */
function bot_http_bytes(string $url, int $timeout = 60): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException('HTTP download error: ' . $err);
    }
    if ($code < 200 || $code >= 300 || $raw === false) {
        throw new RuntimeException('HTTP download failed (HTTP ' . $code . ')');
    }
    $mimeStr = is_string($mime) ? explode(';', $mime)[0] : null;
    return [(string) $raw, $mimeStr !== '' ? $mimeStr : null];
}
