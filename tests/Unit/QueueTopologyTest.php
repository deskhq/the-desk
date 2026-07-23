<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Realtime latency lives in the compose files, which no other test executes.
 *
 * Two settings there decide how fast a broadcast reaches Reverb: `--sleep=0`,
 * without which an idle worker naps for whole seconds between polls even with a
 * blocking pop configured, and a dedicated `queue-broadcasts` worker, without
 * which a 5-second link unfurl on the shared queue holds every message,
 * reaction, and typing indicator behind it. Both read as harmless the moment
 * someone tidies a command line, so they need a guard or they rot back to the
 * 3-second lag of issue #763.
 */
$composePath = fn (string $file): string => dirname(__DIR__, 2).'/'.$file;

/** The two stacks an operator runs in production; they must not drift apart. */
$productionFiles = ['docker-compose.prod.yml', 'docker-compose.dokploy.yml'];

/** Every stack that runs a worker, production and the Sail dev stack. */
$allFiles = [...$productionFiles, 'compose.yaml'];

/**
 * The services in a compose file that consume the queue, as name => command.
 *
 * @return array<string, list<string>>
 */
function queueWorkerCommands(string $file): array
{
    /** @var array<string, array<string, mixed>> $services */
    $services = Yaml::parseFile(dirname(__DIR__, 2).'/'.$file)['services'];

    return array_map(
        static fn (array $service): array => (array) $service['command'],
        array_filter(
            $services,
            static fn (array $service): bool => array_intersect(
                ['queue:work', 'queue:listen'],
                (array) ($service['command'] ?? []),
            ) !== [],
        ),
    );
}

/**
 * The comma-separated value of a worker command's `--queue=` option, or null
 * when it takes whatever the connection's default queue is.
 *
 * @param  list<string>  $command
 */
function workerQueueOption(array $command): ?string
{
    foreach ($command as $token) {
        if (str_starts_with($token, '--queue=')) {
            return substr($token, strlen('--queue='));
        }
    }

    return null;
}

test('the worker services are found, so the assertions below match something', function (string $file, array $expected) use ($composePath): void {
    expect($composePath($file))->toBeReadableFile()
        ->and(array_keys(queueWorkerCommands($file)))->toBe($expected);
})->with([
    ['docker-compose.prod.yml', ['queue', 'queue-broadcasts']],
    ['docker-compose.dokploy.yml', ['queue', 'queue-broadcasts']],
    // The dev stack gets no second container: `queue:listen` respawns per job so
    // it picks up code edits without a restart, and its few hundred milliseconds
    // of bootstrap are invisible next to the 3 seconds this replaces.
    ['compose.yaml', ['queue']],
]);

/**
 * `block_for` makes an *occupied* queue return instantly, but `Worker::daemon`
 * still sleeps whenever a poll comes back empty — so without `--sleep=0` an
 * event dispatched during that nap waits the sleep out. That is exactly the
 * intermittent 1-to-3-second lag reported in #763.
 */
test('no worker sleeps between polls', function (string $file): void {
    $napping = array_keys(array_filter(
        queueWorkerCommands($file),
        static fn (array $command): bool => ! in_array('--sleep=0', $command, true),
    ));

    expect($napping)->toBe([]);
})->with($allFiles);

/**
 * Priority ordering alone cannot fix head-of-line blocking: it chooses the next
 * job, it cannot preempt the 5-second unfurl already in flight. Only a second
 * process can.
 */
test('each production stack runs a worker dedicated to broadcasts', function (string $file): void {
    $command = queueWorkerCommands($file)['queue-broadcasts'];

    expect(workerQueueOption($command))->toBe('broadcasts')
        ->and($command)->toContain('queue:work');
})->with($productionFiles);

/**
 * The shared worker keeps `broadcasts` in its list as an upgrade safety net: an
 * operator running a customized compose file who never adds the new service
 * still gets broadcasts delivered, just with the old head-of-line behaviour,
 * rather than realtime silently dying.
 *
 * The order is not cosmetic. `RedisQueue::pop` blocks only on the first queue in
 * the list, so `broadcasts` has to come first or the fix is quietly undone.
 */
test('the shared worker drains broadcasts first and default second', function (string $file): void {
    expect(workerQueueOption(queueWorkerCommands($file)['queue']))->toBe('broadcasts,default');
})->with($allFiles);

test('the two production stacks agree on how their workers run', function () use ($productionFiles): void {
    [$prod, $dokploy] = array_map(queueWorkerCommands(...), $productionFiles);

    expect($dokploy)->toBe($prod);
});
