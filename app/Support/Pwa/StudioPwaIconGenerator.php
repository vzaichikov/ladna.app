<?php

namespace App\Support\Pwa;

use App\Models\Account;
use GdImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class StudioPwaIconGenerator
{
    private const LADNA_PLUM = '#3B223F';

    private const LADNA_DARK_PLUM = '#2B1731';

    private const LADNA_MUTED_PLUM = '#5A4261';

    private const LADNA_LAVENDER = '#A78AB9';

    private const LADNA_SOFT_LAVENDER = '#C7B4D3';

    private const LADNA_PALE_LAVENDER = '#DCCFF0';

    private const LADNA_CREAM = '#FAF8F5';

    private const LADNA_SAND = '#E7DDC9';

    /**
     * @var list<int>
     */
    private const SIZES = [180, 192, 512];

    /**
     * @var list<array{filename: string, width: int, height: int, form_factor: string, label: string}>
     */
    private const SCREENSHOTS = [
        [
            'filename' => 'screenshot-wide.png',
            'width' => 1920,
            'height' => 1080,
            'form_factor' => 'wide',
            'label' => 'A branded studio app for public schedules, booking, class passes, and customer portal access.',
        ],
        [
            'filename' => 'screenshot-narrow.png',
            'width' => 1080,
            'height' => 1920,
            'form_factor' => 'narrow',
            'label' => 'Mobile customer app for schedule, prices, bookings, and class passes under the studio brand.',
        ],
    ];

    public function ensure(Account $account): void
    {
        $directory = $this->directory($account);

        if (! $this->ensureDirectory($directory)) {
            return;
        }

        $iconSourcePath = $this->iconSourcePath($account);
        $logoPath = $this->logoArtworkPath($account);

        foreach (self::SIZES as $size) {
            $iconPath = $this->iconPath($account, $size);

            if ($this->shouldRefresh($account, $iconPath, $iconSourcePath)) {
                $this->renderIcon($iconSourcePath, $iconPath, $size);
            }
        }

        foreach ([192, 512] as $size) {
            $maskablePath = $this->maskableIconPath($account, $size);

            if ($this->shouldRefresh($account, $maskablePath, $iconSourcePath)) {
                $this->renderIcon($iconSourcePath, $maskablePath, $size, self::LADNA_PLUM, 0.12);
            }
        }

        foreach (self::SCREENSHOTS as $screenshot) {
            $targetPath = $this->screenshotPath($account, $screenshot['filename']);

            if ($this->shouldRefresh($account, $targetPath, $logoPath)) {
                $this->renderScreenshot($account, $targetPath, $screenshot['width'], $screenshot['height'], $screenshot['form_factor'], $logoPath);
            }
        }
    }

    public function path(Account $account, int $size): string
    {
        if (! in_array($size, self::SIZES, true)) {
            throw new InvalidArgumentException('Unsupported PWA icon size.');
        }

        $this->ensure($account);

        $path = $this->iconPath($account, $size);

        return is_file($path) ? $path : $this->fallbackPath($size);
    }

    public function assetPath(Account $account, string $filename): string
    {
        if (! in_array($filename, $this->assetFilenames(), true)) {
            throw new InvalidArgumentException('Unsupported PWA asset.');
        }

        $this->ensure($account);

        $path = $this->directory($account).'/'.$filename;

        if (is_file($path)) {
            return $path;
        }

        if (preg_match('/^icon-(180|192|512)\.png$/', $filename, $matches) === 1) {
            return $this->fallbackPath((int) $matches[1]);
        }

        return $this->fallbackPath(512);
    }

    /**
     * @param  callable(string): string  $url
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    public function iconEntries(Account $account, callable $url): array
    {
        $version = $this->version($account);

        return [
            [
                'src' => $url('/'.$account->slug.'/pwa/icon-192.png?v='.$version),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => $url('/'.$account->slug.'/pwa/icon-512.png?v='.$version),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => $url('/'.$account->slug.'/pwa/maskable-icon-192.png?v='.$version),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'maskable',
            ],
            [
                'src' => $url('/'.$account->slug.'/pwa/maskable-icon-512.png?v='.$version),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'maskable',
            ],
        ];
    }

    /**
     * @param  callable(string): string  $url
     * @return array{src: string, sizes: string}
     */
    public function shortcutIcon(Account $account, callable $url): array
    {
        return [
            'src' => $url('/'.$account->slug.'/pwa/icon-192.png?v='.$this->version($account)),
            'sizes' => '192x192',
        ];
    }

    /**
     * @param  callable(string): string  $url
     * @return list<array{src: string, sizes: string, type: string, form_factor: string, label: string}>
     */
    public function screenshotEntries(Account $account, callable $url): array
    {
        $version = $this->version($account);

        return array_map(
            fn (array $screenshot): array => [
                'src' => $url('/'.$account->slug.'/pwa/'.$screenshot['filename'].'?v='.$version),
                'sizes' => $screenshot['width'].'x'.$screenshot['height'],
                'type' => 'image/png',
                'form_factor' => $screenshot['form_factor'],
                'label' => $screenshot['label'],
            ],
            self::SCREENSHOTS,
        );
    }

    public function deleteForSlug(string $slug): void
    {
        if (preg_match('/^[A-Za-z0-9-]+$/', $slug) !== 1) {
            return;
        }

        File::deleteDirectory(public_path($slug.'/pwa'));
        @rmdir(public_path($slug));
    }

    private function compliantLogoPath(Account $account): ?string
    {
        if (! is_string($account->logo_path) || $account->logo_path === '' || str_starts_with($account->logo_path, 'brand/')) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($account->logo_path)) {
            return null;
        }

        $path = $disk->path($account->logo_path);
        $size = @getimagesize($path);

        if ($size === false) {
            return null;
        }

        [$width, $height] = $size;

        if (($size['mime'] ?? null) !== 'image/png' || $width < 512 || $height < 512) {
            return null;
        }

        return $path;
    }

    private function iconSourcePath(Account $account): string
    {
        return $this->compliantLogoPath($account) ?? $this->fallbackPath(512);
    }

    private function logoArtworkPath(Account $account): ?string
    {
        return $this->compliantLogoPath($account)
            ?? $this->legacyLogoPath($account)
            ?? $this->fallbackPath(512);
    }

    private function legacyLogoPath(Account $account): ?string
    {
        if (! is_string($account->logo_path) || $account->logo_path === '') {
            return null;
        }

        if (str_starts_with($account->logo_path, 'brand/')) {
            $path = public_path($account->logo_path);

            return is_file($path) ? $path : null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($account->logo_path)) {
            return null;
        }

        return $disk->path($account->logo_path);
    }

    private function renderIcon(string $sourcePath, string $targetPath, int $size, ?string $backgroundColor = null, float $paddingRatio = 0.0): bool
    {
        $source = $this->loadImage($sourcePath);

        if (! $source instanceof GdImage) {
            return false;
        }

        $target = imagecreatetruecolor($size, $size);

        if ($target === false) {
            imagedestroy($source);

            return false;
        }

        if ($backgroundColor === null) {
            imagealphablending($target, false);
            imagesavealpha($target, true);

            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $size, $size, $transparent);
            imagealphablending($target, true);
        } else {
            imagefilledrectangle($target, 0, 0, $size, $size, $this->color($target, $backgroundColor));
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $sourceSquare = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $sourceSquare) / 2);
        $sourceY = (int) floor(($sourceHeight - $sourceSquare) / 2);
        $padding = (int) floor($size * $paddingRatio);
        $destinationSize = $size - ($padding * 2);

        $rendered = imagecopyresampled(
            $target,
            $source,
            $padding,
            $padding,
            $sourceX,
            $sourceY,
            $destinationSize,
            $destinationSize,
            $sourceSquare,
            $sourceSquare,
        ) && imagepng($target, $targetPath, 9);

        imagedestroy($source);
        imagedestroy($target);

        return $rendered;
    }

    private function renderScreenshot(Account $account, string $targetPath, int $width, int $height, string $formFactor, ?string $logoPath): bool
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            return false;
        }

        $studioName = $this->trimText($account->name, 34);

        imagealphablending($image, true);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width, $height, $this->color($image, self::LADNA_CREAM));

        if ($formFactor === 'wide') {
            $this->renderWideScreenshot($image, $studioName, $logoPath);
        } else {
            $this->renderNarrowScreenshot($image, $studioName, $logoPath);
        }

        $rendered = imagepng($image, $targetPath, 9);
        imagedestroy($image);

        return $rendered;
    }

    private function renderWideScreenshot(GdImage $image, string $studioName, ?string $logoPath): void
    {
        $ink = $this->color($image, self::LADNA_DARK_PLUM);
        $muted = $this->color($image, self::LADNA_MUTED_PLUM);
        $line = $this->color($image, self::LADNA_SAND);
        $white = $this->color($image, '#FFFFFF');

        $this->drawCircle($image, -120, 650, 700, '#EFE9DE');
        $this->drawCircle($image, 1510, -120, 720, '#EFE8F6');
        $this->drawBrandLockup($image, $studioName, $logoPath, 112, 92, 86, 56, $ink, $line);

        $this->drawTextBlock($image, 'Your studio app, installed by clients', 70, 112, 258, 580, $ink, 1.14, $this->font('regular'));
        $this->drawTextBlock($image, 'Schedule, prices, booking, and class passes stay under '.$studioName.' branding.', 34, 118, 520, 570, $muted, 1.45, $this->font('regular'));
        $this->drawRoundedRectangle($image, 118, 692, 510, 760, 18, $this->color($image, self::LADNA_PLUM));
        $this->drawText($image, 'Customer app ready', 30, 156, 736, $white, $this->font('regular'));

        $this->drawStudioShowcasePanel($image, 760, 98, 1030, 812, $studioName, $logoPath, true);
        $this->drawMascot($image, 610, 398, 304, 456);

        $this->drawRoundedRectangle($image, 1080, 804, 1732, 952, 26, $white);
        $this->drawRoundedRectangleOutline($image, 1080, 804, 1732, 952, 26, $this->color($image, self::LADNA_PALE_LAVENDER), 2);
        $this->drawTextBlock($image, 'Studio branding, schedule, portal, and passes in one install.', 32, 1148, 855, 500, $ink, 1.24, $this->font('regular'));
    }

    private function renderNarrowScreenshot(GdImage $image, string $studioName, ?string $logoPath): void
    {
        $ink = $this->color($image, self::LADNA_DARK_PLUM);
        $muted = $this->color($image, self::LADNA_MUTED_PLUM);
        $line = $this->color($image, self::LADNA_SAND);
        $white = $this->color($image, '#FFFFFF');

        $this->drawCircle($image, 700, -80, 640, '#EFE8F6');
        $this->drawCircle($image, -240, 1470, 760, '#EFE9DE');
        $this->drawBrandLockup($image, $studioName, $logoPath, 76, 76, 76, 46, $ink, $line);

        $this->drawTextBlock($image, 'A studio app your clients can keep', 58, 78, 270, 800, $ink, 1.16, $this->font('regular'));
        $this->drawTextBlock($image, 'Classes, prices, passes, and account access open from one home-screen icon.', 31, 82, 522, 760, $muted, 1.42, $this->font('regular'));

        $this->drawStudioShowcasePanel($image, 78, 760, 925, 760, $studioName, $logoPath, false);
        $this->drawMascot($image, 590, 1300, 340, 510);

        $this->drawRoundedRectangle($image, 100, 1330, 700, 1475, 24, $white);
        $this->drawRoundedRectangleOutline($image, 100, 1330, 700, 1475, 24, $this->color($image, self::LADNA_PALE_LAVENDER), 2);
        $this->drawTextBlock($image, $this->trimText($studioName, 24).' stays one tap away.', 32, 138, 1384, 500, $ink, 1.25, $this->font('regular'));

        $this->drawRoundedRectangle($image, 100, 1580, 660, 1688, 24, $this->color($image, self::LADNA_PLUM));
        $this->drawText($image, 'Open studio app', 30, 242, 1648, $white, $this->font('bold'));
    }

    private function drawBrandLockup(GdImage $image, string $studioName, ?string $logoPath, int $x, int $y, int $logoSize, int $fontSize, int $ink, int $line): void
    {
        $this->drawRoundedRectangle($image, $x, $y, $x + $logoSize, $y + $logoSize, 0, $this->color($image, '#FFFFFF'));
        imagerectangle($image, $x, $y, $x + $logoSize, $y + $logoSize, $line);
        $this->drawLogo($image, $logoPath, $x + 10, $y + 10, $logoSize - 20, self::LADNA_PLUM);
        $this->drawText($image, $studioName, $fontSize, $x + $logoSize + 22, $y + (int) floor($logoSize * 0.68), $ink, $this->font('regular'));
    }

    private function drawStudioShowcasePanel(GdImage $image, int $x, int $y, int $width, int $height, string $studioName, ?string $logoPath, bool $wide): void
    {
        $ink = $this->color($image, self::LADNA_DARK_PLUM);
        $muted = $this->color($image, '#6F5A76');
        $line = $this->color($image, self::LADNA_SAND);
        $white = $this->color($image, '#FFFFFF');
        $primaryAccent = $this->color($image, self::LADNA_PLUM);
        $surface = $this->color($image, '#F7F2EA');

        $this->drawRoundedRectangle($image, $x, $y, $x + $width, $y + $height, 34, $white);
        $this->drawRoundedRectangleOutline($image, $x, $y, $x + $width, $y + $height, 34, $line, 4);

        $headerHeight = $wide ? 96 : 138;
        $this->drawRoundedRectangle($image, $x + 34, $y + 34, $x + $width - 34, $y + $headerHeight, 18, $primaryAccent);

        if ($wide) {
            $this->drawRoundedRectangle($image, $x + 58, $y + 52, $x + 112, $y + 106, 0, $white);
            $this->drawLogo($image, $logoPath, $x + 66, $y + 60, 38, self::LADNA_PLUM);
            $this->drawText($image, $this->trimText($studioName, 24), 28, $x + 132, $y + 88, $white, $this->font('bold'));
            $this->drawText($image, 'Customer portal', 18, $x + 132, $y + 116, $white, $this->font('regular'));
        } else {
            $this->drawRoundedRectangle($image, $x + 52, $y + 56, $x + 132, $y + 136, 0, $white);
            $this->drawLogo($image, $logoPath, $x + 62, $y + 66, 60, self::LADNA_PLUM);
            $this->drawText($image, $this->trimText($studioName, 21), 29, $x + 158, $y + 96, $white, $this->font('bold'));
            $this->drawText($image, 'Branded customer app', 20, $x + 158, $y + 132, $white, $this->font('regular'));
        }

        $this->drawOpportunityCard($image, $x + ($wide ? 58 : 54), $y + ($wide ? 166 : 218), $wide ? 410 : 820, $wide ? 130 : 148, $primaryAccent, 'Schedule', 'Classes and trainer slots');
        $this->drawOpportunityCard($image, $x + ($wide ? 520 : 54), $y + ($wide ? 166 : 402), $wide ? 410 : 820, $wide ? 130 : 148, $this->color($image, self::LADNA_LAVENDER), 'Prices', 'Passes and public offers');
        $this->drawOpportunityCard($image, $x + ($wide ? 58 : 54), $y + ($wide ? 336 : 586), $wide ? 410 : 820, $wide ? 130 : 148, $this->color($image, '#8D5BA6'), 'Bookings', 'Customer account access');

        if (! $wide) {
            return;
        }

        $this->drawOpportunityCard($image, $x + 520, $y + 336, 410, 130, $this->color($image, self::LADNA_SOFT_LAVENDER), 'QR links', 'Share the installable studio page');
        $calendarX = $x + 58;
        $calendarY = $y + 530;
        $calendarWidth = 870;
        $calendarHeight = 168;

        $this->drawRoundedRectangle($image, $calendarX, $calendarY, $calendarX + $calendarWidth, $calendarY + $calendarHeight, 18, $surface);
        imagerectangle($image, $calendarX, $calendarY, $calendarX + $calendarWidth, $calendarY + $calendarHeight, $line);
        $this->drawText($image, 'Today in the studio', $wide ? 22 : 23, $calendarX + 28, $calendarY + 42, $ink, $this->font('bold'));
        $this->drawText($image, 'Live schedule and class-pass actions stay visible for customers.', $wide ? 17 : 17, $calendarX + 28, $calendarY + 74, $muted, $this->font('regular'));

        $barTop = $calendarY + ($wide ? 106 : 86);
        $availableWidth = $calendarWidth - 80;
        foreach ([0, 1, 2] as $index) {
            $barX = $calendarX + 28 + (int) floor(($availableWidth / 3) * $index);
            $barWidth = (int) floor($availableWidth / 3) - 28;
            $barColor = [$primaryAccent, $this->color($image, self::LADNA_LAVENDER), $this->color($image, self::LADNA_SOFT_LAVENDER)][$index];

            $this->drawRoundedRectangle($image, $barX, $barTop, $barX + $barWidth, $barTop + 28, 8, $barColor);
        }
    }

    private function drawOpportunityCard(GdImage $image, int $x, int $y, int $width, int $height, int $accent, string $title, string $body): void
    {
        $ink = $this->color($image, '#2B1731');
        $muted = $this->color($image, '#6F5A76');
        $line = $this->color($image, '#E7DDC9');

        $this->drawRoundedRectangle($image, $x, $y, $x + $width, $y + $height, 18, $this->color($image, '#F7F2EA'));
        imagerectangle($image, $x, $y, $x + $width, $y + $height, $line);
        $this->drawRoundedRectangle($image, $x + 24, $y + 36, $x + 98, $y + $height - 36, 10, $accent);
        $this->drawText($image, $title, 25, $x + 128, $y + 58, $ink, $this->font('bold'));
        $this->drawText($image, $body, 17, $x + 128, $y + 92, $muted, $this->font('regular'));
    }

    private function drawMascot(GdImage $target, int $x, int $y, int $width, int $height): void
    {
        $this->drawImage($target, public_path('assets/brand/mascot/ladna-mascot-sporty-cutout.png'), $x, $y, $width, $height);
    }

    private function drawImage(GdImage $target, string $sourcePath, int $x, int $y, int $maxWidth, int $maxHeight): void
    {
        $source = $this->loadImage($sourcePath);

        if (! $source instanceof GdImage) {
            return;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $renderWidth = (int) floor($sourceWidth * $scale);
        $renderHeight = (int) floor($sourceHeight * $scale);

        imagealphablending($target, true);
        imagecopyresampled($target, $source, $x, $y, 0, 0, $renderWidth, $renderHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);
    }

    private function drawCircle(GdImage $image, int $x, int $y, int $diameter, string $hex): void
    {
        imagefilledellipse(
            $image,
            $x + (int) floor($diameter / 2),
            $y + (int) floor($diameter / 2),
            $diameter,
            $diameter,
            $this->color($image, $hex),
        );
    }

    private function drawRoundedRectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        if ($radius <= 0) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);

            return;
        }

        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    private function drawRoundedRectangleOutline(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color, int $thickness = 1): void
    {
        imagesetthickness($image, $thickness);
        imageline($image, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
        imageline($image, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
        imageline($image, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
        imageline($image, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagearc($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
        imagearc($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
        imagearc($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
        imagearc($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
        imagesetthickness($image, 1);
    }

    private function drawTextBlock(GdImage $image, string $text, int $fontSize, int $x, int $y, int $maxWidth, int $color, float $lineHeight = 1.25, ?string $fontPath = null): int
    {
        $fontPath ??= $this->font('regular');
        $lineStep = (int) ceil($fontSize * $lineHeight);
        $currentY = $y;

        foreach ($this->wrapText($text, $fontSize, $maxWidth, $fontPath) as $line) {
            $this->drawText($image, $line, $fontSize, $x, $currentY, $color, $fontPath);
            $currentY += $lineStep;
        }

        return $currentY;
    }

    /**
     * @return list<string>
     */
    private function wrapText(string $text, int $fontSize, int $maxWidth, ?string $fontPath): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            $line = '';

            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;

                if ($this->textWidth($candidate, $fontSize, $fontPath) <= $maxWidth || $line === '') {
                    $line = $candidate;

                    continue;
                }

                $lines[] = $line;
                $line = $word;
            }

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function drawText(GdImage $image, string $text, int $fontSize, int $x, int $baselineY, int $color, ?string $fontPath): void
    {
        if ($fontPath && function_exists('imagettftext')) {
            imagettftext($image, $fontSize, 0, $x, $baselineY, $color, $fontPath, $text);

            return;
        }

        imagestring($image, 5, $x, $baselineY - $fontSize, $this->ascii($text), $color);
    }

    private function textWidth(string $text, int $fontSize, ?string $fontPath): int
    {
        if ($fontPath && function_exists('imagettfbbox')) {
            $box = imagettfbbox($fontSize, 0, $fontPath, $text);

            if (is_array($box)) {
                return abs($box[2] - $box[0]);
            }
        }

        return strlen($this->ascii($text)) * imagefontwidth(5);
    }

    private function font(string $weight): ?string
    {
        $paths = match ($weight) {
            'bold' => [
                '/usr/share/fonts/truetype/lato/Lato-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/opentype/urw-base35/NimbusSans-Bold.otf',
            ],
            default => [
                '/usr/share/fonts/truetype/lato/Lato-Regular.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/opentype/urw-base35/NimbusSans-Regular.otf',
            ],
        };

        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function drawLogo(GdImage $target, ?string $sourcePath, int $x, int $y, int $size, string $brandColor): void
    {
        $logo = $sourcePath ? $this->loadImage($sourcePath) : null;

        if (! $logo instanceof GdImage) {
            imagefilledellipse($target, $x + (int) floor($size / 2), $y + (int) floor($size / 2), $size, $size, $this->color($target, $brandColor));

            return;
        }

        $sourceWidth = imagesx($logo);
        $sourceHeight = imagesy($logo);
        $scale = min($size / $sourceWidth, $size / $sourceHeight);
        $renderWidth = (int) floor($sourceWidth * $scale);
        $renderHeight = (int) floor($sourceHeight * $scale);
        $renderX = $x + (int) floor(($size - $renderWidth) / 2);
        $renderY = $y + (int) floor(($size - $renderHeight) / 2);

        imagealphablending($target, true);
        imagecopyresampled($target, $logo, $renderX, $renderY, 0, 0, $renderWidth, $renderHeight, $sourceWidth, $sourceHeight);
        imagedestroy($logo);
    }

    private function loadImage(string $path): ?GdImage
    {
        if (! is_file($path)) {
            return null;
        }

        $size = @getimagesize($path);
        $mime = $size['mime'] ?? null;
        $image = match ($mime) {
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => str_ends_with(strtolower($path), '.svg') ? $this->loadSvg($path) : false,
        };

        return $image instanceof GdImage ? $image : null;
    }

    private function loadSvg(string $path): GdImage|false
    {
        if (! class_exists('Imagick')) {
            return false;
        }

        try {
            $imagick = new \Imagick;
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
            $imagick->readImage($path);
            $imagick->setImageFormat('png32');
            $blob = $imagick->getImagesBlob();
            $imagick->clear();
            $imagick->destroy();
        } catch (Throwable) {
            return false;
        }

        $image = imagecreatefromstring($blob);

        return $image instanceof GdImage ? $image : false;
    }

    private function fallbackPath(int $size): string
    {
        return public_path(match ($size) {
            180 => 'pwa/apple-touch-icon.png',
            192 => 'pwa/manifest-icon-192.png',
            default => 'pwa/manifest-icon-512.png',
        });
    }

    private function iconPath(Account $account, int $size): string
    {
        return $this->directory($account).'/icon-'.$size.'.png';
    }

    private function maskableIconPath(Account $account, int $size): string
    {
        return $this->directory($account).'/maskable-icon-'.$size.'.png';
    }

    private function screenshotPath(Account $account, string $filename): string
    {
        return $this->directory($account).'/'.$filename;
    }

    private function directory(Account $account): string
    {
        return storage_path('app/pwa-assets/accounts/'.$account->getKey());
    }

    private function ensureDirectory(string $directory): bool
    {
        return is_dir($directory) || @mkdir($directory, 0755, true);
    }

    /**
     * @return list<string>
     */
    private function assetFilenames(): array
    {
        return [
            'icon-180.png',
            'icon-192.png',
            'icon-512.png',
            'maskable-icon-192.png',
            'maskable-icon-512.png',
            'screenshot-wide.png',
            'screenshot-narrow.png',
        ];
    }

    private function shouldRefresh(Account $account, string $targetPath, ?string $sourcePath = null): bool
    {
        if (! is_file($targetPath)) {
            return true;
        }

        $targetTime = filemtime($targetPath);

        if ($targetTime === false) {
            return true;
        }

        $sourceTime = $sourcePath && is_file($sourcePath) ? filemtime($sourcePath) : false;

        return $targetTime < max(
            $sourceTime !== false ? $sourceTime : 0,
            $account->updated_at?->getTimestamp() ?? 0,
            filemtime(__FILE__) ?: 0,
        );
    }

    private function version(Account $account): string
    {
        return substr(sha1(implode('|', [
            $account->getKey(),
            $account->updated_at?->getTimestamp() ?? 0,
            filemtime(__FILE__) ?: 0,
        ])), 0, 12);
    }

    private function themeColor(Account $account): string
    {
        $color = (string) $account->brand_color;

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#3B223F';
    }

    private function color(GdImage $image, string $hex): int
    {
        if (preg_match('/^#?([0-9A-Fa-f]{6})$/', $hex, $matches) !== 1) {
            $matches = [1 => '3B223F'];
        }

        return imagecolorallocate(
            $image,
            hexdec(substr($matches[1], 0, 2)),
            hexdec(substr($matches[1], 2, 2)),
            hexdec(substr($matches[1], 4, 2)),
        );
    }

    private function ascii(string $value): string
    {
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';

        return mb_strimwidth($value !== '' ? $value : 'Studio', 0, 28, '');
    }

    private function trimText(string $value, int $width): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return mb_strimwidth($value !== '' ? $value : 'Studio', 0, $width, '');
    }
}
