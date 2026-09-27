<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Request;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;

final class AuthController
{
    public function me(Request $request, ?array $user): array
    {
        return [
            'user' => $user ? $this->presentUser($user) : null,
            'csrf' => Auth::csrfToken(),
            'app' => [
                'name' => (string) Settings::get('app.name', 'Scrap'),
                'version' => APP_VERSION,
            ],
        ];
    }

    public function login(Request $request, ?array $user): array
    {
        $email = $request->str('email', '', 190);
        $password = (string) $request->raw('password', '');
        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Enter your email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        $result = Auth::attempt($email, $password, $request->bool('remember'));
        if (!$result['ok']) {
            throw new ApiException($result['error'], 422, ['password' => $result['error']], 'invalid_credentials');
        }
        $fresh = Auth::user();
        return ['user' => $fresh ? $this->presentUser($fresh) : null, 'csrf' => Auth::csrfToken()];
    }

    public function logout(Request $request, ?array $user): array
    {
        Auth::logout();
        return ['csrf' => Auth::csrfToken()];
    }

    public function profile(Request $request, ?array $user): array
    {
        $id = (int) $user['id'];
        $row = Database::row('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        $name = $request->str('name', '', 120);
        $email = mb_strtolower($request->str('email', '', 190));
        $current = (string) $request->raw('current_password', '');
        $new = (string) $request->raw('new_password', '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (Database::value('SELECT id FROM users WHERE email = :e AND id <> :id', ['e' => $email, 'id' => $id])) {
            $errors['email'] = 'Another account already uses this email.';
        }
        $changingSensitive = $new !== '' || $email !== $row['email'];
        if ($changingSensitive && !password_verify($current, (string) $row['password_hash'])) {
            $errors['current_password'] = 'Your current password is required to change email or password.';
        }
        if ($new !== '' && mb_strlen($new) < 8) {
            $errors['new_password'] = 'Use at least 8 characters.';
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }

        $data = ['name' => $name, 'email' => $email, 'updated_at' => Database::now()];
        if ($new !== '') {
            $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            // Sign out other devices.
            Database::query('DELETE FROM auth_tokens WHERE user_id = :id', ['id' => $id]);
        }
        Database::update('users', $data, 'id = :id', ['id' => $id]);
        $fresh = Database::row('SELECT id, name, email, last_login_at, created_at FROM users WHERE id = :id', ['id' => $id]);
        return ['user' => $this->presentUser($fresh ?? [])];
    }

    private function presentUser(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ];
    }
}
