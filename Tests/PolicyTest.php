<?php

namespace Omnipulse\GoogleReviews\Tests;

use Omnipulse\Exception\OmnipulseException;
use Omnipulse\Exception\UsageLimitReachedException;
use Omnipulse\GoogleReviews\GoogleReviewsFactory;
use Omnipulse\GoogleReviews\GoogleReviewsProvider;
use Omnipulse\Model\Place;
use Omnipulse\Registry;
use Omnipulse\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/**
 * GoogleReviewsProvider's usage policy (policy.json) through omnipulse's registry:
 * as declared, a site's limit counted with the call over it never made,
 * a 429 and its Retry-After holding every next call, a tightening accepted
 * and a looser one refused. No network: recorded answers only.
 */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['api_key' => 'not-a-real-key'];

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = (new Registry([new GoogleReviewsFactory(RecordedHttpClient::routes([]))], ['p' => ['factory' => 'google-reviews', 'options' => self::OPTIONS]]))->policy('p');

        self::assertFileExists(GoogleReviewsProvider::POLICY);
        self::assertEquals(UsagePolicy::fromFile(GoogleReviewsProvider::POLICY), $policy);
        self::assertSame('google-reviews', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame(['300/minute/application/rating+reply+reviews', '10/minute/key/reply'], array_map(static fn ($l) => $l->id(), $policy->rateLimits ?? []));
        self::assertSame(true, $policy->requirements->key);
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = RecordedHttpClient::routes([]);
        $registry = new Registry([new GoogleReviewsFactory($http)], ['p' => ['factory' => 'google-reviews', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'day', 'operations' => ['rating']]]]]]);

        try {
            $registry->rating('p')->rating(new Place('ChIJ-not-a-place'));
        } catch (UsageLimitReachedException $e) {
            throw $e;
        } catch (OmnipulseException) {
            // Nothing recorded: the provider's own answer to that, counted all the same.
        }
        $made = \count($http->requests);
        self::assertGreaterThan(0, $made);
        try {
            $registry->rating('p')->rating(new Place('ChIJ-not-a-place'));
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnipulseException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/day/application/rating', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = new RecordedHttpClient(static fn (): array => [429, '{"message":"too many requests"}', ['retry-after' => ['120']]]);
        $registry = new Registry([new GoogleReviewsFactory($http)], ['p' => ['factory' => 'google-reviews', 'options' => self::OPTIONS]]);

        try {
            $registry->rating('p')->rating(new Place('ChIJ-not-a-place'));
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('google-reviews answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        $made = \count($http->requests);
        try {
            $registry->rating('p')->rating(new Place('ChIJ-not-a-place'));
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertCount($made, $http->requests, 'the wait held the next request back');
    }

    public function testATighteningIsAccepted(): void
    {
        $registry = new Registry([new GoogleReviewsFactory(RecordedHttpClient::routes([]))], ['p' => ['factory' => 'google-reviews', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 10, 'window' => 'minute']], 'rights' => ['caching' => ['maxTtl' => 0]]]]]);

        $policy = $registry->policy('p');
        self::assertContains('10/minute/application/*', array_map(static fn ($l) => $l->id(), $policy->rateLimits ?? []));
        self::assertSame(0, $policy->rights->caching->maxTtl);
    }

    public function testASiteCannotLoosenThePolicy(): void
    {
        $registry = new Registry([new GoogleReviewsFactory(RecordedHttpClient::routes([]))], ['p' => ['factory' => 'google-reviews', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 600, 'window' => 'minute', 'operations' => ['rating', 'reviews', 'reply']]]]]]);

        $this->expectException(InvalidPolicyException::class);
        $registry->rating('p');
    }

    /** Its Terms (terms(), what the family's display follows) and its policy's rights say the same, or the policy is stricter. */
    public function testItsTermsAreItsPolicysRights(): void
    {
        $provider = (new GoogleReviewsFactory(RecordedHttpClient::routes([])))->create(self::OPTIONS);
        self::assertInstanceOf(GoogleReviewsProvider::class, $provider);
        $terms = $provider->terms()->rights();
        $rights = $provider->usagePolicy()->rights;

        self::assertSame($terms->attribution->text, $rights->attribution->text);
        self::assertTrue($rights->attribution->required);
        self::assertGreaterThanOrEqual(self::rank($terms->caching->permission), self::rank($rights->caching->permission));
        if (null !== $terms->caching->maxTtl) {
            self::assertLessThanOrEqual($terms->caching->maxTtl, $rights->caching->maxTtl ?? 0);
        }
    }

    private static function rank(Permission $permission): int
    {
        return array_search($permission, [Permission::Allowed, Permission::Unknown, Permission::Contract, Permission::Forbidden], true) ?: 0;
    }
}
