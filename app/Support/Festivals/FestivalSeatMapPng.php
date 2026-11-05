<?php

namespace App\Support\Festivals;

use GdImage;
use InvalidArgumentException;
use SplQueue;

class FestivalSeatMapPng
{
    public const array PALETTE = [
        0xE11D48 => '#E11D48',
        0x2563EB => '#2563EB',
        0x059669 => '#059669',
        0xD97706 => '#D97706',
        0x7C3AED => '#7C3AED',
        0x0891B2 => '#0891B2',
    ];

    public const int MAX_SEATS = 2000;

    private const int WHITE = 0xFFFFFF;

    /** @return array{width:int,height:int,background:string,seats:list<array{color:string,x:int,y:int}>,warnings:array<string,int>} */
    public function parse(string $png): array
    {
        $dimensions = @getimagesizefromstring($png);
        if (! $dimensions || $dimensions[2] !== IMAGETYPE_PNG
            || min($dimensions[0], $dimensions[1]) < 100
            || max($dimensions[0], $dimensions[1]) > 2000
            || $dimensions[0] * $dimensions[1] > 2_400_000
            || strlen($png) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('invalid_png');
        }

        $source = @imagecreatefromstring($png);
        if (! $source) {
            throw new InvalidArgumentException('invalid_png');
        }

        [$width, $height] = $dimensions;
        $background = imagecreatetruecolor($width, $height);
        imagefill($background, 0, 0, self::WHITE);
        imagecopy($background, $source, 0, 0, 0, 0, $width, $height);
        unset($source);

        $seats = [];
        $warnings = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($background, $x, $y);
                if ($this->neutral($color)) {
                    continue;
                }

                $component = $this->eraseComponent($background, $x, $y, $width, $height);
                if ($component['unknown']) {
                    $warnings['unknown_colors'] = ($warnings['unknown_colors'] ?? 0) + 1;
                } elseif (! $this->isCircle($component)) {
                    $warnings['malformed_markers'] = ($warnings['malformed_markers'] ?? 0) + 1;
                } else {
                    $seats[] = [
                        'color' => self::PALETTE[$color],
                        'x' => (int) round(($component['left'] + $component['right']) * 5000 / ($width - 1)),
                        'y' => (int) round(($y + $component['bottom']) * 5000 / ($height - 1)),
                    ];
                    if (count($seats) > self::MAX_SEATS) {
                        throw new InvalidArgumentException('too_many_seats');
                    }
                }
            }
        }

        imagetruecolortopalette($background, false, 64);

        return ['width' => $width, 'height' => $height, 'background' => $this->encode($background), 'seats' => $seats, 'warnings' => $warnings];
    }

    public function template(bool $example = false, string $stage = 'STAGE'): string
    {
        $image = imagecreatetruecolor(900, 600);
        imagefill($image, 0, 0, self::WHITE);
        imagefilledrectangle($image, 280, 35, 620, 92, 0xDDDDDD);
        imagestring($image, 5, 450 - strlen($stage) * 4, 56, $stage, 0x333333);
        imageline($image, 570, 132, 570, 520, 0xAAAAAA);

        foreach ([210, 440] as $y) {
            imagefilledrectangle($image, 672, $y - 25, 796, $y + 25, 0xDDDDDD);
        }

        if ($example) {
            foreach ([170, 234, 298, 362] as $row => $y) {
                foreach ([140, 220, 300, 380] as $x) {
                    imagefilledellipse($image, $x, $y, 18, 18, $row < 2 ? 0xE11D48 : 0x2563EB);
                }
                imagestring($image, 4, 66, $y - 8, chr(65 + $row), 0x444444);
            }

            foreach ([210, 440] as $index => $y) {
                foreach ([695, 773] as $x) {
                    imagefilledellipse($image, $x, $y + 48, 18, 18, 0x059669);
                }
                imagestring($image, 5, 728, $y - 8, (string) ($index + 1), 0x444444);
            }
        }

        return $this->encode($image);
    }

    private function neutral(int $color): bool
    {
        $red = ($color >> 16) & 255;
        $green = ($color >> 8) & 255;
        $blue = $color & 255;

        return max($red, $green, $blue) - min($red, $green, $blue) <= 8;
    }

    /** @return array{left:int,right:int,top:int,bottom:int,pixels:int,mixed:bool,unknown:bool} */
    private function eraseComponent(GdImage $image, int $startX, int $startY, int $width, int $height): array
    {
        $queue = new SplQueue;
        $queue->enqueue($startY * $width + $startX);
        $firstColor = imagecolorat($image, $startX, $startY);
        imagesetpixel($image, $startX, $startY, self::WHITE);
        $left = $right = $startX;
        $top = $bottom = $startY;
        $pixels = 0;
        $mixed = false;
        $unknown = ! isset(self::PALETTE[$firstColor]);

        while (! $queue->isEmpty()) {
            $position = $queue->dequeue();
            $x = $position % $width;
            $y = intdiv($position, $width);
            $pixels++;
            $left = min($left, $x);
            $right = max($right, $x);
            $bottom = max($bottom, $y);

            for ($nearY = max(0, $y - 1); $nearY <= min($height - 1, $y + 1); $nearY++) {
                for ($nearX = max(0, $x - 1); $nearX <= min($width - 1, $x + 1); $nearX++) {
                    $color = imagecolorat($image, $nearX, $nearY);
                    if ($this->neutral($color)) {
                        continue;
                    }

                    $mixed = $mixed || $color !== $firstColor;
                    $unknown = $unknown || ! isset(self::PALETTE[$color]);
                    imagesetpixel($image, $nearX, $nearY, self::WHITE);
                    $queue->enqueue($nearY * $width + $nearX);
                }
            }
        }

        return compact('left', 'right', 'top', 'bottom', 'pixels', 'mixed', 'unknown');
    }

    /** @param array{left:int,right:int,top:int,bottom:int,pixels:int,mixed:bool,unknown:bool} $component */
    private function isCircle(array $component): bool
    {
        $width = $component['right'] - $component['left'] + 1;
        $height = $component['bottom'] - $component['top'] + 1;
        $coverage = $component['pixels'] / ($width * $height);

        return ! $component['mixed']
            && min($width, $height) >= 12
            && max($width, $height) <= 32
            && abs($width - $height) <= 2
            && $coverage >= 0.68
            && $coverage <= 0.86;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 7);

        return ob_get_clean();
    }
}
