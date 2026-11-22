<?php

namespace Serverbackgrounds\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Serverbackgrounds\Models\Background;

class BackgroundController
{
    public function get(Server $server): JsonResponse
    {
        $bg = Background::where('server_id', $server->id)->first();
        return new JsonResponse(['url' => $bg?->image_url ?? '']);
    }

    public function set(Server $server, Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|url|max:500']);
        Background::updateOrCreate(['server_id' => $server->id], ['image_url' => $data['url']]);
        return new JsonResponse(['saved' => true]);
    }

    public function remove(Server $server): JsonResponse
    {
        Background::where('server_id', $server->id)->delete();
        return new JsonResponse(['removed' => true]);
    }
}
