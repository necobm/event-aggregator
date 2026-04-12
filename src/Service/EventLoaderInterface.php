<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Source;

interface EventLoaderInterface
{
    /**
     * @return array<int, mixed>
     */
    public function fetchEvents(Source $source): array;

    /**
     * @param array<int, mixed> $events
     * @return array<int, Event>
     */
    public function parseEvents(array $events): array;

    public function supports(Source $source): bool;
}

