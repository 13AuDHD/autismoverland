<?php

declare(strict_types=1);

function llama_stripe_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configPath =
        dirname(__DIR__, 2) .
        '/private/stripe.php';

    $libraryPath =
        dirname(__DIR__, 2) .
        '/private/stripe-php/init.php';

    if (!is_file($configPath)) {
        throw new RuntimeException(
            'Private Stripe configuration is missing.'
        );
    }

    if (!is_file($libraryPath)) {
        throw new RuntimeException(
            'Stripe PHP library is missing.'
        );
    }

    require_once $libraryPath;

    $config =
        require $configPath;

    if (!is_array($config)) {
        throw new RuntimeException(
            'Private Stripe configuration is invalid.'
        );
    }

    foreach (
        [
            'secret_key',
            'monthly_price_id',
            'annual_price_id',
        ] as $key
    ) {
        if (empty($config[$key])) {
            throw new RuntimeException(
                'Stripe configuration is missing: ' .
                $key
            );
        }
    }

    return $config;
}

function llama_stripe_client(): \Stripe\StripeClient
{
    static $client = null;

    if (
        $client instanceof
        \Stripe\StripeClient
    ) {
        return $client;
    }

    $config =
        llama_stripe_config();

    $client =
        new \Stripe\StripeClient(
            $config['secret_key']
        );

    return $client;
}

function llama_stripe_price_id(
    string $interval
): string {

    $config =
        llama_stripe_config();

    return match ($interval) {
        'monthly' =>
            (string)
            $config['monthly_price_id'],

        'annual' =>
            (string)
            $config['annual_price_id'],

        default =>
            throw new InvalidArgumentException(
                'Invalid membership interval.'
            ),
    };
}
