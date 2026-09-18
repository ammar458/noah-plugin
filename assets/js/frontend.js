/* Noah Memberships and programs — Frontend JS */
( function ( $ ) {
    'use strict';

    // Turns every hidden .noah-notice-data node (rendered by our
    // woocommerce/notices/*.php template overrides) into a single popup
    // modal, grouping error/success/notice messages together.
    function noahShowNoticePopup() {
        var nodes = document.querySelectorAll( '.noah-notice-data' );
        if ( ! nodes.length ) {
            return;
        }

        var overlay = document.createElement( 'div' );
        overlay.className = 'noah-notice-overlay';

        var modal = document.createElement( 'div' );
        modal.className = 'noah-notice-modal';
        modal.setAttribute( 'role', 'alertdialog' );

        var closeBtn = document.createElement( 'button' );
        closeBtn.type = 'button';
        closeBtn.className = 'noah-notice-close';
        closeBtn.setAttribute( 'aria-label', 'Close' );
        closeBtn.innerHTML = '&times;';
        modal.appendChild( closeBtn );

        // WooCommerce sometimes prints the same notice more than once in a
        // single request (e.g. a validation notice re-rendered when the
        // cart/checkout fragment refreshes right after) — de-dupe by exact
        // message text so the popup doesn't repeat itself.
        var seen = {};
        nodes.forEach( function ( node ) {
            var type = node.getAttribute( 'data-type' ) || 'notice';
            var html = node.innerHTML.trim();
            var key  = type + '|' + html;
            node.parentNode.removeChild( node );

            if ( seen[ key ] ) {
                return;
            }
            seen[ key ] = true;

            var item = document.createElement( 'div' );
            item.className = 'noah-notice-item noah-notice-item--' + type;
            item.innerHTML = html;
            modal.appendChild( item );
        } );

        overlay.appendChild( modal );
        document.body.appendChild( overlay );

        function close() {
            if ( overlay.parentNode ) {
                overlay.parentNode.removeChild( overlay );
            }
        }
        closeBtn.addEventListener( 'click', close );
        overlay.addEventListener( 'click', function ( e ) {
            if ( e.target === overlay ) {
                close();
            }
        } );
    }

    $( document ).ready( function () {
        // Fade in member notice
        $( '.noah-checkout-notice' ).hide().fadeIn( 500 );

        noahShowNoticePopup();

        // WooCommerce injects fresh notice HTML into the DOM via AJAX for
        // add-to-cart, cart totals, and checkout updates — re-scan after each
        // so those notices get the same popup treatment as a full page load.
        $( document.body ).on( 'added_to_cart updated_cart_totals updated_checkout wc_fragments_refreshed', function () {
            noahShowNoticePopup();
        } );
    } );
} )( jQuery );
