<?php

declare(strict_types=1);


require_once
    __DIR__
    . '/place-updates.php';


/* =========================================================
   LLAMA SCOUT
   CONTRIBUTOR PLACE UPDATE REVISION

   Allows the original contributor to revise an update only
   after moderation returned it with needs-changes.

   The caller supplies a fresh canonical original-value
   snapshot so stale comparison restarts from the time of
   resubmission.

   ========================================================= */


function llama_resubmit_place_update(
    PDO $db,
    int $updateId,
    int $userId,
    array $proposedChanges,
    array $originalValues,
    string $updateType,
    ?string $visitedAt = null,
    ?string $contributorNotes = null
): void {

    if (
        $updateId < 1
        ||
        $userId < 1
    ) {

        throw new InvalidArgumentException(
            'A valid Place update and contributor are required.'
        );

    }


    if (
        !llama_valid_place_update_type(
            $updateType
        )
    ) {

        throw new InvalidArgumentException(
            'Invalid Place update type.'
        );

    }


    if (
        !$proposedChanges
        ||
        llama_update_field_count(
            $proposedChanges
        )
        < 1
    ) {

        throw new InvalidArgumentException(
            'At least one Place field must be changed.'
        );

    }


    llama_ensure_place_updates_table(
        $db
    );


    $visitedAt =
        llama_update_datetime(
            $visitedAt
        );


    $contributorNotes =
        $contributorNotes !== null
            ? trim(
                $contributorNotes
            )
            : null;


    if (
        $contributorNotes === ''
    ) {

        $contributorNotes =
            null;

    }


    $stmt =
        $db->prepare(
            '
            UPDATE place_update_submissions

            SET
                update_type = ?,
                status = \'pending\',
                visited_at = ?,
                proposed_changes = ?,
                original_values = ?,
                contributor_notes = ?,

                reviewed_by = NULL,
                review_notes = NULL,
                reviewed_at = NULL,

                contribution_id = NULL,
                scout_activity_id = NULL,
                points_awarded = 0,

                submitted_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP

            WHERE id = ?
              AND user_id = ?
              AND status = \'needs-changes\'
            '
        );


    $stmt->execute([
        $updateType,
        $visitedAt,
        llama_update_json(
            $proposedChanges
        ),
        llama_update_json(
            $originalValues
        ),
        $contributorNotes,
        $updateId,
        $userId,
    ]);


    if (
        $stmt->rowCount()
        !==
        1
    ) {

        throw new RuntimeException(
            'This Place update could not be revised. It may no longer be awaiting your changes.'
        );

    }

}
