<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /**
     * @return array<int, Password>
     */
    public static function rules(): array
    {
        return [
            Password::min(6)
                ->mixedCase()
                ->numbers()
                ->symbols(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password.min' => 'A senha deve ter no minimo 6 caracteres.',
            'password.mixed' => 'A senha ainda nao atende aos requisitos.',
            'password.numbers' => 'A senha deve conter pelo menos um numero.',
            'password.symbols' => 'A senha deve conter pelo menos um simbolo.',
            'password.confirmed' => 'A confirmacao da senha nao confere.',
        ];
    }

    public static function temporaryPassword(int $length = 12): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $numbers = '23456789';
        $symbols = '!@#$%&*?';
        $all = $upper.$lower.$numbers.$symbols;

        $password = [
            self::randomChar($upper),
            self::randomChar($lower),
            self::randomChar($numbers),
            self::randomChar($symbols),
        ];

        while (count($password) < $length) {
            $password[] = self::randomChar($all);
        }

        return implode('', self::shuffle($password));
    }

    private static function randomChar(string $characters): string
    {
        return $characters[random_int(0, strlen($characters) - 1)];
    }

    /**
     * @param  array<int, string>  $characters
     * @return array<int, string>
     */
    private static function shuffle(array $characters): array
    {
        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }

        return $characters;
    }
}
