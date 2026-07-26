<?php

namespace EgyptDevRu\FilamentProjectPassport\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Human-readable age labels for audit "last checked" timestamps.
 */
final class CheckAge
{
    public static function label(?string $checkedAt): string
    {
        if ($checkedAt === null || $checkedAt === '') {
            return 'unknown';
        }

        try {
            $checked = Carbon::parse($checkedAt);
        } catch (Throwable) {
            return 'unknown';
        }

        $seconds = (int) max(0, $checked->diffInSeconds(now()));

        if ($seconds < 45) {
            return 'just now';
        }

        if ($seconds < 90) {
            return '1 minute ago';
        }

        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);

            return $minutes === 1 ? '1 minute ago' : "{$minutes} minutes ago";
        }

        if ($seconds < 5400) {
            return '1 hour ago';
        }

        if ($seconds < 86400) {
            $hours = (int) floor($seconds / 3600);

            return $hours === 1 ? '1 hour ago' : "{$hours} hours ago";
        }

        if ($seconds < 172800) {
            return '1 day ago';
        }

        $days = (int) floor($seconds / 86400);

        return "{$days} days ago";
    }
}
