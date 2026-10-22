=== Jarral Forms ===
Contributors: Jarral05
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.4.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Contact form with nonce, honeypot, timing trap, rate limiting and full sanitisation.

== Description ==

Renders the contact form, validates every field, stores each enquiry as a private post and emails a plain-text copy. No third-party services and no external requests.

== Changelog ==

= 1.1.0 =
* Added Update URI header (prevents a same-slug wordpress.org plugin overwriting this one)
* Added GitHub update checking
* Added uninstall.php
* Hardened the icon loader with a strict allowlist and realpath containment
* Added translation loading

= 1.0.0 =
* First release
