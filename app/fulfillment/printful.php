<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT PRINTFUL FULFILLMENT ADAPTER

   Expected private configuration:

   /private/printful.php

   return [
       'token' => '...',
       'store_id' => '...',
       'auto_confirm' => false,
   ];

   auto_confirm = false:
       Create Printful draft only.

   auto_confirm = true:
       Create draft, then immediately confirm it and allow
       Printful to charge the configured Printful account.
   ========================================================= */


/* =========================================================
   CONFIG
   ========================================================= */

function llama_printful_config(): array
{
    $path =
        dirname(
            __DIR__,
            2
        )
        . '/private/printful.php';


    if (
        !is_file(
            $path
        )
    ) {
        throw new RuntimeException(
            'Printful configuration file is missing.'
        );
    }


    $config =
        require
            $path;


    if (
        !is_array(
            $config
        )
    ) {
        throw new RuntimeException(
            'Printful configuration is invalid.'
        );
    }


    return
        $config;
}


/* =========================================================
   API TOKEN
   ========================================================= */

function llama_printful_token(
    array $config
): string
{
    $token =
        trim(
            (string) (
                $config[
                    'token'
                ]
                ?? ''
            )
        );


    if (
        $token === ''
    ) {
        throw new RuntimeException(
            'Printful API token is missing.'
        );
    }


    return
        $token;
}


/* =========================================================
   STORE ID
   ========================================================= */

function llama_printful_store_id(
    array $config
): string
{
    return
        trim(
            (string) (
                $config[
                    'store_id'
                ]
                ?? ''
            )
        );
}


/* =========================================================
   HTTP REQUEST
   ========================================================= */

function llama_printful_request(
    string $method,
    string $endpoint,
    ?array $payload = null
): array {

    $config =
        llama_printful_config();


    $token =
        llama_printful_token(
            $config
        );


    $storeId =
        llama_printful_store_id(
            $config
        );


    $url =
        'https://api.printful.com'
        .
        $endpoint;


    $headers = [

        'Authorization: Bearer '
        .
        $token,

        'Accept: application/json',

        'Content-Type: application/json',

    ];


    /*
     * Printful requires X-PF-Store-Id when using an
     * account-level token that can access multiple stores.
     */

    if (
        $storeId !== ''
    ) {
        $headers[] =
            'X-PF-Store-Id: '
            .
            $storeId;
    }


    $curl =
        curl_init();


    if (
        $curl === false
    ) {
        throw new RuntimeException(
            'Could not initialize the Printful connection.'
        );
    }


    $options = [

        CURLOPT_URL =>
            $url,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_CUSTOMREQUEST =>
            strtoupper(
                $method
            ),

        CURLOPT_HTTPHEADER =>
            $headers,

        CURLOPT_CONNECTTIMEOUT =>
            15,

        CURLOPT_TIMEOUT =>
            45,

        CURLOPT_FOLLOWLOCATION =>
            false,

    ];


    if (
        $payload !== null
    ) {

        $encoded =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                |
                JSON_UNESCAPED_UNICODE
            );


        if (
            $encoded === false
        ) {
            throw new RuntimeException(
                'Printful request data could not be encoded.'
            );
        }


        $options[
            CURLOPT_POSTFIELDS
        ] =
            $encoded;
    }


    curl_setopt_array(
        $curl,
        $options
    );


    $body =
        curl_exec(
            $curl
        );


    $curlError =
        curl_error(
            $curl
        );


    $httpCode =
        (int)
        curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );


    curl_close(
        $curl
    );


    if (
        $body === false
    ) {
        throw new RuntimeException(
            'Printful connection failed'
            .
            (
                $curlError !== ''
                    ? ': '
                      .
                      $curlError
                    : '.'
            )
        );
    }


    $decoded =
        json_decode(
            $body,
            true
        );


    if (
        !is_array(
            $decoded
        )
    ) {
        throw new RuntimeException(
            'Printful returned an unreadable response.'
        );
    }


    if (
        $httpCode < 200
        ||
        $httpCode >= 300
    ) {

        $message =
            'Printful API error.';


        if (
            isset(
                $decoded[
                    'result'
                ]
            )
        ) {

            if (
                is_string(
                    $decoded[
                        'result'
                    ]
                )
            ) {
                $message =
                    trim(
                        $decoded[
                            'result'
                        ]
                    );

            } elseif (
                is_array(
                    $decoded[
                        'result'
                    ]
                )
            ) {

                $message =
                    trim(
                        (string) (
                            $decoded[
                                'result'
                            ][
                                'message'
                            ]
                            ??
                            $decoded[
                                'result'
                            ][
                                'error'
                            ]
                            ??
                            ''
                        )
                    );
            }
        }


        if (
            $message === ''
        ) {
            $message =
                'Printful API returned HTTP '
                .
                $httpCode
                .
                '.';
        }


        throw new RuntimeException(
            $message
        );
    }


    return
        $decoded;
}


/* =========================================================
   PRINTFUL SHIPPING METHOD
   ========================================================= */

function llama_printful_shipping_method(
    array $job
): string
{
    $shipping =
        strtoupper(
            trim(
                (string) (
                    $job[
                        'shipping_method'
                    ][
                        'service'
                    ]
                    ?? ''
                )
            )
        );


    /*
     * Our internal shipping service names do not necessarily
     * equal Printful shipping codes.

     * STANDARD is the safe provider default unless the
     * product/provider data explicitly specifies another
     * Printful shipping method.
     */

    if (
        $shipping === ''
    ) {
        return
            'STANDARD';
    }


    $allowed = [

        'STANDARD',

        'EXPRESS',

        'OVERNIGHT',

    ];


    if (
        in_array(
            $shipping,
            $allowed,
            true
        )
    ) {
        return
            $shipping;
    }


    return
        'STANDARD';
}


/* =========================================================
   PRINTFUL RECIPIENT
   ========================================================= */

function llama_printful_recipient(
    array $job
): array
{
    $shipping =
        $job[
            'shipping'
        ]
        ?? [];


    $recipient = [

        'name' =>
            trim(
                (string) (
                    $shipping[
                        'name'
                    ]
                    ?? ''
                )
            ),

        'address1' =>
            trim(
                (string) (
                    $shipping[
                        'line1'
                    ]
                    ?? ''
                )
            ),

        'city' =>
            trim(
                (string) (
                    $shipping[
                        'city'
                    ]
                    ?? ''
                )
            ),

        'state_code' =>
            strtoupper(
                trim(
                    (string) (
                        $shipping[
                            'state'
                        ]
                        ?? ''
                    )
                )
            ),

        'country_code' =>
            strtoupper(
                trim(
                    (string) (
                        $shipping[
                            'country'
                        ]
                        ?? ''
                    )
                )
            ),

        'zip' =>
            trim(
                (string) (
                    $shipping[
                        'postal_code'
                    ]
                    ?? ''
                )
            ),

    ];


    $line2 =
        trim(
            (string) (
                $shipping[
                    'line2'
                ]
                ?? ''
            )
        );


    if (
        $line2 !== ''
    ) {
        $recipient[
            'address2'
        ] =
            $line2;
    }


    $phone =
        trim(
            (string) (
                $shipping[
                    'phone'
                ]
                ?? ''
            )
        );


    if (
        $phone !== ''
    ) {
        $recipient[
            'phone'
        ] =
            $phone;
    }


    $email =
        trim(
            (string) (
                $shipping[
                    'email'
                ]
                ?? ''
            )
        );


    if (
        $email !== ''
        &&
        filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $recipient[
            'email'
        ] =
            $email;
    }


    return
        $recipient;
}


/* =========================================================
   PRINTFUL ITEM
   ========================================================= */

function llama_printful_item(
    array $item
): array
{
    $providerVariantId =
        trim(
            (string) (
                $item[
                    'provider_variant_id'
                ]
                ?? ''
            )
        );


    $providerData =
        $item[
            'provider_data'
        ]
        ?? [];


    if (
        !is_array(
            $providerData
        )
    ) {
        $providerData = [];
    }


    $printfulItem = [

        'external_id' =>
            'llama-item-'
            .
            (int)
            (
                $item[
                    'order_item_id'
                ]
                ?? 0
            ),

        'quantity' =>
            max(
                1,
                (int) (
                    $item[
                        'quantity'
                    ]
                    ?? 1
                )
            ),

    ];


    /*
     * Preferred mapping:
     *
     * fulfillment_variant_id stores the Printful Sync Variant
     * ID for products already configured in the Printful store.
     */

    if (
        $providerVariantId !== ''
        &&
        ctype_digit(
            $providerVariantId
        )
    ) {

        $printfulItem[
            'sync_variant_id'
        ] =
            (int)
            $providerVariantId;


        return
            $printfulItem;
    }


    /*
     * Optional advanced mapping through fulfillment_data.
     *
     * Example:
     *
     * {
     *   "sync_variant_id": 123456789
     * }
     */

    $syncVariantId =
        $providerData[
            'sync_variant_id'
        ]
        ?? null;


    if (
        is_numeric(
            $syncVariantId
        )
        &&
        (int)
        $syncVariantId > 0
    ) {

        $printfulItem[
            'sync_variant_id'
        ] =
            (int)
            $syncVariantId;


        return
            $printfulItem;
    }


    /*
     * Catalog ordering is also supported when the product
     * contains a Printful catalog variant and print files.
     */

    $catalogVariantId =
        $providerData[
            'variant_id'
        ]
        ?? null;


    $files =
        $providerData[
            'files'
        ]
        ?? null;


    if (
        is_numeric(
            $catalogVariantId
        )
        &&
        (int)
        $catalogVariantId > 0
        &&
        is_array(
            $files
        )
        &&
        $files
    ) {

        $printfulItem[
            'variant_id'
        ] =
            (int)
            $catalogVariantId;


        $printfulItem[
            'files'
        ] =
            $files;


        if (
            isset(
                $providerData[
                    'options'
                ]
            )
            &&
            is_array(
                $providerData[
                    'options'
                ]
            )
        ) {

            $printfulItem[
                'options'
            ] =
                $providerData[
                    'options'
                ];
        }


        return
            $printfulItem;
    }


    throw new RuntimeException(
        'Printful product mapping is missing for '
        .
        (
            trim(
                (string) (
                    $item[
                        'product_name'
                    ]
                    ?? ''
                )
            )
            ?: 'an order item'
        )
        .
        '. Add a Printful Sync Variant ID or catalog variant configuration to the product variant.'
    );
}


/* =========================================================
   CREATE PRINTFUL DRAFT
   ========================================================= */

function llama_printful_create_order(
    array $job
): array
{
    $items =
        [];


    foreach (
        $job[
            'items'
        ]
        ?? []
        as
        $item
    ) {

        $items[] =
            llama_printful_item(
                $item
            );
    }


    if (
        !$items
    ) {
        throw new RuntimeException(
            'Printful fulfillment contains no items.'
        );
    }


    $payload = [

        'external_id' =>
            'llama-'
            .
            (
                trim(
                    (string) (
                        $job[
                            'order_number'
                        ]
                        ?? ''
                    )
                )
                ?: (
                    'order-'
                    .
                    (int) (
                        $job[
                            'order_id'
                        ]
                        ?? 0
                    )
                )
            )
            .
            '-f'
            .
            (int) (
                $job[
                    'fulfillment_id'
                ]
                ?? 0
            ),

        'shipping' =>
            llama_printful_shipping_method(
                $job
            ),

        'recipient' =>
            llama_printful_recipient(
                $job
            ),

        'items' =>
            $items,

    ];


    return
        llama_printful_request(
            'POST',
            '/orders',
            $payload
        );
}


/* =========================================================
   CONFIRM PRINTFUL ORDER
   ========================================================= */

function llama_printful_confirm_order(
    string $providerOrderId
): array
{
    if (
        $providerOrderId === ''
    ) {
        throw new InvalidArgumentException(
            'Printful order ID is missing.'
        );
    }


    return
        llama_printful_request(
            'POST',
            '/orders/'
            .
            rawurlencode(
                $providerOrderId
            )
            .
            '/confirm'
        );
}


/* =========================================================
   PROVIDER STATUS
   ========================================================= */

function llama_printful_normalize_status(
    string $status,
    bool $confirmed
): string
{
    $status =
        strtolower(
            trim(
                $status
            )
        );


    return match ($status) {

        'fulfilled' =>
            LLAMA_FULFILLMENT_RESULT_SHIPPED,

        'partial' =>
            LLAMA_FULFILLMENT_RESULT_PROCESSING,

        'pending',
        'inreview',
        'in_process' =>
            LLAMA_FULFILLMENT_RESULT_PROCESSING,

        'draft' =>
            $confirmed
                ? LLAMA_FULFILLMENT_RESULT_PROCESSING
                : LLAMA_FULFILLMENT_RESULT_SUBMITTED,

        default =>
            LLAMA_FULFILLMENT_RESULT_SUBMITTED,

    };
}


/* =========================================================
   EXTRACT RESULT
   ========================================================= */

function llama_printful_result(
    array $response,
    bool $confirmed
): array
{
    $result =
        $response[
            'result'
        ]
        ?? null;


    if (
        !is_array(
            $result
        )
    ) {
        throw new RuntimeException(
            'Printful did not return an order record.'
        );
    }


    $providerOrderId =
        trim(
            (string) (
                $result[
                    'id'
                ]
                ?? ''
            )
        );


    if (
        $providerOrderId === ''
    ) {
        throw new RuntimeException(
            'Printful did not return an order ID.'
        );
    }


    $status =
        llama_printful_normalize_status(
            (string) (
                $result[
                    'status'
                ]
                ?? ''
            ),
            $confirmed
        );


    $trackingNumber = '';
    $trackingUrl = '';


    /*
     * A newly created Printful order normally does not have
     * shipment tracking yet. This still supports responses
     * that happen to contain shipment data.
     */

    $shipments =
        $result[
            'shipments'
        ]
        ?? [];


    if (
        is_array(
            $shipments
        )
        &&
        $shipments
    ) {

        $shipment =
            $shipments[0];


        if (
            is_array(
                $shipment
            )
        ) {

            $trackingNumber =
                trim(
                    (string) (
                        $shipment[
                            'tracking_number'
                        ]
                        ??
                        $shipment[
                            'tracking'
                        ]
                        ??
                        ''
                    )
                );


            $trackingUrl =
                trim(
                    (string) (
                        $shipment[
                            'tracking_url'
                        ]
                        ??
                        ''
                    )
                );
        }
    }


    return [

        'provider_order_id' =>
            $providerOrderId,

        'status' =>
            $status,

        'tracking_number' =>
            $trackingNumber,

        'tracking_url' =>
            $trackingUrl,

    ];
}


/* =========================================================
   MAIN ADAPTER ENTRYPOINT

   Called by:

   llama_fulfillment_submit()
   ========================================================= */

function llama_printful_submit_fulfillment(
    PDO $db,
    array $job
): array {

    /*
     * PDO is included in the adapter signature so every
     * fulfillment provider uses the same interface.
     *
     * Printful does not currently need a direct database
     * query here.
     */

    unset(
        $db
    );


    $config =
        llama_printful_config();


    $autoConfirm =
        !empty(
            $config[
                'auto_confirm'
            ]
        );


    /*
     * First create the order as a draft.
     *
     * Creating a draft before confirming keeps the provider
     * order creation and the actual fulfillment charge as
     * separate steps.
     */

    $createResponse =
        llama_printful_create_order(
            $job
        );


    $createResult =
        llama_printful_result(
            $createResponse,
            false
        );


    $providerOrderId =
        (string)
        $createResult[
            'provider_order_id'
        ];


    if (
        !$autoConfirm
    ) {

        return
            $createResult;
    }


    /*
     * Confirming tells Printful to begin fulfillment and may
     * charge the payment method configured in Printful.
     */

    $confirmResponse =
        llama_printful_confirm_order(
            $providerOrderId
        );


    return
        llama_printful_result(
            $confirmResponse,
            true
        );
}
