<?php

declare(strict_types=1);

return [
    /*
     * Products that can be enabled per tenant. The key is stored in
     * tenant_products.product; the value is the human label.
     */
    'products' => [
        'radar'   => 'RADAR — Student Performance Prediction',
        'routine' => 'Autoroutine — Class Routine & Proxy',
    ],

    /*
     * Statuses that count as "the tenant may use this product".
     */
    'active_statuses' => ['active', 'trial'],
];
