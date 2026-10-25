/**
 * Livewire 419 = CSRF/sesión vencida. Recarga sola, sin el confirm en inglés
 * "This page has expired".
 */
document.addEventListener('livewire:init', () => {
    if (! window.Livewire || typeof window.Livewire.hook !== 'function') {
        return;
    }

    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status !== 419) {
                return;
            }
            preventDefault();
            window.location.reload();
        });
    });
});
