<?php

declare(strict_types=1);

/**
 * The bearer token every SCIM test authenticates with once provisioning is
 * enabled via enableScim().
 */
const SCIM_TEST_TOKEN = 'scim-secret-token';

/**
 * The core User schema urn every create/replace payload carries.
 */
const SCIM_USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

/**
 * The PatchOp schema urn every PATCH payload carries.
 */
const SCIM_PATCH_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

/**
 * A SCIM User create/replace body with sensible defaults, merged with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scimUserPayload(array $overrides = []): array
{
    return array_merge([
        'schemas' => [SCIM_USER_SCHEMA],
        'userName' => 'ada@example.com',
        'name' => ['formatted' => 'Ada Byte'],
        'active' => true,
    ], $overrides);
}

/**
 * A SCIM PatchOp body wrapping the given operations.
 *
 * @param  array<int, array<string, mixed>>  $operations
 * @return array<string, mixed>
 */
function scimPatch(array $operations): array
{
    return [
        'schemas' => [SCIM_PATCH_SCHEMA],
        'Operations' => $operations,
    ];
}
