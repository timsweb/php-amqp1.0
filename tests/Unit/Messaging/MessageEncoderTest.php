<?php
declare(strict_types=1);
namespace AMQP10\Tests\Unit\Messaging;

use AMQP10\Messaging\Message;
use AMQP10\Messaging\MessageEncoder;
use AMQP10\Protocol\Descriptor;
use AMQP10\Protocol\TypeDecoder;
use PHPUnit\Framework\TestCase;

class MessageEncoderTest extends TestCase
{
    /**
     * Decode all sections from an encoded message payload.
     *
     * @return array<int, array{descriptor: int, value: mixed}>
     */
    private function decodeSections(string $encoded): array
    {
        $decoder  = new TypeDecoder($encoded);
        $sections = [];
        while ($decoder->remaining() > 0) {
            $section = $decoder->decode();
            if (is_array($section) && isset($section['descriptor'])) {
                $sections[] = $section;
            }
        }
        return $sections;
    }

    /** Find a section by its descriptor code. */
    private function findSection(array $sections, int $descriptor): ?array
    {
        foreach ($sections as $section) {
            if ($section['descriptor'] === $descriptor) {
                return $section;
            }
        }
        return null;
    }

    public function test_durable_true_by_default(): void
    {
        // Message::create defaults durable=true, priority=4, ttl=0.
        // The encoder must emit a header section whenever durable=true,
        // since the AMQP 1.0 wire default for durable is false.
        $m        = Message::create('test');
        $encoded  = MessageEncoder::encode($m);
        $sections = $this->decodeSections($encoded);

        $header = $this->findSection($sections, Descriptor::MSG_HEADER);
        $this->assertNotNull($header, 'Header section must be present when durable=true');
        // field[0] = durable
        $this->assertTrue($header['value'][0], 'durable should be true');
    }

    public function test_durable_false_emits_no_header_with_default_priority_and_no_ttl(): void
    {
        // When durable=false, priority=4 (default), and ttl=0, no header section is needed.
        $m        = Message::create('test')->withDurable(false);
        $encoded  = MessageEncoder::encode($m);
        $sections = $this->decodeSections($encoded);

        $header = $this->findSection($sections, Descriptor::MSG_HEADER);
        $this->assertNull($header, 'Header section must be absent when all fields are wire defaults');
    }

    public function test_durable_false_when_set_with_non_default_priority(): void
    {
        $m        = Message::create('test')->withDurable(false)->withPriority(8);
        $encoded  = MessageEncoder::encode($m);
        $sections = $this->decodeSections($encoded);

        $header = $this->findSection($sections, Descriptor::MSG_HEADER);
        $this->assertNotNull($header, 'Header section must be present when priority is non-default');
        $this->assertFalse($header['value'][0], 'durable should be false');
    }

    public function test_properties_section_emitted_for_subject_only(): void
    {
        // Subject set via withSubject() — no other properties.
        // The encoder must emit a MSG_PROPERTIES section even when the $props array is empty.
        $m        = Message::create('test')->withSubject('order.placed');
        $encoded  = MessageEncoder::encode($m);
        $sections = $this->decodeSections($encoded);

        $props = $this->findSection($sections, Descriptor::MSG_PROPERTIES);
        $this->assertNotNull($props, 'Properties section must be present when subject is set');
        // Subject is at index 3 of the properties list (spec §3.2.4)
        $this->assertSame('order.placed', $props['value'][3] ?? null, 'Subject must be at index 3');
    }
}
