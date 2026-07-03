<?php

it('exposes the health check endpoint', function (): void {
    $this->get('/up')->assertOk();
});
