<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !hash_equals(
            csrf_token(),
            $_POST['csrf_token'] ?? ''
        )
    ) {
        $error = 'Your session expired. Please try again.';
    } else {

        $currentPassword =
            $_POST['current_password'] ?? '';

        $newPassword =
            $_POST['new_password'] ?? '';

        $confirmPassword =
            $_POST['confirm_password'] ?? '';

        if (
            $currentPassword === ''
            ||
            $newPassword === ''
            ||
            $confirmPassword === ''
        ) {
            $error = 'Please complete all fields.';
        } elseif (
            $newPassword !== $confirmPassword
        ) {
            $error = 'The new passwords do not match.';
        } elseif (
            strlen($newPassword) < 8
        ) {
            $error =
                'The new password must be at least 8 characters long.';
        } else {

            /*
            |--------------------------------------------------------------------------
            | Get current user
            |--------------------------------------------------------------------------
            */

            $stmt =
                $pdo->prepare("
                    SELECT
                        id,
                        password_hash
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

            $stmt->execute([
                (int) $_SESSION['user_id']
            ]);

            $user =
                $stmt->fetch();

            if (
                !$user
                ||
                !password_verify(
                    $currentPassword,
                    $user['password_hash']
                )
            ) {
                $error =
                    'Your current password is incorrect.';
            } else {

                /*
                |--------------------------------------------------------------------------
                | Save new password
                |--------------------------------------------------------------------------
                */

                $newHash =
                    password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                $stmt =
                    $pdo->prepare("
                        UPDATE users
                        SET password_hash = ?
                        WHERE id = ?
                    ");

                $stmt->execute([
                    $newHash,
                    (int) $_SESSION['user_id']
                ]);

                /*
                |--------------------------------------------------------------------------
                | Regenerate session ID after security-sensitive change
                |--------------------------------------------------------------------------
                */

                session_regenerate_id(true);

                $success =
                    'Your password has been changed successfully.';
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        Change password · <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="assets/css/app.css">

</head>

<body>

    <header class="topbar">

        <div class="brand">
            <?= e(APP_NAME) ?>
        </div>

        <h1 class="admin-title">
            Change password
        </h1>

        <div class="account">

            <a href="index.php">
                Schedule
            </a>

            <a href="logout.php">
                Logout
            </a>

        </div>

    </header>

    <main class="admin-page">

        <section
            class="panel"
            style="max-width:520px;margin:30px auto;">

            <h2>
                Change your password
            </h2>

            <?php if ($error): ?>

                <div class="alert">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>

            <?php if ($success): ?>

                <div class="success">
                    <?= e($success) ?>
                </div>

            <?php endif; ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>">

                <label>

                    Current password

                    <input
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required>

                </label>

                <label>

                    New password

                    <input
                        type="password"
                        name="new_password"
                        autocomplete="new-password"
                        minlength="8"
                        required>

                </label>

                <label>

                    Confirm new password

                    <input
                        type="password"
                        name="confirm_password"
                        autocomplete="new-password"
                        minlength="8"
                        required>

                </label>

                <p class="muted">
                    Minimum 8 characters.
                </p>

                <button
                    class="button primary"
                    type="submit">
                    Change password
                </button>

            </form>

        </section>

    </main>

</body>

</html>