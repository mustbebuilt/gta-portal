<?php
namespace Tests\Concerns;

trait ConfiguresStaging
{
    private function configureStaging(): void
    {
        $this->app['env']='staging';
        config(['app.env'=>'staging','app.debug'=>false,'app.url'=>'https://staging.example.test','app.key'=>'base64:'.base64_encode(str_repeat('s',32)),'app.cipher'=>'AES-256-CBC','deployment.staging_enabled'=>true,'deployment.initial_admin_enabled'=>true,'session.driver'=>'database','session.secure'=>true,'session.encrypt'=>true,'session.http_only'=>true,'cache.default'=>'database']);
        $this->app->forgetInstance('encrypter');
    }
}
