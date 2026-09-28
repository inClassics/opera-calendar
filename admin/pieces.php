<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Piece.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';
require_admin();
$repo = new Piece($pdo);
$logger = new ActivityLogger($pdo);
$error = '';
$success = '';
function piece_values(): array
{
    return [
        'title' => trim((string)($_POST['title'] ?? '')),
        'default_basses' => (int)($_POST['default_basses'] ?? 0),
        'type' => strtolower(trim((string)($_POST['type'] ?? ''))),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'status' => isset($_POST['status']) ? 1 : 0
    ];
}
function validate_piece(array $v): void
{
    if ($v['title'] === '') throw new RuntimeException('Title is required.');
    if ($v['default_basses'] < 1 || $v['default_basses'] > 7) throw new RuntimeException('Default number of basses must be between 1 and 7.');
    if (!in_array($v['type'], Piece::TYPES, true)) throw new RuntimeException('Choose a valid piece type.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), $_POST['csrf_token'] ?? '')) $error = 'Your session expired. Please refresh and try again.';
    else try {
        $a = (string)($_POST['action'] ?? '');
        $v = piece_values();
        validate_piece($v);
        if ($a === 'create') {
            $id = $repo->create($v['title'], $v['default_basses'], $v['type'], $v['notes'], $v['status']);
            $logger->log(current_user_id(), 'piece_created', 'piece', $id, 'Piece created', null, $v);
            $success = 'Piece added.';
        } elseif ($a === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $old = $repo->findById($id);
            if (!$old) throw new RuntimeException('Piece not found.');
            $repo->update($id, $v['title'], $v['default_basses'], $v['type'], $v['notes'], $v['status']);
            $before = ['title' => $old['title'], 'default_basses' => (int)$old['default_basses'], 'type' => $old['type'], 'notes' => (string)($old['notes'] ?? ''), 'status' => (int)$old['status']];
            if ($before !== $v) $logger->log(current_user_id(), 'piece_updated', 'piece', $id, 'Piece updated', $before, $v);
            $success = 'Piece saved.';
        } else throw new RuntimeException('Invalid action.');
    } catch (Throwable $e) {
        $error = $e instanceof PDOException ? 'Could not save the piece. Run the database migration first and try again.' : $e->getMessage();
    }
}
try {
    $pieces = $repo->all();
} catch (Throwable $e) {
    $pieces = [];
    if ($error === '') $error = 'Pieces table is not available yet. Run database/2026-09-28-create-pieces.sql first.';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pieces · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>

<body>
    <header class="topbar">
        <div class="brand"><?= e(APP_NAME) ?></div>
        <h1 class="admin-title">Pieces</h1>
        <div class="account"><a href="index.php">Admin</a><a href="../index.php">Schedule</a><a href="../logout.php">Logout</a></div>
    </header>
    <main class="admin-page">
        <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?><?php if ($success): ?><div class="success"><?= e($success) ?></div><?php endif; ?>
        <section class="panel">
            <h2>Add piece</h2>
            <form class="piece-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
                <label>Title<input name="title" maxlength="255" required></label>
                <label>Default basses<input name="default_basses" type="number" min="1" max="7" value="4" required></label>
                <label>Type<select name="type">
                        <option value="opera">Opera</option>
                        <option value="ballet">Ballet</option>
                        <option value="concert">Concert</option>
                    </select></label>
                <label class="notes">Notes<textarea name="notes" rows="3"></textarea></label>
                <label class="checkbox"><input type="checkbox" name="status" checked>Active</label><button class="button primary">Add piece</button>
            </form>
        </section>
        <section class="panel">
            <div class="piece-heading">
                <div>
                    <h2>Repertoire</h2>
                    <p class="muted"><?= count($pieces) ?> saved <?= count($pieces) === 1 ? 'piece' : 'pieces' ?></p>
                </div>
                <input id="piece-search" type="search" placeholder="Search pieces…">
            </div>
            <div class="piece-list">
                <?php foreach ($pieces as $p): ?><form class="piece-row <?= (int)$p['status'] === 1 ? '' : 'inactive' ?>" method="post" data-search="<?= e(strtolower($p['title'] . ' ' . $p['type'] . ' ' . ($p['notes'] ?? ''))) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <label>Title<input name="title" maxlength="255" value="<?= e($p['title']) ?>" required></label>
                        <label>Default basses<input name="default_basses" type="number" min="1" max="7" value="<?= (int)$p['default_basses'] ?>" required></label>
                        <label>Type<select name="type">
                                <option value="opera" <?= $p['type'] === 'opera' ? 'selected' : '' ?>>Opera</option>
                                <option value="ballet" <?= $p['type'] === 'ballet' ? 'selected' : '' ?>>Ballet</option>
                                <option value="concert" <?= $p['type'] === 'concert' ? 'selected' : '' ?>>Concert</option>
                            </select></label>
                        <label class="notes">Notes<textarea name="notes" rows="2"><?= e($p['notes'] ?? '') ?></textarea></label>
                        <label class="checkbox"><input type="checkbox" name="status" <?= (int)$p['status'] === 1 ? 'checked' : '' ?>>Active</label><button class="button">Save</button>
                    </form><?php endforeach; ?>
                <?php if (!$pieces): ?><p class="muted">No pieces have been added yet.</p><?php endif; ?></div>
        </section>
    </main>
    <style>
        .piece-form,
        .piece-row {
            display: grid;
            grid-template-columns: minmax(220px, 2fr) 120px 140px minmax(240px, 2fr) 90px auto;
            gap: 12px;
            align-items: end
        }

        .piece-form textarea,
        .piece-row textarea {
            width: 100%;
            padding: 10px 11px;
            border: 1px solid #cdd3da;
            border-radius: 7px;
            background: #fff;
            font: inherit;
            resize: vertical
        }

        .piece-list {
            display: grid;
            gap: 10px
        }

        .piece-row {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: #fff
        }

        .piece-row.inactive {
            background: var(--soft);
            opacity: .72
        }

        .piece-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 16px
        }

        .piece-heading h2,
        .piece-heading p {
            margin: 0
        }

        #piece-search {
            max-width: 320px
        }

        @media(max-width:1000px) {

            .piece-form,
            .piece-row {
                grid-template-columns: 1fr 1fr
            }

            .notes {
                grid-column: 1/-1
            }
        }

        @media(max-width:600px) {

            .piece-form,
            .piece-row {
                grid-template-columns: 1fr
            }

            .notes {
                grid-column: auto
            }

            .piece-heading {
                display: grid
            }

            #piece-search {
                max-width: none
            }
        }
    </style>
    <script>
        document.getElementById('piece-search')?.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.piece-row').forEach(r => r.hidden = q !== '' && !r.dataset.search.includes(q));
        });
    </script>
</body>

</html>