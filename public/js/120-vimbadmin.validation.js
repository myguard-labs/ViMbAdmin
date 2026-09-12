/*
 * ViMbAdmin form validation.
 *
 * Uses the browser's Constraint Validation API and Bootstrap 5's validation
 * classes. Fields may share a data-validation-group value; validating one
 * member also refreshes its peers so a now-valid sibling cannot retain stale
 * error styling.
 */
(function() {
    'use strict';

    function formControls(form) {
        return Array.prototype.filter.call(form.elements, function(element) {
            return isValidationControl(element);
        });
    }

    function isValidationControl(element) {
        return typeof element.checkValidity === 'function' &&
            element.willValidate &&
            (element.matches('[required], [pattern], [minlength], [maxlength], [min], [max], [step], [type="email"], [type="url"], [type="number"]') ||
                element.validity.customError ||
                element.hasAttribute('data-validation-group') ||
                element.classList.contains('is-invalid') ||
                element.classList.contains('is-valid'));
    }

    function showValidity(element) {
        const valid = element.checkValidity();

        element.classList.toggle('is-invalid', !valid);
        element.classList.toggle('is-valid', valid);
        return valid;
    }

    function refreshGroup(element) {
        const group = element.getAttribute('data-validation-group');

        if (!group || !element.form)
            return;

        formControls(element.form).forEach(function(peer) {
            if (peer !== element && peer.getAttribute('data-validation-group') === group)
                showValidity(peer);
        });
    }

    document.addEventListener('invalid', function(event) {
        if (event.target.form) {
            event.target.form.classList.add('was-validated');
            event.target.classList.add('is-invalid');
        }
    }, true);

    function handleFieldEvent(event) {
        const element = event.target;

        if (!element.form || !isValidationControl(element))
            return;

        showValidity(element);
        refreshGroup(element);
    }

    document.addEventListener('input', handleFieldEvent);
    document.addEventListener('change', handleFieldEvent);

    document.addEventListener('submit', function(event) {
        const form = event.target;

        if (!(form instanceof HTMLFormElement))
            return;

        if (form.noValidate || (event.submitter && event.submitter.formNoValidate))
            return;

        form.classList.add('was-validated');

        const valid = form.checkValidity();
        formControls(form).forEach(showValidity);

        if (!valid) {
            event.preventDefault();
            event.stopPropagation();
        }
    });
}());
