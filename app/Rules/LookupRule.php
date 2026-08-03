<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The base for a validation rule that has to go to the database to answer —
 * "is there a record this names, and is it one you may have?" — when the
 * question carries too much domain to be a `Rule::exists` chain.
 *
 * It exists because such a rule has to reproduce something Laravel gives its own
 * `exists` and `unique` and gives nothing else: **it stays quiet when the
 * attribute has already failed**. The validator skips those two through
 * `hasNotFailedPreviousRuleIfPresenceRule()`, which is what stops a malformed id
 * that failed `uuid` from reaching the database at all — Postgres answers one
 * with a 22P02, so a rule that speaks out of turn turns a 422 into a 500. The
 * same guard is what the hand-written `after()` bodies spelled as
 * `if ($validator->errors()->has('name')) { return; }` before they moved here,
 * and it is why a rule below may assume its value is well-formed.
 *
 * The default failure is Laravel's own `exists` message, because these rules
 * replaced `Rule::exists` chains and what a client is told about a rejected id
 * is part of the behaviour those carried. A rule whose refusal means something
 * else says so by overriding {@see failWith()}.
 */
abstract class LookupRule implements ValidationRule, ValidatorAwareRule
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

        $this->failWith($fail);
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

    /**
     * Report the refusal. `validation.exists` is one of Laravel's own message
     * keys rather than copy of ours, which is why it is resolved through the
     * string rather than declared with `__()`.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    protected function failWith(Closure $fail): void
    {
        $fail('validation.exists')->translate();
    }
}
