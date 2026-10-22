<?php

declare(strict_types=1);

namespace App\Tenancy\ControlPlane;

use App\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TlsAskController
{
    public function __invoke(Request $request, TenantResolver $resolver): Response
    {
        // Caddy calls: /internal/tls-ask?domain=example.co.za
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);

        $domain = (string) $request->query('domain', '');

        return response('', $domain !== '' && $resolver->isCertifiableHost($domain) ? 200 : 403);
    }
}
