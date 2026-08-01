<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcCartSplitter\Service\TmmsInfoMessageScope;

final class TmmsInfoMessageScopeTest extends TestCase
{
    public function testCasesCoverAllFourScopes(): void
    {
        self::assertCount(4, TmmsInfoMessageScope::cases());
    }

    public function testValuesAreSnakeCaseStrings(): void
    {
        self::assertSame('product', TmmsInfoMessageScope::Product->value);
        self::assertSame('category', TmmsInfoMessageScope::Category->value);
        self::assertSame('plugin_config', TmmsInfoMessageScope::PluginConfig->value);
        self::assertSame('default', TmmsInfoMessageScope::Default->value);
    }

    public function testFromMapsValuesBackToCases(): void
    {
        self::assertSame(TmmsInfoMessageScope::Product, TmmsInfoMessageScope::from('product'));
        self::assertSame(TmmsInfoMessageScope::Category, TmmsInfoMessageScope::from('category'));
        self::assertSame(TmmsInfoMessageScope::PluginConfig, TmmsInfoMessageScope::from('plugin_config'));
        self::assertSame(TmmsInfoMessageScope::Default, TmmsInfoMessageScope::from('default'));
    }
}
