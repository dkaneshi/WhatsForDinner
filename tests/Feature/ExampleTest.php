<?php

test('home page shows the landing page', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSeeText("What's for Dinner?")
        ->assertSeeText('Plan dinners, organize dishes, and build the grocery list')
        ->assertSeeText('Create your dinner plan')
        ->assertSee(route('register'), false);
});
