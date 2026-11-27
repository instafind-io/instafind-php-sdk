# Changelog

All notable changes to the Instafind PHP SDK. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the SDK uses
[Semantic Versioning](https://semver.org/): everything not marked `@internal`
is public API. Changes are written as files in `changelog.d/`, never here:
`scripts/release.sh` moves them in.

## [Unreleased]

## [1.0.0] - 2026-11-27

### Added

- `IntegrationApi` for `/api/integration/v1`:
  - credential and website: `key()`, `website()`
  - indexes and their settings: `createIndex()` (never repeated), `index()`, `fieldDefinitions()`, `addField()`, `updateField()`, `searchBehaviors()`, `putSearchBehaviors()`, `putFilterValueTree()`, `setCampaignPage()`, `setUpDefaultFilters()`, `setUpDefaultTemplate()`
  - what an index holds: `storedProducts()`, `storedProduct()`, `countProducts()`
  - reports: `analytics()`, `summary()`
  - orders: `sendOrders()`, `forgetCustomer()`
  - products: `upsertProducts()` (up to 500, with an optional `RefreshMode`), `upsertProduct()`, `deleteProduct()` (idempotent)
- Typed answers with `raw()`: `KeyInfo`, `Website`, `SearchIndex`, `FieldDefinition`, `SearchBehaviors`, `StoredProductsPage`, `StoredProduct`, `Analytics`, `IndexSummary`, `OrdersResult`, `BulkWriteResult` with per-product `ProductWriteResult`.
- `BulkLoader` streams any number of products in batches of up to 500; only the last batch waits until it is searchable. It counts rejections inside a 200 with refused batches in `BulkResult`, and a `BulkListener` can report progress and carry on past a refused batch.
- `IndexSchema` and the `FieldType` enum (including `number`) check field ids, types, the single identifier and the langcode before an index is created.
- On the routes of one index, a 404 `not_found` throws `IndexNotFoundException`.
- The README: install through the GitHub `vcs` repository, search, the integration API, the bulk loader, "Connect to Instafind", transports, errors, testing, and the stability rules.
- A contract test against a copy of the backend's OpenAPI document (`openapi/instafind-v1.json`, refreshed with `scripts/update-openapi.sh`). It checks that every request the SDK sends is a documented operation with declared parameters, that every operation is covered, and that the top-level response fields the typed objects read are in the schemas.
- `PublicApi` for `/api/public/v1`: `search()`, parallel `searchMany()`, `searchUrl()`, `campaign()` and `campaignMany()`, `campaignEvent()`, `productSuggestions()`, `relatedEvent()`, `imageSearch()`, `suggestions()`, `snippets()`, `facetConfig()` and `keywords()`. No retries and a 5-second timeout by default, for storefront pages.
- Immutable query builders `SearchQuery` and `ProductSuggestionsQuery`, and enums for every closed set of values (`SearchContext`, `SortOrder`, `FacetSort`, `CampaignEvent`, `RelatedEvent`, `SuggestionFill`, `SnippetPlacement`, `KeywordDisplayMode`).
- Typed, immutable answers with `raw()`: `SearchResult` (hits, facets, ranges, banners, redirect, corrections, category matches, ignored filters), `CampaignResult` with `Campaign`, `FacetConfig`, `Snippet`, `Suggestion`, `Keyword`.
- `ClientOptions` to choose the transport, User-Agent, retry policy, timeout and sleeper.
- A 404 `index_not_found` throws `IndexNotFoundException`, on every route.
- Transports: `CurlTransport` (the default, parallel requests with curl_multi), `Psr18Transport` for any PSR-18 client, `WordPressTransport` over the WordPress HTTP API, and `Testing\MockTransport` for tests.
- Credentials: `StaticKey` for site API keys, and "Connect to Instafind" with OAuth 2.1 and PKCE: `OAuthClient` (discovery, authorize URL, code exchange, refresh, revoke) and `OAuthConnection`, which renews the access token behind the lock of a host-provided `TokenStore`.
- Errors as exceptions under `InstafindException`: `ApiException` (status, error code, retryable, Retry-After), `IndexNotFoundException`, `TransportException`, `InvalidSchemaException`.
- `RetryPolicy`: temporary answers (429, 503, the "wait and retry" 409s) and unanswered idempotent requests are repeated.
- Every request sends `User-Agent: instafind-php-sdk/<version> (<integration>/<version>; <site>)` from `UserAgent` and `Sdk::VERSION`.

[Unreleased]: https://github.com/instafind-io/instafind-php-sdk/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/instafind-io/instafind-php-sdk/releases/tag/v1.0.0
