<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmployeeHints;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Підказки працівнику в боковому меню — див. EmployeeHints.
 */
class HintController extends Controller
{
    public function index(Request $request, EmployeeHints $hints): JsonResponse
    {
        $user = $request->user();

        // Адміністратор власних звітів і планів не веде.
        if ($user->isAdmin() || ! $user->employee) {
            return response()->json(['today' => null, 'reports' => [], 'plans' => []]);
        }

        return response()->json($hints->forUser($user, now()->toImmutable()));
    }
}
