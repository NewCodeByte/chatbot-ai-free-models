<?php
// Inizia o riprende la sessione all'inizio dello script
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

$locale_code = 'en_US.UTF-8'; // Default locale
$db = null;

try {
    $db_path = __DIR__ . '/db.db';
    if (file_exists($db_path)) {
        $db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
        if ($db) {
            $lang_setting = $db->querySingle("SELECT value FROM settings WHERE name = 'language'");
            if ($lang_setting) {
                // Mappa i codici DB a codici locale standard completi con encoding UTF-8
                // Aggiungi qui tutte le lingue supportate
                $locale_map = [
                    'it'    => 'it_IT.UTF-8',
                    'en_US' => 'en_US.UTF-8',
                    'es_ES' => 'es_ES.UTF-8',
                    'fr_FR' => 'fr_FR.UTF-8',
                    'de_DE' => 'de_DE.UTF-8',
                    'pt_BR' => 'pt_BR.UTF-8',
                ];
                // Usa la mappatura, altrimenti usa il default con .UTF-8
                $locale_code = $locale_map[$lang_setting] ?? 'en_US.UTF-8';
            }
            $db->close();
            $db = null;
        } else {
             error_log("i18n Init: Impossibile aprire DB per lingua.");
        }
    } else {
         error_log("i18n Init: File DB non trovato. Uso default locale: " . $locale_code);
    }
} catch (Exception $e) {
    error_log("Eccezione lettura lingua DB per i18n: " . $e->getMessage());
    if ($db) { $db->close(); $db = null; }
    // $locale_code resta al default
}

// Imposta la locale per gettext. Prova diverse varianti comuni su Linux.
$setlocale_result = setlocale(LC_ALL,
    $locale_code,                   // Es: 'en_US.UTF-8'
    str_replace('.UTF-8', '.utf8', $locale_code), // Es: 'en_US.utf8'
    substr($locale_code, 0, strpos($locale_code, '_')) // Es: 'en' (codice lingua base)
);

// Log per debug (controlla i log del server)
if ($setlocale_result === false) {
    error_log("Attenzione i18n: setlocale(LC_ALL, ...) fallito per locale base '$locale_code'.");
} else {
    // Log opzionale se vuoi vedere la locale effettivamente impostata
    // error_log("i18n: Locale impostata da setlocale: " . $setlocale_result);
}

// Imposta variabili d'ambiente (a volte aiuta gettext)
putenv('LC_ALL=' . $locale_code);
putenv('LANG=' . $locale_code);
putenv('LANGUAGE=' . $locale_code); // Usato specificamente da gettext su alcuni sistemi

// Specifica il percorso alla cartella principale delle lingue
$languages_directory = __DIR__ . '/languages';
// Specifica il nome base dei file .mo (il "dominio")
$text_domain = 'chatbot';

// Associa il dominio alla cartella e specifica l'encoding
bindtextdomain($text_domain, $languages_directory);
bind_textdomain_codeset($text_domain, 'UTF-8');

// Imposta il dominio di default per le chiamate a gettext() / __()
textdomain($text_domain);

// --- FINE BLOCCO i18n ---


// --- RIDEFINIZIONE DI __() PER GETTEXT ---
// È buona pratica ridefinirla per assicurarsi che usi il dominio giusto,
// anche se le funzioni gettext potrebbero essere già globali.
if (!function_exists('__')) {
    /**
     * Recupera la traduzione di un testo.
     * @param string $text Il testo da tradurre.
     * @param string $domain (Opzionale) Il dominio del testo.
     * @return string Testo tradotto o originale.
     */
    function __($text, $domain = 'chatbot') {
        // Usa dgettext per specificare il dominio, più robusto
        // return dgettext($domain, $text);
        // O usa gettext() se textdomain() è stato impostato correttamente sopra
        return gettext($text);
    }
}


// --- INIZIO CODICE ESISTENTE DI admin.php ---

// --- BLOCCO VERIFICA AUTENTICAZIONE ---
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// --- GESTIONE RICHIESTA LOGOUT ---
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $_SESSION = array(); // Svuota array sessione
    if (ini_get("session.use_cookies")) { // Cancella cookie
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy(); // Distruggi sessione
    header('Location: login.php'); // Reindirizza
    exit;
}

// --- FUNZIONI GLOBALI E HELPER ---

if (!function_exists('__')) { function __($text) { return $text; } }
function escape($string) { return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

if (!function_exists('getAssetUrl')) {
    function getAssetUrl($filename, $subfolder = '') {
        $basePath = __DIR__ . '/';
        if (!empty($subfolder)) { $basePath .= rtrim($subfolder, '/') . '/'; }
        $filepath = $basePath . $filename;
        if (file_exists($filepath)) {
            $relativePath = (!empty($subfolder) ? rtrim($subfolder, '/') . '/' : '') . $filename;
            return $relativePath . '?v=' . filemtime($filepath);
        }
        if (preg_match('/\.(png|gif|jpe?g)$/i', $filename)) {
             $placeholder = 'images/placeholder.png';
             if (file_exists(__DIR__ . '/' . $placeholder)) return $placeholder . '?v=default';
        }
        return '';
    }
}

/* =============================================
   FUNZIONI DATABASE (SQLite)
   ============================================= */
   function getSettingsFromDB($db) {
    $settings = []; 
    $fields = ['chat_title', 'welcome_message', 'knowledge_base', 'bot_rules', 'api_key', 'api_model', 
               'save_messages', 'language', 'show_logo', 'mute_sound'];
    $inClause = "'" . implode("','", $fields) . "'";
    $result = $db->query("SELECT name, value FROM settings WHERE name IN ({$inClause})");
    if ($result) { 
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { 
            $settings[$row['name']] = $row['value']; 
        } 
    }
    else { 
        error_log("Errore query getSettingsFromDB: " . $db->lastErrorMsg()); 
    }
    $settings['language'] = $settings['language'] ?? 'it';
    $settings['save_messages'] = $settings['save_messages'] ?? '0';
    $settings['chat_title'] = $settings['chat_title'] ?? 'Chatbot';
    $settings['welcome_message'] = $settings['welcome_message'] ?? 'Ciao!';
    $settings['api_key'] = $settings['api_key'] ?? '';
    $settings['api_model'] = $settings['api_model'] ?? 'google/gemma-3-27b-it:free';
    $settings['knowledge_base'] = $settings['knowledge_base'] ?? '';
    $settings['bot_rules'] = $settings['bot_rules'] ?? '';
    $settings['show_logo'] = $settings['show_logo'] ?? '1';
    $settings['mute_sound'] = $settings['mute_sound'] ?? '0';
    return $settings;
}
function saveSettingsToDB($db, $settings) {
    $fieldsToSave = ['chat_title', 'welcome_message', 'api_key', 'api_model', 'knowledge_base', 
                     'bot_rules', 'save_messages', 'language', 'show_logo', 'mute_sound'];
    $db->exec('BEGIN TRANSACTION');
    $success = true;
    foreach ($fieldsToSave as $name) {
        $value = null;
        if ($name === 'save_messages' || $name === 'show_logo' || $name === 'mute_sound') {
            $value = (array_key_exists($name, $settings) && 
                     ($settings[$name] === '1' || $settings[$name] === true || $settings[$name] === 1)) ? '1' : '0';
        } else {
            $value = $settings[$name] ?? '';
        }
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (name, value) VALUES (:name, :value)");
        if (!$stmt) {
            error_log("Err prep {$name}: " . $db->lastErrorMsg());
            $success = false;
            break;
        }
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        if (!$stmt->execute()) {
            error_log("Err exec {$name}: " . $db->lastErrorMsg());
            $success = false;
            break;
        }
        $stmt->close();
    }
    if ($success) {
        $db->exec('COMMIT');
    } else {
        $db->exec('ROLLBACK');
    }
    return $success;
}

function getStylesFromDB($db) {
    $styles = []; $result = $db->query("SELECT name, value FROM styles");
    if ($result) { while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $styles[$row['name']] = $row['value']; } }
    else { error_log("Err query getStyles: ".$db->lastErrorMsg()); } return $styles;
}
function saveStylesToDB($db, $styles) {
    $db->exec('BEGIN TRANSACTION'); $success = true;
    $stmt = $db->prepare("UPDATE styles SET value = :value WHERE name = :name");
    if (!$stmt) { error_log("Err prep UPDATE styles: ".$db->lastErrorMsg()); $db->exec('ROLLBACK'); return false; }
    foreach ($styles as $name => $value) {
        $trimmedValue = trim($value);
        if (!empty($trimmedValue) && !preg_match('/^#[a-f0-9]{6}$/i', $trimmedValue)) { continue; }
        $stmt->bindValue(':name', $name, SQLITE3_TEXT); $stmt->bindValue(':value', $trimmedValue, SQLITE3_TEXT);
        if (!$stmt->execute()) { error_log("Err exec UPDATE styles {$name}: ".$db->lastErrorMsg()); $success = false; break; }
        $stmt->reset();
    } $stmt->close();
    if ($success) { $db->exec('COMMIT'); } else { $db->exec('ROLLBACK'); } return $success;
}
function getMessagesFromDB($db) {
    $messages = []; $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='conversazioni'");
    if ($tableCheck) {
        $result = $db->query("SELECT id, conversation_id, ip, timestamp, sender, message FROM conversazioni ORDER BY timestamp DESC");
        if ($result) { while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $row['conversation_id'] = $row['conversation_id'] ?? 'N/D'; $messages[] = $row; } }
        else { error_log("Err query getMessages: ".$db->lastErrorMsg()); }
    } return $messages;
}
function deleteAllMessages($db) {
    $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='conversazioni'");
    if ($tableCheck) {
        $db->exec('BEGIN TRANSACTION'); $deleteResult = $db->exec("DELETE FROM conversazioni");
        if ($deleteResult) { $db->exec('COMMIT'); return true; }
        else { error_log("Err exec DELETE conversazioni: ".$db->lastErrorMsg()); $db->exec('ROLLBACK'); return false; }
    } return true;
}

/* =============================================
   FUNZIONI GESTIONE UPLOAD
   ============================================= */
function handleImageUpload($fileData, $imageType) { /* ... Logica upload immagine (invariata, usa JPG)... */
       $targetDir = __DIR__ . '/images/';
       $imageConfig = [
           'open_icon'   => ['filename' => 'icon-open.png', 'allowed_mime' => ['image/png', 'image/gif', 'image/jpeg']],
           'close_icon'  => ['filename' => 'icon-closed.gif', 'allowed_mime' => ['image/gif', 'image/png', 'image/jpeg']],
           'bot_avatar'  => ['filename' => 'icon-bot.png', 'allowed_mime' => ['image/png', 'image/jpeg']],
           'user_avatar' => ['filename' => 'icon-user.png', 'allowed_mime' => ['image/png', 'image/jpeg']],
           'logo'        => ['filename' => 'logo.png', 'allowed_mime' => ['image/png', 'image/jpeg']],
       ];
       $maxFileSize = 2 * 1024 * 1024;
       if (!isset($imageConfig[$imageType])) { return ['status' => 'error', 'message' => 'Tipo immagine non valido.']; }
       $config = $imageConfig[$imageType]; $targetFilename = $config['filename']; $allowedMimeTypes = $config['allowed_mime'];
       if (!isset($fileData['error'], $fileData['tmp_name'], $fileData['size']) || is_array($fileData['error'])) { return ['status' => 'error', 'message' => 'Parametri file non validi.']; }
       if ($fileData['error'] !== UPLOAD_ERR_OK) { $errors = [1=>'Ini size', 2=>'Form size', 3=>'Partial', 4=>'No file', 6=>'No tmp dir', 7=>'Cant write', 8=>'Extension']; return ['status' => 'error', 'message' => $errors[$fileData['error']] ?? 'Errore upload sconosciuto.']; }
       if ($fileData['size'] === 0) { return ['status' => 'error', 'message' => 'File vuoto.']; }
       if ($fileData['size'] > $maxFileSize) { return ['status' => 'error', 'message' => 'File troppo grande (>2MB).']; }
       $finfo = finfo_open(FILEINFO_MIME_TYPE); if (!$finfo) { return ['status' => 'error', 'message' => 'Errore server (finfo).']; }
       $mimeType = finfo_file($finfo, $fileData['tmp_name']); finfo_close($finfo);
       if ($mimeType === false || !in_array($mimeType, $allowedMimeTypes)) { return ['status' => 'error', 'message' => 'Tipo file non consentito (MIME: '.escape($mimeType).').']; }
       if (!is_dir($targetDir)) { if (!mkdir($targetDir, 0755, true)) { return ['status' => 'error', 'message' => 'Errore creazione dir images.']; } }
       if (!is_writable($targetDir)) { return ['status' => 'error', 'message' => 'Errore permessi dir images.']; }
       $targetPath = rtrim($targetDir, '/') . '/' . $targetFilename;
       if (move_uploaded_file($fileData['tmp_name'], $targetPath)) { return ['status' => 'success', 'message' => 'Immagine caricata!', 'newImageUrl' => 'images/' . $targetFilename . '?v=' . time()]; }
       else { error_log("move_uploaded_file fallito immagine: ".print_r(error_get_last(), true)); return ['status' => 'error', 'message' => 'Errore salvataggio immagine.']; }
}
function handleSoundUpload($fileData) { /* ... Logica upload suono (invariata)... */
        $targetDir = __DIR__ . '/sounds/'; $targetFilename = 'notification.mp3';
        $targetPath = rtrim($targetDir, '/') . '/' . $targetFilename; $allowedMimeTypes = ['audio/mpeg', 'audio/mp3']; $maxFileSize = 2*1024*1024;
        if (!isset($fileData['error'], $fileData['tmp_name'], $fileData['size']) || is_array($fileData['error'])) { return ['status' => 'error', 'message' => 'Parametri file audio non validi.']; }
        if ($fileData['error'] !== UPLOAD_ERR_OK) { $errors = [1=>'Ini size', 2=>'Form size', 3=>'Partial', 4=>'No file', 6=>'No tmp dir', 7=>'Cant write', 8=>'Extension']; return ['status' => 'error', 'message' => $errors[$fileData['error']] ?? 'Errore upload audio.']; }
        if ($fileData['size'] === 0) { return ['status' => 'error', 'message' => 'File audio vuoto.']; }
        if ($fileData['size'] > $maxFileSize) { return ['status' => 'error', 'message' => 'File audio >2MB.']; }
        $finfo = finfo_open(FILEINFO_MIME_TYPE); if (!$finfo) { return ['status' => 'error', 'message' => 'Errore server (finfo audio).']; }
        $mimeType = finfo_file($finfo, $fileData['tmp_name']); finfo_close($finfo);
        if ($mimeType === false || !in_array($mimeType, $allowedMimeTypes)) { return ['status' => 'error', 'message' => 'Tipo file audio non consentito (MIME: '.escape($mimeType).').']; }
        if (!is_dir($targetDir)) { if (!mkdir($targetDir, 0755, true)) { return ['status' => 'error', 'message' => 'Errore creazione dir sounds.']; } }
        if (!is_writable($targetDir)) { return ['status' => 'error', 'message' => 'Errore permessi dir sounds.']; }
        if (file_exists($targetPath) && !is_writable($targetPath)) { return ['status' => 'error', 'message' => 'Errore: file audio esistente non sovrascrivibile.']; }
        if (move_uploaded_file($fileData['tmp_name'], $targetPath)) { return ['status' => 'success', 'message' => 'Suono caricato!', 'newSoundUrl' => 'sounds/' . $targetFilename . '?v=' . time()]; }
        else { error_log("move_uploaded_file fallito suono: ".print_r(error_get_last(), true)); return ['status' => 'error', 'message' => 'Errore salvataggio suono.']; }
}

/* =============================================
   GESTORE RICHIESTE API (AJAX)
   ============================================= */
function handleApiRequest() {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['status' => 'error', 'message' => 'Azione non specificata.'];
    $db = null; $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $actionsRequiringDb = ['get_settings', 'save_settings', 'delete_messages', 'get_messages', 'get_styles', 'save_styles'];

    if (in_array($action, $actionsRequiringDb)) {
        try {
            $dbPath = __DIR__ . '/db.db';
            if (!file_exists(dirname($dbPath)) || (!file_exists($dbPath) && !is_writable(dirname($dbPath))) || (file_exists($dbPath) && !is_writable($dbPath))) { throw new Exception("DB path error: ".$dbPath); }
            $db = new SQLite3($dbPath); $db->exec('PRAGMA journal_mode = WAL;');
        } catch (Exception $e) { http_response_code(500); error_log("DB Conn Err API: ".$e->getMessage()); echo json_encode(['status'=>'error','message'=>'DB Server Error.']); exit; }
    }

    switch ($action) {
        case 'get_settings': case 'get_messages': case 'get_styles':
             if ($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);$response['message']='GET required.';break;} if(!$db){http_response_code(500);$response['message']='DB missing for '.$action;break;}
             try { $func = str_replace('get_', 'get', $action).'FromDB'; $data = $func($db); $response=['status'=>'success', str_replace('get_','',$action)=>$data]; }
             catch (Exception $e) { http_response_code(500); $response['message']='Err get data: '.$e->getMessage(); error_log("Err $action: ".$e->getMessage()); } break;
        case 'save_settings': case 'save_styles':
             if ($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);$response['message']='POST required.';break;} if(!$db){http_response_code(500);$response['message']='DB missing for '.$action;break;}
             try { $input=file_get_contents('php://input'); $data=json_decode($input,true); if($data===null||!is_array($data)){http_response_code(400);$response['message']='Invalid JSON.';break;}
                 $func = str_replace('save_', 'save', $action).'ToDB'; $success = $func($db, $data);
                 if($success){$response=['status'=>'success','message'=>ucfirst(str_replace('save_','',$action)).' salvati.'];} else{http_response_code(500);$response['message']='Errore salvataggio '.str_replace('save_','',$action).'.';}
             } catch (Exception $e) { http_response_code(500); $response['message']='Err processing: '.$e->getMessage(); error_log("Err $action: ".$e->getMessage()); } break;
        case 'delete_messages':
             if ($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);$response['message']='POST required.';break;}
             try { $dbDel=new SQLite3(__DIR__.'/db.db'); if($dbDel && deleteAllMessages($dbDel)){$response=['status'=>'success','message'=>'Messaggi eliminati.'];} else{http_response_code(500);$response['message']='Errore eliminazione.';} if($dbDel)$dbDel->close(); }
             catch (Exception $e) { http_response_code(500); $response['message']='Err delete: '.$e->getMessage(); error_log("Err $action: ".$e->getMessage()); } break;
        case 'upload_image':
            if ($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);$response['message']='POST required.';break;}
            if (isset($_FILES['image_upload'],$_POST['image_type'])) { $response=handleImageUpload($_FILES['image_upload'],$_POST['image_type']); if($response['status']==='error'){http_response_code(strpos(strtolower($response['message']),'server')!==false?500:400);} }
            else { http_response_code(400); $response['message']='Dati mancanti img.'; } break;
        case 'upload_sound':
            if ($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);$response['message']='POST required.';break;}
            if (isset($_FILES['sound_upload'])) { $response=handleSoundUpload($_FILES['sound_upload']); if($response['status']==='error'){http_response_code(strpos(strtolower($response['message']),'server')!==false?500:400);} }
            else { http_response_code(400); $response['message']='Dati mancanti snd.'; } break;
        default: http_response_code(400); $response['message']='Azione non nota: '.escape($action);
    }
    if ($db) { $db->close(); } echo json_encode($response); exit;
}

/* =============================================
   RENDERING PAGINA ADMIN HTML
   ============================================= */
function renderAdminPage() {
    $db = null;
    try {
        $db = new SQLite3(__DIR__ . '/db.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE); // Assicura creazione se non esiste
        $db->exec('PRAGMA journal_mode = WAL;');
    } catch (Exception $e) { error_log("DB Err Render: ".$e->getMessage()); die("Errore DB: ".escape($e->getMessage())); }
    $settings = getSettingsFromDB($db); $styles = getStylesFromDB($db);
    $notificationSoundUrl = getAssetUrl('notification.mp3', 'sounds');
    if ($db) $db->close();
    $locale = $settings['language'] ?? 'it';
    ?>
<!DOCTYPE html>
<html lang="<?php echo escape($locale); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('Pannello di Controllo'); ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo @filemtime('css/style.css') ?: '1'; ?>">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js" defer></script>
</head>
<body>
    <div id="admin-container">
        <div style="text-align: right; margin-bottom: 15px; font-size: 0.9em;">
            <a href="admin.php?logout=1" style="color: #dc3545; text-decoration: none; font-weight: bold;">[<?php echo escape(__('Logout')); ?>]</a>
        </div>
        <h1 id="admin-header"><?php echo __('Pannello di Controllo'); ?></h1>
        <div class="tabs">
            <button class="tab-button active" data-tab="settings"><?php echo __('Impostazioni'); ?></button>
            <button class="tab-button" data-tab="messages"><?php echo __('Messaggi'); ?></button>
            <button class="tab-button" data-tab="style"><?php echo __('Stile'); ?></button>
            <button class="tab-button" data-tab="images"><?php echo __('Immagini'); ?></button>
            <button class="tab-button" data-tab="sounds"><?php echo __('Suoni'); ?></button>
            <button class="tab-button" data-tab="guide"><?php echo __('Guida'); ?></button>
            <button class="tab-button" data-tab="license"><?php echo __('Licenza'); ?></button>
        </div>

        <!-- ================== TAB IMPOSTAZIONI ================== -->
        <div id="settings" class="tab-content">
            <h2><?php echo __('Impostazioni Chatbot'); ?></h2>
            <div class="form-group">
                <label for="language"><?php echo __('Lingua Interfaccia'); ?></label>
                <select name="language" id="language">
                <option value="de_DE" <?php echo ($settings['language'] ?? 'en_US') === 'de_DE' ? ' selected' : ''; ?>>Deutsch</option>
                <option value="en_US" <?php echo ($settings['language'] ?? 'en_US') === 'en_US' ? ' selected' : ''; ?>>English</option>
                <option value="es_ES" <?php echo ($settings['language'] ?? 'en_US') === 'es_ES' ? ' selected' : ''; ?>>Español</option>
                <option value="fr_FR" <?php echo ($settings['language'] ?? 'en_US') === 'fr_FR' ? ' selected' : ''; ?>>Français</option>
                <option value="it" <?php echo ($settings['language'] ?? 'en_US') === 'it' ? ' selected' : ''; ?>>Italiano</option>
                <option value="pt_BR" <?php echo ($settings['language'] ?? 'en_US') === 'pt_BR' ? ' selected' : ''; ?>>Português</option>
                </select>
            </div>
            <div class="form-group"><label for="chat-title"><?php echo __('Titolo Finestra'); ?></label><input type="text" id="chat-title" name="chat_title" value="<?php echo escape($settings['chat_title']); ?>" placeholder="<?php echo escape(__('Chatbot AI Free Models')); ?>"></div>
            <div class="form-group"><label for="welcome-message"><?php echo __('Messaggio di Benvenuto'); ?></label><input type="text" id="welcome-message" name="welcome_message" value="<?php echo escape($settings['welcome_message']); ?>" placeholder="<?php echo escape(__('Ciao! Come posso aiutarti?')); ?>"></div>
            <div class="form-group"><label><?php echo __('Provider API'); ?></label><div class="api-provider-info"><select disabled><option selected>Openrouter.ai</option></select><a href="https://openrouter.ai/" target="_blank" rel="noopener noreferrer" class="api-key-link"><?php echo escape(__('Ottieni la tua API Key (gratis per modelli free)')); ?></a></div></div>
            <div class="form-group"><label for="api-key"><?php echo __('API Key'); ?></label><input type="text" id="api-key" name="api_key" value="<?php echo escape($settings['api_key'] ?? ''); ?>" placeholder="<?php echo escape(__('Inserisci la tua API Key qui')); ?>"></div>
            <div class="form-group"><label for="api-model"><?php echo __('Modello AI'); ?></label><select id="api-model" name="api_model"><option value="" disabled><?php echo escape(__('Caricamento...')); ?></option></select><p style="font-size:0.9em;color:#666;margin-top:5px;"><?php echo escape(__('Consigliati (Free): Google Gemini Flash, Gemma 3, DeepSeek, Qwen 3, LLama 4 ecc.')); ?></p></div>
            <div class="form-group"><label for="knowledge-base"><?php echo __('Base di Conoscenza'); ?></label><textarea id="knowledge-base" name="knowledge_base" rows="6" placeholder="<?php echo escape(__('Consultare la sezione Guida per suggerimenti e struttura consigliata.')); ?>"  oninput="countChars(this, 'knowledge-chars', 'knowledge-tokens')"><?php echo escape($settings['knowledge_base']); ?></textarea><div><span id="knowledge-chars"></span><span id="knowledge-tokens"></span></div></div>
            <div class="form-group"><label for="bot-rules"><?php echo __('Regole di Comportamento'); ?></label><textarea id="bot-rules" name="bot_rules" rows="6" placeholder="<?php echo escape(__('Consultare la sezione Guida per suggerimenti e struttura consigliata.')); ?>"  oninput="countChars(this, 'rules-chars', 'rules-tokens')"><?php echo escape($settings['bot_rules']); ?></textarea><div><span id="rules-chars"></span><span id="rules-tokens"></span></div></div>
            <button type="button" class="save-button" id="save-settings-button"><?php echo escape(__('Salva Impostazioni')); ?></button>
            <div id="message-area" class="feedback-area"></div>
        </div>

        <!-- ================== TAB MESSAGGI ================== -->
        <div id="messages" class="tab-content" style="display: none;">
            <h2><?php echo __('Gestione Messaggi'); ?></h2>
            <div class="form-group"><label for="save-messages"><input type="checkbox" id="save-messages" name="save_messages" value="1" <?php echo ($settings['save_messages'] ?? '0') === '1' ? ' checked' : ''; ?>> <?php echo escape(__('Salva conversazioni')); ?></label></div>
            <div class="message-actions-controls" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px;">
            <button type="button" class="save-button" id="save-messages-settings-button"><?php echo escape(__('Salva Impostazioni')); ?></button>
            <button type="button" id="delete-messages-button" class="delete-button"><?php echo escape(__('Elimina Messaggi')); ?></button>
                <div class="export-group" style="display: flex; align-items: center; gap: 10px; margin-left: auto;">
                    <label for="export-format" style="margin-bottom: 0; font-weight: 500; font-size: 14px;"><?php echo escape(__('Esporta:')); ?></label>
                    <select id="export-format" style="padding: 8px 10px; height: 38px;"><option value="txt">TXT</option><option value="csv">CSV</option><option value="html" selected>HTML</option><option value="md">MD</option></select>
                    <button type="button" id="export-button" class="save-button" style="padding: 6px 16px; height: 38px; font-size: 14px; background-color: #0d6efd;"><?php echo escape(__('Esporta')); ?></button>
                </div>
            </div>
            <div id="messages-message-area" class="feedback-area" style="margin-top: 15px;"></div>
            <div id="messages-list" style="margin-top: 25px;"><h3><?php echo __('Conversazioni'); ?></h3><div id="messages-content"><p><?php echo escape(__('Caricamento...')); ?></p></div></div>
        </div>

        <!-- ================== TAB STILE ================== -->
        <div id="style" class="tab-content" style="display: none;">
            <h2><?php echo __('Personalizzazione Stile'); ?></h2>
            <form id="style-form" class="style-settings">
                 <div class="style-columns">
                     <div class="style-column">
                         <div class="style-group"><h4><?php echo escape(__('Header')); ?></h4>
                             <div class="form-group"><label><?php echo escape(__('Sfondo')); ?></label><input type="color" id="header_bg_color" value="<?php echo escape($styles['header_bg_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['header_bg_color']??''); ?>" data-for="header_bg_color" pattern="#[a-fA-F0-9]{6}"></div>
                             <div class="form-group"><label><?php echo escape(__('Testo')); ?></label><input type="color" id="header_text_color" value="<?php echo escape($styles['header_text_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['header_text_color']??''); ?>" data-for="header_text_color" pattern="#[a-fA-F0-9]{6}"></div>
                         </div>
                         <div class="style-group"><h4><?php echo escape(__('Area Messaggi')); ?></h4>
                              <div class="form-group"><label><?php echo escape(__('Sfondo')); ?></label><input type="color" id="chat_bg_color" value="<?php echo escape($styles['chat_bg_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['chat_bg_color']??''); ?>" data-for="chat_bg_color" pattern="#[a-fA-F0-9]{6}"></div>
                         </div>
                          <div class="style-group"><h4><?php echo escape(__('Pulsante Invia')); ?></h4>
                             <div class="form-group"><label><?php echo escape(__('Sfondo')); ?></label><input type="color" id="send_button_bg_color" value="<?php echo escape($styles['send_button_bg_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['send_button_bg_color']??''); ?>" data-for="send_button_bg_color" pattern="#[a-fA-F0-9]{6}"></div>
                             <div class="form-group"><label><?php echo escape(__('Testo')); ?></label><input type="color" id="send_button_text_color" value="<?php echo escape($styles['send_button_text_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['send_button_text_color']??''); ?>" data-for="send_button_text_color" pattern="#[a-fA-F0-9]{6}"></div>
                          </div>
                     </div>
                     <div class="style-column">
                          <div class="style-group"><h4><?php echo escape(__('Messaggi Utente')); ?></h4>
                             <div class="form-group"><label><?php echo escape(__('Sfondo')); ?></label><input type="color" id="user_msg_bg_color" value="<?php echo escape($styles['user_msg_bg_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['user_msg_bg_color']??''); ?>" data-for="user_msg_bg_color" pattern="#[a-fA-F0-9]{6}"></div>
                             <div class="form-group"><label><?php echo escape(__('Testo')); ?></label><input type="color" id="user_msg_text_color" value="<?php echo escape($styles['user_msg_text_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['user_msg_text_color']??''); ?>" data-for="user_msg_text_color" pattern="#[a-fA-F0-9]{6}"></div>
                         </div>
                         <div class="style-group"><h4><?php echo escape(__('Messaggi Bot')); ?></h4>
                             <div class="form-group"><label><?php echo escape(__('Sfondo')); ?></label><input type="color" id="bot_msg_bg_color" value="<?php echo escape($styles['bot_msg_bg_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['bot_msg_bg_color']??''); ?>" data-for="bot_msg_bg_color" pattern="#[a-fA-F0-9]{6}"></div>
                             <div class="form-group"><label><?php echo escape(__('Testo')); ?></label><input type="color" id="bot_msg_text_color" value="<?php echo escape($styles['bot_msg_text_color']??''); ?>"><input type="text" class="color-text" value="<?php echo escape($styles['bot_msg_text_color']??''); ?>" data-for="bot_msg_text_color" pattern="#[a-fA-F0-9]{6}"></div>
                         </div>
                     </div>
                 </div>
                <div class="preview-section"><h3><?php echo escape(__('Anteprima')); ?></h3><div class="chat-preview"><div class="preview-header"><span><?php echo escape($settings['chat_title']?:__('Titolo Finestra')); ?></span></div><div class="preview-messages"><div class="preview-message user"><p><?php echo escape(__('Questo è un messaggio di esempio inviato dall\'utente.')); ?></p></div><div class="preview-message bot"><p><?php echo escape(__('Questa è una risposta di esempio generata dal bot.')); ?></p></div></div><div class="preview-input"><input type="text" placeholder="<?php echo escape(__('Scrivi un messaggio...')); ?>" disabled><button type="button" class="preview-send-button" disabled><?php echo escape(__('Invia')); ?></button></div></div></div>
                <div style="margin-top: 25px;"><button type="button" class="save-button" id="save-style-button"><?php echo escape(__('Salva Impostazioni')); ?></button></div>
                <div id="style-message-area" class="feedback-area"></div>
            </form>
        </div>

        <!-- ================== TAB IMMAGINI ================== -->
        <div id="images" class="tab-content" style="display: none;">
            <h2><?php echo __('Gestione Immagini'); ?></h2>
            <p><?php echo escape(__('Carica immagini JPG, PNG, GIF (Max 2MB / 512x512 pixel). Le immagini verranno sovrascritte al termine del caricamento e non sarà necessario salvare.')); ?></p>
            <div class="image-setting-row"><h4 class="image-label"><?php echo escape(__('Icona Aperta')); ?></h4><img id="preview-open_icon" src="<?php echo getAssetUrl('icon-open.png','images'); ?>" alt="Preview" class="image-preview"><input type="file" id="upload-open_icon" accept="image/png,image/gif,image/jpeg,.jpg,.jpeg" style="display: none;" data-image-type="open_icon"><button type="button" class="upload-button" data-input-id="upload-open_icon"><?php echo escape(__('Carica')); ?></button></div>
            <div class="image-setting-row"><h4 class="image-label"><?php echo escape(__('Icona Chiusa')); ?></h4><img id="preview-close_icon" src="<?php echo getAssetUrl('icon-closed.gif','images'); ?>" alt="Preview" class="image-preview"><input type="file" id="upload-close_icon" accept="image/png,image/gif,image/jpeg,.jpg,.jpeg" style="display: none;" data-image-type="close_icon"><button type="button" class="upload-button" data-input-id="upload-close_icon"><?php echo escape(__('Carica')); ?></button></div>
            <div class="image-setting-row"><h4 class="image-label"><?php echo escape(__('Avatar Bot')); ?></h4><img id="preview-bot_avatar" src="<?php echo getAssetUrl('icon-bot.png','images'); ?>" alt="Preview" class="image-preview"><input type="file" id="upload-bot_avatar" accept="image/png,image/jpeg,.jpg,.jpeg" style="display: none;" data-image-type="bot_avatar"><button type="button" class="upload-button" data-input-id="upload-bot_avatar"><?php echo escape(__('Carica')); ?></button></div>
            <div class="image-setting-row"><h4 class="image-label"><?php echo escape(__('Avatar Utente')); ?></h4><img id="preview-user_avatar" src="<?php echo getAssetUrl('icon-user.png','images'); ?>" alt="Preview" class="image-preview"><input type="file" id="upload-user_avatar" accept="image/png,image/jpeg,.jpg,.jpeg" style="display: none;" data-image-type="user_avatar"><button type="button" class="upload-button" data-input-id="upload-user_avatar"><?php echo escape(__('Carica')); ?></button></div>
            <div class="image-setting-row"><h4 class="image-label"><?php echo escape(__('Logo Aziendale')); ?></h4><img id="preview-logo" src="<?php echo getAssetUrl('logo.png','images'); ?>" alt="Logo" class="image-preview"><input type="file" id="upload-logo" accept="image/png,image/jpeg,.jpg,.jpeg" style="display: none;" data-image-type="logo"><button type="button" class="upload-button" data-input-id="upload-logo"><?php echo escape(__('Carica')); ?></button><div class="show-logo-control" style="margin-left: 20px; display: flex; align-items: center; gap: 8px;"><input type="checkbox" id="show-logo-checkbox" name="show_logo" value="1" <?php echo ($settings['show_logo'] ?? '1') === '1' ? ' checked' : ''; ?> style="margin: 0;"><label for="show-logo-checkbox" style="margin:0;font-weight:normal;font-size:14px;cursor:pointer;"><?php echo escape(__('Mostra logo')); ?></label></div></div>
            <div style="margin-top: 25px;"><button type="button" id="save-images-button" class="save-button"><?php echo escape(__('Salva Impostazioni')); ?></button></div>
            <div id="images-message-area" class="feedback-area"></div>
        </div>

    <!-- ================== TAB SUONI ================== --> 
    <div id="sounds" class="tab-content" style="display: none;"> 
    <h2><?php echo __('Gestione Suono di Notifica'); ?></h2> 
    <p><?php echo escape(__('Cambia il suono MP3 (Max 2MB) per l\'apertura della chat. Il file MP3 in uso sarà sovrascritto al termine del caricamento e non sarà necessario salvare.')); ?></p> 
    
    <!-- Checkbox per il Mute -->
    <div class="sound-setting-row" style="display: flex; align-items: center; gap: 15px; padding: 15px 0; border-bottom: 1px solid #eee;">
        <h4 style="margin:0;flex-shrink:0;"><?php echo escape(__('Suono di Notifica:')); ?></h4>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
            <input type="checkbox" id="mute_sound" name="mute_sound" <?php echo ($settings['mute_sound'] === '0' ? 'checked' : ''); ?>>
            <span><?php echo escape(__('Abilita suono di notifica')); ?></span>
        </label>
    </div>
    
    <div class="sound-setting-row" style="display: flex; align-items: center; gap: 15px; padding: 15px 0;"> 
        <h4 style="margin:0;flex-shrink:0;width:150px;"><?php echo escape(__('Suono Attuale:')); ?></h4> 
        <?php if (!empty($notificationSoundUrl)): ?><audio id="sound-preview" controls preload="metadata" style="vertical-align: middle;"><source src="<?php echo escape($notificationSoundUrl); ?>" type="audio/mpeg"><?php echo escape(__('No supporto audio.')); ?></audio><?php else: ?><p style="color:red;margin:0;font-weight:bold;"><?php echo escape(__('File non trovato!')); ?></p><?php endif; ?> 
    </div> 
    <div class="sound-setting-row" style="display: flex; align-items: center; gap: 15px; padding: 15px 0; border-top: 1px solid #eee;"> 
         <h4 style="margin:0;flex-shrink:0;width:150px;"><?php echo escape(__('Carica Nuovo:')); ?></h4> 
         <input type="file" id="upload-sound" accept=".mp3,audio/mpeg" style="display: none;" data-sound-type="notification_sound"> 
         <button type="button" class="upload-button" data-input-id="upload-sound"><?php echo escape(__('Carica MP3...')); ?></button> 
         <span style="font-size:0.9em;color:#666;"><?php echo escape(__('(Max 2MB)')); ?></span> 
    </div> 
    <div style="margin-top: 25px;"><button type="button" id="save-sounds-button" class="save-button" disabled title="<?php echo escape(__('Upload automatico')); ?>"><?php echo escape(__('Salva Impostazioni')); ?></button></div> 
    <div id="sounds-message-area" class="feedback-area"></div> 
    </div>

        <!-- ================== TAB GUIDA ================== -->
        <!-- Tab Guida -->
        <div id="guide" class="tab-content" style="display: none;">
            <h2><?php echo __('Guida Rapida e Integrazione'); ?></h2>

             <h4><?php echo escape(__('Come Inserire la Chat nel Tuo Sito Web')); ?></h4>
             <p><?php echo escape(__('Per aggiungere questo chatbot a qualsiasi pagina del tuo sito web (indipendentemente dalla piattaforma: HTML statico, PHP, CMS diversi da WordPress, ecc.), devi includere un singolo script JavaScript nel codice HTML della pagina, preferibilmente prima della chiusura del tag </body>. Per Wordpress puoi facilmente installare il plugin di queso Chatbot che trovi qui:'));?> <a href="https://wordpress.org/plugins/chatbot-ai-free-models/" target="_blank" rel="noopener noreferrer"><b>https://wordpress.org/plugins/chatbot-ai-free-models/</b></a></p>
             <p><?php echo escape(__('Copia e incolla la seguente riga di codice nella tua pagina HTML:')); ?></p>
             <pre><code><?php $script_tag = __('<script src="/percorso/alla/cartella/chatbot/embed.js" defer></script>'); echo escape($script_tag); // Usa la funzione escape() che equivale a htmlspecialchars ?></code></pre>
             <p><strong><?php echo escape(__('Importante:')); ?></strong></p>
             <ul>
                 <li><?php echo escape(__('Sostituisci "/percorso/alla/cartella/chatbot/" con il percorso URL effettivo in cui hai caricato la cartella contenente i file del chatbot (chatbot.php, embed.js, style.css, ecc.) sul tuo server.')); ?><br>
                 <?php echo escape(__('Ad esempio, potrebbe essere "/chatbot-x/" o semplicemente "/" se è nella root.')); ?></li>
                 <li><?php echo escape(__('L\'attributo `defer` è consigliato perché permette alla pagina HTML di caricarsi prima di eseguire lo script, migliorando le prestazioni percepite.')); ?></li>
             </ul>
             <p><?php echo escape(__('Una volta aggiunto questo script, il file `embed.js` creerà automaticamente il pulsante flottante e la finestra della chat (in un iframe) sulla pagina. Il chatbot userà le impostazioni e gli stili definiti in questo pannello di amministrazione.')); ?></p>

              <h4><?php echo escape(__('Funzionamento Base')); ?></h4>
              <p><?php echo escape(__('Il chatbot utilizza le informazioni inserite in "Base di Conoscenza" e le "Regole di Comportamento" per costruire un prompt di sistema. Questo prompt viene inviato, insieme alla cronologia della conversazione corrente (se abilitata e funzionante) e al messaggio dell\'utente, all\'API di OpenRouter.ai per generare una risposta. Assicurati di avere una API Key valida e di selezionare un modello compatibile.')); ?></p>


                     <!-- NUOVA SEZIONE PER TEMPLATE KB E REGOLE -->
                     <hr style="margin: 25px 0;"> <!-- Separatore visivo -->
                     <h4><?php echo escape(__('Template Suggeriti per Impostazioni')); // Già corretto ?></h4>
                     <p><?php echo escape(__('Puoi usare i seguenti testi come punto di partenza. Copiali e incollali nei campi corrispondenti nella tab "Impostazioni", adattandoli poi alle tue specifiche esigenze.')); // Già corretto ?></p>
                     <h4><?php echo escape(__('Template Base di Conoscenza')); ?></h4>
                     <div> <?php // Semplice div contenitore ?>
                         <p><strong><?php echo escape(__('Informazioni Essenziali sul Tuo Sito/Blog:')); ?></strong></p>
                         <ul>
                             <li><strong><?php echo escape(__('Nome del Sito:')); ?></strong> <?php echo escape(__('[Inserisci il nome del tuo sito/blog qui]')); ?></li>
                             <li><strong><?php echo escape(__('Argomento Principale:')); ?></strong> <?php echo escape(__('[Descrivi brevemente di cosa tratta il tuo sito. Es: Blog di cucina vegetariana, Sito di recensioni tech, E-commerce di prodotti artigianali]')); ?></li>
                             <li><strong><?php echo escape(__('Obiettivo del Sito:')); ?></strong> <?php echo escape(__('[Qual è lo scopo principale? Es: Informare, Vendere prodotti, Offrire servizi, Creare una community]')); ?></li>
                             <li><strong><?php echo escape(__('Pubblico di Riferimento:')); ?></strong> <?php echo escape(__('[A chi ti rivolgi principalmente? Es: Principianti di cucina, Appassionati di tecnologia, Amanti del fai-da-te]')); ?></li>
                             <li><strong><?php echo escape(__('Prodotti/Servizi Chiave (se applicabile):')); ?></strong> <?php echo escape(__('[Elenca brevemente i tuoi prodotti o servizi principali. Es: Corsi di cucina online, Guide all\'acquisto smartphone, Oggetti in ceramica fatti a mano]')); ?></li>
                             <li><strong><?php echo escape(__('Contatti:')); ?></strong> <?php echo escape(__('[Come possono contattarti gli utenti? Es: Pagina Contatti sul sito, Email: tua@email.com, Numero di telefono (se appropriato)]')); ?></li>
                             <li><strong><?php echo escape(__('Informazioni Uniche/Importanti:')); ?></strong> <?php echo escape(__('[Ci sono regole specifiche, orari, valori aziendali o informazioni particolari che il bot deve conoscere? Es: Spedizioni solo in Italia, Non trattiamo argomenti X, Siamo aperti dal Lunedì al Venerdì 9-18]')); ?></li>
                         </ul>
                         <p><em><?php echo escape(__('(SUGGERIMENTO: Sii specifico ma conciso. Più informazioni rilevanti fornisci, migliori saranno le risposte del bot. Rimuovi le parentesi quadre e sostituisci il testo.)')); ?></em></p>
                     </div>
                     <?php /* Non serve pulsante copia qui, l'utente copia l'HTML se vuole, ma è meno utile per la textarea */ ?>
                     <h4><?php echo escape(__('Template Comportamento del Bot')); ?></h4>
                     <div>
                     <div> <?php // Semplice div contenitore ?>
                         <p><strong><?php echo escape(__('Istruzioni per il Comportamento del Bot:')); ?></strong></p>
                         <ul>
                             <li><strong><?php echo escape(__('Personalità/Ruolo:')); ?></strong> <?php echo escape(__('Sei un assistente virtuale amichevole e disponibile per il sito [Nome del Tuo Sito]. Il tuo obiettivo è aiutare gli utenti a trovare informazioni presenti nella "Base di Conoscenza" fornita e rispondere alle loro domande in modo chiaro e pertinente.')); ?></li>
                             <li><strong><?php echo escape(__('Tono:')); ?></strong> <?php echo escape(__('Mantieni un tono cordiale, professionale e positivo. Usa un linguaggio semplice e comprensibile.')); ?></li>
                             <li><strong><?php echo escape(__('Stile:')); ?></strong> <?php echo escape(__('Rispondi in modo diretto e vai dritto al punto, ma senza sembrare sbrigativo. Usa paragrafi brevi e, se appropriato, elenchi puntati per migliorare la leggibilità. Dai del "tu" all\'utente.')); ?></li>
                             <li><strong><?php echo escape(__('Base di Conoscenza:')); ?></strong> <?php echo escape(__('Basa le tue risposte ESCLUSIVAMENTE sulle informazioni fornite nella sezione "Informazioni per le risposte". Se una domanda riguarda argomenti non presenti lì, indica gentilmente che non hai quell\'informazione specifica e suggerisci come l\'utente può trovarla (es. "Non ho dettagli su questo argomento specifico, ma potresti trovare utile visitare la nostra pagina [Nome Pagina Rilevante] o contattarci direttamente tramite [Metodo di Contatto]").')); ?></li>
                             <li><strong><?php echo escape(__('Cosa NON Fare:')); ?></strong>
                                 <ul>
                                     <li><?php echo escape(__('Non inventare informazioni o risposte.')); ?></li>
                                     <li><?php echo escape(__('Non esprimere opinioni personali.')); ?></li>
                                     <li><?php echo escape(__('Non rispondere a domande offensive, inappropriate o palesemente fuori tema rispetto allo scopo del sito. In questi casi, rispondi educatamente che non puoi gestire quel tipo di richiesta.')); ?></li>
                                     <li><?php echo escape(__('Non chiedere informazioni personali sensibili agli utenti (password, numeri di carta di credito, ecc.).')); ?></li>
                                 </ul>
                             </li>
                             <li><strong><?php echo escape(__('Gestione Domande Complesse/Ambigue:')); ?></strong> <?php echo escape(__('Se una domanda non è chiara, chiedi gentilmente all\'utente di riformularla o fornire maggiori dettagli.')); ?></li>
                             <li><strong><?php echo escape(__('Lingua:')); ?></strong> <?php echo escape(__('Rispondi sempre in italiano, a meno che l\'utente non scriva chiaramente in un\'altra lingua (in quel caso, se possibile, rispondi in quella lingua mantenendo queste regole).')); ?></li>
                         </ul>
                         <p><em><?php echo escape(__('(SUGGERIMENTO: Adatta queste regole al tuo caso specifico. Puoi renderlo più formale, più tecnico, più spiritoso, ecc. Ricorda di sostituire "[Nome del Tuo Sito]" e "[Metodo di Contatto]").')); ?></em></p>
                     </div>

              <hr style="margin: 25px 0;">
              <h4><?php echo escape(__('Risoluzione Problemi Comuni')); ?></h4>
              <ul>
                  <li><strong><?php echo escape(__('Il chatbot non appare sulla pagina:')); ?></strong> <?php echo escape(__('Verifica che il percorso nello script `<script src="...">` sia corretto e che il file `embed.js` sia caricato correttamente (controlla la console del browser per errori 404). Assicurati che non ci siano errori JavaScript gravi nella console che potrebbero bloccare l\'esecuzione di `embed.js`.')); ?></li>
                  <li><strong><?php echo escape(__('Il chatbot non risponde:')); ?></strong> <?php echo escape(__('Verifica che l\'API Key sia corretta, che il modello selezionato sia valido e attivo su OpenRouter, e che il tuo account OpenRouter abbia crediti sufficienti (se usi modelli a pagamento). Controlla la console del browser (tasto F12) per eventuali errori JavaScript durante l\'invio del messaggio. Controlla i log di errore PHP del server per problemi in `chatbot.php`.')); ?></li>
                  <li><strong><?php echo escape(__('Le immagini/suoni non si aggiornano o non appaiono:')); ?></strong> <?php echo escape(__('Assicurati che le cartelle `/images` e `/sounds` esistano nella directory del chatbot e abbiano i permessi di scrittura corretti per il server web. Prova a svuotare la cache del browser (hard refresh). Verifica che i percorsi usati in `chatbot.js` e `admin.php` per accedere a queste risorse siano corretti rispetto alla root del sito.')); ?></li>
                  <li><strong><?php echo escape(__('Le modifiche allo stile non appaiono:')); ?></strong> <?php echo escape(__('Svuota la cache del browser (hard refresh CTRL+F5 o CMD+SHIFT+R). Verifica che non ci siano regole CSS del tuo sito principale che sovrascrivono gli stili del chatbot con `!important` (anche se l\'iframe dovrebbe isolare abbastanza).')); ?></li>
                  <li><strong><?php echo escape(__('Reset generale:')); ?></strong> <?php echo escape(__('Se per qualsiasi ragione vorresti tornare alle impostazioni di default, ti basterà eliminare il file del database che trovi nella directory principale del chatbot, è l\'unico con estensione .db, se hai cambiato immagini e suoni, questi non saranno ripristinati, dovresti ricaricarli.')); ?></li>
              </ul>
              <p><?php echo escape(__('Per supporto aggiuntivo, visita il sito dello sviluppatore')); ?> <a href="https://newcodebyte.altervista.org" target="_blank" rel="noopener noreferrer"><b>https://newcodebyte.altervista.org</b></a> <?php echo escape(__(' o consulta la documentazione completa (se disponibile).')); ?></p>
        </div> 

    </div> <!-- Fine admin-container -->

        <!-- ================== TAB LICENZA (Versione HTML Semplice) ================== -->
        <div id="license" class="tab-content" style="display: none;">
            <h2><?php echo escape(__('Licenza e Termini di Utilizzo')); ?></h2>
            <p><?php echo escape(__('Si prega di leggere attentamente i termini sotto cui questo software viene fornito.')); ?></p>
            <hr>
            <p><strong><?php echo escape(__('Licenza Personalizzata - Uso Gratuito con Link di Attribuzione')); ?></strong></p>
            <p><strong><?php echo escape('Copyright (c) 2025 NewCodeByte'); ?></strong></p>
            <p><?php echo escape(__('Il permesso è concesso, gratuitamente, a chiunque ottenga una copia di questo software e dei file di documentazione associati (il "Software"), di utilizzare, copiare, modificare, unire, pubblicare e distribuire il Software per qualsiasi scopo, inclusi scopi commerciali, alle seguenti condizioni:')); ?></p>
            <p><?php echo escape(__('1. Un link visibile a')); ?> <a href="https://newcodebyte.altervista.org" target="_blank" rel="noopener noreferrer"><b>https://newcodebyte.altervista.org</b></a> <?php echo escape(__('deve essere mantenuto intatto nel footer dell\'interfaccia del chatbot in ogni momento.')); ?></p>
            <p><?php echo escape(__('2. Il link nel footer può essere rimosso solo dopo una donazione una tantum di almeno $9.90 USD (')); ?><a href="https://buymeacoffee.com/codebytewp" target="_blank" rel="noopener noreferrer"><b>https://buymeacoffee.com/codebytewp</b></a><?php echo escape(__('). La rimozione non autorizzata del link nel footer costituisce una violazione di questa licenza.')); ?></p>
            <p><?php echo escape(__('3. Il Software è fornito "così com\'è", senza garanzie di alcun tipo, esplicite o implicite. L\'autore non sarà responsabile per qualsiasi reclamo, danno o altra responsabilità derivante dall\'uso del Software.')); ?></p>
            <p><?php echo escape(__('4. Questa licenza deve essere inclusa in tutte le copie o porzioni sostanziali del Software.')); ?></p>
            <p><strong><?php echo escape(__('Opzionale:')); ?></strong></p>
            <p><?php echo escape(__('- Per rimuovere legalmente il link nel footer, visita')); ?> <a href="https://newcodebyte.altervista.org" target="_blank" rel="noopener noreferrer"><b>https://newcodebyte.altervista.org</b></a> <?php echo escape(__('o contatta')); ?> <a href="mailto:software_on_demand@yahoo.it"><b>software_on_demand@yahoo.it</b></a>.</p>
            <p><strong><?php echo escape(__('Grazie per rispettare il lavoro dell\'autore.')); ?></strong></p>
            <hr>
            <p style="margin-top: 20px;">
                <small>
                <?php echo escape(__('Per una copia del testo ufficiale della licenza, fare riferimento al file LICENSE.txt nella directory principale del plugin.')); ?>
                </small>
            </p>
        </div>
        <!-- ================== FINE TAB LICENZA ================== -->

    <!-- Script JS Conteggio Caratteri -->
    <script>
        function countChars(textarea, charId, tokenId) { if (!textarea) return; const c = document.getElementById(charId), t = document.getElementById(tokenId); if (!c || !t) return; const txt = textarea.value || '', len = txt.length, est = Math.round(len / 3.8); c.textContent = `<?php echo escape(__('Caratteri:')); ?> ${len}`; t.textContent = `<?php echo escape(__(' / Tokens:')); ?> ${est}`; }
        document.addEventListener('DOMContentLoaded', function() {
             const kb = document.getElementById('knowledge-base'), rules = document.getElementById('bot-rules');
             if(kb) countChars(kb, 'knowledge-chars', 'knowledge-tokens'); if(rules) countChars(rules, 'rules-chars', 'rules-tokens');
             // Copy embed code button
             const copyBtn = document.getElementById('copy-embed-code'); const codeEl = document.getElementById('embed-script-code');
             if(copyBtn && codeEl) { copyBtn.addEventListener('click', () => { navigator.clipboard.writeText(codeEl.textContent || '').then(() => alert('Codice copiato!'), () => alert('Errore copia.')); }); }
        });
    </script>
    <!-- Script JS Principale Admin -->
    <script src="js/admin.js?v=<?php echo @filemtime('js/admin.js') ?: '1'; ?>" defer></script>
</body>
</html>
<?php
} // Fine renderAdminPage

/* =============================================
   ROUTING PRINCIPALE
   ============================================= */
if (isset($_REQUEST['action']) && !empty($_REQUEST['action'])) { handleApiRequest(); }
else { renderAdminPage(); }
?>