<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\FaceIdKioskPunchRequest;
use App\Services\FaceIdSqliteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FaceIdKioskController extends Controller
{
    public function __construct(
        private readonly FaceIdSqliteService $faceId
    ) {}

    public function show(string $token): View
    {
        if (! $this->faceId->isValidKioskToken($token)) {
            throw new NotFoundHttpException;
        }

        return view('face-id.kiosk', [
            'token' => $token,
            'punchUrl' => route('face-id.kiosk.punch', ['token' => $token]),
        ]);
    }

    public function punch(FaceIdKioskPunchRequest $request, string $token): JsonResponse
    {
        if (! $this->faceId->isValidKioskToken($token)) {
            throw new NotFoundHttpException;
        }

        $result = $this->faceId->processPunch($request->descriptor());

        return response()->json($result);
    }
}
