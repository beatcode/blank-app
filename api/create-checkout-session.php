<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $priceId = env_optional('STRIPE_PRICE_ID') ?: get_stripe_resource('tracklens_pro_price');
    if ($priceId === null) {
        throw new RuntimeException('TrackLens Stripe price is not configured. Run scripts/create-tracklens-product.php or set STRIPE_PRICE_ID.');
    }

    $baseUrl = app_base_url();
    $session = stripe_request('POST', '/v1/checkout/sessions', [
        'mode' => 'payment',
        'ui_mode' => 'hosted',
        'success_url' => $baseUrl . '/success.html?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $baseUrl . '/cancel.html',
        'line_items' => [[
            'price' => $priceId,
            'quantity' => 1,
        ]],
        'billing_address_collection' => 'auto',
        'allow_promotion_codes' => 'false',
        'automatic_tax' => [
            'enabled' => 'true',
        ],
        'managed_payments' => [
            'enabled' => 'true',
        ],
        'submit_type' => 'auto',
        'metadata' => [
            'product' => 'tracklens',
            'edition' => 'pro',
            'license_type' => 'perpetual',
        ],
    ], STRIPE_MANAGED_PAYMENTS_VERSION);

    if (!isset($session['id'], $session['url']) || !is_string($session['id']) || !is_string($session['url'])) {
        throw new RuntimeException('Stripe did not return a valid Checkout Session URL.');
    }

    $stmt = db()->prepare(<<<'SQL'
INSERT INTO orders (checkout_session_id, payment_status, amount_total, currency, updated_at)
VALUES (:session_id, :payment_status, :amount_total, :currency, CURRENT_TIMESTAMP)
ON CONFLICT(checkout_session_id) DO UPDATE SET
    payment_status = excluded.payment_status,
    amount_total = excluded.amount_total,
    currency = excluded.currency,
    updated_at = CURRENT_TIMESTAMP
SQL);
    $stmt->execute([
        ':session_id' => $session['id'],
        ':payment_status' => $session['payment_status'] ?? 'created',
        ':amount_total' => $session['amount_total'] ?? null,
        ':currency' => $session['currency'] ?? null,
    ]);

    header('Cache-Control: no-store');
    header('Location: ' . $session['url'], true, 303);
    exit;
} catch (Throwable $e) {
    error_log('TrackLens checkout error: ' . $e->getMessage());
    json_response(['error' => 'Checkout could not be started.'], 500);
}
