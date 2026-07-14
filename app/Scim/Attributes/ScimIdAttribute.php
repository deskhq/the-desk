<?php

declare(strict_types=1);

namespace App\Scim\Attributes;

use ArieTimmerman\Laravel\SCIMServer\Attribute\Constant;
use ArieTimmerman\Laravel\SCIMServer\Parser\Path;
use Illuminate\Database\Eloquent\Model;

/**
 * Exposes the app user's primary key as the immutable SCIM resource `id`.
 *
 * The id is server-owned: it is echoed as a string and a PUT that omits it must
 * not wipe it, so removal is a deliberate no-op (a full replace runs the parent
 * "remove attributes not present in the payload" pass over every root attribute).
 */
class ScimIdAttribute extends Constant
{
    public function __construct()
    {
        parent::__construct('id', null);
    }

    /**
     * Read the resource id as a string.
     *
     * @param  Model  $object
     * @param  array<int, string>  $attributes
     */
    #[\Override]
    protected function doRead(&$object, $attributes = []): string
    {
        return (string) $object->getKey();
    }

    /**
     * Ignore attempts to remove the server-owned id.
     *
     * @param  mixed  $value
     */
    #[\Override]
    public function remove($value, Model &$object, ?Path $path = null): void
    {
        // The id is immutable; a full-replace's "unset what's absent" pass must
        // leave it untouched.
    }
}
