<?php

declare(strict_types=1);

namespace App\Scim\Attributes;

use ArieTimmerman\Laravel\SCIMServer\Attribute\Eloquent;
use ArieTimmerman\Laravel\SCIMServer\Parser\Path;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps the SCIM boolean `active` attribute onto the app's `deactivated_at`
 * timestamp so directory (de)activation flows through one column.
 *
 * Reading reports `active: true` while `deactivated_at` is null; writing a false
 * stamps the deactivation moment (preserving an existing one so repeated pushes
 * are idempotent) and a true clears it. Filtering (`active eq true|false`) is
 * translated to a null / not-null check on the column. The session revocation
 * that must accompany a deactivation is applied by App\Http\Controllers\Scim\
 * ScimUserController, which owns the request lifecycle.
 */
class ScimActiveAttribute extends Eloquent
{
    public function __construct()
    {
        parent::__construct('active', 'deactivated_at');
    }

    /**
     * Report the account's active state (the inverse of being deactivated).
     *
     * @param  Model  $object
     * @param  array<int, string>  $attributes
     */
    #[\Override]
    protected function doRead(&$object, $attributes = []): bool
    {
        return $object->getAttribute('deactivated_at') === null;
    }

    /**
     * Apply an `active` value supplied via PUT (full replace).
     *
     * @param  mixed  $value
     * @param  mixed  $path
     */
    #[\Override]
    public function replace($value, Model &$object, $path = null): void
    {
        $this->applyActive($value, $object);
    }

    /**
     * Apply an `active` value supplied via a PATCH operation.
     *
     * @param  string  $operation
     * @param  mixed  $value
     */
    #[\Override]
    public function patch($operation, $value, Model &$object, ?Path $path = null): void
    {
        $this->applyActive($value, $object);
    }

    /**
     * Translate an `active` filter comparison to the backing timestamp column.
     *
     * @param  Builder<Model>  $query
     * @param  mixed  $parentAttribute
     */
    #[\Override]
    public function applyComparison(Builder &$query, Path $path, $parentAttribute = null): void
    {
        if (filter_var($path->node->compareValue, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('deactivated_at');

            return;
        }

        $query->whereNotNull('deactivated_at');
    }

    /**
     * Stamp or clear `deactivated_at` from a SCIM boolean.
     */
    private function applyActive(mixed $value, Model $object): void
    {
        $object->setAttribute('deactivated_at', filter_var($value, FILTER_VALIDATE_BOOLEAN)
            ? null
            : ($object->getAttribute('deactivated_at') ?? now()));

        $this->dirty = true;
    }
}
