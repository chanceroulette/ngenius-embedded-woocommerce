/**
 * N-Genius Embedded — Frontend checkout integration
 *
 * Si attiva solo sulla pagina checkout di WooCommerce.
 * Intercetta il "Place order" quando il metodo è "ngenius_embedded"
 * e mostra un modal con il form carta embedded (iframe N-Genius).
 *
 * Variabile globale richiesta: window.NgeniusEmbeddedConfig
 * (popolata server-side via wp_localize_script)
 *   {
 *     ajaxUrl:        '/wp-admin/admin-ajax.php',
 *     nonce:          '<random>',
 *     sdkUrl:         'https://paypage.ngenius-payments.com/hosted-sessions/sdk.js',
 *     hostedSessionApiKey: '<key from settings>',
 *     outletRef:      '<uuid>',
 *     embeddedMode:   true|false,
 *     debug:          true|false,
 *   }
 */

(function () {
    'use strict';

    // Esci subito se non siamo sul checkout o se la config non è caricata
    if (typeof window.NgeniusEmbeddedConfig === 'undefined') {
        return;
    }

    var config = window.NgeniusEmbeddedConfig;
    var GATEWAY_ID = 'ngenius_embedded';
    var SDK_LOADED = false;
    var modalEl = null;

    // ================================================================
    // UTILITY: log helper (solo se debug attivo)
    // ================================================================
    function log() {
        if (!config.debug) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[NgeniusEmbedded]');
        console.log.apply(console, args);
    }

    function logError() {
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[NgeniusEmbedded]');
        console.error.apply(console, args);
    }

    // ================================================================
    // SDK LOADER: carica lo script N-Genius dinamicamente (una volta)
    // ================================================================
    function loadSdk(callback) {
        if (SDK_LOADED && typeof window.NI !== 'undefined') {
            log('SDK already loaded');
            callback(null);
            return;
        }

        log('Loading SDK from', config.sdkUrl);

        var script = document.createElement('script');
        script.src = config.sdkUrl;
        script.async = true;

        script.onload = function () {
            SDK_LOADED = true;
            log('SDK loaded successfully');
            callback(null);
        };

        script.onerror = function () {
            logError('Failed to load SDK from', config.sdkUrl);
            callback(new Error('Failed to load N-Genius SDK'));
        };

        document.head.appendChild(script);
    }

    // ================================================================
    // MODAL: crea l'overlay + finestra centrale
    // ================================================================
    function createModal() {
        if (modalEl) return modalEl;

        var overlay = document.createElement('div');
        overlay.id = 'ngenius-embedded-modal';
        overlay.className = 'ngenius-embedded-overlay';
        overlay.innerHTML =
            '<div class="ngenius-embedded-window">' +
                '<div class="ngenius-embedded-header">' +
                    '<h2>Complete your payment</h2>' +
                    '<button type="button" class="ngenius-embedded-close" aria-label="Close">×</button>' +
                '</div>' +
                '<div class="ngenius-embedded-body">' +
                    '<div id="ngenius-embedded-card-mount" class="ngenius-embedded-card-mount">' +
                        '<div class="ngenius-embedded-spinner">Loading secure form...</div>' +
                    '</div>' +
                    '<div id="ngenius-embedded-status" class="ngenius-embedded-status"></div>' +
                '</div>' +
                '<div class="ngenius-embedded-footer">' +
                    '<button type="button" id="ngenius-embedded-pay-btn" class="ngenius-embedded-pay-btn" disabled>' +
                        'Pay <span id="ngenius-embedded-amount"></span>' +
                    '</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        // Click su X per chiudere
        overlay.querySelector('.ngenius-embedded-close').addEventListener('click', closeModal);

        // Click sull'overlay (fuori dalla window) per chiudere
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });

        modalEl = overlay;
        return modalEl;
    }

    function openModal() {
        var modal = createModal();
        modal.classList.add('is-visible');
        document.body.style.overflow = 'hidden'; // disabilita scroll
    }

    function closeModal() {
        if (!modalEl) return;
        modalEl.classList.remove('is-visible');
        document.body.style.overflow = '';
    }

    function setStatus(message, type) {
        var el = document.getElementById('ngenius-embedded-status');
        if (!el) return;
        el.textContent = message || '';
        el.className = 'ngenius-embedded-status' + (type ? ' is-' + type : '');
    }

    // ================================================================
    // SDK MOUNT: monta l'iframe carta dentro il modal
    // ================================================================
    function mountCardInput(orderId, amount, currency) {
        if (typeof window.NI === 'undefined' || typeof window.NI.mountCardInput !== 'function') {
            logError('NI SDK not available');
            setStatus('Payment form failed to load. Please try again.', 'error');
            return;
        }

        log('Mounting card input for order', orderId, amount, currency);

        // Aggiorna il bottone pay con l'amount
        var amountEl = document.getElementById('ngenius-embedded-amount');
        if (amountEl) {
            amountEl.textContent = currency + ' ' + amount;
        }

        // Pulisce lo spinner dentro il mount point
        var mountPoint = document.getElementById('ngenius-embedded-card-mount');
        mountPoint.innerHTML = '';

        try {
            window.NI.mountCardInput('ngenius-embedded-card-mount', {
                hostedSessionApiKey: config.hostedSessionApiKey,
                outletRef: config.outletRef,
                onSuccess: function () {
                    log('Card input mounted successfully');
                    setStatus('Enter your card details above', 'info');
                },
                onFail: function (err) {
                    logError('Card input mount failed', err);
                    setStatus('Could not load secure card form. Please try again.', 'error');
                },
                onChangeValidStatus: function (status) {
                    log('Validation status:', status);
                    var allValid = status.isCVVValid && status.isExpiryValid &&
                                   status.isNameValid && status.isPanValid;
                    var payBtn = document.getElementById('ngenius-embedded-pay-btn');
                    if (payBtn) payBtn.disabled = !allValid;
                },
            });
        } catch (e) {
            logError('Exception mounting card input', e);
            setStatus('Failed to initialize payment form: ' + e.message, 'error');
        }

        // Hook click "Pay"
        var payBtn = document.getElementById('ngenius-embedded-pay-btn');
        if (payBtn) {
            payBtn.onclick = function () { handlePayClick(orderId); };
        }
    }

    // ================================================================
    // PAY: genera sessionId e invialo al backend
    // ================================================================
    function handlePayClick(orderId) {
        var payBtn = document.getElementById('ngenius-embedded-pay-btn');
        if (payBtn) payBtn.disabled = true;

        setStatus('Processing payment...', 'info');

        if (typeof window.NI === 'undefined' || typeof window.NI.generateSessionId !== 'function') {
            setStatus('SDK not ready. Refresh the page.', 'error');
            return;
        }

        window.NI.generateSessionId().then(function (response) {
            log('Session ID generated', response);

            if (!response || !response.session_id) {
                setStatus('Could not generate session. Please try again.', 'error');
                if (payBtn) payBtn.disabled = false;
                return;
            }

            // Invia sessionId al nostro backend
            sendSessionToBackend(orderId, response.session_id);

        }).catch(function (err) {
            logError('generateSessionId failed', err);
            setStatus('Card validation failed: ' + (err.message || 'unknown error'), 'error');
            if (payBtn) payBtn.disabled = false;
        });
    }

    function sendSessionToBackend(orderId, sessionId) {
        var formData = new FormData();
        formData.append('action', 'ngenius_embedded_complete_payment');
        formData.append('nonce', config.nonce);
        formData.append('order_id', orderId);
        formData.append('session_id', sessionId);

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            log('Backend response', json);
            if (json.success) {
                setStatus('✅ Session received by backend (Tappa 5 will complete payment)', 'success');
            } else {
                var msg = (json.data && json.data.error) ? json.data.error : 'Unknown error';
                setStatus('Backend error: ' + msg, 'error');
                var payBtn = document.getElementById('ngenius-embedded-pay-btn');
                if (payBtn) payBtn.disabled = false;
            }
        })
        .catch(function (err) {
            logError('Backend call failed', err);
            setStatus('Network error: ' + err.message, 'error');
            var payBtn = document.getElementById('ngenius-embedded-pay-btn');
            if (payBtn) payBtn.disabled = false;
        });
    }

    // ================================================================
    // PUBLIC API: chiamata dal gateway PHP quando l'ordine è creato
    // ================================================================
    window.NgeniusEmbeddedStart = function (orderId, amount, currency) {
        log('Starting embedded checkout for order', orderId);

        if (!config.embeddedMode) {
            log('Embedded mode is disabled, falling back to redirect');
            return false; // lascia che WC faccia il default redirect
        }

        openModal();
        setStatus('Loading secure payment form...', 'info');

        loadSdk(function (err) {
            if (err) {
                setStatus('Could not load payment SDK. Please try again.', 'error');
                return;
            }
            mountCardInput(orderId, amount, currency);
        });

        return true; // segnala al PHP che abbiamo gestito noi
    };

    log('Frontend script loaded. Embedded mode:', config.embeddedMode);

})();