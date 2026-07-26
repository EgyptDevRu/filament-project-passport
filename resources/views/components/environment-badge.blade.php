@if (! app()->isProduction())
    <span
        class="fi-pp-badge fi-pp-badge--warning fi-pp-env-badge"
        title="Non-production environment"
    >
        {{ app()->environment() }}
    </span>
@endif
