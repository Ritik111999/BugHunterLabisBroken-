<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAppAccountDeletionRequest;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class AccountDeletionController extends Controller
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletionService
    ) {}

    public function store(StoreAppAccountDeletionRequest $request): JsonResponse
    {
        try {
            $this->accountDeletionService->createAppRequest(
                $request->user(),
                $request->validated('reason')
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your account deletion request has been submitted.',
        ], 201);
    }

    public function status(): JsonResponse
    {
        $payload = $this->accountDeletionService->statusPayloadForUser(request()->user());

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $payload['status'],
                'requested_at' => $payload['requested_at'],
                'source' => $payload['source'],
            ],
            'message' => $payload['message'],
        ]);
    }

    public function cancel(): JsonResponse
    {
        $cancelled = $this->accountDeletionService->cancelPendingForUser(request()->user());

        if (!$cancelled) {
            return response()->json([
                'success' => false,
                'message' => 'No pending account deletion request to cancel.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your account deletion request has been cancelled.',
        ]);
    }
}
