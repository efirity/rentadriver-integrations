# Getting the plugin to merchants

Three channels, in the order they become useful. Only the first one is live; the other two are submissions someone
has to make with an account we own, and both are review queues measured in weeks.

The plugin source is `rentadriver-delivery/`. It is the single source of truth for all three — never fork it per channel.

## 1. Download from our own app (live)

`GET /v1/woocommerce/plugin.zip` builds the archive from the plugin files that ship inside the api image and serves it
as `rentadriver-delivery-<version>.zip`. The merchant app shows a **Download the plugin (.zip)** button above the
Store ID / Rate token box, so a merchant who has just connected can actually obtain the thing they are being told to
paste credentials into.

The API serves the zip built from these files.

- The version in the filename and `X-Plugin-Version` is parsed from the `Version:` header in the PHP file. Bump that
  header and the download follows; there is nothing else to update.

Auto-updates are **not** wired: a merchant who downloads the zip has to download it again to upgrade. That is the main
reason to finish channel 2.

## 2. WordPress.org plugin directory — LIVE since 2026-09-30

Approved 2026-09-30. Listed at https://wordpress.org/plugins/rentadriver-delivery, SVN
`https://plugins.svn.wordpress.org/rentadriver-delivery`. SVN access is held by the plugin owner. First release 0.5.4 =
SVN r3721040 (trunk, `tags/0.5.4`, banners + icon).

Releases: `svn cp trunk tags/<version>` after the version is live. Never commit unreleased code: SVN is the
update channel for every installed site. Listing-only changes (screenshots, banners, readme text) need no version bump.
Reviewers can re-check a listed plugin at any time; keep to the directory guidelines.

### Before approval (history)

The plugin is written to pass review; `readme.txt` is in wp.org format and the pieces reviewers reject are already
handled:

| Requirement | Where |
|---|---|
| External services disclosure — what is sent to api.rentadriver.ai, when, and links to terms + privacy | `readme.txt` → `== External services ==`. **Mandatory**, and the most common rejection for a plugin like ours |
| GPL headers, `License URI`, `Stable tag`, `Requires PHP`, `Requires Plugins` | plugin header + readme header |
| No CSRF on the option-writing callback | the Connect round trip carries a `rd_nonce` minted by `wp_nonce_url()` and verified with `wp_verify_nonce()` before any option is written |
| Escaping and sanitising on output/input | `esc_html__`/`esc_url`/`sanitize_text_field` throughout; settings go through WooCommerce's own settings API |
| Text domain matches the slug | `rentadriver-delivery` |

Submission checklist:

1. **A wordpress.org account** to submit under, and `Contributors:` in `readme.txt` set to that username (`efirity`).
2. **Screenshots: done** — nine `screenshot-1.png` … `screenshot-9.png` in `assets/`, one per caption in `readme.txt`
   (published 2026-09-30). They go in the SVN `assets/` directory, not in the plugin zip.
3. **Banner and icon: ready** in `assets/` — `banner-1544x500.png`, `banner-772x250.png`, `icon-256x256.png`.
   Regenerate with `python3 render-assets.py` (needs ImageMagick and the `sharp` npm package); it renders the approved
   vector identity and bundled Sora font. Copy to SVN `assets/`.
4. Submit the zip at https://wordpress.org/plugins/developers/add/ and wait (typically 1–4 weeks for a first review;
   they will email findings, and the external-services section is what they read first).
5. On approval you get an SVN repo. Releases are `svn cp trunk tags/<version>` after bumping `Stable tag` — the version
   in the PHP header, the readme `Stable tag` and the tag directory must all match or the directory serves the wrong one.

Once listed, merchants install from **Plugins → Add New** and get automatic updates, and channel 1 becomes a convenience
rather than the only way in.

## 3. WooCommerce Marketplace (woocommerce.com)

The heaviest path, and the only one that puts us in front of merchants browsing for a delivery extension.

- Needs a **Woo vendor account** and agreement to their vendor terms; listings can be free or paid, and paid listings
  take a revenue share.
- Woo's review is stricter than wp.org's: their [extension guidelines](https://woocommerce.com/document/create-a-plugin/)
  cover coding standards (WordPress Coding Standards via PHPCS), HPOS compatibility, security, i18n and support SLAs.
  We already declare HPOS compatibility (`custom_order_tables` in `before_woocommerce_init`).
- **Coding standards: passing.** `composer --working-dir=apps/integrations/woocommerce install` followed by
  `composer --working-dir=apps/integrations/woocommerce check` runs the locked WordPress standard. `woocommerce-check` enforces
  it in CI together with local PHP integration fixtures. No local Docker is needed; tooling is excluded from the zip.
- They expect a support channel with a response-time commitment and documentation — `support@rentadriver.ai` and
  https://rentadriver.ai/integrations/woocommerce cover both, but the SLA is a commitment someone has to agree to.
- A listing on the Marketplace does not replace wp.org; most extensions are on both.

Practical order: get channel 2 approved first. The wp.org listing is free, gives auto-updates, and the same readme and
security work is what channel 3 asks for — so doing 2 makes 3 mostly paperwork.

## Bumping a version

1. `Version:` in `rentadriver-delivery/rentadriver-delivery.php`
2. `Stable tag:` and a `== Changelog ==` entry in `rentadriver-delivery/readme.txt`
3. Deploy — channel 1 picks it up from the image with no further action
4. If listed on wp.org, tag it in SVN as above
