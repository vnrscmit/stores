<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EIndent\EIndentController;
use App\Http\Controllers\EIndent\IndentItemController;
use App\Http\Controllers\Issue\EIssueController;
use App\Http\Controllers\Issue\EIssueLineController;
use App\Http\Controllers\Masters\BinController;
use App\Http\Controllers\Masters\ClassificationController;
use App\Http\Controllers\Masters\ItemController;
use App\Http\Controllers\Masters\PartyController;
use App\Http\Controllers\Masters\SubBinController;
use App\Http\Controllers\Masters\WarehouseController;
use App\Http\Controllers\Viewer\ReportController;
use App\Support\IssueTypes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::redirect('/', '/login');

// Authentication ------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');

    // Legacy password reset: security question + answer
    Route::get('/forgot-password', [PasswordResetController::class, 'showQuestion'])->name('password.question');
    Route::post('/forgot-password', [PasswordResetController::class, 'verifyAnswer'])->middleware('throttle:5,1')->name('password.verify');
    Route::get('/reset-password', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.reset.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Role dashboards (legacy index1 / indexopr / indexindet / indexview parity) --
Route::middleware(['auth', 'fy'])->group(function () {
    Route::get('/admin', [DashboardController::class, 'admin'])
        ->middleware('role:admin')->name('admin.home');
    Route::get('/operator', [DashboardController::class, 'operator'])
        ->middleware('role:operator')->name('operator.home');
    Route::get('/eindent', [DashboardController::class, 'eindent'])
        ->middleware('role:eindent')->name('eindent.home');
    Route::get('/viewer', [DashboardController::class, 'viewer'])
        ->middleware('role:viewer')->name('viewer.home');
});

// Masters (Phase 9 slice) ----------------------------------------------------
Route::middleware(['auth', 'fy', 'can:manage-masters'])->prefix('masters')->name('masters.')->group(function () {
    foreach ([
        'warehouses' => WarehouseController::class,
        'bins' => BinController::class,
        'subbins' => SubBinController::class,
        'classifications' => ClassificationController::class,
        'items' => ItemController::class,
        'parties' => PartyController::class,
    ] as $prefix => $controller) {
        Route::get("/{$prefix}/export", [$controller, 'export'])->name("{$prefix}.export");
        Route::resource($prefix, $controller)->except(['show']);
    }
});

// Admin approval queue + actions sit outside the raiser gate -----------------
Route::middleware(['auth', 'fy', 'can:manage-masters'])->prefix('eindents')->name('eindents.')->group(function () {
    Route::get('/approvals', [EIndentController::class, 'approvals'])->name('approvals');
    Route::post('/{indent}/approve', [EIndentController::class, 'approve'])->whereNumber('indent')->name('approve');
    Route::post('/{indent}/reject', [EIndentController::class, 'reject'])->whereNumber('indent')->name('reject');
});

// e-Indent raise module (Phase 5 slice) --------------------------------------
Route::middleware(['auth', 'fy', 'can:raise-indents'])->prefix('eindents')->name('eindents.')->group(function () {
    Route::get('/raise', [EIndentController::class, 'raise'])->name('raise');
    Route::get('/', [EIndentController::class, 'index'])->name('index');
    Route::get('/{indent}/workspace', [EIndentController::class, 'workspace'])->whereNumber('indent')->name('workspace');
    Route::get('/{indent}', [EIndentController::class, 'show'])->whereNumber('indent')->name('show');
    Route::put('/{indent}/remarks', [EIndentController::class, 'updateRemarks'])->whereNumber('indent')->name('remarks');
    Route::post('/{indent}/submit', [EIndentController::class, 'submit'])->whereNumber('indent')->name('submit');
    Route::post('/{indent}/reopen', [EIndentController::class, 'reopen'])->whereNumber('indent')->name('reopen');

    // Draft item workspace (AJAX, legacy getuser_indentupdate family)
    Route::get('/classifications/{classification}/items', [IndentItemController::class, 'byClassification'])->name('items.index');
    Route::post('/items', [IndentItemController::class, 'store'])->name('items.store');
    Route::put('/items/{item}', [IndentItemController::class, 'update'])->name('items.update');
    Route::delete('/items/{item}', [IndentItemController::class, 'destroy'])->name('items.destroy');
});

// Issue against e-Indents (Phase 6 slice) -------------------------------------
// Legacy: add_issue_indents.php + the getuser_issue_eindent* AJAX family.
Route::middleware(['auth', 'fy', 'can:post-transactions'])->prefix('issues/eindents')->name('issues.eindents.')->group(function () {
    Route::get('/', [EIssueController::class, 'index'])->name('index');
    Route::get('/pending', [EIssueController::class, 'pending'])->name('pending');
    Route::get('/{indent}/workspace', [EIssueController::class, 'workspace'])->whereNumber('indent')->name('workspace');
    Route::post('/{indent}/lines', [EIssueController::class, 'saveLine'])->whereNumber('indent')->name('lines.save');
    Route::delete('/{indent}/lines', [EIssueController::class, 'deleteLine'])->whereNumber('indent')->name('lines.delete');
    Route::get('/{indent}/lines/{line}/availability', [EIssueLineController::class, 'availability'])
        ->whereNumber(['indent', 'line'])->name('lines.availability');
    Route::post('/{indent}/post', [EIssueController::class, 'post'])->whereNumber('indent')->name('post');
    Route::get('/print/{issue}', [EIssueController::class, 'show'])->whereNumber('issue')->name('show');
});

// Self-contained issue types (Phase 7 slice): physical indent, stock transfer,
// MRTV. Legacy: add_issu_physical_indent.php / add_issue_str_view.php /
// add_issue_mrtv_view.php + the getuser_issue_* AJAX families.
use App\Http\Controllers\Issue\CaptiveController;
use App\Http\Controllers\Issue\IssueAvailabilityController;
use App\Http\Controllers\Issue\IssueController;

foreach (IssueTypes::all() as $issueType) {
    // The type lives in the literal URL prefix; parameter-less routes pass it
    // through via ->defaults. Model-bound actions (lines, workspace, post,
    // print) receive no defaults — a route default would shift Laravel's
    // positional parameter filling — and validate the type via the bound
    // model instead (the issue/line carries its own issue_type).
    Route::middleware(['auth', 'fy', 'can:post-transactions'])
        ->prefix("issues/{$issueType}")
        ->name("issues.{$issueType}.")
        ->group(function () use ($issueType) {
            Route::get('/', [IssueController::class, 'index'])->name('index')->defaults('type', $issueType);
            Route::get('/new', [IssueController::class, 'create'])->name('create')->defaults('type', $issueType);
            Route::get('/availability/{classification}/{item}', [IssueAvailabilityController::class, 'availability'])
                ->whereNumber(['classification', 'item'])->name('availability');
            Route::post('/lines', [IssueController::class, 'storeLine'])->name('lines.store')->defaults('type', $issueType);
            Route::put('/lines/{line}/update', [IssueController::class, 'updateLine'])->whereNumber('line')->name('lines.update');
            Route::delete('/lines/{line}', [IssueController::class, 'deleteLine'])->whereNumber('line')->name('lines.delete');
            Route::get('/workspace/{issue}', [IssueController::class, 'workspace'])->whereNumber('issue')->name('workspace');
            Route::put('/workspace/{issue}/header', [IssueController::class, 'updateHeader'])->whereNumber('issue')->name('header.update');
            Route::post('/workspace/{issue}/post', [IssueController::class, 'post'])->whereNumber('issue')->name('post');
            Route::get('/print/{issue}', [IssueController::class, 'show'])->whereNumber('issue')->name('show');
        });
}

// Captive consumption (internal CC — Phase 7 slice). Legacy:
// add_internalcc.php + getuser_capetdupdate.php + add_cc_preview.php.
Route::middleware(['auth', 'fy', 'can:post-transactions'])->prefix('issues/cc')->name('issues.cc.')->group(function () {
    Route::get('/', [CaptiveController::class, 'index'])->name('index');
    Route::get('/new', [CaptiveController::class, 'create'])->name('create');
    Route::get('/availability/{classification}/{item}', [IssueAvailabilityController::class, 'availability'])
        ->whereNumber(['classification', 'item'])->name('availability');
    Route::post('/lines', [CaptiveController::class, 'storeLine'])->name('lines.store');
    Route::put('/lines/{line}/update', [CaptiveController::class, 'updateLine'])->whereNumber('line')->name('lines.update');
    Route::delete('/lines/{line}', [CaptiveController::class, 'deleteLine'])->whereNumber('line')->name('lines.delete');
    Route::get('/workspace/{captive}', [CaptiveController::class, 'workspace'])->whereNumber('captive')->name('workspace');
    Route::put('/workspace/{captive}/header', [CaptiveController::class, 'updateHeader'])->whereNumber('captive')->name('header.update');
    Route::post('/workspace/{captive}/post', [CaptiveController::class, 'post'])->whereNumber('captive')->name('post');
    Route::get('/print/{captive}', [CaptiveController::class, 'show'])->whereNumber('captive')->name('show');
});

// Viewer reports (Phase 3 slice) ---------------------------------------------
Route::middleware(['auth', 'fy', 'role:viewer,admin'])->prefix('viewer/reports')->name('viewer.reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');

    Route::get('/stock-on-hand', [ReportController::class, 'stockOnHand'])->name('stock-on-hand');
    Route::get('/stock-on-hand/export', [ReportController::class, 'stockOnHandExport'])->name('stock-on-hand.export');

    Route::get('/item-ledger', [ReportController::class, 'itemLedger'])->name('item-ledger');
    Route::get('/item-ledger/export', [ReportController::class, 'itemLedgerExport'])->name('item-ledger.export');

    Route::get('/stock-transfer', [ReportController::class, 'stockTransfer'])->name('stock-transfer');
    Route::get('/stock-transfer/export', [ReportController::class, 'stockTransferExport'])->name('stock-transfer.export');
});
