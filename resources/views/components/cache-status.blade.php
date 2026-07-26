@php
    $checkedAt = isset($checkedAt) && is_string($checkedAt) && $checkedAt !== '' ? $checkedAt : null;
    $fromCache = (bool) ($fromCache ?? false);
    $scheduleHint = isset($scheduleHint) && is_string($scheduleHint) && $scheduleHint !== '' ? $scheduleHint : null;
    $absoluteLabel = null;
    $relativeLabel = null;

    if ($checkedAt !== null) {
        try {
            $parsed = \Illuminate\Support\Carbon::parse($checkedAt)->timezone(config('app.timezone'));
            $absoluteLabel = $parsed->format('j M Y, H:i');
            $relativeLabel = \EgyptDevRu\FilamentProjectPassport\Support\CheckAge::label($checkedAt);
        } catch (\Throwable) {
            $absoluteLabel = null;
            $relativeLabel = null;
        }
    }
@endphp

<div class="fi-pp-cache-status">
    <div class="fi-pp-cache-status__row">
        <span @class([
            'fi-pp-badge',
            'fi-pp-badge--outline' => $fromCache,
            'fi-pp-badge--success' => ! $fromCache,
        ])>
            {{ $fromCache ? 'Cached' : 'Fresh' }}
        </span>

        @if ($absoluteLabel !== null && $relativeLabel !== null)
            <p class="fi-pp-muted fi-pp-cache-status__check">
                Last check: {{ $absoluteLabel }} ({{ $relativeLabel }})
            </p>
        @endif
    </div>

    @if ($scheduleHint !== null)
        <p class="fi-pp-muted fi-pp-cache-status__hint">
            {{ $scheduleHint }}
        </p>
    @endif
</div>
