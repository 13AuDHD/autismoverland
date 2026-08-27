<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT PRINTIFY FULFILLMENT ADAPTER

   Expected private configuration:

   /private/printify.php

   return [
       'token' => '',
       'shop_id' => '',
       'auto_send_to_production' => false,
       'shipping_method' => 1,
   ];

   Keep the Printify store's own Order Approval setting on
   Manual while testing.
   ========================================================= */


/* =========================================================
   CONFIG
   ========================================================= */

function llama_printify_config(): array
{
    static $config = null;


    if (
        $config !== null
    ) {
        return
            $config;
    }


    $path =
        dirname(
            __DIR__,
            3
        )
        . '/private/printify.php';


    if (
        !is_file(
            $path
        )
    ) {
        throw new RuntimeException(
            'Printify configuration file is missing.'
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
            'Printify configuration is invalid.'
        );
    }


    return
        $config;
}


/* =========================================================
   TOKEN
   ========================================================= */

function llama_printify_token(
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
            'Printify API token is missing.'
        );
    }


    return
        $token;
}


/* =========================================================
   SHOP ID
   ========================================================= */

function llama_printify_shop_id(
    array $config
): string
{
    $shopId =
        trim(
            (string) (
                $config[
                    'shop_id'
                ]
                ?? ''
            )
        );


    if (
        $shopId === ''
    ) {
        throw new RuntimeException(
            'Printify Shop ID is missing.'
        );
    }


    return
        $shopId;
}


/* =========================================================
   HTTP REQUEST
   ========================================================= */

function llama_printify_request(
    string $method,
    string $endpoint,
    ?array $payload = null
): array {

    $config =
        llama_printify_config();


    $token =
        llama_printify_token(
            $config
        );


    $url =
        'https://api.printify.com/v1/'
        .
        ltrim(
            $endpoint,
            '/'
        );


    $headers = [

        'Authorization: Bearer '
        .
        $token,

        'Accept: application/json',

        'Content-Type: application/json;charset=utf-8',

    ];


    $curl =
        curl_init();


    if (
        $curl === false
    ) {
        throw new RuntimeException(
            'Could not initialize the Printify connection.'
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
                'Printify request data could not be encoded.'
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
            'Printify connection failed'
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


    /*
     * Some successful Printify actions may return an empty
     * response body.
     */

    if (
        trim(
            $body
        )
        ===
        ''
    ) {

        if (
            $httpCode >= 200
            &&
            $httpCode < 300
        ) {
            return [];
        }


        throw new RuntimeException(
            'Printify returned HTTP '
            .
            $httpCode
            .
            '.'
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
            'Printify returned an unreadable response.'
        );
    }


    if (
        $httpCode < 200
        ||
        $httpCode >= 300
    ) {

        $message =
            trim(
                (string) (
                    $decoded[
                        'message'
                    ]
                    ??
                    $decoded[
                        'error'
                    ]
                    ??
                    ''
                )
            );


        if (
            $message === ''
            &&
            isset(
                $decoded[
                    'errors'
                ]
            )
        ) {

            $errorJson =
                json_encode(
                    $decoded[
                        'errors'
                    ],
                    JSON_UNESCAPED_SLASHES
                    |
                    JSON_UNESCAPED_UNICODE
                );


            if (
                is_string(
                    $errorJson
                )
            ) {
                $message =
                    $errorJson;
            }
        }


        if (
            $message === ''
        ) {
            $message =
                'Printify API returned HTTP '
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
   NAME SPLITTING
   ========================================================= */

function llama_printify_name_parts(
    string $name
): array
{
    $name =
        trim(
            preg_replace(
                '/\s+/',
                ' ',
                $name
            )
            ?? ''
        );


    if (
        $name === ''
    ) {
        return [
            'first_name' => '',
            'last_name' => '',
        ];
    }


    $parts =
        explode(
            ' ',
            $name
        );


    $firstName =
        array_shift(
            $parts
        );


    $lastName =
        trim(
            implode(
                ' ',
                $parts
            )
        );


    if (
        $lastName === ''
    ) {
        $lastName =
            $firstName;
    }


    return [

        'first_name' =>
            $firstName,

        'last_name' =>
            $lastName,

    ];
}


/* =========================================================
   ADDRESS
   ========================================================= */

function llama_printify_address(
    array $job
): array
{
    $shipping =
        $job[
            'shipping'
        ]
        ?? [];


    $nameParts =
        llama_printify_name_parts(
            trim(
                (string) (
                    $shipping[
                        'name'
                    ]
                    ?? ''
                )
            )
        );


    return [

        'first_name' =>
            $nameParts[
                'first_name'
            ],

        'last_name' =>
            $nameParts[
                'last_name'
            ],

        'email' =>
            trim(
                (string) (
                    $shipping[
                        'email'
                    ]
                    ?? ''
                )
            ),

        'phone' =>
            trim(
                (string) (
                    $shipping[
                        'phone'
                    ]
                    ?? ''
                )
            ),

        'country' =>
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

        'region' =>
            trim(
                (string) (
                    $shipping[
                        'state'
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

        'address2' =>
            trim(
                (string) (
                    $shipping[
                        'line2'
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
}


/* =========================================================
   PRINTIFY ITEM
   ========================================================= */

function llama_printify_item(
    array $item
): array
{
    $providerProductId =
        trim(
            (string) (
                $item[
                    'provider_product_id'
                ]
                ?? ''
            )
        );


    $providerVariantId =
        trim(
            (string) (
                $item[
                    'provider_variant_id'
                ]
                ?? ''
            )
        );


    $sku =
        trim(
            (string) (
                $item[
                    'sku'
                ]
                ?? ''
            )
        );


    $quantity =
        max(
            1,
            (int) (
                $item[
                    'quantity'
                ]
                ?? 1
            )
        );


    /*
     * Preferred mapping:
     *
     * fulfillment_product_id = Printify Product ID
     * fulfillment_variant_id = Printify Variant ID
     */

    if (
        $providerProductId !== ''
        &&
        $providerVariantId !== ''
        &&
        ctype_digit(
            $providerVariantId
        )
    ) {

        return [

            'product_id' =>
                $providerProductId,

            'variant_id' =>
                (int)
                $providerVariantId,

            'quantity' =>
                $quantity,

            'external_id' =>
                'llama-item-'
                .
                (int) (
                    $item[
                        'order_item_id'
                    ]
                    ?? 0
                ),

        ];
    }


    /*
     * Printify also supports ordering an existing product
     * using SKU only.
     */

    if (
        $sku !== ''
    ) {

        return [

            'sku' =>
                $sku,

            'quantity' =>
                $quantity,

            'external_id' =>
                'llama-item-'
                .
                (int) (
                    $item[
                        'order_item_id'
                    ]
                    ?? 0
                ),

        ];
    }


    throw new RuntimeException(
        'Printify product mapping is missing for '
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
        '. Add the Printify product and variant IDs or a matching SKU.'
    );
}


/* =========================================================
   SHIPPING METHOD
   ========================================================= */

function llama_printify_shipping_method(
    array $config
): int
{
    $method =
        (int) (
            $config[
                'shipping_method'
            ]
            ?? 1
        );


    /*
     * Current Printify V1 values:
     *
     * 1 = standard
     * 2 = express / priority transition
     * 3 = Printify Express
     * 4 = economy
     */

    if (
        !in_array(
            $method,
            [
                1,
                2,
                3,
                4,
            ],
            true
        )
    ) {
        return
            1;
    }


    return
        $method;
}


/* =========================================================
   CREATE ORDER
   ========================================================= */

function llama_printify_create_order(
    array $job
): array
{
    $config =
        llama_printify_config();


    $shopId =
        llama_printify_shop_id(
            $config
        );


    $lineItems =
        [];


    foreach (
        $job[
            'items'
        ]
        ?? []
        as
        $item
    ) {

        $lineItems[] =
            llama_printify_item(
                $item
            );
    }


    if (
        !$lineItems
    ) {
        throw new RuntimeException(
            'Printify fulfillment contains no items.'
        );
    }


    $externalId =
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
        );


    $payload = [

        'external_id' =>
            $externalId,

        'label' =>
            trim(
                (string) (
                    $job[
                        'order_number'
                    ]
                    ?? $externalId
                )
            ),

        'line_items' =>
            $lineItems,

        'shipping_method' =>
            llama_printify_shipping_method(
                $config
            ),

        'is_printify_express' =>
            false,

        'is_economy_shipping' =>
            false,

        /*
         * Llama Scout owns customer shipping emails.
         * Do not ask Printify to send another one.
         */

        'send_shipping_notification' =>
            false,

        'address_to' =>
            llama_printify_address(
                $job
            ),

    ];


    return
        llama_printify_request(
            'POST',
            'shops/'
            .
            rawurlencode(
                $shopId
            )
            .
            '/orders.json',
            $payload
        );
}


/* =========================================================
   SEND TO PRODUCTION
   ========================================================= */

function llama_printify_send_to_production(
    string $providerOrderId
): array
{
    if (
        $providerOrderId === ''
    ) {
        throw new InvalidArgumentException(
            'Printify order ID is missing.'
        );
    }


    $config =
        llama_printify_config();


    $shopId =
        llama_printify_shop_id(
            $config
        );


    return
        llama_printify_request(
            'POST',
            'shops/'
            .
            rawurlencode(
                $shopId
            )
            .
            '/orders/'
            .
            rawurlencode(
                $providerOrderId
            )
            .
            '/send_to_production.json'
        );
}


/* =========================================================
   NORMALIZE STATUS
   ========================================================= */

function llama_printify_normalize_status(
    string $status,
    bool $sentToProduction
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

        'sending-to-production',
        'in-production',
        'production' =>
            LLAMA_FULFILLMENT_RESULT_PROCESSING,

        'canceled' =>
            LLAMA_SHOP_FULFILLMENT_CANCELED,

        default =>
            $sentToProduction
                ? LLAMA_FULFILLMENT_RESULT_PROCESSING
                : LLAMA_FULFILLMENT_RESULT_SUBMITTED,

    };
}


/* =========================================================
   EXTRACT RESULT
   ========================================================= */

function llama_printify_result(
    array $response,
    bool $sentToProduction
): array
{
    $providerOrderId =
        trim(
            (string) (
                $response[
                    'id'
                ]
                ?? ''
            )
        );


    if (
        $providerOrderId === ''
    ) {
        throw new RuntimeException(
            'Printify did not return an order ID.'
        );
    }


    $status =
        llama_printify_normalize_status(
            (string) (
                $response[
                    'status'
                ]
                ?? ''
            ),
            $sentToProduction
        );


    $trackingNumber = '';
    $trackingUrl = '';


    $shipments =
        $response[
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
                            'number'
                        ]
                        ??
                        $shipment[
                            'tracking_number'
                        ]
                        ??
                        ''
                    )
                );


            $trackingUrl =
                trim(
                    (string) (
                        $shipment[
                            'url'
                        ]
                        ??
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
   ========================================================= */

function llama_printify_submit_fulfillment(
    PDO $db,
    array $job
): array {

    unset(
        $db
    );


    $config =
        llama_printify_config();


    $autoSend =
        !empty(
            $config[
                'auto_send_to_production'
            ]
        );


    /*
     * First create the Printify order.
     */

    $createResponse =
        llama_printify_create_order(
            $job
        );


    $createResult =
        llama_printify_result(
            $createResponse,
            false
        );


    $providerOrderId =
        (string)
        $createResult[
            'provider_order_id'
        ];


    if (
        !$autoSend
    ) {
        return
            $createResult;
    }


    /*
     * Explicitly send the order to production.
     *
     * IMPORTANT:
     * The Printify shop itself should also have automatic
     * approval disabled while using this controlled flow.
     */

    llama_printify_send_to_production(
        $providerOrderId
    );


    /*
     * The send-to-production endpoint may not return the
     * complete order record, so preserve the created order ID
     * and advance our local state to processing.
     */

    return [

        'provider_order_id' =>
            $providerOrderId,

        'status' =>
            LLAMA_FULFILLMENT_RESULT_PROCESSING,

        'tracking_number' =>
            '',

        'tracking_url' =>
            '',

    ];
}
