<?php
require_once 'inventory_config.php';
requireLogin();

$db = getDb();

if (isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Serverfel: ' . $e->getMessage()]);
        exit;
    });

    // ---- Lägg till användare ----
    if ($_POST['action'] === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || mb_strlen($password) < 8) {
            echo json_encode(['success' => false, 'error' => 'Användarnamn krävs och lösenordet måste vara minst 8 tecken.']);
            exit;
        }

        $check = mysqli_prepare($db, 'SELECT 1 FROM ' . INVENTORY_USR_TABLE . ' WHERE Usr = ? LIMIT 1');
        mysqli_stmt_bind_param($check, 's', $username);
        mysqli_stmt_execute($check);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
            echo json_encode(['success' => false, 'error' => 'Det finns redan en användare med det namnet.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($db, 'INSERT INTO ' . INVENTORY_USR_TABLE . ' (Usr, Pwd) VALUES (?, ?)');
        mysqli_stmt_bind_param($stmt, 'ss', $username, $hash);
        $ok = mysqli_stmt_execute($stmt);

        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ---- Redigera användare (byt namn, och valfritt lösenord) ----
    if ($_POST['action'] === 'update_user') {
        $id       = (int)($_POST['ID'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($id <= 0 || $username === '') {
            echo json_encode(['success' => false, 'error' => 'Ogiltig användare eller tomt användarnamn.']);
            exit;
        }
        if ($password !== '' && mb_strlen($password) < 8) {
            echo json_encode(['success' => false, 'error' => 'Lösenordet måste vara minst 8 tecken (lämna tomt för att behålla nuvarande).']);
            exit;
        }

        $check = mysqli_prepare($db, 'SELECT 1 FROM ' . INVENTORY_USR_TABLE . ' WHERE Usr = ? AND ID <> ? LIMIT 1');
        mysqli_stmt_bind_param($check, 'si', $username, $id);
        mysqli_stmt_execute($check);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
            echo json_encode(['success' => false, 'error' => 'Det finns redan en annan användare med det namnet.']);
            exit;
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($db, 'UPDATE ' . INVENTORY_USR_TABLE . ' SET Usr = ?, Pwd = ? WHERE ID = ?');
            mysqli_stmt_bind_param($stmt, 'ssi', $username, $hash, $id);
        } else {
            $stmt = mysqli_prepare($db, 'UPDATE ' . INVENTORY_USR_TABLE . ' SET Usr = ? WHERE ID = ?');
            mysqli_stmt_bind_param($stmt, 'si', $username, $id);
        }
        $ok = mysqli_stmt_execute($stmt);

        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ---- Ta bort användare ----
    if ($_POST['action'] === 'delete_user') {
        $id = (int)($_POST['ID'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Ogiltig användare.']);
            exit;
        }

        // Aldrig tillåtet att ta bort den sista användaren – då kan ingen
        // längre logga in och skapa en ny.
        $antal = mysqli_fetch_assoc(mysqli_query($db, 'SELECT COUNT(*) AS c FROM ' . INVENTORY_USR_TABLE));
        if ((int)$antal['c'] <= 1) {
            echo json_encode(['success' => false, 'error' => 'Kan inte ta bort den sista kvarvarande användaren.']);
            exit;
        }

        $stmt = mysqli_prepare($db, 'DELETE FROM ' . INVENTORY_USR_TABLE . ' WHERE ID = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        $ok = mysqli_stmt_execute($stmt);

        // Om man precis tog bort sin egen inloggning, avsluta sessionen också.
        if ($ok && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $id) {
            session_unset();
            session_destroy();
        }

        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Okänd åtgärd.']);
    exit;
}

$res = mysqli_query($db, 'SELECT ID, Usr FROM ' . INVENTORY_USR_TABLE . ' ORDER BY Usr ASC');
$anvandare = [];
while ($row = mysqli_fetch_assoc($res)) {
    $anvandare[] = $row;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Inventory</title>
    <link rel="stylesheet" href="inventory.css">
</head>
<body>
<div class="page">

    <nav class="inventory-nav">
        <a href="inventory.php">Inventory</a>
        <a href="admin.php" class="active">Admin</a>
        <a href="#" id="logout-link" class="logout-link">Logga ut</a>
    </nav>

    <div class="top-row">
        <h1>Admin</h1>
        <button type="button" id="add-btn" class="primary-btn">+ Lägg till användare</button>
    </div>
    <p class="info-text">Alla användare har full åtkomst till hela verktyget – det finns ingen roll- eller rättighetsuppdelning. Lösenord måste vara minst 8 tecken och hashas med bcrypt (password_hash()) innan de sparas.</p>

    <table class="inventory-table">
        <thead>
            <tr>
                <th>Användarnamn</th>
                <th>Åtgärder</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($anvandare)): ?>
                <tr><td colspan="2" class="empty-row">Inga användare hittades.</td></tr>
            <?php endif; ?>
            <?php foreach ($anvandare as $u): ?>
                <tr data-id="<?= (int)$u['ID'] ?>" data-usr="<?= h($u['Usr']) ?>">
                    <td><?= h($u['Usr']) ?></td>
                    <td class="actions-cell">
                        <button type="button" class="edit-btn">Redigera</button>
                        <button type="button" class="delete-btn">Ta bort</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ---- Popup: Lägg till användare ---- -->
<div id="add-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true">
        <button type="button" class="popup-close" data-close="add-overlay" aria-label="Stäng">&times;</button>
        <h2>Lägg till användare</h2>
        <form id="add-form">
            <label>Användarnamn <input type="text" name="username" autocomplete="off" required></label>
            <label>Lösenord (minst 8 tecken)
                <input type="password" name="password" minlength="8" autocomplete="new-password" required>
            </label>
            <button type="submit">Spara</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<!-- ---- Popup: Redigera användare ---- -->
<div id="edit-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true">
        <button type="button" class="popup-close" data-close="edit-overlay" aria-label="Stäng">&times;</button>
        <h2>Redigera användare</h2>
        <form id="edit-form">
            <input type="hidden" id="edit-ID" name="ID">
            <label>Användarnamn <input type="text" name="username" id="edit-username" autocomplete="off" required></label>
            <label>Nytt lösenord (lämna tomt för att behålla nuvarande)
                <input type="password" name="password" minlength="8" autocomplete="new-password">
            </label>
            <button type="submit">Spara ändringar</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<script>
    function showModal(id) { document.getElementById(id).hidden = false; }
    function hideModal(id) { document.getElementById(id).hidden = true; }

    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () { hideModal(this.dataset.close); });
    });
    document.querySelectorAll('.popup-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.hidden = true; });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.popup-overlay').forEach(o => o.hidden = true);
    });

    function postAction(fields, onSuccess, msgEl) {
        const fd = new FormData();
        Object.keys(fields).forEach(key => fd.append(key, fields[key]));
        fetch('admin.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (onSuccess) onSuccess(data);
                } else if (msgEl) {
                    msgEl.textContent = data.error || 'Något gick fel.';
                } else {
                    alert(data.error || 'Något gick fel.');
                }
            })
            .catch(err => {
                if (msgEl) msgEl.textContent = 'Nätverksfel: ' + err.message;
                else alert('Nätverksfel: ' + err.message);
            });
    }

    document.getElementById('add-btn').addEventListener('click', function () {
        document.getElementById('add-form').reset();
        document.querySelector('#add-overlay .popup-msg').textContent = '';
        showModal('add-overlay');
    });

    document.getElementById('add-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const fd  = new FormData(this);
        const msg = this.querySelector('.popup-msg');
        msg.textContent = 'Sparar...';
        postAction(
            { action: 'add_user', username: fd.get('username'), password: fd.get('password') },
            () => location.reload(),
            msg
        );
    });

    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = this.closest('tr');
            document.getElementById('edit-form').reset();
            document.getElementById('edit-ID').value       = row.dataset.id;
            document.getElementById('edit-username').value = row.dataset.usr;
            document.querySelector('#edit-overlay .popup-msg').textContent = '';
            showModal('edit-overlay');
        });
    });

    document.getElementById('edit-form').addEventListener('submit', function (e) {
        e.preventDefault();
        const fd  = new FormData(this);
        const msg = this.querySelector('.popup-msg');
        msg.textContent = 'Sparar...';
        postAction(
            { action: 'update_user', ID: fd.get('ID'), username: fd.get('username'), password: fd.get('password') },
            () => location.reload(),
            msg
        );
    });

    document.querySelectorAll('.delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = this.closest('tr');
            if (!confirm('Ta bort användaren "' + row.dataset.usr + '"? Detta går inte att ångra.')) return;
            postAction({ action: 'delete_user', ID: row.dataset.id }, () => location.reload());
        });
    });

    document.getElementById('logout-link').addEventListener('click', function (e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('action', 'logout');
        fetch('inventory.php', { method: 'POST', body: fd }).then(() => location.href = 'inventory.php');
    });
</script>
</body>
</html>
