import { router } from '@inertiajs/react';

// Kept local to this page: polling must not follow navigation to other monitors.
export function startLocationsPolling() {
    let disposed = false;
    let inFlight = false;
    let cancelRequest: (() => void) | undefined;
    let timer: ReturnType<typeof setInterval> | undefined;
    const foregroundVisits = new Set<object>();

    const reload = () => {
        if (disposed || document.hidden || inFlight || foregroundVisits.size) return;
        inFlight = true;
        // Inertia reload() always preserves React state and scroll (ReloadOptions
        // intentionally excludes preserveState/preserveScroll). Forms stay mounted.
        router.reload({
            only: ['locations'],
            showProgress: false,
            onCancelToken: token => { cancelRequest = () => token.cancel(); },
            onFinish: () => {
                inFlight = false;
                cancelRequest = undefined;
            },
        });
    };
    const stopTimer = () => {
        if (timer !== undefined) clearInterval(timer);
        timer = undefined;
    };
    const startTimer = () => {
        stopTimer();
        if (!disposed && !document.hidden) timer = setInterval(reload, 15_000);
    };
    const onVisibilityChange = () => {
        stopTimer();
        if (!document.hidden) {
            reload();
            startTimer();
        }
    };
    // Foreground CRUD/navigation wins over an outstanding async reload. Avoid
    // starting another poll until the foreground request (including redirects) ends.
    const removeStart = router.on('start', event => {
        if (!event.detail.visit.async) {
            foregroundVisits.add(event.detail.visit);
            cancelRequest?.();
        }
    });
    const removeFinish = router.on('finish', event => {
        foregroundVisits.delete(event.detail.visit);
    });
    document.addEventListener('visibilitychange', onVisibilityChange);
    startTimer();

    return () => {
        disposed = true;
        stopTimer();
        document.removeEventListener('visibilitychange', onVisibilityChange);
        removeStart();
        removeFinish();
        cancelRequest?.();
    };
}
