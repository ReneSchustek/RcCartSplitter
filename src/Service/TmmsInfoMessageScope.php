<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

/**
 * Quelle des aufgelösten TMMS-Hinweistexts. Sie steht im Protokoll, damit sich bei einer
 * Rückfrage zeigen lässt, woher ein Text kam. `Default` heißt: Es greift das Snippet.
 */
enum TmmsInfoMessageScope: string
{
    case Product = 'product';
    case Category = 'category';
    case PluginConfig = 'plugin_config';
    case Default = 'default';
}
