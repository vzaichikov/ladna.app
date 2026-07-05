<?php

namespace Tests\Unit;

use App\Support\PeopleCounter\PeopleCounterImageMasker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PeopleCounterMaskTest extends TestCase
{
    public function test_masker_blacks_out_configured_polygon_area(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $sourcePath = 'people-counter/testing/source.jpg';
        $targetPath = 'people-counter/testing/masked.jpg';
        $image = imagecreatetruecolor(20, 20);
        $white = imagecolorallocate($image, 255, 255, 255);

        imagefilledrectangle($image, 0, 0, 19, 19, $white);
        $disk->makeDirectory(dirname($sourcePath));
        imagejpeg($image, $disk->path($sourcePath), 100);
        imagedestroy($image);

        $result = app(PeopleCounterImageMasker::class)->mask($sourcePath, $targetPath, [
            [
                'points' => [
                    ['x' => 0, 'y' => 0],
                    ['x' => 0.5, 'y' => 0],
                    ['x' => 0.5, 'y' => 1],
                    ['x' => 0, 'y' => 1],
                ],
            ],
        ]);

        $masked = imagecreatefromjpeg($disk->path($targetPath));
        $maskedPixel = imagecolorsforindex($masked, imagecolorat($masked, 2, 10));
        $visiblePixel = imagecolorsforindex($masked, imagecolorat($masked, 18, 10));

        imagedestroy($masked);

        $this->assertSame($targetPath, $result->path);
        $this->assertSame(20, $result->width);
        $this->assertSame(20, $result->height);
        $this->assertLessThan(10, $maskedPixel['red']);
        $this->assertLessThan(10, $maskedPixel['green']);
        $this->assertLessThan(10, $maskedPixel['blue']);
        $this->assertGreaterThan(200, $visiblePixel['red']);
        $this->assertGreaterThan(200, $visiblePixel['green']);
        $this->assertGreaterThan(200, $visiblePixel['blue']);
    }
}
