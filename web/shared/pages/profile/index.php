<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = current_user();
if ($user === null) {
    redirect('login.php');
}

$authenticatedUserId = (int) $user['user_id'];
$pageTitle = 'My Profile';
$profilePath = role_profile_path((string) $user['role']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($profilePath);
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_profile') {
            // Ignore any posted identity fields — scope is always the session user.
            update_own_profile($authenticatedUserId, [
                'email' => $_POST['email'] ?? '',
                'first_name' => $_POST['first_name'] ?? '',
                'last_name' => $_POST['last_name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
            ]);
            set_flash('success', 'Profile updated successfully.');
        } elseif ($action === 'change_password') {
            change_own_password(
                $authenticatedUserId,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? '')
            );
            set_flash('success', 'Password changed successfully.');
        } elseif ($action === 'upload_profile_photo') {
            if ((string) $user['role'] !== 'STUDENT') {
                throw new InvalidArgumentException('Only students can upload a profile photo.');
            }
            $upload = $_FILES['profile_photo'] ?? null;
            if (!is_array($upload)) {
                throw new InvalidArgumentException('Choose a profile photo to upload.');
            }
            student_upload_own_profile_photo($authenticatedUserId, $upload);
            set_flash('success', 'Profile photo updated successfully.');
        } elseif ($action === 'remove_profile_photo') {
            if ((string) $user['role'] !== 'STUDENT') {
                throw new InvalidArgumentException('Only students can remove a profile photo.');
            }
            student_remove_own_profile_photo($authenticatedUserId);
            set_flash('success', 'Profile photo removed.');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException $exception) {
        set_flash('error', $exception->getMessage());
    } catch (RuntimeException $exception) {
        set_flash('error', $exception->getMessage());
    }

    redirect($profilePath);
}

$profile = load_own_profile($authenticatedUserId);
$account = $profile['account'];
$person = $profile['person'];
$editable = $profile['editable'];
$canEditNames = in_array('first_name', $editable, true);
$isStudent = (string) $account['role'] === 'STUDENT' && is_array($person);
$hasProfilePhoto = $isStudent
    && profile_photo_resolve_path(isset($person['profile_photo']) ? (string) $person['profile_photo'] : null) !== null;

$form = [
    'email' => (string) $account['email'],
    'first_name' => is_array($person) ? (string) ($person['first_name'] ?? '') : '',
    'last_name' => is_array($person) ? (string) ($person['last_name'] ?? '') : '',
    'phone' => is_array($person) ? (string) ($person['phone'] ?? '') : '',
];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Manage your own account details. Academic and permission fields are read-only.
</p>

<?php if ($isStudent): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">Profile photo</h2>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Optional display photo only. This is separate from Face Enrollment used for attendance.
            </p>
            <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
                <?= render_student_profile_avatar($person, 'lg') ?>
                <div>
                    <div class="fw-semibold"><?= e($profile['display_name']) ?></div>
                    <div class="small text-muted"><?= $hasProfilePhoto ? 'Custom photo' : 'Default avatar' ?></div>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_profile_photo">
                <div class="app-form-section">
                    <h2 class="app-form-section__title">Upload</h2>
                    <div class="mb-0">
                        <label for="profile_photo" class="form-label app-required">Upload / replace photo</label>
                        <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required>
                        <div class="form-text">JPG, PNG, or WebP. Maximum 2 MB.</div>
                    </div>
                </div>
                <div class="app-form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Upload photo</button>
                </div>
            </form>
            <?php if ($hasProfilePhoto): ?>
                <form method="post" onsubmit="return confirm('Remove your profile photo?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove_profile_photo">
                    <div class="app-form-actions">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Remove photo</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">Account information</h2>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Full name</dt>
                    <dd class="col-sm-8"><?= e($profile['display_name']) ?></dd>
                    <dt class="col-sm-4">Username</dt>
                    <dd class="col-sm-8"><?= e((string) $account['username']) ?></dd>
                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8"><?= e((string) $account['email']) ?></dd>
                    <dt class="col-sm-4">Role</dt>
                    <dd class="col-sm-8"><?= e(role_label((string) $account['role'])) ?></dd>
                    <dt class="col-sm-4">Account status</dt>
                    <dd class="col-sm-8">
                        <span class="badge <?= e(status_badge_class((string) $account['status'])) ?>"><?= e((string) $account['status']) ?></span>
                    </dd>

                    <?php if ((string) $account['role'] === 'STUDENT' && is_array($person)): ?>
                        <dt class="col-sm-4">Registration No</dt>
                        <dd class="col-sm-8"><?= e((string) $person['registration_no']) ?></dd>
                        <dt class="col-sm-4">Course</dt>
                        <dd class="col-sm-8"><?= e($person['course_code'] . ' – ' . $person['course_name']) ?></dd>
                        <dt class="col-sm-4">Batch</dt>
                        <dd class="col-sm-8"><?= e((string) $person['batch_name']) ?></dd>
                        <dt class="col-sm-4">Phone</dt>
                        <dd class="col-sm-8"><?= e((string) ($person['phone'] ?? '') !== '' ? (string) $person['phone'] : '-') ?></dd>
                        <dt class="col-sm-4">Student status</dt>
                        <dd class="col-sm-8">
                            <span class="badge <?= e(status_badge_class((string) $person['status'])) ?>"><?= e((string) $person['status']) ?></span>
                        </dd>
                    <?php elseif ((string) $account['role'] === 'LECTURER' && is_array($person)): ?>
                        <dt class="col-sm-4">Staff No</dt>
                        <dd class="col-sm-8"><?= e((string) $person['staff_no']) ?></dd>
                        <dt class="col-sm-4">Department</dt>
                        <dd class="col-sm-8"><?= e((string) ($person['department'] ?? '') !== '' ? (string) $person['department'] : '-') ?></dd>
                        <dt class="col-sm-4">Phone</dt>
                        <dd class="col-sm-8"><?= e((string) ($person['phone'] ?? '') !== '' ? (string) $person['phone'] : '-') ?></dd>
                        <dt class="col-sm-4">Lecturer status</dt>
                        <dd class="col-sm-8">
                            <span class="badge <?= e(status_badge_class((string) $person['status'])) ?>"><?= e((string) $person['status']) ?></span>
                        </dd>
                    <?php elseif ((string) $account['role'] === 'ACADEMIC_STAFF' && is_array($person)): ?>
                        <dt class="col-sm-4">Staff No</dt>
                        <dd class="col-sm-8"><?= e((string) $person['staff_no']) ?></dd>
                        <dt class="col-sm-4">Position</dt>
                        <dd class="col-sm-8"><?= e((string) ($person['position'] ?? '') !== '' ? (string) $person['position'] : '-') ?></dd>
                        <dt class="col-sm-4">Phone</dt>
                        <dd class="col-sm-8"><?= e((string) ($person['phone'] ?? '') !== '' ? (string) $person['phone'] : '-') ?></dd>
                        <dt class="col-sm-4">Staff status</dt>
                        <dd class="col-sm-8">
                            <span class="badge <?= e(status_badge_class((string) $person['status'])) ?>"><?= e((string) $person['status']) ?></span>
                        </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">Edit profile</h2>
            </div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>
                    <div class="app-form-section">
                        <h2 class="app-form-section__title">Editable fields</h2>
                        <?php if ($canEditNames): ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label app-required">First name</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required value="<?= e($form['first_name']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label app-required">Last name</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required value="<?= e($form['last_name']) ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?= e($form['phone']) ?>">
                            </div>
                        <?php endif; ?>
                        <div class="mb-0">
                            <label for="email" class="form-label app-required">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required value="<?= e($form['email']) ?>">
                        </div>
                    </div>
                    <div class="app-form-actions">
                        <button type="submit" class="btn btn-primary">Save profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">Change password</h2>
            </div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>
                    <div class="app-form-section">
                        <h2 class="app-form-section__title">Password</h2>
                        <div class="mb-3">
                            <label for="current_password" class="form-label app-required">Current password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label app-required">New password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="<?= PROFILE_PASSWORD_MIN_LENGTH ?>">
                            <div class="form-text">At least <?= PROFILE_PASSWORD_MIN_LENGTH ?> characters.</div>
                        </div>
                        <div class="mb-0">
                            <label for="confirm_password" class="form-label app-required">Confirm new password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="<?= PROFILE_PASSWORD_MIN_LENGTH ?>">
                        </div>
                    </div>
                    <div class="app-form-actions">
                        <button type="submit" class="btn btn-outline-primary">Change password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
