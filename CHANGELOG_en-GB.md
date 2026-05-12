# 2.1.0

- Added: TMMS hint text now freely configurable across three scopes — product custom field `rc_cart_splitter_tmms_info_message`, category custom field `rc_cart_splitter_cat_tmms_info_message` (category chain), plugin configuration `RcCartSplitter.config.tmmsInformationMessage`. Resolution order: product > category > plugin config > snippet default (`rc-cart-splitter.tmmsInformationMessage`). Replaces the TMMS default hint ("previous input will be overwritten"), which was factually wrong with RcCartSplitter active. Twig decoration on `buy_widget_configurator_include_customerinput_informationmessage_content`; container, visibility and icon remain owned by the TMMS plugin.
- Added: `TmmsInformationMessageResolver` service with its own `CategoryChainLoader` (no cross-plugin coupling) and scope logging on channel `rc_cart_splitter`. The resolved text is attached to the product and quick-view page via the `rcCartSplitterTmmsInfo` page extension.
- Added: Plugin migrations for the two custom-field sets `rc_cart_splitter` (product) and `rc_cart_splitter_category` (category), each with translated textarea fields.
- Changed: Plugin template priority set to `-1` (default 0) so the TMMS block override wins deterministically.

# 2.0.2

- Fixed: TMMS inputs on split positions (e.g. multiple floor-profile items with different mitre cuts and a length suffix from RcDynamicPrice) are no longer overwritten by session values. The session fallback in the input provider now emits the same payload shape as the JS path (`rcTmmsActive` plus `rcTmmsField<N>Value`/`Label`), and the display subscriber removes leaked TMMS extensions for fields the position did not fill. Affects all TMMS field types — select fields in particular.
- Improved: The display correction now writes the label into the TMMS extension as well (previously value only).
- Improved (security): TMMS session data is now sanitised at the read layer (`TmmsPayloadReader::readSessionData`) using the same profile as the request path — `strip_tags` plus a 2000-character cap. Closes an asymmetry that let raw session strings flow unfiltered into `lineItem->payload` and `order_line_item.custom_fields` (stored-XSS / payload-bomb hardening, defence-in-depth beyond Twig auto-escape).
- Cleanup: All TMMS schema magic strings (`rcTmmsField<N>Value/Label`, `tmms_customer_input_<N>_value/label/placeholder/fieldtype`, `tmmsLineItemCustomerInput<N>`, `tmms_customer_input_<N>_<productNumber>`) consolidated into `TmmsConstants` builder methods (`payloadValueKey`, `payloadLabelKey`, `sessionKey`, `extensionName`, `customFieldValueKey`, `customFieldLabelKey`, `customFieldPlaceholderKey`, `customFieldFieldtypeKey`). Schema changes now require a single-file edit.
- Cleanup: `composer quality` runs portably on Windows and Linux (`php vendor/bin/...` wrappers), `.php-cs-fixer.php` now covers `tests/` as well.

# 2.0.1

- Docs: README now spells out the detail-payload contract (`source` required, `suffix` recommended, plugin-specific fields non-binding) and the self-loop convention for plugins that both fire and listen to the event. Naming rationale for the neutral event namespace documented (was internal note only).
- Pure documentation clarification, no behavior change. Patch bump.

# 2.0.0

> **Breaking change.** Storefront JS now only listens on the generic `rcSuffixChanged` event. Suffix plugins must dispatch this event after every value change; the previous hard-coded event list (`rcMeterLengthChanged`, `rcColorPickerChanged`) is no longer observed. Plugin-specific events remain available for internal listeners.

- Changed: Generic suffix event `rcSuffixChanged` is the sole trigger for LineItem ID recomputation. The event name is exposed as a static constant `CartSplitterPlugin.SUFFIX_CHANGED_EVENT` and locked in by a JS unit test (POLS).
- Improved: Adding a new suffix plugin no longer requires a pull request against `cart-splitter.plugin.js` — set the suffix and dispatch the generic event.
- Changed: Plugin interaction protocol (`.ai/rules/plugin-interaction.md`) and README document the new event contract and the simpler extension path.

### Upgrade notes
- Before upgrading to v2.0.0, every active suffix plugin must implement the new event contract: **RcColorPicker ≥ 2.2.0** and **RcDynamicPrice ≥ 1.7.0**. Without the updated suffix plugins, suffix changes on the form only propagate on the next regular TMMS input event — the feature does not break, but reacts sluggishly.
- Standalone suffix plugins (without RcCartSplitter) are unaffected; the additional event is a no-op without a listener.

# 1.2.0

- Added: Screen-reader group context on the customer-inputs list via `aria-label` — new snippets in German and English (WCAG 1.3.1)
- Improved: Correction service narrowed to one public method and sealed (final) — slim interface for clean tests and extensions (DIP/FCoI)
- Improved: Defensive cap (500 positions) when loading order line items for correction, hardened validation of session data against type manipulation, DB hiccups during AddToCart are now logged
- Improved: Dedicated logging channel `rc_cart_splitter`, correction errors now write the full stack trace instead of just the message
- Improved: Local quality gate also checks Twig syntax and XML well-formedness, CI runs PHPUnit with pcov coverage and a threshold gate (services 80 %, subscribers 60 %)
- Improved: README extended with architecture notes for the two DBAL exceptions, a "further suffix plugins" how-to and the BFSG contrast baseline (6.76:1 against white — well above the WCAG-AA threshold)
- Improved: More descriptive variable names in the LineItem ID computation in the storefront JavaScript
- Fixed: PHPUnit runs without an active coverage driver no longer abort — coverage configuration now lives only in `composer coverage`

# 1.1.0

- Improved: Correction of `order_line_item.custom_fields` runs as a single batch CASE-WHEN-UPDATE inside one transaction instead of N round-trips
- Improved: Input capture decoupled from TMMS — additional input plugins can hook in via the `rc_cart_splitter.input_provider` tag
- Improved: TMMS payload reader with schema and length validation (protects against payload bombs)
- Improved: Aggregated logging and error handling in the correction path — DB errors no longer abort the checkout

# 1.0.2

- Added: GitHub Actions pipeline for PHPStan, PHP CS Fixer and PHPUnit
- Improved: Service extraction into `OrderInputCorrectionService`, unit tests
- Fixed: Several review findings (FQCN imports, snippets, priority)

# 1.0.1

- Fixed: Order positions now receive the correct TMMS inputs per split — TMMS previously overwrote all positions of the same product with the last value

# 1.0.0

- Added: Automatic position splitting for different customer inputs
- Added: TMMS inputs are persisted per position in the `LineItem` payload
- Added: Correction of the TMMS "verify input" display in Mini-Cart, Cart and Confirm
- Added: Generic suffix protocol for `LineItem` IDs — other plugins (e.g. RcDynamicPrice) integrate without code changes
