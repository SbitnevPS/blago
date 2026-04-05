<?php

/**
 * Local Valet driver for this legacy PHP project.
 *
 * Compatibility note:
 * - Newer Valet uses \Valet\Drivers\ValetDriver
 * - Older installs may expose a global \ValetDriver class
 */
if (!class_exists('\\Valet\\Drivers\\ValetDriver') && class_exists('\\ValetDriver')) {
    class_alias('\\ValetDriver', '\\Valet\\Drivers\\ValetDriver');
}

class LocalValetDriver extends \Valet\Drivers\ValetDriver
{
    /**
     * Always serve this project with the local driver.
     */
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return true;
    }

    /**
     * Serve existing static files directly.
     */
    public function isStaticFile(string $sitePath, string $siteName, string $uri): string|false
    {
        $path = $this->resolvePath($sitePath, $uri);

        if ($path !== null && is_file($path)) {
            return $path;
        }

        return false;
    }

    /**
     * Route all dynamic requests to the project front controller.
     */
    public function frontControllerPath(string $sitePath, string $siteName, string $uri): string
    {
        return $sitePath . '/index.php';
    }

    /**
     * Resolve URI to a file within the project root safely.
     */
    private function resolvePath(string $sitePath, string $uri): ?string
    {
        $relativePath = ltrim(parse_url($uri, PHP_URL_PATH) ?? '', '/');
        $candidate = realpath($sitePath . '/' . $relativePath);
        $rootPath = realpath($sitePath);

        if ($candidate === false || $rootPath === false) {
            return null;
        }

        if (strpos($candidate, $rootPath) !== 0) {
            return null;
        }

        return $candidate;
    }
}
