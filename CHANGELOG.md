# Changelog

## Unreleased (1.x)

### Changed
- Token requests counted: the owner's refresh-token request (Business Profile) goes through the provider's guard, checked before it is sent (a
  site's limit naming `token`, the provider's waits) and counted after, in the registry's limiter.
  `GoogleReviewsFactory` gives the provider its guard (`Config::guard()`, the constructor's last argument `tokens`): each token request is counted under `token`; the declared limits name their operations and do not count it. Needs omnisense/contracts 16c8da1 or later (`Config::guard()`; 1568f2c, what the sites'
  vendors hold, has it). Tests/GoogleReviewsProviderTest: a site's limit of 0 tokens a day, the token request never sent.

### Added
- Google reviews's usage policy declared as data: `policy.json` (omnisense/contracts' schema), its terms
  read on 2026-11-10, named in `composer.json` (`extra.omnisense.policy`, and
  `omnisense/contracts` required). The provider implements `DeclaresUsagePolicyInterface` and gives
  the policy to its Capabilities; the factory hands the provider's Backoff to its HTTP calls, so
  that a 429 and its `Retry-After` hold every next call. `Tests/PolicyTest.php`.

### Changed
- `omnireview/google` is now `omnipulse/google-reviews` (2026-11-10: omnireview and omnifund merged
  into omnipulse, Marco's decision; this repository's history kept, from omnireview-google at
  9f6acfa): the namespace `Omnipulse\GoogleReviews`, requiring `omnisense/omnipulse`;
  `composer.json` replaces `omnireview/google`, so that a project never installs both.
  `GoogleGateway` is `GoogleReviewsProvider`, `GoogleGatewayFactory` is `GoogleReviewsFactory`,
  implementing the core's `ProviderInterface` and `ProviderFactory` (were `GatewayInterface` and
  `GatewayFactory`); the factory's name is `google-reviews` (was `google`). Its Terms are unchanged.
- The decoded answers are read with types (`Omnipulse\Support\Data`): the same values, an
  item of a list that is not an object skipped where it stopped the call.

### Added
- CI (glitchr-studio/ci's `php-package.yml`), PHPStan at level max with an empty baseline,
  `phpunit.xml.dist`, and `Tests/BareTest.php`: every class loads with nothing from outside but
  `omnisense/omnipulse` and Symfony's HTTP client contracts.
