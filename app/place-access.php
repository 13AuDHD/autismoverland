<?php

declare(strict_types=1);

/* =========================================================
   PLACE ACCESS LEVELS
   ========================================================= */

function place_access_level(?array $user = null): string
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return 'visitor';
    }

    if (
        user_has_role('admin', (int) $user['id']) ||
        user_has_role('scout', (int) $user['id'])
    ) {
        return 'member';
    }

    if (user_has_membership($user)) {
        return 'member';
    }

    return 'free';
}

function is_member_place_access(?array $user = null): bool
{
    return place_access_level($user) === 'member';
}

function is_free_place_access(?array $user = null): bool
{
    return place_access_level($user) === 'free';
}

function is_visitor_place_access(?array $user = null): bool
{
    return place_access_level($user) === 'visitor';
}

/* =========================================================
   LOCK HELPERS
   ========================================================= */

function place_locked_value(string $accessLevel, string $requiredLevel): array
{
    return [
        'locked' => true,
        'requiredLevel' => $requiredLevel,
        'cta' => $accessLevel === 'visitor' ? 'sign_up' : 'upgrade',
    ];
}

function lock_place_section(array $section, string $accessLevel, string $requiredLevel): array
{
    $locked = [];

    foreach ($section as $field => $value) {
        $locked[$field] = place_locked_value($accessLevel, $requiredLevel);
    }

    return $locked;
}

function lock_nested_place_section(array $section, string $accessLevel, string $requiredLevel): array
{
    $locked = [];

    foreach ($section as $field => $value) {
        if (is_array($value)) {
            $locked[$field] = lock_nested_place_section(
                $value,
                $accessLevel,
                $requiredLevel
            );
            continue;
        }

        $locked[$field] = place_locked_value($accessLevel, $requiredLevel);
    }

    return $locked;
}

/* =========================================================
   PUBLIC MAP + ABOUT HELPERS
   ========================================================= */

function place_limit_coordinates(mixed $latitude, mixed $longitude): array
{
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return [
            'latitude' => null,
            'longitude' => null,
        ];
    }

    return [
        'latitude' => round((float) $latitude, 1),
        'longitude' => round((float) $longitude, 1),
    ];
}

function place_truncate_about(?string $text, int $maxCharacters = 320): ?string
{
    if ($text === null) {
        return null;
    }

    $text = trim($text);

    if ($text === '') {
        return null;
    }

    if (mb_strlen($text) <= $maxCharacters) {
        return $text;
    }

    $preview = mb_substr($text, 0, $maxCharacters);

    $sentenceEnd = max(
        mb_strrpos($preview, '.') ?: -1,
        mb_strrpos($preview, '!') ?: -1,
        mb_strrpos($preview, '?') ?: -1
    );

    if ($sentenceEnd >= (int) ($maxCharacters * 0.55)) {
        return trim(mb_substr($preview, 0, $sentenceEnd + 1));
    }

    $space = mb_strrpos($preview, ' ');

    if ($space !== false) {
        $preview = mb_substr($preview, 0, $space);
    }

    return rtrim($preview, " \t\n\r\0\x0B,;:") . '...';
}

/* =========================================================
   MEMBER VIEW
   ========================================================= */

function member_place_view(array $place): array
{
    $place['accessLevel'] = 'member';
    $place['memberAccess'] = true;
    $place['exactLocationAvailable'] = true;
    $place['aboutTruncated'] = false;
    $place['photoAccess'] = 'full';
    $place['photoModalAccess'] = true;

    return $place;
}

/* =========================================================
   FREE ACCOUNT VIEW
   ========================================================= */

function free_place_view(array $place): array
{
    $place['accessLevel'] = 'free';
    $place['memberAccess'] = false;
    $place['exactLocationAvailable'] = false;

    if (isset($place['location']) && is_array($place['location'])) {
        $approximate = place_limit_coordinates(
            $place['location']['latitude'] ?? null,
            $place['location']['longitude'] ?? null
        );

        $place['location']['latitude'] = $approximate['latitude'];
        $place['location']['longitude'] = $approximate['longitude'];
        $place['location']['road'] = place_locked_value('free', 'member');
    }

    $place['site'] = lock_place_section($place['site'] ?? [], 'free', 'member');
    $place['access'] = lock_place_section($place['access'] ?? [], 'free', 'member');
    $place['sensory'] = lock_nested_place_section($place['sensory'] ?? [], 'free', 'member');
    $place['connectivity'] = lock_place_section($place['connectivity'] ?? [], 'free', 'member');
    $place['accessibility'] = lock_place_section($place['accessibility'] ?? [], 'free', 'member');
    $place['experience'] = lock_place_section($place['experience'] ?? [], 'free', 'member');
    $place['recommendedFor'] = lock_place_section($place['recommendedFor'] ?? [], 'free', 'member');
    $place['notRecommendedFor'] = place_locked_value('free', 'member');
    $place['sensorySummary'] = place_locked_value('free', 'member');
    $place['accessSummary'] = place_locked_value('free', 'member');
    $place['notes'] = place_locked_value('free', 'member');

    if (isset($place['environment']) && is_array($place['environment'])) {
        foreach (['bugs', 'windExposure', 'sunExposure', 'shade', 'openSky'] as $field) {
            if (array_key_exists($field, $place['environment'])) {
                $place['environment'][$field] = place_locked_value('free', 'member');
            }
        }
    }

    if (isset($place['safety']) && is_array($place['safety'])) {
        foreach (['feltSafeDaytime', 'feltSafeNighttime', 'emergencyAccess'] as $field) {
            if (array_key_exists($field, $place['safety'])) {
                $place['safety'][$field] = place_locked_value('free', 'member');
            }
        }
    }

    if (isset($place['season']) && is_array($place['season'])) {
        foreach (['recommendedTravelSeason', 'seasonalAccessNote'] as $field) {
            if (array_key_exists($field, $place['season'])) {
                $place['season'][$field] = place_locked_value('free', 'member');
            }
        }
    }

    if (isset($place['nearby']) && is_array($place['nearby'])) {
        foreach (['nearestToilet', 'nearestWater'] as $field) {
            if (array_key_exists($field, $place['nearby'])) {
                $place['nearby'][$field] = place_locked_value('free', 'member');
            }
        }
    }

    $place['description'] = place_truncate_about(
        is_string($place['description'] ?? null) ? $place['description'] : null
    );
    $place['aboutTruncated'] = true;

    $place['photoAccess'] = 'gallery';
    $place['photoModalAccess'] = false;

    $place['verification'] = [
        'createdAt' => $place['createdAt'] ?? null,
        'lastVerified' => place_locked_value('free', 'member'),
        'verifiedBy' => place_locked_value('free', 'member'),
    ];

    return $place;
}

/* =========================================================
   VISITOR VIEW
   ========================================================= */

function visitor_place_view(array $place): array
{
    $place = free_place_view($place);
    $place['accessLevel'] = 'visitor';

    if (isset($place['safety']) && is_array($place['safety'])) {
        foreach ($place['safety'] as $field => $value) {
            if (is_array($value) && ($value['locked'] ?? false)) {
                continue;
            }

            $place['safety'][$field] = place_locked_value('visitor', 'free');
        }
    }

    $place['warnings'] = lock_place_section($place['warnings'] ?? [], 'visitor', 'free');

    if (isset($place['season']) && is_array($place['season'])) {
        foreach (['bestMonths', 'winterAccess', 'snowRisk', 'mudSeasonRisk', 'monsoonRisk'] as $field) {
            if (array_key_exists($field, $place['season'])) {
                $place['season'][$field] = place_locked_value('visitor', 'free');
            }
        }
    }

    if (isset($place['nearby']) && is_array($place['nearby'])) {
        foreach (['nearestFuel', 'nearestGrocery'] as $field) {
            if (array_key_exists($field, $place['nearby'])) {
                $place['nearby'][$field] = place_locked_value('visitor', 'free');
            }
        }
    }

    $place['photoAccess'] = 'featured_only';
    $place['photoModalAccess'] = false;

    if (isset($place['images']) && is_array($place['images'])) {
        $featured = [];

        foreach ($place['images'] as $image) {
            if (!empty($image['featured'])) {
                $featured[] = $image;
                break;
            }
        }

        if (!$featured && !empty($place['images'])) {
            $featured[] = $place['images'][0];
        }

        $place['images'] = $featured;
    }

    $place['verification'] = [
        'createdAt' => $place['createdAt'] ?? null,
    ];

    return $place;
}

/* =========================================================
   LEGACY API COMPATIBILITY
   ========================================================= */

/*
 * api/places.php still calls these two function names.
 * Keep them as compatibility wrappers while the API is
 * migrated to the three-level access model.
 */

function user_can_view_protected_place_data(
    ?array $user = null
): bool {

    return place_access_level(
        $user
    ) === 'member';
}


function public_place_preview(
    array $place
): array {

    $level =
        place_access_level();

    if ($level === 'free') {
        return free_place_view(
            $place
        );
    }

    if ($level === 'member') {
        return member_place_view(
            $place
        );
    }

    return visitor_place_view(
        $place
    );
}
