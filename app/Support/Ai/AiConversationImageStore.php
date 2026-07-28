<?php

namespace App\Support\Ai;

use App\Models\AiConversationMessage;
use App\Models\AiConversationMessageAttachment;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AiConversationImageStore
{
    public const MaxInputBytes = 2 * 1024 * 1024;

    public const MaxPixels = 8_000_000;

    public const MaxEdge = 2048;

    /**
     * @var array<string, true>
     */
    private const AllowedMimeTypes = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
    ];

    public function storeUploadedImage(
        AiConversationMessage $message,
        UploadedFile $file,
    ): AiConversationMessageAttachment {
        $contents = $file->getContent();

        if (! is_string($contents)) {
            throw new InvalidAiConversationImage('invalid');
        }

        return $this->storeImage(
            $message,
            $contents,
            'dashboard',
            $file->getClientOriginalName(),
        );
    }

    public function storeTelegramImage(
        AiConversationMessage $message,
        string $contents,
        ?string $originalName = null,
    ): AiConversationMessageAttachment {
        return $this->storeImage($message, $contents, 'telegram', $originalName);
    }

    private function storeImage(
        AiConversationMessage $message,
        string $contents,
        string $source,
        ?string $originalName,
    ): AiConversationMessageAttachment {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new InvalidAiConversationImage('processor_unavailable');
        }

        if (strlen($contents) > self::MaxInputBytes) {
            throw new InvalidAiConversationImage('too_large');
        }

        $imageInfo = @getimagesizefromstring($contents);

        if (! is_array($imageInfo)) {
            throw new InvalidAiConversationImage('invalid');
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $mimeType = (string) ($imageInfo['mime'] ?? '');

        if (! isset(self::AllowedMimeTypes[$mimeType])) {
            throw new InvalidAiConversationImage('unsupported');
        }

        if ($width < 1 || $height < 1 || $width * $height > self::MaxPixels) {
            throw new InvalidAiConversationImage('invalid');
        }

        $normalized = $this->normalize($contents, $mimeType);
        $path = 'ai-conversation-images/'.$message->account_id.'/'.$message->ai_conversation_id.'/'.Str::uuid().'.webp';
        $disk = Storage::disk('local');
        $attachment = $message->attachments()->create([
            'account_id' => $message->account_id,
            'source' => $source,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->safeOriginalName($originalName),
            'mime_type' => 'image/webp',
            'byte_size' => strlen($normalized['contents']),
            'width' => $normalized['width'],
            'height' => $normalized['height'],
        ]);

        try {
            if (! $disk->put($path, $normalized['contents'])) {
                throw new InvalidAiConversationImage('storage_failed');
            }
        } catch (Throwable $throwable) {
            try {
                if (! $disk->exists($path) || $disk->delete($path)) {
                    $attachment->delete();
                }
            } catch (Throwable $cleanupFailure) {
                report($cleanupFailure);

                throw $cleanupFailure;
            }

            throw $throwable;
        }

        return $attachment;
    }

    /**
     * @return array{contents: string, width: int, height: int}
     */
    private function normalize(string $contents, string $mimeType): array
    {
        $source = @imagecreatefromstring($contents);

        if (! $source instanceof GdImage) {
            throw new InvalidAiConversationImage('invalid');
        }

        try {
            $source = $this->orient($source, $contents, $mimeType);
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $scale = min(1, self::MaxEdge / max($sourceWidth, $sourceHeight));
            $width = max(1, (int) round($sourceWidth * $scale));
            $height = max(1, (int) round($sourceHeight * $scale));
            $target = imagecreatetruecolor($width, $height);

            if (! $target instanceof GdImage) {
                throw new InvalidAiConversationImage('invalid');
            }

            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
            imagecopyresampled(
                $target,
                $source,
                0,
                0,
                0,
                0,
                $width,
                $height,
                $sourceWidth,
                $sourceHeight,
            );

            try {
                $normalized = $this->encodeWebp($target);
            } finally {
                imagedestroy($target);
            }

            return [
                'contents' => $normalized,
                'width' => $width,
                'height' => $height,
            ];
        } finally {
            imagedestroy($source);
        }
    }

    private function orient(GdImage $image, string $contents, string $mimeType): GdImage
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $temporary = tmpfile();

        if (! is_resource($temporary)) {
            return $image;
        }

        try {
            fwrite($temporary, $contents);
            $metadata = stream_get_meta_data($temporary);
            $path = $metadata['uri'] ?? null;
            $exif = is_string($path) ? @exif_read_data($path) : false;
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($image, IMG_FLIP_VERTICAL);
            }

            $angle = match ($orientation) {
                3 => 180,
                5, 6 => -90,
                7, 8 => 90,
                default => 0,
            };

            if ($angle === 0) {
                return $image;
            }

            $rotated = imagerotate($image, $angle, 0);

            if (! $rotated instanceof GdImage) {
                return $image;
            }

            imagedestroy($image);

            return $rotated;
        } finally {
            fclose($temporary);
        }
    }

    private function encodeWebp(GdImage $image): string
    {
        foreach ([90, 82, 74] as $quality) {
            ob_start();
            $encoded = imagewebp($image, null, $quality);
            $contents = ob_get_clean();

            if ($encoded && is_string($contents) && $contents !== '' && strlen($contents) <= self::MaxInputBytes) {
                return $contents;
            }
        }

        throw new InvalidAiConversationImage('too_large');
    }

    private function safeOriginalName(?string $originalName): ?string
    {
        $name = trim(basename((string) $originalName));

        return $name !== '' ? Str::limit($name, 255, '') : null;
    }
}
