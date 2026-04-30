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
