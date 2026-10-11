<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;

class LeaveTypePolicy
{
    /**
     * Perform pre-authorization checks. Super admins can do anything.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view any leave types.
     *
     * Every member of a company may read its leave-type catalogue: employees
     * need it to populate the "Request Leave" type picker even though they
     * hold no `leave_type.view` ability. The controller scopes the listing to
     * the caller's own company.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('leave_type.view')
            || $user->company_id !== null;
    }

    /**
     * Determine whether the user can view the leave type.
     */
    public function view(User $user, LeaveType $leaveType): bool
    {
        if (! $this->belongsToCompany($user, $leaveType)) {
            return false;
        }

        return $user->can('leave_type.view')
            || $user->company_id !== null;
    }

    /**
     * Determine whether the user can create leave types.
     */
    public function create(User $user): bool
    {
        return $user->can('leave_type.create');
    }

    /**
     * Determine whether the user can update the leave type.
     */
    public function update(User $user, LeaveType $leaveType): bool
    {
        return $user->can('leave_type.edit') && $this->belongsToCompany($user, $leaveType);
    }

    /**
     * Determine whether the user can delete the leave type.
     */
    public function delete(User $user, LeaveType $leaveType): bool
    {
        return $user->can('leave_type.delete') && $this->belongsToCompany($user, $leaveType);
    }

    /**
     * Check that the user belongs to the leave type's company.
     */
    protected function belongsToCompany(User $user, LeaveType $leaveType): bool
    {
        return (int) $user->company_id === (int) $leaveType->company_id;
    }
}

