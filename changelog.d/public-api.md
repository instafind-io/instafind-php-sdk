### Added

- `PublicApi` for `/api/public/v1`: `search()`, parallel `searchMany()`, `searchUrl()`, `campaign()` and `campaignMany()`, `campaignEvent()`, `productSuggestions()`, `relatedEvent()`, `imageSearch()`, `suggestions()`, `snippets()`, `facetConfig()` and `keywords()`. No retries and a 5-second timeout by default, for storefront pages.
- Immutable query builders `SearchQuery` and `ProductSuggestionsQuery`, and enums for every closed set of values (`SearchContext`, `SortOrder`, `FacetSort`, `CampaignEvent`, `RelatedEvent`, `SuggestionFill`, `SnippetPlacement`, `KeywordDisplayMode`).
- Typed, immutable answers with `raw()`: `SearchResult` (hits, facets, ranges, banners, redirect, corrections, category matches, ignored filters), `CampaignResult` with `Campaign`, `FacetConfig`, `Snippet`, `Suggestion`, `Keyword`.
- `ClientOptions` to choose the transport, User-Agent, retry policy, timeout and sleeper.
- An unknown index on search, campaign, product suggestions and image search throws `IndexNotFoundException`.
