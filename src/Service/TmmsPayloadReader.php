<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter\Service;

use Ruhrcoder\RcCartSplitter\TmmsConstants;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Liest TMMS-Kundeneingaben aus dem Request-Payload oder aus der TMMS-Session.
 *
 * Beide Quellen sind Kundeneingaben und laufen durch dieselbe Bereinigung: Tags entfernen, Länge
 * kappen, Nicht-Skalare verwerfen. Was hier durchkommt, landet ungeprüft im Warenkorb-Payload und
 * später in den custom_fields der Bestellposition.
 */
final class TmmsPayloadReader
{
    // Eine Kundeneingabe ist ein Maß, eine Farbe oder eine kurze Notiz. 2000 Zeichen lassen dafür
    // reichlich Luft und begrenzen, was ein manipulierter Request je Feld in Warenkorb und
    // Bestellung schreiben kann.
    private const MAX_VALUE_LENGTH = 2000;

    /**
     * Die Storefront schickt die Positionen als `lineItems`, die Store-API als `items`. Wer nur
     * den ersten Namen liest, sieht über den zweiten Weg nichts, und die Kundeneingabe fällt
     * still aus. RcColorPicker und RcCustomFields lesen ebenfalls beide Namen.
     *
     * Gesucht wird die Position unter dem Schlüssel der Produktkennung. Die Storefront schlüsselt
     * `lineItems` so; eine Store-API-Liste mit fortlaufenden Indizes, wie `CartItemAddRoute` sie
     * annimmt, trifft dieser Zugriff nur, wenn der Aufrufer die Einträge ebenfalls nach
     * Produktkennung schlüsselt.
     */
    private const PARAMETER_NAMES = ['lineItems', 'items'];

    /**
     * Die Storefront schlüsselt die Positionen nach Produktkennung, die Store-API schickt `items` als
     * Liste. In der Liste steht die Kennung im Eintrag selbst, als `referencedId` oder `id`.
     *
     * @param array<mixed> $items
     *
     * @return array<mixed>|null
     */
    private function findItem(array $items, string $productId): ?array
    {
        $keyed = $items[$productId] ?? null;
        if (is_array($keyed)) {
            return $keyed;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['referencedId'] ?? null) === $productId || ($item['id'] ?? null) === $productId) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function readRequestPayload(Request $request, string $productId): array
    {
        // `all()` ohne Schlüssel: Mit Schlüssel wirft der InputBag bei einem skalaren Wert eine
        // BadRequestException und macht aus einem krummen Request eine 400.
        $allParameters = $request->request->all();

        $itemData = null;
        foreach (self::PARAMETER_NAMES as $parameterName) {
            $items = $allParameters[$parameterName] ?? null;
            if (!is_array($items)) {
                continue;
            }

            $itemData = $this->findItem($items, $productId);
            if ($itemData !== null) {
                break;
            }
        }

        if (!is_array($itemData)) {
            return [];
        }

        $payload = $itemData['payload'] ?? null;
        if (!is_array($payload)) {
            return [];
        }

        // Ohne Marker hat das Storefront-Skript keine Werte eingefügt; dann greift im Provider der
        // Session-Weg.
        if (!isset($payload[TmmsConstants::PAYLOAD_TMMS_ACTIVE])) {
            return [];
        }

        $result = [TmmsConstants::PAYLOAD_TMMS_ACTIVE => '1'];

        for ($i = 1; $i <= TmmsConstants::INPUT_COUNT; $i++) {
            $value = $this->sanitizeFrom($payload, TmmsConstants::payloadValueKey($i));
            // Ein Label ohne Wert hätte in Anzeige und Bestellung nichts zu beschriften.
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
            // Ein Nicht-Array im Session-Eintrag würde den folgenden Schlüsselzugriff sprengen.
            if (!is_array($data)) {
                continue;
            }

            // TMMS speichert die Eingabe so, wie der Kunde sie abgeschickt hat. Ohne dieselbe
            // Bereinigung wie im Request-Weg landeten Tags und Überlängen im Payload und in custom_fields.
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

    // Arrays und Objekte werden zum leeren Text, sie lassen sich nicht sinnvoll in einen Wert wandeln.
    private function normalizeScalar(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        return trim((string) $raw);
    }

    // Gekappt wird nach dem Entfernen der Tags, damit Markup nicht von der erlaubten Länge zehrt.
    private function sanitize(string $value): string
    {
        $stripped = strip_tags($value);

        if (mb_strlen($stripped) > self::MAX_VALUE_LENGTH) {
            $stripped = mb_substr($stripped, 0, self::MAX_VALUE_LENGTH);
        }

        return $stripped;
    }
}
