<?php

declare(strict_types=1);

namespace App\Scim\Attributes;

use ArieTimmerman\Laravel\SCIMServer\Attribute\Constant;
use ArieTimmerman\Laravel\SCIMServer\SCIM\Schema;

/**
 * The constant `schemas` list for the User resource.
 *
 * The vendor Constant rejects any write whose value differs from the constant,
 * which would 403 a PUT whose `schemas` array is merely reordered or carries an
 * (unmapped) extension urn. Since the value is server-defined anyway, the replace
 * is accepted as a no-op so a full replace never fails on this housekeeping field.
 */
class ScimSchemasAttribute extends Constant
{
    public function __construct()
    {
        parent::__construct('schemas', [Schema::SCHEMA_USER]);
    }

    /**
     * Accept (and ignore) the incoming schemas on a full replace.
     *
     * @param  mixed  $value
     * @param  mixed  $object
     * @param  mixed  $path
     */
    #[\Override]
    public function replace($value, &$object, $path = null): void
    {
        $this->dirty = true;
    }
}
