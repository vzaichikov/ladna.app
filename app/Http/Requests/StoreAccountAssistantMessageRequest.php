<?php

namespace App\Http\Requests;

use App\Models\PlatformAiSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreAccountAssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:2000'],
            'image' => [
                'nullable',
                File::image()
                    ->types(['jpeg', 'jpg', 'png', 'webp'])
                    ->max('2mb'),
            ],
            'voice' => [
                'nullable',
                File::types(['mp3', 'mp4', 'm4a', 'mpeg', 'mpga', 'wav', 'webm', 'ogg', 'oga', 'opus'])
                    ->max('25mb'),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->messageText() === '' && ! $this->hasFile('image') && ! $this->hasFile('voice')) {
                    $validator->errors()->add('message', __('app.assistant_message_or_image_required'));
                }
            },
            function (Validator $validator): void {
                if ($this->hasFile('image') && ! PlatformAiSetting::imageInferenceEnabled()) {
                    $validator->errors()->add('image', __('app.assistant_image_provider_unsupported'));
                }
            },
            function (Validator $validator): void {
                if (! $this->hasFile('voice')) {
                    return;
                }

                if ($this->messageText() !== '' || $this->hasFile('image')) {
                    $validator->errors()->add('voice', __('app.assistant_voice_must_be_sent_alone'));
                }

                if (! PlatformAiSetting::ownerVoiceInputEnabled()) {
                    $validator->errors()->add('voice', __('app.assistant_voice_unavailable'));
                }
            },
        ];
    }

    public function messageText(): string
    {
        return trim((string) $this->input('message', ''));
    }
}
