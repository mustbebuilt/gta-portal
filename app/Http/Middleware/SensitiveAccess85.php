<?php

namespace App\Http\Middleware;

use App\Support\SecurityAudit85;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class SensitiveAccess85
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $name = $request->route()?->getName() ?? '';
        if ($request->user() && $request->isMethod('GET') && $response->getStatusCode() === 200
            && (str_starts_with($name, 'support.') || str_starts_with($name, 'support-register')
                || preg_match('/(?:evidence|download|export)/i', $name))) {
            $records = [];
            foreach ($request->route()->parameters() as $key => $value) {
                if ($value instanceof Model) {
                    $records[$key] = (string) $value->getKey();
                } elseif (is_scalar($value) && ctype_digit((string) $value)) {
                    $records[$key] = (string) $value;
                }
            }
            SecurityAudit85::record($request, 'sensitive_access', ['route' => $name, 'records' => $records]);
        }

        return $response;
    }
}
