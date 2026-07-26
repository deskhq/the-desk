<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\NewMessageNotification;
use App\Support\PhpExtensions;
use App\Support\WebPushConfig;
use ErrorException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * Report whether this instance can actually deliver a web push notification.
 *
 * Web push is the app's only channel for message alerts — {@see NewMessageNotification::via()}
 * deliberately sends nothing else — so a broken push setup produces exactly the
 * same silence as a user who never subscribed a device. Nothing fails visibly:
 * the notification job is consumed before it throws, so the queue drains and
 * the worker stays up, and the only evidence is a row in `failed_jobs`.
 *
 * This command is the thing an operator can run to tell those two states apart.
 * It checks configuration and runtime prerequisites separately, because they
 * fail for different reasons and in different places: keys come from the
 * operator's `.env`, extensions come from the image.
 */
#[Signature('push:doctor')]
#[Description('Diagnose web push: VAPID configuration, runtime prerequisites, subscribed devices, and past delivery failures')]
class PushDoctorCommand extends Command
{
    /**
     * Nothing is wrong with this component.
     */
    private const string OK = 'ok';

    /**
     * Worth an operator's attention, but not a fault: push is switched off, or
     * no device has subscribed yet. Never changes the exit code.
     */
    private const string WARN = 'warn';

    /**
     * Push cannot be delivered in this state. Fails the command.
     */
    private const string FAIL = 'fail';

    /**
     * Extensions `minishlink/web-push` cannot send a payload or sign a VAPID
     * header without.
     *
     * @var list<string>
     */
    private const array REQUIRED_EXTENSIONS = ['curl', 'mbstring', 'openssl'];

    /**
     * The queued work a web push travels through — the listener that resolves
     * recipients, and the notification that reaches a push service — matched
     * against the serialized payload of a failed job.
     *
     * Matched on the class name alone: the payload holds it JSON-escaped, and a
     * namespace separator is also LIKE's escape character on Postgres, so a
     * fully-qualified pattern would have to be escaped twice over to mean what
     * it says. Neither name is ambiguous without it.
     *
     * @var list<string>
     */
    private const array PUSH_JOBS = [
        'SendMessagePushNotifications',
        'NewMessageNotification',
    ];

    /**
     * The checks run so far, in report order.
     *
     * @var list<array{status: string, label: string, detail: string}>
     */
    private array $checks = [];

    /**
     * Execute the console command.
     */
    public function handle(PhpExtensions $extensions): int
    {
        $this->checkVapidKeypair();
        $this->checkVapidSubject();
        $this->checkRequiredExtensions($extensions);
        $this->checkMathExtension($extensions);
        $this->checkDeliveryChannel();
        $this->checkSubscriptions();
        $this->checkPastFailures();

        $this->report();

        return $this->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check that the instance holds a usable VAPID keypair.
     *
     * Presence and validity are one check because they are one decision for the
     * operator: keys that decode to the wrong length are as unusable as keys
     * that were never set, and the library rejects both at the same point.
     * Having neither half is not a fault — an instance is free to run without
     * push, and every push surface hides itself when it does.
     */
    private function checkVapidKeypair(): void
    {
        $publicKey = (string) config('webpush.vapid.public_key');
        $privateKey = (string) config('webpush.vapid.private_key');

        if (! WebPushConfig::configured()) {
            $missingBoth = $publicKey === '' && $privateKey === '';

            $this->record(
                $missingBoth ? self::WARN : self::FAIL,
                'VAPID keypair',
                $missingBoth
                    ? 'not set: web push is disabled on this instance'
                    : 'half-configured: set both VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY',
            );

            return;
        }

        try {
            // The subject is passed only because the library insists the key is
            // present; it never inspects it. Whether it is a contact a push
            // service would accept is {@see self::checkVapidSubject()}'s call.
            VAPID::validate([
                'subject' => (string) config('webpush.vapid.subject'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        } catch (ErrorException $exception) {
            $this->record(self::FAIL, 'VAPID keypair', $exception->getMessage());

            return;
        }

        $this->record(self::OK, 'VAPID keypair', 'set and well-formed');
    }

    /**
     * Check the contact the VAPID header identifies this instance by.
     *
     * A push service uses it to reach whoever runs the sender when something is
     * wrong, and the spec allows only a `mailto:` address or an `https` URL.
     * Leaving it unset is survivable — the package substitutes the app URL — but
     * it is worth naming, because an operator who set `VAPID_SUBJECT` and got
     * the format wrong sees the same silence either way. The library itself
     * checks only that a subject exists, so the format check has to live here.
     */
    private function checkVapidSubject(): void
    {
        $subject = (string) config('webpush.vapid.subject');

        if ($subject === '') {
            $this->record(self::WARN, 'VAPID subject', 'not set: falling back to '.url('/'));

            return;
        }

        if (! str_starts_with($subject, 'mailto:') && ! str_starts_with($subject, 'https://')) {
            $this->record(self::FAIL, 'VAPID subject', $subject.' must be a mailto: address or an https URL');

            return;
        }

        $this->record(self::OK, 'VAPID subject', $subject);
    }

    /**
     * Check the extensions the library refuses to work without.
     */
    private function checkRequiredExtensions(PhpExtensions $extensions): void
    {
        $missing = $extensions->missing(self::REQUIRED_EXTENSIONS);

        $this->record(
            $missing === [] ? self::OK : self::FAIL,
            'PHP extensions',
            $missing === []
                ? implode(', ', self::REQUIRED_EXTENSIONS).' loaded'
                : 'missing: '.implode(', ', $missing),
        );
    }

    /**
     * Check that a big-number backend is available.
     *
     * The library only calls this one "highly recommended", but on this app it
     * is fatal rather than slow: it announces the gap with `trigger_error()` at
     * `E_USER_NOTICE`, Laravel's error handler promotes that to an
     * `ErrorException`, and it is raised inside the container binding — so
     * `WebPush` cannot be constructed at all and every queued notification dies
     * (issue #865).
     */
    private function checkMathExtension(PhpExtensions $extensions): void
    {
        $candidates = ['gmp', 'bcmath'];
        $missing = $extensions->missing($candidates);

        if ($missing === $candidates) {
            $this->record(
                self::FAIL,
                'Math extension',
                'neither gmp nor bcmath is loaded: WebPush cannot be constructed',
            );

            return;
        }

        $this->record(
            self::OK,
            'Math extension',
            implode(', ', array_values(array_diff($candidates, $missing))).' loaded',
        );
    }

    /**
     * Resolve the notification channel exactly as a queued notification would.
     *
     * The checks above name causes; this one asks the only question that
     * matters, and it is the one the individual checks can never fully answer —
     * a delivery attempt begins by resolving this channel out of the container,
     * and anything that stops it (a missing extension, a rejected keypair, a
     * future prerequisite nobody has thought of yet) surfaces here as the very
     * error the failed job would have carried.
     */
    private function checkDeliveryChannel(): void
    {
        try {
            app()->make(WebPushChannel::class);
        } catch (Throwable $exception) {
            $this->record(self::FAIL, 'Delivery channel', $exception->getMessage());

            return;
        }

        $this->record(self::OK, 'Delivery channel', 'resolves');
    }

    /**
     * Count the devices a notification could actually be delivered to.
     *
     * Everything above can be perfect and still reach nobody: a push needs a
     * browser to have subscribed. Reporting the count is what lets an operator
     * separate "delivery is broken" from "nobody has turned notifications on
     * yet", which is the ambiguity this whole command exists to resolve.
     */
    private function checkSubscriptions(): void
    {
        $devices = PushSubscription::query()->count();

        if ($devices === 0) {
            $this->record(self::WARN, 'Subscriptions', 'no device has subscribed: nothing would be delivered');

            return;
        }

        $people = PushSubscription::query()->distinct()->count('subscribable_id');

        $this->record(self::OK, 'Subscriptions', sprintf('%d device(s) across %d user(s)', $devices, $people));
    }

    /**
     * Look for push work that has already died.
     *
     * This is the table the issue was hiding in: a failed job is consumed
     * before it throws, so the queue drains and every liveness signal reads
     * green while the rows pile up unread. A count and a timestamp turn that
     * into something an operator sees without knowing to go looking.
     *
     * Deliberately a warning, never a failure. These are historical: an
     * instance that has been repaired would otherwise report red forever, until
     * someone thought to run `queue:flush`.
     */
    private function checkPastFailures(): void
    {
        $driver = (string) config('queue.failed.driver');

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            $this->record(self::WARN, 'Past failures', "failed jobs are stored by the `{$driver}` driver: inspect them there");

            return;
        }

        $failures = DB::connection(config('queue.failed.database'))
            ->table((string) config('queue.failed.table'))
            ->where(function (Builder $query): void {
                foreach (self::PUSH_JOBS as $job) {
                    $query->orWhere('payload', 'like', '%'.$job.'%');
                }
            });

        $count = (clone $failures)->count();

        if ($count === 0) {
            $this->record(self::OK, 'Past failures', 'no push job has failed');

            return;
        }

        $this->record(self::WARN, 'Past failures', sprintf(
            '%d failed push job(s), most recently %s: inspect with `php artisan queue:failed`',
            $count,
            (string) $failures->max('failed_at'),
        ));
    }

    /**
     * Record a check's outcome.
     */
    private function record(string $status, string $label, string $detail): void
    {
        $this->checks[] = ['status' => $status, 'label' => $label, 'detail' => $detail];
    }

    /**
     * Whether any check found something that stops push being delivered.
     */
    private function hasFailures(): bool
    {
        return array_any($this->checks, static fn (array $check): bool => $check['status'] === self::FAIL);
    }

    /**
     * Print every check, then a one-line verdict.
     */
    private function report(): void
    {
        $this->newLine();

        foreach ($this->checks as $check) {
            $this->components->twoColumnDetail(
                $check['label'],
                $check['detail'].' '.$this->marker($check['status']),
            );
        }

        $this->newLine();

        if ($this->hasFailures()) {
            $this->error('Web push cannot be delivered in this state.');

            return;
        }

        if (array_any($this->checks, static fn (array $check): bool => $check['status'] === self::WARN)) {
            $this->comment('Web push has no faults, but see the warnings above.');

            return;
        }

        $this->info('Web push is healthy.');
    }

    /**
     * The status marker a check is printed with.
     *
     * Spelled out in ASCII rather than drawn with a tick or a cross: this is
     * read over `docker compose exec` on whatever terminal an operator's server
     * happens to give them, and a word survives a font or an encoding that a
     * glyph does not.
     */
    private function marker(string $status): string
    {
        return match ($status) {
            self::OK => '<fg=green;options=bold>OK</>',
            self::WARN => '<fg=yellow;options=bold>WARN</>',
            default => '<fg=red;options=bold>FAIL</>',
        };
    }
}
