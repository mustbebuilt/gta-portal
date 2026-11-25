<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ConfiguresStaging
{
    private function configureStaging(): void
    {
        // Resolve the lazy test migration before replacing its encryption key.
        Schema::hasTable('migrations');
        $this->app['env'] = 'staging';
        config(['app.env' => 'staging', 'app.debug' => false, 'app.url' => 'https://staging.example.test', 'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.cipher' => 'AES-256-CBC', 'deployment.staging_enabled' => true, 'deployment.initial_admin_enabled' => true, 'session.driver' => 'database', 'session.secure' => true, 'session.encrypt' => true, 'session.http_only' => true, 'cache.default' => 'database']);
        $this->app->forgetInstance('encrypter');
        if (Schema::hasTable('portal_privacy_state')) {
            $state = DB::table('portal_privacy_state')->where('id', 1)->first();
            if ($state && (int) $state->revision === 1 && $state->current_notice_id === null && ! $state->enabled) {
                DB::table('portal_privacy_state')->where('id', 1)->update([
                    'proof' => Crypt::encryptString(json_encode(['id' => 1, 'revision' => 1, 'current_notice_id' => null, 'enabled' => false])),
                ]);
            }
        }
    }
}
