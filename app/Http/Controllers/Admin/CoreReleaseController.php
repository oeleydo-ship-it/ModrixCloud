<?php

namespace Marketplace\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Marketplace\Models\CoreRelease;
use Marketplace\Services\CoreReleaseException;
use Marketplace\Services\CoreReleases;

/** Core platform releases: upload a package, publish it, withdraw it. */
class CoreReleaseController
{
    public function index(): View
    {
        return view('admin.core', ['releases' => CoreRelease::query()->latest('id')->get()]);
    }

    public function store(Request $request, CoreReleases $releases): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'min_php' => ['required', 'string', 'max:16'],
            'package' => ['required', 'file', 'mimes:zip', 'max:'.((int) config('marketplace.core_max_mb', 200) * 1024)],
        ]);

        try {
            $release = $releases->upload($request->file('package')->getRealPath(), trim($data['version']), $data['notes'] ?? null, trim($data['min_php']));
        } catch (CoreReleaseException $e) {
            return back()->withInput()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect('/admin/core')->with('notice', "Core release {$release->version} uploaded as a draft.");
    }

    public function publish(CoreRelease $release, CoreReleases $releases): RedirectResponse
    {
        try {
            $releases->publish($release);
        } catch (CoreReleaseException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect('/admin/core')->with('notice', "Core release {$release->version} published.");
    }

    public function withdraw(CoreRelease $release, CoreReleases $releases): RedirectResponse
    {
        try {
            $releases->withdraw($release);
        } catch (CoreReleaseException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect('/admin/core')->with('notice', "Core release {$release->version} withdrawn.");
    }
}
