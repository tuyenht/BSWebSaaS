<?php

use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Rbac\Providers\RbacServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    TenancyServiceProvider::class,
    RbacServiceProvider::class,
];
