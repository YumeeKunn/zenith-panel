<?php

namespace Simplefavicons\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaviconController
{
    public function get(): JsonResponse
    {
        $store = \Simplefavicons\SimplefaviconsProvider::store();
        return new JsonResponse(['url' => $store?->get('url') ?? '']);
    }

    public function set(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|url|max:500']);
        \Simplefavicons\SimplefaviconsProvider::store()?->set('url', $data['url']);
        return new JsonResponse(['saved' => true]);
    }
}
