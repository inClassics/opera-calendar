<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/User.php';

require_admin();

$userRepository = new User($pdo);
$error = '';
$success = '';

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

        try {
            if ($name === '' || $username === '') {
                throw new RuntimeException('Name and username are required.');
            }

            if ($action === 'create') {
                if ($password === '') {
                    throw new RuntimeException('Password is required for a new user.');
                }
                $userRepository->create($name, $username, $password, $role, $sortOrder);
                $success = 'User created.';
            } elseif ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                if (!$id) {
                    throw new RuntimeException('Invalid user.');
                }
                $userRepository->update($id, $name, $username, $role, $status, $sortOrder, $password ?: null);
                $success = 'User updated.';
            }
        } catch (Throwable $e) {
            $error = $e instanceof PDOException ? 'Could not save user. The username may already exist.' : $e->getMessage();
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
        <div class="account"><a href="../index.php">Schedule</a><a href="../logout.php">Logout</a></div>
    </header>
    <main class="admin-page">
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?= e($success) ?></div><?php endif; ?>

        <section class="panel">
            <h2>Add user</h2>
            <form class="user-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <label>Name<input name="name" required></label>
                <label>Username<input name="username" required></label>
                <label>Password<input name="password" type="password" required></label>
                <label>Priority<input name="sort_order" type="number" value="100"></label>
                <label>Role<select name="role">
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                    </select></label>
                <button class="button primary" type="submit">Add user</button>
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
                        <label>Name<input name="name" value="<?= e($user['name']) ?>" required></label>
                        <label>Username<input name="username" value="<?= e($user['username']) ?>" required></label>
                        <label>New password<input name="password" type="password" placeholder="leave unchanged"></label>
                        <label>Priority<input name="sort_order" type="number" value="<?= (int) $user['sort_order'] ?>"></label>
                        <label>Role<select name="role">
                                <option value="member" <?= $user['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select></label>
                        <label class="checkbox"><input type="checkbox" name="status" <?= (int) $user['status'] === 1 ? 'checked' : '' ?>> Active</label>
                        <button class="button" type="submit">Save</button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>

</html>