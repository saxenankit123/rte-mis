(function ($, Drupal, once) {
    Drupal.behaviors.wrapBlockMenu = {
        attach: function (context, settings) {
            $(once('wrapAll', '.content-wrapper .menu--social-links, .content-wrapper .menu--get-in-touch', context)).wrapAll('<div class="menu-social-links-wrapper block"></div>');
            $(once('wrapAll', '.content-wrapper .block:not(:nth-child(1))',  context)).wrapAll('<div class="footer-wrapper"></div>');
        }
    };
})(jQuery, Drupal, once);