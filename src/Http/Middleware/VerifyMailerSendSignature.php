<?php

declare(strict_types=1);

namespace RvWaarloos\RvMail\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RvWaarloos\RvMail\Webhooks\SignatureVerifier;
use Symfony\Component\HttpFoundation\Response;

final class VerifyMailerSendSignature
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // getContent() en niet $request->all(): de handtekening geldt over de
        // ruwe bytes, en decoderen-plus-hercoderen levert een andere string op.
        $valid = $this->verifier->verify(
            $request->getContent(),
            $request->header('Signature'),
        );

        if (! $valid) {
            // Kan misbruik zijn, maar vaker een gewijzigd secret na een
            // hersleuteling. Loggen zonder de payload: die kan ledengegevens
            // bevatten en we weten niet wie hem stuurde.
            Log::warning('rv-mail: webhook met ongeldige handtekening geweigerd', [
                'ip' => $request->ip(),
                'length' => strlen($request->getContent()),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }
}
