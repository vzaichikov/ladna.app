<?php

namespace App\Http\Requests;

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
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->messageText() === '' && ! $this->hasFile('image')) {
                    $validator->errors()->add('message', __('app.assistant_message_or_image_required'));
                }
            },
        ];
    }

    public function messageText(): string
    {
        return trim((string) $this->input('message', ''));
    }
}
