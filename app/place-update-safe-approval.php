<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/place-update-approval.php';

require_once
    __DIR__
    . '/place-update-conflicts.php';


/* =========================================================
   LLAMA SCOUT
   SAFE PLACE UPDATE APPROVAL

   This is the moderation-facing approval entry point.

   It protects against stale structured updates by:

       1. locking the update submission
       2. locking the canonical Place
       3. comparing original/current/proposed values
       4. refusing approval if any field changed
       5. calling the existing approval engine only when safe

   The caller MUST already have an active transaction.

   ========================================================= */


function llama_approve_place_update_safely(
    PDO $db,
    int $updateId,
    int $reviewedBy,
    ?string $reviewNotes = null,
    ?string $moderationType = null
): array {

    if (
        !$db->inTransaction()
    ) {

        throw new RuntimeException(
            'Safe Place update approval requires an active database transaction.'
        );

    }


    if (
        $updateId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid update submission is required.'
        );

    }


    if (
        $reviewedBy < 1
    ) {

        throw new InvalidArgumentException(
            'A valid moderator is required.'
        );

    }


    /* =====================================================
       LOCK UPDATE
       ===================================================== */

    $update =
        llama_place_update(
            $db,
            $updateId,
            true
        );


    if (
        !$update
    ) {

        throw new DomainException(
            'The Place update could not be found.'
        );

    }


    if (
        !in_array(
            (string)
            $update[
                'status'
            ],
            [
                LLAMA_UPDATE_PENDING,
                LLAMA_UPDATE_NEEDS_CHANGES,
            ],
            true
        )
    ) {

        throw new DomainException(
            'This Place update is no longer awaiting approval.'
        );

    }

        $moderationType =
        $moderationType !== null

            ? trim(
                $moderationType
            )

            : trim(
                (string) (
                    $update[
                        'update_type'
                    ]
                    ?? LLAMA_PLACE_UPDATE
                )
            );


    if (
        !llama_valid_place_update_type(
            $moderationType
        )
    ) {

        throw new DomainException(
            'Choose a valid moderation classification for this contribution.'
        );

    }

    $placeId =
        (int)
        $update[
            'place_id'
        ];


    if (
        $placeId < 1
    ) {

        throw new RuntimeException(
            'The update is missing its Place.'
        );

    }


    /* =====================================================
       LOCK PLACE

       Every safe structured approval locks this same Place row
       before checking child tables. That serializes concurrent
       structured approvals for one Place.
       ===================================================== */

    $placeStmt =
        $db->prepare(
            '
            SELECT
                id,
                status

            FROM places

            WHERE id = ?

            LIMIT 1

            FOR UPDATE
            '
        );


    $placeStmt->execute([
        $placeId
    ]);


    $place =
        $placeStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (
        !$place
    ) {

        throw new DomainException(
            'The Place no longer exists.'
        );

    }


    if (
        in_array(
            (string)
            $place[
                'status'
            ],
            [
                'removed',
                'archived',
            ],
            true
        )
    ) {

        throw new DomainException(
            'Updates cannot be approved for a removed or archived Place.'
        );

    }


    /* =====================================================
       STRUCTURED VALUES
       ===================================================== */

    $changes =
        $update[
            'proposed_changes'
        ]
        ?? [];


    $originalValues =
        $update[
            'original_values'
        ]
        ?? [];


    if (
        !is_array(
            $changes
        )
        ||
        !$changes
    ) {

        throw new DomainException(
            'The update contains no structured changes.'
        );

    }


    if (
        !is_array(
            $originalValues
        )
    ) {

        $originalValues =
            [];

    }


    /* =====================================================
       STALE UPDATE CHECK
       ===================================================== */

    $fieldMap =
        llama_place_update_field_map();


    llama_assert_place_update_not_stale(
        $db,
        $placeId,
        $changes,
        $originalValues,
        $fieldMap
    );


        /*
     * Persist the moderator's classification before the
     * approval engine reloads and scores this contribution.
     *
     * Contributors do not control this value for new
     * submissions. Moderation determines whether the approved
     * contribution is an update or factual correction.
     */

    if (
        (string) (
            $update[
                'update_type'
            ]
            ?? ''
        )
        !==
        $moderationType
    ) {

        $typeStmt =
            $db->prepare(
                '
                UPDATE place_update_submissions

                SET
                    update_type = ?,
                    updated_at = CURRENT_TIMESTAMP

                WHERE id = ?
                '
            );


        $typeStmt->execute([
            $moderationType,
            $updateId,
        ]);

    }

    
    /* =====================================================
       EXISTING APPROVAL ENGINE

       The update and Place rows are already locked in this
       transaction. Re-locking them inside the approval engine
       is safe and keeps the existing engine self-contained.
       ===================================================== */

    return
        llama_approve_place_update(
            $db,
            $updateId,
            $reviewedBy,
            $reviewNotes
        );

}
