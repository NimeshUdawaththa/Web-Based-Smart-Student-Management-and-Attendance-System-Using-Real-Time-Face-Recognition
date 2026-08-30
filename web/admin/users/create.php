<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$pageTitle = 'Create User';
$managementRoles = user_management_roles();
$errors = [];
$form = [
    'username' => '',
    'email' => '',
    'password' => '',
    'role' => 'LECTURER',
    'status' => 'ACTIVE',
    'staff_no' => '',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'department' => '',
    'position' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('admin/users/create.php');
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['username'] === '' || $form['email'] === '' || $form['password'] === '') {
        $errors[] = 'Username, email, and password are required.';
    }

    if (!validate_email_address($form['email'])) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($form['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (in_array($form['role'], ['LECTURER', 'ACADEMIC_STAFF'], true)) {
        if ($form['staff_no'] === '' || $form['first_name'] === '' || $form['last_name'] === '') {
            $errors[] = 'Staff number, first name, and last name are required for Lecturer and Academic Staff accounts.';
        }
    }

    if ($errors === []) {
        try {
            create_user($form);
            set_flash('success', 'User account created successfully.');
            redirect('admin/users/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('User creation failed: ' . $exception->getMessage());
            $errors[] = 'Unable to create the user account.';
        }
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url('admin/users/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to Users</a>
</div>

<div class="alert alert-info">
    Create Admin, Academic Staff, or Lecturer accounts here.
    Student accounts must be created from <a href="<?= e(app_url('admin/students/register.php')) ?>" class="alert-link">Student Management → Register Student</a>.
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card shadow-sm" id="create-user-form">
    <div class="card-body">
        <?= csrf_field() ?>
        <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Account</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="username" class="form-label app-required">Username</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label app-required">Email</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($form['email']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="password" class="form-label app-required">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
            </div>
        </div>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Role / Status</h2>
            <div class="row g-3">
                <div class="col-md-3">
                    <label for="role" class="form-label">Role</label>
                    <select class="form-select" id="role" name="role">
                        <?php foreach ($managementRoles as $authRole): ?>
                            <option value="<?= e($authRole) ?>" <?= $form['role'] === $authRole ? 'selected' : '' ?>><?= e(role_label($authRole)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (user_statuses() as $userStatus): ?>
                            <option value="<?= e($userStatus) ?>" <?= $form['status'] === $userStatus ? 'selected' : '' ?>><?= e($userStatus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="text-muted small mt-2 mb-0" id="role-help-lecturer" hidden>
                Creating a lecturer account also creates its Lecturer Management profile.
            </p>
            <p class="text-muted small mt-2 mb-0" id="role-help-staff" hidden>
                Creating an academic staff account also creates its Academic Staff Management profile.
            </p>
        </div>

        <div class="app-form-section" id="staff-profile-fields" hidden>
            <h2 class="app-form-section__title">Profile details</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="staff_no" class="form-label app-required">Staff Number</label>
                    <input type="text" class="form-control" id="staff_no" name="staff_no" value="<?= e($form['staff_no']) ?>" maxlength="50">
                </div>
                <div class="col-md-4">
                    <label for="first_name" class="form-label app-required">First Name</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label for="last_name" class="form-label app-required">Last Name</label>
                    <input type="text" class="form-control" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?= e($form['phone']) ?>" maxlength="20">
                </div>
                <div class="col-md-4" id="department-field" hidden>
                    <label for="department" class="form-label">Department</label>
                    <input type="text" class="form-control" id="department" name="department" value="<?= e($form['department']) ?>" maxlength="100">
                </div>
                <div class="col-md-4" id="position-field" hidden>
                    <label for="position" class="form-label">Position</label>
                    <input type="text" class="form-control" id="position" name="position" value="<?= e($form['position']) ?>" maxlength="100">
                </div>
            </div>
        </div>

        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary">Create User</button>
        </div>
    </div>
</form>

<script>
(function () {
    var role = document.getElementById('role');
    var profile = document.getElementById('staff-profile-fields');
    var helpLec = document.getElementById('role-help-lecturer');
    var helpStaff = document.getElementById('role-help-staff');
    var dept = document.getElementById('department-field');
    var pos = document.getElementById('position-field');
    var staffNo = document.getElementById('staff_no');
    var firstName = document.getElementById('first_name');
    var lastName = document.getElementById('last_name');

    function sync() {
        var value = role ? role.value : '';
        var isLecturer = value === 'LECTURER';
        var isStaff = value === 'ACADEMIC_STAFF';
        var needsProfile = isLecturer || isStaff;

        if (profile) {
            profile.hidden = !needsProfile;
        }
        if (helpLec) {
            helpLec.hidden = !isLecturer;
        }
        if (helpStaff) {
            helpStaff.hidden = !isStaff;
        }
        if (dept) {
            dept.hidden = !isLecturer;
        }
        if (pos) {
            pos.hidden = !isStaff;
        }

        [staffNo, firstName, lastName].forEach(function (el) {
            if (el) {
                el.required = needsProfile;
            }
        });
    }

    if (role) {
        role.addEventListener('change', sync);
        sync();
    }
})();
</script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
