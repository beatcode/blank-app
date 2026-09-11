<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

try {
    $configuredPriceId = env_optional('STRIPE_PRICE_ID');
    $configuredProductId = env_optional('STRIPE_PRODUCT_ID');

    if ($configuredPriceId !== null) {
        save_stripe_resource('tracklens_pro_price', $configuredPriceId, ['source' => 'environment']);
        if ($configuredProductId !== null) {
            save_stripe_resource('tracklens_pro_product', $configuredProductId, ['source' => 'environment']);
        }
        fwrite(STDOUT, "Using existing Stripe price from STRIPE_PRICE_ID: {$configuredPriceId}\n");
        exit(0);
    }

    $storedPriceId = get_stripe_resource('tracklens_pro_price');
    if ($storedPriceId !== null) {
        fwrite(STDOUT, "TrackLens Stripe price already configured: {$storedPriceId}\n");
        exit(0);
    }

    $product = stripe_request('POST', '/v1/products', [
        'name' => 'TrackLens Pro',
        'description' => 'Track analysis and arrangement feedback for music producers.',
        'tax_code' => 'txcd_10103100',
        'default_price_data' => [
            'unit_amount' => 9900,
            'currency' => 'chf',
        ],
        'metadata' => [
            'product' => 'tracklens',
            'edition' => 'pro',
            'license_type' => 'perpetual',
        ],
    ], STRIPE_MANAGED_PAYMENTS_VERSION);

    $productId = $product['id'] ?? null;
    $priceId = stripe_object_id($product['default_price'] ?? null);

    if (!is_string($productId) || $productId === '' || $priceId === null) {
        throw new RuntimeException('Stripe did not return a product id and default price id.');
    }

    save_stripe_resource('tracklens_pro_product', $productId, [
        'name' => 'TrackLens Pro',
        'tax_code' => 'txcd_10103100',
    ]);
    save_stripe_resource('tracklens_pro_price', $priceId, [
        'currency' => 'chf',
        'unit_amount' => 9900,
    ]);

    fwrite(STDOUT, "Created TrackLens Pro in Stripe.\n");
    fwrite(STDOUT, "Product: {$productId}\n");
    fwrite(STDOUT, "Price:   {$priceId}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Stripe setup failed: {$e->getMessage()}\n");
    exit(1);
}
