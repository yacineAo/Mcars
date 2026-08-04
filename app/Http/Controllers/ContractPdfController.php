<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the generated contract PDF through a policy-checked, short-lived signed URL
 * (ADR-009). Contract keeps `pdf_disk`/`pdf_path` directly rather than Media Library —
 * the exact bytes are legally significant — but the serving discipline mirrors
 * DocumentMediaController: a valid signature and `bookings.view` are both required, so
 * a leaked link expires and a stale session gains nothing.
 */
class ContractPdfController extends Controller
{
    public function download(Request $request, Contract $contract): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired link.');
        }

        if (! $request->user()?->can('bookings.view')) {
            abort(403);
        }

        if ($contract->pdf_path === null) {
            abort(404);
        }

        $disk = $contract->pdf_disk ?? 'private';

        if (! Storage::disk($disk)->exists($contract->pdf_path)) {
            abort(404);
        }

        return Storage::disk($disk)->download(
            $contract->pdf_path,
            $contract->contract_number.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
