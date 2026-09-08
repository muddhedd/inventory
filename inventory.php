<?php
/**
 * inventory.php
 * -----------------------------------------------------------------------
 * Enkel inventarielista mot tabellen inventory_Items.
 *  - Listan/sök/filter/sortering visas alltid, utan inloggning.
 *  - Inloggning krävs för att lägga till/redigera. Länken "Admin" i
 *    admin.php kräver också inloggning, för att skapa/redigera/ta bort
 *    användare.
 *  - Listar de 20 senaste raderna (ID fallande) som standard.
 *  - Sortering: klicka på kolumnrubrikerna.
 *  - Filtrering: en dropdown per kolumn, med tabellens befintliga
 *    unika värden.
 *  - Sökning: fritextfält som söker i alla kolumner.
 *  - "Lägg till" och "Redigera" öppnas i popup-fönster, med
 *    autokompletteringsförslag (datalist) byggda på befintliga värden.
 * -----------------------------------------------------------------------
 */

require_once 'inventory_config.php';

// ============================================================
// AJAX-HANTERING (inloggning / lägg till / uppdatera) – körs innan
// HTML skrivs ut
// ============================================================
if (isset($_POST['action'])) {

    header('Content-Type: application/json; charset=utf-8');

    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Serverfel: ' . $e->getMessage()]);
        exit;
    });

    $db = getDb();

    // ---- Inloggning (kräver ingen tidigare session) ----
    if ($_POST['action'] === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $stmt = mysqli_prepare($db, 'SELECT ID, Pwd FROM ' . INVENTORY_USR_TABLE . ' WHERE Usr = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($row && password_verify($password, $row['Pwd'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = $row['ID'];
            $_SESSION['login_time'] = time();
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Fel användarnamn eller lösenord.']);
        }
        exit;
    }

    // ---- Utloggning ----
    if ($_POST['action'] === 'logout') {
        session_unset();
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // Allt nedanför kräver inloggning.
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Inte inloggad.']);
        exit;
    }

    // ---- Lägg till en ny rad ----
    if ($_POST['action'] === 'add_item') {
        $values = [];
        foreach (INVENTORY_FIELDS as $field) {
            $values[$field] = trim($_POST[$field] ?? '');
            if ($values[$field] === '') {
                echo json_encode(['success' => false, 'error' => "Fältet \"$field\" måste fyllas i."]);
                exit;
            }
        }

        $stmt = mysqli_prepare(
            $db,
            'INSERT INTO ' . INVENTORY_TABLE . ' (Titel, OfBy, Kind, Kategori, Status) VALUES (?, ?, ?, ?, ?)'
        );
        mysqli_stmt_bind_param(
            $stmt, 'sssss',
            $values['Titel'], $values['OfBy'], $values['Kind'], $values['Kategori'], $values['Status']
        );
        $ok = mysqli_stmt_execute($stmt);

        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    // ---- Uppdatera en befintlig rad ----
    if ($_POST['action'] === 'update_item') {
        $id = (int)($_POST['ID'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Ogiltigt ID.']);
            exit;
        }

        $values = [];
        foreach (INVENTORY_FIELDS as $field) {
            $values[$field] = trim($_POST[$field] ?? '');
            if ($values[$field] === '') {
                echo json_encode(['success' => false, 'error' => "Fältet \"$field\" måste fyllas i."]);
                exit;
            }
        }

        $stmt = mysqli_prepare(
            $db,
            'UPDATE ' . INVENTORY_TABLE . ' SET Titel = ?, OfBy = ?, Kind = ?, Kategori = ?, Status = ? WHERE ID = ?'
        );
        mysqli_stmt_bind_param(
            $stmt, 'sssssi',
            $values['Titel'], $values['OfBy'], $values['Kind'], $values['Kategori'], $values['Status'], $id
        );
        $ok = mysqli_stmt_execute($stmt);

        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Okänd åtgärd.']);
    exit;
}

// ============================================================
// INLOGGNINGSSTATUS
// (Listan/sök/filter/sortering visas alltid; Lägg till/Redigera kräver
// inloggning, både i gränssnittet nedan och i AJAX-hanteringen högre
// upp i filen.)
// ============================================================
$loggedIn = isLoggedIn();

// ============================================================
// BYGG LISTAN UTIFRÅN SORTERING / FILTER / SÖKNING
// ============================================================
$db = getDb();

// ---- Sortering ----
$sortColumns = array_merge(['ID'], INVENTORY_FIELDS);
$sortBy  = in_array($_GET['sort'] ?? '', $sortColumns, true) ? $_GET['sort'] : 'ID';
$sortDir = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

// ---- Filter (en dropdown per kolumn) ----
$filters = [];
foreach (INVENTORY_FIELDS as $field) {
    $filters[$field] = trim($_GET['filter_' . strtolower($field)] ?? '');
}

// ---- Fritextsökning över alla kolumner ----
$q = trim($_GET['q'] ?? '');

// ---- Bygg WHERE-klausul ----
$where  = [];
$params = [];
$types  = '';

foreach ($filters as $field => $val) {
    if ($val !== '') {
        $where[]  = "`$field` = ?";
        $params[] = $val;
        $types   .= 's';
    }
}

if ($q !== '') {
    $searchParts = [];
    foreach (INVENTORY_FIELDS as $field) {
        $searchParts[] = "`$field` LIKE ?";
        $params[]      = '%' . $q . '%';
        $types        .= 's';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}

$sql = 'SELECT ID, Titel, OfBy, Kind, Kategori, Status FROM ' . INVENTORY_TABLE;
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

// ---- Totalt antal träffar (samma WHERE-klausul, men utan LIMIT) – behövs för sidbläddringen ----
$countSql  = 'SELECT COUNT(*) AS c FROM ' . INVENTORY_TABLE;
if (!empty($where)) {
    $countSql .= ' WHERE ' . implode(' AND ', $where);
}
$countStmt = mysqli_prepare($db, $countSql);
$countParams = $params; // kopia, eftersom bindParams binder via referens
bindParams($countStmt, $types, $countParams);
mysqli_stmt_execute($countStmt);
$totalRows  = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['c'];

// ---- Sidbläddring ----
$perPage    = 20;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = max(1, (int)($_GET['page'] ?? 1));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sql .= " ORDER BY `$sortBy` $sortDir LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types   .= 'ii';

$stmt = mysqli_prepare($db, $sql);
bindParams($stmt, $types, $params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$items  = mysqli_fetch_all($result, MYSQLI_ASSOC);

// ---- Unika värden per fält: används till filter-dropdowns OCH autocomplete ----
$distinct = [];
foreach (INVENTORY_FIELDS as $field) {
    $distinct[$field] = getDistinctValues($db, $field);
}

// ---- Hjälpfunktion för att bygga sorteringslänkar som bevarar filter/sök ----
function sortLink(string $col, string $label, string $currentSort, string $currentDir): string {
    $qs = $_GET;
    $qs['sort'] = $col;
    $qs['dir']  = ($currentSort === $col && $currentDir === 'ASC') ? 'desc' : 'asc';
    $qs['page'] = 1; // ny sortering börjar om på sida 1

    $arrow = '';
    if ($currentSort === $col) {
        $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
    }

    return '<a href="?' . h(http_build_query($qs)) . '">' . h($label) . $arrow . '</a>';
}

// Bygger en länk till en annan sida, med filter/sök/sortering bevarade.
function pageLink(int $targetPage): string {
    $qs = $_GET;
    $qs['page'] = $targetPage;
    return '?' . h(http_build_query($qs));
}

$hasActiveFilters = $q !== '' || count(array_filter($filters)) > 0;
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory</title>
    <link rel="stylesheet" href="inventory.css">
</head>
<body>
<div class="page">

    <nav class="inventory-nav">
        <a href="inventory.php" class="active">Inventory</a>
        <?php if ($loggedIn): ?>
            <a href="admin.php">Admin</a>
            <a href="#" id="logout-link" class="logout-link">Logga ut</a>
        <?php endif; ?>
    </nav>

    <h1>Inventory</h1>

    <!-- ============================================================ -->
    <!-- TOPPRAD – Lägg till-knapp + fritextsökning                    -->
    <!-- ============================================================ -->
    <div class="top-row">
        <?php if ($loggedIn): ?>
            <button type="button" id="add-btn" class="primary-btn">+ Lägg till</button>
        <?php endif; ?>

        <form method="get" class="search-form">
            <?php foreach ($filters as $field => $val): ?>
                <input type="hidden" name="filter_<?= strtolower($field) ?>" value="<?= h($val) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="sort" value="<?= h($sortBy) ?>">
            <input type="hidden" name="dir" value="<?= strtolower($sortDir) ?>">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Sök i allt...">
            <button type="submit">Sök</button>
        </form>

        <div class="auth-area">
            <?php if (!$loggedIn): ?>
                <button type="button" id="login-btn" class="secondary-btn">Logga in</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$loggedIn): ?>
        <p class="info-text">Du kan se och söka fritt. Logga in för att lägga till eller redigera objekt.</p>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!-- FILTERRAD – en dropdown per kolumn                            -->
    <!-- ============================================================ -->
    <form method="get" class="filter-form">
        <input type="hidden" name="q" value="<?= h($q) ?>">
        <input type="hidden" name="sort" value="<?= h($sortBy) ?>">
        <input type="hidden" name="dir" value="<?= strtolower($sortDir) ?>">

        <?php foreach (INVENTORY_FIELDS as $field): ?>
            <div class="filter-field">
                <label><?= h($field) ?></label>
                <select name="filter_<?= strtolower($field) ?>" onchange="this.form.submit()">
                    <option value="">Alla</option>
                    <?php foreach ($distinct[$field] as $val): ?>
                        <option value="<?= h($val) ?>" <?= $filters[$field] === $val ? 'selected' : '' ?>><?= h($val) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <?php if ($hasActiveFilters): ?>
            <a href="inventory.php" class="reset-link">Rensa filter/sökning</a>
        <?php endif; ?>
    </form>

    <!-- ============================================================ -->
    <!-- TABELL – de senaste 20 raderna enligt vald sortering/filter   -->
    <!-- ============================================================ -->
    <table class="inventory-table">
        <thead>
            <tr>
                <th><?= sortLink('ID', 'ID', $sortBy, $sortDir) ?></th>
                <?php foreach (INVENTORY_FIELDS as $field): ?>
                    <th><?= sortLink($field, $field, $sortBy, $sortDir) ?></th>
                <?php endforeach; ?>
                <?php if ($loggedIn): ?>
                    <th>Redigera</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="<?= count(INVENTORY_FIELDS) + 1 + ($loggedIn ? 1 : 0) ?>" class="empty-row">Inga rader hittades.</td></tr>
            <?php endif; ?>

            <?php foreach ($items as $item): ?>
                <tr data-id="<?= (int)$item['ID'] ?>"
                    data-titel="<?= h($item['Titel']) ?>"
                    data-ofby="<?= h($item['OfBy']) ?>"
                    data-kind="<?= h($item['Kind']) ?>"
                    data-kategori="<?= h($item['Kategori']) ?>"
                    data-status="<?= h($item['Status']) ?>">
                    <td><?= (int)$item['ID'] ?></td>
                    <td><?= h($item['Titel']) ?></td>
                    <td><?= h($item['OfBy']) ?></td>
                    <td><?= h($item['Kind']) ?></td>
                    <td><?= h($item['Kategori']) ?></td>
                    <td><?= h($item['Status']) ?></td>
                    <?php if ($loggedIn): ?>
                        <td><button type="button" class="edit-btn">Redigera</button></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ============================================================ -->
    <!-- SIDBLÄDDRING                                                  -->
    <!-- ============================================================ -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= pageLink($page - 1) ?>" class="secondary-btn">&laquo; Föregående</a>
            <?php else: ?>
                <span class="secondary-btn disabled">&laquo; Föregående</span>
            <?php endif; ?>

            <span class="pagination-status">Sida <?= $page ?> av <?= $totalPages ?> (<?= $totalRows ?> träffar)</span>

            <?php if ($page < $totalPages): ?>
                <a href="<?= pageLink($page + 1) ?>" class="secondary-btn">Nästa &raquo;</a>
            <?php else: ?>
                <span class="secondary-btn disabled">Nästa &raquo;</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ============================================================ -->
<!-- DATALISTS – autokompletteringsförslag, delas av båda formulären -->
<!-- ============================================================ -->
<?php foreach (INVENTORY_FIELDS as $field): ?>
    <datalist id="options-<?= strtolower($field) ?>">
        <?php foreach ($distinct[$field] as $val): ?>
            <option value="<?= h($val) ?>">
        <?php endforeach; ?>
    </datalist>
<?php endforeach; ?>

<!-- ============================================================ -->
<!-- POPUP – Logga in                                              -->
<!-- ============================================================ -->
<div id="login-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="login-title">
        <button type="button" class="popup-close" data-close="login-overlay" aria-label="Stäng">&times;</button>
        <h2 id="login-title">Logga in</h2>
        <form id="login-form">
            <label>Användarnamn
                <input type="text" name="username" autocomplete="username" required>
            </label>
            <label>Lösenord
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit">Logga in</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- POPUP – Lägg till                                             -->
<!-- ============================================================ -->
<div id="add-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="add-title">
        <button type="button" class="popup-close" data-close="add-overlay" aria-label="Stäng">&times;</button>
        <h2 id="add-title">Lägg till</h2>
        <form id="add-form">
            <?php foreach (INVENTORY_FIELDS as $field): ?>
                <label><?= h($field) ?>
                    <input type="text" name="<?= h($field) ?>" list="options-<?= strtolower($field) ?>" required>
                </label>
            <?php endforeach; ?>
            <button type="submit">Spara</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- POPUP – Redigera                                              -->
<!-- ============================================================ -->
<div id="edit-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="edit-title">
        <button type="button" class="popup-close" data-close="edit-overlay" aria-label="Stäng">&times;</button>
        <h2 id="edit-title">Redigera</h2>
        <form id="edit-form">
            <input type="hidden" name="ID" id="edit-ID">
            <?php foreach (INVENTORY_FIELDS as $field): ?>
                <label><?= h($field) ?>
                    <input type="text" name="<?= h($field) ?>" id="edit-<?= h($field) ?>" list="options-<?= strtolower($field) ?>" required>
                </label>
            <?php endforeach; ?>
            <button type="submit">Spara ändringar</button>
            <p class="popup-msg"></p>
        </form>
    </div>
</div>

<script>
    function showModal(id) {
        document.getElementById(id).hidden = false;
    }
    function hideModal(id) {
        document.getElementById(id).hidden = true;
    }

    document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            hideModal(this.dataset.close);
        });
    });
    document.querySelectorAll('.popup-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.hidden = true;
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.popup-overlay').forEach(o => o.hidden = true);
        }
    });

    // ---- Lägg till (knappen finns bara om inloggad) ----
    const addBtn = document.getElementById('add-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            document.getElementById('add-form').reset();
            document.querySelector('#add-overlay .popup-msg').textContent = '';
            showModal('add-overlay');
        });
    }

    const addForm = document.getElementById('add-form');
    if (addForm) {
        addForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'add_item');
            const msg = this.querySelector('.popup-msg');
            msg.textContent = 'Sparar...';

            fetch('inventory.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        msg.textContent = data.error || 'Något gick fel.';
                    }
                })
                .catch(err => { msg.textContent = 'Nätverksfel: ' + err.message; });
        });
    }

    // ---- Redigera (knapparna finns bara om inloggad) ----
    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = this.closest('tr');
            document.getElementById('edit-ID').value        = row.dataset.id;
            document.getElementById('edit-Titel').value     = row.dataset.titel;
            document.getElementById('edit-OfBy').value       = row.dataset.ofby;
            document.getElementById('edit-Kind').value      = row.dataset.kind;
            document.getElementById('edit-Kategori').value  = row.dataset.kategori;
            document.getElementById('edit-Status').value    = row.dataset.status;
            document.querySelector('#edit-overlay .popup-msg').textContent = '';
            showModal('edit-overlay');
        });
    });

    const editForm = document.getElementById('edit-form');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'update_item');
            const msg = this.querySelector('.popup-msg');
            msg.textContent = 'Sparar...';

            fetch('inventory.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        msg.textContent = data.error || 'Något gick fel.';
                    }
                })
                .catch(err => { msg.textContent = 'Nätverksfel: ' + err.message; });
        });
    }

    // ---- Logga in (knappen finns bara om EJ inloggad) ----
    const loginBtn = document.getElementById('login-btn');
    if (loginBtn) {
        loginBtn.addEventListener('click', function () {
            document.getElementById('login-form').reset();
            document.querySelector('#login-overlay .popup-msg').textContent = '';
            showModal('login-overlay');
        });
    }

    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'login');
            const msg = this.querySelector('.popup-msg');
            msg.textContent = 'Loggar in...';

            fetch('inventory.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        msg.textContent = data.error || 'Fel vid inloggning.';
                    }
                })
                .catch(err => { msg.textContent = 'Nätverksfel: ' + err.message; });
        });
    }

    // ---- Logga ut (länken finns bara om inloggad) ----
    const logoutLink = document.getElementById('logout-link');
    if (logoutLink) {
        logoutLink.addEventListener('click', function (e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'logout');
            fetch('inventory.php', { method: 'POST', body: fd })
                .then(() => location.reload());
        });
    }
</script>
</body>
</html>
