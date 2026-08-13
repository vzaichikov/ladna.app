import Hls from 'hls.js';

export function initFestivalStreamPlayer() {
    const player = document.querySelector('[data-festival-stream-player]');

    if (!(player instanceof HTMLVideoElement)) {
        return;
    }

    const playlistUrl = player.dataset.playlistUrl;
    const heartbeatUrl = player.dataset.heartbeatUrl;
    const error = document.querySelector('[data-festival-stream-error]');

    if (!playlistUrl) {
        return;
    }

    const showError = () => error?.classList.remove('hidden');

    if (player.canPlayType('application/vnd.apple.mpegurl')) {
        player.src = playlistUrl;
    } else if (Hls.isSupported()) {
        const hls = new Hls({
            enableWorker: true,
            liveSyncDurationCount: 6,
            liveMaxLatencyDurationCount: 9,
            maxLiveSyncPlaybackRate: 1.2,
        });
        hls.loadSource(playlistUrl);
        hls.attachMedia(player);
        hls.on(Hls.Events.ERROR, (_event, data) => {
            if (data.fatal) {
                showError();
            }
        });
    } else {
        showError();
    }

    if (heartbeatUrl) {
        const heartbeat = () => fetch(heartbeatUrl, { credentials: 'same-origin' }).catch(showError);
        heartbeat();
        window.setInterval(heartbeat, 30000);
    }
}
