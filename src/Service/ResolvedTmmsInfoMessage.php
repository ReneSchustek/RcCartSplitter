<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Ergebnis der Scope-Auflösung für den TMMS-Hinweistext. `message=null`
 * signalisiert „nichts gesetzt" — der Twig-Fallback nutzt das Snippet.
 */
final class ResolvedTmmsInfoMessage extends Struct
{
    public function __construct(
        public readonly ?string $message,
        public readonly TmmsInfoMessageScope $scope,
    ) {
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getScope(): string
    {
        return $this->scope->value;
    }
}
