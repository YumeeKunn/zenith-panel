<?php

namespace Serverbackgrounds\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Serverbackgrounds\Models\Background;

class AdminBackgroundController
{
    /** GET — all configured backgrounds (admin). */
    public function index(): JsonResponse
    {
        $rows = Background::all()->map(fn (Background $b) => [
            'server_id' => $b->server_id,
            'url' => $b->image_url,
            'server_name' => optional(Server::find($b->server_id))->name,
        ]);
        return new JsonResponse(['data' => $rows]);
    }

    /** POST — set background for a server (admin). */
    public function set(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => 'required|integer|exists:servers,id',
            'url' => 'required|url|max:500',
        ]);
        Background::updateOrCreate(['server_id' => $data['server_id']], ['image_url' => $data['url']]);
        return new JsonResponse(['saved' => true]);
    }

    /** DELETE — remove background (admin). */
    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate(['server_id' => 'required|integer']);
        Background::where('server_id', $data['server_id'])->delete();
        return new JsonResponse(['removed' => true]);
    }
}
