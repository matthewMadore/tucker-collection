<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Public registration is turned off, so accounts are created from the
 * command line (locally or on the server). The email is marked verified
 * because the account owner is the one running this command.
 */
#[Signature('app:create-user')]
#[Description('Create a verified login for someone who manages the art pieces')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $name = text(label: 'Name', required: true);
        $email = text(label: 'Email', required: true);
        $password = password(label: 'Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->markEmailAsVerified();

        $this->info("Created {$user->email}. They can log in now.");

        return self::SUCCESS;
    }
}
