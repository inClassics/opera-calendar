<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/IcsImporter.php';

require_login();

if (!is_admin()) {
    http_response_code(403);
    exit('Admin access required.');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token =
        $_POST['csrf_token'] ?? '';

    if (
        !$token ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $token
        )
    ) {
        $error =
            'Invalid security token. Refresh the page.';
    } else {

        try {

            if (
                empty($_FILES['ics_file']) ||
                $_FILES['ics_file']['error'] !== UPLOAD_ERR_OK
            ) {
                throw new RuntimeException(
                    'Please choose an .ics file.'
                );
            }

            $icsText =
                file_get_contents(
                    $_FILES['ics_file']['tmp_name']
                );

            if (
                !$icsText ||
                !str_contains(
                    $icsText,
                    'BEGIN:VCALENDAR'
                )
            ) {
                throw new RuntimeException(
                    'This does not appear to be a valid calendar file.'
                );
            }

            $importer =
                new IcsImporter(
                    $pdo,
                    'Europe/Riga'
                );

            $result =
                $importer->import(
                    $icsText,
                    'lnob-lydian',
                    'LNOB Lydian Calendar'
                );

            $message = sprintf(
                '%d events found. %d new, %d updated, %d skipped.',
                $result['total'],
                $result['inserted'],
                $result['updated'],
                $result['skipped']
            );
        } catch (Throwable $e) {

            $error =
                $e->getMessage();
        }
    }
}

$csrf = csrf_token();

?>
<!doctype html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>
        Import calendar · <?= e(APP_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/app.css">

</head>

<body>

    <header class="topbar">

        <div class="brand">
            <?= e(APP_NAME) ?>
        </div>

        <div class="account">

            <a href="../index.php">
                Schedule
            </a>

            <a href="users.php">
                Users
            </a>

            <a href="../logout.php">
                Logout
            </a>

        </div>

    </header>

    <main class="page">

        <h1>
            Import calendar
        </h1>

        <?php if ($message): ?>

            <p style="color: green;">
                <?= e($message) ?>
            </p>

        <?php endif; ?>

        <?php if ($error): ?>

            <p style="color: red;">
                <?= e($error) ?>
            </p>

        <?php endif; ?>

        <form
            method="post"
            enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrf) ?>">

            <p>
                <input
                    type="file"
                    name="ics_file"
                    accept=".ics,text/calendar"
                    required>
            </p>

            <button type="submit">
                Import calendar
            </button>

        </form>

    </main>

</body>

</html>