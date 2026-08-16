<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {

    $db = db();

    $stmt = $db->query(
        "
        SELECT
            id,
            slug,
            name,
            type,
            status,
            source_type,
            description,
            sensory_summary,
            access_summary,
            latitude,
            longitude,
            elevation_feet,
            road,
            city,
            county,
            state,
            region,
            land_manager,
            land_type,
            last_verified_at,
            published_at
        FROM places
        WHERE status IN (
            'active',
            'featured'
        )
        ORDER BY
            CASE status
                WHEN 'featured' THEN 1
                ELSE 2
            END,
            name ASC
        "
    );

    $places =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

    echo json_encode(
        [
            'ok' => true,
            'count' => count($places),
            'places' => $places
        ],
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $exception) {

    error_log(
        'Llama Scout public places API error: ' .
        $exception->getMessage()
    );

    http_response_code(500);

    echo json_encode(
        [
            'ok' => false,
            'error' =>
                'Places could not be loaded.'
        ]
    );
}
