<?php
namespace Tests\Concerns;

trait ConfiguresStaging
{
    private function configureStaging(): void
    {
        $this->app['env']='staging';
        config(['app.env'=>'staging','app.debug'=>false,'app.url'=>'https://staging.example.test','app.key'=>'base64:'.base64_encode(str_repeat('s',32)),'app.cipher'=>'AES-256-CBC','deployment.staging_enabled'=>true,'deployment.initial_admin_enabled'=>true,'session.driver'=>'database','session.secure'=>true,'session.encrypt'=>true,'session.http_only'=>true,'cache.default'=>'database']);
        $this->app->forgetInstance('encrypter');
        if (\Illuminate\Support\Facades\Schema::hasTable('portal_privacy_state')) {
            $state = \Illuminate\Support\Facades\DB::table('portal_privacy_state')->where('id', 1)->first();
            if ($state && (int) $state->revision === 1 && $state->current_notice_id === null && ! $state->enabled) {
                \Illuminate\Support\Facades\DB::table('portal_privacy_state')->where('id', 1)->update([
                    'proof' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['id' => 1, 'revision' => 1, 'current_notice_id' => null, 'enabled' => false])),
                ]);
            }
        }
    }
}
