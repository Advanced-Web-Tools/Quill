<?php
namespace Quill\services\editor;

use Quill\services\conversion\shared\ConversionInput;

/** Sparse structural operations, scoped to one external resource and snapshot. */
final class ExternalStructureOverrides
{
    public const UID_PREFIX = 'quill-added:';

    /** Index placement by UID, independently of transient converter block IDs. */
    public function index(\stdClass $document, array $blocks): array
    {
        $index = [];
        $walk = function (array $ids, string $section, ?string $parent) use (&$walk, &$index, $blocks): void {
            $siblings = array_map(fn($id) => $blocks[$id]->editor->urpcUid ?? null, $ids);
            foreach ($ids as $position => $id) {
                $uid = $siblings[$position];
                if (!is_string($uid) || $uid === '' || strlen($uid) > 255 || isset($index[$uid])) {
                    throw new \InvalidArgumentException('Every external block must have a unique stable UID. Reload if its identity is missing.');
                }
                $index[$uid] = ['id' => $id, 'section' => $section, 'parent' => $parent,
                    'siblings' => $siblings, 'position' => $position];
                $walk($blocks[$id]->children ?? [], $section, $uid);
            }
        };
        foreach ($document->sections as $section) $walk($section->blocks, $section->name, null);
        return $index;
    }

    /** Longest ordered subset: deleting a sibling does not turn all later nodes into moves. */
    private function stationary(array $original, array $next): array
    {
        $positions = array_flip($original); $tails = []; $ends = []; $previous = [];
        foreach ($next as $i => $uid) {
            if (!isset($positions[$uid])) continue;
            $value = $positions[$uid]; $low = 0; $high = count($tails);
            while ($low < $high) { $mid = intdiv($low + $high, 2); if ($tails[$mid] < $value) $low = $mid + 1; else $high = $mid; }
            $previous[$i] = $low ? $ends[$low - 1] : null;
            $tails[$low] = $value; $ends[$low] = $i;
        }
        $out = []; $i = $ends ? $ends[count($tails) - 1] : null;
        while ($i !== null) { $out[$next[$i]] = true; $i = $previous[$i]; }
        return $out;
    }

    /** Build only inserts, explicit removals and nodes whose location actually changed. */
    public function changes(array $source, \stdClass $document, array $blocks, array $previousRecords): array
    {
        $base = $this->index($source['document'], $source['blocks']);
        $next = $this->index($document, $blocks);
        if (array_map(fn($s) => $s->name, $source['document']->sections) !== array_map(fn($s) => $s->name, $document->sections)) {
            throw new \InvalidArgumentException('External section names and order are owned by the provider.');
        }
        $stationary = []; $groups = [];
        foreach ($next as $uid => $at) $groups[json_encode([$at['section'], $at['parent']])][] = $uid;
        foreach ($groups as $uids) {
            $same = array_values(array_filter($uids, fn($uid) => isset($base[$uid])
                && $base[$uid]['parent'] === $next[$uid]['parent'] && $base[$uid]['section'] === $next[$uid]['section']));
            if ($same) $stationary += $this->stationary($base[$same[0]]['siblings'], $same);
        }
        $records = [];
        // Temporarily unavailable provider targets and their dependent inserts remain recoverable.
        $visible = $this->apply($source, $previousRecords);
        $visibleIndex = $this->index($visible['document'], $visible['blocks']);
        foreach ($previousRecords as $uid => $record) {
            if (!isset($visibleIndex[$uid]) && !isset($next[$uid])) $records[$uid] = $record;
        }
        foreach ($base as $uid => $at) {
            if (!isset($next[$uid]) && isset($visibleIndex[$uid])) $records[$uid] = (object)['schema_version' => 1, 'kind' => 'override',
                'urpc_uid' => (string)$uid, 'changes' => [], 'operation' => 'remove'];
        }
        foreach ($next as $uid => $at) {
            $isNew = !isset($base[$uid]);
            if ($isNew && !str_starts_with($uid, self::UID_PREFIX)) {
                // An orphaned source UID must not be silently reclassified as a Quill-owned block.
                throw new \InvalidArgumentException('New external blocks require a Quill-owned UID. Reload the editor before saving.');
            }
            if (!$isNew && isset($stationary[$uid])) continue;
            $siblings = $at['siblings'];
            $placement = (object)['section' => $at['section'], 'parent_uid' => $at['parent'],
                'after_uids' => array_reverse(array_slice($siblings, 0, $at['position'])),
                'before_uids' => array_slice($siblings, $at['position'] + 1)];
            $prior = $visibleIndex[$uid] ?? null;
            if (isset($previousRecords[$uid]->placement) && $prior
                && $prior['parent'] === $at['parent'] && $prior['section'] === $at['section']
                && $prior['siblings'] === $at['siblings'] && $prior['position'] === $at['position']) {
                // An unrelated save must not forget temporarily missing neighbor anchors.
                $placement = $previousRecords[$uid]->placement;
            }
            $record = (object)['schema_version' => 1, 'kind' => $isNew ? 'insert' : 'override', 'urpc_uid' => (string)$uid,
                'placement' => $placement];
            if ($isNew) {
                $record->block = ConversionInput::copy($blocks[$at['id']]);
                $record->block->children = []; // Descendants have independent UID records and anchors.
            } else $record->changes = [];
            $records[$uid] = $record;
        }
        return $records;
    }

    /** Apply to a fresh canonical source. Provider objects are never touched. */
    public function apply(array $source, array $records): array
    {
        $source['document'] = ConversionInput::copy($source['document']);
        $source['blocks'] = array_map(fn($block) => ConversionInput::copy($block), $source['blocks']);
        $byUid = []; $warnings = [];
        foreach ($source['blocks'] as $id => $block) $byUid[$block->editor->urpcUid] = $id;
        $nextId = $source['blocks'] ? max(array_keys($source['blocks'])) + 1 : 1;
        foreach ($records as $uid => $record) {
            if (($record->kind ?? '') !== 'insert') continue;
            if (!str_starts_with($uid, self::UID_PREFIX) || isset($byUid[$uid]) || !($record->block ?? null) instanceof \stdClass) {
                throw new \UnexpectedValueException('Invalid inserted external block.');
            }
            $block = ConversionInput::copy($record->block);
            $block->children = []; $block->editor ??= new \stdClass(); $block->editor->urpcUid = $uid;
            $byUid[$uid] = $nextId; $source['blocks'][$nextId++] = $block;
        }
        $detach = function(int $id) use (&$source): void {
            foreach ($source['document']->sections as $section) $section->blocks = array_values(array_diff($section->blocks, [$id]));
            foreach ($source['blocks'] as $block) $block->children = array_values(array_diff($block->children ?? [], [$id]));
        };
        $pending = [];
        foreach ($records as $uid => $record) {
            if (!isset($record->placement)) continue;
            $at = $record->placement;
            if (!($at instanceof \stdClass) || !is_string($at->section ?? null)
                || !property_exists($at, 'parent_uid') || ($at->parent_uid !== null && !is_string($at->parent_uid))
                || !is_array($at->after_uids ?? null) || !is_array($at->before_uids ?? null)) throw new \UnexpectedValueException('Invalid external block placement.');
            foreach ([...$at->after_uids, ...$at->before_uids] as $anchor) if (!is_string($anchor)) throw new \UnexpectedValueException('Invalid external placement anchor.');
            if (isset($byUid[$uid])) { $pending[$uid] = $record; $detach($byUid[$uid]); }
        }
        // Parent insertions/moves are resolved before children, even after provider topology changes.
        while ($pending) {
            $progress = false;
            foreach ($pending as $uid => $record) {
                $at = $record->placement; $parent = $at->parent_uid;
                if ($parent !== null && isset($pending[$parent])) continue;
                unset($pending[$uid]); $progress = true;
                $id = $byUid[$uid];
                if ($parent !== null) {
                    if (!isset($byUid[$parent]) || ($records[$parent]->operation ?? null) === 'remove') {
                        $warnings[] = 'An added or moved block is hidden because its destination container is unavailable.'; continue;
                    }
                    $parentId = $byUid[$parent];
                    $contains = function(int $candidate) use (&$contains, &$source, $parentId): bool {
                        if ($candidate === $parentId) return true;
                        foreach ($source['blocks'][$candidate]->children ?? [] as $child) if ($contains($child)) return true;
                        return false;
                    };
                    if ($contains($id)) { $warnings[] = 'A block placement conflicts with the current provider structure.'; continue; }
                    $list =& $source['blocks'][$parentId]->children;
                } else {
                    $section = null;
                    foreach ($source['document']->sections as $candidate) if ($candidate->name === $at->section) $section = $candidate;
                    if (!$section) { $warnings[] = 'An added or moved block is hidden because its section is unavailable.'; continue; }
                    $list =& $section->blocks;
                }
                $position = count($list); $found = false;
                foreach ($at->after_uids as $anchor) {
                    $index = isset($byUid[$anchor]) ? array_search($byUid[$anchor], $list, true) : false;
                    if ($index !== false) { $position = $index + 1; $found = true; break; }
                }
                if (!$found) foreach ($at->before_uids as $anchor) {
                    $index = isset($byUid[$anchor]) ? array_search($byUid[$anchor], $list, true) : false;
                    if ($index !== false) { $position = $index; $found = true; break; }
                }
                if (!$found && ($at->after_uids || $at->before_uids)) $warnings[] = 'A block was placed at the end of its container because its neighboring blocks are unavailable.';
                array_splice($list, $position, 0, [$id]); unset($list);
            }
            if (!$progress) { $warnings[] = 'Some block placements have unresolved container dependencies.'; break; }
        }
        foreach ($records as $uid => $record) if (($record->operation ?? null) === 'remove' && isset($byUid[$uid])) $detach($byUid[$uid]);
        // Unreachable records stay in storage, but cannot leak into conversion or the editor transport.
        $reachable = [];
        $visit = function(array $ids) use (&$visit, &$reachable, &$source): void {
            foreach ($ids as $id) {
                if (isset($reachable[$id])) throw new \UnexpectedValueException('Cyclic or repeated external block placement.');
                $reachable[$id] = true; $visit($source['blocks'][$id]->children ?? []);
            }
        };
        foreach ($source['document']->sections as $section) $visit($section->blocks);
        foreach ($records as $uid => $record) {
            if (isset($record->placement, $byUid[$uid]) && !isset($reachable[$byUid[$uid]])) $warnings[] = 'An added or moved block is hidden until its destination container becomes available.';
        }
        $source['blocks'] = array_intersect_key($source['blocks'], $reachable);
        $source['warnings'] = array_values(array_unique($warnings));
        return $source;
    }
}
