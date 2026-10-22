<?php

declare(strict_types=1);

namespace App\Platform\Onboarding\Http;

use App\Platform\Onboarding\SignupDTO;
use App\Platform\Onboarding\Subdomain;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public signup
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'admin_email' => mb_strtolower(trim((string) $this->input('admin_email', ''))),
            'country_code' => strtoupper(trim((string) $this->input('country_code', ''))),
            // Normalised here so the length rule sees what will actually be stored.
            'requested_subdomain' => Subdomain::normalise(
                (string) $this->input('requested_subdomain', ''),
                (string) $this->input('company_name', ''),
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'min:2', 'max:120'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            // Format only. Uniqueness is on the COMPLETE address (see the provisioner), never the domain.
            'admin_email' => ['required', 'string', 'email:rfc', 'max:254'],
            // max 72: bcrypt ignores everything beyond 72 bytes.
            'admin_password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'max:72'],
            'requested_subdomain' => ['nullable', 'string', 'max:'.Subdomain::MAX],
            'business_type' => ['required', 'string', 'max:60'],
            // Plans are data, not a hard-coded list.
            'subscription_plan' => ['required', 'string', Rule::exists('plans', 'key')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $subdomain = (string) $this->input('requested_subdomain', '');
            if (! Subdomain::hasValidLength($subdomain)) {
                $validator->errors()->add(
                    'requested_subdomain',
                    'Choose a subdomain of '.Subdomain::MIN.' to '.Subdomain::MAX.' letters, numbers or hyphens.',
                );
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function toDto(): SignupDTO
    {
        $v = $this->validated();

        return new SignupDTO(
            companyName: trim($v['company_name']),
            adminName: trim($v['admin_name']),
            adminEmail: $v['admin_email'],
            adminPassword: $v['admin_password'],
            subdomain: $v['requested_subdomain'],
            businessType: trim($v['business_type']),
            subscriptionPlan: $v['subscription_plan'],
            billingCycle: $v['billing_cycle'],
            countryCode: $v['country_code'],
        );
    }
}
