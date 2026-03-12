<?php
declare(strict_types=1);
namespace AMQP10\Protocol;

/**
 * AMQP 1.0 performative and section descriptor codes.
 * These are ulong values used as described-type descriptors.
 * Spec: Transport §2.7, Security §5.3, Messaging §3.2
 */
final class Descriptor
{
    // Connection-level performatives
    public const OPEN  = 0x10;
    public const CLOSE = 0x18;

    // Session-level performatives
    public const BEGIN = 0x11;
    public const END   = 0x17;

    // Link-level performatives
    public const ATTACH      = 0x12;
    public const FLOW        = 0x13;
    public const TRANSFER    = 0x14;
    public const DISPOSITION = 0x15;
    public const DETACH      = 0x16;

    // SASL performatives (TYPE=0x01 frames)
    public const SASL_MECHANISMS = 0x40;
    public const SASL_INIT       = 0x41;
    public const SASL_CHALLENGE  = 0x42;
    public const SASL_RESPONSE   = 0x43;
    public const SASL_OUTCOME    = 0x44;

    // Delivery outcomes (used inside Disposition frames)
    public const ACCEPTED = 0x24;
    public const REJECTED = 0x25;
    public const RELEASED = 0x26;
    public const MODIFIED = 0x27;

    // Message sections (used in Transfer frame bodies)
    public const MSG_HEADER               = 0x70;
    public const MSG_DELIVERY_ANNOTATIONS = 0x71;
    public const MSG_ANNOTATIONS          = 0x72;
    public const MSG_PROPERTIES           = 0x73;
    public const MSG_APPLICATION_PROPS    = 0x74;
    public const MSG_DATA                 = 0x75;
    public const MSG_AMQP_SEQUENCE        = 0x76;
    public const MSG_AMQP_VALUE           = 0x77;
    public const MSG_FOOTER               = 0x78;
}
