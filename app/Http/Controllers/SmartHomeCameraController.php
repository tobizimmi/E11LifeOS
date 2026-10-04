<?php

namespace App\Http\Controllers;

use App\Models\SmartHomeEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Camera pictures for the dashboards: fetched from Home Assistant on the server, so the token
 * never reaches the browser. Only cameras the household picked.
 */
class SmartHomeCameraController extends Controller
{
    public function __invoke(Request $request, SmartHomeEntity $entity): Response
    {
        abort_unless($entity->household_id === $request->user()->currentHousehold()?->id && $entity->domain() === 'camera', 404);

        try {
            $image = $entity->connection->homeAssistant()->cameraImage($entity->entity_id);
        } catch (Throwable) {
            abort(502);
        }

        return response($image, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }
}
