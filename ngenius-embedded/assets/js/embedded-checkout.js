/**
 * N-Genius Embedded — Frontend checkout integration
 *
 * Intercetta il "Place order" del checkout WooCommerce quando il metodo
 * di pagamento è "ngenius_embedded" e l'embedded mode è ON.
 * Apre un modal con form carta embedded (iframe N-Genius).
 *
 * NOTA TAPPA 4.4: il flusso è "TEST MODE" — dopo aver ricevuto il sessionId
 * NON addebita nulla. Il completion vero arriverà nella Tappa 5.
 *
 * Variabile globale richiesta: window.NgeniusEmbeddedConfig
 * (popolata server-side via wp_localize_script)
 */

(function () {
    'use strict';

    if (typeof window.NgeniusEmbeddedConfig === 'undefined') {
        return;
    }

    var config = window.NgeniusEmbeddedConfig;
    var GATEWAY_ID = 'ngenius_embedded';
    var SDK_LOADED = false;
    var modalEl = null;
    var currentOrderData = null; // { amount, currency } catturati dal checkout

    // ================================================================
    // UTILITY: log helpers
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
    // SDK LOADER
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
    // MODAL: crea overlay + finestra
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

        overlay.querySelector('.ngenius-embedded-close').addEventListener('click', closeModal);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeModal();
        });

        modalEl = overlay;
        return modalEl;
    }

    function openModal() {
        var modal = createModal();
        modal.classList.add('is-visible');
        document.body.style.overflow = 'hidden';
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
    // SDK MOUNT: monta iframe carta
    // ================================================================
    function mountCardInput(amount, currency) {
        if (typeof window.NI === 'undefined' || typeof window.NI.mountCardInput !== 'function') {
            logError('NI SDK not available');
            setStatus('Payment form failed to load. Please try again.', 'error');
            return;
        }

        log('Mounting card input', amount, currency);

        var amountEl = document.getElementById('ngenius-embedded-amount');
        if (amountEl) {
            amountEl.textContent = currency + ' ' + amount;
        }

        var mountPoint = document.getElementById('ngenius-embedded-card-mount');
        mountPoint.innerHTML = '';

        try {
            window.NI.mountCardInput('ngenius-embedded-card-mount', {
                apiKey: config.hostedSessionApiKey,
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

        var payBtn = document.getElementById('ngenius-embedded-pay-btn');
        if (payBtn) {
            payBtn.onclick = function () { handlePayClick(); };
        }
    }

    // ================================================================
    // PAY: genera sessionId e mandalo al backend
    // ================================================================
    function handlePayClick() {
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

            sendSessionToBackend(response.session_id);

        }).catch(function (err) {
            logError('generateSessionId failed', err);
            setStatus('Card validation failed: ' + (err.message || 'unknown error'), 'error');
            if (payBtn) payBtn.disabled = false;
        });
    }

    function sendSessionToBackend(sessionId) {
        var formData = new FormData();
        formData.append('action', 'ngenius_embedded_complete_payment');
        formData.append('nonce', config.nonce);
        // NOTA: order_id sarà 0 in Tappa 4.4 (l'ordine non è ancora creato).
        // Tappa 5 cambierà il flusso per creare prima l'ordine WC.
        formData.append('order_id', 0);
        formData.append('session_id', sessionId);

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
        .then(function (res) { return res.json(); })
        .then(function (json) {
            log('Backend response', json);
            // In Tappa 4.4 il backend risponde sempre con error "Order not found"
            // perché order_id=0. Lo trattiamo come "TEST MODE OK".
            showTestModeSuccess(sessionId);
        })
        .catch(function (err) {
            logError('Backend call failed', err);
            // Anche su errore network mostriamo Test Mode (la session è stata generata
            // con successo dal SDK, che è ciò che vogliamo testare in Tappa 4.4)
            showTestModeSuccess(sessionId);
        });
    }

    function showTestModeSuccess(sessionId) {
        var body = document.querySelector('.ngenius-embedded-body');
        var footer = document.querySelector('.ngenius-embedded-footer');
        if (!body || !footer) return;

        var shortSession = sessionId.substring(0, 12) + '...';

        body.innerHTML =
            '<div style="text-align:center;padding:24px 8px;">' +
                '<div style="font-size:48px;margin-bottom:8px;">✅</div>' +
                '<h3 style="margin:0 0 12px 0;color:#166534;">TEST MODE — Integration successful</h3>' +
                '<p style="color:#374151;margin:0 0 16px 0;line-height:1.5;">' +
                    'The Web SDK integration is working correctly.<br>' +
                    '<strong>No real payment was processed.</strong><br><br>' +
                    'Real payment completion will be implemented in <em>Tappa 5</em>.' +
                '</p>' +
                '<div style="background:#f3f4f6;padding:10px;border-radius:6px;font-family:monospace;font-size:12px;color:#6b7280;">' +
                    'Session ID: ' + shortSession +
                '</div>' +
            '</div>';

        footer.innerHTML =
            '<button type="button" class="ngenius-embedded-pay-btn" id="ngenius-embedded-test-close">Close</button>';

        document.getElementById('ngenius-embedded-test-close').onclick = function () {
            closeModal();
            // Reset del bottone Place Order su WC checkout (rimuove lo "spinner" se attivo)
            if (typeof jQuery !== 'undefined' && jQuery('form.checkout').length) {
                jQuery('form.checkout').removeClass('processing').unblock();
            }
        };
    }

    // ================================================================
    // EXTRACT amount & currency dal checkout DOM
    // ================================================================
    function getOrderDataFromCheckout() {
        // WooCommerce mostra il totale in .order-total .woocommerce-Price-amount
        var totalEl = document.querySelector('.order-total .woocommerce-Price-amount');
        if (!totalEl) {
            log('Could not find total element');
            return null;
        }

        var amountEl = totalEl.querySelector('bdi') || totalEl;
        var raw = amountEl.textContent.trim();

        // Estrae numero (es. "€49.99" → 49.99) e currency
        var match = raw.match(/([0-9]+[.,]?[0-9]*)/);
        var amount = match ? match[1].replace(',', '.') : '0';

        var currencyEl = totalEl.querySelector('.woocommerce-Price-currencySymbol');
        var currency = currencyEl ? currencyEl.textContent.trim() : '';

        log('Extracted from checkout DOM:', { amount: amount, currency: currency, raw: raw });

        return {
            amount: amount,
            currency: currency,
        };
    }

    // ================================================================
    // CHECKOUT INTERCEPT: intercetta "Place order" via jQuery hook WC
    // ================================================================
function setupCheckoutIntercept() {
        if (typeof jQuery === 'undefined') {
            logError('jQuery not available');
            return;
        }

        var $ = jQuery;

        /**
         * Intercept click sul bottone "Place order" in CAPTURE PHASE.
         * 
         * Strategia: registriamo un handler click su `document` con `capture=true`,
         * che scatta PRIMA di qualunque handler aggiunto da WooCommerce o altri plugin
         * (es. checkout-permanent). Se il metodo selezionato è ngenius_embedded e
         * embedded mode è ON, blocchiamo TUTTO con preventDefault + stopImmediatePropagation.
         * 
         * Questo approccio funziona indipendentemente da come WC/altri plugin gestiscono
         * il submit (jQuery events, form submit, AJAX click, ecc.).
         */
        document.addEventListener('click', function (e) {
            // Risali la catena DOM dal target cercando il bottone Place Order
            var target = e.target;
            var placeOrderBtn = null;

            while (target && target !== document.body) {
                if (target.id === 'place_order' ||
                    (target.name && target.name === 'woocommerce_checkout_place_order')) {
                    placeOrderBtn = target;
                    break;
                }
                target = target.parentElement;
            }

            // Se non è il bottone Place Order, lascia passare
            if (!placeOrderBtn) {
                return;
            }

            log('Place Order button click detected (capture phase)');

            // Trova il form di checkout
            var $form = $(placeOrderBtn).closest('form.checkout');
            if (!$form.length) {
                logError('Could not find form.checkout from button');
                return;
            }

            // Verifica metodo selezionato
            var selectedMethod = $form.find('input[name="payment_method"]:checked').val();
            log('Selected method:', selectedMethod);

            if (selectedMethod !== GATEWAY_ID) {
                log('Not our gateway, allowing default flow');
                return;
            }

            // Verifica embedded mode (PHP passa '1' / '' invece di true / false)
            if (!config.embeddedMode || config.embeddedMode === '0' || config.embeddedMode === '') {
                log('Embedded mode is off, allowing HPP flow');
                return;
            }

            // BLOCCA TUTTO
            log('Embedded mode active, blocking submit and opening modal');
            e.preventDefault();
            e.stopImmediatePropagation();
            e.stopPropagation();

            // Cattura amount e currency
            currentOrderData = getOrderDataFromCheckout();
            if (!currentOrderData) {
                logError('Could not extract order data from checkout');
                alert('Could not read order total. Please refresh the page.');
                $form.removeClass('processing').unblock();
                return;
            }

            // Apri modal
            openModal();
            setStatus('Loading secure payment form...', 'info');

            loadSdk(function (err) {
                if (err) {
                    setStatus('Could not load payment SDK. Please try again.', 'error');
                    return;
                }
                mountCardInput(currentOrderData.amount, currentOrderData.currency);
            });

        }, true); // CAPTURE PHASE = scatta PRIMA degli altri handler

        log('Checkout intercept registered (click capture on Place Order button)');
    }

    // ================================================================
    // INIT
    // ================================================================
    function init() {
        log('Frontend script loaded. Embedded mode:', config.embeddedMode);
        setupCheckoutIntercept();
    }

    // Aspetta che jQuery e DOM siano pronti
    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(init);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();