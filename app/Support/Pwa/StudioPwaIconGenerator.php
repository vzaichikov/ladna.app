<?php

namespace App\Support\Pwa;

use App\Models\Account;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class StudioPwaIconGenerator
{
    /**
     * @var list<int>
     */
    private const SIZES = [180, 192, 512];

    public function path(Account $account, int $size): string
    {
        if (! in_array($size, self::SIZES, true)) {
            throw new InvalidArgumentException('Unsupported PWA icon size.');
        }

        $sourcePath = $this->compliantLogoPath($account);

        if ($sourcePath === null) {
            return $this->fallbackPath($size);
        }

        $targetPath = storage_path(
            'app/public/pwa-icons/accounts/'.$account->getKey().'/icon-'.$size.'-'.$this->signature($account, $sourcePath).'.png',
        );

        if (is_file($targetPath)) {
            return $targetPath;
        }

        $directory = dirname($targetPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (! $this->renderIcon($sourcePath, $targetPath, $size)) {
            if (is_file($targetPath)) {
                unlink($targetPath);
            }

            return $this->fallbackPath($size);
        }

        return $targetPath;
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

    private function renderIcon(string $sourcePath, string $targetPath, int $size): bool
    {
        if (! function_exists('imagecreatefrompng')) {
            return false;
        }

        $source = @imagecreatefrompng($sourcePath);

        if ($source === false) {
            return false;
        }

        $target = imagecreatetruecolor($size, $size);

        if ($target === false) {
            imagedestroy($source);

            return false;
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);

        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $size, $size, $transparent);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $sourceSquare = min($sourceWidth, $sourceHeight);
        $sourceX = (int) floor(($sourceWidth - $sourceSquare) / 2);
        $sourceY = (int) floor(($sourceHeight - $sourceSquare) / 2);

        $rendered = imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $size,
            $size,
            $sourceSquare,
            $sourceSquare,
        ) && imagepng($target, $targetPath, 9);

        imagedestroy($source);
        imagedestroy($target);

        return $rendered;
    }

    private function fallbackPath(int $size): string
    {
        return public_path(match ($size) {
            180 => 'pwa/apple-touch-icon.png',
            192 => 'pwa/manifest-icon-192.png',
            default => 'pwa/manifest-icon-512.png',
        });
    }

    private function signature(Account $account, string $sourcePath): string
    {
        return substr(sha1($account->logo_path.'|'.filemtime($sourcePath).'|'.filesize($sourcePath)), 0, 12);
    }
}
