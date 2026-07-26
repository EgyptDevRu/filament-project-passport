@php
    $checkedAt = isset($checkedAt) && is_string($checkedAt) && $checkedAt !== '' ? $checkedAt : null;
    $fromCache = (bool) ($fromCache ?? false);
    $scheduleHint = isset($scheduleHint) && is_string($scheduleHint) && $scheduleHint !== '' ? $scheduleHint : null;
    $initialLabel = \EgyptDevRu\FilamentProjectPassport\Support\CheckAge::label($checkedAt);
@endphp

@once
    <script>
        window.fiPpCacheStatus = window.fiPpCacheStatus || function (iso) {
            const format = (value) => {
                const then = new Date(value);
                if (Number.isNaN(then.getTime())) {
                    return 'unknown';
                }

                const seconds = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000));

                if (seconds < 45) {
                    return 'just now';
                }

                if (seconds < 90) {
                    return '1 minute ago';
                }

                if (seconds < 3600) {
                    return Math.floor(seconds / 60) + ' minutes ago';
                }

                if (seconds < 5400) {
                    return '1 hour ago';
                }

                if (seconds < 86400) {
                    return Math.floor(seconds / 3600) + ' hours ago';
                }

                if (seconds < 172800) {
                    return '1 day ago';
                }

                return Math.floor(seconds / 86400) + ' days ago';
            };

            return {
                iso: iso,
                label: format(iso),
                timer: null,
                start() {
                    this.tick();
                    this.timer = setInterval(() => this.tick(), 30000);
                },
                tick() {
                    this.label = format(this.iso);
                },
            };
        };
    </script>
@endonce

<div
    class="fi-pp-cache-status"
    @if ($checkedAt !== null)
        x-data="fiPpCacheStatus(@js($checkedAt))"
        x-init="start()"
    @endif
>
    <div class="fi-pp-cache-status__row">
        <span @class([
            'fi-pp-badge',
            'fi-pp-badge--outline' => $fromCache,
            'fi-pp-badge--success' => ! $fromCache,
        ])>
            {{ $fromCache ? 'Cached' : 'Fresh' }}
        </span>

        <p class="fi-pp-muted fi-pp-cache-status__check">
            @if ($checkedAt === null)
                Last check: unknown
            @else
                Last check:
                <span x-text="label">{{ $initialLabel }}</span>
            @endif
        </p>
    </div>

    @if ($scheduleHint !== null)
        <p class="fi-pp-muted fi-pp-cache-status__hint">
            {{ $scheduleHint }}
        </p>
    @endif
</div>
