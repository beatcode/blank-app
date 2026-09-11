<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $sessionId = isset($_GET['session_id']) && is_string($_GET['session_id']) ? trim($_GET['session_id']) : '';
    if ($sessionId === '' || !str_starts_with($sessionId, 'cs_')) {
        json_response(['error' => 'Invalid session id'], 400);
    }

    $stmt = db()->prepare(<<<'SQL'
SELECT payment_status, amount_total, currency, fulfillment_status
FROM orders
WHERE checkout_session_id = :session_id
SQL);
    $stmt->execute([':session_id' => $sessionId]);
    $order = $stmt->fetch();

    if (!$order) {
        json_response(['status' => 'pending'], 202);
    }

    json_response([
        'status' => $order['payment_status'],
        'fulfillment_status' => $order['fulfillment_status'],
        'amount_total' => $order['amount_total'] !== null ? (int)$order['amount_total'] : null,
        'currency' => $order['currency'],
    ]);
} catch (Throwable $e) {
    error_log('TrackLens checkout-status error: ' . $e->getMessage());
    json_response(['error' => 'Status unavailable'], 500);
}
