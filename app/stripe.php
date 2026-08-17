<?php

declare(strict_types=1);

function llama_stripe_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configPath = dirname(__DIR__, 2) . '/private/stripe.php';
    $libraryPath = dirname(__DIR__, 2) . '/private/stripe-php/init.php';

    if (!is_file($configPath)) {
        throw new RuntimeException('Private Stripe configuration is missing.');
    }

    if (!is_file($libraryPath)) {
        throw new RuntimeException('Stripe PHP library is missing.');
    }

    require_once $libraryPath;

    $config = require $configPath;

    if (!is_array($config)) {
        throw new RuntimeException('Private Stripe configuration is invalid.');
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
                'Stripe configuration is missing: ' . $key
            );
        }
    }

    return $config;
}


function llama_stripe_webhook_secret(): string
{
    $config = llama_stripe_config();

    $secret = trim(
        (string) (
            $config['webhook_secret']
            ?? ''
        )
    );

    if ($secret === '') {
        throw new RuntimeException(
            'Stripe webhook secret is missing.'
        );
    }

    return $secret;
}


function llama_stripe_client(): \Stripe\StripeClient
{
    static $client = null;

    if ($client instanceof \Stripe\StripeClient) {
        return $client;
    }

    $config = llama_stripe_config();

    $client = new \Stripe\StripeClient(
        $config['secret_key']
    );

    return $client;
}


function llama_stripe_price_id(
    string $interval
): string {
    $config = llama_stripe_config();

    return match ($interval) {
        'monthly' => (string) $config['monthly_price_id'],
        'annual' => (string) $config['annual_price_id'],
        default => throw new InvalidArgumentException(
            'Invalid membership interval.'
        ),
    };
}


function llama_membership_interval_from_price(
    ?string $priceId
): ?string {
    if (!$priceId) {
        return null;
    }

    $config = llama_stripe_config();

    if (
        hash_equals(
            (string) $config['monthly_price_id'],
            $priceId
        )
    ) {
        return 'monthly';
    }

    if (
        hash_equals(
            (string) $config['annual_price_id'],
            $priceId
        )
    ) {
        return 'annual';
    }

    return null;
}


function llama_membership_status_from_stripe(
    ?string $stripeStatus
): string {
    return match ((string) $stripeStatus) {
        'active' => 'active',
        'trialing' => 'trialing',
        'past_due' => 'past_due',
        'canceled',
        'unpaid',
        'incomplete_expired' => 'canceled',
        'incomplete',
        'paused' => 'past_due',
        default => 'none',
    };
}


function llama_stripe_timestamp(
    mixed $timestamp
): ?string {
    if (
        $timestamp === null
        ||
        $timestamp === ''
        ||
        !is_numeric($timestamp)
    ) {
        return null;
    }

    return gmdate(
        'Y-m-d H:i:s',
        (int) $timestamp
    );
}


function llama_subscription_period_end(
    object $subscription
): ?string {
    if (
        isset($subscription->current_period_end)
        &&
        is_numeric($subscription->current_period_end)
    ) {
        return llama_stripe_timestamp(
            $subscription->current_period_end
        );
    }

    $items = $subscription->items->data ?? [];

    if (is_array($items) && $items) {
        $ends = [];

        foreach ($items as $item) {
            $end = $item->current_period_end ?? null;

            if (is_numeric($end)) {
                $ends[] = (int) $end;
            }
        }

        if ($ends) {
            return llama_stripe_timestamp(
                max($ends)
            );
        }
    }

    return null;
}


function llama_sync_stripe_subscription(
    PDO $db,
    object $subscription,
    ?int $fallbackUserId = null
): int {
    $subscriptionId = trim(
        (string) ($subscription->id ?? '')
    );

    $customerId = trim(
        (string) ($subscription->customer ?? '')
    );

    if (
        $subscriptionId === ''
        ||
        $customerId === ''
    ) {
        throw new RuntimeException(
            'Stripe subscription is missing an ID or customer.'
        );
    }

    $metadataUserId = (int) (
        $subscription->metadata->llama_user_id
        ?? 0
    );

    $userId =
        $metadataUserId > 0
            ? $metadataUserId
            : ($fallbackUserId ?? 0);

    if ($userId < 1) {
        $lookup = $db->prepare(
            '
            SELECT id
            FROM users
            WHERE stripe_subscription_id = ?
               OR stripe_customer_id = ?
            LIMIT 1
            '
        );

        $lookup->execute([
            $subscriptionId,
            $customerId,
        ]);

        $row = $lookup->fetch(
            PDO::FETCH_ASSOC
        );

        $userId = (int) ($row['id'] ?? 0);
    }

    if ($userId < 1) {
        throw new RuntimeException(
            'Could not match Stripe subscription to a Llama Scout user.'
        );
    }

    $firstItem = $subscription->items->data[0] ?? null;
    $priceId = $firstItem->price->id ?? null;

    $interval =
        llama_membership_interval_from_price(
            $priceId !== null
                ? (string) $priceId
                : null
        );

    if (!$interval) {
        $metadataInterval = trim(
            (string) (
                $subscription->metadata->membership_interval
                ?? ''
            )
        );

        if (
            in_array(
                $metadataInterval,
                ['monthly', 'annual'],
                true
            )
        ) {
            $interval = $metadataInterval;
        }
    }

    $membershipStatus =
        llama_membership_status_from_stripe(
            (string) ($subscription->status ?? '')
        );

    $startedAt =
        llama_stripe_timestamp(
            $subscription->start_date
            ?? $subscription->created
            ?? null
        );

    $endsAt =
        llama_subscription_period_end(
            $subscription
        );

    $stmt = $db->prepare(
        '
        UPDATE users
        SET
            stripe_customer_id = ?,
            stripe_subscription_id = ?,
            membership_status = ?,
            membership_interval = ?,
            membership_started_at =
                COALESCE(
                    membership_started_at,
                    ?
                ),
            membership_ends_at = ?
        WHERE id = ?
        '
    );

    $stmt->execute([
        $customerId,
        $subscriptionId,
        $membershipStatus,
        $interval,
        $startedAt,
        $endsAt,
        $userId,
    ]);

    return $userId;
}
