<?php

namespace App\Tests\Filter;

use App\Filter\Chain;
use App\Measurement\FilterCase;
use App\Measurement\ShapeMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The filter models against the 8x8 itself. The fixture holds a sdwa5-dsp:filter-shapes walk of Out7 over its
 * loopback, trimmed to what the comparison needs: the output as set and each case's measured points. A change
 * to Biquad, Crossover or Prototype that no longer matches the device fails here.
 */
final class DeviceShapesTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/filter-shapes.json';

    /** @var array{state: array<string, mixed>, cases: list<array{id: string, delay: float|null, points: list<array{float, float, float, float}>}>}|null */
    private static ?array $fixture = null;

    #[DataProvider('cases')]
    public function testTheModelFitsTheDevice(string $id): void
    {
        $fixture = self::fixture();
        $row = array_values(array_filter($fixture['cases'], static fn (array $row): bool => $id === $row['id']))[0];
        $case = array_values(array_filter(FilterCase::catalogue(), static fn (FilterCase $case): bool => $id === $case->id))[0];

        $fit = ShapeMatch::fromArray($row)->against(Chain::output($case->apply($fixture['state'])), Chain::output($fixture['state']));

        self::assertTrue($fit['fits'], json_encode($fit, \JSON_THROW_ON_ERROR));
        self::assertGreaterThan(20, $fit['points']);
    }

    public function testTheFixtureCoversTheCatalogue(): void
    {
        $ids = array_map(static fn (FilterCase $case): string => $case->id, FilterCase::catalogue());

        self::assertSame(\array_slice($ids, 1), array_column(self::fixture()['cases'], 'id'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as $row) {
            yield $row['id'] => [$row['id']];
        }
    }

    /**
     * @return array{state: array<string, mixed>, cases: list<array{id: string, delay: float|null, points: list<array{float, float, float, float}>}>}
     */
    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(self::FIXTURE), true, 16, \JSON_THROW_ON_ERROR);
    }
}
