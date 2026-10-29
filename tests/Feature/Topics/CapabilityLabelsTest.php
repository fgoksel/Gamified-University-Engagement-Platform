<?php

namespace Tests\Feature\Topics;

use App\Enums\Capability;
use Tests\TestCase;

/**
 * The interface names each capability with the list in resources/js/topicsLabels.js.
 * It must name every capability in the registry exactly as the server does, or a new
 * capability shows up as its raw value (as "access.import" would have, Task #72).
 */
class CapabilityLabelsTest extends TestCase
{
    public function test_the_interface_labels_match_the_capability_registry(): void
    {
        $file = file_get_contents(resource_path('js/topicsLabels.js'));
        preg_match('/capabilityLabels = \{(.*?)\};/s', $file, $block);
        preg_match_all('/"([a-z_.]+)":\s*"([^"]+)"/', $block[1] ?? '', $pairs, PREG_SET_ORDER);

        $labels = collect($pairs)->mapWithKeys(fn (array $pair) => [$pair[1] => $pair[2]])->all();
        $expected = collect(Capability::cases())->mapWithKeys(fn (Capability $c) => [$c->value => $c->label()])->all();

        $this->assertSame($expected, $labels);
    }
}
