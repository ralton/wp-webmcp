/**
 * WP-WebMCP Declarative
 * Auto-annotates detected HTML forms with WebMCP declarative attributes.
 *
 * Uses the real Chrome WebMCP Declarative API, which reads plain HTML
 * attributes directly on the <form> element and its fields — there is
 * no JSON-schema data attribute; the browser derives the schema itself
 * from field names/types and associated <label> elements.
 * Reference: https://developer.chrome.com/docs/ai/webmcp/declarative-api
 *
 * @package WP_WebMCP
 */

(function () {
    'use strict';

    const config = window.WP_WebMCP_Config || {};
    const debug = config.debug || false;

    function log(...args) {
        if (debug) {
            console.log('[WP-WebMCP Declarative]', ...args);
        }
    }

    /**
     * Parse detected forms from the inline JSON script tag.
     */
    function getDetectedForms() {
        const script = document.getElementById('wp-webmcp-forms');
        if (!script) {
            return [];
        }
        try {
            return JSON.parse(script.textContent);
        } catch (e) {
            console.error('[WP-WebMCP] Failed to parse form data:', e);
            return [];
        }
    }

    /**
     * Annotate a form with the real WebMCP declarative attributes:
     * toolname + tooldescription on the <form>, and toolparamdescription
     * on individual fields that don't already have an associated <label>.
     */
    function annotateForm(formElement, formData) {
        // Don't clobber a form that's already been manually annotated
        // (e.g. a developer hand-wrote toolname/tooldescription in the
        // page's HTML/shortcode output).
        if (formElement.hasAttribute('toolname')) {
            log('Form already annotated, skipping:', formElement.getAttribute('toolname'));
            return;
        }

        const rawName = formData.id || formElement.getAttribute('id') || 'wp_form';
        const cleanName = rawName
            .replace(/[^a-zA-Z0-9_-]/g, '_')
            .replace(/_+/g, '_')
            .toLowerCase();

        formElement.setAttribute('toolname', cleanName);
        formElement.setAttribute(
            'tooldescription',
            'Submit the ' + (formData.plugin || 'website') + ' form on this page.'
        );

        // Only fields without a discoverable label/aria-description need
        // an explicit toolparamdescription — the browser reads <label>
        // and aria-description automatically otherwise.
        const inputs = formElement.querySelectorAll('input, select, textarea');
        inputs.forEach(function (input) {
            if (input.hasAttribute('toolparamdescription')) {
                return;
            }
            if (hasDiscoverableLabel(input)) {
                return;
            }

            const fallback = input.getAttribute('placeholder') || input.getAttribute('name');
            if (fallback) {
                input.setAttribute('toolparamdescription', fallback);
            }
        });

        log('Annotated form:', cleanName);
    }

    /**
     * Whether the browser can already find a description for this field
     * on its own (associated <label>, or aria-label/aria-description).
     */
    function hasDiscoverableLabel(input) {
        const id = input.getAttribute('id');
        if (id && document.querySelector('label[for="' + id + '"]')) {
            return true;
        }
        if (input.closest('label')) {
            return true;
        }
        if (input.getAttribute('aria-label') || input.getAttribute('aria-description')) {
            return true;
        }
        return false;
    }

    /**
     * Initialize — find and annotate all detected forms.
     */
    function init() {
        const formsData = getDetectedForms();

        if (formsData.length === 0) {
            log('No forms detected on this page.');
            return;
        }

        log('Detected ' + formsData.length + ' form(s).');

        formsData.forEach(function (formData, index) {
            const selector = formData.id ? '#' + CSS.escape(formData.id) : 'form:nth-of-type(' + (index + 1) + ')';
            const formElement = document.querySelector(selector);

            if (formElement) {
                annotateForm(formElement, formData);
            } else {
                log('Form element not found for:', formData.id);
            }
        });
    }

    // Wait for DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
