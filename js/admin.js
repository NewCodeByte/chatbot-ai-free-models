document.addEventListener('DOMContentLoaded', function () {
    // --- Selettori Elementi DOM ---
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    const saveSettingsBtn = document.getElementById('save-settings-button');
    const saveMessagesSettingsBtn = document.getElementById('save-messages-settings-button');
    const saveStyleBtn = document.getElementById('save-style-button');
    const deleteMessagesButton = document.getElementById('delete-messages-button');
    const messageArea = document.getElementById('message-area');
    const messagesMessageArea = document.getElementById('messages-message-area');
    const styleMessageArea = document.getElementById('style-message-area');
    const saveImagesBtn = document.getElementById('save-images-button');
    const imagesMessageArea = document.getElementById('images-message-area');
    const imageUploadInputs = document.querySelectorAll('#images input[type="file"]');
    const imageUploadButtons = document.querySelectorAll('#images .upload-button');
    const apiModelSelect = document.getElementById('api-model');
    const apiKeyInput = document.getElementById('api-key');
    const soundUploadInput = document.getElementById('upload-sound');
    const soundUploadButton = document.querySelector('#sounds .upload-button[data-input-id="upload-sound"]');
    const soundPreviewPlayer = document.getElementById('sound-preview');
    const soundsMessageArea = document.getElementById('sounds-message-area');
    const saveSoundsBtn = document.getElementById('save-sounds-button');
    const showLogoCheckbox = document.getElementById('show-logo-checkbox');
    const exportFormatSelect = document.getElementById('export-format');
    const exportButton = document.getElementById('export-button');

    // --- Funzioni Helper ---
    function showMessage(message, type, areaId) {
        const targetArea = document.getElementById(areaId);
        if (!targetArea) { console.error(`Area msg "${areaId}" non trovata.`); alert(`(${type}): ${message}`); return; }
        targetArea.innerHTML = `<div class="${type === 'success' ? 'success-message' : 'error-message'}">${escapeHtml(message)}</div>`;
        setTimeout(() => { if (targetArea.firstChild) targetArea.removeChild(targetArea.firstChild); }, 5000);
    }

    function escapeHtml(unsafe) {
        const safeString = String(unsafe ?? ''); // Gestisce null/undefined e converte in stringa
        return safeString.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // --- Gestione TAB ---
    tabButtons.forEach(button => {
        button.addEventListener('click', (event) => {
            const tabName = button.dataset.tab;
            if (!tabName) { console.error("Btn tab senza data-tab:", button); return; }
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => { if (content) content.style.display = 'none'; });
            button.classList.add('active');
            const activeTabContent = document.getElementById(tabName);
             if (activeTabContent) {
                 activeTabContent.style.display = 'block';
                 if (tabName === 'messages') loadMessages(); else if (tabName === 'style') loadStyles();
             } else { console.error(`Contenuto tab ID "${tabName}" non trovato.`); }
        });
    });

    // --- Caricamento Dati ---
    async function loadSettings() {
        if(messageArea) messageArea.innerHTML = '';
        try {
            const response = await fetch('admin.php?action=get_settings');
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (data.status === 'success' && data.settings) {
                const s = data.settings; // Abbreviazione
                document.getElementById('language').value = s.language || 'it';
                document.getElementById('chat-title').value = s.chat_title || '';
                document.getElementById('welcome-message').value = s.welcome_message || '';
                if(apiKeyInput) apiKeyInput.value = s.api_key || '';
                document.getElementById('knowledge-base').value = s.knowledge_base || '';
                document.getElementById('bot-rules').value = s.bot_rules || '';
                const saveMsgCb = document.getElementById('save-messages'); if(saveMsgCb) saveMsgCb.checked=s.save_messages==='1';
                if (showLogoCheckbox) showLogoCheckbox.checked = s.show_logo === '1';
                if(apiModelSelect) apiModelSelect.dataset.savedModel = s.api_model || '';
                if (s.api_key && apiModelSelect) loadApiModels(s.api_key); else if (apiModelSelect) apiModelSelect.innerHTML = '<option value="" disabled>Key?</option>';
                setTimeout(() => { // Aggiorna contatori
                    const kb=document.getElementById('knowledge-base'), rules=document.getElementById('bot-rules');
                    if(kb && typeof countChars === 'function') countChars(kb,'knowledge-chars','knowledge-tokens');
                    if(rules && typeof countChars === 'function') countChars(rules,'rules-chars','rules-tokens');
                }, 0);
            } else { showMessage('Errore carico Impostazioni: '+(data.message||'N/D'), 'error', 'message-area'); }
        } catch (e) { showMessage(`Errore rete Impostazioni: ${e.message}`, 'error', 'message-area'); console.error('Err loadSettings:', e); }
    }
    async function loadApiModels(apiKey) {
        if (!apiKey || !apiModelSelect) { if(apiModelSelect) apiModelSelect.innerHTML = '<option value="" disabled>Key?</option>'; return; }
        apiModelSelect.innerHTML = '<option value="" disabled>Load...</option>'; const saved = apiModelSelect.dataset.savedModel || '';
        try {
            const r = await fetch('https://openrouter.ai/api/v1/models');
            if (!r.ok) { let eMsg = `API ${r.status}`; try{const ed=await r.json();eMsg+=`: ${ed.error?.message||r.statusText}`; } catch(e){} throw new Error(eMsg); }
            const d = await r.json();
            if (d?.data?.length) {
                d.data.sort((a,b)=>(a.name||a.id).localeCompare(b.name||b.id)); apiModelSelect.innerHTML = `<option value="" disabled>${d.data.length} modelli</option>`;
                d.data.forEach(m=>{ const o=document.createElement('option'); o.value=m.id; o.textContent=`${m.name||m.id}${m.pricing?.prompt>0?' ($)':' (F)'}`; o.title=`ID: ${m.id}\nP:${m.pricing?.prompt||0} C:${m.pricing?.completion||0}`; apiModelSelect.appendChild(o); });
                if (saved && apiModelSelect.querySelector(`option[value="${escapeHtml(saved)}"]`)) apiModelSelect.value = saved;
                else { apiModelSelect.value = ""; const f=d.data.find(m=>m.pricing?.prompt==0); if(f)apiModelSelect.value=f.id; }
            } else { apiModelSelect.innerHTML = '<option value="" disabled>None.</option>'; }
        } catch (e) { apiModelSelect.innerHTML = '<option value="" disabled>Err</option>'; showMessage(`Err Modelli: ${e.message}`, 'error', 'message-area'); console.error('Err loadModels:', e); }
    }
    async function loadMessages() {
        if(messagesMessageArea) messagesMessageArea.innerHTML = '';
        const messagesContent = document.getElementById('messages-content');
        if (!messagesContent) return;
        messagesContent.innerHTML = ''; // Mostra messaggio di caricamento '<p>Caricamento messaggi...'</p>

        try {
            const response = await fetch('admin.php?action=get_messages');
            if (!response.ok) throw new Error(`Errore HTTP ${response.status}`);
            const data = await response.json();

            messagesContent.innerHTML = '';

            if (data.status === 'success' && data.messages) {
                if (data.messages.length === 0) {
                    messagesContent.innerHTML = ''; // Mostra messaggio di caricamento '<p>Nessun messaggio salvato.</p>'</p>
                    return;
                }

                if (typeof DOMPurify === 'undefined' || typeof marked === 'undefined') {
                    console.warn("DOMPurify o Marked non ancora caricati per rendering messaggi.");
                    messagesContent.innerHTML = '<p>Librerie di rendering in caricamento...</p>';
                    return;
                }

                const table = document.createElement('table');
                // *** MODIFICA: Aggiunta colonna header "Conv. ID" ***
                table.innerHTML = `
                    <thead>
                        <tr>
                            <th style="width: 5%;">ID</th>
                            <th style="width: 20%;">Conv. ID</th>
                            <th style="width: 10%;">IP</th>
                            <th style="width: 15%;">Date</th>
                            <th style="width: 10%;">Sender</th>
                            <th>Messages</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                `;
                // Nota: Le larghezze sono indicative, andrebbero messe in style.css
                // e aggiustate per fare spazio alla nuova colonna.
                const tbody = table.querySelector('tbody');

                data.messages.forEach(message => {
                    let renderedMessage = '';
                    try {
                        const sanitizedMessage = message.message ? DOMPurify.sanitize(message.message) : '';
                        renderedMessage = message.sender === 'Bot' ? marked.parse(sanitizedMessage) : escapeHtml(sanitizedMessage);
                    } catch(e) {
                         console.error("Errore rendering messaggio ID " + message.id + ":", e);
                         renderedMessage = escapeHtml(message.message || '[Errore rendering]');
                    }

                    const row = tbody.insertRow();
                    // *** MODIFICA: Aggiunta cella <td> per conversation_id ***
                    row.innerHTML = `
                        <td>${escapeHtml(String(message.id))}</td>
                        <td class="conversation-id-cell" title="${escapeHtml(String(message.conversation_id))}">
                            ${escapeHtml(String(message.conversation_id).substring(0, 30))}
                        </td>
                        <td>${escapeHtml(String(message.ip))}</td>
                        <td>${escapeHtml(String(message.timestamp))}</td>
                        <td>${message.sender === 'User' ? 'User' : 'Bot'}</td>
                        <td class="message-content-cell">${renderedMessage}</td>
                    `;
                    // Nota: Mostro solo i primi 8 caratteri dell'ID per brevità,
                    // ma l'ID completo è nel title (visibile al passaggio del mouse).
                    // Aggiunta classe 'conversation-id-cell' per stile opzionale.
                });
                messagesContent.appendChild(table);
            } else {
                showMessage('Errore caricamento messaggi: ' + (data.message || 'N/D'), 'error', 'messages-message-area');
            }
        } catch (error) {
            if(messagesContent) messagesContent.innerHTML = '<p>Errore durante il caricamento dei messaggi.</p>';
            showMessage(`Errore rete caricamento messaggi: ${error.message}`, 'error', 'messages-message-area');
            console.error('Errore loadMessages:', error);
        }
    }
    async function loadStyles() {
        if(styleMessageArea) styleMessageArea.innerHTML='';
        try {
            const r = await fetch('admin.php?action=get_styles'); if(!r.ok) throw new Error(`HTTP ${r.status}`); const d=await r.json();
            if(d.status==='success' && d.styles){ Object.entries(d.styles).forEach(([n,v])=>{ const iC=document.getElementById(n),iT=document.querySelector(`.color-text[data-for="${n}"]`); const fb='#000000'; if(iC)iC.value=v||fb; if(iT)iT.value=v||fb; updatePreviewStyle(n,v||fb); }); }
            else { showMessage('Errore Carico Stili: '+(d.message||'N/D'), 'error', 'style-message-area'); }
        } catch (e) { showMessage(`Errore Rete Stili: ${e.message}`, 'error', 'style-message-area'); console.error('Err loadStyles:', e); }
    }

    // --- Salvataggio Dati ---
    async function saveMainSettings(feedbackAreaIdOverride = null) {
        const activeTab = document.querySelector('.tab-button.active')?.dataset?.tab;
        const defaultFeedbackAreaId = activeTab === 'settings' ? 'message-area' : (activeTab === 'images' ? 'images-message-area' : (activeTab === 'messages' ? 'messages-message-area' : 'message-area'));
        const feedbackAreaId = feedbackAreaIdOverride || defaultFeedbackAreaId;
        const feedbackArea = document.getElementById(feedbackAreaId); if (feedbackArea) feedbackArea.innerHTML = 'Salvo...';
        const isSoundEnabledChecked = document.getElementById('mute_sound')?.checked;
        const muteSoundValue = isSoundEnabledChecked ? '0' : '1';
        [messageArea, messagesMessageArea, imagesMessageArea].forEach(a => { if(a && a.id !== feedbackAreaId) a.innerHTML = ''; });

        const settings = {
            language: document.getElementById('language')?.value || 'it', chat_title: document.getElementById('chat-title')?.value || '',
            welcome_message: document.getElementById('welcome-message')?.value || '', api_key: document.getElementById('api-key')?.value || '',
            api_model: document.getElementById('api-model')?.value || '', knowledge_base: document.getElementById('knowledge-base')?.value || '',
            bot_rules: document.getElementById('bot-rules')?.value || '', save_messages: document.getElementById('save-messages')?.checked ? '1' : '0',
            show_logo: showLogoCheckbox?.checked ? '1' : '0',  mute_sound: muteSoundValue
        };

        try {
            const r = await fetch('admin.php?action=save_settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(settings) });
            if (!r.ok) { let err=`HTTP ${r.status}`; try{const d=await r.json();err+=`: ${d.message||'N/D'}`; }catch(e){} throw new Error(err); }
            const d = await r.json(); showMessage( d.status==='success'?'Impostazioni salvate!':'Errore: '+(d.message||'N/D'), d.status, feedbackAreaId );
            if(d.status==='success' && settings.api_key && apiModelSelect) loadApiModels(settings.api_key);
        } catch (e) { showMessage(`Errore Salvataggio: ${e.message}`, 'error', feedbackAreaId); console.error('Err saveMainSettings:', e); if (feedbackArea) feedbackArea.innerHTML = ''; }
    }
    async function saveStyles() {
        if(styleMessageArea) styleMessageArea.innerHTML='Salvo...'; const styles={}; document.querySelectorAll('#style-form input[type="color"]').forEach(i=>{styles[i.id]=i.value;});
        try {
            const r=await fetch('admin.php?action=save_styles',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(styles)});
            if(!r.ok){let e=`HTTP ${r.status}`; try{const d=await r.json();e+=`: ${d.message||'N/D'}`; }catch(x){} throw new Error(e);} const d=await r.json();
            showMessage(d.status==='success'?'Stili salvati!':'Errore: '+(d.message||'N/D'), d.status, 'style-message-area');
        } catch (e) { showMessage(`Errore Salvataggio Stili: ${e.message}`, 'error', 'style-message-area'); console.error('Err saveStyles:', e); if(styleMessageArea) styleMessageArea.innerHTML=''; }
    }
    async function deleteMessages() {
        if (confirm('SICURO? Azione IRREVERSIBILE.')) {
            if(messagesMessageArea) messagesMessageArea.innerHTML='Elimino...';
            try {
                const r=await fetch('admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delete_messages'});
                if(!r.ok){let e=`HTTP ${r.status}`; try{const d=await r.json();e+=`: ${d.message||'N/D'}`; }catch(x){} throw new Error(e);} const d=await r.json();
                showMessage(d.status==='success'?'Messaggi eliminati.':'Errore: '+(d.message||'N/D'), d.status, 'messages-message-area');
                if(d.status==='success') loadMessages();
            } catch (e) { showMessage(`Errore Eliminazione: ${e.message}`, 'error', 'messages-message-area'); console.error('Err deleteMsg:', e); if(messagesMessageArea) messagesMessageArea.innerHTML=''; }
        }
    }

    // --- Aggiornamento Anteprima Stili ---
    function updatePreviewStyle(styleName, value) {
         const p = document.querySelector('.chat-preview'); if (!p) return; const v = value || ''; const el = (s) => p.querySelector(s);
         try { switch (styleName) {
             case 'header_bg_color': el('.preview-header').style.backgroundColor = v; break; case 'header_text_color': el('.preview-header').style.color = v; break;
             case 'chat_bg_color': el('.preview-messages').style.backgroundColor = v; break; case 'user_msg_bg_color': el('.preview-message.user').style.backgroundColor = v; break;
             case 'user_msg_text_color': el('.preview-message.user p').style.color = v; break; case 'bot_msg_bg_color': el('.preview-message.bot').style.backgroundColor = v; break;
             case 'bot_msg_text_color': el('.preview-message.bot p').style.color = v; break; case 'send_button_bg_color': el('.preview-send-button').style.backgroundColor = v; break;
             case 'send_button_text_color': el('.preview-send-button').style.color = v; break;
         }} catch (e) { console.error(`Err preview ${styleName}: ${e.message}`); }
    }

    // --- Gestione Upload (Refactored) ---
    function handleFileSelection(event, uploadFunction, feedbackAreaId) {
        const fileInput = event.target; const file = fileInput.files?.[0]; const dataType = fileInput.dataset.imageType || fileInput.dataset.soundType;
        const previewElementId = `preview-${dataType}`; const previewElement = document.getElementById(previewElementId);
        if (!file || !dataType || (!previewElement && dataType !== 'notification_sound')) { console.error("Dati mancanti handleFileSelection:", fileInput.id); if(fileInput) fileInput.value = ''; return; }
        const allowedMime = (fileInput.accept || '').split(',').map(s => s.trim()).filter(Boolean);
        const isTypeAllowed = allowedMime.length === 0 || allowedMime.some(type => type.endsWith('/*') ? file.type.startsWith(type.slice(0,-2)) : file.type === type );
        if (!isTypeAllowed) { showMessage(`Tipo file non valido (${escapeHtml(file.type)}). Permessi: ${escapeHtml(fileInput.accept)}`, 'error', feedbackAreaId); fileInput.value = ''; return; }
        const maxSizeMB = 2; if (file.size > maxSizeMB * 1024 * 1024) { showMessage(`File >${maxSizeMB}MB.`, 'error', feedbackAreaId); fileInput.value = ''; return; }
        const reader = new FileReader();
        reader.onload = (e) => {
            if (previewElement && previewElement.tagName === 'IMG') previewElement.src = e.target?.result || '';
            else if (previewElement && previewElement.tagName === 'AUDIO') {
                const source = previewElement.querySelector('source') || document.createElement('source'); source.src = e.target?.result || ''; source.type = file.type;
                if (!previewElement.contains(source)) previewElement.appendChild(source); previewElement.load(); showMessage('Anteprima audio aggiornata.', 'success', feedbackAreaId);
            }
        };
        reader.onerror = () => { showMessage('Errore lettura anteprima.', 'error', feedbackAreaId); fileInput.value = ''; }; reader.readAsDataURL(file);
        uploadFunction(file, dataType, previewElement, fileInput);
    }
    async function uploadFile(file, type, previewElement, fileInput, action, formDataKey, feedbackAreaId, typeKey = null) {
        const fArea = document.getElementById(feedbackAreaId); if (fArea) fArea.innerHTML = `Load ${escapeHtml(type)}...`;
        const formData = new FormData(); formData.append('action', action); formData.append(formDataKey, file); if (typeKey) formData.append(typeKey, type);
        try {
            const r = await fetch('admin.php', { method: 'POST', body: formData }); const d = await r.json(); if (!r.ok) throw new Error(d.message || `HTTP ${r.status}`);
            if (d.status === 'success') {
                showMessage(d.message || `${type} caricato!`, 'success', feedbackAreaId); const newUrl = d.newImageUrl || d.newSoundUrl;
                if (newUrl) { const finalUrl = newUrl + '&t=' + Date.now();
                     if (previewElement?.tagName === 'IMG') previewElement.src = finalUrl;
                     else if (previewElement?.tagName === 'AUDIO') { const s = previewElement.querySelector('source') || document.createElement('source'); s.src = finalUrl; s.type = 'audio/mpeg'; if (!previewElement.contains(s)) previewElement.appendChild(s); previewElement.load(); }
                 } fileInput.value = '';
            } else { throw new Error(d.message || 'Errore upload.'); }
        } catch (e) { showMessage(`Errore upload ${escapeHtml(type)}: ${e.message}`, 'error', feedbackAreaId); console.error(`Err ${action} ${escapeHtml(type)}:`, e); fileInput.value = ''; if (fArea) fArea.innerHTML = ''; }
    }
    const uploadImage = (f, t, p, i) => uploadFile(f, t, p, i, 'upload_image', 'image_upload', 'images-message-area', 'image_type');
    const uploadSound = (f, t, p, i) => uploadFile(f, t, p, i, 'upload_sound', 'sound_upload', 'sounds-message-area');

    // --- Event Listeners ---
    document.querySelectorAll('#style-form input[type="color"]').forEach(i => { i.addEventListener('input', e => { const v=e.target.value, n=e.target.id; const t=document.querySelector(`.color-text[data-for="${n}"]`); if(t)t.value=v; updatePreviewStyle(n,v); }); });
    document.querySelectorAll('#style-form .color-text').forEach(i => { i.addEventListener('input', e => { const v=e.target.value, id=e.target.dataset.for; const cI=document.getElementById(id); if (cI && /^#[0-9A-F]{6}$/i.test(v)) { cI.value=v; updatePreviewStyle(id,v); } }); i.addEventListener('blur', e => { if (!/^#[0-9A-F]{6}$/i.test(e.target.value)) { e.target.value='#000000'; const id=e.target.dataset.for; const ci=document.getElementById(id); if(ci)ci.value='#000000'; updatePreviewStyle(id,'#000000'); } }); });
    if (apiKeyInput) apiKeyInput.addEventListener('change', e => { const k=e.target.value.trim(); if (k && apiModelSelect) loadApiModels(k); else if(apiModelSelect) apiModelSelect.innerHTML='<option value="">?</option>'; });
    if (saveSettingsBtn) saveSettingsBtn.addEventListener('click', () => saveMainSettings('message-area'));
    if (saveMessagesSettingsBtn) saveMessagesSettingsBtn.addEventListener('click', () => saveMainSettings('messages-message-area'));
    if (saveStyleBtn) saveStyleBtn.addEventListener('click', saveStyles);
    if (deleteMessagesButton) deleteMessagesButton.addEventListener('click', deleteMessages);
    imageUploadButtons.forEach(b => b.addEventListener('click', () => { const id = b.dataset.inputId; const input = document.getElementById(id); if (input) input.click(); else showMessage('Err: Input mancante.', 'error', 'images-message-area'); }));
    imageUploadInputs.forEach(i => i.addEventListener('change', e => handleFileSelection(e, uploadImage, 'images-message-area')));
    if (saveImagesBtn) { saveImagesBtn.disabled = false; saveImagesBtn.removeAttribute('title'); saveImagesBtn.addEventListener('click', () => saveMainSettings('images-message-area')); } // Chiama saveMainSettings!
    if (soundUploadButton && soundUploadInput) { soundUploadButton.addEventListener('click', () => soundUploadInput.click()); soundUploadInput.addEventListener('change', e => handleFileSelection(e, uploadSound, 'sounds-message-area')); }
    if (saveSoundsBtn) {
        saveSoundsBtn.disabled = false; // Abilita il pulsante
        saveSoundsBtn.removeAttribute('title'); // Rimuovi il titolo "Upload automatico"
        // Aggiungi l'event listener per chiamare saveMainSettings specificando l'area di feedback corretta
        saveSoundsBtn.addEventListener('click', () => saveMainSettings('sounds-message-area'));
    }
    if (exportButton && exportFormatSelect) { exportButton.addEventListener('click', () => { const format = exportFormatSelect.value; if (!format) { showMessage('Seleziona formato.', 'error', 'messages-message-area'); return; } window.location.href = `export.php?format=${encodeURIComponent(format)}`; }); }
    else { console.warn("Elementi export mancanti."); }

    // --- Inizializzazione ---
    loadSettings(); // Carica tutto all'inizio

}); // Fine DOMContentLoaded