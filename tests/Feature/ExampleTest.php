<?php

test('unauthenticated user is redirected to login', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
