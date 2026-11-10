<?php

namespace Omnipulse\GoogleReviews;

use Omnipulse\Capabilities;
use Omnipulse\Exception\InvalidKeyException;
use Omnipulse\Exception\NotSupportedException;
use Omnipulse\Exception\ProviderException;
use Omnipulse\Http\Answer;
use Omnipulse\Model\Place;
use Omnipulse\Model\Rating;
use Omnipulse\Model\Reply;
use Omnipulse\Model\Review;
use Omnipulse\Model\Terms;
use Omnipulse\RatingInterface;
use Omnipulse\ReplyInterface;
use Omnipulse\ReviewsInterface;
use Omnipulse\Support\Data;
use Omnipulse\WriteUrlInterface;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google: the Places API (New) for anyone's place - its rating, its
 * number of ratings, up to five reviews -, and the Business Profile API for
 * the owner's - every review, and replying.
 *
 *   - Places: GET https://places.googleapis.com/v1/places/{placeId}, X-Goog-Api-Key, X-Goog-FieldMask;
 *   - Business Profile: OAuth 2.0 (the owner's refresh token, scope business.manage),
 *     GET https://mybusiness.googleapis.com/v4/{accounts/a/locations/l}/reviews,
 *     PUT .../reviews/{reviewId}/reply.
 */
final class GoogleReviewsProvider implements RatingInterface, ReviewsInterface, ReplyInterface, WriteUrlInterface, DeclaresUsagePolicyInterface
{
    /** Google reviews's usage policy, as its terms read on the day it says (provenance.verifiedAt). */
    public const POLICY = __DIR__.'/policy.json';

    private static ?UsagePolicy $policy = null;

    public const PLACES = 'https://places.googleapis.com/v1/places/';
    public const BUSINESS = 'https://mybusiness.googleapis.com/v4/';
    public const TOKEN = 'https://oauth2.googleapis.com/token';
    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    private ?string $token = null;
    private int $expires = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly ?string $clientId = null,
        #[\SensitiveParameter] private readonly ?string $clientSecret = null,
        #[\SensitiveParameter] private readonly ?string $refreshToken = null,
        private readonly ?string $language = null,
        private readonly ?Backoff $backoff = null,
    ) {
    }

    public function getName(): string
    {
        return 'google-reviews';
    }

    public function getTitle(): string
    {
        return 'Google';
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(rating: true, reviews: true, reply: $this->owner(), invite: false, writeUrl: true, policy: $this->usagePolicy());
    }

    public function terms(): Terms
    {
        return new Terms(
            cacheFor: 0,
            maxReviews: 5,
            attribution: 'Google Maps',
            authors: true,
            link: true,
            noIndex: false,
            rules: [
                'Show the Google Maps logo, or the words "Google Maps" unchanged where space is short; the Google logo when no Google map is shown.',
                'Credit each review\'s author: their name, photo and profile link (the photo at least, where space is short).',
                'Say how the reviews are ordered and filtered: by relevance, as Google gives them.',
                'Keep nothing but the place ID: no other content cached, pre-fetched or stored.',
                'The Business Profile API is for the place\'s owner, on their own account.',
            ],
            source: 'https://developers.google.com/maps/documentation/places/web-service/policies',
        );
    }

    public function rating(Place $place): Rating
    {
        if (null !== $place->owner && $this->owner()) {
            $data = $this->business('GET', $place->owner.'/reviews', ['query' => ['pageSize' => 1]]);

            return new Rating('google', $data->float('averageRating') ?? 0.0, $data->int('totalReviewCount') ?? 0, url: self::maps($place));
        }
        $data = $this->place($place, 'id,displayName,rating,userRatingCount,googleMapsUri');

        return new Rating('google', $data->float('rating') ?? 0.0, $data->int('userRatingCount') ?? 0, url: self::str($data, 'googleMapsUri'));
    }

    public function reviews(Place $place, int $limit = 5, ?string $language = null): array
    {
        if (null !== $place->owner && $this->owner()) {
            $data = $this->business('GET', $place->owner.'/reviews', ['query' => ['pageSize' => max(1, min(50, $limit)), 'orderBy' => 'updateTime desc']]);

            return \array_slice(array_map(fn (Data $r) => new Review(
                'google',
                self::str($r, 'reviewId') ?? self::str($r, 'name') ?? '',
                (float) (self::STARS[self::str($r, 'starRating') ?? ''] ?? 0),
                self::str($r, 'comment'),
                author: self::str($r, 'reviewer.displayName'),
                authorPhoto: self::str($r, 'reviewer.profilePhotoUrl'),
                publishedAt: self::date($r->raw('createTime')),
                // The Business Profile API gives no review its own address: the place on Google Maps.
                url: self::maps($place),
                reply: null !== ($reply = self::str($r, 'reviewReply.comment')) ? new Reply($reply, self::date($r->raw('reviewReply.updateTime'))) : null,
            ), self::objects($data, 'reviews')), 0, $limit);
        }

        $data = $this->place($place, 'id,reviews,googleMapsUri', $language);

        return \array_slice(array_map(static fn (Data $r) => new Review(
            'google',
            self::str($r, 'name') ?? '',
            $r->float('rating') ?? 0.0,
            self::str($r, 'text.text') ?? self::str($r, 'originalText.text'),
            language: self::str($r, 'text.languageCode') ?? self::str($r, 'originalText.languageCode'),
            author: self::str($r, 'authorAttribution.displayName'),
            authorUrl: self::str($r, 'authorAttribution.uri'),
            authorPhoto: self::str($r, 'authorAttribution.photoUri'),
            publishedAt: self::date($r->raw('publishTime')),
            url: self::str($r, 'googleMapsUri') ?? self::str($data, 'googleMapsUri'),
            translated: null !== $r->raw('text.languageCode') && null !== $r->raw('originalText.languageCode') && $r->raw('text.languageCode') !== $r->raw('originalText.languageCode'),
        ), self::objects($data, 'reviews')), 0, $limit);
    }

    public function reply(Place $place, Review $review, string $text): Reply
    {
        if (!$this->owner() || null === $place->owner) {
            throw NotSupportedException::operation('google-reviews', 'reply', 'replying is the owner\'s, through the Business Profile API: client_id, client_secret, refresh_token, and the Place\'s owner ("accounts/<a>/locations/<l>")');
        }
        $data = $this->business('PUT', $place->owner.'/reviews/'.rawurlencode($review->id).'/reply', ['json' => ['comment' => $text]]);

        return new Reply(self::str($data, 'comment') ?? $text, self::date($data->raw('updateTime')));
    }

    public function writeUrl(Place $place): string
    {
        return 'https://search.google.com/local/writereview?placeid='.rawurlencode($place->id);
    }

    private static function maps(Place $place): string
    {
        return 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $place->name ?? $place->id, 'query_place_id' => $place->id]);
    }

    private function owner(): bool
    {
        return null !== $this->clientId && null !== $this->clientSecret && null !== $this->refreshToken;
    }

    private function place(Place $place, string $fields, ?string $language = null): Data
    {
        if (null === $this->apiKey) {
            throw InvalidKeyException::from('google', 'The Places API needs an API key (the api_key option).');
        }
        $answer = Answer::send($this->http, 'google', 'GET', self::PLACES.rawurlencode($place->id), [
            'headers' => ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => $fields],
            'query' => array_filter(['languageCode' => $language ?? $this->language]),
        ], $this->backoff);

        return $this->read($answer);
    }

    /** @param array<string, mixed> $options */
    private function business(string $method, string $path, array $options): Data
    {
        $answer = Answer::send($this->http, 'google', $method, self::BUSINESS.$path, ['headers' => ['Authorization' => 'Bearer '.$this->token()]] + $options, $this->backoff);

        return $this->read($answer);
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->expires) {
            return $this->token;
        }
        $answer = Answer::send($this->http, 'google', 'POST', self::TOKEN, ['body' => ['grant_type' => 'refresh_token', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'refresh_token' => $this->refreshToken]]);
        $data = Data::of(json_decode($answer->body, true));
        $token = self::str($data, 'access_token');
        if ($answer->status >= 400 || null === $token) {
            throw InvalidKeyException::from('google', 'The owner\'s refresh token was refused: '.(self::str($data, 'error_description') ?? self::str($data, 'error') ?? ''), self::str($data, 'error'));
        }
        $this->token = $token;
        $this->expires = time() + max(0, ($data->int('expires_in') ?? 3600) - 60);

        return $this->token;
    }

    private function read(Answer $answer): Data
    {
        if ($answer->status < 400) {
            return '' === trim($answer->body) ? new Data() : new Data($answer->json());
        }
        $error = Data::of(json_decode($answer->body, true))->data('error');
        $message = \sprintf('HTTP %d, %s: %s', $answer->status, self::str($error, 'status') ?? '', self::str($error, 'message') ?? mb_substr(trim($answer->body), 0, 200));
        throw \in_array($answer->status, [401, 403], true) ? InvalidKeyException::from('google', $message, self::str($error, 'status')) : ProviderException::from('google', $message, self::str($error, 'status'));
    }

    /** A value of the answer as it is, when it is a string or a number. */
    private static function str(Data $data, string $path): ?string
    {
        $value = $data->raw($path);

        return \is_scalar($value) ? (string) $value : null;
    }

    /** @return list<Data> the objects of the list at the path */
    private static function objects(Data $data, string $path): array
    {
        return array_values(array_filter($data->list($path), static fn (Data $item): bool => [] !== $item->values));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return \is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
