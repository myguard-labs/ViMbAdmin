/*
 * ViMbAdmin's native Bootstrap 5 modal helpers.
 *
 * These helpers deliberately use the Bootstrap JavaScript API directly and
 * have no DOM-library or third-party dialog dependency. The application still renders HTML in
 * informational popups for backwards-compatible OSS_Message output; confirm
 * messages are always inserted as text.
 */

(function( window, document ) {
    'use strict';

    var nextDialogId = 0;

    function modalCtor()
    {
        return window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal
            : null;
    }

    function resolveElement( target )
    {
        if( typeof target === 'string' )
            return document.querySelector( target );

        return target && target.nodeType === 1 ? target : null;
    }

    /**
     * Show an existing in-page modal.
     *
     * @return {bootstrap.Modal|null}
     */
    function ossModal( target )
    {
        var element = resolveElement( target );
        var Modal = modalCtor();

        if( !element || !Modal )
            return null;

        var instance = Modal.getOrCreateInstance( element, {
            backdrop: true,
            keyboard: true
        } );
        instance.show();

        return instance;
    }

    function createDialog( title, message, messageIsHtml, confirmLabel )
    {
        var titleId = 'oss-modal-title-' + (++nextDialogId);
        var element = document.createElement( 'div' );
        element.className = 'modal fade';
        element.tabIndex = -1;
        element.setAttribute( 'aria-hidden', 'true' );
        element.setAttribute( 'aria-labelledby', titleId );
        element.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
                '<div class="modal-content">' +
                    '<div class="modal-header">' +
                        '<h3 class="modal-title"></h3>' +
                        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body"></div>' +
                    '<div class="modal-footer">' +
                        (confirmLabel
                            ? '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
                              '<button type="button" class="btn btn-danger" data-oss-confirm>' + confirmLabel + '</button>'
                            : '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>') +
                    '</div>' +
                '</div>' +
            '</div>';

        var titleElement = element.querySelector( '.modal-title' );
        titleElement.id = titleId;
        titleElement.textContent = title;

        var body = element.querySelector( '.modal-body' );
        if( messageIsHtml )
            body.innerHTML = String( message );
        else
            body.textContent = String( message );

        document.body.appendChild( element );
        return element;
    }

    /**
     * Show an informational popup and invoke callback after it is dismissed.
     *
     * @return {Element|null}
     */
    function ossAlert( message, callback )
    {
        var Modal = modalCtor();

        if( !Modal )
        {
            window.alert( String( message ).replace( /<[^>]*>/g, '' ) );
            if( typeof callback === 'function' )
                callback();
            return null;
        }

        var element = createDialog( 'Message', message, true, '' );
        var instance = Modal.getOrCreateInstance( element, {
            backdrop: true,
            keyboard: true
        } );

        element.addEventListener( 'hidden.bs.modal', function() {
            instance.dispose();
            element.remove();
            if( typeof callback === 'function' )
                callback();
        }, { once: true } );

        instance.show();
        return element;
    }

    /**
     * Ask for confirmation. Dismissal, Escape, and a missing Bootstrap runtime
     * all fail closed and report false to the callback.
     *
     * @return {Element|null}
     */
    function ossConfirm( message, callback )
    {
        var Modal = modalCtor();
        if( !Modal )
        {
            if( typeof callback === 'function' )
                callback( false );
            return null;
        }

        var element = createDialog( 'Confirm action', message, false, 'Confirm' );
        var instance = Modal.getOrCreateInstance( element, {
            backdrop: true,
            keyboard: true
        } );
        var accepted = false;
        var confirmButton = element.querySelector( '[data-oss-confirm]' );
        confirmButton.disabled = true;

        element.addEventListener( 'shown.bs.modal', function() {
            confirmButton.disabled = false;
            confirmButton.focus();
        }, { once: true } );

        confirmButton.addEventListener( 'click', function() {
            accepted = true;
            instance.hide();
        }, { once: true } );

        element.addEventListener( 'hidden.bs.modal', function() {
            instance.dispose();
            element.remove();
            if( typeof callback === 'function' )
                callback( accepted );
        }, { once: true } );

        instance.show();
        return element;
    }

    window.ossModal = ossModal;
    window.ossAlert = ossAlert;
    window.ossConfirm = ossConfirm;
})( window, document );
