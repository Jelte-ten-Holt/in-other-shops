<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Agent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InOtherShops\Tests\Support\SeedsReadApiFixtures;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read API reproduces the bookkeeping app's fixtures (tests/Fixtures/Books,
 * copied from in-other-books, where the app's shop driver was written against
 * them first). For every pull: the rows its responses describe are seeded,
 * then every request it made is made again and the response compared with the
 * fixture's — every key, in order, every value with its type.
 *
 * Whitespace and escaping are not part of the contract: both sides are
 * re-encoded the same way before the comparison. Everything else is.
 */
final class ReadApiContractTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReadApiFixtures;

    private const string BEARER = 'read-bearer-for-the-contract-test';

    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('agent.read.enabled', true);
        $app['config']->set('agent.read.bearer_token', self::BEARER);
    }

    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return [
            'a pre-order in US dollars, refunded after its payout' => ['preorder-usd'],
            "bianka's month: card, bank transfer, a corrected shipment, a purchase" => ['bianka-month'],
            "the illustrator's first period, paged two at a time" => ['illustrator-first-period'],
        ];
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function every_response_in_the_fixture_is_reproduced_from_the_rows_it_describes(string $fixture): void
    {
        $exchanges = 0;

        foreach ($this->readApiFixture($fixture)->pulls as $pull) {
            $this->seedPull($pull);

            foreach ($pull->exchanges as $exchange) {
                $response = $this->withToken(self::BEARER)
                    ->getJson($exchange->path.'?'.http_build_query((array) $exchange->query));

                $response->assertOk();
                $this->assertSame(
                    json_encode($exchange->response, self::FLAGS),
                    json_encode(json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR), self::FLAGS),
                    "{$fixture}, pull at {$pull->at}: {$exchange->resource} with ".json_encode($exchange->query),
                );

                $exchanges++;
            }
        }

        $this->assertGreaterThan(0, $exchanges);
    }
}
