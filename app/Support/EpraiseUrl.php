<?php

namespace App\Support;

class EpraiseUrl
{
    public static function build(string $template, string $fromDate, array $vars = []): string
    {
        $map = array_merge([
            '{School_ID}' => (string) config('services.epraise.school_id'),
            '{From_Date}' => (string) $fromDate,
            '{API_Key}'   => (string) config('services.epraise.api_key'),
        ], $vars);

        return strtr($template, $map);
    }

    public static function endpoint(string $key, string $fromDate, array $vars = []): string
    {
        $tpl = (string) config('services.epraise.endpoints.' . $key);
        return self::build($tpl, $fromDate, $vars);
    }
}
