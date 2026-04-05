<?php

/**
 * Local Valet driver for this legacy PHP project.
 *
 * It forces Valet to use /index.php as the front controller
 * and properly resolves static files from the project root.
 */
if (!class_exists('ValetDriver') && class_exists(\Valet\Drivers\ValetDriver::class)) {
    class_alias(\Valet\Drivers\ValetDriver::class, 'ValetDriver');
}

class LocalValetDriver extends ValetDriver
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
