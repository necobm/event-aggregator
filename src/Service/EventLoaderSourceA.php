<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Source;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class EventLoaderSourceA implements EventLoaderInterface
{
    public function __construct(
        private HttpClientInterface $clientSourceA,
    ) {
    }
    /**
     * @inheritDoc
     */
    public function fetchEvents(Source $source): array
    {
        try {
            $response = $this->clientSourceA->request(
                'GET',
                '/events',
                [
                    'query' => [
                        'lastKnownOffset' => $source->getNextOffset(),
                    ],
                ],
            );

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException($response->getContent(false));
            }

            return json_decode($response->getContent(), true);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Failed to fetch events from Source A: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * @inheritDoc
     * @return array<int, Event>
     */
    public function parseEvents(array $events): array
    {
        // This method is the responsible to convert the events from Source A to the expected format
        // defined in the Event entity. The approach is using the Symfony Serializer component to serialize
        // the raw event and store it in Event::content property
        return [];
    }

    public function supports(Source $source): bool
    {
        return $source->getName() === 'SourceA';
    }
}
