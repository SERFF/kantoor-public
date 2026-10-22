<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

#[Signature('kantoor:invite {email} {--name=} {--minutes=30}')]
#[Description('Create the account (or reset it) and print a one-time link to set its password in the browser')]
class InviteUser extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $this->option('name') ?: ($user->name ?: $email);
        $user->password = Str::password(64);
        $user->save();

        $this->line(URL::temporarySignedRoute(
            'password.setup',
            now()->addMinutes((int) $this->option('minutes')),
            ['user' => $user->id, 'hash' => sha1($user->getAuthPassword())],
        ));

        return self::SUCCESS;
    }
}
