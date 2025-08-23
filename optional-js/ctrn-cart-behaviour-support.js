// <!-- ctrn-cart-behaviour-support.js -->
<script>
(function () {
  // === Helpers ===
  function getCartCountFromCookie() {
    // Read WooCommerce cart item count from cookie
    var m = document.cookie.match(/(?:^|;\s*)woocommerce_items_in_cart=(\d+)/);
    return m ? parseInt(m[1], 10) || 0 : 0;
  }

  function syncHeaderBadge(n) {
    // Update header cart badge (theme selectors can be adjusted if needed)
    var badge = document.querySelector('.header-cart-link .cart-icon strong, .header-cart-link .count');
    if (badge) badge.textContent = String(n);

    // Toggle a helper class based on cart item count
    var link = document.querySelector('.header-cart-link');
    if (link) (n > 0 ? link.classList.add('has-items') : link.classList.remove('has-items'));
  }

  function openOffCanvasCart() {
    // Do not open cart on cart/checkout pages
    if (document.body.classList.contains('woocommerce-cart') ||
        document.body.classList.contains('woocommerce-checkout')) return;

    // Flatsome default toggle (adjust selector to match your theme)
    var el = document.querySelector('.header-cart-link.nav-top-link.is-small.off-canvas-toggle');
    if (el) { el.click(); return; }

    // Fallback togglers
    var alt = document.querySelector('[data-open="#cart-popup"], a[href="#cart-popup"], .header-cart-link.off-canvas-toggle');
    if (alt) alt.click();
  }

  // === On first load: cookie → badge sync + potential initial push ===
  document.addEventListener('DOMContentLoaded', function () {
    var n = getCartCountFromCookie();
    syncHeaderBadge(n);

    var prev = parseInt(localStorage.getItem('ctr_prev_cart_count') || '0', 10) || 0;
    var firstPushDone = localStorage.getItem('ctr_first_push_done') === '1';

    // If user added the very first item → gently open the off-canvas cart once
    if (!firstPushDone && prev === 0 && n > 0) {
      setTimeout(openOffCanvasCart, 1400); // tune 700–1200ms+ to match your UX
      localStorage.setItem('ctr_first_push_done', '1');
    }

    localStorage.setItem('ctr_prev_cart_count', String(n));

    // Handle non-AJAX add-to-cart flows (e.g., page reload parameters)
    var p = new URLSearchParams(location.search);
    if (p.has('add-to-cart') || p.has('added-to-cart')) {
      setTimeout(openOffCanvasCart, 1400);
    }
  });

  // === WooCommerce events ===
  function refreshFragments() {
    // Ask Woo to refresh mini-cart fragments after changes
    if (window.jQuery) {
      jQuery(document.body).trigger('wc_fragment_refresh');
    }
  }

  if (window.jQuery) {
    jQuery(function ($) {
      // Item added
      $(document.body).on('added_to_cart', function () {
        $(document.body).one('wc_fragments_refreshed', function () {
          var n = getCartCountFromCookie();
          syncHeaderBadge(n);
          localStorage.setItem('ctr_prev_cart_count', String(n));
          if (n === 0) localStorage.removeItem('ctr_first_push_done');
        });
        refreshFragments();
      });

      // Item removed / cart updated
      $(document.body).on('removed_from_cart updated_wc_div', function () {
        $(document.body).one('wc_fragments_refreshed', function () {
          var n = getCartCountFromCookie();
          syncHeaderBadge(n);
          localStorage.setItem('ctr_prev_cart_count', String(n));
          if (n === 0) localStorage.removeItem('ctr_first_push_done');
        });
        refreshFragments();
      });

      // Any fragment refresh → keep badge in sync
      $(document.body).on('wc_fragments_refreshed', function () {
        var n = getCartCountFromCookie();
        syncHeaderBadge(n);
        localStorage.setItem('ctr_prev_cart_count', String(n));
        if (n === 0) localStorage.removeItem('ctr_first_push_done');
      });
    });
  }
})();
</script>
