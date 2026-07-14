<?php

declare(strict_types=1);

namespace App\Scim;

use App\Models\User;
use App\Scim\Attributes\ScimActiveAttribute;
use App\Scim\Attributes\ScimIdAttribute;
use App\Scim\Attributes\ScimSchemasAttribute;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Complex;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Eloquent;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Meta;
use ArieTimmerman\Laravel\SCIMServer\Attribute\Schema as AttributeSchema;
use ArieTimmerman\Laravel\SCIMServer\SCIM\Schema;
use ArieTimmerman\Laravel\SCIMServer\SCIMConfig as BaseScimConfig;

/**
 * The SCIM resource map for this app: a single, trimmed `Users` resource.
 *
 * Groups are intentionally out of scope (issue #121), so only the User resource
 * is exposed — a request to any other resource type resolves to nothing and 404s.
 * The map is deliberately small: `userName` is the user's email (the identifier
 * the IdP reconciles and filters on), `name.formatted` the display name, and
 * `active` the deactivation state (see App\Scim\Attributes\ScimActiveAttribute).
 * Creation and the activation side effects are handled by ScimUserController so
 * every SCIM user flows through the shared App\Actions\Sso\ProvisionSsoUser layer.
 */
class ScimConfig extends BaseScimConfig
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getUserConfig(): array
    {
        return [
            'class' => User::class,
            'singular' => 'User',
            'withRelations' => [],
            'description' => 'User Account',

            'map' => (new Complex)->withSubAttributes(
                new ScimSchemasAttribute,
                new ScimIdAttribute,
                new Meta('Users'),
                (new AttributeSchema(Schema::SCHEMA_USER, true))->withSubAttributes(
                    (new Eloquent('userName', 'email'))->ensure('required'),
                    (new Complex('name'))->withSubAttributes(
                        new Eloquent('formatted', 'name'),
                    ),
                    new ScimActiveAttribute,
                ),
            ),
        ];
    }

    /**
     * Expose only the User resource; Groups are out of scope for this app.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function getConfig(): array
    {
        return [
            'Users' => $this->getUserConfig(),
        ];
    }
}
