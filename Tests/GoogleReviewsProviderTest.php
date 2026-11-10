<?php

namespace Omnipulse\GoogleReviews\Tests;

use Omnipulse\Exception\InvalidConfigException;
use Omnipulse\Exception\InvalidKeyException;
use Omnipulse\Exception\NotSupportedException;
use Omnipulse\Exception\ProviderException;
use Omnipulse\GoogleReviews\GoogleReviewsProvider;
use Omnipulse\GoogleReviews\GoogleReviewsFactory;
use Omnipulse\Model\Place;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from Google's documentation (Place Details (New), the
 * Business Profile API's reviews): no key was used. The write-review link
 * answered 200 on 2026-10-08.
 */
final class GoogleReviewsProviderTest extends TestCase
{
    private const PLACE = 'ChIJLU7jZClu5kcR4PcOOO6p3I0';

    /** @var list<array{string, string, array<mixed>}> */
    private array $calls = [];

    /**
     * @param list<string|MockResponse> $answers
     * @param array<string, mixed>      $options
     */
    private function provider(array $answers, array $options = ['api_key' => 'places-key', 'language' => 'fr']): GoogleReviewsProvider
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$url);
            if ($answer instanceof MockResponse) {
                return $answer;
            }
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file.'.json'), ['http_code' => (int) $status]);
        });
        $provider = (new GoogleReviewsFactory($http))->create($options);
        self::assertInstanceOf(GoogleReviewsProvider::class, $provider);

        return $provider;
    }

    public function testThePlacesRatingAndReviewsWithTheirAuthors(): void
    {
        $provider = $this->provider(['place', 'place']);
        $rating = $provider->rating(new Place(self::PLACE));

        self::assertSame([4.6, 128, 'https://maps.google.com/?cid=10223305813012340704'], [$rating->value, $rating->count, $rating->url]);
        [$method, $url, $options] = $this->calls[0];
        self::assertSame(['GET', 'https://places.googleapis.com/v1/places/'.self::PLACE.'?languageCode=fr'], [$method, $url]);
        $headers = self::headers($options);
        self::assertStringContainsString('X-Goog-Api-Key: places-key', $headers);
        self::assertStringContainsString('X-Goog-FieldMask: id,displayName,rating,userRatingCount,googleMapsUri', $headers);

        $reviews = $provider->reviews(new Place(self::PLACE));
        self::assertCount(2, $reviews);
        self::assertSame(['Camille R.', 'https://www.google.com/maps/contrib/1011', 'https://lh3.googleusercontent.com/a/camille', '2026-09-23', 5.0, false], [$reviews[0]->author, $reviews[0]->authorUrl, $reviews[0]->authorPhoto, $reviews[0]->publishedAt?->format('Y-m-d'), $reviews[0]->rating, $reviews[0]->translated]);
        self::assertTrue($reviews[1]->translated, 'Google\'s translation of an English review');
        self::assertStringContainsString('X-Goog-FieldMask: id,reviews,googleMapsUri', self::headers($this->calls[1][2]));
    }

    public function testTheOwnersReviewsAndReply(): void
    {
        $provider = $this->provider(['token', 'business-reviews', 'reply'], ['client_id' => 'cid', 'client_secret' => 'cs', 'refresh_token' => 'rt']);
        $place = new Place(self::PLACE, 'Maison Érable', 'accounts/101/locations/202');

        $reviews = $provider->reviews($place, 10);
        self::assertSame(['AbFvOqm1', 5.0, 'Merci Camille !', 3.0, null], [$reviews[0]->id, $reviews[0]->rating, $reviews[0]->reply?->text, $reviews[1]->rating, $reviews[1]->text]);
        self::assertSame('https://www.google.com/maps/search/?api=1&query=Maison+%C3%89rable&query_place_id='.self::PLACE, $reviews[0]->url, 'no address of its own: the place');
        parse_str(self::body($this->calls[0][2]), $grant);
        self::assertSame(['grant_type' => 'refresh_token', 'client_id' => 'cid', 'client_secret' => 'cs', 'refresh_token' => 'rt'], $grant);
        self::assertSame('https://mybusiness.googleapis.com/v4/accounts/101/locations/202/reviews?pageSize=10&orderBy=updateTime%20desc', $this->calls[1][1]);

        $reply = $provider->reply($place, $reviews[1], 'Merci pour votre retour, à bientôt !');
        self::assertSame(['PUT', 'https://mybusiness.googleapis.com/v4/accounts/101/locations/202/reviews/AbFvOqm2/reply'], [$this->calls[2][0], $this->calls[2][1]]);
        self::assertSame(['comment' => 'Merci pour votre retour, à bientôt !'], json_decode(self::body($this->calls[2][2]), true));
        self::assertSame('2026-10-08T09:00:00+00:00', $reply->at?->format(\DATE_ATOM));
        self::assertTrue($provider->capabilities()->reply);
    }

    public function testTermsLinksAndErrors(): void
    {
        $provider = $this->provider([]);
        self::assertSame('https://search.google.com/local/writereview?placeid='.self::PLACE, $provider->writeUrl(new Place(self::PLACE)));
        self::assertSame([0, 5, 'Google Maps', false], [$provider->terms()->cacheFor, $provider->terms()->maxReviews, $provider->terms()->attribution, $provider->terms()->allowsCaching(60)]);
        self::assertSame(['rating', 'reviews', 'writeUrl'], $provider->capabilities()->contracts());

        try {
            $provider->reply(new Place(self::PLACE), new \Omnipulse\Model\Review('google', 'r', 5), 'Merci');
            self::fail();
        } catch (NotSupportedException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->provider(['not-found:404'])->rating(new Place('nope'));
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame('NOT_FOUND', $e->providerCode);
        }
        try {
            $this->provider(['key-refused:403'])->rating(new Place(self::PLACE));
            self::fail();
        } catch (InvalidKeyException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidConfigException::class);
        (new GoogleReviewsFactory(new MockHttpClient()))->create([]);
    }

    /** @param array<mixed> $options a recorded request's options: its headers, one a line */
    private static function headers(array $options): string
    {
        $headers = $options['headers'] ?? [];

        return implode("\n", \is_array($headers) ? array_filter($headers, 'is_string') : []);
    }

    /** @param array<mixed> $options a recorded request's options: its body */
    private static function body(array $options): string
    {
        return \is_string($options['body'] ?? null) ? $options['body'] : '';
    }
}
