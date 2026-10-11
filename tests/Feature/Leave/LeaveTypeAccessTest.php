<?php

namespace Tests\Feature\Leave;

use App\Models\Company;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the "Request Leave" type-picker gap (mobile spec G2): employees hold
 * no `leave_type.view` ability but must still read their company's active
 * leave-type catalogue. Write access stays permission-gated.
 */
class LeaveTypeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['leave_type.view', 'leave_type.create', 'leave_type.edit', 'leave_type.delete'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions(Permission::all());

        $companyAdmin = Role::findOrCreate('company_admin', 'web');
        $companyAdmin->syncPermissions(['leave_type.view', 'leave_type.create', 'leave_type.edit', 'leave_type.delete']);

        // Employee intentionally has NO leave_type.* permission.
        Role::findOrCreate('employee', 'web');
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsCompanyAdmin(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('company_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsEmployee(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('employee');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_access_leave_types(): void
    {
        $this->getJson('/api/v1/leave-types')->assertUnauthorized();
    }

    public function test_employee_can_list_leave_types_for_own_company(): void
    {
        $company = Company::factory()->create();
        LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Annual Leave', 'code' => 'ANN']);
        LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Sick Leave', 'code' => 'SIC']);
        LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Unpaid Leave', 'code' => 'UNP']);

        $this->actingAsEmployee($company);

        $this->getJson('/api/v1/leave-types?status=active&per_page=100')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.data');
    }

    public function test_employee_listing_is_scoped_to_own_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        // Explicit names avoid the factory's small `unique()` name pool.
        LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Own Annual', 'code' => 'OWN']);
        LeaveType::factory()->create(['company_id' => $company->id, 'name' => 'Own Sick', 'code' => 'OWS']);
        LeaveType::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Other Annual', 'code' => 'OTA']);
        LeaveType::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Other Sick', 'code' => 'OTS']);

        $this->actingAsEmployee($company);

        $this->getJson('/api/v1/leave-types?per_page=100')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_employee_cannot_see_leave_type_of_another_company(): void
    {
        $company = Company::factory()->create();
        $foreignType = LeaveType::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAsEmployee($company);

        $this->getJson("/api/v1/leave-types/{$foreignType->id}")->assertForbidden();
    }

    public function test_employee_cannot_create_leave_type(): void
    {
        $company = Company::factory()->create();
        $this->actingAsEmployee($company);

        $this->postJson('/api/v1/leave-types', [
            'name' => 'Sick Leave',
            'code' => 'SICK',
        ])->assertForbidden();
    }

    public function test_employee_cannot_update_leave_type(): void
    {
        $company = Company::factory()->create();
        $leaveType = LeaveType::factory()->create(['company_id' => $company->id]);
        $this->actingAsEmployee($company);

        $this->putJson("/api/v1/leave-types/{$leaveType->id}", [
            'name' => 'Renamed',
        ])->assertForbidden();
    }

    public function test_employee_cannot_delete_leave_type(): void
    {
        $company = Company::factory()->create();
        $leaveType = LeaveType::factory()->create(['company_id' => $company->id]);
        $this->actingAsEmployee($company);

        $this->deleteJson("/api/v1/leave-types/{$leaveType->id}")->assertForbidden();
    }

    public function test_company_admin_can_still_manage_leave_types(): void
    {
        $company = Company::factory()->create();
        LeaveType::factory()->create(['company_id' => $company->id]);
        $this->actingAsCompanyAdmin($company);

        $this->getJson('/api/v1/leave-types?per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->postJson('/api/v1/leave-types', [
            'name' => 'Annual Leave',
            'code' => 'ANNUAL',
        ])->assertCreated();
    }
}
