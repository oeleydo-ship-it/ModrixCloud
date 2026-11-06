<?php

namespace Marketplace\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Marketplace\Models\StoreConnection;
use Marketplace\Services\Connections;

/**
 * /connect: a signed-in customer approves linking a store to their account
 * (Modrix Cloud Connector). The store is sent back with a one-time code; it
 * only becomes a connection when the store proves its PKCE verifier.
 */
class ConnectController
{
    public function __construct(private readonly Connections $connections) {}

    public function show(Request $request): View
    {
        $pairing = $this->pairing($request);

        return view('connect.show', ['request' => $pairing, 'refusal' => $this->connections->refusal($request->user(), $pairing['instance_id'], $pairing['domain'])]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $pairing = $this->pairing($request);
        $refusal = $this->connections->refusal($request->user(), $pairing['instance_id'], $pairing['domain']);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['store' => $refusal]);
        }
        $code = $this->connections->issueCode($request->user(), $pairing['instance_id'], $pairing['store_name'], $pairing['domain'], $pairing['code_challenge']);

        return redirect()->away($this->back($pairing['return_url'], ['code' => $code, 'state' => $pairing['state']]));
    }

    public function deny(Request $request): RedirectResponse
    {
        $pairing = $this->pairing($request);

        return redirect()->away($this->back($pairing['return_url'], ['error' => 'access_denied', 'state' => $pairing['state']]));
    }

    /** Account → Connected stores → Disconnect. */
    public function revoke(Request $request, StoreConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 403);
        $this->connections->revoke($connection);

        return redirect('/account')->with('notice', "{$connection->store_name} was disconnected from your account.");
    }

    /**
     * @return array{instance_id: string, store_name: string, domain: string, return_url: string, state: string, code_challenge: string}
     */
    private function pairing(Request $request): array
    {
        $data = $request->validate([
            'instance_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9-]{8,128}$/'],
            'store_name' => ['required', 'string', 'max:160'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+(:\d{1,5})?$/i'],
            'return_url' => ['required', 'string', 'max:2000', 'url'],
            'state' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'code_challenge' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]+$/'],
            'code_challenge_method' => ['required', 'in:S256'],
        ]);

        if (! Connections::returnUrlMatches($data['return_url'], $data['domain'])) {
            throw ValidationException::withMessages(['return_url' => 'The store asked to return to an address outside its own domain, so this request was refused.']);
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function back(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
