/*
 * Anti-self-XSS warning.
 *
 * Loaded synchronously in <head> so it prints before the main application
 * modules and the page body are evaluated. Keep this file external because
 * the application's Content-Security-Policy forbids inline scripts.
 */
(function () {
  'use strict';

  var message = [
    'DO NOT PUT ANYTHING HERE!',
    '请不要在这里粘贴任何东西。',
    '无论是谁，请不要这么做。',
    '这很有可能会导致您的数据泄露。',
    '',
    'Please do not paste anything here.',
    'Anyways, please do not do it.',
    'May it will make your data leaks.',
  ].join('\n');

  console.log(
    '%c' + message,
    'color:#e74c3c;font-size:16px;font-weight:700;line-height:1.65;'
  );
})();
