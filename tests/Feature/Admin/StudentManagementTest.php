<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Pages\StudentManagement;
use App\Models\Faculty;
use App\Models\User;
use App\Notifications\StudentInvitationNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@campusengage.hu',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);
        $admin->assignRole(UserRole::Admin);

        return $admin;
    }

    private function makeStudent(string $status = 'active', string $neptunCode = 'STU001'): User
    {
        $student = User::create([
            'name' => 'Test Student',
            'email' => "student.{$neptunCode}@student.pte.hu",
            'neptun_code' => $neptunCode,
            'major' => 'Computer Science BSc',
            'year_of_study' => 2,
            'password' => 'secret_hash',
            'status' => $status,
            'activation_token' => $status === 'invited' ? str_repeat('b', 64) : null,
            'activation_token_expires_at' => $status === 'invited' ? now()->addHours(24) : null,
        ]);
        $student->assignRole(UserRole::Student);

        return $student;
    }

    // ─── UC-3.2.1: Listing ────────────────────────────────────────────────────

    public function test_admin_can_view_student_management_page_and_table(): void
    {
        $admin = $this->makeAdmin();

        $faculty = Faculty::create([
            'name' => 'Faculty of Engineering and Information Technology',
            'code' => 'MIK',
        ]);

        $student = User::create([
            'name' => 'Existing Student',
            'email' => 'student.existing@mik.pte.hu',
            'neptun_code' => 'EXM123',
            'major' => 'Computer Science BSc',
            'year_of_study' => 2,
            'password' => 'secret_hash',
            'status' => 'invited',
            'faculty_id' => $faculty->id,
        ]);
        $student->assignRole(UserRole::Student);

        $this->actingAs($admin)
            ->get('/admin/students')
            ->assertOk();

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->assertCanSeeTableRecords([$student])
            ->assertCanNotSeeTableRecords([$admin])
            ->assertSeeHtml('Existing Student')
            ->assertSeeHtml('EXM123')
            ->assertSeeHtml('student.existing@mik.pte.hu')
            ->assertSeeHtml('Computer Science BSc')
            ->assertSeeHtml('Faculty of Engineering and Information Technology');
    }

    public function test_admin_can_invite_new_student_individually_per_uc_3_2_1(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();

        $faculty = Faculty::create([
            'name' => 'Faculty of Sciences',
            'code' => 'TTK',
        ]);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callAction('invite', [
                'name' => 'Kovács János',
                'email' => 'kovacs.janos@student.pte.hu',
                'neptun_code' => 'kov123',
                'major' => 'Computer Science BSc',
                'year_of_study' => 2,
                'faculty_id' => $faculty->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('users', [
            'name' => 'Kovács János',
            'email' => 'kovacs.janos@student.pte.hu',
            'neptun_code' => 'KOV123',
            'major' => 'Computer Science BSc',
            'year_of_study' => 2,
            'status' => 'invited',
            'must_change_password' => 1,
            'faculty_id' => $faculty->id,
        ]);

        $user = User::where('email', 'kovacs.janos@student.pte.hu')->firstOrFail();
        $this->assertNotNull($user->activation_token);
        $this->assertSame(64, strlen($user->activation_token));
        $this->assertTrue($user->hasValidActivationToken());
        $this->assertTrue($user->hasRole(UserRole::Student));

        // Verify token expiration is within 24 hours per UC-3.2.1
        $this->assertTrue($user->activation_token_expires_at->gt(now()->addHours(23)));
        $this->assertTrue($user->activation_token_expires_at->lte(now()->addHours(25)));

        Notification::assertSentTo(
            $user,
            StudentInvitationNotification::class,
            function (StudentInvitationNotification $notification) use ($user) {
                return $notification->activationToken === $user->activation_token
                    && $notification->expiresInHours === 24;
            }
        );
    }

    public function test_student_invitation_requires_mandatory_fields(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callAction('invite', [
                'name' => '',
                'email' => 'invalid-email',
                'neptun_code' => '',
                'major' => '',
                'year_of_study' => null,
                'faculty_id' => null,
            ])
            ->assertHasActionErrors([
                'name' => 'required',
                'email' => 'email',
                'neptun_code' => 'required',
                'major' => 'required',
                'year_of_study' => 'required',
            ]);
    }

    public function test_student_invitation_rejects_duplicate_email_with_spec_message(): void
    {
        $admin = $this->makeAdmin();

        User::create([
            'name' => 'Existing Student',
            'email' => 'already.registered@student.pte.hu',
            'neptun_code' => 'OLD001',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callAction('invite', [
                'name' => 'Duplicate Email Attempt',
                'email' => 'already.registered@student.pte.hu',
                'neptun_code' => 'NEW002',
                'major' => 'Biology BSc',
                'year_of_study' => 1,
                'faculty_id' => null,
            ])
            ->assertHasActionErrors([
                'email' => 'This email address is already registered in the system.',
            ]);
    }

    public function test_student_invitation_rejects_duplicate_neptun_code_with_spec_message(): void
    {
        $admin = $this->makeAdmin();

        User::create([
            'name' => 'Existing Student',
            'email' => 'student1@student.pte.hu',
            'neptun_code' => 'DUP123',
            'password' => 'secret_hash',
            'status' => 'active',
        ]);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callAction('invite', [
                'name' => 'Duplicate Neptun Attempt',
                'email' => 'student2@student.pte.hu',
                'neptun_code' => 'DUP123',
                'major' => 'Physics BSc',
                'year_of_study' => 3,
                'faculty_id' => null,
            ])
            ->assertHasActionErrors([
                'neptun_code' => 'This Neptun code is already registered in the system.',
            ]);
    }

    public function test_student_invitation_validates_neptun_code_format(): void
    {
        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callAction('invite', [
                'name' => 'Invalid Neptun Format',
                'email' => 'student@student.pte.hu',
                'neptun_code' => 'INVALID!',
                'major' => 'Math BSc',
                'year_of_study' => 1,
                'faculty_id' => null,
            ])
            ->assertHasActionErrors(['neptun_code']);
    }

    public function test_student_invitation_can_be_sent_without_faculty(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callAction('invite', [
                'name' => 'Faculty-less Student',
                'email' => 'nofaculty.student@pte.hu',
                'neptun_code' => 'NOF123',
                'major' => 'General Studies',
                'year_of_study' => 1,
                'faculty_id' => null,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'nofaculty.student@pte.hu',
            'neptun_code' => 'NOF123',
            'faculty_id' => null,
            'status' => 'invited',
        ]);
    }

    public function test_student_invitation_notification_renders_proper_mail_content(): void
    {
        $user = new User([
            'name' => 'Nagy Anna',
            'email' => 'nagy.anna@student.pte.hu',
            'neptun_code' => 'NAGY01',
        ]);

        $notification = new StudentInvitationNotification('test-token-student-123', 24);
        $mail = $notification->toMail($user);

        $this->assertSame('Student Invitation to Gamified University Engagement Platform', $mail->subject);
        $this->assertStringContainsString('Nagy Anna', $mail->greeting);
        $this->assertStringContainsString('NAGY01', $mail->render());
        $this->assertStringContainsString('/auth/activate/test-token-student-123', $mail->actionUrl);
        $this->assertStringContainsString('24 hours', $mail->render());
    }

    // ─── UC-3.2.2: Deactivation ───────────────────────────────────────────────

    public function test_admin_can_deactivate_active_student_per_uc_3_2_2(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent('active', 'ACT001');

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('deactivate', $student)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'status' => 'inactive',
            'activation_token' => null,
            'activation_token_expires_at' => null,
        ]);
    }

    public function test_admin_can_deactivate_invited_student_and_revokes_token(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent('invited', 'INV001');

        // Confirm token exists before deactivation.
        $this->assertNotNull($student->fresh()->activation_token);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('deactivate', $student)
            ->assertHasNoTableActionErrors();

        $fresh = $student->fresh();
        $this->assertSame('inactive', $fresh->status);
        $this->assertNull($fresh->activation_token);
        $this->assertNull($fresh->activation_token_expires_at);
    }

    public function test_deactivate_action_is_hidden_for_already_inactive_student(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent('inactive', 'INA001');

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->assertTableActionHidden('deactivate', $student);
    }

    public function test_admin_can_reactivate_inactive_student_per_uc_3_2_2(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent('inactive', 'INA002');

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('reactivate', $student)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'status' => 'active',
        ]);
    }

    public function test_reactivate_action_is_hidden_for_active_student(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent('active', 'ACT002');

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->assertTableActionHidden('reactivate', $student);
    }

    public function test_reactivate_action_is_hidden_for_invited_student(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent('invited', 'INV002');

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->assertTableActionHidden('reactivate', $student);
    }

    public function test_admin_cannot_deactivate_their_own_account_if_they_appear_in_student_list(): void
    {
        $admin = $this->makeAdmin();
        $admin->update(['neptun_code' => 'ADM999']);
        $admin->assignRole(UserRole::Student);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->assertTableActionDisabled('deactivate', $admin);
    }

    // ─── UC-3.2.2: Resend Invitation ──────────────────────────────────────────

    public function test_admin_can_resend_invitation_to_invited_student_per_uc_3_2_2(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin();
        $student = $this->makeStudent('invited', 'INV003');
        $oldToken = $student->activation_token;

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('resend_invitation', $student)
            ->assertHasNoTableActionErrors();

        $fresh = $student->fresh();
        $this->assertNotNull($fresh->activation_token);
        $this->assertNotSame($oldToken, $fresh->activation_token);
        $this->assertSame(64, strlen($fresh->activation_token));
        $this->assertTrue($fresh->activation_token_expires_at->isFuture());

        Notification::assertSentTo(
            $fresh,
            StudentInvitationNotification::class,
            function (StudentInvitationNotification $notification) use ($fresh) {
                return $notification->activationToken === $fresh->activation_token
                    && $notification->expiresInHours === 24;
            }
        );
    }

    public function test_admin_can_edit_student_per_uc_3_2_2(): void
    {
        $admin = $this->makeAdmin();
        $faculty1 = Faculty::create(['name' => 'Faculty One', 'code' => 'F1']);
        $faculty2 = Faculty::create(['name' => 'Faculty Two', 'code' => 'F2']);
        $student = $this->makeStudent('active', 'OLD123');
        $student->update(['faculty_id' => $faculty1->id]);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('edit', $student, [
                'name' => 'Updated Student Name',
                'email' => 'updated.student@pte.hu',
                'neptun_code' => 'new456',
                'major' => 'Software Engineering MSc',
                'year_of_study' => 3,
                'faculty_id' => $faculty2->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Updated Student Name',
            'email' => 'updated.student@pte.hu',
            'neptun_code' => 'NEW456',
            'major' => 'Software Engineering MSc',
            'year_of_study' => 3,
            'faculty_id' => $faculty2->id,
        ]);
    }

    public function test_student_edit_rejects_duplicate_email(): void
    {
        $admin = $this->makeAdmin();
        $student1 = $this->makeStudent('active', 'STU001');
        $student2 = User::create([
            'name' => 'Other Student',
            'email' => 'existing.stu@pte.hu',
            'neptun_code' => 'STU002',
            'major' => 'Biology',
            'year_of_study' => 1,
            'password' => 'secret_hash',
            'status' => 'active',
        ]);
        $student2->assignRole(UserRole::Student);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('edit', $student1, [
                'name' => 'Attempt Duplicate Email',
                'email' => 'existing.stu@pte.hu',
                'neptun_code' => 'UNI123',
                'major' => 'Math',
                'year_of_study' => 1,
                'faculty_id' => null,
            ])
            ->assertHasTableActionErrors(['email' => 'unique']);
    }

    public function test_student_edit_rejects_duplicate_neptun_code(): void
    {
        $admin = $this->makeAdmin();
        $student1 = $this->makeStudent('active', 'STU001');
        $student2 = User::create([
            'name' => 'Other Student',
            'email' => 'unique.other@pte.hu',
            'neptun_code' => 'DUP999',
            'major' => 'Physics',
            'year_of_study' => 2,
            'password' => 'secret_hash',
            'status' => 'active',
        ]);
        $student2->assignRole(UserRole::Student);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('edit', $student1, [
                'name' => 'Attempt Duplicate Neptun',
                'email' => 'different.unique@pte.hu',
                'neptun_code' => 'DUP999',
                'major' => 'Physics',
                'year_of_study' => 2,
                'faculty_id' => null,
            ])
            ->assertHasTableActionErrors(['neptun_code' => 'unique']);
    }

    public function test_admin_cannot_deactivate_last_remaining_admin_in_student_list(): void
    {
        // Only one admin exists ($admin). Assign them Student role so they appear in student table.
        $admin = $this->makeAdmin();
        $admin->assignRole(UserRole::Student);

        // Verify only 1 active admin exists in system
        $this->assertSame(1, User::role(UserRole::Admin)->where('status', 'active')->count());

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->assertTableActionDisabled('deactivate', $admin);
    }

    public function test_deactivate_action_aborts_if_called_on_own_admin_account_in_student_list(): void
    {
        $admin = $this->makeAdmin();
        $admin->assignRole(UserRole::Student);

        Livewire::actingAs($admin)
            ->test(StudentManagement::class)
            ->callTableAction('deactivate', $admin);

        $this->assertSame('active', $admin->fresh()->status);
    }
}
