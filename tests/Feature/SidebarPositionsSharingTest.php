<?php

use App\Enums\SidebarPosition;
use Inertia\Testing\AssertableInertia as Assert;

test('the sidebar-position options are shared to the frontend so the user menu can offer the switcher anywhere', function (): void {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('sidebarPositions', SidebarPosition::options())
        );
});
