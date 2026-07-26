@props([
    'title' => 'Loading…',
    'message' => 'Please wait while we gather the latest data.',
])

<section class="fi-pp-card fi-pp-loading" role="status" aria-live="polite" aria-busy="true">
    <div class="fi-pp-loading__spinner" aria-hidden="true"></div>
    <div class="fi-pp-loading__body">
        <h3 class="fi-pp-loading__title">{{ $title }}</h3>
        <p class="fi-pp-loading__text">{{ $message }}</p>
    </div>
</section>
