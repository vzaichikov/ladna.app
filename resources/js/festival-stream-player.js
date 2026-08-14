import Hls from 'hls.js';

export function initFestivalStreamPlayer() {
    const player = document.querySelector('[data-festival-stream-player]');

    if (!(player instanceof HTMLElement)) {
        return;
    }

    const playlistUrl = player.dataset.playlistUrl;
    const heartbeatUrl = player.dataset.heartbeatUrl;
    const error = document.querySelector('[data-festival-stream-error]');
    let hls = null;
    let heartbeatTimer = null;
    let stopped = false;

    const stopPlayback = () => {
        if (stopped) {
            return;
        }

        stopped = true;
        if (heartbeatTimer) {
            window.clearInterval(heartbeatTimer);
        }
        hls?.destroy();
        if (player instanceof HTMLVideoElement) {
            player.pause();
            player.removeAttribute('src');
            player.load();
        } else if (player instanceof HTMLIFrameElement) {
            player.removeAttribute('src');
        }
        error?.classList.remove('hidden');
    };

    if (player instanceof HTMLVideoElement) {
        if (!playlistUrl) {
            stopPlayback();
        } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
            player.src = playlistUrl;
        } else if (Hls.isSupported()) {
            hls = new Hls({
                enableWorker: true,
                liveSyncDurationCount: 6,
                liveMaxLatencyDurationCount: 9,
                maxLiveSyncPlaybackRate: 1.2,
            });
            hls.loadSource(playlistUrl);
            hls.attachMedia(player);
            hls.on(Hls.Events.ERROR, (_event, data) => {
                if (data.fatal) {
                    stopPlayback();
                }
            });
        } else {
            stopPlayback();
        }
    }

    if (heartbeatUrl) {
        const heartbeat = async () => {
            try {
                const response = await fetch(heartbeatUrl, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('Festival stream authorization expired.');
                }
            } catch {
                stopPlayback();
            }
        };
        heartbeat();
        heartbeatTimer = window.setInterval(heartbeat, 30000);
    }
}
