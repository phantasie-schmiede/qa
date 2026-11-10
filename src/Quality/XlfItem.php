<?php

declare(strict_types=1);

namespace PSBits\Qa\Quality;

/**
 * One sortable item of an XLIFF container (body or group): a
 * <trans-unit> or <group> element, with the line range of its opening
 * tag (from $start to $open), its closing line ($end) and - for a group
 * - the nested items of its interior.
 */
final class XlfItem
{
    /**
     * @param 'trans-unit'|'group' $type
     * @param list<XlfItem>        $children
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly int    $start,
        public readonly int    $open,
        public readonly int    $end,
        public readonly array  $children,
    ) {
    }
}
