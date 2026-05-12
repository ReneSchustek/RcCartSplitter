<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter;

/** Konstanten und Builder für die Interaktion mit TmmsProductCustomerInputs */
final class TmmsConstants
{
    /** Maximale Anzahl der TMMS-Eingabefelder pro Produkt */
    public const INPUT_COUNT = 5;

    /** Prefix der Session-Keys: tmms_customer_input_{count}_{productNumber} */
    public const SESSION_KEY_PREFIX = 'tmms_customer_input_';

    /** Payload-Schlüssel: gesicherte TMMS-Eingaben pro LineItem (Session-Fallback, Altbestellungen) */
    public const PAYLOAD_TMMS_INPUTS = 'rc_tmms_inputs';

    /** Payload-Marker: TMMS-Felder sind aktiv (vom JS gesetzt oder im Session-Fallback) */
    public const PAYLOAD_TMMS_ACTIVE = 'rcTmmsActive';

    /** Payload-Prefix/-Suffix für Value/Label pro Feld: rcTmmsField{i}Value / rcTmmsField{i}Label */
    public const PAYLOAD_FIELD_PREFIX = 'rcTmmsField';
    public const PAYLOAD_FIELD_VALUE_SUFFIX = 'Value';
    public const PAYLOAD_FIELD_LABEL_SUFFIX = 'Label';

    /** Session-Value-Keys innerhalb eines TMMS-Eintrags (von TMMS-Plugin geschrieben) */
    public const SESSION_VALUE_KEY = 'tmms_customer_input_value';
    public const SESSION_LABEL_KEY = 'tmms_customer_input_label';
    public const SESSION_PLACEHOLDER_KEY = 'tmms_customer_input_placeholder';
    public const SESSION_FIELDTYPE_KEY = 'tmms_customer_input_fieldtype';

    /** TMMS-Extension-Name pro LineItem-Feld: tmmsLineItemCustomerInput{i} */
    public const EXTENSION_NAME_PREFIX = 'tmmsLineItemCustomerInput';

    /** Custom-Field-Prefix/-Suffix für Bestellpositionen: tmms_customer_input_{i}_value etc. */
    public const CUSTOM_FIELD_PREFIX = 'tmms_customer_input_';
    public const CUSTOM_FIELD_VALUE_SUFFIX = '_value';
    public const CUSTOM_FIELD_LABEL_SUFFIX = '_label';
    public const CUSTOM_FIELD_PLACEHOLDER_SUFFIX = '_placeholder';
    public const CUSTOM_FIELD_FIELDTYPE_SUFFIX = '_fieldtype';

    /** Produkt-Custom-Field für den TMMS-Hinweistext-Override (Scope: Produkt) */
    public const PRODUCT_TMMS_INFO_MESSAGE_FIELD = 'rc_cart_splitter_tmms_info_message';

    /** Kategorie-Custom-Field für den TMMS-Hinweistext-Override (Scope: Kategorie-Chain) */
    public const CATEGORY_TMMS_INFO_MESSAGE_FIELD = 'rc_cart_splitter_cat_tmms_info_message';

    /** Plugin-Config-Schlüssel für den globalen TMMS-Hinweistext-Default */
    public const CONFIG_TMMS_INFO_MESSAGE = 'RcCartSplitter.config.tmmsInformationMessage';

    public static function payloadValueKey(int $index): string
    {
        return self::PAYLOAD_FIELD_PREFIX . $index . self::PAYLOAD_FIELD_VALUE_SUFFIX;
    }

    public static function payloadLabelKey(int $index): string
    {
        return self::PAYLOAD_FIELD_PREFIX . $index . self::PAYLOAD_FIELD_LABEL_SUFFIX;
    }

    public static function sessionKey(int $index, string $productNumber): string
    {
        return self::SESSION_KEY_PREFIX . $index . '_' . $productNumber;
    }

    public static function extensionName(int $index): string
    {
        return self::EXTENSION_NAME_PREFIX . $index;
    }

    public static function customFieldValueKey(int $index): string
    {
        return self::CUSTOM_FIELD_PREFIX . $index . self::CUSTOM_FIELD_VALUE_SUFFIX;
    }

    public static function customFieldLabelKey(int $index): string
    {
        return self::CUSTOM_FIELD_PREFIX . $index . self::CUSTOM_FIELD_LABEL_SUFFIX;
    }

    public static function customFieldPlaceholderKey(int $index): string
    {
        return self::CUSTOM_FIELD_PREFIX . $index . self::CUSTOM_FIELD_PLACEHOLDER_SUFFIX;
    }

    public static function customFieldFieldtypeKey(int $index): string
    {
        return self::CUSTOM_FIELD_PREFIX . $index . self::CUSTOM_FIELD_FIELDTYPE_SUFFIX;
    }
}
