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
        var pluginUrl = (config.pluginUrl || '');
        var shopName = (config.shopName || 'Modevia');
        overlay.innerHTML =
            '<div class="ngenius-embedded-window">' +
                '<div class="ngenius-embedded-header">' +
                    '<div class="ngenius-embedded-shop-name">' + shopName + '</div>' +
                    '<div class="ngenius-embedded-card-logos">' +
                        '<img src="' + pluginUrl + 'resources/cards/visa.svg" alt="VISA" onerror="this.style.display=\'none\'" />' +
                        '<img src="' + pluginUrl + 'resources/cards/mastercard.svg" alt="Mastercard" onerror="this.style.display=\'none\'" />' +
                    '</div>' +
                    '<button type="button" class="ngenius-embedded-close" aria-label="Close">×</button>' +
                '</div>' +
                '<div class="ngenius-embedded-body">' +
                    '<div class="ngenius-embedded-card-mount-wrap">' +
                        '<div id="ngenius-embedded-card-mount" class="ngenius-embedded-card-mount">' +
                            '<div class="ngenius-embedded-spinner">Loading secure form...</div>' +
                        '</div>' +
                    '</div>' +
                    '<div id="ngenius-embedded-status" class="ngenius-embedded-status"></div>' +
                '</div>' +
                '<div class="ngenius-embedded-footer">' +
                    '<button type="button" id="ngenius-embedded-pay-btn" class="ngenius-embedded-pay-btn" disabled>' +
                        'Pay <span id="ngenius-embedded-amount"></span>' +
                    '</button>' +
                    '<div class="ngenius-embedded-secure">🔒 Secure payment</div>' +
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
    function mountCardInput(amount, currency, mountId) {
        mountId = mountId || 'ngenius-embedded-card-mount';
        // Unmount any previous instance to avoid "already mounted" SDK error
        if (typeof window.NI !== 'undefined' && typeof window.NI.unMountCardInputs === 'function') {
            try {
                window.NI.unMountCardInputs();
                log('Previous card input unmounted');
            } catch (e) {
                log('unMountCardInputs threw, continuing:', e.message);
            }
        }
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

        var mountPoint = document.getElementById(mountId);
        if (!mountPoint) { logError('Mount point not found:', mountId); return; }
        mountPoint.innerHTML = '';

        try {
           window.NI.mountCardInput(mountId, {
                apiKey: config.hostedSessionApiKey,
                outletRef: config.outletRef,
                language: 'en',
                style: {
                    main: {
                        backgroundColor: '#ffffff',
                        padding: '0',
                    },
                    base: {
                        color: '#111827',
                        fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
                        fontSize: '15px',
                        fontWeight: '400',
                        lineHeight: '1.5',
                        '::placeholder': {
                            color: '#9ca3af',
                        },
                    },
                    input: {
                        border: '1px solid #d1d5db',
                        borderRadius: '8px',
                        padding: '12px 14px',
                        marginBottom: '18px',
                        backgroundColor: '#ffffff',
                    },
                    inputError: {
                        border: '1px solid #ef4444',
                    },
                    label: {
                        color: '#374151',
                        fontSize: '12px',
                        fontWeight: '500',
                        marginBottom: '4px',
                        textTransform: 'none',
                    },
                    error: {
                        color: '#ef4444',
                        fontSize: '11px',
                        marginTop: '2px',
                    },
                },
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
                    var allValid = status.isCVVValid && status.isExpiryValid && status.isPanValid;
                    inlineFieldsValid = allValid;
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

    /**
     * Submit the WC checkout form with the sessionId attached as hidden input.
     * This makes WC run its standard process_checkout(), which calls our
     * gateway's process_payment(), which sees _ngenius_embedded_session_id and
     * routes to process_embedded_payment() for completion via N-Genius API.
     */
    function sendSessionToBackend(sessionId) {
        log('Submitting form with session ID:', sessionId.substring(0, 12) + '...');
        setStatus('Processing payment...', 'info');

        var $ = jQuery;
        var $form = $('form.checkout');
        if (!$form.length) {
            setStatus('Checkout form not found.', 'error');
            return;
        }

        // Inject hidden input with sessionId
        $form.find('input[name="_ngenius_embedded_session_id"]').remove();
        $form.append(
            $('<input>', {
                type: 'hidden',
                name: '_ngenius_embedded_session_id',
                value: sessionId,
            })
        );

        // Mark this submit as "embedded passthrough" so our click capture handler
        // does not block it again.
        window.__ngeniusEmbeddedAllowSubmit = true;

        // Trigger native submit. WC's checkout.js listens on this and runs the
        // AJAX checkout flow (which calls our process_payment).
        $form.trigger('submit');
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
    /**
     * Handle 3DS challenge using SDK's handlePaymentResponse.
     * Mounts 3DS iframe in our modal and completes the auth flow.
     */
    function handle3dsChallenge(rawResponseJson, orderId) {
        if (typeof window.NI === 'undefined' || typeof window.NI.handlePaymentResponse !== 'function') {
            logError('SDK not loaded for 3DS');
            setStatus('Could not initialize 3DS. Please try again.', 'error');
            return;
        }

        var paymentResponse;
        try {
            paymentResponse = (typeof rawResponseJson === 'string')
                ? JSON.parse(rawResponseJson)
                : rawResponseJson;
        } catch (e) {
            logError('Could not parse 3DS response', e);
            setStatus('Invalid 3DS response.', 'error');
            return;
        }

        // Smonta SDK inline (se presente) per evitare conflitto apple-spinner
        safeUnmountInline();

        // Apri modal SOLO per il 3DS challenge (in caso siamo nel flow inline)
        openModal();

        // Replace modal body with 3DS mount point
        var body = document.querySelector('.ngenius-embedded-body');
        var footer = document.querySelector('.ngenius-embedded-footer');
        if (body) {
            body.innerHTML = '<div id="ngenius-3ds-mount" style="width:100%;min-height:400px;"></div>' +
                '<div id="ngenius-embedded-status" class="ngenius-embedded-status"></div>';
        }
        if (footer) footer.style.display = 'none';
        setStatus('Authenticating with your bank...', 'info');

        log('Calling NI.handlePaymentResponse with 3DS', paymentResponse);

        window.NI.handlePaymentResponse(paymentResponse, {
            mountId: 'ngenius-3ds-mount',
            style: { width: '100%', height: '450px' }
        }).then(function (result) {
            log('3DS result:', result);

            var status = result && result.status;
            var SUCCESS_STATES = [
                window.NI.paymentStates.AUTHORISED,
                window.NI.paymentStates.CAPTURED,
                window.NI.paymentStates.PURCHASED,
            ];

            if (SUCCESS_STATES.indexOf(status) !== -1) {
                setStatus('✅ Payment successful! Redirecting...', 'success');
                // Notify backend to finalize the order
                finalizeOrderAfter3ds(orderId, status, true);
            } else {
                setStatus('❌ Payment failed (' + status + ')', 'error');
                finalizeOrderAfter3ds(orderId, status, false);
            }
        }).catch(function (err) {
            logError('3DS error:', err);
            setStatus('3DS authentication failed: ' + (err.message || 'unknown'), 'error');
            finalizeOrderAfter3ds(orderId, 'FAILED', false);
        });
    }

    /**
     * Notify backend to finalize order after 3DS completion.
     */
    function finalizeOrderAfter3ds(orderId, finalState, success) {
        var formData = new FormData();
        formData.append('action', 'ngenius_embedded_complete_payment');
        formData.append('nonce', config.nonce);
        formData.append('order_id', orderId);
        formData.append('final_state', finalState);
        formData.append('success', success ? '1' : '0');

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            log('Finalize response:', json);
            if (success && json.success && json.data && json.data.redirect) {
                window.location.href = json.data.redirect;
            } else if (success) {
                // Default redirect to thank-you
                window.location.reload();
            }
        })
        .catch(function (err) {
            logError('Finalize error:', err);
        });
    }
// ============================================================
// INLINE MOUNT — gestisce ciclo mount/unmount sincronizzato con WC update_checkout
// ============================================================

var inlineFieldsValid = false;
var inlineMounted = false;

function setInlineStatus(msg, type) {
    var el = document.getElementById('ngenius-embedded-inline-status');
    if (!el) return;
    if (!msg) {
        el.style.display = 'none';
        el.textContent = '';
        return;
    }
    el.style.display = 'block';
    el.textContent = msg;
    el.className = 'ngenius-embedded-inline-status' + (type ? ' is-' + type : '');
}

function isOurMethodSelected() {
    if (typeof jQuery === 'undefined') return false;
    var $checked = jQuery('input[name="payment_method"]:checked');
    return $checked.length && $checked.val() === GATEWAY_ID;
}

function safeUnmountInline() {
    // Chiamata documentata da N-Genius per smontare prima di rimontare
    if (typeof window.NI !== 'undefined' && typeof window.NI.unMountCardInputs === 'function') {
        try {
            window.NI.unMountCardInputs();
            log('SDK unmounted (cleanup before remount)');
        } catch (e) {
            log('unMountCardInputs threw on cleanup:', e.message);
        }
    }
    inlineMounted = false;
    inlineFieldsValid = false;
}

function mountInline() {
    if (!isOurMethodSelected()) return;

    var mountEl = document.getElementById('ngenius-embedded-card-mount-inline');
    if (!mountEl) {
        log('Inline mount point not in DOM');
        return;
    }

    // Cleanup di eventuale mount precedente (dopo update_checkout WC)
    safeUnmountInline();

    log('Mounting SDK inline...');
    setInlineStatus('Loading secure form...', 'info');

    loadSdk(function (err) {
        if (err) {
            logError('SDK load failed for inline mount', err);
            setInlineStatus('Could not load secure payment form. Please try again.', 'error');
            return;
        }
        var data = getOrderDataFromCheckout();
        if (!data) {
            log('No order data, aborting inline mount');
            return;
        }
        try {
            mountCardInput(data.amount, data.currency, 'ngenius-embedded-card-mount-inline');
            inlineMounted = true;
            setInlineStatus(null);
            log('Inline mount complete');
        } catch (e) {
            logError('Mount error:', e);
            setInlineStatus('Could not initialize secure form.', 'error');
        }
    });
}

function setupInlineMount() {
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;

    // WC rigenera il DOM dopo ogni update_checkout (cambio metodo, indirizzo, ecc.)
    // dobbiamo unmount + remount per evitare "container not present" / "apple-spinner already used"
    $(document.body).on('updated_checkout', function () {
        log('updated_checkout: remounting if needed');
        if (isOurMethodSelected()) {
            // Aspetta che WC abbia finito di rigenerare il DOM
            setTimeout(mountInline, 150);
        } else {
            safeUnmountInline();
        }
    });

    // Cambio radio button
    $(document).on('change', 'input[name="payment_method"]', function () {
        log('payment_method changed:', $(this).val());
        if (isOurMethodSelected()) {
            setTimeout(mountInline, 100);
        } else {
            safeUnmountInline();
        }
    });

    // Mount iniziale (caso di refresh con metodo gia selezionato)
    setTimeout(function () {
        if (isOurMethodSelected()) mountInline();
    }, 300);
}

function generateSessionAndSubmit($form) {
    if (typeof window.NI === 'undefined' || typeof window.NI.generateSessionId !== 'function') {
        setInlineStatus('Payment system not ready. Please refresh.', 'error');
        $form.removeClass('processing').unblock();
        return;
    }

    setInlineStatus('Generating secure session...', 'info');

    window.NI.generateSessionId().then(function (response) {
        var sessionId = response && response.session_id;
        if (!sessionId) {
            setInlineStatus('Could not generate payment session.', 'error');
            $form.removeClass('processing').unblock();
            return;
        }
        log('SessionId generated, submitting form');
        setInlineStatus('Processing payment...', 'info');
        $form.find('input[name="_ngenius_embedded_session_id"]').remove();
        $form.append('<input type="hidden" name="_ngenius_embedded_session_id" value="' + sessionId + '" />');
        window.__ngeniusEmbeddedAllowSubmit = true;
        $form.find('#place_order, button[name="woocommerce_checkout_place_order"]').first().trigger('click');
    }).catch(function (err) {
        logError('generateSessionId failed', err);
        setInlineStatus('Card details invalid or expired. Please re-enter.', 'error');
        $form.removeClass('processing').unblock();
    });
}

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
            // Se siamo nel passthrough (sessionId già generato), lascia passare
            if (window.__ngeniusEmbeddedAllowSubmit === true) {
                log('Passthrough mode active, allowing submit with sessionId');
                window.__ngeniusEmbeddedAllowSubmit = false; // reset
                return;
            }
            log('Embedded mode active, blocking default submit');
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

            // INLINE FLOW: iframe gia mounted nella pagina
            if (inlineMounted) {
                log('Inline flow: generating sessionId from mounted iframe');
                if (!inlineFieldsValid) {
                    setInlineStatus('Please complete all card fields.', 'error');
                    $form.removeClass('processing').unblock();
                    return;
                }
                generateSessionAndSubmit($form);
                return;
            }

            // FALLBACK MODAL (se inline mount fallito per qualche motivo)
            log('Modal fallback flow');
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

        // Handle 3DS challenge response from WC checkout AJAX
       // WC 10.x doesn't fire checkout_place_order_success reliably.
        // Instead, hook into jQuery's global ajaxComplete to intercept the response.
        $(document).ajaxComplete(function (event, xhr, settings) {
            // Only care about wc-ajax=checkout responses
            if (!settings.url || settings.url.indexOf('wc-ajax=checkout') === -1) {
                return;
            }

            var response = xhr.responseJSON;
            if (!response) {
                try {
                    response = JSON.parse(xhr.responseText);
                } catch (e) {
                    return;
                }
            }

            log('checkout AJAX response:', response);

            if (response && response.ngenius_3ds_required && response.ngenius_payment_response) {
                log('3DS required, handling via SDK');
                // Stop WC from doing its default redirect
                if (response.redirect) {
                    response.redirect = '#ngenius_3ds_handled';
                }
                handle3dsChallenge(response.ngenius_payment_response, response.ngenius_order_id);
            }
        });

        log('Checkout intercept registered (click capture on Place Order button)');
    }

    // ================================================================
    // INIT
    // ================================================================
    function init() {
        log('Frontend script loaded. Embedded mode:', config.embeddedMode);
        setupInlineMount();
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