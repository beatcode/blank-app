<?php

declare(strict_types=1);

const STRIPE_API_BASE = 'https://api.stripe.com';
const STRIPE_MANAGED_PAYMENTS_VERSION = '2026-02-25.preview';

function env_required(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }
    return trim($value);
}

function env_optional(string $name): ?string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return null;
    }
    return trim($value);
}

function app_base_url(): string
{
    return rtrim(env_required('APP_BASE_URL'), '/');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = env_required('TRACKLENS_DB_PATH');
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create database directory: {$dir}");
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS stripe_resources (
    resource_key TEXT PRIMARY KEY,
    stripe_id TEXT NOT NULL,
    extra_json TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    checkout_session_id TEXT NOT NULL UNIQUE,
    payment_intent_id TEXT,
    customer_id TEXT,
    customer_email TEXT,
    payment_status TEXT NOT NULL DEFAULT 'created',
    amount_total INTEGER,
    currency TEXT,
    fulfillment_status TEXT NOT NULL DEFAULT 'pending',
    raw_event_id TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS processed_events (
    event_id TEXT PRIMARY KEY,
    event_type TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

    return $pdo;
}

function save_stripe_resource(string $key, string $stripeId, array $extra = []): void
{
    $stmt = db()->prepare(<<<'SQL'
INSERT INTO stripe_resources (resource_key, stripe_id, extra_json, updated_at)
VALUES (:resource_key, :stripe_id, :extra_json, CURRENT_TIMESTAMP)
ON CONFLICT(resource_key) DO UPDATE SET
    stripe_id = excluded.stripe_id,
    extra_json = excluded.extra_json,
    updated_at = CURRENT_TIMESTAMP
SQL);
    $stmt->execute([
        ':resource_key' => $key,
        ':stripe_id' => $stripeId,
        ':extra_json' => $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function get_stripe_resource(string $key): ?string
{
    $stmt = db()->prepare('SELECT stripe_id FROM stripe_resources WHERE resource_key = :resource_key');
    $stmt->execute([':resource_key' => $key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['stripe_id'] : null;
}

function stripe_request(string $method, string $path, array $params = [], ?string $apiVersion = null): array
{
    $secret = env_required('STRIPE_SECRET_KEY');
    $url = STRIPE_API_BASE . $path;

    $headers = [
        'Authorization: Bearer ' . $secret,
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if ($apiVersion !== null && $apiVersion !== '') {
        $headers[] = 'Stripe-Version: ' . $apiVersion;
    }

    $ch = curl_init();
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($params !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Stripe request failed: ' . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Stripe returned invalid JSON (HTTP {$status}).");
    }

    if ($status < 200 || $status >= 300) {
        $message = $decoded['error']['message'] ?? 'Unknown Stripe API error';
        throw new RuntimeException("Stripe API error (HTTP {$status}): {$message}");
    }

    return $decoded;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function stripe_object_id(mixed $value): ?string
{
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (is_array($value) && isset($value['id']) && is_string($value['id'])) {
        return $value['id'];
    }
    return null;
}

function verify_stripe_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) {
            continue;
        }
        [$key, $value] = $pair;
        if ($key === 't') {
            $timestamp = ctype_digit($value) ? (int)$value : null;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || $signatures === []) {
        return false;
    }
    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}
