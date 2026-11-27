<?php

function vs_geoip_country_database_path(): string
{
    $configuredPath = trim((string)(getenv('VIBESTREAM_GEOIP_COUNTRY_DB') ?: ''));
    if ($configuredPath === '') {
        return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'GeoLite2-Country.mmdb';
    }

    if (preg_match('~^(?:[A-Za-z]:[\\\\/]|[/\\\\]{2}|/)~', $configuredPath)) {
        return $configuredPath;
    }

    return __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configuredPath);
}

function vs_geoip_country_reader(): ?\GeoIp2\Database\Reader
{
    static $attempted = false;
    static $reader = null;
    if ($attempted) {
        return $reader;
    }
    $attempted = true;

    $autoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!class_exists(\GeoIp2\Database\Reader::class) && is_file($autoload)) {
        require_once $autoload;
    }
    if (!class_exists(\GeoIp2\Database\Reader::class)) {
        return null;
    }

    $databasePath = vs_geoip_country_database_path();
    if (!is_file($databasePath)) {
        return null;
    }

    try {
        $reader = new \GeoIp2\Database\Reader($databasePath);
    } catch (Throwable $error) {
        error_log('Local GeoIP country database could not be opened: ' . $error->getMessage());
    }

    return $reader;
}

function vs_geoip_country_tracking_available(): bool
{
    return vs_geoip_country_reader() !== null;
}

function vs_geoip_country_code(?string $ipAddress = null): ?string
{
    $ipAddress = trim((string)($ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return null;
    }

    $reader = vs_geoip_country_reader();
    if (!$reader) {
        return null;
    }

    try {
        $record = $reader->country($ipAddress);
        $countryCode = strtoupper((string)($record->country->isoCode ?? ''));
        return preg_match('/^[A-Z]{2}$/', $countryCode) ? $countryCode : null;
    } catch (\GeoIp2\Exception\AddressNotFoundException $error) {
        return null;
    } catch (Throwable $error) {
        error_log('Viewer country lookup failed: ' . $error->getMessage());
        return null;
    }
}
