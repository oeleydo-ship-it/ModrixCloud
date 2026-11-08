<?php

/*
| Store license API, under /api/marketplace/v1 (see the spec's contract table).
*/

use Illuminate\Support\Facades\Route;
use Marketplace\Http\Controllers\Api\CatalogController;
use Marketplace\Http\Controllers\Api\ConnectionController;
use Marketplace\Http\Controllers\Api\CoreReleaseController;
use Marketplace\Http\Controllers\Api\DownloadController;
use Marketplace\Http\Controllers\Api\LicenseController;
use Marketplace\Http\Controllers\Api\UpdateController;

Route::prefix('marketplace/v1')->middleware('throttle:60,1')->name('api.')->group(function () {
    Route::get('catalog', CatalogController::class)->name('catalog');
    Route::post('licenses/activate', [LicenseController::class, 'activate'])->name('licenses.activate');
    Route::post('licenses/check', [LicenseController::class, 'check'])->name('licenses.check');
    Route::post('licenses/deactivate', [LicenseController::class, 'deactivate'])->name('licenses.deactivate');
    Route::post('updates', UpdateController::class)->name('updates');
    Route::post('downloads', [DownloadController::class, 'request'])->name('downloads');
    Route::get('download/{token}', [DownloadController::class, 'download'])->where('token', '[A-Za-z0-9-]+')->name('download');

    // Core platform updates: the list is public, downloads need a connected store.
    Route::get('core/releases', [CoreReleaseController::class, 'index'])->name('core.releases');
    Route::post('core/downloads', [CoreReleaseController::class, 'request'])->middleware('throttle:20,1')->name('core.downloads');
    Route::get('core/download/{token}', [CoreReleaseController::class, 'download'])->where('token', '[A-Za-z0-9-]+')->name('core.download');

    // Connected stores (Modrix Cloud Connector): bearer token from the pairing flow.
    Route::post('connections/token', [ConnectionController::class, 'token'])->middleware('throttle:20,1')->name('connections.token');
    Route::get('connections/me', [ConnectionController::class, 'me'])->name('connections.me');
    Route::get('connections/licenses', [ConnectionController::class, 'licenses'])->name('connections.licenses');
    Route::post('connections/licenses/claim', [ConnectionController::class, 'claim'])->middleware('throttle:30,1')->name('connections.claim');
    Route::post('connections/licenses/release', [ConnectionController::class, 'release'])->middleware('throttle:20,1')->name('connections.release');
    Route::delete('connections/current', [ConnectionController::class, 'revoke'])->name('connections.revoke');
});
