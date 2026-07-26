<div class="fi-pp-unofficial">
    <div class="fi-pp-unofficial__inner">
        <div class="fi-pp-unofficial__icon">
            <x-filament::icon
                icon="heroicon-o-exclamation-triangle"
                class="h-8 w-8"
            />
        </div>

        <div class="fi-pp-unofficial__body">
            <div class="fi-pp-unofficial__badges">
                <span class="fi-pp-badge fi-pp-badge--solid">Not Covered by EgyptDev.ru Support</span>
                <span class="fi-pp-badge fi-pp-badge--outline">Unregistered Domain</span>
            </div>

            <h2 class="fi-pp-unofficial__title">
                Not Covered by EgyptDev.ru Support
            </h2>

            <div class="fi-pp-status-copy fi-pp-status-copy--compact">
                <p class="fi-pp-status-copy__text">
                    No active development, maintenance, or support agreement could
                    be verified for this installation.
                </p>

                <p class="fi-pp-status-copy__text">
                    This status <strong>does not affect the functionality of the software</strong>
                    and does not indicate any issue with Laravel, Filament, or any
                    other third-party software or their respective licenses.
                </p>

                <p class="fi-pp-status-copy__text">
                    If your agreement with EgyptDev.ru includes multiple installations,
                    enterprise deployment rights, staging environments, disaster
                    recovery environments, or any other deployment exceptions, or if
                    you believe this status is displayed incorrectly, please contact
                    EgyptDev.ru using the contact information provided in your contract.
                    After verification, the status can be updated if appropriate.
                </p>
            </div>

            <p class="fi-pp-unofficial__meta">
                Host checked:
                <span class="fi-pp-mono">{{ request()->getHost() }}</span>
            </p>

            <p class="fi-pp-contact">
                Contact:
                <a href="mailto:info@egyptdev.ru">info@egyptdev.ru</a>
            </p>
        </div>
    </div>
</div>
