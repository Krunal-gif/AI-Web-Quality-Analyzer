<?php

namespace App\Analyzer;

/**
 * User-changeable settings (Ollama host/model), persisted in storage/app/webqa/settings.json
 * with defaults from config/webqa.php (.env).
 */
final class Settings
{
    private static function path(): string
    {
        return storage_path('app/webqa/settings.json');
    }

    public static function get(): array
    {
        $defaults = ['ollama_host' => config('webqa.ollama_host'), 'ollama_model' => config('webqa.ollama_model')];
        $saved = is_file(self::path()) ? (json_decode((string) file_get_contents(self::path()), true) ?: []) : [];

        return array_merge($defaults, array_filter($saved));
    }

    public static function put(array $values): array
    {
        $settings = array_merge(self::get(), array_intersect_key(array_filter($values), array_flip(['ollama_host', 'ollama_model'])));
        @mkdir(dirname(self::path()), 0775, true);
        file_put_contents(self::path(), json_encode($settings, JSON_PRETTY_PRINT));

        return $settings;
    }
}
