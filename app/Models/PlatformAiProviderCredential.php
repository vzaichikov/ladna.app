<?php

namespace App\Models;

use App\Enums\AiProvider;
use Database\Factories\PlatformAiProviderCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'model', 'credentials', 'is_configured', 'last_validated_at'])]
#[Hidden(['credentials'])]
class PlatformAiProviderCredential extends Model
{
    /** @use HasFactory<PlatformAiProviderCredentialFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'credentials' => 'encrypted:array',
            'is_configured' => 'boolean',
            'last_validated_at' => 'datetime',
        ];
    }

    public function apiKey(): ?string
    {
        $credentials = $this->credentials;

        if (! is_array($credentials)) {
            return null;
        }

        $apiKey = $credentials['api_key'] ?? null;

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }
}
