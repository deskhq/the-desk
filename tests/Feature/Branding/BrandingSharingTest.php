<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->brandingPath = sys_get_temp_dir().'/branding-'.bin2hex(random_bytes(8));

    config(['branding.path' => $this->brandingPath]);
});

afterEach(function (): void {
    File::deleteDirectory($this->brandingPath);
});

test('an un-rebranded instance shares no logo mark, so the client keeps the inline one', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('branding.logo', null));
});

test('it shares the logo route once the operator has supplied a mark', function (): void {
    File::ensureDirectoryExists($this->brandingPath);
    File::put($this->brandingPath.'/logo.svg', '<svg></svg>');

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('branding.logo', route('branding.logo')));
});

test('attribution is shared on by default and off once the operator turns it off', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('branding.attribution', true));

    config(['branding.attribution' => false]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('branding.attribution', false));
});
