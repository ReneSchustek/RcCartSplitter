<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

/**
 * Quelle, aus der der aufgelöste TMMS-Hinweistext stammt — für Logging
 * und Nachvollziehbarkeit bei Support-Anfragen.
 */
enum TmmsInfoMessageScope: string
{
    case Product = 'product';
    case Category = 'category';
    case PluginConfig = 'plugin_config';
    case Default = 'default';
}
