<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Passport::loadKeysFrom($this->oauthKeys());
    }

    protected function oauthKeys(): string
    {
        $dir = sys_get_temp_dir().'/kantoor-test-oauth';
        $private = $dir.'/oauth-private.key';
        $public = $dir.'/oauth-public.key';

        if (is_file($private) && is_file($public)) {
            return $dir;
        }

        is_dir($dir) || mkdir($dir, 0700, true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);

        file_put_contents($private, $privatePem);
        file_put_contents($public, openssl_pkey_get_details($key)['key']);
        chmod($private, 0600);
        chmod($public, 0600);

        return $dir;
    }
}
