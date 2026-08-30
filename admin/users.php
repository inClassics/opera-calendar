<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

require_admin();

$userRepository = new User($pdo);
$activityLogger = new ActivityLogger($pdo);
$error = '';
$success = '';

function adminUserLogFieldChange(
    ActivityLogger $logger,
    int $targetUserId,
    string $field,
    mixed $oldValue,
    mixed $newValue,
    string $description
): void {
    if ((string) $oldValue === (string) $newValue) {
        return;
    }

    $logger->log(
        current_user_id(),
        'user_' . $field . '_changed',
        'user',
        $targetUserId,
        $description,
        [
            $field => $oldValue,
        ],
        [
            $field => $newValue,
        ],
        $targetUserId
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $status = isset($_POST['status']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        $position = trim((string) ($_POST['position'] ?? 'Tutti'));
        $multiplierRaw = trim((string) ($_POST['multiplier'] ?? '1'));

        try {
            if ($name === '' || $username === '') {
                throw new RuntimeException('Name and username are required.');
            }

            if (!in_array($position, ['Concertmaster', 'Tutti'], true)) {
                throw new RuntimeException('Invalid position.');
            }

            if (!is_numeric($multiplierRaw)) {
                throw new RuntimeException('Multiplier must be a number.');
            }

            $multiplier = (float) $multiplierRaw;

            if ($multiplier <= 0 || $multiplier > 10) {
                throw new RuntimeException('Multiplier must be greater than 0 and no more than 10.');
            }

            if ($action === 'create') {
                if ($password === '') {
                    throw new RuntimeException('Password is required for a new user.');
                }

                $newUserId = $userRepository->create(
                    $name,
                    $username,
                    $password,
                    $role,
                    $sortOrder,
                    $position,
                    $multiplier
                );

                $activityLogger->log(
                    current_user_id(),
                    'user_created',
                    'user',
                    $newUserId,
                    'User created',
                    null,
                    [
                        'name' => $name,
                        'username' => $username,
                        'role' => $role,
                        'status' => 1,
                        'sort_order' => $sortOrder,
                        'position' => $position,
                        'multiplier' => $multiplier,
                        'password_set' => true,
                    ],
                    $newUserId
                );

                $success = 'User created.';
            } elseif ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);

                if (!$id) {
                    throw new RuntimeException('Invalid user.');
                }

                $oldUser = $userRepository->findById($id);

                if (!$oldUser) {
                    throw new RuntimeException('User not found.');
                }

                $userRepository->update(
                    $id,
                    $name,
                    $username,
                    $role,
                    $status,
                    $sortOrder,
                    $position,
                    $multiplier,
                    $password ?: null
                );

                adminUserLogFieldChange(
                    $activityLogger,
                    $id,
                    'name',
                    $oldUser['name'] ?? '',
                    $name,
                    'User name changed'
                );

                adminUserLogFieldChange(
                    $activityLogger,
                    $id,
                    'username',
                    $oldUser['username'] ?? '',
                    $username,
                    'Username changed'
                );

                adminUserLogFieldChange(
                    $activityLogger,
                    $id,
                    'role',
                    $oldUser['role'] ?? 'member',
                    $role,
                    'User role changed'
                );

                adminUserLogFieldChange(
                    $activityLogger,
                    $id,
                    'status',
                    (int) ($oldUser['status'] ?? 0),
                    $status,
                    $status === 1
                        ? 'User activated'
                        : 'User deactivated'
                );

                adminUserLogFieldChange(
                    $activityLogger,
                    $id,
                    'sort_order',
                    (int) ($oldUser['sort_order'] ?? 0),
                    $sortOrder,
                    'User priority changed'
                );

                adminUserLogFieldChange(
                    $activityLogger,
                    $id,
                    'position',
                    $oldUser['position'] ?? 'Tutti',
                    $position,
                    'User position changed'
                );

                if (
                    (float) ($oldUser['multiplier'] ?? 1)
                    !== $multiplier
                ) {
                    $activityLogger->log(
                        current_user_id(),
                        'user_multiplier_changed',
                        'user',
                        $id,
                        'User point multiplier changed',
                        [
                            'multiplier' => (float) ($oldUser['multiplier'] ?? 1),
                        ],
                        [
                            'multiplier' => $multiplier,
                        ],
                        $id
                    );
                }

                if ($password !== '') {
                    $activityLogger->log(
                        current_user_id(),
                        'user_password_reset',
                        'user',
                        $id,
                        'User password reset by administrator',
                        null,
                        [
                            'password_reset' => true,
                        ],
                        $id
                    );
                }

                $success = 'User updated.';
            }
        } catch (Throwable $e) {
            $error = $e instanceof PDOException
                ? 'Could not save user. The username may already exist.'
                : $e->getMessage();
        }
    }
}

$users = $userRepository->allUsers();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>
        <h1 class="admin-title">Users</h1>
        <div class="account">
            <a href="../index.php">Schedule</a>
            <a href="activity-log.php">Changes</a>
            <a href="../logout.php">Logout</a>
        </div>
    </header>

    <main class="admin-page">
        <?php if ($error): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= e($success) ?></div>
        <?php endif; ?>

        <section class="panel">
            <h2>Add user</h2>

            <form class="user-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <label>
                    Name
                    <input name="name" required>
                </label>

                <label>
                    Username
                    <input name="username" required>
                </label>

                <label>
                    Password
                    <input name="password" type="password" required>
                </label>

                <label>
                    Priority
                    <input name="sort_order" type="number" value="100">
                </label>

                <label>
                    Role
                    <select name="role">
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                    </select>
                </label>

                <label>
                    Position
                    <select name="position">
                        <option value="Tutti">Tutti</option>
                        <option value="Concertmaster">Concertmaster</option>
                    </select>
                </label>

                <label>
                    Multiplier
                    <input
                        name="multiplier"
                        type="number"
                        value="1"
                        min="0.01"
                        max="10"
                        step="0.01">
                </label>

                <button class="button primary" type="submit">
                    Add user
                </button>
            </form>
        </section>

        <section class="panel">
            <h2>Existing users</h2>

            <div class="user-list">
                <?php foreach ($users as $user): ?>
                    <form class="user-row" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">

                        <label>
                            Name
                            <input name="name" value="<?= e($user['name']) ?>" required>
                        </label>

                        <label>
                            Username
                            <input name="username" value="<?= e($user['username']) ?>" required>
                        </label>

                        <label>
                            New password
                            <input name="password" type="password" placeholder="leave unchanged">
                        </label>

                        <label>
                            Priority
                            <input name="sort_order" type="number" value="<?= (int) $user['sort_order'] ?>">
                        </label>

                        <label>
                            Role
                            <select name="role">
                                <option value="member" <?= $user['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </label>

                        <label>
                            Position
                            <select name="position">
                                <option value="Tutti" <?= ($user['position'] ?? 'Tutti') === 'Tutti' ? 'selected' : '' ?>>Tutti</option>
                                <option value="Concertmaster" <?= ($user['position'] ?? '') === 'Concertmaster' ? 'selected' : '' ?>>Concertmaster</option>
                            </select>
                        </label>

                        <label>
                            Multiplier
                            <input
                                name="multiplier"
                                type="number"
                                value="<?= e((string) ($user['multiplier'] ?? 1)) ?>"
                                min="0.01"
                                max="10"
                                step="0.01">
                        </label>

                        <label class="checkbox">
                            <input
                                type="checkbox"
                                name="status"
                                <?= (int) $user['status'] === 1 ? 'checked' : '' ?>>
                            Active
                        </label>

                        <button class="button" type="submit">
                            Save
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>

</html>