@php
    /** @var array<string, mixed> $license */
    $license = $this->license;
    $errorCode = $license['error_code'] ?? \EgyptDevRu\FilamentProjectPassport\Support\LicenseErrorCode::UNKNOWN;
@endphp

<div class="fi-pp-undefined">
    <div class="fi-pp-undefined__inner">
        <div class="fi-pp-undefined__icon">
            <x-filament::icon
                icon="heroicon-o-question-mark-circle"
                class="h-8 w-8"
            />
        </div>

        <div class="fi-pp-undefined__body">
            <div class="fi-pp-undefined__badges">
                <span class="fi-pp-badge fi-pp-badge--undefined">Undefined status</span>
            </div>

            <h2 class="fi-pp-undefined__title">
                Undefined status
            </h2>

            <div class="fi-pp-status-copy fi-pp-status-copy--compact">
                <p class="fi-pp-status-copy__text">
                    The EgyptDev.ru license-check service could not be completed for this
                    installation. This is a problem on the <strong>developer’s side</strong>,
                    not a customer fault.
                </p>

                <p class="fi-pp-status-copy__text">
                    We will fix this as soon as possible. Documentation is temporarily
                    unavailable until a successful license check can be completed.
                </p>

                <p class="fi-pp-status-copy__text">
                    This status <strong>does not affect the functionality of the software</strong>
                    and does not indicate any issue with Laravel, Filament, or any other
                    third-party software or their respective licenses.
                </p>
            </div>

            <p class="fi-pp-undefined__meta">
                Host checked:
                <span class="fi-pp-mono">{{ request()->getHost() }}</span>
                · Error code:
                <span class="fi-pp-mono">{{ $errorCode }}</span>
            </p>

            <p class="fi-pp-contact fi-pp-contact--undefined">
                Contact:
                <a href="mailto:info@egyptdev.ru">info@egyptdev.ru</a>
            </p>
        </div>
    </div>
</div>
