<?php

declare(strict_types=1);

namespace App\Modules\Booking\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * /_/widget.js — the embeddable booking widget (plan.md §12.2). A tenant's
 * customer drops
 *
 *   <div id="albabook-widget"></div>
 *   <script src="https://book.example/_/widget.js"
 *           data-business="the-slug" data-theme="light" defer></script>
 *
 * on their site; this script creates a sandboxed iframe pointing at the
 * /{slug}/embed booking page. Served with CORP `cross-origin` because the
 * file is loaded by third-party origins — no secrets live here, so there is
 * nothing to leak (plan.md: never put private API secrets in widget JS).
 */
final class WidgetController
{
    private const SCRIPT = <<<'JS'
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script) return;
  var slug = (script.getAttribute('data-business') || '').toLowerCase().trim();
  if (!/^[a-z0-9-]{3,60}$/.test(slug)) {
    if (window.console) console.error('albabook-widget: missing or invalid data-business');
    return;
  }
  var origin = new URL(script.src).origin;
  var params = new URLSearchParams();
  ['theme', 'branch', 'service', 'locale'].forEach(function (key) {
    var value = script.getAttribute('data-' + key);
    if (value) params.set(key, value);
  });
  var iframe = document.createElement('iframe');
  iframe.src = origin + '/' + encodeURIComponent(slug) + '/embed' + (params.toString() ? '?' + params : '');
  iframe.title = 'Book an appointment';
  iframe.setAttribute('sandbox', 'allow-scripts allow-forms allow-same-origin');
  iframe.setAttribute('loading', 'lazy');
  iframe.style.width = '100%';
  iframe.style.border = '0';
  iframe.style.minHeight = '480px';
  var mount = document.getElementById('albabook-widget') || script.parentNode;
  mount.appendChild(iframe);
})();
JS;

    /** @param array<string, string> $params */
    public function script(Request $request, array $params): Response
    {
        return new Response(self::SCRIPT, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Loaded cross-origin by customer websites — must opt out of the
            // default same-origin resource policy.
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
