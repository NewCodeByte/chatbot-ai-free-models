<?php // login.php - PRIMISSIMA RIGA
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_init.php';
$db = initDatabase(); // $db conterrà l'oggetto SQLite3 o false

if (!$db) {
    error_log("Fallimento initDatabase() rilevato in: " . $errorContext);
     return false;
}

// --- BLOCCO INTERNAZIONALIZZAZIONE (i18n) ---
$locale_code = 'it_IT.UTF-8'; // Default locale IN ITALIANO ORA
$db_i18n = null;

try {
    $db_path_i18n = __DIR__ . '/db.db';
    if (file_exists($db_path_i18n)) {
        $db_i18n = new SQLite3($db_path_i18n, SQLITE3_OPEN_READONLY);
        if ($db_i18n) {
            $tableCheckI18n = $db_i18n->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='settings'");
            if ($tableCheckI18n) {
                $lang_setting = $db_i18n->querySingle("SELECT value FROM settings WHERE name = 'language'");
                if ($lang_setting) {
                    $locale_map = [
                        'it'    => 'it_IT.UTF-8',
                        'en_US' => 'en_US.UTF-8',
                        'es_ES' => 'es_ES.UTF-8',
                        'fr_FR' => 'fr_FR.UTF-8',
                        'de_DE' => 'de_DE.UTF-8',
                        'pt_BR' => 'pt_BR.UTF-8',
                    ];
                    // Se la lingua nel DB è 'it', $locale_code rimane 'it_IT.UTF-8'
                    // Se è un'altra, viene mappata. Se non mappata o non trovata, il default è en_US
                    $locale_code = $locale_map[$lang_setting] ?? 'en_US.UTF-8';
                }
            } else {
                error_log("i18n Login: Tabella 'settings' non trovata per lingua. Uso default: " . $locale_code);
            }
            $db_i18n->close();
            $db_i18n = null;
        } else {
             error_log("i18n Login Init: Impossibile aprire DB per lingua.");
        }
    } else {
         error_log("i18n Login Init: File DB non trovato. Uso default locale: " . $locale_code);
    }
} catch (Exception $e) {
    error_log("Eccezione lettura lingua DB per i18n in Login: " . $e->getMessage());
    if ($db_i18n) { $db_i18n->close(); $db_i18n = null; }
}

$setlocale_result = setlocale(LC_ALL,
    $locale_code,
    str_replace('.UTF-8', '.utf8', $locale_code),
    substr($locale_code, 0, strpos($locale_code, '_'))
);

if ($setlocale_result === false) {
    error_log("Attenzione i18n Login: setlocale(LC_ALL, ...) fallito per locale base '$locale_code'.");
}

putenv('LC_ALL=' . $locale_code);
putenv('LANG=' . $locale_code);
putenv('LANGUAGE=' . $locale_code);

$languages_directory = __DIR__ . '/languages';
$text_domain = 'chatbot';

bindtextdomain($text_domain, $languages_directory);
bind_textdomain_codeset($text_domain, 'UTF-8');
textdomain($text_domain);
// --- FINE BLOCCO i18n ---

if (!function_exists('__')) {
    function __($text, $domain = 'chatbot') {
        return gettext($text);
    }
}

if (!function_exists('escape')) {
    function escape($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

function getDbConnectionLogin() {
    try {
        $db_path = __DIR__ . '/db.db';
        $db = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
        if (!$db) {
             error_log("Login DB: Impossibile aprire DB.");
             return null;
        }
        $db->exec('PRAGMA journal_mode = WAL;');
        $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='admins'");
        if (!$tableCheck) {
            error_log("Login DB: Tabella 'admins' non trovata! Il DB potrebbe non essere stato inizializzato. Provare ad accedere prima ad admin.php.");
            $db->close();
            return null;
        }
        return $db;
    } catch (Exception $e) {
        error_log("Eccezione connessione DB Login: " . $e->getMessage());
        return null;
    }
}

function adminUserExists($db) {
    if (!$db) return false;
    $result = $db->querySingle("SELECT COUNT(id) FROM admins");
    return ($result !== null && $result > 0);
}

$db = getDbConnectionLogin();
$show_setup_form = false;
$setup_error = '';
$setup_success = '';
$login_error = '';

$critical_error_message_key = "Errore di sistema: Impossibile accedere alla configurazione degli utenti. " .
                              "Verificare che il file 'db.db' esista nella cartella del plugin e che il server web abbia i permessi per leggerlo e scriverlo. " .
                              "Potrebbe essere necessario visitare prima la pagina principale del chatbot o il pannello admin per inizializzare il database.";

if (!$db) {
    $critical_error_message = __($critical_error_message_key);
} else {
    if (!adminUserExists($db)) {
        $show_setup_form = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_admin'])) {
            $new_username = trim($_POST['admin_username_setup'] ?? '');
            $new_password = $_POST['admin_password_setup'] ?? '';
            $confirm_password = $_POST['admin_password_confirm_setup'] ?? '';

            if (empty($new_username) || empty($new_password) || empty($confirm_password)) {
                $setup_error = __('Tutti i campi sono obbligatori per la registrazione.');
            } elseif (strlen($new_password) < 6) {
                $setup_error = __('La password deve essere di almeno 6 caratteri.');
            } elseif ($new_password !== $confirm_password) {
                $setup_error = __('Le password non coincidono.');
            } else {
                $stmt_check = $db->prepare("SELECT COUNT(id) FROM admins WHERE username = :username");
                $stmt_check->bindValue(':username', $new_username, SQLITE3_TEXT);
                $user_exists_check = $stmt_check->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0;
                $stmt_check->close();

                if ($user_exists_check > 0) {
                     $setup_error = __('Questo username esiste già. Scegline un altro.');
                } else {
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)");
                    if ($stmt) {
                        $stmt->bindValue(':username', $new_username, SQLITE3_TEXT);
                        $stmt->bindValue(':password_hash', $password_hash, SQLITE3_TEXT);
                        if ($stmt->execute()) {
                            $setup_success = __('Account amministratore creato con successo! Ora puoi effettuare il login.');
                            $show_setup_form = false;
                        } else {
                            $setup_error = __('Errore durante la creazione dell\'account:') . ' ' . $db->lastErrorMsg();
                        }
                        $stmt->close();
                    } else {
                        $setup_error = __('Errore di preparazione statement:') . ' ' . $db->lastErrorMsg();
                    }
                }
            }
        }
    } else {
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            header('Location: admin.php');
            if ($db) $db->close();
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_admin'])) {
            $submitted_username = trim($_POST['admin_username_login'] ?? '');
            $submitted_password = $_POST['admin_password_login'] ?? '';

            if (empty($submitted_username) || empty($submitted_password)) {
                $login_error = __('Username e Password sono obbligatori.');
            } else {
                $stmt = $db->prepare("SELECT password_hash FROM admins WHERE username = :username");
                if ($stmt) {
                    $stmt->bindValue(':username', $submitted_username, SQLITE3_TEXT);
                    $result = $stmt->execute();
                    $row = $result->fetchArray(SQLITE3_ASSOC);
                    $stmt->close();

                    if ($row && isset($row['password_hash'])) {
                        if (password_verify($submitted_password, $row['password_hash'])) {
                            session_regenerate_id(true);
                            $_SESSION['admin_logged_in'] = true;
                            $_SESSION['admin_username'] = $submitted_username;
                            header('Location: admin.php');
                            if ($db) $db->close();
                            exit;
                        } else {
                            $login_error = __('Username o Password errati.');
                        }
                    } else {
                        $login_error = __('Username o Password errati.');
                    }
                } else {
                     $login_error = __('Errore di preparazione statement login:') . ' ' . $db->lastErrorMsg();
                }
            }
        }
    }
}

if ($db) {
    $db->close();
}

$html_lang = 'it'; // Default per l'HTML se non diversamente specificato
if (strpos($locale_code, '_') !== false) {
    $html_lang = substr($locale_code, 0, strpos($locale_code, '_'));
} elseif (strpos($locale_code, '.') !== false) {
     $html_lang = substr($locale_code, 0, strpos($locale_code, '.'));
} else if (strlen($locale_code) >= 2) {
    $html_lang = substr($locale_code,0,2);
}

?>
<!DOCTYPE html>
<html lang="<?php echo escape($html_lang); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo $show_setup_form ? __('Setup Admin Chatbot') : __('Login Admin Chatbot'); ?></title>
    <style>
        /* Gli stili rimangono invariati */
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f0f0f0; margin:0; padding:20px; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; min-width: 300px; max-width: 400px; }
        h2 { margin-top: 0; margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; text-align: left; }
        input[type="text"],
        input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        input[type="submit"] { background-color: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        input[type="submit"]:hover { background-color: #0056b3; }
        .error, .critical-error { color: red; margin-bottom: 15px; font-weight: bold; font-size: 0.9em; background-color: #ffebee; border: 1px solid #e57373; padding: 10px; border-radius: 4px; }
        .success { color: green; margin-bottom: 15px; font-weight: bold; font-size: 0.9em; background-color: #e8f5e9; border: 1px solid #81c784; padding: 10px; border-radius: 4px;}
        .info { font-size: 0.9em; color: #555; margin-bottom: 20px; background-color: #e3f2fd; border: 1px solid #90caf9; padding: 10px; border-radius: 4px;}
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($critical_error_message) && !empty($critical_error_message)): ?>
            <h2><?php echo __('Errore Critico'); ?></h2>
            <p class="critical-error"><?php echo escape($critical_error_message); ?></p>
        <?php elseif ($show_setup_form): ?>
            <h2><?php echo __('Setup Amministratore Chatbot'); ?></h2>
            <p class="info"><?php echo __('Sembra sia la prima volta che configuri l\'accesso. Crea il tuo account amministratore.'); ?></p>
            <?php if ($setup_error): ?><p class="error"><?php echo escape($setup_error); ?></p><?php endif; ?>
            <?php if ($setup_success): ?><p class="success"><?php echo escape($setup_success); ?></p><?php endif; ?>

            <?php if (empty($setup_success)): ?>
            <form method="post" action="login.php">
                <label for="admin_username_setup"><?php echo __('Username Admin:'); ?></label>
                <input type="text" id="admin_username_setup" name="admin_username_setup" required>

                <label for="admin_password_setup"><?php echo __('Password Admin:'); ?></label>
                <input type="password" id="admin_password_setup" name="admin_password_setup" required minlength="6">

                <label for="admin_password_confirm_setup"><?php echo __('Conferma Password:'); ?></label>
                <input type="password" id="admin_password_confirm_setup" name="admin_password_confirm_setup" required minlength="6">
                <br>
                <input type="submit" name="setup_admin" value="<?php echo __('Crea Account Admin'); ?>">
            </form>
            <?php endif; ?>

        <?php else: ?>
            <h2><?php echo __('Accesso Area Admin Chatbot'); ?></h2>
            <?php if ($login_error): ?><p class="error"><?php echo escape($login_error); ?></p><?php endif; ?>
             <?php if ($setup_success): ?>
                <p class="success"><?php echo escape($setup_success); ?></p>
            <?php endif; ?>

            <form method="post" action="login.php">
                <label for="admin_username_login"><?php echo __('Username:'); ?></label>
                <input type="text" id="admin_username_login" name="admin_username_login" required value="<?php echo isset($_POST['admin_username_login']) ? escape($_POST['admin_username_login']) : ''; ?>">

                <label for="admin_password_login"><?php echo __('Password:'); ?></label>
                <input type="password" id="admin_password_login" name="admin_password_login" required>
                <br>
                <input type="submit" name="login_admin" value="<?php echo __('Accedi'); ?>">
            </form>
        <?php endif; ?>
    </div>
</body>
</html>