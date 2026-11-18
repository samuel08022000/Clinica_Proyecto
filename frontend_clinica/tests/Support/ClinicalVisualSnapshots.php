<?php

namespace Tests\Support;

class ClinicalVisualSnapshots
{
    public static function save(string $name, string $html): void
    {
        $directory = getenv('CLINICAL_VISUAL_DIR');
        if (! $directory) {
            return;
        }
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $assets = '<link rel="stylesheet" href="/frontend_clinica/public/build/'.$manifest['resources/css/app.css']['file'].'">';
        $assets .= '<script type="module" src="/frontend_clinica/public/build/'.$manifest['resources/js/app.js']['file'].'"></script>';
        file_put_contents($directory.'/'.$name.'.html', str_replace('</head>', $assets.'</head>', $html));
    }
}
