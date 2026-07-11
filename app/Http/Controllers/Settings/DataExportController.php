<?php

namespace App\Http\Controllers\Settings;

use App\Data\DataExportData;
use App\Enums\DataExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ExportUserData;
use App\Models\DataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataExportController extends Controller
{
    /**
     * Show the data & privacy settings page, carrying the user's latest export.
     */
    public function edit(Request $request): Response
    {
        $latestExport = $request->user()->dataExports()->first();

        return Inertia::render('settings/DataPrivacy', [
            'dataExport' => $latestExport === null ? null : DataExportData::fromExport($latestExport),
        ]);
    }

    /**
     * Queue a fresh export of the authenticated user's personal data.
     */
    public function store(Request $request): RedirectResponse
    {
        $export = $request->user()->dataExports()->create([
            'status' => DataExportStatus::Pending,
        ]);

        ExportUserData::dispatch($export->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __("Preparing your data export. We'll email you when it's ready.")]);

        return back();
    }

    /**
     * Download a ready export archive.
     *
     * Guards ownership and the download window: another user's export is
     * forbidden, and one that is still pending, failed, or expired is a 404.
     */
    public function download(Request $request, DataExport $dataExport): StreamedResponse
    {
        abort_unless($dataExport->user_id === $request->user()->id, 403);
        abort_unless($dataExport->isReady() && ! $dataExport->isExpired(), 404);

        return Storage::disk(ExportUserData::DISK)->download($dataExport->path, 'data-export.zip');
    }
}
