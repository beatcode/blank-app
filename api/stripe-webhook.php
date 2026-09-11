<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $payload = file_get_contents('php://input');
    if ($payload === false) {
        throw new RuntimeException('Unable to read request body.');
    }

    $signatureHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $webhookSecret = env_required('STRIPE_WEBHOOK_SECRET');

    if (!verify_stripe_signature($payload, $signatureHeader, $webhookSecret)) {
        json_response(['error' => 'Invalid Stripe signature'], 400);
    }

    $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $eventId = $event['id'] ?? null;
    $eventType = $event['type'] ?? null;

    if (!is_string($eventId) || !is_string($eventType)) {
        json_response(['error' => 'Invalid event payload'], 400);
    }

    $pdo = db();
    $pdo->beginTransaction();

    $seen = $pdo->prepare('SELECT 1 FROM processed_events WHERE event_id = :event_id');
    $seen->execute([':event_id' => $eventId]);
    if ($seen->fetchColumn()) {
        $pdo->rollBack();
        json_response(['received' => true, 'duplicate' => true]);
    }

    if ($eventType === 'checkout.session.completed') {
        $session = $event['data']['object'] ?? null;
        if (!is_array($session) || !isset($session['id']) || !is_string($session['id'])) {
            throw new RuntimeException('checkout.session.completed did not contain a valid session object.');
        }

        $customerEmail = null;
        if (isset($session['customer_details']['email']) && is_string($session['customer_details']['email'])) {
            $customerEmail = $session['customer_details']['email'];
        }

        $paymentStatus = isset($session['payment_status']) && is_string($session['payment_status'])
            ? $session['payment_status']
            : 'completed';
        $fulfillmentStatus = $paymentStatus === 'paid' ? 'ready' : 'pending';

        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO orders (
    checkout_session_id,
    payment_intent_id,
    customer_id,
    customer_email,
    payment_status,
    amount_total,
    currency,
    fulfillment_status,
    raw_event_id,
    updated_at
) VALUES (
    :checkout_session_id,
    :payment_intent_id,
    :customer_id,
    :customer_email,
    :payment_status,
    :amount_total,
    :currency,
    :fulfillment_status,
    :raw_event_id,
    CURRENT_TIMESTAMP
)
ON CONFLICT(checkout_session_id) DO UPDATE SET
    payment_intent_id = excluded.payment_intent_id,
    customer_id = excluded.customer_id,
    customer_email = excluded.customer_email,
    payment_status = excluded.payment_status,
    amount_total = excluded.amount_total,
    currency = excluded.currency,
    fulfillment_status = excluded.fulfillment_status,
    raw_event_id = excluded.raw_event_id,
    updated_at = CURRENT_TIMESTAMP
SQL);
        $stmt->execute([
            ':checkout_session_id' => $session['id'],
            ':payment_intent_id' => stripe_object_id($session['payment_intent'] ?? null),
            ':customer_id' => stripe_object_id($session['customer'] ?? null),
            ':customer_email' => $customerEmail,
            ':payment_status' => $paymentStatus,
            ':amount_total' => $session['amount_total'] ?? null,
            ':currency' => $session['currency'] ?? null,
            ':fulfillment_status' => $fulfillmentStatus,
            ':raw_event_id' => $eventId,
        ]);
    }

    $insertEvent = $pdo->prepare('INSERT INTO processed_events (event_id, event_type) VALUES (:event_id, :event_type)');
    $insertEvent->execute([
        ':event_id' => $eventId,
        ':event_type' => $eventType,
    ]);

    $pdo->commit();
    json_response(['received' => true]);
} catch (JsonException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['error' => 'Invalid JSON'], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('TrackLens Stripe webhook error: ' . $e->getMessage());
    json_response(['error' => 'Webhook processing failed'], 500);
}
