<?php

use App\Providers\TypeScriptTransformerServiceProvider;
use Illuminate\Support\Facades\File;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;

test('it configures the typescript transformer', function () {
    // Building the config validates that the output directory exists; it is not
    // committed to the repo, so ensure it is present before resolving.
    File::ensureDirectoryExists(resource_path('js/generated'));

    $this->app->forgetInstance(TypeScriptTransformerConfig::class);

    (new TypeScriptTransformerServiceProvider($this->app))->register();

    expect($this->app->make(TypeScriptTransformerConfig::class))
        ->toBeInstanceOf(TypeScriptTransformerConfig::class);
});
