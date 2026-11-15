<?php
namespace App\Http\Middleware;
use App\Support\{PortalPrivacy,LearningAccess};
use App\Models\PortalPrivacyState;
use Illuminate\Http\Request;
use Closure;
class RequirePrivacyAcknowledgement {
 public function handle(Request $r,Closure $next){
  if($r->routeIs('portal-privacy.*','logout')){return $next($r);}
  if($r->routeIs('portal-privacy-admin.*')){abort_unless(LearningAccess::manager($r->user()),403);return $next($r);}
  $s=PortalPrivacyState::findOrFail(1);abort_unless(PortalPrivacy::intact($s),503,'Privacy controls require authorised recovery.');$n=PortalPrivacy::current($s);$case=PortalPrivacy::open($r->user());$alternative=$case&&PortalPrivacy::alternative($case,$n);
  if($case&&!$alternative||$s->enabled&&!$alternative&&(!$n||!PortalPrivacy::acknowledged($r->user(),$n))){if($r->expectsJson()){return response()->json(['message'=>'Review the current privacy notice or your concern.','privacy_url'=>route('portal-privacy.form')],409);}return to_route('portal-privacy.form');}
  return $next($r);
 }
}
