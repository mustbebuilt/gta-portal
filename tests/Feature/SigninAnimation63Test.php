<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;
class SigninAnimation63Test extends TestCase
{
 use LazilyRefreshDatabase;
 public function test_login_includes_animation_assets_and_one_pause_control_without_losing_the_form():void{
  $r=$this->get('/login');$r->assertOk()
   ->assertSee('assets/signin-atmosphere.css?v=63',false)->assertSee('assets/signin-atmosphere.js?v=63',false)
   ->assertSee('data-atmosphere-controls',false)->assertSee('data-animation-toggle',false)
   ->assertSee('name="email"',false)->assertSee('name="password"',false)->assertSee('name="_token"',false)
   ->assertSee('Forgotten your password?')->assertSee('data-signin-theme',false);
  $html=$r->getContent();
  $this->assertSame(1,substr_count($html,'data-animation-toggle'));
  $this->assertSame(1,substr_count($html,'signin-atmosphere.js'));
  $this->assertSame(1,substr_count($html,'signin-atmosphere.css'));
  $this->assertStringNotContainsString('<audio',$html);
  $this->assertLessThan(strpos($html,'<form method="post"'),strpos($html,'data-atmosphere-controls'),'Pause control must sit above the sign-in form.');
 }
 public function test_animation_assets_match_the_supplied_release_and_contain_no_audio_or_network_code():void{
  $js=file_get_contents(public_path('assets/signin-atmosphere.js'));$css=file_get_contents(public_path('assets/signin-atmosphere.css'));
  $this->assertSame('6f1495ebbd4ce0652a7172cb1968fd20c2e467c8ea73773a84e02d832838a4a6',hash('sha256',$js));
  $this->assertSame('f0ef25ba349c5eb096453588cabec7889bf9a43a088225cf5b8c7d9639d2e235',hash('sha256',$css));
  foreach(['AudioContext','new Audio','fetch(','XMLHttpRequest','sendBeacon','http://','https://']as$needle)$this->assertStringNotContainsString($needle,$js,$needle);
  $this->assertStringContainsString('prefers-reduced-motion',$css);
 }
 public function test_other_pages_do_not_load_the_sign_in_animation():void{
  $this->get(route('privacy.public'))->assertOk()->assertDontSee('signin-atmosphere',false);
 }
}
