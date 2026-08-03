<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The base for a rule that answers `exists`-shaped question the database can
 * only answer for a well-formed value — "is there a record this names, and is it
 * one you may have?" — when that question is too domain-laden to express as a
 * `Rule::exists` chain.
 *
 * It exists because such a rule has to reproduce two things Laravel gives its
 * own presence rules and gives nothing else:
 *
 * - **It stays quiet when the attribute has already failed.** `Exists` and
 *   `Unique` are skipped by {@see \Illuminate\Validation\Validator} through
 *   `hasNotFailedPreviousRuleIfPresenceRule()`, which is what stops a malformed
 *   id that failed `uuid` from reaching the database at all. Postgres rejects
 *   one with a 500 rather than a 422, so a rule that speaks out of turn here
 *   turns a validation error into an exception.
 * - **It fails with Laravel's own `exists` message**, because these rules
 *   replaced `Rule::exists` chains and what a client is told about a rejected id
 *   is part of the behaviour those carried.
 */
abstract class PresenceRule implements ValidationRule, ValidatorAwareRule
{
    /**
     * The validator running this rule, set by the framework before it runs.
     */
    protected ValidatorContract $validator;

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->validator->errors()->has($attribute)) {
            return;
        }

        if ($this->matches($value)) {
            return;
        }

        $fail('validation.exists')->translate();
    }

    /**
     * Set the current validator.
     */
    public function setValidator(ValidatorContract $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * Whether the given value names a record this rule accepts.
     */
    abstract protected function matches(mixed $value): bool;
}
