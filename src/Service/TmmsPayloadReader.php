<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Liest TMMS-Kundeneingaben aus Request-Payload oder Session — sanitisiert an beiden Eingangs-Pfaden gleich. */
final class TmmsPayloadReader
{
    // Schutz vor Payload-Bombs — laengere Eingaben sprengen die JSON-Spalte custom_fields ohnehin
    private const MAX_VALUE_LENGTH = 2000;

    /** @return array<string, string> */
    public function readRequestPayload(Request $request, string $productId): array
    {
        $lineItems = $request->request->all('lineItems');

        $itemData = $lineItems[$productId] ?? null;
        if (!is_array($itemData)) {
            return [];
        }

        $payload = $itemData['payload'] ?? null;
        if (!is_array($payload)) {
            return [];
        }

        if (!isset($payload[TmmsConstants::PAYLOAD_TMMS_ACTIVE])) {
            return [];
        }

        $result = [TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1'];

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $value = $this->sanitizeFrom($payload, TmmsConstants::payloadValueKey($i));
            if ($value === '') {
                continue;
            }

            $result[TmmsConstants::payloadValueKey($i)] = $value;
            $result[TmmsConstants::payloadLabelKey($i)] = $this->sanitizeFrom($payload, TmmsConstants::payloadLabelKey($i));
        }

        return $result;
    }

    /** @return array<int, array<string, string>> */
    public function readSessionData(SessionInterface $session, string $productNumber): array
    {
        $inputs = [];

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $key = TmmsConstants::sessionKey($i, $productNumber);

            if (!$session->has($key)) {
                continue;
            }

            $data = $session->get($key, []);
            // Manipulierte Session darf den nachfolgenden Array-Zugriff nicht sprengen
            if (!is_array($data)) {
                continue;
            }

            // Sanitisierung auf der Session-Seite identisch zum Request-Pfad — sonst landen
            // Roh-Strings (Tags, Ueberlaengen) im Cart-Payload und in custom_fields.
            $value = $this->sanitizeFrom($data, TmmsConstants::SESSION_VALUE_KEY);
            if ($value === '') {
                continue;
            }

            $inputs[$i] = [
                TmmsConstants::SESSION_VALUE_KEY => $value,
                TmmsConstants::SESSION_LABEL_KEY => $this->sanitizeFrom($data, TmmsConstants::SESSION_LABEL_KEY),
                TmmsConstants::SESSION_PLACEHOLDER_KEY => $this->sanitizeFrom($data, TmmsConstants::SESSION_PLACEHOLDER_KEY),
                TmmsConstants::SESSION_FIELDTYPE_KEY => $this->sanitizeFrom($data, TmmsConstants::SESSION_FIELDTYPE_KEY),
            ];
        }

        return $inputs;
    }

    /** @param array<string, mixed> $source */
    private function sanitizeFrom(array $source, string $key): string
    {
        return $this->sanitize($this->normalizeScalar($source[$key] ?? null));
    }

    // Nicht-Skalare (Arrays/Objekte) werden verworfen, damit nichts ungeprueft weiterlaeuft
    private function normalizeScalar(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return trim((string) $raw);
    }

    // HTML-Tags raus + Laenge kappen, bevor der Wert in Payload/custom_fields landet
    private function sanitize(string $value): string
    {
        $stripped = strip_tags($value);

        if (mb_strlen($stripped) > self::MAX_VALUE_LENGTH) {
            $stripped = mb_substr($stripped, 0, self::MAX_VALUE_LENGTH);
        }

        return $stripped;
    }
}
