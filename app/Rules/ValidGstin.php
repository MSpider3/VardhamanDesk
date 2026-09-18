<?php

namespace App\Rules;

use App\Enums\IndianState;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidGstin implements DataAwareRule, ValidationRule
{
    /**
     * All of the data under validation.
     */
    protected array $data = [];

    public function __construct(
        protected string|IndianState|Closure|null $state = null
    ) {}

    /**
     * Set the data under validation.
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $gstin = strtoupper(trim((string) $value));

        // 1. Format check: 15-character standard GSTIN pattern
        $pattern = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/';
        if (! preg_match($pattern, $gstin)) {
            $fail('The :attribute must be a valid 15-character GSTIN (e.g. 08AAAAA0000A1Z5).');

            return;
        }

        // 2. State-code cross-check
        $resolvedState = $this->resolveState();
        if ($resolvedState) {
            $expectedStateCode = $resolvedState instanceof IndianState
                ? $resolvedState->code()
                : (IndianState::fromCodeOrName($resolvedState)?->code() ?? str_pad((string) $resolvedState, 2, '0', STR_PAD_LEFT));

            $gstinStateCode = substr($gstin, 0, 2);

            if ($gstinStateCode !== $expectedStateCode) {
                $fail("The GSTIN's first two digits ({$gstinStateCode}) do not match the selected state code ({$expectedStateCode}).");
            }
        }
    }

    /**
     * Resolve state from parameter or form data.
     */
    protected function resolveState(): string|IndianState|null
    {
        if ($this->state instanceof Closure) {
            return ($this->state)($this->data);
        }

        if ($this->state) {
            return $this->state;
        }

        return $this->data['state'] ?? null;
    }

    /**
     * Static helper for direct program-level validation.
     * Returns array of error messages (empty if valid).
     *
     * @return array<int, string>
     */
    public static function check(?string $gstin, string|IndianState|null $state = null): array
    {
        if (blank($gstin)) {
            return [];
        }

        $normalized = strtoupper(trim((string) $gstin));
        $errors = [];

        if (! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $normalized)) {
            $errors[] = 'Invalid GSTIN format. Expected 15-character alphanumeric GSTIN.';

            return $errors;
        }

        if ($state) {
            $expectedCode = $state instanceof IndianState
                ? $state->code()
                : (IndianState::fromCodeOrName($state)?->code() ?? str_pad((string) $state, 2, '0', STR_PAD_LEFT));

            $actualCode = substr($normalized, 0, 2);
            if ($actualCode !== $expectedCode) {
                $errors[] = "GSTIN state prefix ({$actualCode}) does not match the client state code ({$expectedCode}).";
            }
        }

        return $errors;
    }
}
