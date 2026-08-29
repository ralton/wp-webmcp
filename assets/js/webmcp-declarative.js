/**
 * WP-WebMCP Declarative
 * Auto-annotates detected HTML forms with WebMCP declarative attributes.
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
     * Annotate a form with WebMCP declarative attributes.
     *
     * WebMCP declarative API uses data attributes on form elements:
     * data-webmcp-tool: tool name
     * data-webmcp-description: human-readable description
     * data-webmcp-schema: JSON schema for inputs
     */
    function annotateForm(formElement, formData) {
        // Determine tool name from form data or ID.
        const toolName = formData.id || formElement.getAttribute('id') || 'wp_form';

        // Build a sensible tool name (snake_case).
        const cleanName = toolName
            .replace(/[^a-zA-Z0-9_-]/g, '_')
            .replace(/_+/g, '_')
            .toLowerCase();

        // Set WebMCP declarative attributes.
        formElement.setAttribute('data-webmcp-tool', cleanName);
        formElement.setAttribute('data-webmcp-description',
            'Submit the ' + (formData.plugin || 'website') + ' form on this page.');

        // Annotate individual input fields with schema hints.
        const inputs = formElement.querySelectorAll('input, select, textarea');
        const schemaProps = {};

        inputs.forEach(function (input) {
            const name = input.getAttribute('name');
            if (!name) return;

            const type = input.getAttribute('type') || input.tagName.toLowerCase();
            let schemaType = 'string';

            if (type === 'email') schemaType = 'string';
            else if (type === 'number' || type === 'range') schemaType = 'number';
            else if (type === 'checkbox') schemaType = 'boolean';
            else if (type === 'date') schemaType = 'string';
            else if (input.tagName === 'SELECT') schemaType = 'string';

            schemaProps[name] = {
                type: schemaType,
                label: getLabel(input),
            };

            // Mark required fields.
            if (input.hasAttribute('required')) {
                if (!schemaProps[name].required) {
                    schemaProps[name].required = true;
                }
            }
        });

        // Embed schema as data attribute.
        formElement.setAttribute('data-webmcp-schema', JSON.stringify({
            type: 'object',
            properties: schemaProps,
        }));

        log('Annotated form:', cleanName, schemaProps);
    }

    /**
     * Get a human-readable label for an input.
     */
    function getLabel(input) {
        // Try associated <label>.
        const id = input.getAttribute('id');
        if (id) {
            const label = document.querySelector('label[for="' + id + '"]');
            if (label) return label.textContent.trim();
        }

        // Try aria-label.
        const ariaLabel = input.getAttribute('aria-label');
        if (ariaLabel) return ariaLabel;

        // Try placeholder.
        const placeholder = input.getAttribute('placeholder');
        if (placeholder) return placeholder;

        // Fallback to name.
        return input.getAttribute('name') || 'field';
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
            // Find the form element on the page.
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
