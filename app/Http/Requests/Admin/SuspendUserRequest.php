<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SuspendUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can suspend users
        return $this->user() && $this->user()->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'max:500',
                'min:10'
            ],
            'duration_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:365'
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan suspend wajib diisi.',
            'reason.min' => 'Alasan suspend minimal 10 karakter.',
            'reason.max' => 'Alasan suspend maksimal 500 karakter.',
            'duration_days.integer' => 'Durasi harus berupa angka.',
            'duration_days.min' => 'Durasi minimal 1 hari.',
            'duration_days.max' => 'Durasi maksimal 365 hari.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan',
            'duration_days' => 'durasi',
        ];
    }
}
