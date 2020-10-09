/**
 * @file
 * OpenideaL Tour utilities.
 *
 */
(function ($, document) {

  'use strict';

  if (!Cookies.get('tour_displayed')) {
    $(document).on('drupalTourStarted', function () {
      Cookies.set('tour_displayed', '1', {expires: 365})
    });
  }

}
)(jQuery, document);
