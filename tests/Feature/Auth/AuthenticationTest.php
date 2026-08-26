<?php

use App\Models\User;

test('guest is redirected to login screen when accessing dashboard', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
    $this->assertGuest('web');
});

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('user can authenticate using login screen and access dashboard', function () {
    $user = User::factory()->create([
        'email' => 'lavss@lavss.ru',
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user, 'web');
    $response->assertRedirect('/');

    $dashboardResponse = $this->actingAs($user, 'web')->get('/');
    $dashboardResponse->assertStatus(200);
});

test('user can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest('web');
});

test('user can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->post('/logout');

    $this->assertGuest('web');
    $response->assertRedirect('/');
});
