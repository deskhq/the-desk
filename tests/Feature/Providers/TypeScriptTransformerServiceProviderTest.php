<?php

use App\Providers\TypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;

test('it configures the typescript transformer', function () {
    $this->app->forgetInstance(TypeScriptTransformerConfig::class);

    (new TypeScriptTransformerServiceProvider($this->app))->register();

    expect($this->app->make(TypeScriptTransformerConfig::class))
        ->toBeInstanceOf(TypeScriptTransformerConfig::class);
});
