<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Ergebnis der Auflösung des TMMS-Hinweistexts. `message = null` heißt „nirgends gesetzt", das
 * Template zeigt dann das Snippet.
 *
 * Erbt von Struct, weil `addExtension()` an der Seite nur Structs annimmt.
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

    /** Der Scope als Text, damit ein Template ihn ohne Enum-Zugriff vergleichen kann. */
    public function getScope(): string
    {
        return $this->scope->value;
    }
}
