<?php

namespace App\Support\Festivals;

use App\Models\Account;
use App\Models\FestivalEdition;
use Illuminate\Support\Facades\View;
use LogicException;

class FestivalLandingRegistry
{
    public const DEFAULT_TEMPLATE = 'general';

    public const DEFAULT_PALETTE = 'general';

    /** @var list<string> */
    private const PALETTE_TOKENS = [
        'page',
        'surface',
        'text',
        'muted_text',
        'primary',
        'primary_text',
        'accent',
        'accent_text',
        'border',
    ];

    /**
     * @return array<string, array{key: string, name_key: string, view: string, thumbnail: string}>
     */
    public function templates(): array
    {
        $templates = collect(config('festival_landing.templates', []))
            ->filter(fn (mixed $template, mixed $key): bool => is_string($key) && $this->templateIsTrusted($key, $template))
            ->all();

        if (! array_key_exists(self::DEFAULT_TEMPLATE, $templates)) {
            throw new LogicException('The Festival landing registry must contain a trusted [general] template.');
        }

        return $templates;
    }

    /**
     * @return array<string, array{key: string, name_key: string, swatches: list<string>, tokens: array<string, string>}>
     */
    public function palettes(): array
    {
        $palettes = collect(config('festival_landing.palettes', []))
            ->filter(fn (mixed $palette, mixed $key): bool => is_string($key) && $this->paletteIsTrusted($key, $palette))
            ->all();

        if (! array_key_exists(self::DEFAULT_PALETTE, $palettes)) {
            throw new LogicException('The Festival landing registry must contain a trusted [general] palette.');
        }

        return $palettes;
    }

    /** @return array{key: string, name_key: string, view: string, thumbnail: string} */
    public function template(?string $key): array
    {
        $templates = $this->templates();

        return $templates[$key ?? ''] ?? $templates[self::DEFAULT_TEMPLATE];
    }

    /** @return array{key: string, name_key: string, swatches: list<string>, tokens: array<string, string>} */
    public function palette(?string $key): array
    {
        $palettes = $this->palettes();

        return $palettes[$key ?? ''] ?? $palettes[self::DEFAULT_PALETTE];
    }

    /** @return list<string> */
    public function availableTemplateKeys(Account $account): array
    {
        $registeredKeys = array_keys($this->templates());
        $grantedKeys = collect($account->allowed_festival_landing_templates ?? [])
            ->filter(fn (mixed $key): bool => is_string($key))
            ->intersect($registeredKeys)
            ->reject(fn (string $key): bool => $key === self::DEFAULT_TEMPLATE)
            ->values()
            ->all();

        return [self::DEFAULT_TEMPLATE, ...$grantedKeys];
    }

    /**
     * @return array<string, array{key: string, name_key: string, view: string, thumbnail: string}>
     */
    public function availableTemplates(Account $account): array
    {
        return collect($this->templates())->only($this->availableTemplateKeys($account))->all();
    }

    public function isTemplateAvailable(Account $account, ?string $key): bool
    {
        return is_string($key) && in_array($key, $this->availableTemplateKeys($account), true);
    }

    public function effectiveTemplateKey(FestivalEdition $edition, ?Account $account = null): string
    {
        $account ??= $edition->account;

        return $account instanceof Account && $this->isTemplateAvailable($account, $edition->landing_template)
            ? (string) $edition->landing_template
            : self::DEFAULT_TEMPLATE;
    }

    /** @return array{key: string, name_key: string, view: string, thumbnail: string} */
    public function effectiveTemplate(FestivalEdition $edition, ?Account $account = null): array
    {
        return $this->template($this->effectiveTemplateKey($edition, $account));
    }

    public function effectivePaletteKey(FestivalEdition $edition): string
    {
        return array_key_exists((string) $edition->landing_palette, $this->palettes())
            ? (string) $edition->landing_palette
            : self::DEFAULT_PALETTE;
    }

    /** @return array{key: string, name_key: string, swatches: list<string>, tokens: array<string, string>} */
    public function effectivePalette(FestivalEdition $edition): array
    {
        return $this->palette($this->effectivePaletteKey($edition));
    }

    private function templateIsTrusted(string $key, mixed $template): bool
    {
        if (! is_array($template) || ($template['key'] ?? null) !== $key || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
            return false;
        }

        $view = $template['view'] ?? null;
        $thumbnail = $template['thumbnail'] ?? null;

        return is_string($template['name_key'] ?? null)
            && is_string($view)
            && str_starts_with($view, 'festivals.public.templates.')
            && View::exists($view)
            && is_string($thumbnail)
            && preg_match('/^assets\/festivals\/landing-templates\/[a-z0-9_-]+\.(webp|png|jpe?g)$/', $thumbnail) === 1
            && is_file(public_path($thumbnail));
    }

    private function paletteIsTrusted(string $key, mixed $palette): bool
    {
        if (! is_array($palette)
            || ($palette['key'] ?? null) !== $key
            || preg_match('/^[a-z0-9_]+$/', $key) !== 1
            || ! is_string($palette['name_key'] ?? null)) {
            return false;
        }

        $swatches = $palette['swatches'] ?? null;
        $tokens = $palette['tokens'] ?? null;

        return is_array($swatches)
            && $swatches !== []
            && collect($swatches)->every(fn (mixed $color): bool => $this->isHexColor($color))
            && is_array($tokens)
            && array_keys($tokens) === self::PALETTE_TOKENS
            && collect($tokens)->every(fn (mixed $color): bool => $this->isHexColor($color));
    }

    private function isHexColor(mixed $color): bool
    {
        return is_string($color) && preg_match('/^#[0-9A-F]{6}$/', $color) === 1;
    }
}
