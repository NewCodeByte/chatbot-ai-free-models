<?php
// --- Inizio Sessione ---
if (session_status() == PHP_SESSION_NONE) {
    session_start(); // Avvia o riprendi la sessione PRIMA DI QUALSIASI OUTPUT
}

require_once __DIR__ . '/db_init.php';
$db = initDatabase(); // $db conterrà l'oggetto SQLite3 o false

if (!$db) {
    error_log("Fallimento initDatabase() rilevato in: " . $errorContext);
     return false;
}

// --- BLOCCO INTERNAZIONALIZZAZIONE (i18n) ---
$locale_code = 'en_US.UTF-8'; // Default locale, potrebbe essere sovrascritto dal DB
$db_i18n_chatbot = null; // Usa una variabile diversa per evitare conflitti se $db è usato dopo

try {
    $db_path_i18n_chatbot = __DIR__ . '/db.db';
    if (file_exists($db_path_i18n_chatbot)) {
        $db_i18n_chatbot = new SQLite3($db_path_i18n_chatbot, SQLITE3_OPEN_READONLY);
        if ($db_i18n_chatbot) {
            // Aggiungi un controllo se la tabella settings esiste, per robustezza
            $tableCheck = $db_i18n_chatbot->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='settings'");
            if ($tableCheck) {
                $lang_setting = $db_i18n_chatbot->querySingle("SELECT value FROM settings WHERE name = 'language'");
                if ($lang_setting) {
                    $locale_map = [
                        'it'    => 'it_IT.UTF-8',
                        'en_US' => 'en_US.UTF-8',
                        'es_ES' => 'es_ES.UTF-8',
                        'fr_FR' => 'fr_FR.UTF-8',
                        'de_DE' => 'de_DE.UTF-8',
                        'pt_BR' => 'pt_BR.UTF-8',
                    ];
                    $locale_code = $locale_map[$lang_setting] ?? 'en_US.UTF-8';
                }
            } else {
                 error_log("i18n Chatbot.php: Tabella 'settings' non trovata. Uso default locale: " . $locale_code);
            }
            $db_i18n_chatbot->close();
            $db_i18n_chatbot = null;
        } else {
             error_log("i18n Chatbot.php: Impossibile aprire DB per lingua.");
        }
    } else {
         error_log("i18n Chatbot.php: File DB non trovato. Uso default locale: " . $locale_code);
    }
} catch (Exception $e) {
    error_log("Eccezione lettura lingua DB per i18n in Chatbot.php: " . $e->getMessage());
    if ($db_i18n_chatbot) { $db_i18n_chatbot->close(); $db_i18n_chatbot = null; }
}

// Imposta la locale per gettext
$setlocale_result = setlocale(LC_ALL,
    $locale_code,
    str_replace('.UTF-8', '.utf8', $locale_code),
    substr($locale_code, 0, strpos($locale_code, '_'))
);

if ($setlocale_result === false) {
    error_log("Attenzione i18n Chatbot.php: setlocale(LC_ALL, ...) fallito per locale base '$locale_code'.");
}

// Imposta variabili d'ambiente
putenv('LC_ALL=' . $locale_code);
putenv('LANG=' . $locale_code);
putenv('LANGUAGE=' . $locale_code);

// Specifica il percorso e il dominio per gettext
$languages_directory = __DIR__ . '/languages';
$text_domain = 'chatbot'; // Assicurati che sia lo stesso dominio usato in admin.php

bindtextdomain($text_domain, $languages_directory);
bind_textdomain_codeset($text_domain, 'UTF-8');
textdomain($text_domain);
// --- FINE BLOCCO i18n ---

// Funzione di localizzazione placeholder se non definita altrove
if (!function_exists('__')) {
    function __($text) {
        return gettext($text); // O semplicemente return $text;
    }
}

// Funzione helper per sanitizzare l'output HTML (necessaria in renderChatWidget)
if (!function_exists('escape')) {
    function escape($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// Funzione per ottenere un valore di stile con fallback (necessaria in renderChatWidget)
function get_style_value($styles_array, $key, $default) {
    $value = $styles_array[$key] ?? $default;
    if (!empty(trim($value)) && !preg_match('/^#([a-f0-9]{6}|[a-f0-9]{3})$/i', $value)) {
        error_log("Valore stile non valido per {$key}: '{$value}'. Uso default: '{$default}'");
        $value = $default;
    } elseif (empty(trim($value))) {
         $value = $default;
    }
    return escape($value);
}

function getSettings($db) {
    $settings = [];
    $result = $db->query("SELECT name, value FROM settings");
    if ($result) { while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $settings[$row['name']] = $row['value']; } }

    // Testi predefiniti in italiano per fallback
    $knowledge_base_default_text = '';
    $bot_rules_default_text = '';

    // Applica fallback corretti
    $settings['language'] = $settings['language'] ?? 'it';
    $settings['chat_title'] = $settings['chat_title'] ?? 'Chatbot';
    $settings['welcome_message'] = $settings['welcome_message'] ?? 'Ciao! Come posso aiutarti?';
    $settings['save_messages'] = $settings['save_messages'] ?? '0';
    $settings['api_key'] = $settings['api_key'] ?? '';
    $settings['api_model'] = $settings['api_model'] ?? 'google/gemma-3-27b-it:free';
    $settings['knowledge_base'] = (!isset($settings['knowledge_base']) || trim($settings['knowledge_base']) === '') ? trim($knowledge_base_default_text) : $settings['knowledge_base'];
    $settings['bot_rules'] = (!isset($settings['bot_rules']) || trim($settings['bot_rules']) === '') ? trim($bot_rules_default_text) : $settings['bot_rules'];
    $settings['show_logo'] = $settings['show_logo'] ?? '1'; // Default a '1'
    $settings['mute_sound'] = $settings['mute_sound'] ?? '0'; // Default '0' (non muto)

    return $settings;
}

function getStylesFromDB($db) {
    $styles = [];
    $result = $db->query("SELECT name, value FROM styles");
    if ($result) { while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $styles[$row['name']] = $row['value']; } }
    return $styles;
}

function saveConversation($conversationId, $sender, $message) {
    $db = initDatabase();
    if (!$db) {
        error_log("saveConversation: Impossibile inizializzare il DB.");
        return false;
    }
    if (empty(trim($conversationId))) {
        error_log("saveConversation: Tentativo di salvare messaggio con conversation_id vuoto.");
        $db->close();
        return false;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
    $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    $timestamp = date('Y-m-d H:i:s');

    $stmt = $db->prepare("INSERT INTO conversazioni (conversation_id, ip, timestamp, sender, message) VALUES (:conversation_id, :ip, :timestamp, :sender, :message)");
    if (!$stmt) {
        error_log("Errore prepare saveConversation: " . $db->lastErrorMsg());
        $db->close();
        return false;
    }

    $stmt->bindValue(':conversation_id', $conversationId, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    $stmt->bindValue(':sender', $sender, SQLITE3_TEXT);
    $stmt->bindValue(':message', $message, SQLITE3_TEXT);

    $success = $stmt->execute();
    if (!$success) {
        error_log("Errore execute saveConversation: " . $db->lastErrorMsg() . " - ConvID: " . $conversationId);
    }
    $stmt->close();
    $db->close();
    return $success;
}

/* =============================================
   GESTIONE RICHIESTA POST (Invio Messaggio Utente) - CON MEMORIA DI SESSIONE
   ============================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json; charset=utf-8');

    // --- Leggi Impostazioni ---
    $db_settings = initDatabase(); // Connessione temporanea per settings
    if (!$db_settings) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Errore: Impossibile leggere le impostazioni.']);
        exit;
    }
    $settings = getSettings($db_settings);
    $apiKey = $settings['api_key'] ?? '';
    $apiModel = $settings['api_model'] ?? '';
    $knowledgeBase = $settings['knowledge_base'] ?? '';
    $botRules = $settings['bot_rules'] ?? '';
    $saveMessagesToDB = ($settings['save_messages'] ?? '0') === '1';
    $siteTitle = $settings['chat_title'] ?? 'Chatbot';
    $db_settings->close(); // Chiudi connessione settings
    unset($db_settings);   // Rimuovi variabile

    // --- Validazione Input e Impostazioni ---
    if (empty($apiKey) || $apiKey === 'Inserisci la tua API Key qui') {
         http_response_code(400);
         echo json_encode(['status' => 'error', 'message' => 'Errore: API key non configurata.']);
         exit;
    }
    if (empty($apiModel)) {
         http_response_code(400);
         echo json_encode(['status' => 'error', 'message' => 'Errore: Modello AI non configurato.']);
         exit;
    }

    $inputJSON = file_get_contents('php://input');
    $request = json_decode($inputJSON, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($request['message'], $request['conversation_id']) || trim($request['message']) === '' || trim($request['conversation_id']) === '') {
        http_response_code(400);
        error_log("Richiesta POST chatbot invalida: " . $inputJSON);
        echo json_encode(['status' => 'error', 'message' => 'Errore: Richiesta non valida.']);
        exit;
    }
    $userMessage = trim($request['message']);
    $conversationId = trim($request['conversation_id']);

    // --- Gestione Cronologia Sessione (Lettura e Aggiornamento Pre-API) ---
    $session_history_key = 'chatbot_conversation_history';
    $max_history_items = 10; // Max scambi (utente+bot)
    $limit = $max_history_items * 2;

    if (!isset($_SESSION[$session_history_key])) {
        $_SESSION[$session_history_key] = [];
    }
    if (!isset($_SESSION[$session_history_key][$conversationId])) {
        $_SESSION[$session_history_key][$conversationId] = [];
    }

    $current_session_history = $_SESSION[$session_history_key][$conversationId];
    $current_session_history[] = ['role' => 'user', 'content' => $userMessage];
    if (count($current_session_history) > $limit) {
        $current_session_history = array_slice($current_session_history, -$limit);
    }
    $_SESSION[$session_history_key][$conversationId] = $current_session_history;

    // --- Salvataggio DB (Opzionale - Messaggio Utente) ---
    if ($saveMessagesToDB) {
        saveConversation($conversationId, 'User', $userMessage);
    }

// --- Preparazione Dati per API ---
$knowledge_base_intro_string = __("Usa queste informazioni come base di conoscenza:\n"); // Stringa tradotta con newline

$prompt_parts = [];
if (!empty(trim($botRules))) {
    $prompt_parts[] = trim($botRules); // Aggiungi le regole se esistono
}

// Aggiungi l'introduzione e la knowledge base solo se la knowledge base stessa non è vuota.
if (!empty(trim($knowledgeBase))) {
    // Combina l'introduzione tradotta con la knowledge base
    // L'introduzione viene aggiunta solo se c'è effettivamente una knowledge base.
    $prompt_parts[] = $knowledge_base_intro_string . trim($knowledgeBase);
}

// Costruisci il systemPrompt finale
if (!empty($prompt_parts)) {
    // Unisci le parti con due newline, poi fai un trim finale per pulizia
    $systemPrompt = trim(implode("\n\n", $prompt_parts));
} else {
    // Se non ci sono né regole né knowledge base, il prompt è vuoto e si userà il fallback
    $systemPrompt = '';
}

// Fallback se il systemPrompt costruito è ancora vuoto (o lo era dall'inizio)
if (empty(trim($systemPrompt))) {
    $systemPrompt = __('Sei un assistente virtuale utile e cordiale.');
}

    $messagesForApi = [];
    $messagesForApi[] = ['role' => 'system', 'content' => $systemPrompt];
    $messagesForApi = array_merge($messagesForApi, $current_session_history); // Usa cronologia da sessione

    // --- Chiamata API AI ---
    $apiUrl = "https://openrouter.ai/api/v1/chat/completions";
    $siteUrl = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $apiData = [
        'model' => $apiModel,
        'messages' => $messagesForApi,
        'temperature' => 0.7,
        'max_tokens' => 1000,
    ];
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n" .
                        "Authorization: Bearer " . $apiKey . "\r\n" .
                        "HTTP-Referer: " . $siteUrl . "\r\n" .
                        "X-Title: " . $siteTitle . "\r\n",
            'content' => json_encode($apiData),
            'ignore_errors' => true, // Fondamentale per leggere corpo risposta anche su errori 4xx/5xx
            'timeout' => 45 // Aumentato leggermente timeout
        ]
    ];
    $context = stream_context_create($options);
    $response = file_get_contents($apiUrl, false, $context);

    // --- Gestione Risposta API ---
    $httpStatusCode = 500;
    if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0) {
        if (preg_match('{HTTP/\S*\s(\d{3})}', $http_response_header[0], $match)) {
            $httpStatusCode = intval($match[1]);
        }
    }

    $errorOccurred = false;
    $errorMessageToUser = 'Errore sconosciuto dall\'API AI.'; // Default più generico
    $botMessage = '';

    if ($response === false) {
        $errorOccurred = true;
        $phpError = error_get_last();
        $errorMessageToUser = 'Errore interno: Impossibile contattare l\'API AI.';
        error_log("Errore file_get_contents API chatbot: " . ($phpError['message'] ?? 'N/D'));
        $httpStatusCode = 503; // Service Unavailable
    } else {
        $botResponseData = json_decode($response, true);
        $jsonDecodeError = json_last_error();

        if ($httpStatusCode >= 400) {
            $errorOccurred = true;
            if ($jsonDecodeError === JSON_ERROR_NONE && isset($botResponseData['error']['message'])) {
                $apiErrorMsg = $botResponseData['error']['message'];
                $errorMessageToUser = 'Errore API AI (' . $httpStatusCode . '): ' . htmlspecialchars($apiErrorMsg);
                error_log("Errore API OpenRouter ($httpStatusCode) per ID " . $conversationId . ": " . $apiErrorMsg);
            } else {
                $errorMessageToUser = 'Errore API AI (' . $httpStatusCode . ').';
                error_log("Errore API OpenRouter ($httpStatusCode) per ID " . $conversationId . ", risposta non JSON o senza dettaglio: " . substr($response, 0, 500));
            }
            // Mappatura codici specifici per messaggio utente
             if ($httpStatusCode == 401) { $errorMessageToUser = 'Errore: API Key non valida o scaduta.'; }
             if ($httpStatusCode == 429) { $errorMessageToUser = 'Errore: Limite richieste API raggiunto. Riprova più tardi.'; }
             // Potresti aggiungere 400 per modelli non validi, ecc.

        } elseif ($jsonDecodeError !== JSON_ERROR_NONE) {
            $errorOccurred = true;
            $errorMessageToUser = 'Errore interno: Risposta API non leggibile.';
            error_log("Errore decodifica JSON da API (Status: $httpStatusCode) per ID " . $conversationId . ": " . json_last_error_msg() . " - Risposta: " . substr($response, 0, 500));
             $httpStatusCode = 502; // Bad Gateway, la risposta del server upstream non è valida
        } elseif (!isset($botResponseData['choices'][0]['message']['content'])) {
            $errorOccurred = true;
            $finishReason = $botResponseData['choices'][0]['finish_reason'] ?? 'sconosciuto';
            $errorMessageToUser = 'Errore: Risposta API non valida o interrotta (Motivo: ' . htmlspecialchars($finishReason) . ').';
            error_log("Risposta API senza contenuto o terminata inattesa (Reason: $finishReason) per ID " . $conversationId . ": " . substr($response, 0, 500));
             $httpStatusCode = 502; // Bad Gateway
        } else {
            // Successo API
            $botMessage = trim($botResponseData['choices'][0]['message']['content']);
        }
    }

    // --- Gestione Finale e Risposta ---
    if ($errorOccurred) {
        http_response_code($httpStatusCode); // Usa il codice determinato sopra
        echo json_encode(['status' => 'error', 'message' => $errorMessageToUser]);
    } else {
        // Successo: Aggiorna sessione e salva DB (se necessario)

        // Aggiorna sessione con risposta bot
        $final_session_history = $_SESSION[$session_history_key][$conversationId] ?? [];
        $final_session_history[] = ['role' => 'assistant', 'content' => $botMessage];
        if (count($final_session_history) > $limit) {
            $final_session_history = array_slice($final_session_history, -$limit);
        }
        $_SESSION[$session_history_key][$conversationId] = $final_session_history;

        // Salva DB (Opzionale - Risposta Bot)
        if ($saveMessagesToDB) {
            saveConversation($conversationId, 'Bot', $botMessage);
        }

        // Invia risposta di successo
        echo json_encode(['status' => 'success', 'message' => $botMessage]);
    }

    exit; // Termina sempre lo script dopo aver inviato la risposta JSON

} // <-- Fine del blocco if ($_SERVER['REQUEST_METHOD'] === 'POST')

/* =============================================
   RENDERING WIDGET HTML (Richiesta GET)
   ============================================= */

function renderChatWidget() {
    $db = initDatabase();
    if (!$db) {
        header("HTTP/1.1 503 Service Unavailable");
        echo "<html><body>Errore critico: Il servizio chatbot non è disponibile (DB error). Riprovare più tardi.</body></html>";
        exit;
    }

    $settings = getSettings($db);
    $styles = getStylesFromDB($db);
    $chatTitle = $settings['chat_title'];
    $welcomeMessage = $settings['welcome_message'];
    $language = $settings['language'];
    $showLogoEnabled = ($settings['show_logo'] ?? '1') === '1';
    $muteSoundSetting = $settings['mute_sound'] ?? '0'; // Ottieni l'impostazione con fallback
    $db->close();

                // --- Logica per Logo ---
                $logoHtml = '';
                $headerClass = 'chat-header'; // Classe base
                $logoPath = __DIR__ . '/images/logo.png';
                $logoUrl = '';
                if ($showLogoEnabled && file_exists($logoPath)) {
                    // Solo se entrambe vere, genera l'HTML e aggiungi la classe
                    $logoUrl = 'images/logo.png?v=' . filemtime($logoPath);
                    $logoHtml = '<img src="' . escape($logoUrl) . '" alt="Logo" class="chat-header-logo">';
                    $headerClass .= ' header-with-logo';
                }
                // --- Fine Logica Logo ---
    ?>
<!DOCTYPE html>
<html lang="<?php echo escape($language); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($chatTitle); ?></title>    
    <link rel="stylesheet" href="css/style.css?v=<?php echo file_exists(__DIR__ . 'css/style.css') ? filemtime(__DIR__ . 'css/style.css') : '1'; ?>">
    <style>
      :root {
        --header-bg-color: <?php echo get_style_value($styles, 'header_bg_color', '#038AF9'); ?>;
        --header-text-color: <?php echo get_style_value($styles, 'header_text_color', '#FFFFFF'); ?>;
        --chat-bg-color: <?php echo get_style_value($styles, 'chat_bg_color', '#FFFFFF'); ?>;
        --user-msg-bg-color: <?php echo get_style_value($styles, 'user_msg_bg_color', '#ADD8E6'); ?>;
        --user-msg-text-color: <?php echo get_style_value($styles, 'user_msg_text_color', '#000000'); ?>;
        --bot-msg-bg-color: <?php echo get_style_value($styles, 'bot_msg_bg_color', '#FFC0CB'); ?>;
        --bot-msg-text-color: <?php echo get_style_value($styles, 'bot_msg_text_color', '#000000'); ?>;
        --send-button-bg-color: <?php echo get_style_value($styles, 'send_button_bg_color', '#FF0000'); ?>;
        --send-button-text-color: <?php echo get_style_value($styles, 'send_button_text_color', '#FFFFFF'); ?>;        
      }
    </style>
</head>
<body>
    <div id="chatbot-container">
        <div id="chat-window" class="chat-window" role="log" aria-live="polite">
        <div class="<?php echo escape($headerClass); // Usa la classe dinamica ?>">
                 <?php echo $logoHtml; // Stampa il tag <img> del logo se esiste ?>
                <span class="chat-header-title"><?php echo escape($chatTitle); ?></span>
                <button id="close-button" class="close-button" aria-label="<?php echo __('Chiudi chat'); ?>">×</button>
            </div>
            <div class="chat-messages" id="chat-messages"></div>
            <div class="chat-input">
                <input type="text" id="user-input" placeholder="<?php echo escape(__('Scrivi un messaggio...')); ?>" autocomplete="off" aria-label="<?php echo __('Input messaggio utente'); ?>">
                <button id="send-button"><?php echo escape(__('Invia')); ?></button>
            </div>
            <div class="chat-footer">Powered by <a href="https://newcodebyte.altervista.org" target="_blank" rel="noopener">NewCodeByte</a></div>
        </div>
    </div>
    <script>
        window.chatSettings = {
            welcomeMessage: <?php echo json_encode($welcomeMessage); ?>,
            chatTitle: <?php echo json_encode($chatTitle); ?>,
            muteSound: <?php echo json_encode($muteSoundSetting); ?> // Passa '0' o '1'
        };
    </script>
    <script src="js/chatbot.js?v=<?php echo file_exists(__DIR__ . 'js/chatbot.js') ? filemtime(__DIR__ . 'js/chatbot.js') : '1'; ?>" defer></script>
</body>
</html>
    <?php
} // Fine renderChatWidget

// --- ESECUZIONE PRINCIPALE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Già gestito sopra
} else {
    renderChatWidget();
}
?>