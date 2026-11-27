# Instafind PHP SDK

The official PHP client for Instafind: public search (`/api/public/v1`) and the
integration API (`/api/integration/v1`). It needs PHP 8.1 or newer and
`ext-json`, and has no required libraries. Requests go out with cURL by
default (which needs `ext-curl`), through any PSR-18 client, or through
WordPress.

## Install

The package is not on Packagist. Add the repository to your `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/instafind-io/instafind-php-sdk" }
    ],
    "require": {
        "instafind/php-sdk": "^1.0"
    }
}
```

Until `v1.0.0` is tagged, pin a commit of `main` instead:
`"instafind/php-sdk": "dev-main#<commit sha>"`.

## Search

```php
use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\PublicApi;
use Instafind\Sdk\Search\SearchQuery;
use Instafind\Sdk\Search\SortOrder;

$search = new PublicApi('https://platform.instafind.io', new ClientOptions(
    userAgent: new UserAgent('my-shop-module', '1.4.0', 'https://shop.example.com/'),
));

$result = $search->search($indexId, SearchQuery::text('running shoes')
    ->limit(24)
    ->page(2)
    ->filter('brand', ['Asics', 'Nike'])
    ->range('price', 50, 120)
    ->sort('price', SortOrder::Asc));

if ($redirect = $result->redirect()) {      // before any output
    header('Location: ' . $redirect->url());
    exit;
}
echo $result->total();
foreach ($result->hits() as $hit) {
    echo $hit->get('title'), ' ', $hit->get('price'), "\n";
}
foreach ($result->facet('brand')?->buckets() ?? [] as $bucket) {
    echo $bucket->value(), ' (', $bucket->count(), ")\n";
}
```

Several searches run in parallel, for example the sections of a page. One
failing does not fail the others:

```php
$results = $search->searchMany($indexId, [
    'new' => SearchQuery::text()->filter('category', ['new'])->limit(8),
    'sale' => SearchQuery::text()->filter('category', ['sale'])->limit(8),
], timeout: 2.0);

foreach ($results as $section => $result) {
    if ($result instanceof \Instafind\Sdk\Exception\InstafindException) {
        continue; // show the section without products
    }
    // $result is a SearchResult
}
```

The public API also has `campaign()`, `productSuggestions()` (with a
`ProductSuggestionsQuery`), `imageSearch()`, `suggestions()`, `snippets()`,
`facetConfig()`, `keywords()`, and the `campaignEvent()` and `relatedEvent()`
calls. By default it doesn't retry and times out after 5 seconds, because a
storefront page should not wait. Each method takes its own `$timeout` too.

## Integration API

With a site API key (`ifk_…`):

```php
use Instafind\Sdk\Auth\StaticKey;
use Instafind\Sdk\IntegrationApi;
use Instafind\Sdk\Schema\FieldType;
use Instafind\Sdk\Schema\IndexSchema;

$api = new IntegrationApi('https://platform.instafind.io', new StaticKey($apiKey));

$key = $api->key();                       // website, scopes, index grant, expiry
$schema = IndexSchema::fromFields([       // checked before anything is sent
    'id' => FieldType::Identifier,
    'title' => FieldType::Title,
    'price' => FieldType::Price,
    'categories' => FieldType::Category,
]);
$index = $api->createIndex($key->websiteId(), $schema, 'el', 'Shop (Greek)');

$write = $api->upsertProducts($index->id(), [
    ['id' => '1', 'title' => 'Shoe', 'price' => 89.9, 'categories' => ['Shoes']],
]);
foreach ($write->errors() as $error) {   // rejections arrive inside a 200
    echo $error->id(), ': ', $error->reason(), "\n";
}
```

To load a whole catalogue, use the bulk loader. It sends batches of up to 500
products, and only the last batch waits until its products are searchable:

```php
$result = $api->bulkLoader()->load($index->id(), $products); // any iterable, a generator too
printf("%d successful, %d rejected in %d batches\n", $result->successful(), $result->failed(), $result->batches());
```

Pass a `BulkListener` to report progress and to carry on past a refused batch.
The integration API also covers fields, search behaviours, filter value trees,
the campaign page, default filters and templates, stored products, analytics,
the index summary, orders and customer erasure. See `IntegrationApi` for the
full list.

## Connect to Instafind (OAuth)

Instead of a key, a shop can connect with OAuth 2.1 and PKCE. Your module
stores the connection by implementing `TokenStore` (for example in a database
row with an atomic lock). The SDK does the rest: discovery, the authorize URL,
the code exchange, refreshing early behind the lock, and revoking.

```php
use Instafind\Sdk\Auth\OAuth\OAuthClient;
use Instafind\Sdk\Auth\OAuth\OAuthConnection;
use Instafind\Sdk\Transport\CurlTransport;

$transport = new CurlTransport();
$server = OAuthClient::discover('https://platform.instafind.io', $transport);
$oauth = new OAuthClient($server, $clientId, $transport);

// 1. Send the shop manager to Instafind; keep $request in the session.
$request = $oauth->authorize('https://shop.example.com/instafind/callback', ['indexes:manage', 'products:write']);
header('Location: ' . $request->url());

// 2. On the callback:
$state = $_GET['state'] ?? null;
$code = $_GET['code'] ?? null;
if (!is_string($state) || !is_string($code) || !$request->matchesState($state)) {
    exit('Invalid state');
}
$tokens = $oauth->exchangeCode($code, $request);
$connection = new OAuthConnection($yourTokenStore, $transport);
$connection->connect($server, $clientId, $tokens);

// 3. From then on the connection is the credential:
$api = new IntegrationApi('https://platform.instafind.io', $connection);
```

## Transports

- `CurlTransport` is the default. It sends parallel requests with `curl_multi`.
- `Psr18Transport` works with any PSR-18 client and needs `psr/http-client`
  and `psr/http-factory`. Its requests run one after another, and timeouts are
  set on the client.
- `WordPressTransport` uses `wp_remote_request()`, and the Requests library for
  parallel calls. Single requests get WordPress's proxy, CA bundle and filters
  (`pre_http_request`, `http_request_args`); parallel ones get the CA bundle
  but skip those filters, and on a site with a proxy or blocked external
  requests they run one after another through `wp_remote_request()`.

```php
use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\Http\UserAgent;
use Instafind\Sdk\Transport\WordPressTransport;

$userAgent = new UserAgent('my-plugin', '2.0.1', home_url('/'));
$options = new ClientOptions(transport: new WordPressTransport(), userAgent: $userAgent);
```

Every request sends
`User-Agent: instafind-php-sdk/<version> (<integration>/<version>; <site>)`,
which Instafind shows on the API key's activity.

## Errors

Every failure of a request extends `Instafind\Sdk\Exception\InstafindException`:

| Exception | When |
|---|---|
| `ApiException` | Instafind answered with an error. It has `status()`, `errorCode()` (the API's `error`, such as `index_locked`), `isRetryable()`, `retryAfter()` and `body()`. |
| `IndexNotFoundException` | A 404 for an index this caller cannot reach (deleted, being deleted, not in the key's grant). It extends `ApiException`. |
| `TransportException` | No answer arrived: DNS, refused connection, TLS, timeout. |
| `InvalidSchemaException` | An index schema breaks a rule of the API. Nothing was sent. |

Mistakes in the calling code are PHP's own exceptions and are not meant to be
caught: `\InvalidArgumentException` for an argument out of range (a limit over
100, more than 500 products in one `upsertProducts()` call, a bad
`UserAgent` part) and `\LogicException` for a transport that cannot run here
(`CurlTransport` without `ext-curl`, `WordPressTransport` outside WordPress).

On the integration API, the SDK repeats temporary answers itself: 429, 503,
and the "wait and retry" 409s, honouring `Retry-After`. It also repeats
idempotent requests that got no answer. It never repeats `createIndex()`. Change
this with `ClientOptions(retryPolicy: new RetryPolicy(...))`.

## Testing your integration

`Instafind\Sdk\Testing\MockTransport` answers from a queue and records every
request:

```php
use Instafind\Sdk\Auth\StaticKey;
use Instafind\Sdk\ClientOptions;
use Instafind\Sdk\IntegrationApi;
use Instafind\Sdk\Testing\MockTransport;

$transport = (new MockTransport())->queueJson(200, ['data' => ['id' => 'k1', 'scopes' => ['products:write']]]);
$api = new IntegrationApi('https://platform.example', new StaticKey('ifk_test'), new ClientOptions(transport: $transport));

$api->key();
$transport->lastRequest()->url(); // https://platform.example/api/integration/v1/key
```

## Stability

The SDK follows [Semantic Versioning](https://semver.org/). Everything not
marked `@internal` is public API. SDK 1.x speaks the API's `public/v1` and
`integration/v1`; an API v2 will be SDK 2.0. In 1.x:

- Response objects are built by the SDK only, so their constructors are
  `@internal`. New fields and accessors arrive in minor versions. Result
  classes are `final`.
- Parameter names are part of the API, because PHP has named arguments.
- Input values from a closed set are enums (`SortOrder`, `RefreshMode`,
  `FieldType` …). Values the API sends back are strings, so a new value on the
  server does not break your code.
- The interfaces you implement are frozen: `Transport`, `Credential`,
  `TokenStore`, `ConnectionObserver`, `BulkListener`, `Sleeper` and `Clock`.
- Dates are `DateTimeImmutable`.

Every release is listed in [CHANGELOG.md](CHANGELOG.md).

## Licence

MIT, see [LICENSE](LICENSE).
