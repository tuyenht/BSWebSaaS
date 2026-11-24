<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

/**
 * What a live check of a social-login app's keys found (SocialAppCheck): the provider accepted the
 * client id and secret, refused them, or could not tell (network fault, timeout, an answer we do
 * not recognise). Only a refusal may turn a working app off.
 */
enum SocialAppVerdict: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unknown = 'unknown';
}
