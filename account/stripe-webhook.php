<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/database.php';
require_once dirname(__DIR__) . '/app/stripe.php';


if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {
    http_response_code(405);
    exit('Method not allowed.');
}


$payload =
    file_get_contents(
        'php://input'
    );

$signature =
    $_SERVER['HTTP_STRIPE_SIGNATURE']
    ?? '';


if (
    !is_string($payload)
    ||
    $payload === ''
) {
    http_response_code(400);
    exit('Missing request body.');
}


if (
    !is_string($signature)
    ||
    $signature === ''
) {
    http_response_code(400);
    exit('Missing Stripe signature.');
}


try {

    $event =
        \Stripe\Webhook::constructEvent(
            $payload,
            $signature,
            llama_stripe_webhook_secret()
        );

} catch (
    \UnexpectedValueException $exception
) {

    error_log(
        'Llama Scout Stripe webhook invalid payload: ' .
        $exception->getMessage()
    );

    http_response_code(400);
    exit('Invalid payload.');

} catch (
    \Stripe\Exception\SignatureVerificationException
    $exception
) {

    error_log(
        'Llama Scout Stripe webhook signature error: ' .
        $exception->getMessage()
    );

    http_response_code(400);
    exit('Invalid signature.');

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Stripe webhook setup error: ' .
        $exception->getMessage()
    );

    http_response_code(500);
    exit('Webhook configuration error.');
}


$db = db();


try {

    switch ($event->type) {

        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':

            $session =
                $event->data->object;

            $userId =
                (int) (
                    $session->client_reference_id
                    ?? $session->metadata->llama_user_id
                    ?? 0
                );

            $subscriptionId =
                trim(
                    (string) (
                        $session->subscription
                        ?? ''
                    )
                );

            if (
                $userId < 1
                ||
                $subscriptionId === ''
            ) {
                throw new RuntimeException(
                    'Completed Checkout Session is missing the Llama Scout user or subscription.'
                );
            }

            $subscription =
                llama_stripe_client()
                    ->subscriptions
                    ->retrieve(
                        $subscriptionId,
                        []
                    );

            llama_sync_stripe_subscription(
                $db,
                $subscription,
                $userId
            );

            break;


        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':

            $subscription =
                $event->data->object;

            llama_sync_stripe_subscription(
                $db,
                $subscription
            );

            break;


        case 'invoice.paid':
        case 'invoice.payment_failed':

            $invoice =
                $event->data->object;

            $subscriptionId =
                trim(
                    (string) (
                        $invoice->subscription
                        ?? $invoice
                            ->parent
                            ->subscription_details
                            ->subscription
                        ?? ''
                    )
                );

            if ($subscriptionId !== '') {

                $subscription =
                    llama_stripe_client()
                        ->subscriptions
                        ->retrieve(
                            $subscriptionId,
                            []
                        );

                llama_sync_stripe_subscription(
                    $db,
                    $subscription
                );
            }

            break;


        default:
            break;
    }

} catch (
    Throwable $exception
) {

    error_log(
        'Llama Scout Stripe webhook processing error for ' .
        $event->type .
        ': ' .
        $exception->getMessage()
    );

    http_response_code(500);
    exit('Webhook processing failed.');
}


http_response_code(200);

header(
    'Content-Type: application/json'
);

echo json_encode([
    'received' => true,
]);
