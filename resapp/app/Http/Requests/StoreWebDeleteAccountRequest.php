<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebDeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::exists('users', 'email')->whereNull('deleted_at'),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'confirm_deletion' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.exists' => 'We could not find an active account with this email address. Use the same email you use to sign in to the app.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $email = strtolower(trim((string) $this->input('email')));
            $verified = (string) $this->session()->get('delete_account_email_verified', '');
            $expiresTs = (int) $this->session()->get('delete_account_email_verified_expires', 0);

            if ($email === '' || $verified === '' || $verified !== $email) {
                $validator->errors()->add(
                    'email',
                    'Please verify your email using Send OTP and the code we email you before submitting.'
                );

                return;
            }

            if ($expiresTs <= 0 || Carbon::now()->getTimestamp() > $expiresTs) {
                $validator->errors()->add(
                    'email',
                    'Email verification has expired. Please send a new code and verify again.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }
}
