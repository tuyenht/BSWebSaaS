<?php

use App\Modules\Admin\Providers\AdminServiceProvider;
use App\Modules\Cms\Providers\CmsServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Provisioning\Providers\ProvisioningServiceProvider;
use App\Modules\Rbac\Providers\RbacServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Modules\Theme\Providers\ThemeServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    IdentityServiceProvider::class,
    TenancyServiceProvider::class,
    RbacServiceProvider::class,
    ProvisioningServiceProvider::class,
    ThemeServiceProvider::class,
    CmsServiceProvider::class,
    AdminServiceProvider::class,
];
