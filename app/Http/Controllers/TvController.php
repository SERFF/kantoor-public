<?php

namespace App\Http\Controllers;

use App\Models\TvScreen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TvController
{
    public function __construct(protected OfficeController $office) {}

    public function show(): View
    {
        return view('tv', ['config' => ['tv' => true] + $this->office->config()]);
    }

    public function screen(string $token): View
    {
        $this->screenFor($token);

        return view('tv', ['config' => array_merge($this->office->config(), [
            'tv' => true,
            'stateUrl' => route('tv.state', $token),
            'authUrl' => route('tv.auth', $token),
        ])]);
    }

    public function state(string $token): JsonResponse
    {
        $this->screenFor($token);

        return $this->office->state();
    }

    public function auth(Request $request, string $token): JsonResponse
    {
        $this->screenFor($token);

        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', Rule::in(['private-office'])],
        ]);

        $signature = hash_hmac(
            'sha256',
            $data['socket_id'].':'.$data['channel_name'],
            (string) config('broadcasting.connections.reverb.secret'),
        );

        return response()->json(['auth' => config('broadcasting.connections.reverb.key').':'.$signature]);
    }

    protected function screenFor(string $token): TvScreen
    {
        $screen = TvScreen::findByPlain($token);

        abort_unless($screen, 404);

        $screen->touchSeen();

        return $screen;
    }
}
