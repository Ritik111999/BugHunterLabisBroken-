<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class AdminAccountDeletionController extends Controller
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletionService
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', '');

        $requests = AccountDeletionRequest::query()
            ->with(['user', 'resolver'])
            ->when(in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true), function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.manage.account-deletion-requests.index', [
            'title' => 'Account deletion requests',
            'requests' => $requests,
            'filterStatus' => $status,
        ]);
    }

    public function approve(AccountDeletionRequest $account_deletion_request): RedirectResponse
    {
        if ($this->targetsCurrentAdmin($account_deletion_request)) {
            return redirect()->route('admin.account-deletion-requests.index')->with('status', 'You cannot process a deletion request for your own account.');
        }

        try {
            $this->accountDeletionService->approve($account_deletion_request, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.account-deletion-requests.index')->with('status', $e->getMessage());
        }

        return redirect()->route('admin.account-deletion-requests.index')->with('status', 'Request approved and user account removed.');
    }

    public function reject(AccountDeletionRequest $account_deletion_request): RedirectResponse
    {
        try {
            $this->accountDeletionService->reject($account_deletion_request, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.account-deletion-requests.index')->with('status', $e->getMessage());
        }

        return redirect()->route('admin.account-deletion-requests.index')->with('status', 'Request rejected.');
    }

    public function deleteUser(AccountDeletionRequest $account_deletion_request): RedirectResponse
    {
        if ($this->targetsCurrentAdmin($account_deletion_request)) {
            return redirect()->route('admin.account-deletion-requests.index')->with('status', 'You cannot process a deletion request for your own account.');
        }

        try {
            $this->accountDeletionService->deleteUserForRequest($account_deletion_request, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.account-deletion-requests.index')->with('status', $e->getMessage());
        }

        return redirect()->route('admin.account-deletion-requests.index')->with('status', 'User account deletion executed.');
    }

    private function targetsCurrentAdmin(AccountDeletionRequest $account_deletion_request): bool
    {
        $admin = auth()->user();
        if (!$admin instanceof User) {
            return false;
        }

        if ($account_deletion_request->user_id && (int) $account_deletion_request->user_id === (int) $admin->id) {
            return true;
        }

        return strtolower((string) $account_deletion_request->email) === strtolower((string) $admin->email);
    }
}
