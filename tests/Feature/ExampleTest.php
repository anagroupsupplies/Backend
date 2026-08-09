<?php

test('the application returns a successful response', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('Antenkayume Shop');
    $response->assertSee('Current uptime');
    $response->assertSee('https://github.com/sirtheprogrammer', false);
});
