# omnipulse/google-reviews

Formerly `omnireview/google` (moved on 2026-11-10, when omnireview and omnifund merged into
omnipulse: `Omnireview\Google\` is now `Omnipulse\GoogleReviews\`, `GoogleGateway` is
`GoogleReviewsProvider`, `GoogleGatewayFactory` is `GoogleReviewsFactory`; the factory is
`google-reviews` (was `google`); this package replaces the former name).

**Google reviews** for [omnisense/omnipulse](https://github.com/glitchr-omnisense/omnipulse): any
place's rating, number of ratings and up to five reviews through the Places API (New); for the
place's owner, every review and replying through the Business Profile API.

```php
use Omnipulse\GoogleReviews\GoogleReviewsFactory;
use Omnipulse\Model\Place;

$provider = (new GoogleReviewsFactory($httpClient))->create(['api_key' => getenv('GOOGLE_PLACES_KEY'), 'language' => 'fr']);

$place = new Place('ChIJLU7jZClu5kcR4PcOOO6p3I0');     // the place ID: the one thing Google lets you keep
$provider->rating($place);                              // 4.6 from 128, with its Google Maps link
$provider->reviews($place);                             // up to five, with their authors
$provider->writeUrl($place);                            // search.google.com/local/writereview?placeid=...
```

Written from Google's documentation of the Places API (New) and the Business Profile API, read on
2026-10-08. **Not verified in real: no key** - but for the link to write a review, which answered.

[Documentation](docs/index.md): the options, the calls, Google's terms, what was verified.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Google LLC. It reaches their services on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Google's terms ([Google
Maps Platform Terms of Service](https://cloud.google.com/maps-platform/terms), [Business Profile
APIs terms](https://developers.google.com/my-business/content/terms)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
