<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** Liest TMMS-Kundeneingaben aus Request-Payload oder Session */
final class TmmsPayloadReader
{
    /**
     * Maximale Zeichenlaenge pro Eingabewert/Label.
     *
     * Schuetzt vor Payload-Bombs: Storefront erlaubt keine sinnvollen Werte
     * jenseits dieser Groessenordnung, der DB-Spalte custom_fields (JSON) wuerden
     * Megabyte-Strings ohnehin zum Verhaengnis.
     */
    private const MAX_VALUE_LENGTH = 2000;

    /**
     * Liest rcTmmsField*-Werte aus dem Request-Payload (vom JS als Hidden-Felder injiziert).
     *
     * @return array<string, string>
     */
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

    /**
     * Liest TMMS-Eingaben aus der Session (Fallback wenn kein Request-Payload vorhanden).
     *
     * @return array<int, array<string, string>>
     */
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

    /** Wandelt Raw-Payload-Wert in einen sicheren String. Nicht-Skalare werden verworfen. */
    private function normalizeScalar(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return trim((string) $raw);
    }

    /** Sanitization-Schicht: HTML-Tags entfernen, Laenge begrenzen. */
    private function sanitize(string $value): string
    {
        $stripped = strip_tags($value);

        if (mb_strlen($stripped) > self::MAX_VALUE_LENGTH) {
            $stripped = mb_substr($stripped, 0, self::MAX_VALUE_LENGTH);
        }

        return $stripped;
    }
}
