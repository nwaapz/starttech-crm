<?php
/**
 * Iran province/city helpers for CRM report filters (shared with LAN/Android).
 */
declare(strict_types=1);

/** @return list<array{id:string,name:string,cities:list<string>}> */
function iran_provinces_load(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $candidates = [
        __DIR__ . '/data/iran-provinces.json',
        dirname(__DIR__, 2) . '/shared/data/iran-provinces.json',
        dirname(__DIR__, 3) . '/shared/data/iran-provinces.json',
    ];
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        $cache = $decoded;
        return $cache;
    }

    $cache = [];
    return $cache;
}

function iran_normalize_city(?string $text): string
{
    if ($text === null || $text === '') {
        return '';
    }
    $text = trim($text);
    $text = str_replace("\u{200c}", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = str_replace(['ي', 'ك', 'ة'], ['ی', 'ک', 'ه'], $text);
    return mb_strtolower($text, 'UTF-8');
}

function iran_city_matches(?string $storedCity, string $targetCity): bool
{
    $stored = iran_normalize_city($storedCity);
    $target = iran_normalize_city($targetCity);
    if ($stored === '' || $target === '') {
        return false;
    }
    if ($stored === $target) {
        return true;
    }
    return str_contains($stored, $target) || str_contains($target, $stored);
}

/** @return array{id:string,name:string,cities:list<string>}|null */
function iran_province_by_id(string $provinceId): ?array
{
    $provinceId = trim($provinceId);
    if ($provinceId === '') {
        return null;
    }
    foreach (iran_provinces_load() as $province) {
        if (($province['id'] ?? '') === $provinceId) {
            return $province;
        }
    }
    return null;
}

/**
 * Build SQL fragments for province/city filtering on old_serials.city.
 *
 * @return array{0:string[],1:string,2:array}
 */
function serial_location_filters(string $provinceId, string $cityName): array
{
    $where = [];
    $types = '';
    $params = [];
    $provinceId = trim($provinceId);
    $cityName = trim($cityName);
    if ($provinceId === '') {
        return [$where, $types, $params];
    }

    $province = iran_province_by_id($provinceId);
    if ($province === null) {
        $where[] = '0=1';
        return [$where, $types, $params];
    }

    if ($cityName !== '') {
        $where[] = '(city = ? OR city LIKE ?)';
        $types .= 'ss';
        $params[] = $cityName;
        $params[] = '%' . $cityName . '%';
        return [$where, $types, $params];
    }

    $cities = $province['cities'] ?? [];
    if ($cities === []) {
        $where[] = '0=1';
        return [$where, $types, $params];
    }

    $parts = [];
    foreach ($cities as $city) {
        $city = trim((string) $city);
        if ($city === '') {
            continue;
        }
        $parts[] = '(city = ? OR city LIKE ?)';
        $types .= 'ss';
        $params[] = $city;
        $params[] = '%' . $city . '%';
    }
    if ($parts === []) {
        $where[] = '0=1';
    } else {
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    return [$where, $types, $params];
}
