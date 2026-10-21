<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** T-FLASH-1: flash messages must reach the front end. */
class InertiaSharedPropsTest extends TestCase
{
    public function test_flash_messages_are_shared_with_inertia_pages(): void
    {
        $this->withSession(['success' => 'Saved.', 'info' => 'FYI', 'error' => 'Nope'])
            ->get('/login')
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Saved.')
                ->where('flash.info', 'FYI')
                ->where('flash.error', 'Nope'));
    }

    public function test_flash_keys_are_null_when_nothing_was_flashed(): void
    {
        $this->get('/login')
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', null)
                ->where('flash.info', null)
                ->where('flash.error', null));
    }
}
