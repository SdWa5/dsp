<?php

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;

final class TranslationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function domains(): iterable
    {
        foreach (['messages', 'js', 'labels'] as $domain) {
            yield $domain => [$domain];
        }
    }

    #[DataProvider('domains')]
    public function testBothLanguagesHaveTheSameMessagesAndParameters(string $domain): void
    {
        $loader = new YamlFileLoader();
        $root = __DIR__.'/../translations/';
        $en = $loader->load($root.$domain.'.en.yaml', 'en', $domain)->all($domain);
        $de = $loader->load($root.$domain.'.de.yaml', 'de', $domain)->all($domain);
        ksort($en);
        ksort($de);
        self::assertSame(array_keys($en), array_keys($de));
        foreach ($en as $key => $text) {
            self::assertNotSame('', $de[$key], $key);
            preg_match_all('/%[a-z_]+%/', $text, $english);
            preg_match_all('/%[a-z_]+%/', $de[$key], $german);
            sort($english[0]);
            sort($german[0]);
            self::assertSame($english[0], $german[0], 'Parameters of '.$key);
        }
    }
}
