<?php

use App\Providers\AppServiceProvider;
use App\Providers\DemoServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SlashCommandServiceProvider;
use App\Providers\SsoServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

return [
    AppServiceProvider::class,
    DemoServiceProvider::class,
    FortifyServiceProvider::class,
    SlashCommandServiceProvider::class,
    SsoServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
];
