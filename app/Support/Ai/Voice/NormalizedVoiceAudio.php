<?php

namespace App\Support\Ai\Voice;

use RuntimeException;

class NormalizedVoiceAudio
{
    private bool $released = false;

    public function __construct(public readonly string $path) {}

    public function release(): bool
    {
        if ($this->released) {
            return true;
        }

        if (! is_file($this->path) || @unlink($this->path)) {
            $this->released = true;

            return true;
        }

        return false;
    }

    public function __destruct()
    {
        if (! $this->release()) {
            report(new RuntimeException('Unable to remove temporary normalized voice audio.'));
        }
    }
}
