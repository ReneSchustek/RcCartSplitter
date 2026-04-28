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
