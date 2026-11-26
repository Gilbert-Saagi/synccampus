<?php
declare(strict_types=1);

namespace SyncCampus;

final class Request
{
    /** Values captured from the route pattern, e.g. {id}. */
    public array $params = [];

    private ?array $body = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // The API is reached as /api/... behind Vercel and Apache; routes are written without it.
        if (str_starts_with($path, '/api/') || $path === '/api') {
            $path = substr($path, 4) ?: '/';
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), rtrim($path, '/') ?: '/');
    }

    public function id(string $name = 'id'): int
    {
        return (int) ($this->params[$name] ?? 0);
    }

    public function query(string $key, string $default = ''): string
    {
        return trim((string) ($_GET[$key] ?? $default));
    }

    /** JSON body, or a regular form post (the login form is sent that way). */
    public function input(): Input
    {
        if ($this->body === null) {
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($type, 'application/x-www-form-urlencoded') || str_contains($type, 'multipart/form-data')) {
                $this->body = $_POST;
            } else {
                $raw = file_get_contents('php://input') ?: '';
                $data = $raw === '' ? [] : json_decode($raw, true);
                if (!is_array($data)) {
                    throw new HttpError(422, 'The request body is not valid JSON');
                }
                $this->body = $data;
            }
        }
        return new Input($this->body);
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                }
            }
        }
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }
}
