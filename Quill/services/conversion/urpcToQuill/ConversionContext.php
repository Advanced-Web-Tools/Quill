<?php

namespace Quill\services\conversion\urpcToQuill;

use Quill\services\conversion\shared\ConversionException;
use URPC\api\contracts\IURPCBlock;

final class ConversionContext
{
    /** @var \SplObjectStorage<IURPCBlock, string> */
    private \SplObjectStorage $active;
    /** @var array<int, \stdClass> */
    private array $blocks = [];
    /** @var array<int, array{uid: string, source_key: string}> */
    private array $identities = [];
    private int $nextId = 1;

    public function __construct(private int $maxDepth)
    {
        $this->active = new \SplObjectStorage();
    }

    public function enter(IURPCBlock $block, string $path): int
    {
        if ($this->active->contains($block)) {
            throw ConversionException::at($path, 'Cyclic URPC block; already active at ' . $this->active[$block] . '.');
        }
        if (count($this->active) >= $this->maxDepth) {
            throw ConversionException::at($path, "Maximum conversion depth {$this->maxDepth} exceeded.");
        }
        $this->active[$block] = $path;
        return $this->nextId++;
    }

    public function leave(IURPCBlock $block): void { $this->active->detach($block); }

    public function addBlock(int $localId, \stdClass $document, string $uid, string $sourceKey): void
    {
        $this->blocks[$localId] = $document;
        $this->identities[$localId] = ['uid' => $uid, 'source_key' => $sourceKey];
    }

    /** @return array<int, \stdClass> Local IDs are conversion references, not database IDs. */
    public function getBlocks(): array
    {
        $blocks = $this->blocks;
        ksort($blocks);
        return $blocks;
    }

    /** @return array<int, array{uid: string, source_key: string}> */
    public function getIdentities(): array
    {
        $identities = $this->identities;
        ksort($identities);
        return $identities;
    }
}
