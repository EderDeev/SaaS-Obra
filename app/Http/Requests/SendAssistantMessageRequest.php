<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'max:2000'],
            'page_path' => ['nullable', 'string', 'max:500', 'regex:/^\//'],
            'page_title' => ['nullable', 'string', 'max:200'],
        ];
    }
}
