<?php

it('exposes the health check endpoint', function () {
    $this->get('/up')->assertOk();
});
