<?php

declare(strict_types=1);


/* =========================================================
   LLAMA SCOUT TIMEZONES
   ========================================================= */

function llama_timezones(): array
{
    return [
        'America/Denver' =>
            'Mountain Time',

        'America/Phoenix' =>
            'Arizona Time',

        'America/Los_Angeles' =>
            'Pacific Time',

        'America/Chicago' =>
            'Central Time',

        'America/New_York' =>
            'Eastern Time',

        'America/Anchorage' =>
            'Alaska Time',

        'Pacific/Honolulu' =>
            'Hawaii Time',

        'America/Toronto' =>
            'Toronto / Eastern Canada',

        'America/Vancouver' =>
            'Vancouver / Pacific Canada',

        'Europe/London' =>
            'London',

        'Europe/Paris' =>
            'Central Europe',

        'Australia/Sydney' =>
            'Sydney',

        'Pacific/Auckland' =>
            'Auckland',
    ];
}


function llama_default_timezone(): string
{
    return 'America/Denver';
}


function llama_timezone_is_valid(
    ?string $timezone
): bool {

    if (!$timezone) {
        return false;
    }

    return array_key_exists(
        $timezone,
        llama_timezones()
    );
}


function llama_user_timezone(
    ?array $user
): string {

    $timezone =
        (string) (
            $user['timezone']
            ?? ''
        );

    return llama_timezone_is_valid(
        $timezone
    )
        ? $timezone
        : llama_default_timezone();
}


/*
 * Database timestamps are treated as UTC.
 * They are converted only for display.
 */

function llama_format_datetime(
    ?string $date,
    ?string $timezone = null,
    string $format = 'M j, Y g:i A'
): string {

    if (!$date) {
        return 'Never';
    }

    $timezone =
        llama_timezone_is_valid(
            $timezone
        )
            ? $timezone
            : llama_default_timezone();

    try {

        $value =
            new DateTimeImmutable(
                $date,
                new DateTimeZone('UTC')
            );

        return $value
            ->setTimezone(
                new DateTimeZone(
                    $timezone
                )
            )
            ->format(
                $format
            );

    } catch (
        Throwable $exception
    ) {

        return $date;
    }
}


function llama_format_user_datetime(
    ?string $date,
    ?array $user,
    string $format = 'M j, Y g:i A'
): string {

    return llama_format_datetime(
        $date,
        llama_user_timezone(
            $user
        ),
        $format
    );
}
