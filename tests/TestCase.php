<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        if(getenv('GTA_ENGINE_TESTS')!=='1'){
            if(!app()->environment('testing') || config('database.default')!=='sqlite' || config('database.connections.sqlite.database')!==':memory:'){
                throw new \RuntimeException('Default suite only permits testing with in-memory SQLite. Use the isolated native engine runner for MySQL.');
            }
            return parent::setUpTraits();
        }
        \Tests\Support\IsolatedMysql::assertDisposable();
        $original=$this->traitsUsedByTest??class_uses_recursive(static::class);
        $refresh=isset($original[\Illuminate\Foundation\Testing\RefreshDatabase::class]);
        // MySQL DDL commits implicitly. A fresh disposable schema avoids a fake outer transaction.
        $this->traitsUsedByTest=array_diff_key($original,array_flip([
            \Illuminate\Foundation\Testing\RefreshDatabase::class,
            \Illuminate\Foundation\Testing\LazilyRefreshDatabase::class,
            \Illuminate\Foundation\Testing\DatabaseTransactions::class,
        ]));
        try{
            $uses=parent::setUpTraits();
            if($refresh){
                $this->artisan('migrate:fresh',['--force'=>true])->assertSuccessful();
                $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);
            }
            return $uses;
        }finally{$this->traitsUsedByTest=$original;}
    }

    // actingAs represents a fresh sign-in; match the version binding used by SessionController.
    public function actingAs($user, $guard = null)
    {
        parent::actingAs($user, $guard);
        $this->withSession(['auth_version' => (int) $user->auth_version]);

        return $this;
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        if (getenv('GTA_TEST_PRIVATE_DISK') === '1') {
            $app['config']->set('filesystems.disks.test_private_evidence', ['driver' => 'local', 'root' => storage_path('framework/testing/private-evidence-target'), 'visibility' => 'private', 'serve' => false, 'throw' => true]);
            $app['config']->set('evidence.disk', 'test_private_evidence');
            $app['config']->set('evidence.private_disks', ['local', 'test_private_evidence']);
        }

        return $app;
    }
}
