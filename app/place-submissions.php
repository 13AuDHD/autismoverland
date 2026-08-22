<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT
   PLACE SUBMISSION STORAGE HELPERS
   ========================================================= */


/* =========================================================
   ROLE SNAPSHOT COLUMN EXISTS
   ========================================================= */

function llama_place_submission_role_column_exists(
    PDO $db
): bool {

    $stmt =
        $db->prepare(
            '
            SELECT 1

            FROM information_schema.columns

            WHERE table_schema = DATABASE()

              AND table_name =
                  \'place_submissions\'

              AND column_name =
                  \'role_at_submission\'

            LIMIT 1
            '
        );


    $stmt->execute();


    return
        $stmt->fetchColumn()
        !==
        false;

}


/* =========================================================
   ENSURE ROLE SNAPSHOT COLUMN
   ========================================================= */

function llama_ensure_place_submission_role_column(
    PDO $db
): void {

    if (
        llama_place_submission_role_column_exists(
            $db
        )
    ) {

        return;

    }


    /*
     * ALTER TABLE implicitly commits in MySQL.
     * Never perform this migration from inside an application
     * transaction.
     */

    if (
        $db->inTransaction()
    ) {

        throw new RuntimeException(
            'Place submission role storage must be initialized before starting a transaction.'
        );

    }


    $db->exec(
        '
        ALTER TABLE place_submissions

        ADD COLUMN role_at_submission
            VARCHAR(50)
            NULL

        AFTER user_id
        '
    );

}
