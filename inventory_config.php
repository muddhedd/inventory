<?php
/**
 * inventory_config.php
 * -----------------------------------------------------------------------
 * Delad konfiguration för inventory.php och admin.php: databasuppgifter,
 * session/inloggning, samt hjälpfunktioner.
 *
 * Denna fil ska INTE anropas direkt i webbläsaren – den inkluderas
 * (require) överst i inventory.php och admin.php.
 *
 * INSTALLATION:
 * 1. Kör schema_inventory.sql i phpMyAdmin (skapar tabellerna, prefix
 *    Inventory_, samt en admin-användare).
 * 2. Fyll i dina egna databasuppgifter i de fyra define()-raderna nedan.
 * 3. Se teknisk_beskrivning.txt för standardinloggning.
 * -----------------------------------------------------------------------
 */

// ============================================================
// KONFIGURATION – fyll i dina egna databasuppgifter här
// ============================================================
define('DB_HOST', 'FYLL_I_DIN_DATABASHOST');       // t.ex. 'localhost'
define('DB_USER', 'FYLL_I_DITT_DATABASANVANDARNAMN');
define('DB_PASS', 'FYLL_I_DITT_DATABASLOSENORD');
define('DB_NAME', 'FYLL_I_DITT_DATABASNAMN');

define('SESSION_LIFETIME', 86400); // 24h

// Kolumner i inventarietabellen som får redigeras/sorteras/filtreras
// (allt utom ID). Ändra/lägg till här om tabellen någon gång växer.
const INVENTORY_FIELDS = ['Titel', 'OfBy', 'Kind', 'Kategori', 'Status'];

// Tabellnamn (prefixade för att inte krocka med andra tabeller i samma databas).
const INVENTORY_TABLE     = 'inventory_Items';
const INVENTORY_USR_TABLE = 'inventory_Usr';

// ============================================================
// SESSION / INLOGGNING
// ============================================================
session_set_cookie_params(SESSION_LIFETIME);
session_start();

function isLoggedIn(): bool {
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        return false;
    }
    if ((time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        return false;
    }
    return true;
}

// Anropas överst i admin.php (inventory.php kräver INTE inloggning för
// att visas – bara för att lägga till/redigera). Blockerar hela sidan
// med ett inloggningsformulär tills man är inloggad.
function requireLogin(): void {
    if (isLoggedIn()) {
        return;
    }
    ?>
    <!DOCTYPE html>
    <html lang="sv">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Logga in – Inventory</title>
        <link rel="stylesheet" href="inventory.css">
    </head>
    <body class="login-body">
        <div class="login-box">
            <h1>Logga in</h1>
            <form id="login-form">
                <label>Användarnamn
                    <input type="text" name="username" autocomplete="username" required>
                </label>
                <label>Lösenord
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit">Logga in</button>
                <p id="login-error" class="error-text"></p>
            </form>
        </div>
        <script>
            document.getElementById('login-form').addEventListener('submit', function (e) {
                e.preventDefault();
                const fd = new FormData(this);
                fd.append('action', 'login');
                fetch('inventory.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            document.getElementById('login-error').textContent = data.error || 'Fel vid inloggning.';
                        }
                    });
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// DATABAS
// ============================================================
function getDb(): mysqli {
    static $db = null;
    if ($db === null) {
        $db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$db) {
            http_response_code(500);
            die('Databasanslutning misslyckades.');
        }
        mysqli_set_charset($db, 'utf8mb4');
    }
    return $db;
}

// Hjälpfunktion: binder en variabel array av parametrar (alla strängar)
// till ett prepared statement. Används eftersom antalet filter/sök-
// villkor varierar beroende på vad användaren valt.
function bindParams(mysqli_stmt $stmt, string $types, array &$params): void {
    if ($types === '') {
        return;
    }
    $args = [$stmt, $types];
    foreach ($params as $key => $value) {
        $args[] = &$params[$key];
    }
    call_user_func_array('mysqli_stmt_bind_param', $args);
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Hämtar alla unika, icke-tomma värden för ett fält – används både till
// filter-dropdowns och till autocomplete-datalists.
function getDistinctValues(mysqli $db, string $field): array {
    if (!in_array($field, INVENTORY_FIELDS, true)) {
        return [];
    }
    $sql = 'SELECT DISTINCT `' . $field . '` AS v FROM ' . INVENTORY_TABLE . ' WHERE `' . $field . "` <> '' ORDER BY `" . $field . '` ASC';
    $res = mysqli_query($db, $sql);
    $values = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $values[] = $row['v'];
    }
    return $values;
}
