<?php

test('the registration page does not exist', function () {
    $this->get('/register')->assertNotFound();
});

test('nobody can sign up by posting to the registration endpoint', function () {
    $this->post('/register', [
        'name' => 'Stranger',
        'email' => 'stranger@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
});
