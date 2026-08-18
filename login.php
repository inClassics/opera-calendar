<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/User.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$userRepository = new User($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals(csrf_token(), $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        $user = $userRepository->findByUsername($username);

        if ($user && (int) $user['status'] === 1 && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            header('Location: index.php');
            exit;
        }

        $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
    <main class="login-card">
        <h1><?= e(APP_NAME) ?></h1>
        <p class="muted">Sign in to view and update availability.</p>

        <?php if ($error): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Username
                <input type="text" name="username" autocomplete="username" required autofocus>
            </label>
            <label>Password
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="button primary" type="submit">Login</button>
        </form>
    </main>
</body>
</html>
