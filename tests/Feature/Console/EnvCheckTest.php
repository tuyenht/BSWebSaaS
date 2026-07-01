<?php

/*
 * env:check compares a live .env against .env.example (the canonical key set) and fails (non-zero)
 * when a documented key is missing — the deploy gate that would have caught the R2_* / MAIL_SCHEME
 * drift before it silently broke upload / login. It compares KEY NAMES only, never values.
 */

/** Write a throwaway dotenv-style file and return its path. */
function tmpEnv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'envcheck');
    file_put_contents($path, $contents);

    return $path;
}

it('passes when .env has every key of .env.example (extra keys only warn)', function () {
    $example = tmpEnv("APP_KEY=\nR2_BUCKET=\n# a comment\nMAIL_SCHEME=null\n");
    $env = tmpEnv("APP_KEY=x\nR2_BUCKET=b\nMAIL_SCHEME=smtps\nEXTRA_ONLY_IN_ENV=1\n");

    $this->artisan('env:check', ['--env' => $env, '--example' => $example])
        ->assertExitCode(0);

    @unlink($example);
    @unlink($env);
});

it('fails and names a key present in .env.example but missing from .env', function () {
    $example = tmpEnv("APP_KEY=\nR2_BUCKET=\nR2_ENDPOINT=\n");
    $env = tmpEnv("APP_KEY=x\n"); // R2_BUCKET + R2_ENDPOINT missing

    $this->artisan('env:check', ['--env' => $env, '--example' => $example])
        ->expectsOutputToContain('R2_BUCKET')
        ->assertExitCode(1);

    @unlink($example);
    @unlink($env);
});

it('fails and names a key duplicated in .env (ambiguous value)', function () {
    $example = tmpEnv("APP_KEY=\nMAIL_SCHEME=\n");
    $env = tmpEnv("APP_KEY=x\nMAIL_SCHEME=smtp\nMAIL_SCHEME=ssl\n"); // MAIL_SCHEME twice

    $this->artisan('env:check', ['--env' => $env, '--example' => $example])
        ->expectsOutputToContain('MAIL_SCHEME')
        ->assertExitCode(1);

    @unlink($example);
    @unlink($env);
});

it('fails when a file is not found', function () {
    $this->artisan('env:check', ['--env' => '/no/such/.env', '--example' => '/no/such/.env.example'])
        ->assertExitCode(1);
});
