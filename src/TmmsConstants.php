<?php

declare(strict_types=1);

namespace Ruhrcoder\RcCartSplitter;

/** Konstanten für die Interaktion mit TmmsProductCustomerInputs */
final class TmmsConstants
{
    /** Maximale Anzahl der TMMS-Eingabefelder pro Produkt */
    public const INPUT_COUNT = 5;

    /** Prefix der Session-Keys: tmms_customer_input_{count}_{productNumber} */
    public const SESSION_KEY_PREFIX = 'tmms_customer_input_';

    /** Payload-Schlüssel: gesicherte TMMS-Eingaben pro LineItem (Session-Fallback) */
    public const PAYLOAD_TMMS_INPUTS = 'rc_tmms_inputs';

    /** Payload-Marker: TMMS-Felder sind aktiv (vom JS gesetzt) */
    public const PAYLOAD_TMMS_ACTIVE = 'rcTmmsActive';

    /** Payload-Prefix für Value/Label pro Feld: rcTmmsField{i}Value / rcTmmsField{i}Label */
    public const PAYLOAD_FIELD_PREFIX = 'rcTmmsField';
    public const PAYLOAD_FIELD_VALUE_SUFFIX = 'Value';
    public const PAYLOAD_FIELD_LABEL_SUFFIX = 'Label';

    /** Session-Value-Keys innerhalb eines TMMS-Eintrags */
    public const SESSION_VALUE_KEY = 'tmms_customer_input_value';
    public const SESSION_LABEL_KEY = 'tmms_customer_input_label';
    public const SESSION_PLACEHOLDER_KEY = 'tmms_customer_input_placeholder';
    public const SESSION_FIELDTYPE_KEY = 'tmms_customer_input_fieldtype';
}
