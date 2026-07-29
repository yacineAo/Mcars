<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PendingExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function download(Request $request, int $id): StreamedResponse
    {
        $export = PendingExport::query()->findOrFail($id);

        if ($export->user_id !== Auth::id() && ! Auth::user()->can('branches.view_all')) {
            abort(403, 'Unauthorized');
        }

        if (! $export->isCompleted() || $export->file_path === null) {
            abort(404, 'Export not ready or not found');
        }

        if (! Storage::disk('private')->exists($export->file_path)) {
            abort(404, 'Export file not found');
        }

        $export->markAsDownloaded();

        return Storage::disk('private')->download(
            $export->file_path,
            $export->file_name,
            ['Content-Type' => $export->format->mimeType()],
        );
    }
}
