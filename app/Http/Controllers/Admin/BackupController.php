<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DatabaseBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The admin database backup — the port of the legacy admin navbar
 * "Backup" popup (utility/backup.php full dump; utility/backup1.php
 * business-tables variant). See DatabaseBackup for the verbatim dump
 * semantics and the documented deviations.
 */
class BackupController extends Controller
{
    /** The backup screen (legacy popup content, inline page here). */
    public function index(): View
    {
        $tables = count(DB::select('SHOW TABLES'));

        return view('admin.backup', [
            'tables' => $tables,
            'filename' => DatabaseBackup::filename(),
        ]);
    }

    /** Stream the full-database dump (legacy utility/backup.php). */
    public function download(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => DatabaseBackup::stream(DatabaseBackup::fullDump()),
            DatabaseBackup::filename(),
            ['Content-Type' => 'application/sql']
        );
    }

    /** Stream the business-tables dump (legacy utility/backup1.php). */
    public function downloadBusiness(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => DatabaseBackup::stream(DatabaseBackup::businessDump()),
            DatabaseBackup::filename(),
            ['Content-Type' => 'application/sql']
        );
    }
}
