<?php

namespace Tests;

use App\Models\User;
use App\Modules\Tenancy\Actions\CreateOrganization;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createOrganization(User $owner, string $name = 'Kin Events', string $type = 'agency'): Organization
    {
        return app(CreateOrganization::class)->handle($owner, ['name' => $name, 'type' => $type]);
    }
}
