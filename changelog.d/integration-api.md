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
