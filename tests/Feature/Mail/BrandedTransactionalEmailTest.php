<?php

use App\Enums\AppLocale;
use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\User;

test('a user exposes their stored locale as the mail preference', function () {
    $user = User::factory()->make(['locale' => AppLocale::French->value]);

    expect($user->preferredLocale())->toBe('fr');
});

test('transactional mail carries The Desk branding without external asset requests', function () {
    $export = DataExport::factory()->ready()->create();

    $html = (new DataExportReady($export))->render();

    expect($html)
        ->toContain('The Desk')             // brand wordmark in the header + footer
        ->toContain('999px')                // inlined pill CTA radius
        ->not->toContain('fonts.googleapis') // web-safe stacks, no external fonts
        ->not->toContain('notification-logo') // stock Laravel logo image is gone
        ->not->toContain('<img');            // no embedded/remote images at all
});

test('the ready-export mail renders in the recipient stored locale', function () {
    $user = User::factory()->create(['locale' => AppLocale::French->value]);
    $export = DataExport::factory()->ready()->create(['user_id' => $user->id]);

    $mail = (new DataExportReady($export))->to($user);

    $mail->assertSeeInHtml('Votre export de données est prêt');
    $mail->assertSeeInHtml('Télécharger vos données');
});

test('the ready-export mail defaults to English for a user without a French locale', function () {
    $user = User::factory()->create(['locale' => AppLocale::English->value]);
    $export = DataExport::factory()->ready()->create(['user_id' => $user->id]);

    $mail = (new DataExportReady($export))->to($user);

    $mail->assertSeeInHtml('Your data export is ready');
    $mail->assertSeeInHtml('Download your data');
});
