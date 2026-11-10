# Changelog

## Unreleased (1.x)

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
