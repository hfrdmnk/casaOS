<?php

use App\Providers\AppServiceProvider;
use App\Providers\FinanceServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\VerificationServiceProvider;

return [
    AppServiceProvider::class,
    FinanceServiceProvider::class,
    FortifyServiceProvider::class,
    VerificationServiceProvider::class,
];
