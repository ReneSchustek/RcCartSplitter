<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Liest TMMS-Kundeneingaben aus Request-Payload oder Session */
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
            $valueKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
            $labelKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_LABEL_SUFFIX;

            $value = $this->normalizeScalar($payload[$valueKey] ?? null);
            if ($value === '') {
                continue;
            }

            $result[$valueKey] = $this->sanitize($value);
            $result[$labelKey] = $this->sanitize($this->normalizeScalar($payload[$labelKey] ?? null));
        }

        return $result;
    }

    /** @return array<int, array<string, string>> */
    public function readSessionData(SessionInterface $session, string $productNumber): array
    {
        $inputs = [];

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $key = TmmsConstants::SESSION_KEY_PREFIX . $i . '_' . $productNumber;

            if (!$session->has($key)) {
                continue;
            }

            /** @var array<string, string> $data */
            $data = $session->get($key, []);
            $value = $data[TmmsConstants::SESSION_VALUE_KEY] ?? '';

            if ($value === '') {
                continue;
            }

            $inputs[$i] = $data;
        }

        return $inputs;
    }

    // Nicht-Skalare (Arrays/Objekte) werden verworfen, damit nichts ungeprueft weiterlaeuft
    private function normalizeScalar(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return trim((string) $raw);
    }

    // HTML-Tags raus + Laenge kappen, bevor der Wert in custom_fields landet
    private function sanitize(string $value): string
    {
        $stripped = strip_tags($value);

        if (mb_strlen($stripped) > self::MAX_VALUE_LENGTH) {
            $stripped = mb_substr($stripped, 0, self::MAX_VALUE_LENGTH);
        }

        return $stripped;
    }
}
