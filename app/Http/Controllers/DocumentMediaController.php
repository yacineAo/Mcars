<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CarDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves private-disk document scans through a policy-checked, short-lived signed URL
 * (ADR-009). The caller must have `fleet.view` *and* hold a valid signature, so a leaked
 * link expires in 5 minutes and a stale-session screenshot gains nothing.
 */
class DocumentMediaController extends Controller
{
    public function download(Request $request, CarDocument $carDocument): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired link.');
        }

        if (! $request->user()?->can('fleet.view')) {
            abort(403);
        }

        $media = $carDocument->getFirstMedia('document');

        if (! $media) {
            abort(404);
        }

        return $media->toResponse($request);
    }
}
