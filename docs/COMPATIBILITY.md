# Compatibility

## Tested matrix (real runs, 2026-10-22/23)

| Environment | What ran | Result |
| --- | --- | --- |
| WordPress 7.1.3, PHP 8.3.6, MariaDB 10.11 (local) | PHPUnit integration (58 tests), ZIP smoke (27 checks), HTTP agent/security (26), private worker e2e, browser UI e2e (11), Chromium worker fixture (10) | pass |
| WordPress 6.9.10, PHP 8.3.6 (local) | PHPUnit integration, ZIP smoke (27), HTTP agent/security incl. Abilities + MCP Adapter 0.7.1 (26) | pass |
| WordPress 6.5.13, PHP 8.3.6 (local) | PHPUnit integration (4 Abilities tests skipped: API absent before 6.9), ZIP smoke (26), HTTP agent/security (22, Abilities/MCP version-gated) | pass |
| GitHub Actions (ubuntu-latest, MariaDB 10.11 service) | `php -l` on PHP 7.4/8.0/8.1/8.2/8.3/8.4; unit on 7.4/8.1/8.3/8.4; integration on PHP 7.4 + WP 6.5.13, 8.1 + 6.9.10, 8.3 + 7.1.3, 8.4 + 7.1.3; JS + Chromium; ZIP build + install smoke on WP 6.5.13 and 7.1.3 | see the PR checks / [run 39880430340](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39880430340) (all 17 jobs green) |
| Static | WordPress Coding Standards 3 + PHPCompatibilityWP (testVersion 7.4-) | 0 errors, 0 warnings |

PHP 7.4 was exercised in CI only (the local sandbox has PHP 8.3 only).

## WordPress feature gates

| Feature | Requirement | Behaviour when missing |
| --- | --- | --- |
| Core plugin (inventory, M1–M6, REST, WP-CLI, Human Mode) | WP 6.5+, PHP 7.4+ | refuses to boot with an admin notice below the minimums (no fatal error) |
| Abilities API (`wp_register_ability`) | WP 6.9+ | not registered; status reports `abilities_api: false`; REST unaffected |
| MCP | WP 6.9+ and the official MCP Adapter plugin (tested 0.7.1) | status reports `mcp_adapter: false`; admin notice only if MCP exposure is enabled |
| Application Passwords | HTTPS or `WP_ENVIRONMENT_TYPE=local` (WordPress rule) | Agent page explains why they are unavailable |
| XLSX export | PHP `zip` extension | CSV export still available |
| Unicode normalization | PHP `intl` recommended | built-in Vietnamese NFC composer used |

## Language plugins

| Framework | Detection | Tested |
| --- | --- | --- |
| Polylang | `pll_get_post_language()`, translations via `pll_get_post_translations()` | Polylang 3.9.1 (real plugin from source) on WP 6.9.10 and 7.1.3; API double in PHPUnit. Polylang 3.9.1 requires WP 6.8+, so WP 6.5.13 runs used the site language |
| WPML | public filters (`wpml_post_language_details`, `wpml_element_trid`, `wpml_get_element_translations`, …) | code path only, **not tested** with a real WPML install (commercial plugin not available here) |
| Nguyen Ha VI-EN Core (`nguyen-ha-vi-en`, the custom VI/EN plugin of blog.rentbikehanoi.com, source in the owner's `thuexemayhanoi/wordpress-theme` repository) | its public read API `NH_VIEN_Links` (`language_of`, `en_for_vi`, `vi_for_en`, `for_page`, `en_only_term_ids`); English pages without a page pair = below `/en/` | `NhVienLanguageTest` against the **real plugin class** (CI checks out commit `d8f6b58`); the read-only pilot runs the real plugin on a disposable copy. Read only: its options are never written |
| URL prefix (e.g. `/en/`) | Settings → URL prefix rules | unit/integration tested |
| None | site language for every document | tested on WP 6.5.13 |

## The blog's existing stack (read-only audit of the owner's repositories, 2026-10-23)

| Component | Finding | Consequence for this plugin |
| --- | --- | --- |
| Theme (latest record: *Nguyen Ha Editorial*, repo `wordpress-theme`; an older record names *Nguyễn Hà Glass 2026*) | prints no JSON-LD/meta/canonical (Yoast owns them); builds a TOC from `h2/h3` in `the_content`; has its own related-posts block with no off switch; fixed home/footer link block | related-content output stays **off** (default); if ever enabled inside content its heading would enter the TOC; menu/footer links are outside the scanned link graph (stated with every orphan list) |
| Yoast SEO | the single source of schema, meta, canonical, breadcrumbs | read only; this plugin prints none of them |
| Nguyen Ha VI-EN Core | `/en/` routing, hreflang, explicit VI⇔EN pairs; front-end queries scoped by language (REST/admin/cron/CLI are not) | locales and pairs read through its API; translations never inferred into pairs; "possible translation" is only a suggestion |
| Multi AI SEO Content Factory (`wp-plugin` repo, `MASF\`, `masf_*`) | a different product (AI article drafts, Yoast meta writes) | no name, table, route or option collisions; `_masf_*` meta is never touched |
| `theme-thuha` (Rentbike Hanoi 1.2.0) | theme of rentbikehanoi.com, not the blog | no collisions (`rbh_*`); not a target |

## SEO plugins (read-only metadata)

Yoast SEO, Rank Math, SEOPress, The SEO Framework, All in One SEO: title, description, focus
keyword, canonical, robots/noindex are read from their documented post meta keys; nothing is ever
written. Tested with Yoast-style meta (`_yoast_wpseo_focuskw`) in fixtures; the real plugins were
**not installed** in this build. The core fallback uses WordPress' own title and `blog_public`.

## Browsers (Tier 1 inference)

| Browser | Status |
| --- | --- |
| Chromium (Playwright, headless, GitHub Actions) | **real Qwen3-Embedding-0.6B verified on WASM** (q4f16 and fp16: self-test, vectors equal to ONNX Runtime CPU, verified cache reload); WebGPU only through the SwiftShader software adapter (self-test passed, no GPU) |
| Desktop Chrome/Edge with a real GPU (WebGPU) | intended fastest target; **speed not measured** (no GPU in CI) |
| Safari < 26 without WebGPU | plain (non-asyncify) WASM build selected automatically; not tested |
| iPhone / iPadOS | detected and warned (emulated UA tested in Chromium, **no real iPhone tested**) |
| No WebAssembly / no module workers | "unsupported on this device", model loading disabled, lexical tier unaffected (tested) |

## Model profile

`onnx-community/Qwen3-Embedding-0.6B-ONNX` at revision `c25a394dd583836952667c12f008335071b3f43d`
(base `Qwen/Qwen3-Embedding-0.6B` @ `97b0c614be4d77ee51c0cef4e5f07c00f9eb65b3`, Apache-2.0).
File sizes and SHA-256 values in `includes/Semantic/ModelProfile.php` were taken from the Hugging
Face Hub metadata on 2026-10-22; the files themselves could not be downloaded from the build
sandbox. Precisions: q4f16 (default, 567 MB graph), q8 (614 MB), fp16 (1.2 GB). Dimensions 1024
or MRL-truncated 768/512/256.
