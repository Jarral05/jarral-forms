# Changelog

All notable changes to this project.
Format: [Keep a Changelog](https://keepachangelog.com/). Versioning: [SemVer](https://semver.org/).

## [1.4.5] - 2026-10-26

### Fixed
- **The confirmation email could carry a stranger's text to any inbox.**
  The form emails whatever address is typed, and that email repeated the
  estimator fields and the first name. The estimator fields are hidden
  inputs and URL parameters, so anyone could fill them with "your invoice
  is overdue, call…" or a link, and send it from your domain.
  - Estimator values are kept only in the shape the site produces
    (`jdf_clean_estimate()`): a service name made of letters (commas, "&",
    hyphens and brackets allowed), a "$600 - $2500" range, and extras
    without links. A value that fails is dropped and the enquiry still
    arrives.
  - The same check applies to the "Your estimate" panel, so a crafted link
    can't put words on your page.
  - The greeting uses the first name only if it is a plain name (letters,
    apostrophe, hyphen, up to 30 characters), otherwise "Thanks, there".
- Commas and angle brackets are removed from the name in the Reply-To
  header of your own notification. `wp_mail()` splits that header on
  commas, so a crafted name could add a second address to your reply.
- Company is capped at 120 characters and "Sent from" at 200.

### Changed
- The client confirmation no longer lists the chosen extras ("Including:
  …"). Extras are free-form labels that may contain digits. They still
  appear in your notification email and in the Enquiries screen.
- Tested on the QA install with theme 1.8.4: 29 hardening checks (1.4.4
  fails 16). The form cache test (16) still passes.

## [1.4.4] - 2026-10-25

### Fixed
- **The form was not safe on cached pages.** LiteSpeed Cache keeps guest
  pages for a week by default, and the form sits on nine pages (home,
  contact, services and the six service pages). Three faults followed:
  - The cached nonce expired after 12 to 24 hours, so every enquiry from an
    older copy failed with "Your session expired".
  - After sending, the visitor returned to the same URL and got the cached
    page, without the thank-you or error message.
  - On a cache miss, LiteSpeed could store that message, including the
    visitor's typed name, email and message, and show it to everyone.

  The fixes:
  - Pages with the form now ask LiteSpeed for an 8-hour cache at most
    (`litespeed_control_set_ttl`, logged-out visitors only).
  - The visitor now returns to `?jdf=sent` or `?jdf=check`, which is never
    cached: `litespeed_control_set_nocache`, `DONOTCACHEPAGE` and
    `nocache_headers()`.
  - The saved result is shown only on that return visit. Both hooks do
    nothing without LiteSpeed Cache.
- The release workflow no longer generates release notes, matching the theme.
  `CHANGELOG.md` is the record.

### Changed
- The only visible change is the address after sending: `/contact/?jdf=sent#contact`
  instead of `/contact/#contact`. Message text and design are unchanged.
- Known limit: on a cached page the timing trap's start time is the cache
  time, so it only catches bots on uncached copies. The nonce, honeypot and
  rate limit are unaffected.

## [1.4.3] - 2026-10-23

### Added
- **Two-column layout for `[jarral_contact_form]`.** Two new attributes:
  `promises`, pipe-separated "Bold line :: plain sentence" pairs rendered as
  `ul.promise`, and `meta`, a line under them. With either one and a heading,
  the copy sits beside the form in the theme's `.contact-cols` markup. Without
  them the output is byte-for-byte what it was, so existing pages, including
  the six service pages, are unchanged. Promise text is escaped with
  `esc_html`; `meta` goes through `wp_kses`, allowing only `<a href>` and
  `<br>`.
- Needs Jarral Digital 1.6.3 or later for the styling, and `style="dark"`:
  the copy colours are written for the pine band, and on a light band the
  promise titles render at 1:1 contrast.

## [1.4.2] - 2026-10-23

### Fixed
- **Update checks crashed on every site running a release build.**
  `.gitignore` had `vendor/`, which also matched the update library's own
  `lib/plugin-update-checker/vendor/`, so Parsedown and the readme parser never
  reached a release zip. The check needs both, hit `Class "Parsedown" not
  found`, and stopped before recording any update. The rule is now `/vendor/`,
  top level only. Confirmed on the live site: `vendor/Parsedown.php` returned
  404. Sites on 1.4.1 or earlier cannot detect this release, so upload the
  1.4.2 zip once by hand; updates work normally after that.

## [1.4.1] - 2026-10-22

### Fixed
- The comment above `enableReleaseAssets()` claimed it restricted updates to
  tagged releases. It did not — `GitHubApi::getUpdateDetectionStrategies()`
  appends the branch strategy unconditionally, so the checker fell back to
  whatever sat on `main` when no release or tag existed. A
  `puc_vcs_update_detection_strategies-jarral-forms` filter now removes that
  fallback, making the claim true instead of merely written down.
- `getVcsApi()` is called once and null-checked before `method_exists()`,
  which raises a deprecation on PHP 8.1+ when passed null.

### Added
- The release workflow runs `php -l` over every PHP file before building the
  zip, so a syntax error fails the release instead of reaching a live site.

## [1.4.0] - 2026-10-22

### Added
- **A confirmation email to the client.** Designed HTML, table-based layout,
  inline styles, 600px, no images - built for Outlook's Word rendering engine
  and Gmail's stylesheet stripping. It confirms the message arrived, states
  the reply window, repeats the estimate with a clear note that it is an
  estimate and not a quote, and sets out what happens next. The wordmark is
  text rather than an image, because images are blocked by default in most
  clients.
- A configuration block at the top of the file: `JDF_FROM_EMAIL`,
  `JDF_FROM_NAME`, `JDF_AUTOREPLY` and `JDF_REPLY_WINDOW`. Each is wrapped in
  `defined()`, so wp-config.php can override any of them and a plugin update
  will not undo the change.

### Security
- **The client's own message is deliberately not repeated back to them.** The
  form accepts any address a visitor types, so anything echoed into that email
  is something a stranger could aim at somebody else's inbox. The structured
  fields come from the site's own dropdowns and estimator and are safe to
  include; a free paragraph is not. The admin copy still contains everything.
- The `wp_mail_content_type` filter is removed immediately after the send.
  Left attached it would turn every other plugin's plain-text email on the
  site into `text/html`, showing recipients raw markup.

### Fixed
- **The client email scrolled sideways on phones.** The card was
  `width="600" style="width:600px;max-width:100%"`, which looks responsive but
  is not: table layout resolves the specified width first and `max-width`
  never gets a say, leaving a 600px card inside a 360px screen. It is now a
  100%-wide table capped with `max-width`, wrapped in an `[if mso]`
  conditional that gives Outlook the fixed-width table it needs because
  Outlook ignores `max-width`. Verified at 360px: no horizontal scroll.
- Both emails now send from `JDF_FROM_EMAIL` rather than a synthesised
  `no-reply@` address.

### Changed
- GitHub username corrected from the `mohsinjarral` placeholder to `Jarral05`.

## [1.3.0] - 2026-10-22

### Added
- **The estimator selection is now visible on the form.** The service, price
  and extras were already carried from the estimator into the database and
  the notification email - that part worked. But all three arrived as
  `type="hidden"` inputs with one line of small text, so the visitor had no
  way to see that their choice had carried over. They now appear as a panel
  showing the service, the price range in large type, the chosen extras, and
  a "Change it" link back to the estimator.
- **The service and price lead the email subject.** It was `New enquiry:
  Jane Whitfield`, which meant opening every email to learn what it was
  about. It is now `Portal or members area $1,050 - $2,400 - Jane
  Whitfield`. An inbox list on a phone shows roughly 45 characters, so the
  service goes first. Enquiries with no estimate keep the old subject.
- **An estimate panel at the top of the admin edit screen**, and `Service`
  and `Estimate` columns on the Enquiries list, so the whole list can be
  read without opening anything.
- **Shortcode attributes**: `heading`, `intro`, `service`, `style` and `id`.
  One shortcode now produces a finished section on a service page rather
  than a bare form under the body text. `service` tags the enquiry even when
  the visitor never touched the estimator, so a Headless WordPress enquiry
  is distinguishable from a MERN one in the admin list.
- **`_jdf_source`** records which page the form was sent from. Captured as a
  hidden field at render time, not read from the referrer on submit, because
  by then `admin-post.php` is the referrer and the real origin is gone.

### Changed
- `jd_render_contact_form()` accepts an optional `$args` array. Calling it
  with no arguments behaves exactly as before, so the theme's homepage
  template part needs no change.

## [1.2.1] - 2026-10-19

### Added
- `[jarral_contact_form]` shortcode, so the form can appear on any page.
  Previously it existed only inside the homepage template part, which meant
  the /contact/ page - the destination of the header button and the
  estimator's call to action - rendered empty.

### Verified
- Shortcode returns its markup instead of echoing it, so the form appears
  where the shortcode sits rather than at the top of the page.
- Nonce, honeypot and timing-trap fields all present in the shortcode output.
- Estimator URL prefill still works through the shortcode.

## [1.2.0] - 2026-10-19

### Fixed
- SVG icons carried ~7.7 KB of embedded C2PA provenance metadata each, 65 KB
  across the eight files. `wp_kses()` stripped the tags at output so nothing
  was ever served to a visitor, but WordPress parsed 65 KB of dead weight on
  every page load. Icons regenerated clean: 65,153 bytes -> 2,835 bytes.

### Verified this release (PHP 8.3.6, executed not just inspected)
- All 35 project PHP files and all 129 bundled library files pass `php -l`.
- Theme loads and runs with no fatals; all hooks fire.
- Icon loader: 10 path-traversal payloads attempted, all 10 blocked.
- Both JSON-LD schema blocks emit valid JSON; FAQ schema count matches the
  rendered page exactly (7 = 7).
- Form handler attacked with honeypot, sub-3-second timing, missing nonce,
  malformed email, short and oversized messages, link spam, oversized name,
  forged dropdown values and stored XSS. Every guard held; nothing unwanted
  was written to the database.
- Rate limit fires on the 6th attempt against a cap of 5.
- Validation errors reach the user and submitted input survives the bounce.

## [1.1.0] - 2026-09-29

### Security
- `jd_icon()` now uses a strict `[a-z0-9-]` allowlist plus a `realpath()`
  containment check, rather than `sanitize_file_name()` alone. Closes a
  theoretical path-traversal surface in the icon loader.
- Added the `Update URI` header to the theme and both plugins. Without it,
  a plugin published on wordpress.org under the same folder name could be
  served to your sites as an "update".

### Added
- GitHub-based update checking (Plugin Update Checker 5.7, MIT).
- `uninstall.php` for both plugins. Enquiries and Projects are deliberately
  preserved; only transients and rewrite rules are cleared.
- `load_theme_textdomain()` and `index.php` silence files throughout.
- `readme.txt` for both plugins.

### Fixed
- `remove_action()` was called with four arguments; it accepts three.
- `wp_sprintf()` replaced with `sprintf()` where no list formatting was used.

## [1.0.0] - 2026-09-29

### Added
- First release: 25-file classic theme, Projects plugin, Forms plugin.
