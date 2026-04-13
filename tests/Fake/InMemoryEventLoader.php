<?php declare(strict_types=1);

namespace App\Tests\Fake;

use App\Entity\Event;
use App\Entity\Source;
use App\Service\EventLoaderInterface;

/**
 * Test double used in integration tests. Replaces any real HTTP-backed loader.
 *
 * Tests script per-source behaviour via {@see script()}; each call to
 * {@see fetchEvents()} consumes one scripted batch. Every invocation is
 * recorded — including the lockedUntil value observed on the Source at call
 * time — so tests can assert the command correctly holds the lock during
 * fetch and releases it afterwards.
 */
final class InMemoryEventLoader implements EventLoaderInterface
{
    /**
     * @var array<string, list<array{raw: array<int, array{externalId: int, content: string}>, throw: ?\Throwable}>>
     */
    private array $scripts = [];

    /**
     * @var list<array{source: string, nextOffset: int, lockedUntil: ?\DateTimeImmutable}>
     */
    private array $calls = [];

    /**
     * Tracks the Source instance passed to the most recent fetchEvents() call so
     * that parseEvents() (which does not receive the Source from the command) can
     * attach it to the Event entities it creates. In a real scenario, each EventLoader instance
     * is attached to one specific source validated in {@see supports()} method, so in the parseEvents()
     * method the loader will "know" wich source attach to the events
     */
    private ?Source $currentSource = null;

    /**
     * Queue a batch for the next fetchEvents() call against $sourceName.
     *
     * @param array<int, array{id: int, content: string}> $rawBatch
     */
    public function script(string $sourceName, array $rawBatch, ?\Throwable $throw = null): void
    {
        $this->scripts[$sourceName][] = ['raw' => $rawBatch, 'throw' => $throw];
    }

    public function reset(): void
    {
        $this->scripts = [];
        $this->calls = [];
        $this->currentSource = null;
    }

    /**
     * @return list<array{source: string, nextOffset: int, lockedUntil: ?\DateTimeImmutable}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function supports(Source $source): bool
    {
        return \array_key_exists($source->getName(), $this->scripts);
    }

    public function fetchEvents(Source $source): array
    {
        $this->currentSource = $source;
        $this->calls[] = [
            'source' => $source->getName(),
            'nextOffset' => $source->getNextOffset(),
            'lockedUntil' => $source->getLockedUntil(),
        ];

        $queue = $this->scripts[$source->getName()] ?? [];
        if ($queue === []) {
            throw new \LogicException(\sprintf(
                'InMemoryEventLoader received an unexpected call for source "%s" (no scripted batch left).',
                $source->getName(),
            ));
        }

        $next = \array_shift($queue);
        $this->scripts[$source->getName()] = $queue;

        if ($next['throw'] !== null) {
            throw $next['throw'];
        }

        return $next['raw'];
    }

    /**
     * @param array<int, array{id: int, content: string}> $events
     *
     * @return array<int, Event>
     */
    public function parseEvents(array $events): array
    {
        if ($this->currentSource === null) {
            throw new \LogicException('parseEvents() called before fetchEvents() — the fake cannot infer the Source.');
        }

        $parsed = [];
        foreach ($events as $raw) {
            if (!isset($raw['id'], $raw['content'])) {
                return [];
            }
            $event = new Event();
            $event->setExternalId($raw['id']);
            $event->setContent($raw['content']);
            $event->setSource($this->currentSource);
            $parsed[] = $event;
        }

        return $parsed;
    }
}
