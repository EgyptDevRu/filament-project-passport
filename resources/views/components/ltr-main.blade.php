{{-- Force LTR on Filament's main content while a Passport page is mounted. --}}
<script>
    (function () {
        const mark = 'data-fi-pp-ltr';

        function main() {
            return document.getElementById('fi-main-content')
                || document.querySelector('main.fi-main');
        }

        function sync() {
            const el = main();

            if (! el) {
                return;
            }

            if (el.querySelector('.fi-pp')) {
                if (! el.hasAttribute(mark)) {
                    el.setAttribute(mark, el.hasAttribute('dir') ? (el.getAttribute('dir') || '') : '');
                }

                el.setAttribute('dir', 'ltr');

                return;
            }

            if (! el.hasAttribute(mark)) {
                return;
            }

            const previous = el.getAttribute(mark);
            el.removeAttribute(mark);

            if (previous === '') {
                el.removeAttribute('dir');
            } else {
                el.setAttribute('dir', previous);
            }
        }

        sync();
        document.addEventListener('DOMContentLoaded', sync);
        document.addEventListener('livewire:navigated', sync);
        document.addEventListener('livewire:init', function () {
            if (! window.Livewire || typeof window.Livewire.hook !== 'function') {
                return;
            }

            window.Livewire.hook('morphed', sync);
        });
    })();
</script>
