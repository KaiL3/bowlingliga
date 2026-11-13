<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

/**
 * Minimal router separating public and administrative routes.
 *
 * Public routes (public area) are reachable without login and read-only.
 * Admin routes (admin area) are writing and are protected via the security
 * layer (wired in public/index.php).
 */
final class Router
{
    /** @var array<string, callable> */
    private array $publicRoutes = [];

    /** @var array<string, callable> */
    private array $adminRoutes = [];

    /**
     * @var list<array{prefix: string, handler: callable}> Public routes with a
     *      trailing numeric id segment (e.g. "/matchday/7").
     */
    private array $publicIdRoutes = [];

    /**
     * @var list<array{prefix: string, pattern: string, handler: callable}>
     *      Public routes with a trailing name segment matching a pattern
     *      (e.g. "/media/<hash>.jpg").
     */
    private array $publicNameRoutes = [];

    /**
     * Registers a public route whose last segment is a name; the handler
     * receives the name. Only a single segment matching the regular
     * expression `$pattern` resolves (anchored, e.g. `[0-9a-f]{64}\.jpg`).
     *
     * @param callable(string): void $handler
     */
    public function publicWithName(string $prefix, string $pattern, callable $handler): void
    {
        $this->publicNameRoutes[] = [
            'prefix'  => $this->normalize($prefix),
            'pattern' => '~^(?:' . $pattern . ')$~D',
            'handler' => $handler,
        ];
    }

    /**
     * Resolves a public name route, or null if none matches.
     *
     * @return callable():void|null
     */
    public function resolveWithName(string $path): ?callable
    {
        $normalized = $this->normalize($path);

        foreach ($this->publicNameRoutes as $route) {
            $prefix = $route['prefix'] . '/';
            if (! str_starts_with($normalized, $prefix)) {
                continue;
            }

            $name = substr($normalized, strlen($prefix));
            if (preg_match($route['pattern'], $name) !== 1) {
                continue;
            }
            $handler = $route['handler'];

            return static fn (): mixed => $handler($name);
        }

        return null;
    }

    /**
     * Registers a public (reading) route.
     */
    public function public(string $path, callable $handler): void
    {
        $this->publicRoutes[$this->normalize($path)] = $handler;
    }

    /**
     * Registers a public (reading) route with a trailing numeric id segment
     * (e.g. prefix "/matchday" for "/matchday/7").
     *
     * The handler receives the extracted integer id as argument. Such routes
     * serve the detail views (R15) and are only resolved if the last path
     * segment is a positive integer.
     *
     * @param callable(int): void $handler
     */
    public function publicWithId(string $prefix, callable $handler): void
    {
        $this->publicIdRoutes[] = [
            'prefix'  => $this->normalize($prefix),
            'handler' => $handler,
        ];
    }

    /**
     * Resolves a public id route and returns the handler bound to the id, or
     * null if no id route matches.
     *
     * @return callable():void|null
     */
    public function resolveWithId(string $path): ?callable
    {
        $normalized = $this->normalize($path);

        foreach ($this->publicIdRoutes as $route) {
            $prefix = $route['prefix'] . '/';
            if (! str_starts_with($normalized, $prefix)) {
                continue;
            }

            $rest = substr($normalized, strlen($prefix));
            // Exactly one segment that is a positive integer.
            if ($rest === '' || ! ctype_digit($rest)) {
                continue;
            }

            $id = (int) $rest;
            $handler = $route['handler'];

            return static fn (): mixed => $handler($id);
        }

        return null;
    }

    /**
     * Registers an administrative (writing) route.
     *
     * The path is always kept under the prefix `/admin`.
     */
    public function admin(string $path, callable $handler): void
    {
        $this->adminRoutes[$this->normalizeAdminPath($path)] = $handler;
    }

    /**
     * Checks whether a path belongs to the admin area.
     */
    public function isAdminPath(string $path): bool
    {
        return str_starts_with($this->normalize($path), '/admin');
    }

    /**
     * Resolves a path to the registered handler.
     *
     * @return callable|null The handler, or null if no route matches.
     */
    public function resolve(string $path): ?callable
    {
        $normalized = $this->normalize($path);

        if ($this->isAdminPath($normalized)) {
            $handler = $this->adminRoutes[$normalized] ?? null;

            return is_callable($handler) ? $handler : null;
        }

        return $this->publicRoutes[$normalized] ?? null;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function normalizeAdminPath(string $path): string
    {
        $normalized = $this->normalize($path);

        if (str_starts_with($normalized, '/admin')) {
            return $normalized;
        }

        return $this->normalize('/admin' . $normalized);
    }
}
