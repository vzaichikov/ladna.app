<?php

namespace App\Support\PeopleCounter;

use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PeopleCounterImageMasker
{
    /**
     * @param  array<int, array<string, mixed>|array<int, array{x: float|int, y: float|int}>>  $polygons
     */
    public function mask(string $sourcePath, string $targetPath, array $polygons): PeopleCounterCaptureResult
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('PHP GD extension is required for people counter masking.');
        }

        $disk = Storage::disk('local');
        $sourceAbsolutePath = $disk->path($sourcePath);
        $targetAbsolutePath = $disk->path($targetPath);
        $directory = dirname($targetPath);

        if ($directory !== '.') {
            $disk->makeDirectory($directory);
        }

        $contents = @file_get_contents($sourceAbsolutePath);
        $image = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Unable to read captured camera frame for masking.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $black = imagecolorallocate($image, 0, 0, 0);

        foreach ($polygons as $polygon) {
            $points = $this->polygonPoints($polygon);

            if (count($points) < 3) {
                continue;
            }

            $coordinates = [];

            foreach ($points as $point) {
                $coordinates[] = (int) round($this->clamp((float) $point['x']) * $width);
                $coordinates[] = (int) round($this->clamp((float) $point['y']) * $height);
            }

            imagefilledpolygon($image, $coordinates, $black);
        }

        if (! imagejpeg($image, $targetAbsolutePath, 90)) {
            imagedestroy($image);

            throw new RuntimeException('Unable to write masked people counter frame.');
        }

        imagedestroy($image);

        return new PeopleCounterCaptureResult(
            path: $targetPath,
            width: $width,
            height: $height,
        );
    }

    /**
     * @param  array<string, mixed>|array<int, array{x: float|int, y: float|int}>  $polygon
     * @return array<int, array{x: float|int, y: float|int}>
     */
    private function polygonPoints(array $polygon): array
    {
        $points = $polygon['points'] ?? $polygon;

        if (! is_array($points)) {
            return [];
        }

        return collect($points)
            ->filter(fn (mixed $point): bool => is_array($point) && isset($point['x'], $point['y']))
            ->values()
            ->all();
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
