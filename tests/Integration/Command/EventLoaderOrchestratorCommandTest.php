<?php declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Source;
use App\Repository\SourceRepository;
use App\Tests\Fake\InMemoryEventLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end coverage of {@see \App\Command\EventLoaderOrchestratorCommand}
 * and the locking/cooldown semantics in {@see SourceRepository::acquireNext}.
 *
 * Real PostgreSQL is used so SELECT ... FOR UPDATE and cooldown filtering
 * execute against the actual RDBMS; DAMA wraps each test in a transaction
 * that is rolled back on teardown — no data persists between tests.
 *
 * External vendors are replaced by {@see InMemoryEventLoader}, scripted per
 * test, so no http call is made.
 */
final class EventLoaderOrchestratorCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SourceRepository $sourceRepository;
    private InMemoryEventLoader $fakeLoader;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->sourceRepository = $container->get(SourceRepository::class);
        $this->fakeLoader = $container->get(InMemoryEventLoader::class);
        $this->fakeLoader->reset();

        $application = new Application(self::$kernel);
        $command = $application->find('app:event-loader:run');
        $this->commandTester = new CommandTester($command);
    }

    // Command tests

    public function test_it_acquires_available_source_persists_events_and_releases_lock(): void
    {
        $source = $this->seedSource('fake_a');
        $this->fakeLoader->script('fake_a', [
            ['id' => 101, 'content' => 'first'],
            ['id' => 102, 'content' => 'second'],
            ['id' => 103, 'content' => 'third'],
        ]);

        $exit = $this->runCommand(1);

        self::assertSame(0, $exit);
        self::assertCount(1, $this->fakeLoader->calls(), 'fetchEvents should be invoked once');

        $fresh = $this->reloadSource($source);
        self::assertSame(103, $fresh->getNextOffset(), 'nextOffset must advance to the last externalId');
        self::assertNotNull($fresh->getLastFetchedAt(), 'lastFetchedAt must be bumped after a successful fetch');
        self::assertNull($fresh->getLockedUntil(), 'lock must be released after the batch is persisted');

        $persisted = $this->em->getRepository(\App\Entity\Event::class)->findBy(['source' => $fresh]);
        self::assertCount(3, $persisted);
    }

    public function test_it_skips_source_with_no_loader_and_releases_it(): void
    {
        $source = $this->seedSource('unknown_src');

        $exit = $this->runCommand(1);

        self::assertSame(0, $exit);
        self::assertSame([], $this->fakeLoader->calls(), 'fetchEvents must not be called when no loader supports the source');

        $fresh = $this->reloadSource($source);
        self::assertNull($fresh->getLockedUntil(), 'lock must be released even when no loader matches');
        self::assertNull($fresh->getLastFetchedAt(), 'unhandled sources must not have their lastFetchedAt updated');
        self::assertStringContainsString('No loader found', $this->commandTester->getDisplay());
    }

    public function test_it_handles_empty_raw_events_without_persisting(): void
    {
        $source = $this->seedSource('fake_a');
        $this->fakeLoader->script('fake_a', []);

        $exit = $this->runCommand(1);

        self::assertSame(0, $exit);

        $fresh = $this->reloadSource($source);
        self::assertNotNull($fresh->getLastFetchedAt(), 'lastFetchedAt must advance even for empty batches');
        self::assertNull($fresh->getLockedUntil(), 'lock must be released after an empty fetch');
        self::assertSame(0, $fresh->getNextOffset(), 'nextOffset stays untouched when no events arrive');

        $persisted = $this->em->getRepository(\App\Entity\Event::class)->findBy(['source' => $fresh]);
        self::assertCount(0, $persisted);
    }

    public function test_it_releases_lock_when_loader_throws(): void
    {
        $source = $this->seedSource('fake_a');
        $this->fakeLoader->script('fake_a', [], new \RuntimeException('vendor is down'));

        $exit = $this->runCommand(1);

        self::assertSame(0, $exit);

        $fresh = $this->reloadSource($source);
        self::assertNull($fresh->getLockedUntil(), 'the finally block must release the lock on loader failure');
        self::assertStringContainsString('Error loading events', $this->commandTester->getDisplay());
    }

    public function test_it_processes_multiple_sources_across_iterations(): void
    {
        $old = new \DateTimeImmutable('-10 seconds');
        $older = new \DateTimeImmutable('-20 seconds');

        $sourceA = $this->seedSource('fake_a', lastFetchedAt: $older);
        $sourceB = $this->seedSource('fake_b', lastFetchedAt: $old);

        $this->fakeLoader->script('fake_a', [['id' => 1, 'content' => 'a1']]);
        $this->fakeLoader->script('fake_b', [['id' => 2, 'content' => 'b1']]);

        $exit = $this->runCommand(2);

        self::assertSame(0, $exit);

        $calls = $this->fakeLoader->calls();
        self::assertCount(2, $calls);
        $processed = array_column($calls, 'source');
        self::assertSame(['fake_a', 'fake_b'], $processed, 'least recently fetched is taken first');

        $freshA = $this->reloadSource($sourceA);
        $freshB = $this->reloadSource($sourceB);
        self::assertNull($freshA->getLockedUntil());
        self::assertNull($freshB->getLockedUntil());
        self::assertSame(1, $freshA->getNextOffset());
        self::assertSame(2, $freshB->getNextOffset());
    }

    public function test_it_stops_after_max_iterations(): void
    {
        // No scripted batches and no seeded sources => acquireNext returns null every
        // iteration. The command would otherwise loop forever; --max-iterations=2 must
        // force a bounded exit.
        $exit = $this->runCommand(2);

        self::assertSame(0, $exit);
        self::assertSame([], $this->fakeLoader->calls());
    }

    public function test_command_holds_lockedUntil_while_loader_runs(): void
    {
        $source = $this->seedSource('fake_a');
        $this->fakeLoader->script('fake_a', [['id' => 1, 'content' => 'a']]);

        $this->runCommand(1);

        $calls = $this->fakeLoader->calls();
        self::assertCount(1, $calls);
        self::assertNotNull(
            $calls[0]['lockedUntil'],
            'The Source must carry a non-null lockedUntil when handed to the loader — this proves the orchestrator sets the lock before dispatch.',
        );

        // Lock TTL in the command is 60s;
        $lockedUntil = $calls[0]['lockedUntil'];
        $now = new \DateTimeImmutable();
        $deltaSeconds = $lockedUntil->getTimestamp() - $now->getTimestamp();
        self::assertGreaterThan(30, $deltaSeconds, 'lockedUntil must be comfortably in the future (~60s)');
        self::assertLessThanOrEqual(60, $deltaSeconds);

        // And after the iteration the lock is gone.
        $fresh = $this->reloadSource($source);
        self::assertNull($fresh->getLockedUntil());
    }

    // Lock and Source acquirement validations

    public function test_acquireNext_returns_null_when_source_is_locked_in_future(): void
    {
        $this->seedSource('fake_a', lockedUntil: new \DateTimeImmutable('+60 seconds'));

        $this->em->wrapInTransaction(function (): void {
            self::assertNull($this->sourceRepository->acquireNext());
        });
    }

    public function test_acquireNext_returns_source_when_lock_expired(): void
    {
        $this->seedSource('fake_a', lockedUntil: new \DateTimeImmutable('-1 second'));

        $this->em->wrapInTransaction(function (): void {
            $acquired = $this->sourceRepository->acquireNext();
            self::assertNotNull($acquired);
            self::assertSame('fake_a', $acquired->getName());
        });
    }

    public function test_acquireNext_respects_200ms_cooldown(): void
    {
        $source = $this->seedSource('fake_a', lastFetchedAt: new \DateTimeImmutable());

        $this->em->wrapInTransaction(function (): void {
            self::assertNull(
                $this->sourceRepository->acquireNext(),
                'A source fetched now is still within the 200ms cooldown.',
            );
        });

        $source->setLastFetchedAt(new \DateTimeImmutable('-10 seconds'));
        $this->em->flush();

        $this->em->wrapInTransaction(function (): void {
            $acquired = $this->sourceRepository->acquireNext();
            self::assertNotNull($acquired, 'Past-cooldown sources must be acquirable.');
        });
    }

    public function test_acquireNext_orders_by_least_recently_fetched(): void
    {
        $this->seedSource('fresh', lastFetchedAt: new \DateTimeImmutable('-1 second'));
        $this->seedSource('stale', lastFetchedAt: new \DateTimeImmutable('-60 seconds'));

        $this->em->wrapInTransaction(function (): void {
            $acquired = $this->sourceRepository->acquireNext();
            self::assertNotNull($acquired);
            self::assertSame(
                'stale',
                $acquired->getName(),
                'ORDER BY lastFetchedAt ASC must prefer the source that has not been fetched for the longest.',
            );
        });
    }

    private function runCommand(int $maxIterations): int
    {
        return $this->commandTester->execute(
            ['--max-iterations' => (string) $maxIterations],
            ['capture_stderr_separately' => true],
        );
    }

    private function seedSource(
        string $name,
        ?\DateTimeImmutable $lockedUntil = null,
        ?\DateTimeImmutable $lastFetchedAt = null,
        int $nextOffset = 0,
    ): Source {
        $source = new Source();
        $source->setName($name);
        $source->setNextOffset($nextOffset);
        $source->setLastFetchedAt($lastFetchedAt);
        $source->setLockedUntil($lockedUntil);
        $this->em->persist($source);
        $this->em->flush();

        return $source;
    }

    private function reloadSource(Source $source): Source
    {
        $reloaded = $this->em->find(Source::class, $source->getId());
        self::assertNotNull($reloaded, 'Seeded source disappeared from the DB.');

        return $reloaded;
    }
}
