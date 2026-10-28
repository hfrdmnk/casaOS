<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\VerificationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    VerificationServiceProvider::class,
];
