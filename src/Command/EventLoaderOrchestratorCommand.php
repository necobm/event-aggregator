<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\Source;
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
    /** @var iterable<EventLoaderInterface> */
    private iterable $loaders;

    private SymfonyStyle $io;

    /**
     * @param iterable<EventLoaderInterface> $loaders
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger = new NullLogger(),
        #[AutowireIterator('app.event_loader')] iterable $loaders,
    ) {
        parent::__construct();

        $this->loaders = $loaders;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $sources = $this->entityManager->getRepository(Source::class)->findAll();

        if (empty($sources)) {
            $this->logMessage('No sources found.');

            return Command::SUCCESS;
        }

        foreach ($sources as $source) {
            $loader = $this->findLoaderForSource($source);

            if ($loader === null) {
                $this->logMessage(\sprintf('No loader found for source "%s". Skipping.', $source->getName()));

                continue;
            }

            $this->io->info(\sprintf('Fetching events from source "%s" (offset: %d)...', $source->getName(), $source->getNextOffset()));

            try {
                // Wait one second before making the request to avoid rate limits when running more than one loader in parallel
                sleep(1);
                $rawEvents = $loader->fetchEvents($source);

                if (empty($rawEvents)) {
                    $this->logMessage(\sprintf('No new events from source "%s".', $source->getName()), 'info');

                    continue;
                }

                $events = $loader->parseEvents($rawEvents);

                foreach ($events as $event) {
                    $this->entityManager->persist($event);
                }

                $source->setNextOffset(array_pop($events)->getExternalId());
                $source->setLastQueried(new \DateTimeImmutable());

                $this->entityManager->persist($source);
                $this->entityManager->flush();

                $this->io->success(\sprintf('Loaded %d events from source "%s".', \count($events), $source->getName()));
            } catch (\Throwable $e) {
                $this->logMessage(\sprintf('Error loading events from source "%s": %s', $source->getName(), $e->getMessage()), 'error');
            }
        }

        // Avoid memory leaks after every run
        $this->clean();

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
}

