<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\ResolvedTmmsInfoMessage;
use Ruhrcoder\RcCartSplitter\Service\TmmsInfoMessageScope;

final class ResolvedTmmsInfoMessageTest extends TestCase
{
    public function testConstructStoresMessageAndScope(): void
    {
        $resolved = new ResolvedTmmsInfoMessage('Hinweis', TmmsInfoMessageScope::Product);

        self::assertSame('Hinweis', $resolved->getMessage());
        self::assertSame('product', $resolved->getScope());
    }

    public function testGetMessageReturnsNullWhenNoMessage(): void
    {
        $resolved = new ResolvedTmmsInfoMessage(null, TmmsInfoMessageScope::Default);

        self::assertNull($resolved->getMessage());
        self::assertSame('default', $resolved->getScope());
    }

    public function testScopeIsExposedAsString(): void
    {
        $cases = [
            [TmmsInfoMessageScope::Product, 'product'],
            [TmmsInfoMessageScope::Category, 'category'],
            [TmmsInfoMessageScope::PluginConfig, 'plugin_config'],
            [TmmsInfoMessageScope::Default, 'default'],
        ];

        foreach ($cases as [$scope, $expected]) {
            $resolved = new ResolvedTmmsInfoMessage('text', $scope);
            self::assertSame($expected, $resolved->getScope());
        }
    }

    public function testReadonlyPropertiesAreAccessibleDirectly(): void
    {
        $resolved = new ResolvedTmmsInfoMessage('Direct', TmmsInfoMessageScope::Category);

        self::assertSame('Direct', $resolved->message);
        self::assertSame(TmmsInfoMessageScope::Category, $resolved->scope);
    }
}
