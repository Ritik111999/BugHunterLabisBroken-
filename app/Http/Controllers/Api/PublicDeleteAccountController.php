<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePublicDeleteAccountRequest;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PublicDeleteAccountController extends Controller
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletionService
    ) {}

    public function store(StorePublicDeleteAccountRequest $request): JsonResponse
    {
        try {
            $this->accountDeletionService->createWebRequest($request->validated());
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your account deletion request has been submitted successfully.',
        ], 201);
    }
}
