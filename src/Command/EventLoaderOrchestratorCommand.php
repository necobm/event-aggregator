<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Service\EventLoaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(
    name: 'app:event-loader:run',
    description: 'Orchestrates event loading from all configured sources',
)]
class EventLoaderOrchestratorCommand extends Command
{
    /**
     * Max time in seconds a source remains locked to other workers.
     */
    private const LOCK_TTL_SECONDS = 60;

    /** @var iterable<EventLoaderInterface> */
    private iterable $loaders;

    private SymfonyStyle $io;

    /**
     * @param iterable<EventLoaderInterface> $loaders
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SourceRepository $sourceRepository,
        #[AutowireIterator('app.event_loader')] iterable $loaders,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();

        $this->loaders = $loaders;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        while (true) {
            $source = null;

            $this->entityManager->wrapInTransaction(function () use (&$source): void {
                $source = $this->sourceRepository->acquireNext();
                if ($source === null) {
                    return;
                }
                $source->setLockedUntil(new \DateTimeImmutable('+' . self::LOCK_TTL_SECONDS . ' seconds'));
                $this->entityManager->flush();
            });

            if ($source === null) {
                // No source is currently available (all locked by peers, or none configured).
                // Back off briefly and retry.
                sleep(1);
                continue;
            }

            $loader = $this->findLoaderForSource($source);

            if ($loader === null) {
                $this->logMessage(\sprintf('No loader found for source "%s". Skipping.', $source->getName()));
                $this->releaseSource($source);
                continue;
            }

            $this->io->info(\sprintf('Fetching events from source "%s" (offset: %d)...', $source->getName(), $source->getNextOffset()));

            try {
                $rawEvents = $loader->fetchEvents($source);
                $source->setLastFetchedAt(new \DateTimeImmutable());

                if (empty($rawEvents)) {
                    $this->logMessage(\sprintf('No new events from source "%s".', $source->getName()), 'info');
                } else {
                    $events = $loader->parseEvents($rawEvents);
                    if (empty($events)) {
                        $this->logMessage(\sprintf('Error parsing events from source "%s".', $source->getName()), 'error');
                        continue;
                    }

                    $source->setNextOffset(\end($events)->getExternalId());
                    // Release source immediately to allow other workers fetch the next event batch from it
                    $this->releaseSource($source);

                    try {
                        foreach ($events as $event) {
                            $this->entityManager->persist($event);
                        }
                        $this->entityManager->flush();

                        $this->io->success(\sprintf('Loaded %d events from source "%s".', \count($events), $source->getName()));
                    } catch (\Throwable) {
                        $this->logMessage(
                            \sprintf(
                                'Failed persisting events from source "%s" with the ID range %d - %d.',
                                $source->getName(),
                                $events[0]->getExternalId(),
                                \end($events)->getExternalId()
                            ),
                            'error'
                        );
                        $this->clean();
                    }
                }
            } catch (\Throwable $e) {
                $this->logMessage(\sprintf('Error loading events from source "%s": %s', $source->getName(), $e->getMessage()), 'error');
            } finally {
                // Always release the lock, even on failure
                $this->releaseSource($source);
            }

            // Avoid memory leaks after every run
            $this->clean();
        }

        return Command::SUCCESS;
    }

    private function clean(): void
    {
        $this->entityManager->clear();
        \gc_collect_cycles();
    }

    private function findLoaderForSource(Source $source): ?EventLoaderInterface
    {
        foreach ($this->loaders as $loader) {
            if ($loader->supports($source)) {
                return $loader;
            }
        }

        return null;
    }

    private function logMessage(string $message, string $level = 'warning'): void
    {
        $this->io->{$level}($message);
        $this->logger->{$level}($message);
    }

    private function releaseSource(Source $source): void
    {
        if ($source->getLockedUntil() === null) {
            return;
        }
        $source->setLockedUntil(null);
        $this->entityManager->persist($source);
        $this->entityManager->flush();
    }
}
