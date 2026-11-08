<?php

namespace App\Support;

use Illuminate\Contracts\Translation\Loader;

/**
 * Puts the made-up language (PseudoLocale) behind Laravel's translator (Task #95).
 *
 * For every other language the real loader is used as it is. For the made-up one, the English
 * texts are loaded and converted: the JSON dictionary lang/en.json, but also the language files of
 * Laravel (validation, passwords) and of packages such as the admin panel. So when the app runs with
 * APP_LOCALE=qps, every text that goes through the translator changes, not only the ones of this
 * project, and a text that does not change is one that is typed into the code.
 */
class PseudoLoader implements Loader
{
    public function __construct(private Loader $loader) {}

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<array-key, mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        if ($locale !== PseudoLocale::CODE) {
            return $this->loader->load($locale, $group, $namespace);
        }

        return PseudoLocale::lines($this->loader->load('en', $group, $namespace));
    }

    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->loader->namespaces();
    }
}
