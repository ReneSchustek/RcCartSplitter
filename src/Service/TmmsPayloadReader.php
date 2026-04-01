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
     * Liest rcTmmsField*-Werte aus dem Request-Payload (vom JS als Hidden-Felder injiziert).
     *
     * @return array<string, string>
     */
    public function readRequestPayload(Request $request, string $productId): array
    {
        /** @var array<string, array<string, mixed>> $lineItems */
        $lineItems = $request->request->all('lineItems');

        $itemData = $lineItems[$productId] ?? [];
        /** @var array<string, string> $payload */
        $payload = $itemData['payload'] ?? [];

        if (!isset($payload[TmmsConstants::PAYLOAD_TMMS_ACTIVE])) {
            return [];
        }

        $result = [TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1'];

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $valueKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_VALUE_SUFFIX;
            $labelKey = TmmsConstants::PAYLOAD_FIELD_PREFIX . $i . TmmsConstants::PAYLOAD_FIELD_LABEL_SUFFIX;

            $value = isset($payload[$valueKey]) ? trim((string) $payload[$valueKey]) : '';
            if ($value === '') {
                continue;
            }

            $result[$valueKey] = strip_tags($value);
            $result[$labelKey] = strip_tags(trim((string) ($payload[$labelKey] ?? '')));
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
}
